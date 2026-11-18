<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\ChunkUploadPart;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;

beforeEach(function () {
    $this->loginAsAdmin();

    $this->createChunkUpload = function (array $overrides = []): ChunkUpload {
        $directory = Directory::factory()->create();
        $tracker = UploadTracker::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => auth()->id(),
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
        ]);

        return ChunkUpload::create(array_merge([
            'uuid'               => (string) Str::uuid(),
            'upload_tracker_id'  => $tracker->id,
            'user_id'            => auth()->id(),
            'directory_id'       => $directory->id,
            'original_name'      => 'fixture.mp4',
            'mime_type'          => 'video/mp4',
            'extension'          => 'mp4',
            'expected_bytes'     => 4,
            'source_modified_ms' => 1_788_864_000_000,
            'source_fingerprint' => str_repeat('a', 64),
            'chunk_bytes'        => 4,
            'chunk_count'        => 1,
            'received_bytes'     => 0,
            'state'              => ChunkUpload::STATE_RECEIVING,
            'staged_root'        => '.uploads/'.Str::uuid(),
            'expires_at'         => now()->addHours(12),
        ], $overrides));
    };
});

it('stores a 20 GiB upload manifest with 640 parts', function () {
    $upload = ($this->createChunkUpload)([
        'original_name'  => 'master.mp4',
        'expected_bytes' => 21_474_836_480,
        'chunk_bytes'    => 33_554_432,
        'chunk_count'    => 640,
    ]);

    expect($upload->expected_bytes)->toBe(21_474_836_480)
        ->and($upload->chunk_count)->toBe(640)
        ->and($upload->isReceiving())->toBeTrue()
        ->and($upload->tracker)->toBeInstanceOf(UploadTracker::class);
});

it('enforces one record for each chunk index', function () {
    $upload = ($this->createChunkUpload)();
    $attributes = [
        'chunk_upload_id' => $upload->id,
        'chunk_index'     => 0,
        'byte_offset'     => 0,
        'byte_length'     => 4,
        'sha256'          => hash('sha256', 'data'),
        'staged_path'     => $upload->staged_root.'/parts/0.part',
    ];

    ChunkUploadPart::create($attributes);

    expect(fn () => ChunkUploadPart::create($attributes))->toThrow(QueryException::class);
});

it('ships upload behavior with the exact production limits', function () {
    expect(config('dam.explorer.upload.max_file_bytes'))->toBe(21_474_836_480)
        ->and(config('dam.explorer.upload.chunk_bytes'))->toBe(33_554_432)
        ->and(config('dam.explorer.upload.stale_hours'))->toBe(12)
        ->and(config('dam.explorer.upload.concurrency'))->toBe(3);
});
