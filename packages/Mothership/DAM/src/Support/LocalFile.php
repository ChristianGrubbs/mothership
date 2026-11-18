<?php

namespace Mothership\DAM\Support;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A real filesystem path to a blob's bytes, staged by ContentStore::localPath() for tools that need one
 * (ffmpeg, vips, exiftool, Python workers). The path lives in a private temp directory that this object
 * owns: a symlink to the blob on a local disk, a downloaded copy otherwise. release() removes that
 * directory (never the blob a symlink points at); it is idempotent and also runs on destruction, so a
 * caller that forgets it only holds the staging until the object goes out of scope.
 */
final class LocalFile
{
    private bool $released = false;

    public function __construct(
        public readonly string $path,
        private readonly string $directory,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (! is_dir($this->directory) || is_link($this->directory)) {
            return;
        }

        // Child-first and without following links: a symlinked entry is unlinked, never descended into.
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entryPath = $entry->getPathname();

            if (is_link($entryPath) || ! is_dir($entryPath)) {
                @unlink($entryPath);
            } else {
                @rmdir($entryPath);
            }
        }

        @rmdir($this->directory);
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function __destruct()
    {
        $this->release();
    }

    /** A clone would release the same directory twice over, under a caller still using the other copy. */
    public function __clone()
    {
        throw new LogicException('A LocalFile owns its staging directory and cannot be cloned.');
    }
}
