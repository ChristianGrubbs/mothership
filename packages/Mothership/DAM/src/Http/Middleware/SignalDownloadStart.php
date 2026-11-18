<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the page that a download really began. Every download is a plain browser navigation, so nothing on screen
 * moves until the server sends headers; the "Preparing download…" card (components/download/preparing.blade.php)
 * adds a random `?dl=<token>` to the URL and waits for the cookie this sets. The cookie rides on the response
 * headers, so it reaches the browser exactly when the download starts: for a streamed zip, at the first flush.
 *
 * Nothing is set for any other token, for a preflight (it asks and sends no bytes) or for an error response. A redirect
 * counts only when it leaves this origin, host or port (the presigned storage URL of a share download); a redirect that stays on this
 * origin is the app sending the person to another page, not a download.
 * The cookie holds no data, so it is readable by the page's script and sent in the clear (SignalDownloadStartCookies).
 */
class SignalDownloadStart
{
    /** The token the card makes: letters and digits only, so it is safe as a cookie name. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{12,40}$/';

    public const COOKIE_PREFIX = 'dam_dl_';

    public const MAX_AGE_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->query('dl');

        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return $response;
        }

        if ($request->headers->has('X-Zip-Preflight') || ! $this->signalsDownload(request: $request, response: $response)) {
            return $response;
        }

        $response->headers->setCookie(new Cookie(
            name: self::COOKIE_PREFIX.$token,
            value: '1',
            expire: time() + self::MAX_AGE_SECONDS,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    private function signalsDownload(Request $request, Response $response): bool
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return true;
        }

        if ($status < 300 || $status >= 400) {
            return false;
        }

        $location = parse_url((string) $response->headers->get('Location'));
        $host = $location['host'] ?? '';

        if ($host === '') {
            return false;
        }

        $scheme = strtolower((string) ($location['scheme'] ?? $request->getScheme()));
        $port = (int) ($location['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Origin host plus port: a presigned URL on this host's storage port (MinIO :9000) leaves the app too.
        return strcasecmp($host, $request->getHost()) !== 0 || $port !== (int) $request->getPort();
    }
}
