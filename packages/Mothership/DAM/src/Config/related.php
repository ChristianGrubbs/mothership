<?php

use Mothership\DAM\Services\Related\Signals\SameFileStem;
use Mothership\DAM\Services\Related\Signals\SameFolder;
use Mothership\DAM\Services\Related\Signals\SameStack;
use Mothership\DAM\Services\Related\Signals\SharedProperties;
use Mothership\DAM\Services\Related\Signals\SharedTags;
use Mothership\DAM\Services\Related\Signals\VectorSimilarity;

/*
 * Related assets v1 (P4 D14): a live ranking from content signals, never stored as relationship edges.
 *
 * Each signal finds at most `per_signal_limit` candidates through an indexed lookup among the assets the viewer
 * may list, and adds its weight to each candidate's score:
 *
 * - tags:      `weight` × Σ idf over the shared tags, idf = ln(1 + N / df), N and df counted over what the viewer
 *              may list. A tag on two of 500 files says far more than one on half of them.
 * - stack:     same stack basis and key (the same campaign, product or use case), a deliberate grouping.
 * - stem:      the same file name once case, extension, separators and copy/version suffixes are set aside
 *              ("Poster_v2.psd", "poster (1).jpg").
 * - vector:    `weight` × cosine of the AI summary vectors, only while smart search is on and at or above
 *              `vector_min_cosine`. Rescores every other signal's candidates and adds the nearest of the newest
 *              `vector_scan_limit` visible vectors.
 * - property:  `weight` per shared property value (same name and value; values over 191 characters are prose,
 *              not a shared value, and are skipped).
 * - folder:    the same folder; the weakest signal, so it mostly breaks ties.
 *
 * `signals` is the extension point: a class implementing Mothership\DAM\Services\Related\RelatedSignal (labels and
 * collections, once they exist) joins the ranking by being listed here with its weight.
 */
return [
    'limit'            => (int) env('DAM_RELATED_LIMIT', 8),
    'max_limit'        => 24,
    'per_signal_limit' => (int) env('DAM_RELATED_PER_SIGNAL_LIMIT', 50),
    'vector_scan_limit'=> (int) env('DAM_RELATED_VECTOR_SCAN_LIMIT', 2000),
    // Image-to-image summary vectors sit far closer than a text query does to an image (ai-index.min_cosine).
    'vector_min_cosine'=> is_numeric(env('DAM_RELATED_VECTOR_MIN_COSINE')) ? (float) env('DAM_RELATED_VECTOR_MIN_COSINE') : 0.75,

    'signals' => [
        'tags'     => ['class' => SharedTags::class, 'weight' => 1.0],
        'stack'    => ['class' => SameStack::class, 'weight' => 5.0],
        'stem'     => ['class' => SameFileStem::class, 'weight' => 4.0],
        'vector'   => ['class' => VectorSimilarity::class, 'weight' => 4.0],
        'property' => ['class' => SharedProperties::class, 'weight' => 2.0],
        'folder'   => ['class' => SameFolder::class, 'weight' => 1.0],
    ],

    // JSONL to storage/logs/dam-related.jsonl: per request, the signal candidate counts and timing (ids and counts only).
    'logging' => env('DAM_RELATED_LOGGING', false),
];
