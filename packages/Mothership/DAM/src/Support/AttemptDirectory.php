<?php

namespace Mothership\DAM\Support;

/**
 * Shared filesystem helpers for the local, attempt-scoped temp directories
 * CliPanoramaWorker creates per conversion attempt (source staging + CLI
 * output). Extracted as pure, dependency-free static methods so both
 * CliPanoramaWorker and GeneratePanoramaPreview share one correct
 * implementation, and so the two properties that matter — "does this path
 * really live flat inside that directory" and "did we really remove
 * everything" — are directly unit-testable without a real subprocess.
 */
final class AttemptDirectory
{
    /**
     * Resolves $candidatePath and verifies it is a real, existing file that
     * lives DIRECTLY inside the real path of $dir — not outside it (a
     * realpath/symlink escape) and not nested in a subdirectory of it (the
     * CLI contract requires flat output: a single file per attempt
     * directory, no subdirectories). Returns the resolved absolute path on
     * success, or false if either condition fails.
     */
    public static function realFileFlatWithin(string $dir, string $candidatePath): string|false
    {
        $realPath = realpath($candidatePath);
        $realDir = realpath($dir);

        if ($realPath === false || $realDir === false || ! is_file($realPath)) {
            return false;
        }

        if (! str_starts_with($realPath, $realDir.DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (dirname($realPath) !== $realDir) {
            return false;
        }

        return $realPath;
    }

    /**
     * Deletes $dir and everything inside it, including nested
     * subdirectories (ffmpeg two-pass logs, temp segments, and similar
     * intermediates routinely land alongside the final output). Silent and
     * best-effort: a filesystem race or permission error on one entry does
     * not stop the rest from being removed, since this only ever runs
     * during cleanup of a temp directory nothing else depends on.
     */
    public static function removeRecursively(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                self::removeRecursively($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
