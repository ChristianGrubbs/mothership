<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Validation\ValidationException;

/**
 * Reads one embedded key from an asset's raw ExifTool data and runs a mapping's transforms over it (P5b, D18).
 *
 * The key is looked up in meta_data.exif exactly, then case-insensitively, then (for a grouped key such as
 * `IPTC:Keywords`) by its bare tag name, which is how `exiftool -j` without -G names it. A list value is one value
 * per item. Transforms run in order over every value:
 *
 * - split {delimiter}: one value per part;
 * - trim; lowercase;
 * - regex {pattern, group?}: keeps the capture group (0 = whole match) of values that match, drops the rest;
 * - map {values: {from: to}, drop_unmapped?}: replaces exact (case-insensitive) matches; unmapped values stay
 *   unless drop_unmapped.
 *
 * Empty values are dropped and duplicates folded, case-insensitively, keeping the first spelling.
 */
final class MappingTransforms
{
    public const TYPES = ['split', 'trim', 'lowercase', 'regex', 'map'];

    /** Values longer than this are cut: a mapping writes tags, labels and field values, never documents. */
    private const MAX_VALUE = 191;

    private const MAX_VALUES = 100;

    /** @return list<string> */
    public static function read(array $metaData, string $key): array
    {
        $exif = is_array($metaData['exif'] ?? null) ? $metaData['exif'] : [];

        if (array_is_list($exif) && isset($exif[0]) && is_array($exif[0])) {
            $exif = $exif[0];
        }

        $value = self::lookup($exif, $key) ?? self::lookup($metaData, $key);

        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? array_values(array_filter($value, fn ($item) => is_scalar($item))) : [$value];

        return array_values(array_map(fn ($item) => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, $values));
    }

    /**
     * @param  list<string>  $values
     * @param  list<array<string, mixed>>  $transforms
     * @return list<string>
     */
    public static function apply(array $values, array $transforms): array
    {
        foreach ($transforms as $transform) {
            $values = match ($transform['type'] ?? null) {
                'split'     => array_merge([], ...array_map(fn (string $value) => explode((string) $transform['delimiter'], $value), $values)),
                'trim'      => array_map('trim', $values),
                'lowercase' => array_map('mb_strtolower', $values),
                'regex'     => self::extract($values, (string) $transform['pattern'], (int) ($transform['group'] ?? 0)),
                'map'       => self::map($values, (array) ($transform['values'] ?? []), (bool) ($transform['drop_unmapped'] ?? false)),
                default     => $values,
            };
        }

        $seen = [];
        $result = [];

        foreach ($values as $value) {
            $value = mb_substr(trim($value), 0, self::MAX_VALUE);
            $fold = mb_strtolower($value);

            if ($value === '' || isset($seen[$fold])) {
                continue;
            }

            $seen[$fold] = true;
            $result[] = $value;

            if (count($result) >= self::MAX_VALUES) {
                break;
            }
        }

        return $result;
    }

    /**
     * The transforms as stored, or a 422 on `transforms.N.<key>`.
     *
     * @return list<array<string, mixed>>
     */
    public static function validate(mixed $transforms): array
    {
        if ($transforms === null || $transforms === []) {
            return [];
        }

        if (! is_array($transforms) || ! array_is_list($transforms) || count($transforms) > 20) {
            throw ValidationException::withMessages(['transforms' => [trans('dam::app.admin.automation.validation.transforms')]]);
        }

        $clean = [];

        foreach ($transforms as $index => $transform) {
            $type = is_array($transform) ? ($transform['type'] ?? null) : null;
            $error = fn (string $key) => ValidationException::withMessages(["transforms.$index.$key" => [trans('dam::app.admin.automation.validation.transform-'.$key)]]);

            if (! in_array($type, self::TYPES, true)) {
                throw $error('type');
            }

            $clean[] = match ($type) {
                'split' => is_string($transform['delimiter'] ?? null) && $transform['delimiter'] !== '' && mb_strlen($transform['delimiter']) <= 8
                    ? ['type' => 'split', 'delimiter' => $transform['delimiter']] : throw $error('delimiter'),
                'regex' => self::validPattern($transform['pattern'] ?? null) && is_numeric($transform['group'] ?? 0) && (int) ($transform['group'] ?? 0) >= 0 && (int) ($transform['group'] ?? 0) <= 9
                    ? ['type' => 'regex', 'pattern' => $transform['pattern'], 'group' => (int) ($transform['group'] ?? 0)] : throw $error('pattern'),
                'map'   => self::validMap($transform['values'] ?? null)
                    ? ['type' => 'map', 'values' => array_map('strval', $transform['values']), 'drop_unmapped' => (bool) ($transform['drop_unmapped'] ?? false)] : throw $error('values'),
                default => ['type' => $type],
            };
        }

        return $clean;
    }

    private static function lookup(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        // Then case-insensitively, then by bare tag name on either side: `IPTC:Keywords` finds a plain `Keywords`
        // (ExifTool -j without groups) and `Make` finds `EXIF:Make`. The first bare match wins.
        $lower = mb_strtolower($key);
        $bare = self::bare($lower);
        $bareMatch = null;

        foreach ($data as $candidate => $value) {
            $candidate = mb_strtolower((string) $candidate);

            if ($candidate === $lower) {
                return $value;
            }

            if ($bareMatch === null && self::bare($candidate) === $bare) {
                $bareMatch = $value;
            }
        }

        return $bareMatch;
    }

    private static function bare(string $key): string
    {
        return str_contains($key, ':') ? substr($key, (int) strrpos($key, ':') + 1) : $key;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function extract(array $values, string $pattern, int $group): array
    {
        $kept = [];

        foreach ($values as $value) {
            if (@preg_match($pattern, $value, $match) === 1 && isset($match[$group])) {
                $kept[] = $match[$group];
            }
        }

        return $kept;
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $map
     * @return list<string>
     */
    private static function map(array $values, array $map, bool $dropUnmapped): array
    {
        $folded = [];

        foreach ($map as $from => $to) {
            $folded[mb_strtolower(trim((string) $from))] = (string) $to;
        }

        $mapped = [];

        foreach ($values as $value) {
            $key = mb_strtolower(trim($value));

            if (array_key_exists($key, $folded)) {
                $mapped[] = $folded[$key];
            } elseif (! $dropUnmapped) {
                $mapped[] = $value;
            }
        }

        return $mapped;
    }

    private static function validPattern(mixed $pattern): bool
    {
        return is_string($pattern) && $pattern !== '' && strlen($pattern) <= 255 && @preg_match($pattern, '') !== false;
    }

    private static function validMap(mixed $values): bool
    {
        if (! is_array($values) || $values === [] || count($values) > 200 || array_is_list($values)) {
            return false;
        }

        foreach ($values as $from => $to) {
            if (! is_scalar($to) || mb_strlen((string) $from) > 191 || mb_strlen((string) $to) > 191) {
                return false;
            }
        }

        return true;
    }
}
