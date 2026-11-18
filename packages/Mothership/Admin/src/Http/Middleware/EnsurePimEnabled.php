<?php

namespace Mothership\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePimEnabled
{
    /**
     * Handle an incoming request.
     *
     * A disabled PIM page is not a permission the admin lacks, so it says the feature is off instead of
     * "contact your administrator", which sent full admins to look for someone above them (FAULT-15).
     * The status stays 403: nothing about the surface changes for a client that only reads the code.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mothership.pim_enabled', false)) {
            $description = trans('admin::app.errors.feature-disabled', ['name' => config('documentation.name')]);

            if ($request->wantsJson() || $request->is('api/*', '*/api/*')) {
                return response()->json([
                    'error'       => trans('admin::app.errors.403.title'),
                    'description' => $description,
                    'message'     => $description,
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->view('admin::errors.index', [
                'errorCode'   => Response::HTTP_FORBIDDEN,
                'description' => $description,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
