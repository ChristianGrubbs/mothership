<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/**
 * The scoped ingest capability (§3.6.2, §3.6.6): a short-lived signed token rendered into each page that loads the
 * collector, bound to that page's container, an admin or a share link. Ingest accepts a batch only with a valid,
 * unexpired token whose binding matches what the server itself knows about the request (the signed-in admin, a link
 * that is still live), so neither a session alone nor anything the client asserts decides who an event is about.
 */
final class IngestToken
{
    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    /** Keeps this signature from ever matching another HMAC keyed with APP_KEY. */
    private const PURPOSE = 'dam-analytics-ingest-v1';

    public static function issue(string $scope, int $boundTo, ?int $ttlMinutes = null): string
    {
        $payload = self::encode(json_encode([
            's' => $scope,
            'b' => $boundTo,
            // Expiry rounds down to a 10-minute boundary (110 to 120 minutes of life; pages renew it), so every page
            // in that window carries the same token: a page's bytes never differ by the second it was served, and
            // hidden and missing assets keep answering with identical bodies.
            'e' => (int) (floor(now()->addMinutes($ttlMinutes ?? (int) config('dam_events.ingest.token_ttl_minutes', 120))->getTimestamp() / 600) * 600),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::sign($payload);
    }

    /** @return array{scope: string, bound: int, expires: int}|string the claims, or INVALID / EXPIRED */
    public static function verify(mixed $token): array|string
    {
        if (! is_string($token) || strlen($token) > 512 || substr_count($token, '.') !== 1) {
            return self::INVALID;
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals(self::sign($payload), $signature)) {
            return self::INVALID;
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($claims) || ! is_string($claims['s'] ?? null) || ! is_int($claims['b'] ?? null) || ! is_int($claims['e'] ?? null)) {
            return self::INVALID;
        }

        if ($claims['e'] < now()->getTimestamp()) {
            return self::EXPIRED;
        }

        return ['scope' => $claims['s'], 'bound' => $claims['b'], 'expires' => $claims['e']];
    }

    private static function sign(string $payload): string
    {
        return self::encode(hash_hmac('sha256', self::PURPOSE.'|'.$payload, (string) config('app.key'), true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
