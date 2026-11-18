<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * Folder upload with structure (FU): the one place a dropped or picked folder's relative paths become Library folder
 * names, used both when the structure is created (DirectoryController::createStructure) and when each file's
 * destination is resolved (ChunkUploadService), so the two always agree on a name.
 *
 * - **Sanitized, not refused.** A segment keeps its name with control characters removed, each of `\ / : * ? " < > |`
 *   replaced by `_`, runs of whitespace collapsed, and leading dots (reserved, ReservedDirectoryNames) and trailing
 *   dots and spaces trimmed, cut to 255 characters. A segment left empty, `.` or `..` is dropped, so no path can climb
 *   out of the target folder.
 * - **Limited.** At most `dam.explorer.upload.folder_max_depth` folders deep and
 *   `dam.explorer.upload.folder_max_count` folders per upload; over either, the upload is refused (422) before any
 *   folder is made.
 */
final class FolderUploadPaths
{
    public const MAX_SEGMENT_LENGTH = 255;

    /** The Library folder name for one path segment, or null when nothing usable is left of it. */
    public static function segment(string $segment): ?string
    {
        $name = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $segment);
        $name = (string) preg_replace('/[\\\\\/:*?"<>|]/u', '_', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = ltrim(trim($name), '.');
        $name = rtrim($name, '. ');
        $name = trim(mb_substr($name, 0, self::MAX_SEGMENT_LENGTH));

        return $name === '' ? null : $name;
    }

    /**
     * The sanitized folder segments of a folder path (`Shoot/Clips`).
     *
     * @return list<string>
     */
    public static function folders(string $path): array
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $name = self::segment($segment);

            if ($name !== null) {
                $segments[] = $name;
            }
        }

        return $segments;
    }

    /**
     * The folders a file's relative path puts it in: the path without its file name, and without its top folder
     * unless `$preserveRoot` (the upload panel's "keep the dropped folder itself" choice).
     *
     * @return list<string>
     */
    public static function fileFolders(?string $relativePath, bool $preserveRoot): array
    {
        $raw = explode('/', ltrim(str_replace('\\', '/', (string) $relativePath), '/'));
        array_pop($raw);

        if (! $preserveRoot && $raw !== []) {
            array_shift($raw);
        }

        return self::folders(implode('/', $raw));
    }

    public static function maxDepth(): int
    {
        return max(1, (int) config('dam.explorer.upload.folder_max_depth'));
    }

    public static function maxCount(): int
    {
        return max(1, (int) config('dam.explorer.upload.folder_max_count'));
    }

    /**
     * Every distinct folder the paths name, parents first, as `a`, `a/b`… (sanitized). Throws when one is deeper than
     * the limit or there are more than the limit.
     *
     * @param  list<string>  $paths
     * @return list<list<string>>
     *
     * @throws \LengthException
     */
    public static function distinctFolders(array $paths): array
    {
        $folders = [];

        foreach ($paths as $path) {
            $segments = self::folders((string) $path);

            if (count($segments) > self::maxDepth()) {
                throw new \LengthException('folder_too_deep');
            }

            for ($depth = 1; $depth <= count($segments); $depth++) {
                $prefix = array_slice($segments, 0, $depth);
                $folders[implode("\0", $prefix)] = $prefix;
            }

            if (count($folders) > self::maxCount()) {
                throw new \LengthException('too_many_folders');
            }
        }

        return array_values($folders);
    }
}
