<?php

namespace Mothership\DAM\Support;

use Generator;
use Illuminate\Support\Facades\DB;
use JsonException;
use Normalizer;

/**
 * Which rows name a file on the asset disk: an asset's Library path (`dam_assets.path`), a FileObject locator
 * (a legacy row names a Library file until dam:storage:repair repoints it), a layered-editor resource
 * (`dam_image_editor_resources.path`), an editor document's content path, or any string in a resource's JSON
 * `details`. These five families are what a cleanup must re-check before it unlinks a file it did not write
 * itself. The reclaim (ADR 0009 slice 4) also reads a sixth, opt-in family for derivatives: any string in an
 * asset's `meta_data` (a pre-slice-2 video poster's `thumbnail_path`). It asks who names a path (namers(),
 * namersAmong()) and decides itself which families protect a file.
 *
 * `details` is the one family with no fixed column: the legacy AI edit kept a draft's working files there
 * (`input`, `mask`, `reference`, `source`, `guidance`), and a save-as-new copies a resource's details unchanged
 * (ImageEditorController::save()), so a resource on another asset can name them, in the first asset's editor
 * folder or elsewhere. Every string in it, at any key or depth and each key too, names every path it contains.
 * `meta_data` is read the same way.
 *
 * Paths are compared by filesystem identity, not as exact strings: the Studio's asset disk is APFS, which is
 * case- and Unicode-normalization-insensitive, so a row naming `Working.png` names the on-disk `working.png`,
 * and an NFD spelling names the NFC one. key() folds both (NFC, then lower case). Matching more only ever
 * protects more, so the same rule is safe on a case-sensitive disk.
 */
final class PathReferences
{
    public const ASSET_PATH = 'dam_assets.path';

    public const FILE_OBJECT_PATH = 'dam_file_objects.path';

    public const RESOURCE_PATH = 'dam_image_editor_resources.path';

    public const DOCUMENT_CONTENT_PATH = 'dam_image_editor_documents.content_path';

    public const RESOURCE_DETAILS = 'dam_image_editor_resources.details';

    public const ASSET_METADATA = 'dam_assets.meta_data';

    /** [table, column, column holding the owning asset's id] of each row family that names a file by its whole value. */
    private const FAMILIES = [
        ['dam_assets', 'path', 'id'],
        ['dam_file_objects', 'path', null],
        ['dam_image_editor_resources', 'path', 'asset_id'],
        ['dam_image_editor_documents', 'content_path', 'asset_id'],
    ];

    /** [table, column, asset id column] of the family that names files by any string in its JSON. */
    private const DETAILS = ['dam_image_editor_resources', 'details', 'asset_id'];

    /** The opt-in JSON family: any string in an asset's `meta_data`. */
    private const METADATA = ['dam_assets', 'meta_data', 'id'];

    /**
     * ASCII characters that a single non-ASCII character also folds to (U+037E, U+1FEF, U+212A under NFC and
     * lower case): a raw row may hold that character where the candidate holds the ASCII one.
     */
    private const FOLDED_FROM_NON_ASCII = [';', '`', 'k'];

    private const CHUNK = 1000;

    /** A path's filesystem identity on a case- and normalization-insensitive disk. */
    public static function key(string $path): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
            $path = is_string($normalized) ? $normalized : $path;
        }

        return mb_strtolower($path, 'UTF-8');
    }

    /**
     * Which of `$paths` some row names, streaming each family once, so a pass over a whole Library tree costs
     * five scans rather than one query per file. Every path must be valid UTF-8 (a PostgreSQL text parameter
     * refuses anything else; callers set such names aside first).
     *
     * @param  list<string>  $paths
     * @return array<string, true>
     */
    public static function namedAmong(array $paths, string $disk): array
    {
        return array_map(fn (): bool => true, self::namersAmong(paths: $paths, disk: $disk));
    }

    /**
     * The rows that name each of `$paths`, streaming each family once, like namedAmong(). A path no row names
     * has no entry. Each row names a path at most once: `family` is one of the constants above, `id` the row's
     * key and `asset_id` the asset it belongs to (null for a FileObject).
     *
     * @param  list<string>  $paths
     * @return array<string, list<array{family: string, id: int|string, asset_id: int|null}>>
     */
    public static function namersAmong(array $paths, string $disk, bool $withAssetMetadata = false): array
    {
        $wanted = [];

        foreach ($paths as $path) {
            $wanted[self::key($path)][] = $path;
        }

        if ($wanted === []) {
            return [];
        }

        // A row under a top-level dot-folder can only name a path under that same dot-folder.
        $dotted = array_filter(array_keys($wanted), fn ($key) => str_starts_with((string) $key, 'assets/.'));
        $named = [];

        foreach (self::FAMILIES as [$table, $column, $assetColumn]) {
            $query = DB::table($table)->select(self::columns($column, $assetColumn))->whereNotNull($column);

            if ($dotted === []) {
                $query->where($column, 'not like', 'assets/.%');
            }

            if ($table === 'dam_file_objects') {
                $query->where('disk', $disk);
            }

            foreach ($query->lazyById(self::CHUNK, 'id') as $row) {
                foreach ($wanted[self::key((string) $row->{$column})] ?? [] as $path) {
                    $namer = self::namer(table: $table, column: $column, assetColumn: $assetColumn, row: $row);
                    $named[$path][$namer['family']."\0".$namer['id']] = $namer;
                }
            }
        }

        $anchors = self::anchors($wanted);

        foreach (self::jsonFamilies($withAssetMetadata) as [$table, $column, $assetColumn]) {
            foreach (self::jsonStrings(table: $table, column: $column, assetColumn: $assetColumn) as [$row, $string]) {
                foreach (self::keysWithin(string: $string, wanted: $wanted, anchors: $anchors) as $key) {
                    foreach ($wanted[$key] as $path) {
                        $namer = self::namer(table: $table, column: $column, assetColumn: $assetColumn, row: $row);
                        $named[$path][$namer['family']."\0".$namer['id']] = $namer;
                    }
                }
            }
        }

        return array_map('array_values', $named);
    }

    /**
     * Whether some row names `$path` right now: the fresh, per-file read a cleanup makes just before it unlinks.
     * A LIKE on the lower-cased column narrows each family to the rows that could fold to the same key (every
     * character whose other spellings differ is a wildcard), and key() decides. Resource details are read
     * whole: their JSON escapes slashes and non-ASCII characters, so no LIKE on the column can narrow them.
     */
    public static function isNamed(string $path, string $disk): bool
    {
        foreach (self::namersOf(path: $path, disk: $disk, withAssetMetadata: false) as $ignored) {
            return true;
        }

        return false;
    }

    /**
     * The rows that name `$path` right now, read the way isNamed() reads them, each once.
     *
     * @return list<array{family: string, id: int|string, asset_id: int|null}>
     */
    public static function namers(string $path, string $disk, bool $withAssetMetadata = false): array
    {
        $namers = [];

        foreach (self::namersOf(path: $path, disk: $disk, withAssetMetadata: $withAssetMetadata) as $namer) {
            $namers[$namer['family']."\0".$namer['id']] = $namer;
        }

        return array_values($namers);
    }

    /** @return Generator<int, array{family: string, id: int|string, asset_id: int|null}> */
    private static function namersOf(string $path, string $disk, bool $withAssetMetadata): Generator
    {
        $key = self::key($path);
        $pattern = self::likePattern($key);

        foreach (self::FAMILIES as [$table, $column, $assetColumn]) {
            $query = DB::table($table)->select(self::columns($column, $assetColumn))->whereNotNull($column)
                ->whereRaw('LOWER('.DB::getQueryGrammar()->wrap($column).") LIKE ? ESCAPE '!'", [$pattern]);

            if ($table === 'dam_file_objects') {
                $query->where('disk', $disk);
            }

            foreach ($query->get() as $row) {
                if (is_string($row->{$column}) && self::key($row->{$column}) === $key) {
                    yield self::namer(table: $table, column: $column, assetColumn: $assetColumn, row: $row);
                }
            }
        }

        foreach (self::jsonFamilies($withAssetMetadata) as [$table, $column, $assetColumn]) {
            foreach (self::jsonStrings(table: $table, column: $column, assetColumn: $assetColumn) as [$row, $string]) {
                if (str_contains($string, $key)) {
                    yield self::namer(table: $table, column: $column, assetColumn: $assetColumn, row: $row);
                }
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function jsonFamilies(bool $withAssetMetadata): array
    {
        return $withAssetMetadata ? [self::DETAILS, self::METADATA] : [self::DETAILS];
    }

    /** @return list<string> */
    private static function columns(string $column, ?string $assetColumn): array
    {
        return array_values(array_unique(array_filter(['id', $column, $assetColumn])));
    }

    /** @return array{family: string, id: int|string, asset_id: int|null} */
    private static function namer(string $table, string $column, ?string $assetColumn, object $row): array
    {
        $assetId = $assetColumn === null ? null : $row->{$assetColumn};

        return [
            'family'   => $table.'.'.$column,
            'id'       => is_numeric($row->id) ? (int) $row->id : (string) $row->id,
            'asset_id' => $assetId === null ? null : (int) $assetId,
        ];
    }

    /**
     * Every string in each row's JSON column, keys included, folded by key(), with its row. Text that is not
     * valid JSON counts whole, and again with its escaped slashes read as slashes.
     *
     * @return Generator<int, array{0: object, 1: string}>
     */
    private static function jsonStrings(string $table, string $column, string $assetColumn): Generator
    {
        foreach (DB::table($table)->select(self::columns($column, $assetColumn))->whereNotNull($column)->lazyById(self::CHUNK, 'id') as $row) {
            $text = (string) $row->{$column};

            try {
                $strings = self::strings(json_decode($text, true, 512, JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $strings = [$text, str_replace('\/', '/', $text)];
            }

            foreach ($strings as $string) {
                yield [$row, self::key($string)];
            }
        }
    }

    /**
     * Every string in a decoded JSON value, keys included.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $strings = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $strings[] = $key;
            }

            array_push($strings, ...self::strings($item));
        }

        return $strings;
    }

    /**
     * The wanted keys grouped by their anchor, the first segment and its slash (`assets/`, `thumbnails/`): a key
     * can only start in a string where its anchor occurs. Each anchor carries the distinct lengths of its keys.
     * A key with no such segment is `loose` and searched for whole.
     *
     * @param  array<string, list<string>>  $wanted
     * @return array{anchored: array<string, list<int>>, loose: list<string>}
     */
    private static function anchors(array $wanted): array
    {
        $anchored = [];
        $loose = [];

        foreach (array_keys($wanted) as $key) {
            $key = (string) $key;
            $slash = strpos($key, '/');

            if ($slash === false || $slash === 0) {
                $loose[] = $key;
            } else {
                $anchored[substr($key, 0, $slash + 1)][strlen($key)] = true;
            }
        }

        return ['anchored' => array_map(fn (array $lengths): array => array_keys($lengths), $anchored), 'loose' => $loose];
    }

    /**
     * The keys of `$wanted` that a folded JSON string contains, which namersOf() decides with str_contains().
     * Only the offsets where a key's anchor occurs are looked up, once per length that anchor's keys have, so a
     * string costs the same however many paths are wanted; a loose key is searched for.
     *
     * @param  array<string, list<string>>  $wanted
     * @param  array{anchored: array<string, list<int>>, loose: list<string>}  $anchors
     * @return list<string>
     */
    private static function keysWithin(string $string, array $wanted, array $anchors): array
    {
        $found = [];

        foreach ($anchors['anchored'] as $anchor => $lengths) {
            $anchor = (string) $anchor;

            for ($offset = strpos($string, $anchor); $offset !== false; $offset = strpos($string, $anchor, $offset + 1)) {
                foreach ($lengths as $length) {
                    $candidate = substr($string, $offset, $length);

                    if (strlen($candidate) === $length && isset($wanted[$candidate])) {
                        $found[] = $candidate;
                    }
                }
            }
        }

        foreach ($anchors['loose'] as $key) {
            if (str_contains($string, $key)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /** A LIKE pattern (escape `!`) every row whose key() equals `$key` matches once lower-cased. */
    private static function likePattern(string $key): string
    {
        $characters = mb_str_split($key, 1, 'UTF-8');
        $pattern = '';

        foreach ($characters as $index => $character) {
            $next = $characters[$index + 1] ?? '';

            $pattern .= match (true) {
                // Non-ASCII, or ASCII a following mark belongs to: its other spellings differ in length.
                strlen($character) > 1 || strlen($next) > 1                   => '%',
                in_array($character, self::FOLDED_FROM_NON_ASCII, true)       => '_',
                in_array($character, ['%', '_', '!'], true)                   => '!'.$character,
                default                                                       => $character,
            };
        }

        return $pattern;
    }
}
