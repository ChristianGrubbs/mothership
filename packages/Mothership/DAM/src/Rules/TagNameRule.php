<?php

declare(strict_types=1);

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Mothership\DAM\Support\TagLog;

/**
 * The one rule for a tag name (P4 D8), on every path that writes one: the asset page, bulk add, the Tags page,
 * REST, AI accept and import. normalize() is what gets stored (trimmed, inner whitespace collapsed); the rule
 * refuses an empty name, one over `dam-tags.max_length`, and one the denylist covers (see Config/tags.php),
 * with a neutral message that does not repeat the word.
 */
final class TagNameRule implements ValidationRule
{
    private const LOOKALIKES = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's'];

    public static function normalize(string $name): string
    {
        // Control characters go first so a tab or newline cannot hide inside a stored name.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Whether the denylist covers this name: one of its words, or all of its words run together. */
    public static function refuses(string $name): bool
    {
        $denylist = array_flip((array) config('dam-tags.denylist', []));

        if ($denylist === []) {
            return false;
        }

        $folded = strtr(mb_strtolower(Str::ascii(self::normalize($name))), self::LOOKALIKES);
        $words = preg_split('/[^a-z]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (isset($denylist[$word])) {
                return true;
            }
        }

        return $words !== [] && isset($denylist[implode('', $words)]);
    }

    /** The reason a name is refused, or null when it may be stored. */
    public static function problem(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return trans('dam::app.admin.dam.tag.name-invalid');
        }

        $name = self::normalize((string) $value);
        $max = (int) config('dam-tags.max_length', 100);

        return match (true) {
            $name === ''                => trans('dam::app.admin.dam.tag.name-invalid'),
            mb_strlen($name) > $max     => trans('validation.max.string', ['attribute' => trans('dam::app.admin.dam.tag.datagrid.name'), 'max' => $max]),
            self::refuses($name)        => trans('dam::app.admin.dam.tag.name-refused'),
            default                     => null,
        };
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $problem = self::problem($value);

        if ($problem !== null) {
            TagLog::write(event: 'tag.name_refused', ctx: ['attribute' => mb_substr($attribute, 0, 32), 'route' => mb_substr((string) request()->route()?->getName(), 0, 32)]);
            $fail($problem);
        }
    }
}
