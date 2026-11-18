<?php

namespace Mothership\DAM\Tests\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;

/**
 * An object-store disk for tests (ADR 0017): Storage::fake() keeps real bytes on a local directory, but its
 * adapter is local, so every "is this disk local?" branch would take the local path. This swaps in an adapter
 * subclass that reports a non-local Flysystem adapter and records each write, copy and move, so a test can see
 * which path ran and how many server-side copies it cost.
 */
final class RemoteDiskFake
{
    /**
     * The installed disk; its public `calls` lists each writeStream, copy and move as [method, path, ...].
     * `$writeStream` ($path, $resource, $driver) and `$copy` ($from, $to, $driver) replace the real operation, to
     * store short or failed writes.
     */
    public static function install(string $disk, ?Closure $writeStream = null, ?Closure $copy = null): FilesystemAdapter
    {
        $fake = Storage::fake($disk);

        $remote = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $writeStream, $copy) extends FilesystemAdapter
        {
            /** @var list<array{0: string, 1: string, 2?: string}> */
            public array $calls = [];

            private ?FlysystemAdapter $reported = null;

            public function __construct(
                $driver,
                $adapter,
                array $config,
                private readonly ?Closure $writeStreamOverride,
                private readonly ?Closure $copyOverride,
            ) {
                parent::__construct($driver, $adapter, $config);
            }

            public function getAdapter()
            {
                return $this->reported ??= Mockery::mock(FlysystemAdapter::class);
            }

            public function writeStream($path, $resource, array $options = [])
            {
                $this->calls[] = ['writeStream', $path];

                if ($this->writeStreamOverride) {
                    return ($this->writeStreamOverride)($path, $resource, $this->driver);
                }

                return parent::writeStream($path, $resource, $options);
            }

            public function copy($from, $to)
            {
                $this->calls[] = ['copy', $from, $to];

                if ($this->copyOverride) {
                    return ($this->copyOverride)($from, $to, $this->driver);
                }

                return parent::copy($from, $to);
            }

            public function move($from, $to)
            {
                $this->calls[] = ['move', $from, $to];

                return parent::move($from, $to);
            }
        };

        Storage::set($disk, $remote);

        assert(! $remote->getAdapter() instanceof LocalFilesystemAdapter);

        return $remote;
    }
}
