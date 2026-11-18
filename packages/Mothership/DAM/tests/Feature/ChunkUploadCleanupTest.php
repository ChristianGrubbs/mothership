<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\ChunkUploadPart;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\ChunkUploadService;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());

    $this->makeCleanupUpload = function (
        string $state = ChunkUpload::STATE_RECEIVING,
        mixed $expiresAt = null,
    ): ChunkUpload {
        $directory = Directory::factory()->create();
        $tracker = UploadTracker::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => auth()->id(),
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
        ]);
        $uuid = (string) Str::uuid();
        $upload = ChunkUpload::create([
            'uuid'                => $uuid,
            'upload_tracker_id'   => $tracker->id,
            'user_id'             => auth()->id(),
            'directory_id'        => $directory->id,
            'original_name'       => 'cleanup.bin',
            'mime_type'           => 'application/octet-stream',
            'extension'           => 'bin',
            'preserve_root'       => false,
            'expected_bytes'      => 4,
            'source_modified_ms'  => 1_788_864_000_000,
            'source_fingerprint'  => str_repeat('d', 64),
            'chunk_bytes'         => 4,
            'chunk_count'         => 1,
            'received_bytes'      => 4,
            'finalization_bytes'  => 0,
            'state'               => $state,
            'staged_root'         => '.uploads/'.$uuid,
            'expires_at'          => $expiresAt ?? now()->addHours(12),
        ]);
        $partPath = $upload->staged_root.'/parts/0.part';
        Storage::disk(Directory::getAssetDisk())->put($partPath, 'data');
        ChunkUploadPart::create([
            'chunk_upload_id' => $upload->id,
            'chunk_index'     => 0,
            'byte_offset'     => 0,
            'byte_length'     => 4,
            'sha256'          => hash('sha256', 'data'),
            'staged_path'     => $partPath,
        ]);

        return $upload;
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

it('cancels one upload idempotently without touching unrelated staging', function () {
    $cancelled = ($this->makeCleanupUpload)();
    $other = ($this->makeCleanupUpload)();

    $this->deleteJson(route('admin.dam.assets.chunk-upload.cancel', $cancelled->uuid))
        ->assertOk()
        ->assertJsonPath('upload.state', ChunkUpload::STATE_CANCELLED);
    $this->deleteJson(route('admin.dam.assets.chunk-upload.cancel', $cancelled->uuid))
        ->assertOk()
        ->assertJsonPath('upload.state', ChunkUpload::STATE_CANCELLED);

    expect($cancelled->fresh()->parts()->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->directoryMissing($cancelled->staged_root))->toBeTrue()
        ->and($other->fresh()->parts()->count())->toBe(1)
        ->and(Storage::disk(Directory::getAssetDisk())->exists($other->parts()->first()->staged_path))->toBeTrue();
});

it('expires stale receiving queued and failed uploads but keeps a fresh upload', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');
    $fresh = ($this->makeCleanupUpload)(expiresAt: now()->addSecond());
    $stale = collect([
        ($this->makeCleanupUpload)(ChunkUpload::STATE_RECEIVING, now()),
        ($this->makeCleanupUpload)(ChunkUpload::STATE_QUEUED, now()->subMinute()),
        ($this->makeCleanupUpload)(ChunkUpload::STATE_FAILED, now()->subHour()),
    ]);

    $expired = app(ChunkUploadService::class)->expire(now());

    expect($expired)->toBe(3)
        ->and($fresh->fresh()->state)->toBe(ChunkUpload::STATE_RECEIVING);
    $stale->each(fn (ChunkUpload $upload) => expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_EXPIRED));
});

it('never expires active assembly or removes a completed destination', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');
    $assembling = ($this->makeCleanupUpload)(ChunkUpload::STATE_ASSEMBLING, now()->subHour());
    $completed = ($this->makeCleanupUpload)(ChunkUpload::STATE_COMPLETED, now()->subHour());
    $destination = 'assets/kept.bin';
    Storage::disk(Directory::getAssetDisk())->put($destination, 'published');
    $asset = Asset::factory()->create(['file_name' => 'kept.bin', 'path' => $destination]);
    $completed->update(['asset_id' => $asset->id, 'finalized_at' => now()]);

    expect(app(ChunkUploadService::class)->expire(now()))->toBe(0)
        ->and($assembling->fresh()->state)->toBe(ChunkUpload::STATE_ASSEMBLING)
        ->and($completed->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and(Storage::disk(Directory::getAssetDisk())->get($destination))->toBe('published');
});

it('runs expiry from the command and schedules it hourly', function () {
    Carbon::setTestNow('2026-09-08 12:00:00');
    ($this->makeCleanupUpload)(expiresAt: now()->subMinute());

    $this->artisan('dam:uploads:expire')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'dam:uploads:expire'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
