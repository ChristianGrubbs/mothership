<?php

namespace Mothership\Core;

use Illuminate\Http\Request;

/**
 * The public HTTPS origins The Mothership answers on besides APP_URL (APP_PUBLIC_ORIGINS).
 *
 * A request is matched by its host[:port] only, so the result does not depend on whether a
 * proxy's X-Forwarded-Proto has been applied yet. The returned origin is always a configured
 * string, never text copied from the Host header.
 */
final class PublicOrigins
{
    /**
     * The canonical origin first, then each extra entry that is a bare HTTPS origin
     * (no path, query, fragment or credentials). Invalid entries are dropped.
     *
     * @param  iterable<mixed>|string|null  $extra  a comma-separated string or a list
     * @return list<string>
     */
    public static function list(string $canonical, iterable|string|null $extra): array
    {
        $origins = [rtrim($canonical, '/')];
        $entries = is_string($extra) || $extra === null ? explode(',', (string) $extra) : $extra;

        foreach ($entries as $entry) {
            $origin = rtrim(trim((string) $entry), '/');
            if (self::isBareHttpsOrigin(origin: $origin)) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique(array_filter($origins, fn (string $origin): bool => $origin !== '')));
    }

    /** The listed origin whose host[:port] equals the request's, or null. */
    public static function match(Request $request, array $origins): ?string
    {
        $host = strtolower($request->getHttpHost());

        foreach ($origins as $origin) {
            if (self::authority(origin: (string) $origin) === $host) {
                return $origin;
            }
        }

        return null;
    }

    public static function isBareHttpsOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return filter_var($origin, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! isset($parts['path']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    /** host[:port] as Symfony's getHttpHost() reports it: the port only when it is not the scheme default. */
    private static function authority(string $origin): string
    {
        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port = (int) ($parts['port'] ?? 0);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $default = $scheme === 'https' ? 443 : 80;

        return $port > 0 && $port !== $default ? $host.':'.$port : $host;
    }
}
