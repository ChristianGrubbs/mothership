<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

/**
 * Who wrote a value (roadmap §3.4), and how much that writer outranks another. Precedence is
 * manual > rule/mapping > AI:
 *
 * - `ai` / `ai:<model>` rank 1;
 * - `rule:<id>@v<n>`, `mapping:<id>` and P4d's `rule` and `import` channels rank 2;
 * - everything else ranks 3 and is treated as a person's value: `manual`, `merge:<id>`, an empty or unknown source.
 *   A machine writer never touches it, so a source nobody planned for fails safe.
 */
final class ValueSource
{
    public const MANUAL = 'manual';

    public const RANK_AI = 1;

    public const RANK_AUTOMATION = 2;

    public const RANK_MANUAL = 3;

    /** The machine sources a writer may name: P4d's channels, an AI model, a mapping or a rule version. */
    private const MACHINE_PATTERN = '/\A(?:ai|rule|import|ai:[A-Za-z0-9._\/-]{1,48}|mapping:\d{1,12}|rule:\d{1,12}@v\d{1,9})\z/';

    public static function rule(int $ruleId, int $version): string
    {
        return 'rule:'.$ruleId.'@v'.$version;
    }

    public static function mapping(int $mappingId): string
    {
        return 'mapping:'.$mappingId;
    }

    public static function rank(?string $source): int
    {
        $source = (string) $source;

        if ($source === 'ai' || str_starts_with($source, 'ai:')) {
            return self::RANK_AI;
        }

        if (in_array($source, ['rule', 'import'], true) || str_starts_with($source, 'rule:') || str_starts_with($source, 'mapping:')) {
            return self::RANK_AUTOMATION;
        }

        return self::RANK_MANUAL;
    }

    public static function isMachine(?string $source): bool
    {
        return self::rank($source) < self::RANK_MANUAL;
    }

    /** Whether a machine writer may name this source at all. */
    public static function validMachine(string $source): bool
    {
        return preg_match(self::MACHINE_PATTERN, $source) === 1;
    }

    /**
     * Whether a machine writer with `$writer` may replace a value written by `$existing`: never a person's value,
     * and only one of lower or equal precedence.
     */
    public static function mayReplace(string $writer, ?string $existing): bool
    {
        return self::isMachine($existing) && self::rank($existing) <= self::rank($writer);
    }
}
