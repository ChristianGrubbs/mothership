<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\WritesContentAddressedObjects;

/**
 * Regression fixtures for WritesContentAddressedObjects::ensureObjectWritten()
 * — the two historical incident shapes named in review (a truncated existing
 * object being accepted as intact, and a failed write/move orphaning a
 * `.uploading-*.tmp` temp object) plus the two failure paths that must clean
 * up after themselves without masking the original exception.
 *
 * No global helper functions are declared here (Pest loads every Feature
 * spec into one process, so a top-level `function` name collides across
 * files) — every helper below is a local closure captured via `use()`.
 */
beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
});

// Anonymous consumer of the trait: exposes the protected ensureObjectWritten()
// through a public forwarding method, exactly as RenditionPublisher and
// VideoProxyPublisher do.
$writer = function () {
    return new class
    {
        use WritesContentAddressedObjects;

        public function write(string $disk, string $objectPath, string $localPath, int $localSize): void
        {
            $this->ensureObjectWritten($disk, $objectPath, $localPath, $localSize);
        }
    };
};

// Local source file fixture; caller is responsible for unlink()ing it.
$makeTempFile = function (string $contents): string {
    $path = tempnam(sys_get_temp_dir(), 'wcao-test-');
    file_put_contents($path, $contents);

    return $path;
};

// The fake's own view of any orphaned per-attempt temp object.
$uploadingFiles = function (string $disk): array {
    return collect(Storage::disk($disk)->allFiles('assets/.objects'))
        ->filter(fn (string $path) => str_contains($path, '.uploading-'))
        ->values()
        ->all();
};

// Swaps the faked disk for an anonymous FilesystemAdapter subclass built from
// the SAME underlying driver/adapter/config, with writeStream()/move()/
// delete() overridable per test so a write or move failure — and a cleanup
// failure on top of it — can be injected without touching production code.
$installFailingAdapter = function (
    string $disk,
    ?Closure $writeStream = null,
    ?Closure $move = null,
    ?Closure $delete = null,
): void {
    $fake = Storage::disk($disk);

    $failing = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $writeStream, $move, $delete) extends FilesystemAdapter
    {
        public function __construct(
            $driver,
            $adapter,
            array $config,
            private readonly ?Closure $writeStreamOverride,
            private readonly ?Closure $moveOverride,
            private readonly ?Closure $deleteOverride,
        ) {
            parent::__construct($driver, $adapter, $config);
        }

        public function writeStream($path, $resource, array $options = [])
        {
            if ($this->writeStreamOverride) {
                return ($this->writeStreamOverride)($path, $resource, $options, $this->driver);
            }

            return parent::writeStream($path, $resource, $options);
        }

        public function move($from, $to)
        {
            if ($this->moveOverride) {
                return ($this->moveOverride)($from, $to, $this->driver);
            }

            return parent::move($from, $to);
        }

        public function delete($paths)
        {
            if ($this->deleteOverride) {
                return ($this->deleteOverride)($paths, $this->driver);
            }

            return parent::delete($paths);
        }
    };

    Storage::set($disk, $failing);
};

it('leaves an intact existing object untouched and writes no temp file', function () use ($writer, $makeTempFile, $uploadingFiles) {
    $disk = Directory::getAssetDisk();
    $objectPath = 'assets/.objects/ab/intact-object.bin';
    $localContents = str_repeat('A', 32);
    $existingContents = str_repeat('B', 32); // same length, different bytes
    $localPath = $makeTempFile($localContents);

    try {
        Storage::disk($disk)->put($objectPath, $existingContents);

        $writer()->write($disk, $objectPath, $localPath, strlen($localContents));

        expect(Storage::disk($disk)->get($objectPath))->toBe($existingContents)
            ->and($uploadingFiles($disk))->toBe([]);
    } finally {
        unlink($localPath);
    }
});

it('replaces a truncated existing object with the complete local file', function () use ($writer, $makeTempFile, $uploadingFiles) {
    $disk = Directory::getAssetDisk();
    $objectPath = 'assets/.objects/ab/truncated-object.bin';
    $localContents = str_repeat('C', 64);
    $truncatedContents = str_repeat('C', 16); // fewer bytes than the local file
    $localPath = $makeTempFile($localContents);

    try {
        Storage::disk($disk)->put($objectPath, $truncatedContents);

        $writer()->write($disk, $objectPath, $localPath, strlen($localContents));

        expect(Storage::disk($disk)->get($objectPath))->toBe($localContents)
            ->and($uploadingFiles($disk))->toBe([]);
    } finally {
        unlink($localPath);
    }
});

it('throws object_write_incomplete and cleans up the temp object when the disk stores a truncated stream', function () use ($writer, $makeTempFile, $uploadingFiles, $installFailingAdapter) {
    $disk = Directory::getAssetDisk();
    $objectPath = 'assets/.objects/ab/short-write-object.bin';
    $localContents = str_repeat('D', 64);
    $localPath = $makeTempFile($localContents);

    try {
        $installFailingAdapter($disk, writeStream: function ($path, $resource, array $options, $driver) {
            $contents = stream_get_contents($resource);
            $driver->write($path, substr($contents, 0, intdiv(strlen($contents), 2)), []);

            return true;
        });

        $thrown = null;

        try {
            $writer()->write($disk, $objectPath, $localPath, strlen($localContents));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->toBe('object_write_incomplete')
            ->and(Storage::disk($disk)->exists($objectPath))->toBeFalse()
            ->and($uploadingFiles($disk))->toBe([]);
    } finally {
        unlink($localPath);
    }
});

it('propagates a move failure and cleans up the temp object', function () use ($writer, $makeTempFile, $uploadingFiles, $installFailingAdapter) {
    $disk = Directory::getAssetDisk();
    $objectPath = 'assets/.objects/ab/move-failure-object.bin';
    $localContents = str_repeat('E', 64);
    $localPath = $makeTempFile($localContents);

    try {
        $installFailingAdapter($disk, move: function ($from, $to) {
            throw new RuntimeException('simulated_move_failure');
        });

        $thrown = null;

        try {
            $writer()->write($disk, $objectPath, $localPath, strlen($localContents));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->toBe('simulated_move_failure')
            ->and(Storage::disk($disk)->exists($objectPath))->toBeFalse()
            ->and($uploadingFiles($disk))->toBe([]);
    } finally {
        unlink($localPath);
    }
});

it('keeps the original move-failure exception observable even when temp cleanup itself fails', function () use ($writer, $makeTempFile, $installFailingAdapter) {
    $disk = Directory::getAssetDisk();
    $objectPath = 'assets/.objects/ab/cleanup-failure-object.bin';
    $localContents = str_repeat('F', 64);
    $localPath = $makeTempFile($localContents);

    try {
        $installFailingAdapter(
            $disk,
            move: function ($from, $to) {
                throw new RuntimeException('simulated_move_failure');
            },
            delete: function ($paths) {
                throw new RuntimeException('simulated_delete_failure');
            },
        );

        $thrown = null;

        try {
            $writer()->write($disk, $objectPath, $localPath, strlen($localContents));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        // Cleanup legitimately fails and leaves the temp object behind here —
        // only the propagated exception's identity is asserted, per R15.
        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->toBe('simulated_move_failure');
    } finally {
        unlink($localPath);
    }
});
