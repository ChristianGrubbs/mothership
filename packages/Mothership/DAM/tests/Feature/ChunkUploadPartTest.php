<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\ChunkUploadPart;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Support\ChunkUploadError;

beforeEach(function () {
    $this->loginAsAdmin();
    config(['dam.explorer.upload.chunk_bytes' => 10]);
    Storage::fake(Directory::getAssetDisk());

    $this->createChunkUpload = function (int $size = 26): ChunkUpload {
        $directory = Directory::factory()->create();
        $tracker = UploadTracker::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => auth()->id(),
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
        ]);

        $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
            'session_uuid'  => $tracker->uuid,
            'directory_id'  => $directory->id,
            'original_name' => 'payload.bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => $size,
            'last_modified' => 1_788_864_000_000,
            'fingerprint'   => str_repeat('c', 64),
            'relative_path' => null,
            'preserve_root' => false,
        ])->assertCreated()->json('upload.uuid');

        return ChunkUpload::where('uuid', $uuid)->firstOrFail();
    };

    $this->putChunk = function (
        ChunkUpload $upload,
        int $index,
        string $contents,
        ?string $range = null,
        ?string $digest = null,
    ) {
        $start = $index * (int) $upload->chunk_bytes;
        $range ??= sprintf('bytes %d-%d/%d', $start, $start + strlen($contents) - 1, $upload->expected_bytes);
        $digest ??= hash('sha256', $contents);

        return $this->call(
            'PUT',
            route('admin.dam.assets.chunk-upload.store', [$upload->uuid, $index]),
            server: [
                'CONTENT_TYPE'        => 'application/octet-stream',
                'HTTP_CONTENT_RANGE'  => $range,
                'HTTP_X_CHUNK_SHA256' => $digest,
            ],
            content: $contents,
        );
    };
});

it('accepts verified chunks out of order', function () {
    $upload = ($this->createChunkUpload)();

    ($this->putChunk)($upload, 1, 'klmnopqrst')
        ->assertOk()
        ->assertJsonPath('upload.received_bytes', 10)
        ->assertJsonPath('upload.received_indices.0', 1);

    ($this->putChunk)($upload, 0, 'abcdefghij')
        ->assertOk()
        ->assertJsonPath('upload.received_bytes', 20)
        ->assertJsonPath('upload.received_indices.0', 0)
        ->assertJsonPath('upload.received_indices.1', 1);

    Storage::disk(Directory::getAssetDisk())->assertExists($upload->staged_root.'/parts/0.part');
    Storage::disk(Directory::getAssetDisk())->assertExists($upload->staged_root.'/parts/1.part');
});

it('treats an identical retry as idempotent', function () {
    $upload = ($this->createChunkUpload)();

    ($this->putChunk)($upload, 1, 'klmnopqrst')->assertOk();
    ($this->putChunk)($upload, 1, 'klmnopqrst')
        ->assertOk()
        ->assertJsonPath('upload.received_bytes', 10);

    expect($upload->parts()->count())->toBe(1);
});

it('rejects a conflicting retry', function () {
    $upload = ($this->createChunkUpload)();

    ($this->putChunk)($upload, 1, 'klmnopqrst')->assertOk();
    ($this->putChunk)($upload, 1, '0123456789')
        ->assertStatus(409)
        ->assertJsonPath('code', 'chunk_conflict')
        ->assertJsonPath('retryable', false);

    expect($upload->fresh()->received_bytes)->toBe(10);
});

it('rejects invalid chunk digests ranges indices totals and lengths', function () {
    $upload = ($this->createChunkUpload)();

    ($this->putChunk)($upload, 0, 'abcdefghij', digest: str_repeat('0', 64))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'chunk_checksum_mismatch');
    ($this->putChunk)($upload, 0, 'abcdefghij', range: 'bytes 1-10/26')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_chunk_range');
    ($this->putChunk)($upload, 3, 'x')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_chunk_index');
    ($this->putChunk)($upload, 0, 'abcdefghij', range: 'bytes 0-9/27')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_chunk_range');
    ($this->putChunk)($upload, 0, 'short', range: 'bytes 0-9/26')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_chunk_length');

    expect($upload->parts()->count())->toBe(0);
});

it('rejects writes against expired and cancelled uploads', function () {
    $expired = ($this->createChunkUpload)();
    $expired->update(['expires_at' => now()->subSecond()]);
    ($this->putChunk)($expired->fresh(), 0, 'abcdefghij')
        ->assertStatus(409)
        ->assertJsonPath('code', 'upload_expired');

    $cancelled = ($this->createChunkUpload)();
    $cancelled->update(['state' => ChunkUpload::STATE_CANCELLED]);
    ($this->putChunk)($cancelled->fresh(), 0, 'abcdefghij')
        ->assertStatus(409)
        ->assertJsonPath('code', 'upload_not_receiving');
});

it('rechecks state under the row lock when cancellation races an in-flight chunk', function () {
    $stale = ($this->createChunkUpload)();
    app(ChunkUploadService::class)->cancel($stale->fresh());
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'abcdefghij');
    rewind($stream);

    try {
        app(ChunkUploadService::class)->storePart($stale, 0, [
            'content_range' => 'bytes 0-9/26',
            'sha256'        => hash('sha256', 'abcdefghij'),
        ], $stream);
        $this->fail('The stale chunk request should have been rejected.');
    } catch (ChunkUploadError $error) {
        expect($error->errorCode)->toBe('upload_not_receiving');
    } finally {
        fclose($stream);
    }

    expect($stale->fresh()->parts()->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->directoryMissing($stale->staged_root))->toBeTrue();
});

it('leaves no part row when storage rejects a write', function () {
    $upload = ($this->createChunkUpload)();
    $disk = Mockery::mock();
    $disk->shouldReceive('writeStream')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->once()->with(Directory::getAssetDisk())->andReturn($disk);

    ($this->putChunk)($upload, 0, 'abcdefghij')
        ->assertStatus(507)
        ->assertJsonPath('code', 'chunk_storage_failed')
        ->assertJsonPath('retryable', true);

    expect(ChunkUploadPart::where('chunk_upload_id', $upload->id)->exists())->toBeFalse();
});
