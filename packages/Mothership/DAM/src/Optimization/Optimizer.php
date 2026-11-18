<?php

namespace Mothership\DAM\Optimization;

use finfo;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Services\HomeTelemetry;
use RuntimeException;
use Throwable;

/**
 * Talks to the Studio Clop host service (docs/ops/studio-clop.md) over the fixed host/app contract:
 * raw file bytes in, either an optimized copy or a "no smaller copy" outcome back. Shaped like
 * Vectorization/Vectorizer.php — ready() gates the feature, one private client(), no redirects, and a
 * scrubbed RuntimeException on any failure so a host-side path or diagnostic never reaches the caller.
 *
 * Both directions stream through disk rather than PHP memory (round-1 review finding, 2026-09-26): the
 * PHP worker container runs with a 512M memory_limit (dockerfiles/php.ini) inside a 1 GiB container
 * (tools/studio/runtime.py), well under the 2 GiB CLOP_MAX_BYTES cap this class also enforces.
 */
class Optimizer
{
    /** Fixture-verified allowlist (Product section of the 2026-09-26 Clop Optimize spec). Never trust
     *  the host's X-Clop-Extension beyond this — Clop chooses the output format, not the caller. */
    private const OUTPUT_EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png'],
        'video' => ['mp4'],
        'pdf'   => ['pdf'],
    ];

    /** The exact sniffed MIME an allowed extension must have — not a family prefix: image/* alone
     *  would let an SVG or GIF (real finfo results, round-2 review finding, 2026-09-26) through as a
     *  claimed JPG. Keyed by extension because two extensions can share a family but not a MIME type. */
    private const OUTPUT_MIME_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'mp4'  => 'video/mp4',
        'pdf'  => 'application/pdf',
    ];

    public function ready(): bool
    {
        return filled(config('optimization.api'));
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout((int) config('optimization.connect_timeout'))
            ->timeout((int) config('optimization.timeout'))
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Optimize a local file and return either an "optimized" outcome (a fresh temp file the caller
     * owns and must delete) or a "no_reduction" outcome (nothing to attach).
     *
     * @param  array<string, scalar>  $options
     * @return array{
     *     outcome: string,
     *     old_bytes: int,
     *     path?: string,
     *     extension?: string,
     *     new_bytes?: int,
     *     width?: int|null,
     *     height?: int|null,
     * }
     */
    public function optimize(string $localPath, string $filename, string $type, array $options): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Optimization provider unavailable.');
        }

        $oldBytes = @filesize($localPath);

        if ($oldBytes === false) {
            throw new RuntimeException('Input file unavailable.');
        }

        $maxBytes = (int) config('optimization.max_bytes');

        if ($maxBytes > 0 && $oldBytes > $maxBytes) {
            throw new RuntimeException('Input file too large.');
        }

        // A raw resource, not a string: Guzzle streams it from disk chunk by chunk rather than this
        // process holding the whole (up to 2 GiB) file in memory. Guzzle wraps it into its own Stream
        // and owns closing it once the request is built; the is_resource() guard below only catches
        // the case where that handoff never happened (an exception before ->post() is reached).
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Input file unavailable.');
        }

        $sinkPath = $this->tempPath('sink');

        try {
            $base = rtrim((string) config('optimization.api'), '/');
            $query = array_merge(['type' => $type], $options);

            HomeTelemetry::record(event: 'optimization.provider_start', ctx: ['phase' => $type]);

            // The response body is written straight to $sinkPath as it arrives (Guzzle's `sink` option)
            // instead of being buffered into a PHP string: for a large video that is the same memory
            // saving on the way back that the streamed request body is on the way in.
            $response = $this->client()
                ->withHeaders(['X-Clop-Filename' => $filename])
                ->withBody($stream, 'application/octet-stream')
                ->withOptions(['sink' => $sinkPath])
                ->post($base.'/optimize?'.http_build_query($query));

            if ($response->status() === 200 && $response->header('X-Clop-Outcome') === 'no_reduction') {
                // Only ever a few bytes of JSON, so reading it into memory is not the concern the
                // binary output path above is.
                $decoded = json_decode((string) @file_get_contents($sinkPath), true);
                $reportedOldBytes = (int) ($decoded['old_bytes'] ?? 0);

                if ($reportedOldBytes <= 0) {
                    throw new RuntimeException;
                }

                HomeTelemetry::record(event: 'optimization.provider_complete', ctx: ['status' => 'no_reduction']);

                return ['outcome' => 'no_reduction', 'old_bytes' => $oldBytes];
            }

            if (! $response->successful()) {
                // Whatever the host wrote to the sink here is its (small) error JSON; never surfaced.
                throw new RuntimeException;
            }

            $extension = strtolower((string) $response->header('X-Clop-Extension'));

            if (! in_array($extension, self::OUTPUT_EXTENSIONS[$type] ?? [], true)) {
                throw new RuntimeException;
            }

            $newBytes = @filesize($sinkPath);

            // Trust the files on disk, not the host's headers, for the numbers that decide whether an
            // "optimized" result is actually smaller.
            if ($newBytes === false || $newBytes <= 0 || $newBytes >= $oldBytes) {
                throw new RuntimeException;
            }

            if (! $this->sniffedMimeMatchesExtension($sinkPath, $extension)) {
                throw new RuntimeException;
            }

            $path = $this->relocateOutput($sinkPath, $extension);
            $sinkPath = null; // ownership moved to $path; the finally block must not delete it now.

            $width = $response->hasHeader('X-Clop-Width') ? (int) $response->header('X-Clop-Width') : null;
            $height = $response->hasHeader('X-Clop-Height') ? (int) $response->header('X-Clop-Height') : null;

            HomeTelemetry::record(event: 'optimization.provider_complete', ctx: ['status' => 'optimized']);

            return [
                'outcome'   => 'optimized', 'path' => $path, 'extension' => $extension,
                'old_bytes' => $oldBytes, 'new_bytes' => $newBytes, 'width' => $width, 'height' => $height,
            ];
        } catch (Throwable $error) {
            if ($this->isUnreachable($error)) {
                HomeTelemetry::record(
                    event: 'optimization.provider_unreachable',
                    ctx: ['exception' => $error::class, 'reason' => 'provider_unreachable'],
                    level: 'error',
                );

                // Nothing was sent or produced; the caller reports the service as down, not the file as bad.
                throw new OptimizerUnreachable('Optimization provider unreachable.');
            }

            HomeTelemetry::record(
                event: 'optimization.provider_failed',
                ctx: ['exception' => $error::class],
                level: 'error',
            );
            // Do not retain a previous exception: an HTTP exception may contain the host's diagnostic body.
            throw new RuntimeException('Optimization provider unavailable.');
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }

            // $sinkPath is null only once relocateOutput() has moved the file out of this directory and
            // handed ownership to the caller; every other path (no_reduction, a scrubbed failure, an
            // exception before the sink was ever written) owns this directory outright and must not
            // leave it behind (round-2 review finding, 2026-09-26) — @unlink() on a sink that was never
            // written is a harmless no-op, and rmdir() only succeeds once the directory is empty.
            if ($sinkPath !== null) {
                $directory = dirname($sinkPath);
                @unlink($sinkPath);
                @rmdir($directory);
            }
        }
    }

    /**
     * True only for a positively identified pre-connection failure: cURL errno 6 (could not resolve the
     * host), 7 (connection refused or unreachable), or 28 (timed out) with a connect_time of exactly zero,
     * meaning the TCP connection was never established. Laravel wraps both Guzzle ConnectException and
     * RequestException into ConnectionException with the original as `previous`, so the handler context is
     * read from whichever it is. Anything ambiguous is an ordinary failure, because a bridge that is up but
     * resets mid-transfer (52, 55, 56), fails TLS (35, 60), or times out after connecting must not be
     * reported as "not running": a missing errno, a missing connect_time, a non-cURL handler and a
     * non-transport exception all count as ambiguous.
     */
    private function isUnreachable(Throwable $error): bool
    {
        if (! $error instanceof ConnectionException) {
            return false;
        }

        $previous = $error->getPrevious();

        if (! $previous instanceof ConnectException && ! $previous instanceof RequestException) {
            return false;
        }

        $context = $previous->getHandlerContext();
        $errno = $context['errno'] ?? null;

        if ($errno === CURLE_COULDNT_RESOLVE_HOST || $errno === CURLE_COULDNT_CONNECT) {
            return true;
        }

        return $errno === CURLE_OPERATION_TIMEOUTED
            && array_key_exists('connect_time', $context)
            && is_numeric($context['connect_time'])
            && (float) $context['connect_time'] === 0.0;
    }

    /** finfo against the exact extension, not the host's Content-Type or a family prefix: an output
     *  claiming to be a JPG whose bytes are an SVG, GIF or anything else never reaches the
     *  content-addressed store (round-2 review finding, 2026-09-26). */
    private function sniffedMimeMatchesExtension(string $path, string $extension): bool
    {
        $expected = self::OUTPUT_MIME_TYPES[$extension] ?? null;

        if ($expected === null) {
            return false;
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === $expected;
    }

    private function tempPath(string $prefix): string
    {
        $directory = rtrim((string) (config('optimization.staging_dir') ?: sys_get_temp_dir()), '/').'/mothership-optimize-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Local staging failed.');
        }

        return $directory.'/'.$prefix;
    }

    private function relocateOutput(string $sinkPath, string $extension): string
    {
        $path = dirname($sinkPath).'/out.'.$extension;

        if (! @rename($sinkPath, $path)) {
            throw new RuntimeException('Local staging failed.');
        }

        return $path;
    }
}
