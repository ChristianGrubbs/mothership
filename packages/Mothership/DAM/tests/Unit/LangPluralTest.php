<?php

/*
 * Counts read as real plurals ("1 file", "2 files"), never "file(s)", and every locale keeps the same one-or-other
 * shape so a translation cannot drop a form.
 */

/** @return array<string, string> dotted key => string, for every string in a nested lang array */
function langStrings(array $lines, string $prefix = ''): array
{
    $out = [];

    foreach ($lines as $key => $value) {
        is_array($value) ? $out += langStrings($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = (string) $value;
    }

    return $out;
}

it('has no "(s)" plural in any DAM locale', function (string $locale) {
    $withParen = array_filter(langStrings(require dirname(__DIR__, 2)."/src/Resources/lang/{$locale}/app.php"), fn (string $text) => str_contains($text, '(s)'));

    expect(array_keys($withParen))->toBe([]);
})->with(['en_US', 'en_GB', 'en_AU', 'en_NZ']);

it('keeps the same number of plural forms in every locale', function () {
    $forms = fn (string $locale) => array_map(fn (string $text) => substr_count($text, '|'), langStrings(require dirname(__DIR__, 2)."/src/Resources/lang/{$locale}/app.php"));
    $base = $forms('en_US');

    foreach (['en_GB', 'en_AU', 'en_NZ'] as $locale) {
        expect($forms($locale))->toBe($base, $locale);
    }
});

it('reads one and many correctly', function () {
    expect(trans_choice('dam::app.admin.explorer.mass-actions.deleted-dirs', 1, ['count' => 1, 'source' => 'Library']))->toBe("1 folder deleted from 'Library'.")
        ->and(trans_choice('dam::app.admin.explorer.mass-actions.deleted-dirs', 3, ['count' => 3, 'source' => 'Library']))->toBe("3 folders deleted from 'Library'.")
        ->and(trans_choice('dam::app.admin.dam.asset.delete-blocked-confirm', 1, ['count' => 1]))->toStartWith('1 item is required')
        ->and(trans_choice('dam::app.admin.dam.asset.delete-blocked-confirm', 0, ['count' => 0]))->toStartWith('0 items are required');
});
