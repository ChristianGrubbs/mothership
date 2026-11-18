<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mothership\DAM\Services\Share\ShareGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * P2 S5b: the password and download-off rules on every share-scoped route, ahead of the controller and before any
 * target is looked up, so neither answer says whether a file exists.
 *
 * - A link that does not open (unknown, expired, revoked, or closed by creator access) passes straight through: the
 *   controller gives it the answer it always has.
 * - A locked link (a password, and no unlock cookie or page grant) shows its prompt on a landing page and answers
 *   every other route with a bare 401, never bytes.
 * - A preview-only link answers 403 on the routes that hand out an original.
 */
class EnforceShareGate
{
    public function __construct(
        protected ShareGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $share = is_string($token) ? $this->gate->activeShare($token) : null;

        if ($share === null) {
            return $next($request);
        }

        if (! $this->gate->unlocked($request, $share)) {
            if ($request->routeIs(...ShareGate::PROMPT_ROUTES)) {
                $assetId = $request->route('assetId');

                return response()->view('dam::share.public.password', [
                    'share'   => $share,
                    'assetId' => is_numeric($assetId) ? (int) $assetId : null,
                ], 401)->header('Cache-Control', 'no-store, private');
            }

            return response('', 401)->header('Cache-Control', 'no-store, private');
        }

        if (! $share->allowsDownload() && $request->routeIs(...ShareGate::DOWNLOAD_ROUTES)) {
            abort(403);
        }

        return $next($request);
    }
}
