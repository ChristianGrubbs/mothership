<?php

namespace Mothership\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Mothership\Core\PublicOrigins;
use Symfony\Component\HttpFoundation\Response;

/**
 * CoreServiceProvider pins url()/route()/asset() to APP_URL. For a request that arrives on an
 * allowlisted public origin (APP_PUBLIC_ORIGINS), this re-pins them, config('app.url') and the
 * public disk URL to that origin for the length of the request, so redirects (login), form actions,
 * signed URLs, asset links and the admin `base-url` meta stay on the host the browser is using.
 * Any other Host keeps APP_URL. Console, queue and scheduler work never passes through here and
 * stays on APP_URL. The previous values are restored afterwards, so a long-lived worker or a test
 * process never carries one request's origin into the next.
 */
class UsePublicOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = rtrim((string) config('app.url'), '/');
        $origin = $canonical === '' ? null
            : PublicOrigins::match(request: $request, origins: PublicOrigins::list(canonical: $canonical, extra: config('app.public_origins')));

        if ($origin === null || $origin === $canonical) {
            return $next($request);
        }

        $disk = config('filesystems.disks.public.url');
        $scheme = parse_url($canonical, PHP_URL_SCHEME) ?: 'https';
        URL::forceRootUrl($origin);
        URL::forceScheme('https');
        config([
            'app.url'                       => $origin,
            'filesystems.disks.public.url'  => is_string($disk) && str_starts_with($disk, $canonical) ? $origin.substr($disk, strlen($canonical)) : $disk,
        ]);

        try {
            return $next($request);
        } finally {
            URL::forceRootUrl($canonical);
            URL::forceScheme($scheme);
            config(['app.url' => $canonical, 'filesystems.disks.public.url' => $disk]);
        }
    }
}
