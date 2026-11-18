<?php

/*
 * Tag names (P4 D8). TagNameRule reads this on every path that writes a tag name: the asset page, bulk add,
 * the Tags page (create and rename), REST, AI accept and import.
 *
 * The denylist is whole words, lower case. A tag is refused when any of its words, or all of its words run
 * together ("f u c k"), is on the list, after the name is folded to ASCII and common look-alike characters are
 * mapped back (0→o, 1→i, 3→e, 4→a, 5→s, 7→t, @→a, $→s). Matching whole words keeps "Scunthorpe", "class" and
 * "cocktail" usable. Add a word here and deploy; DAM_TAG_DENYLIST_EXTRA adds comma-separated words per site
 * without a code change.
 */
return [
    'max_length' => 100,

    'denylist' => array_values(array_filter(array_merge([
        'asshole',
        'bitch',
        'bullshit',
        'chink',
        'cunt',
        'faggot',
        'fuck',
        'fucked',
        'fucker',
        'fucking',
        'kike',
        'motherfucker',
        'nigga',
        'nigger',
        'pussy',
        'retard',
        'shit',
        'slut',
        'spic',
        'tranny',
        'twat',
        'wanker',
        'wetback',
        'whore',
    ], array_map(fn ($word) => mb_strtolower(trim($word)), explode(',', (string) env('DAM_TAG_DENYLIST_EXTRA', '')))))),

    // JSONL to storage/logs/dam-tags.jsonl: refusals, removals, re-index fan-out (ids and counts only, never names).
    'logging' => env('DAM_TAG_LOGGING', false),
];
