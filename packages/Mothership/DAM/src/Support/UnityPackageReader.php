<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use RuntimeException;

/**
 * Reads a Unity `.unitypackage` without Unity: the package is a gzip-compressed tar (read by TarListing)
 * holding one folder per asset GUID, each with
 *
 * - `pathname`: the asset's path in the project (`Assets/Models/Robot.fbx`), sometimes followed by a second line;
 * - `asset` and `asset.meta`: the asset and its import settings (never read here);
 * - `preview.png`: optional, the thumbnail the Unity editor drew for the asset.
 *
 * The result is the contents listing (the first `$maxEntries` assets, sorted by path) and the PNG bytes of one
 * preview: the first prefab's, else the first model's, else the first material's, else the first of any asset.
 *
 * Nothing about the package is trusted, and everything it can make this hold is bounded while it is read, not
 * after: at most `$maxEntries` listed paths, two previews (UnityPackageScan); a
 * preview whose PNG header names a picture larger than MAX_PREVIEW_SIDE or MAX_PREVIEW_PIXELS is never kept,
 * so it never reaches a decoder; and TarListing stops after `$maxBytes` of decompressed data or `$timeoutSeconds`.
 * A package that is cut short that way, or damaged part-way, still yields what was read before, best preview
 * included, with `complete` false and `stopped` naming why.
 */
final class UnityPackageReader
{
    /** Assets listed for the preview modal; a package can hold tens of thousands. */
    public const MAX_ENTRIES = 2000;

    /** A preview.png larger than this is skipped (Unity's are 128 px, a few KB). */
    public const MAX_PREVIEW_BYTES = 1048576;

    /** The largest preview side, and picture, allowed to reach a decoder: a small PNG can name a huge one. */
    public const MAX_PREVIEW_SIDE = 4096;

    public const MAX_PREVIEW_PIXELS = 16777216;

    /** Decompressed bytes one read walks before it stops (512 MB), as BlendFileReader. */
    public const DEFAULT_MAX_BYTES = 536870912;

    private const MAX_PATHNAME_BYTES = 4096;

    /** Preview preference by asset extension; lower wins, anything unlisted ranks last. */
    private const RANKS = [
        'prefab' => 0,
        'fbx'    => 1, 'obj' => 1, 'blend' => 1, 'dae' => 1, '3ds' => 1, 'gltf' => 1, 'glb' => 1, 'max' => 1, 'ma' => 1, 'mb' => 1,
        'mat'    => 2,
    ];

    public function __construct(
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly float $timeoutSeconds = 60.0,
    ) {}

    /**
     * @return array{
     *     assets: list<array{path: string, has_preview: bool}>,
     *     asset_count: int,
     *     truncated: bool,
     *     complete: bool,
     *     stopped: string|null,
     *     preview_png: string|null,
     *     preview_path: string|null,
     * }|null  null when nothing in the file reads as a Unity package
     */
    public function read(string $path): ?array
    {
        $scan = new UnityPackageScan($this->maxEntries);
        $stopped = null;

        $capture = function (string $name, int $size): bool {
            [, $leaf] = self::split($name);

            return match ($leaf) {
                'pathname'    => $size <= self::MAX_PATHNAME_BYTES,
                'preview.png' => $size <= self::MAX_PREVIEW_BYTES,
                default       => false,
            };
        };

        try {
            foreach (TarListing::entries($path, $capture, $this->maxBytes, $this->timeoutSeconds) as $entry) {
                [$guid, $leaf] = self::split($entry['name']);

                if ($guid === null) {
                    continue;
                }

                if ($leaf === 'pathname' && isset($entry['data'])) {
                    $pathname = self::pathname($entry['data']);

                    if ($pathname !== '') {
                        $scan->pathname($guid, $pathname, self::rank($pathname));
                    }
                } elseif ($leaf === 'preview.png') {
                    $scan->preview($guid, isset($entry['data']) && self::pngDimensions($entry['data']) !== null ? $entry['data'] : null);
                }
            }
        } catch (RuntimeException $e) {
            // Over budget, out of time, truncated or corrupt: keep what was read before.
            $stopped = in_array($e->getMessage(), ['archive_budget', 'archive_deadline'], true)
                ? substr($e->getMessage(), strlen('archive_'))
                : 'damaged';
        }

        if ($scan->assetCount === 0) {
            return null;
        }

        $assets = array_values($scan->records);
        usort($assets, fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));

        return [
            'assets'       => $assets,
            'asset_count'  => $scan->assetCount,
            'truncated'    => $scan->assetCount > count($assets) || $scan->ranksCapped || $stopped !== null,
            'complete'     => $stopped === null,
            'stopped'      => $stopped,
            'preview_png'  => $scan->best['png'] ?? null,
            'preview_path' => $scan->best['path'] ?? null,
        ];
    }

    /**
     * A PNG's [width, height] from its IHDR chunk, or null when the bytes are not a PNG, the header is malformed,
     * or the picture is larger than a preview may be. Read before anything decodes it.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function pngDimensions(string $bytes): ?array
    {
        // Signature, then the first chunk: length 13, type IHDR, width, height (big-endian).
        if (strlen($bytes) < 33 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            || substr($bytes, 8, 8) !== "\x00\x00\x00\x0dIHDR") {
            return null;
        }

        ['w' => $width, 'h' => $height] = unpack('Nw/Nh', substr($bytes, 16, 8));

        if ($width < 1 || $height < 1 || $width > self::MAX_PREVIEW_SIDE || $height > self::MAX_PREVIEW_SIDE
            || $width * $height > self::MAX_PREVIEW_PIXELS) {
            return null;
        }

        return [$width, $height];
    }

    private static function rank(string $pathname): int
    {
        return self::RANKS[strtolower(pathinfo($pathname, PATHINFO_EXTENSION))] ?? UnityPackageScan::LAST_RANK;
    }

    /**
     * `<guid>/<leaf>` (a leading `./` dropped) as [guid, leaf]; [null, ''] for anything not one level under a GUID folder.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $name): array
    {
        $name = str_starts_with($name, './') ? substr($name, 2) : $name;
        $parts = explode('/', trim($name, '/'));

        // Only a Unity asset GUID folder counts: any other name, however long, is read through and ignored.
        if (count($parts) !== 2 || ! UnityPackageScan::isGuid($parts[0]) || $parts[1] === '') {
            return [null, ''];
        }

        return [$parts[0], $parts[1]];
    }

    /** The first line, trimmed, as valid UTF-8 (Unity writes `Assets/…` then sometimes a second line). */
    private static function pathname(string $data): string
    {
        $line = trim(strtok(str_replace("\r", "\n", $data), "\n") ?: '');

        return mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'UTF-8');
    }
}
