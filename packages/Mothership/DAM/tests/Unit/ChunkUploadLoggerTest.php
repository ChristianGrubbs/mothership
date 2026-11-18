<?php

declare(strict_types=1);

use Mothership\DAM\Support\ChunkUploadLogger;

beforeEach(function () {
    $this->logPath = storage_path('logs/chunk-uploads.jsonl');

    if (is_file($this->logPath)) {
        unlink($this->logPath);
    }
});

afterEach(function () {
    if (is_file($this->logPath)) {
        unlink($this->logPath);
    }
});

it('writes typed lifecycle context while removing forbidden fields', function () {
    app(ChunkUploadLogger::class)->write(
        runId: 'run-123',
        level: 'info',
        event: 'chunk.accepted',
        loc: 'ChunkUploadService::storePart',
        ctx: [
            'upload_id' => 42,
            'retry'     => false,
            'filename'  => 'private.mov',
            'nested'    => [
                'path'     => '/private/location',
                'contents' => 'private bytes',
                'cookie'   => 'private cookie',
                'headers'  => ['private header'],
            ],
        ],
    );

    $record = json_decode(trim((string) file_get_contents($this->logPath)), true, flags: JSON_THROW_ON_ERROR);

    expect($record)->toHaveKeys(['ts', 'run_id', 'level', 'event', 'loc', 'ctx'])
        ->and($record['run_id'])->toBe('run-123')
        ->and($record['ctx']['upload_id'])->toBe(42)
        ->and($record['ctx']['retry'])->toBeFalse()
        ->and($record['ctx'])->not->toHaveKey('filename')
        ->and($record['ctx']['nested'])->not->toHaveKeys(['path', 'contents', 'cookie', 'headers']);
});

it('suppresses verbose chunk events unless verbose logging is enabled', function () {
    config(['dam.explorer.upload.verbose_log' => false]);

    app(ChunkUploadLogger::class)->write(
        runId: 'run-456',
        level: 'info',
        event: 'chunk.accepted',
        loc: 'ChunkUploadService::storePart',
        ctx: ['chunk_index' => 1],
        verbose: true,
    );

    expect(is_file($this->logPath))->toBeFalse();
});
