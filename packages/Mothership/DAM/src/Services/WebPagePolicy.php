<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Symfony\Component\HttpFoundation\Response;

/** The page sandbox (ADR 0007). Both page routes emit exactly these headers on every response. */
class WebPagePolicy
{
    public const SANDBOX = 'allow-scripts allow-popups allow-forms allow-modals';

    /**
     * Claude Design exports load their pinned React/ReactDOM/Babel runtime from unpkg and their
     * fonts from Google Fonts at run time, so those three hosts are allowed for scripts, styles
     * and fonts only (ADR 0007 amendment 2026-09-23, operator decision). Everything else stays
     * same-origin.
     */
    public const CSP = "default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob:; font-src 'self' data: https://fonts.gstatic.com; media-src 'self' blob:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox ".self::SANDBOX;

    public static function headers(bool $public): array
    {
        return [
            'Content-Security-Policy'     => self::CSP,
            'Cross-Origin-Opener-Policy'  => 'same-origin',
            'X-Content-Type-Options'      => 'nosniff',
            // The grant/share token lives in the URL path, so a page that links or fetches
            // off-origin must never hand that capability to the destination as a Referer.
            'Referrer-Policy'             => 'no-referrer',
            // Opaque-origin documents send Origin: null; fonts, module scripts and fetch() are CORS requests.
            'Access-Control-Allow-Origin' => '*',
            'Vary'                        => 'Origin',
        ];
    }

    public static function cacheControl(bool $public, bool $isEntry): string
    {
        // Symfony normalises a Cache-Control without public/private by appending `private`,
        // so it is spelled out here and the emitted header matches this string exactly.
        if ($isEntry) {
            return 'no-store, private';
        }

        return ($public ? 'public' : 'private').', max-age=300';
    }

    /**
     * A throttled page request is answered by the rate limiter itself, short-circuiting the
     * route middleware, so the limiter has to emit the page policy on the 429 the same way
     * WebPageHeaders does on every other response.
     */
    public static function throttledResponse(bool $public, array $headers = []): Response
    {
        return response('Too Many Requests', 429, $headers + self::headers($public) + ['Cache-Control' => 'no-store']);
    }
}
