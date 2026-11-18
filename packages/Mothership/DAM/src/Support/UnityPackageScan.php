<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * The bounded state of one UnityPackageReader scan. Nothing assumes a GUID's files come together, and nothing a
 * package names is kept unless it is a Unity asset GUID (32 hex characters, see isGuid()) or a pathname cut to
 * MAX_PATH_BYTES, so every map below is bounded in bytes, not only in entries:
 *
 * - `$ranks`: GUID => preview rank, for every GUID whose pathname has been seen, up to MAX_RANKED_GUIDS. Past
 *   that, no rank is added: an unknown GUID's preview competes at the lowest rank, and `$ranksCapped` marks the
 *   scan truncated.
 * - `$previewed`: GUIDs whose preview.png came before their pathname (same cap), so the listing still shows it.
 * - `$records`: the listing for the modal, the first `$maxEntries` pathnames; `$assetCount` counts them all.
 * - `$best`: the best preview so far (one buffer), replaced only by a strictly better one, and re-ranked when its
 *   GUID's pathname arrives after it.
 * - `$pending`: one preview whose GUID's rank is still unknown and that is not the best, offered again at its real
 *   rank when that pathname arrives. A best preview displaced while its rank is unknown moves here.
 *
 * So at most two preview buffers (each at most UnityPackageReader::MAX_PREVIEW_BYTES) are held. A preview's path is
 * known from the listing or, past the listing cap, from the latest pathname (Unity writes a GUID's files together);
 * otherwise `preview_path` is null.
 *
 * @internal
 */
final class UnityPackageScan
{
    /** UnityPackageReader's rank for any asset it does not prefer. */
    public const LAST_RANK = 3;

    /** GUIDs remembered per map: a 32-byte key plus a small value each, so a few MB at most. */
    public const MAX_RANKED_GUIDS = 20000;

    /** The longest pathname kept, in bytes; Unity's are far shorter. */
    public const MAX_PATH_BYTES = 1024;

    public int $assetCount = 0;

    public bool $ranksCapped = false;

    /** @var array<string, array{path: string, has_preview: bool}> the listed assets, by GUID */
    public array $records = [];

    /** @var array{rank: int, ranked: bool, guid: string, png: string, path: string|null}|null */
    public ?array $best = null;

    /** @var array<string, int> */
    private array $ranks = [];

    /** @var array<string, true> */
    private array $previewed = [];

    /** @var array{guid: string, png: string}|null */
    private ?array $pending = null;

    /** @var array{guid: string, path: string}|null the latest pathname, so a GUID past the listing cap still names its preview */
    private ?array $lastPathname = null;

    public function __construct(private readonly int $maxEntries) {}

    /** A Unity asset GUID: 32 hex characters. Any other folder in a package is ignored. */
    public static function isGuid(string $name): bool
    {
        return preg_match('/^[0-9a-f]{32}$/iD', $name) === 1;
    }

    /** How many GUIDs have a remembered rank (never more than MAX_RANKED_GUIDS). */
    public function rankedCount(): int
    {
        return count($this->ranks);
    }

    /** `$rank` comes from the whole pathname; only its first MAX_PATH_BYTES are kept. */
    public function pathname(string $guid, string $path, int $rank): void
    {
        if (! self::isGuid($guid) || isset($this->ranks[$guid]) || isset($this->records[$guid])) {
            return;
        }

        $path = mb_strcut($path, 0, self::MAX_PATH_BYTES, 'UTF-8');
        $this->assetCount++;
        $this->lastPathname = ['guid' => $guid, 'path' => $path];

        if (count($this->ranks) < self::MAX_RANKED_GUIDS) {
            $this->ranks[$guid] = $rank;
        } else {
            $this->ranksCapped = true;
        }

        $previewed = isset($this->previewed[$guid]);
        unset($this->previewed[$guid]);

        if (count($this->records) < $this->maxEntries) {
            $this->records[$guid] = ['path' => $path, 'has_preview' => $previewed];
        }

        if ($this->best !== null && $this->best['guid'] === $guid && ! $this->best['ranked']) {
            // Chosen at the lowest rank before its pathname came: its real rank can only be as good.
            $this->best = ['rank' => min($this->best['rank'], $rank), 'ranked' => true, 'path' => $path] + $this->best;
        } elseif ($this->pending !== null && $this->pending['guid'] === $guid) {
            $pending = $this->pending;
            $this->pending = null;
            $this->offer($guid, $pending['png'], $rank, true, $path);
        }
    }

    /** A GUID's preview.png; null when it was too large or not a sane PNG. */
    public function preview(string $guid, ?string $png): void
    {
        if (! self::isGuid($guid)) {
            return;
        }

        if (isset($this->records[$guid])) {
            $this->records[$guid]['has_preview'] = true;
        } elseif (! isset($this->ranks[$guid]) && count($this->previewed) < self::MAX_RANKED_GUIDS) {
            $this->previewed[$guid] = true;
        }

        if ($png === null) {
            return;
        }

        if (isset($this->ranks[$guid])) {
            $this->offer($guid, $png, $this->ranks[$guid], true, $this->pathFor($guid));

            return;
        }

        if (! $this->offer($guid, $png, self::LAST_RANK, false, $this->pathFor($guid)) && ! $this->ranksCapped) {
            // Its pathname may still come and rank it higher.
            $this->pending = ['guid' => $guid, 'png' => $png];
        }
    }

    private function pathFor(string $guid): ?string
    {
        return $this->records[$guid]['path'] ?? (($this->lastPathname['guid'] ?? null) === $guid ? $this->lastPathname['path'] : null);
    }

    private function offer(string $guid, string $png, int $rank, bool $ranked, ?string $path): bool
    {
        if ($this->best !== null && $rank >= $this->best['rank']) {
            return false;
        }

        // A displaced best whose rank is still unknown may yet outrank this one: it takes the pending slot.
        if ($this->best !== null && ! $this->best['ranked'] && ! $this->ranksCapped) {
            $this->pending = ['guid' => $this->best['guid'], 'png' => $this->best['png']];
        }

        $this->best = ['rank' => $rank, 'ranked' => $ranked, 'guid' => $guid, 'png' => $png, 'path' => $path];

        return true;
    }
}
