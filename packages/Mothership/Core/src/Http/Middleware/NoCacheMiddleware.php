<?php

namespace Mothership\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class NoCacheMiddleware
{
    // Routes allowed to keep an `immutable` Cache-Control below. It serves StylePress thumbnail
    // derivatives at a URL that already carries ?v={catalogVersion}, so a version bump is a new
    // URL and a private, year-long cache is safe. Deliberately a named-route allowlist rather
    // than "any response that says immutable": a route name has to be added here on purpose,
    // it can't be opted into by accident from inside a controller.
    private const IMMUTABLE_ROUTES = [
        'admin.home.stylepress.thumbnail',
    ];

    // Web page bundle routes (ADR 0007) carry their own policy on every response through
    // WebPageHeaders: the entry is `no-store` and siblings are cacheable for five minutes so a
    // sandboxed page's css/js/images are not refetched on every frame reload. The admin entry
    // cannot opt out via `public` below (its bundle must not be shared-cached), and the share
    // entry must not either: revocation has to be seen on every request, so `no-store, private`
    // is the exact header both entries need to keep.
    private const PAGE_POLICY_ROUTES = [
        'admin.dam.pages.file',
        'dam.share.page',
    ];

    // DAM asset-byte routes: originals, previews, thumbnails and downloads. `no-store` made every
    // 360 / 3D / preview open re-download the whole file (a 294 MB GLB on a phone never finished),
    // so a successful response is `private, no-cache` instead: the browser may keep the bytes but
    // must revalidate (ETag / Last-Modified -> 304) on every use. Each request still runs its own
    // authorization first, so a revoked grant or share is a 403/404 on the next request, and those
    // error responses keep the full `no-store` below. Never `public`: nothing shared may hold bytes.
    private const REVALIDATED_BYTE_ROUTES = [
        'admin.dam.file.preview',
        'admin.dam.file.thumbnail',
        'admin.dam.file.asset-file',
        'admin.dam.file.cover-art',
        'admin.dam.assets.download',
        'dam.share.asset_view',
        'dam.share.asset_download',
        'dam.share.thumbnail',
        'dam.share.download',
        // P6 D26: an embed's media, revalidated on every use so a revoke is seen on the next range request.
        'dam.embed.media',
    ];

    // Of those, the routes whose URL carries a `v` naming the exact bytes served (FileController::thumbnail()
    // only answers `immutable` when `v` matches the content it is sending). Such a response keeps the
    // controller's `private, max-age=<1y>, immutable`; every other response on the route stays `private, no-cache`.
    // `public` is still never honoured here, and errors are decided below as before.
    private const VERSIONED_BYTE_ROUTES = [
        'admin.dam.file.thumbnail',
        // A stacked file's `?thumb=1` picture (FileController::stackFileThumbnail()) is immutable only under the `v`
        // StackFileThumbnail::version() names; every other use of the route (originals, ?preview=1, ?download=1) never is.
        'admin.dam.file.asset-file',
    ];

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Asset-byte routes are decided before the `public` opt-out: response()->file() builds a
        // BinaryFileResponse that is `public` by default, and a shared cache holding it would keep
        // serving bytes after a share is revoked. A controller cannot opt these routes into public.
        if ($request->routeIs(...self::REVALIDATED_BYTE_ROUTES)) {
            if ($response->getStatusCode() < 400) {
                if ($request->routeIs(...self::VERSIONED_BYTE_ROUTES)
                    && $response->headers->hasCacheControlDirective('immutable')
                    && $response->headers->hasCacheControlDirective('private')
                    && ! $response->headers->hasCacheControlDirective('public')) {
                    $response->headers->remove('Pragma');
                    $response->headers->remove('Expires');

                    return $response;
                }

                $response->headers->set('Cache-Control', 'private, no-cache');
                $response->headers->remove('Pragma');
                $response->headers->remove('Expires');
            } else {
                // Any error (a 416 from a BinaryFileResponse included) is never shared-cacheable.
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
            }

            return $response;
        }

        // 'public' opts a response out entirely (shared/CDN caching, e.g. a public asset route).
        if ($response->headers->hasCacheControlDirective('public')) {
            return $response;
        }

        if ($request->routeIs(...self::PAGE_POLICY_ROUTES)) {
            return $response;
        }

        if ($request->routeIs(...self::IMMUTABLE_ROUTES) && $response->headers->hasCacheControlDirective('immutable')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
