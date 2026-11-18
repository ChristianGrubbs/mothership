<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Feedback;

/**
 * Strips secrets from what a report carries: page URLs and the console lines the browser collected. The browser runs
 * the same rules for its preview (feedback.js); this copy is the one that counts. Redaction runs before truncation, so
 * a cut can never expose half a secret.
 */
final class FeedbackRedactor
{
    public const MASK = '[redacted]';

    /** Query keys whose values never leave the page; any key containing one of these fragments matches too. */
    private const SENSITIVE_KEY = '/token|secret|pass|key|sig|auth|code|state|session|cookie|share|expires|^t$|^s$/i';

    private const CONSOLE_PATTERNS = [
        // Bearer and Basic credentials.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
        // JWT-shaped strings.
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/',
        // name=value and name: value pairs with a sensitive name (query strings, logged objects, form bodies). A quoted
        // value is masked through its closing quote, escapes included, so a secret with spaces cannot leave a tail.
        '/\b([A-Za-z0-9_.-]*(?:token|secret|pass(?:word)?|api[_-]?key|sig(?:nature)?|auth|session|cookie|xsrf|csrf)[A-Za-z0-9_.-]*)(["\']?\s*[=:]\s*)("(?:[^"\\\\]|\\\\.)*"?|\'(?:[^\'\\\\]|\\\\.)*\'?|[^\s"\'&,;}]+)/i',
        // Long opaque runs (keys, share tokens, hashes).
        '/\b[A-Za-z0-9_-]{32,}\b/',
    ];

    public function url(?string $url, int $max = 2048): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['path'])) {
            return null;
        }

        // Only the path and a cleaned query are kept: no scheme, host, credentials or fragment.
        $out = $this->console($parts['path'], max: $max);

        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = [];

            foreach (explode('&', $parts['query']) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
                $name = urldecode((string) $key);
                $pairs[] = $value === null ? $key : $key.'='.(preg_match(self::SENSITIVE_KEY, $name) === 1 ? self::MASK : $this->value($value));
            }

            $out .= '?'.implode('&', $pairs);
        }

        return mb_substr($out, 0, $max);
    }

    public function console(string $text, int $max = 500): string
    {
        // A Cookie or Set-Cookie header is masked to the end of its line: its later pairs have ordinary names.
        $text = preg_replace('/\b((?:set-)?cookie\s*:\s*)[^\r\n]*/i', '$1'.self::MASK, $text) ?? '';
        $text = preg_replace(self::CONSOLE_PATTERNS[0], '$1 '.self::MASK, $text) ?? '';
        $text = preg_replace(self::CONSOLE_PATTERNS[1], self::MASK, $text) ?? '';
        $text = preg_replace_callback(self::CONSOLE_PATTERNS[2], function (array $match): string {
            $quote = in_array($match[3][0] ?? '', ['"', "'"], true) ? $match[3][0] : '';

            return $match[1].$match[2].$quote.self::MASK.$quote;
        }, $text) ?? '';
        $text = preg_replace(self::CONSOLE_PATTERNS[3], self::MASK, $text) ?? '';

        // Credentials in a URL's authority (scheme://user:pass@host or //user:pass@host) never stay.
        $text = preg_replace('~((?:\b[a-z][a-z0-9+.-]*:)?//)[^\s/?#@"\'<>]+@~i', '$1'.self::MASK.'@', $text) ?? '';

        // Every URL-like token in the line (any run holding a / or ?, so absolute, root-, path-, dot- and query-relative
        // references alike, or a #fragment carrying name=value) gets the query rules and loses its fragment. Plain
        // text such as "issue #12" is left alone.
        $text = preg_replace_callback('~[^\s"\'<>]*[/?#][^\s"\'<>]*~', function (array $match): string {
            if (strpbrk($match[0], '/?') === false && ! str_contains((string) strstr($match[0], '#'), '=')) {
                return $match[0];
            }

            $url = (string) preg_replace('/#.*$/s', '', $match[0]);
            $at = strpos($url, '?');

            return $at === false ? $url : substr($url, 0, $at).'?'.$this->query(substr($url, $at + 1));
        }, $text) ?? '';

        return mb_substr($text, 0, $max);
    }

    /** A query string with every sensitive key's value masked; percent-encoded keys are decoded before matching. */
    private function query(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $pairs[] = $value === null ? $key : $key.'='.(preg_match(self::SENSITIVE_KEY, rawurldecode(str_replace('+', ' ', (string) $key))) === 1 ? self::MASK : $this->value($value));
        }

        return implode('&', $pairs);
    }

    /**
     * An ordinary key's value, kept as sent unless its decoded text holds something the console rules mask (an opaque
     * token, an encoded secret=value), in which case the whole value is masked so the query's boundaries stay intact.
     */
    private function value(string $raw): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $raw));

        return $this->console($decoded, max: strlen($decoded) + 1) === $decoded ? $raw : self::MASK;
    }
}
