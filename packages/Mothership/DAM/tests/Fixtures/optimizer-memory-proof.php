<?php

/**
 * Bootstraps the real application (Illuminate\Contracts\Console\Kernel, same as
 * tools/testing/vectorization-race.php) and runs the actual Mothership\DAM\Optimization\Optimizer
 * against a live local stub server (optimizer-stub-server.php), not a mock — the round-2 review's
 * finding on the round-1 proof was that a standalone Guzzle example is not production. Run in a
 * child process bounded well under the input file's size (OptimizerClientTest.php uses
 * `-d memory_limit=48M` against a 64 MiB upload) so a real streamed transfer succeeding, and the
 * "buffered" mode below failing, is direct evidence rather than an assertion about the code.
 *
 * Usage: php -d memory_limit=<n> optimizer-memory-proof.php <production|buffered> <base-url> <input-path>
 *
 * `production` calls Optimizer::optimize() exactly as OptimizeAsset does.
 * `buffered` never touches Optimizer.php; it replicates the pre-fix shape by hand — the whole input
 * read with file_get_contents() and the whole response read with ->body() — over the same Http
 * client stack, to prove that shape still exhausts memory rather than to toggle it inside the class
 * under test.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Optimization\Optimizer;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $baseUrl, $inputPath] = [$argv[1], $argv[2], $argv[3]];

config([
    'optimization.api'             => $baseUrl,
    'optimization.connect_timeout' => 10,
    'optimization.timeout'         => 30,
    'optimization.max_bytes'       => 0,
]);

if ($mode === 'production') {
    $result = app(Optimizer::class)->optimize(
        localPath: $inputPath,
        filename: 'source.png',
        type: 'image',
        options: ['scale' => '1', 'adaptive' => '0'],
    );

    fwrite(STDOUT, sprintf(
        "OK outcome=%s path=%s old_bytes=%d new_bytes=%d peak_bytes=%d\n",
        $result['outcome'],
        $result['path'] ?? '',
        $result['old_bytes'],
        $result['new_bytes'] ?? 0,
        memory_get_peak_usage(true),
    ));

    exit(0);
}

if ($mode === 'buffered') {
    $bytes = file_get_contents($inputPath);
    $response = Http::withHeaders(['X-Clop-Filename' => 'source.png'])
        ->withBody($bytes, 'application/octet-stream')
        ->post(rtrim($baseUrl, '/').'/optimize?type=image&scale=1&adaptive=0');
    $body = $response->body();

    fwrite(STDOUT, sprintf("OK status=%d bytes=%d peak_bytes=%d\n", $response->status(), strlen($body), memory_get_peak_usage(true)));

    exit(0);
}

fwrite(STDERR, "unknown mode: $mode\n");
exit(2);
