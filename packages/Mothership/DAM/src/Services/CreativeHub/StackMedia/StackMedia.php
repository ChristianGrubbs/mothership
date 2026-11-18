<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

use Illuminate\Support\Collection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\CreativeHub\OpenWithResolver;
use Mothership\DAM\Support\StackFileName;

/**
 * The apps whose native files open with the media they name from their stack (StackMediaReader), and how a named path
 * finds its stack file.
 */
final class StackMedia
{
    /**
     * Each app's reader; an app plugs in by adding its class.
     *
     * @var list<class-string<StackMediaReader>>
     */
    public const READERS = [SoundCraftSession::class, FilmCraftProject::class, VectorCraftDocument::class, EffectCraftProject::class];

    /** The most of a native file read to find its media; these are JSON of a few kilobytes to a few hundred. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function reader(?string $extension): ?StackMediaReader
    {
        $extension = OpenWithResolver::normalizeExtension($extension);

        foreach (self::READERS as $class) {
            $reader = new $class;

            if (in_array($extension, $reader->extensions(), true)) {
                return $reader;
            }
        }

        return null;
    }

    /**
     * Each path's file in the stack: the one with the path's file name, an exact match before one that differs only in
     * case, then stack order. Null for a path the stack does not hold.
     *
     * @param  list<string>  $paths
     * @param  Collection<int, AssetFile>  $members
     * @return array<string, ?AssetFile>
     */
    public static function match(array $paths, Collection $members, Asset $asset): array
    {
        $named = $members->values()->map(fn (AssetFile $file): array => ['name' => StackFileName::fileName($file, $asset), 'file' => $file]);
        $found = [];

        foreach ($paths as $path) {
            $base = basename($path);
            $hit = $named->first(fn (array $entry): bool => $entry['name'] === $base)
                ?? $named->first(fn (array $entry): bool => mb_strtolower($entry['name']) === mb_strtolower($base));
            $found[$path] = $hit['file'] ?? null;
        }

        return $found;
    }
}
