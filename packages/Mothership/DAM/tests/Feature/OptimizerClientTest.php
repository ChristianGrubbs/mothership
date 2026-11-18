<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Optimization\OptimizerUnreachable;

// Staging lives in a directory private to this test: the self-hosted runners share one /tmp, so a
// glob of the system temp directory also saw a concurrent job's staging and failed the leak checks.
beforeEach(function () {
    $this->stagingRoot = sys_get_temp_dir().'/optimizer-client-test-'.bin2hex(random_bytes(8));
    mkdir($this->stagingRoot, 0700);
    config(['optimization.api' => 'http://clop.test', 'optimization.connect_timeout' => 5, 'optimization.timeout' => 960, 'optimization.max_bytes' => 0, 'optimization.staging_dir' => $this->stagingRoot]);
    Http::preventStrayRequests();
});

afterEach(function () {
    foreach (glob($this->stagingRoot.'/mothership-optimize-*/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($this->stagingRoot.'/mothership-optimize-*') ?: [] as $directory) {
        @rmdir($directory);
    }
    @rmdir($this->stagingRoot);
});

/** 2000 bytes by default: comfortably bigger than any of this file's tiny real-media fixtures, so a
 *  genuine "optimized" outcome's new_bytes < old_bytes check has real headroom to pass on. */
function optimizerFixturePath(?string $bytes = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'optimizer-input-');
    file_put_contents($path, $bytes ?? str_repeat('source bytes ', 200));

    return $path;
}

/** A real, finfo-sniffable JPEG small enough to stay comfortably under optimizerFixturePath()'s input size. */
function optimizerJpegBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'optimizer-jpg-');
    imagejpeg(imagecreatetruecolor(4, 4), $path, 90);
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function optimizerPngBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'optimizer-png-');
    imagepng(imagecreatetruecolor(4, 4), $path);
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** The minimum finfo needs to sniff application/pdf. */
function optimizerPdfBytes(): string
{
    return "%PDF-1.4\n%%EOF";
}

/** A minimal MP4 `ftyp` box: enough structure for finfo to report video/mp4. */
function optimizerMp4Bytes(): string
{
    return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";
}

function optimizerSvgBytes(): string
{
    return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"></svg>';
}

function optimizerGifBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'optimizer-gif-');
    imagegif(imagecreatetruecolor(4, 4), $path);
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function optimizerCleanup(?string $path): void
{
    if ($path === null) {
        return;
    }

    @unlink($path);
    @rmdir(dirname($path));
}

/** @return list<string> every mothership-optimize-* staging directory Optimizer::optimize() owns. */
function optimizerStagingDirectories(): array
{
    return glob(config('optimization.staging_dir').'/mothership-optimize-*') ?: [];
}

it('reports unready without a configured api', function () {
    config(['optimization.api' => null]);
    expect(app(Optimizer::class)->ready())->toBeFalse();
});

it('reports ready with a configured api', function () {
    expect(app(Optimizer::class)->ready())->toBeTrue();
});

it('sends the filename header and streamed body, and stages a real optimized JPEG', function () {
    Http::fake(['clop.test/optimize*' => Http::response(optimizerJpegBytes(), 200, ['X-Clop-Extension' => 'JPG'])]);
    $path = optimizerFixturePath();
    $result = null;

    try {
        $result = app(Optimizer::class)->optimize(localPath: $path, filename: 'source.png', type: 'image', options: ['scale' => '1', 'adaptive' => '0']);
        expect($result['outcome'])->toBe('optimized')
            ->and($result['extension'])->toBe('jpg')
            // Derived from the actual files on disk, not the (absent, in this fixture) X-Clop-*-Bytes headers.
            ->and($result['old_bytes'])->toBe(filesize($path))
            ->and($result['new_bytes'])->toBe(strlen(optimizerJpegBytes()))
            ->and($result['new_bytes'])->toBeLessThan($result['old_bytes'])
            ->and(mime_content_type($result['path']))->toBe('image/jpeg');
    } finally {
        optimizerCleanup($result['path'] ?? null);
        unlink($path);
    }

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/optimize?')
            && str_contains($request->url(), 'type=image')
            && str_contains($request->url(), 'scale=1')
            && str_contains($request->url(), 'adaptive=0')
            && $request->hasHeader('X-Clop-Filename', 'source.png');
    });
});

it('stages a real optimized PNG, MP4 and PDF for their matching type', function (string $type, string $extension, string $bytes, string $expectedMime) {
    Http::fake(['clop.test/optimize*' => Http::response($bytes, 200, ['X-Clop-Extension' => $extension])]);
    $path = optimizerFixturePath();
    $result = null;

    try {
        $result = app(Optimizer::class)->optimize(localPath: $path, filename: "source.$extension", type: $type, options: []);
        expect($result['outcome'])->toBe('optimized')
            ->and($result['extension'])->toBe($extension)
            ->and(mime_content_type($result['path']))->toBe($expectedMime);
    } finally {
        optimizerCleanup($result['path'] ?? null);
        unlink($path);
    }
})->with([
    'jpeg' => ['image', 'jpeg', fn () => optimizerJpegBytes(), 'image/jpeg'],
    'png'  => ['image', 'png', fn () => optimizerPngBytes(), 'image/png'],
    'mp4'  => ['video', 'mp4', fn () => optimizerMp4Bytes(), 'video/mp4'],
    'pdf'  => ['pdf', 'pdf', fn () => optimizerPdfBytes(), 'application/pdf'],
]);

it('rejects an output extension outside the type\'s allowlist even when the bytes look real', function () {
    // A real JPEG, but claimed as a video output — Clop would never do this; a compromised or buggy
    // host might, and the allowlist must catch it regardless of what finfo says about the bytes.
    Http::fake(['clop.test/optimize*' => Http::response(optimizerJpegBytes(), 200, ['X-Clop-Extension' => 'jpg'])]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'an allowlist rejection must not leave its staging directory behind');
    } finally {
        unlink($path);
    }
});

it('rejects an allowlisted extension whose bytes are not actually that type', function (string $extension, string $bytes) {
    // Round-1 review's fixture (plain text claimed as JPG) only proved finfo runs at all; a family
    // check (image/* for any of jpg/jpeg/png) would still let an SVG or GIF pass as a claimed JPG.
    // Round-2 review finding, 2026-09-26: check the sniffed MIME against the specific extension.
    Http::fake(['clop.test/optimize*' => Http::response($bytes, 200, ['X-Clop-Extension' => $extension])]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: "source.$extension", type: 'image', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'a MIME-mismatch rejection must not leave its staging directory behind');
    } finally {
        unlink($path);
    }
})->with([
    'plain text as jpg'  => ['jpg', 'not actually a jpeg, just text'],
    'SVG as jpg'         => ['jpg', fn () => optimizerSvgBytes()],
    'GIF as jpg'         => ['jpg', fn () => optimizerGifBytes()],
    'PNG as jpg'         => ['jpg', fn () => optimizerPngBytes()],
    'JPEG as png'        => ['png', fn () => optimizerJpegBytes()],
]);

it('rejects an empty output body', function () {
    Http::fake(['clop.test/optimize*' => Http::response('', 200, ['X-Clop-Extension' => 'jpg'])]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.png', type: 'image', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'an empty-body rejection must not leave its staging directory behind');
    } finally {
        unlink($path);
    }
});

it('rejects an output that is not actually smaller than the input', function () {
    $path = optimizerFixturePath('tiny');
    // Bigger than the 4-byte input above: never a real reduction, whatever the host claims.
    Http::fake(['clop.test/optimize*' => Http::response(optimizerJpegBytes(), 200, ['X-Clop-Extension' => 'jpg'])]);
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.png', type: 'image', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'a not-actually-smaller rejection must not leave its staging directory behind');
    } finally {
        unlink($path);
    }
});

it('reports a no_reduction outcome without staging a local file', function () {
    Http::fake(['clop.test/optimize*' => Http::response(
        json_encode(['outcome' => 'no_reduction', 'old_bytes' => 500]),
        200,
        ['Content-Type' => 'application/json', 'X-Clop-Outcome' => 'no_reduction'],
    )]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        $result = app(Optimizer::class)->optimize(localPath: $path, filename: 'source.pdf', type: 'pdf', options: []);
        expect($result)->toBe(['outcome' => 'no_reduction', 'old_bytes' => filesize($path)])
            ->and(optimizerStagingDirectories())->toBe($before, 'a no_reduction outcome must not leave its staging directory behind (round-2 review finding, 2026-09-26)');
    } finally {
        unlink($path);
    }
});

it('scrubs an unprocessable option response instead of leaking it', function () {
    Http::fake(['clop.test/optimize*' => Http::response(['error' => 'invalid_option'], 422)]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: ['compression' => '999']))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'a scrubbed provider error must not leave its staging directory behind (round-2 review finding, 2026-09-26)');
    } finally {
        unlink($path);
    }
});

it('scrubs a timeout response instead of leaking it', function () {
    Http::fake(['clop.test/optimize*' => Http::response(['error' => 'timeout'], 504)]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
        expect(optimizerStagingDirectories())->toBe($before, 'a scrubbed timeout must not leave its staging directory behind (round-2 review finding, 2026-09-26)');
    } finally {
        unlink($path);
    }
});

it('leaves no staging directory behind when the connection fails before any output arrives', function () {
    Http::fake(['clop.test/optimize*' => fn () => throw optimizerTransportFailure('connect', 7, 0.0)]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []))
            ->toThrow(OptimizerUnreachable::class, 'Optimization provider unreachable.');
        expect(optimizerStagingDirectories())->toBe($before, 'a transport failure must not leave its staging directory behind (round-3 review finding, 2026-09-26)');
    } finally {
        unlink($path);
    }
});

/** A transport failure as Laravel actually surfaces it: ConnectionException wrapping the Guzzle exception
 *  (ConnectException, or RequestException for non-connect transport errors) with cURL's handler context. */
function optimizerTransportFailure(string $guzzle, int $errno, ?float $connectTime): ConnectionException
{
    $context = ['errno' => $errno] + ($connectTime === null ? [] : ['connect_time' => $connectTime]);
    $request = new Request('POST', 'http://clop.test/optimize');
    $previous = $guzzle === 'connect'
        ? new ConnectException('cURL error '.$errno, $request, null, $context)
        : new RequestException('cURL error '.$errno, $request, null, null, $context);

    return new ConnectionException('cURL error '.$errno, 0, $previous);
}

function optimizerOutcome(Throwable $failure): ?Throwable
{
    Http::fake(['clop.test/optimize*' => fn () => throw $failure]);
    $path = optimizerFixturePath();

    try {
        app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []);

        return null;
    } catch (Throwable $error) {
        return $error;
    } finally {
        unlink($path);
    }
}

it('classifies only a pre-connection transport failure as unreachable', function (string $guzzle, int $errno, ?float $connectTime, bool $unreachable) {
    $thrown = optimizerOutcome(optimizerTransportFailure($guzzle, $errno, $connectTime));

    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    $unreachable
        ? expect($thrown)->toBeInstanceOf(OptimizerUnreachable::class)->and($thrown->getMessage())->toBe('Optimization provider unreachable.')
        : expect($thrown)->not->toBeInstanceOf(OptimizerUnreachable::class)->and($thrown->getMessage())->toBe('Optimization provider unavailable.');
})->with([
    'resolve failure (6)'                          => ['connect', 6, 0.0, true],
    'refused (7)'                                  => ['connect', 7, 0.0, true],
    'refused (7), no timing in context'            => ['connect', 7, null, true],
    'refused (7) as RequestException'              => ['request', 7, 0.0, true],
    'connect timeout (28, connect_time 0)'         => ['connect', 28, 0.0, true],
    'timeout after connecting (28, 0.01)'          => ['connect', 28, 0.01, false],
    'timeout, no connect_time (ambiguous)'         => ['connect', 28, null, false],
    'TLS handshake (35)'                           => ['connect', 35, 0.0, false],
    'empty reply (52)'                             => ['request', 52, 0.02, false],
    'reset during transfer (55)'                   => ['request', 55, 0.02, false],
    'reset during transfer (56)'                   => ['request', 56, 0.02, false],
    'TLS certificate (60)'                         => ['request', 60, 0.02, false],
    'timeout after connecting as RequestException' => ['request', 28, 0.5, false],
]);

it('never calls a context-free or non-Guzzle ConnectionException unreachable', function () {
    expect(optimizerOutcome(new ConnectionException('connection refused')))->not->toBeInstanceOf(OptimizerUnreachable::class)
        ->and(optimizerOutcome(new ConnectionException('x', 0, new RuntimeException('y'))))->not->toBeInstanceOf(OptimizerUnreachable::class)
        ->and(optimizerOutcome(new ConnectionException('x', 0, new ConnectException('no cURL', new Request('POST', 'http://clop.test/optimize')))))->not->toBeInstanceOf(OptimizerUnreachable::class);
});

it('leaves no staging directory behind and the input untouched on a refused connection', function () {
    Http::fake(['clop.test/optimize*' => fn () => throw optimizerTransportFailure('connect', 7, 0.0)]);
    $path = optimizerFixturePath();
    $before = optimizerStagingDirectories();
    $hash = hash_file('sha256', $path);

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []))
            ->toThrow(OptimizerUnreachable::class, 'Optimization provider unreachable.');
        expect(optimizerStagingDirectories())->toBe($before)
            ->and(hash_file('sha256', $path))->toBe($hash);
    } finally {
        unlink($path);
    }
});

it('does not call an HTTP error from the bridge unreachable', function () {
    Http::fake(['clop.test/optimize*' => Http::response(['error' => 'boom'], 500)]);
    $path = optimizerFixturePath();

    try {
        $thrown = null;

        try {
            app(Optimizer::class)->optimize(localPath: $path, filename: 'source.mp4', type: 'video', options: []);
        } catch (Throwable $error) {
            $thrown = $error;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown)->not->toBeInstanceOf(OptimizerUnreachable::class);
    } finally {
        unlink($path);
    }
});

it('refuses to optimize when the provider is not configured', function () {
    config(['optimization.api' => null]);
    $path = optimizerFixturePath();

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.png', type: 'image', options: []))
            ->toThrow(RuntimeException::class, 'Optimization provider unavailable.');
    } finally {
        unlink($path);
    }
});

it('rejects a file over the configured byte cap without a request', function () {
    config(['optimization.max_bytes' => 4]);
    Http::fake(['*' => Http::response('unused', 200)]);
    $path = optimizerFixturePath('twelve bytes here');

    try {
        expect(fn () => app(Optimizer::class)->optimize(localPath: $path, filename: 'source.png', type: 'image', options: []))
            ->toThrow(RuntimeException::class);
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
});

it('never reads the whole input or output into memory (round-1 review finding, 2026-09-26)', function () {
    // Grep, not behavioural: a fast, deterministic guard against reintroducing file_get_contents()
    // on the local input path or ->body()/->json() on the binary success response. The process-level
    // proof that this actually matters is the next test.
    $source = file_get_contents(__DIR__.'/../../src/Optimization/Optimizer.php');

    expect($source)->not->toContain('file_get_contents($localPath)')
        ->and(preg_match('/\$response->body\(\)/', $source))->toBe(0);
});

it('runs the real Optimizer::optimize() against a live host far past memory_limit, and proves the pre-fix buffered shape would not', function () {
    // Round-2 review finding, 2026-09-26: round-1's proof exercised a standalone Guzzle example, not
    // production. This one bootstraps the real application (optimizer-memory-proof.php, the same
    // shape as tools/testing/vectorization-race.php) and calls the actual Optimizer::optimize()
    // against a live local server. The stub is Python's http.server (optimizer-stub-server.py), not
    // `php -S`: PHP's built-in dev server mishandles a large streamed POST's `Expect: 100-continue`
    // negotiation and corrupts the response, which would make this proof fail for the wrong reason —
    // Python's http.server is the same stdlib family the real host service is built on.
    // Port 0: the stub binds a free port and prints it. Both self-hosted runners share one host, so a
    // random fixed port could already be taken ("Address already in use", PR #68 run, 2026-09-28).
    $outputBytes = 32 * 1024 * 1024;
    $server = Process::forever()
        ->env(['OPTIMIZER_STUB_OUTPUT_BYTES' => (string) $outputBytes])
        ->start(['python3', __DIR__.'/../Fixtures/optimizer-stub-server.py', '0']);

    try {
        $baseUrl = '';
        $deadline = microtime(true) + 5;
        $up = false;

        while (microtime(true) < $deadline) {
            if ($baseUrl === '' && preg_match('#listening on (http://127\.0\.0\.1:\d+)#', $server->output(), $match)) {
                $baseUrl = $match[1];
            }

            $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);

            if ($baseUrl !== '' && @file_get_contents("$baseUrl/health", false, $context) !== false) {
                $up = true;

                break;
            }

            usleep(50000);
        }

        expect($up)->toBeTrue('the stub server at '.$baseUrl.' never came up: '.$server->latestOutput().$server->latestErrorOutput());

        $input = tempnam(sys_get_temp_dir(), 'optimizer-proof-input-');
        $handle = fopen($input, 'wb');
        $chunk = str_repeat('x', 1024 * 1024);

        for ($i = 0; $i < 64; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);

        $fixture = __DIR__.'/../Fixtures/optimizer-memory-proof.php';
        $outputPath = null;

        try {
            // 96M, not the 48M round 1 used: booting the real application (config, providers,
            // container) alone costs real memory before a single byte of the transfer happens. What
            // matters is the contrast, not the absolute number — 96M is still nowhere near the
            // ~64 MiB request + 32 MiB response a buffered approach would hold at once, on top of
            // that same boot cost.
            $stream = Process::timeout(30)->run(['php', '-d', 'memory_limit=96M', $fixture, 'production', $baseUrl, $input]);

            expect($stream->successful())->toBeTrue("production mode failed:\n".$stream->errorOutput().$stream->output())
                ->and($stream->output())->toContain('OK outcome=optimized');

            preg_match('/path=(\S+)/', $stream->output(), $match);
            $outputPath = $match[1] ?? null;

            expect($outputPath)->not->toBeNull()
                ->and(is_file($outputPath))->toBeTrue()
                ->and(filesize($outputPath))->toBe($outputBytes)
                ->and(mime_content_type($outputPath))->toBe('image/jpeg');

            $buffered = Process::timeout(30)->run(['php', '-d', 'memory_limit=96M', $fixture, 'buffered', $baseUrl, $input]);

            expect($buffered->successful())->toBeFalse('the pre-fix file_get_contents()+->body() shape was expected to exhaust a 96M limit on this 64 MiB file, but it did not — this proof no longer demonstrates the fix')
                ->and($buffered->errorOutput().$buffered->output())->toContain('Allowed memory size');
        } finally {
            @unlink($input);
            optimizerCleanup($outputPath);
        }
    } finally {
        $server->stop();
    }
})->skip(fn () => trim((string) shell_exec('command -v python3')) === '', 'python3 is not available on this machine');
