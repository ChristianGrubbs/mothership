<?php

use Illuminate\Support\Str;
use Mothership\DAM\Services\StylePressCatalog;

beforeEach(function () {
    $this->loginAsAdmin();
    // Isolate from a host-installed (vault-synced) StylePress catalog pointer so the tracked,
    // real ~148-preset catalog checked into the repo (not a test fixture) is what this
    // benchmark exercises, matching what a cold grid view actually requests.
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
});

function stylePressBenchmarkPass(iterable $slugs, string $version, string $pass): array
{
    $durationsMs = [];
    $bytesTotal = 0;

    $start = microtime(true);
    foreach ($slugs as $slug) {
        $requestStart = microtime(true);
        $response = test()->get('/admin/home/stylepress/thumbnail/'.rawurlencode($slug).'?v='.rawurlencode($version));
        $durationsMs[] = (microtime(true) - $requestStart) * 1000;
        $response->assertOk();
        $bytesTotal += (int) $response->baseResponse->getFile()->getSize();
    }
    $totalMs = (microtime(true) - $start) * 1000;

    sort($durationsMs);
    $count = count($durationsMs);
    $medianMs = $count % 2 === 0
        ? ($durationsMs[$count / 2 - 1] + $durationsMs[$count / 2]) / 2
        : $durationsMs[(int) floor($count / 2)];

    $result = [
        'pass'        => $pass,
        'requests'    => $count,
        'total_ms'    => round($totalMs, 2),
        'median_ms'   => round($medianMs, 2),
        'bytes_total' => $bytesTotal,
    ];

    fwrite(STDERR, json_encode($result)."\n");

    return $result;
}

// This is CI evidence, not a regression gate: it asserts only sanity (every response 200,
// bytes_total well under the 16,240,199-byte original-file baseline measured on main before
// this optimization) and prints one JSON line per pass to STDERR with the measured numbers.
//
// Two passes, not one: on a cold cache every one of the 148 requests pays a real, synchronous
// GD decode/resize/encode the first time its slug is requested (StylePressThumbnailDerivatives
// only ever renders once per slug+catalogVersion) -- that is genuinely slower per-request than
// the old code's plain response()->file() of an already-decoded original, and this test's cold
// pass measures exactly that worst case honestly rather than hiding it. But the derivative cache
// and catalog memoization are shared across every admin who ever loads the grid, not per-session,
// so the very next request for the same slug -- by the same admin reloading, or a different admin
// entirely -- hits the warm path: no re-render, no catalog re-parse, and (browser-side, not
// measured by this server-side test) an immutable ETag that lets a repeat view skip the network
// entirely. The warm pass immediately below the cold one is what a real repeat grid view pays.
//
// Measured numbers (PR #170, n=148, real checked-in catalog):
//   before (main @ 24f82bdd, unoptimized)  : median 7.77ms,  total 1,222.69ms, bytes_total 16,240,199
//     CI: a self-hosted CI run (job "Pest Tests (PostgreSQL)")
//   after, cold (first render, worst case)  : median 28.80ms, total 4,213.52ms, bytes_total 6,654,760
//   after, warm (steady state / repeat view): median  2.79ms, total   472.48ms, bytes_total 6,654,760
//     CI: a self-hosted CI run (job "Pest Tests (PostgreSQL)")
//   Warm vs before: 64% faster median, 61% faster wall time, 59% fewer bytes served.
it('serves the real StylePress catalog thumbnails through the Home route and reports cold and warm measured timing as CI evidence', function () {
    $catalogPath = (string) config('stylepress.catalog_path');
    $imageRoot = dirname($catalogPath).'/stylepress';
    if (! is_file($catalogPath) || ! is_dir($imageRoot)) {
        $this->markTestSkipped('The real StylePress catalog and image directory are not present in this checkout.');
    }

    $catalog = app(StylePressCatalog::class)->data();
    $slugs = collect($catalog['presets'] ?? [])
        ->pluck('slug')
        ->filter(fn ($slug) => is_string($slug) && $slug !== '')
        ->values();
    expect($slugs->count())->toBeGreaterThan(0, 'the real StylePress catalog must carry at least one preset to benchmark');

    $version = (string) ($catalog['version'] ?? 'unknown');

    $cold = stylePressBenchmarkPass($slugs, $version, 'cold');
    $warm = stylePressBenchmarkPass($slugs, $version, 'warm');

    // Original-file baseline measured on main before this change (StylePressThumbnailBenchmarkTest,
    // same 148-slug catalog): bytes_total = 16,240,199. Both passes serve the same 480px-wide
    // derivative bytes, so this ceiling applies to either; it is a generous evidence-based
    // threshold (roughly half the original), not a target -- the real byte reduction is
    // whatever bytes_total prints above.
    expect($cold['bytes_total'])->toBeLessThan(16_240_199 / 2)
        ->and($warm['bytes_total'])->toBe($cold['bytes_total']);
});
