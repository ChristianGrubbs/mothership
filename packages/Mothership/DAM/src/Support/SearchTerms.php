<?php

namespace Mothership\DAM\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Normalizer;

/**
 * How a typed search reaches a LIKE predicate. A name stored decomposed (NFD, what macOS file pickers hand
 * over) never matched the same word typed precomposed (NFC), so every term is tried in both forms. A term
 * is split on whitespace and every word has to match, so "photo normal" finds "photo - normal.jpg" as well
 * as the exact phrase. `%` and `_` in a word are literal characters, not wildcards.
 */
final class SearchTerms
{
    /** The pattern escape character: `LIKE ? ESCAPE '!'`. */
    public const ESCAPE = '!';

    /** @return list<string> the whitespace-separated words, in order */
    public static function words(string $term): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($term)) ?: [], fn (string $word): bool => $word !== ''));
    }

    /** @return list<string> `%word%` patterns for the composed and decomposed spellings of one word, lower-cased */
    public static function patterns(string $word): array
    {
        $spellings = [$word];

        if (class_exists(Normalizer::class)) {
            $spellings = array_filter([
                Normalizer::normalize($word, Normalizer::FORM_C),
                Normalizer::normalize($word, Normalizer::FORM_D),
            ], fn ($form): bool => is_string($form) && $form !== '') ?: [$word];
        }

        return array_values(array_unique(array_map(
            fn (string $spelling): string => '%'.self::escape(mb_strtolower($spelling)).'%',
            $spellings,
        )));
    }

    public static function escape(string $value): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $value);
    }

    /**
     * Narrows `$query` to rows where every word of `$term` matches at least one spelling in `$match`.
     * `$match($builder, $pattern)` adds the OR-able test of one escaped pattern against the columns searched.
     *
     * @param  callable(Builder, string): void  $match
     */
    public static function apply($query, string $term, callable $match): void
    {
        foreach (self::words($term) as $word) {
            $patterns = self::patterns($word);

            $query->where(function ($wordQuery) use ($patterns, $match): void {
                foreach ($patterns as $pattern) {
                    $wordQuery->orWhere(function ($inner) use ($match, $pattern): void {
                        $match($inner, $pattern);
                    });
                }
            });
        }
    }

    /** `LOWER(column) LIKE ? ESCAPE '!'` as a SQL fragment; `$column` is already wrapped/prefixed SQL. */
    public static function likeSql(string $column): string
    {
        return 'LOWER('.$column.") LIKE ? ESCAPE '".self::ESCAPE."'";
    }
}
