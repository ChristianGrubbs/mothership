<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The lowercase hex SHA-256 of a local file (2026-09-26 finalize perf fix). PHP's own hash_file() runs
 * SHA-256 in software, at a fraction of the throughput the same process's OpenSSL extension gets from the
 * CPU's hardware SHA instructions; coreutils' sha256sum is faster still, but only pays for spawning a
 * process on a file large enough for that to matter, so it only runs at or above a configured threshold.
 *
 * The binary always runs from an argv array, never a shell, so the path can never be read as a shell
 * fragment. Any native failure — the binary missing or disabled, a non-zero exit, a timeout, or output
 * that does not parse as a digest — falls back to hash_file() silently as far as the result goes, but
 * carries a failure reason so the caller can log it.
 */
final class Sha256File
{
    public const METHOD_NATIVE = 'native';

    public const METHOD_PHP = 'php';

    /** A healthy multi-gigabyte file never approaches this; it only bounds a stuck or hung process. */
    private const NATIVE_TIMEOUT_SECONDS = 1800.0;

    /**
     * Test seam only: Sha256FileTest overrides this to make Process construction throw, since proc_open
     * cannot be disabled at runtime to exercise that path for real. Always reset to null after a test.
     *
     * @var (\Closure(array<int, string>): Process)|null
     */
    public static ?\Closure $processFactory = null;

    /**
     * @return array{sha256: string, method: 'native'|'php', failure_reason: ?string} failure_reason is set
     *                                                                                only when the native path was attempted and failed
     */
    public static function hash(string $path): array
    {
        $binary = trim((string) config('dam.explorer.upload.sha256_binary'));
        $minBytes = (int) config('dam.explorer.upload.native_sha256_min_bytes');
        $size = @filesize($path);
        $failureReason = null;

        if ($binary !== '' && $size !== false && $size >= $minBytes) {
            [$digest, $failureReason] = self::native($binary, $path);

            if ($digest !== null) {
                return ['sha256' => $digest, 'method' => self::METHOD_NATIVE, 'failure_reason' => null];
            }
        }

        $digest = @hash_file('sha256', $path);

        if ($digest === false) {
            throw new RuntimeException('checksum_failed');
        }

        return ['sha256' => $digest, 'method' => self::METHOD_PHP, 'failure_reason' => $failureReason];
    }

    /** @return array{0: ?string, 1: ?string} the lowercase hex digest, or null with a failure reason */
    private static function native(string $binary, string $path): array
    {
        if (! function_exists('proc_open')) {
            return [null, 'native_sha256_unavailable'];
        }

        try {
            // Construction (Process's own constructor throws LogicException when proc_open is missing),
            // setTimeout() and run() all share this one catch: any of them failing falls back to
            // hash_file() with a reason, rather than escaping uncaught and requeuing the finalize forever.
            $process = self::makeProcess([$binary, '--', $path]);
            $process->setTimeout(self::NATIVE_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable) {
            return [null, 'native_sha256_process_error'];
        }

        if (! $process->isSuccessful()) {
            return [null, 'native_sha256_exit_'.$process->getExitCode()];
        }

        // The exact single record GNU coreutils prints for one path: 64 lowercase hex characters, a
        // space, a mode flag (a space for text mode, `*` for binary), the path itself, and one trailing
        // newline — nothing before, nothing after. A digest followed by garbage, a second record, or a
        // non-hex prefix all fail this and fall back, rather than risk matching the wrong record.
        $pattern = '/^([a-f0-9]{64}) [ *]'.preg_quote($path, '/').'\n\z/';

        if (preg_match($pattern, $process->getOutput(), $matches) !== 1) {
            return [null, 'native_sha256_unparsable_output'];
        }

        return [$matches[1], null];
    }

    /** @param  array<int, string>  $command */
    private static function makeProcess(array $command): Process
    {
        return self::$processFactory !== null ? (self::$processFactory)($command) : new Process($command);
    }
}
