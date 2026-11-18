<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mothership\Core\Filesystem\FileStorer;

it('removes the stored SVG when sanitization rejects malformed XML', function () {
    Storage::fake(config('filesystems.default'));

    Storage::put('uploads/malformed.svg', '<svg><broken></svg>');

    expect(fn () => app(FileStorer::class)->sanitizeSVG(
        path: 'uploads/malformed.svg',
        mimeType: 'image/svg+xml',
    ))->toThrow(ValidationException::class);

    Storage::assertMissing('uploads/malformed.svg');
});

it('removes dangerous content from a valid SVG before writing it', function () {
    Storage::fake(config('filesystems.default'));

    Storage::put('uploads/script.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="10" height="10" /></svg>');

    app(FileStorer::class)->sanitizeSVG(
        path: 'uploads/script.svg',
        mimeType: 'image/svg+xml',
    );

    expect(Storage::get('uploads/script.svg'))
        ->not->toContain('<script')
        ->not->toContain('onload')
        ->toContain('<rect');
});

it('removes the stored SVG when reading it throws', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('get')->once()->andThrow(new RuntimeException('read failed'));
    $disk->shouldReceive('delete')->once()->with('uploads/read-failure.svg')->andReturnTrue();

    Storage::shouldReceive('disk')->twice()->andReturn($disk);

    expect(fn () => app(FileStorer::class)->sanitizeSVG(
        path: 'uploads/read-failure.svg',
        mimeType: 'image/svg+xml',
    ))->toThrow(RuntimeException::class, 'read failed');
});

it('fails loudly when cleanup of a rejected SVG fails', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('get')->once()->andReturn('<svg><broken></svg>');
    $disk->shouldReceive('delete')->once()->with('uploads/cleanup-failure.svg')->andReturnFalse();

    Storage::shouldReceive('disk')->twice()->andReturn($disk);

    expect(fn () => app(FileStorer::class)->sanitizeSVG(
        path: 'uploads/cleanup-failure.svg',
        mimeType: 'image/svg+xml',
    ))->toThrow(RuntimeException::class, 'could not be removed');
});
