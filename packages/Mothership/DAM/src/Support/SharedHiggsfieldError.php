<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use RuntimeException;
use Throwable;

/**
 * A classified shared-subscription failure.
 *
 * Before 2026-09-16 every CLI failure — an unreachable binary, a malformed response, a
 * completed generation whose URL had not landed yet — arrived at the caller as the same
 * bare RuntimeException carrying one generic sentence and nothing else. SphereSeam's job
 * turned any of them into a terminal `failed`, so a completed and charged generation was
 * lost, and no log line recorded why.
 *
 * This keeps the user-facing message exactly as generic as it was, and carries the
 * diagnosis beside it: whether a retry can help, which CLI operation failed, the exit
 * code, and a redacted excerpt of the provider's own stderr.
 *
 * Extends RuntimeException so every existing catch site keeps working unchanged.
 */
class SharedHiggsfieldError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly string $reason,
        public readonly string $operation = 'unknown',
        public readonly ?int $exitCode = null,
        public readonly string $stderrExcerpt = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * CLI output echoes the command it ran, which carries the full generation prompt, and
     * can carry session material from the vendor's saved credentials. Everything from the
     * first --prompt onward is dropped, long opaque runs and labelled secrets are masked,
     * and what remains is truncated before it can reach a log sink.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_split('/--prompt\b/i', $text, 2)[0];
        $text = (string) preg_replace('/\b(bearer|token|api[_-]?key|key|secret|password|session)\b["\':=\s]+\S+/i', '$1 [redacted]', $text);
        $text = (string) preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]', $text);

        return mb_substr(trim($text), 0, 500);
    }
}
