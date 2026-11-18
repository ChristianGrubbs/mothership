<?php

namespace Mothership\User\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class Bouncer
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, \Closure $next, $guard = 'admin')
    {
        if (! auth()->guard($guard)->check()) {
            return $this->signIn($request);
        }

        /**
         * If user status is changed by admin. Then session should be
         * logged out.
         */
        if (! (bool) auth()->guard($guard)->user()->status) {
            auth()->guard($guard)->logout();

            return $this->signIn($request);
        }

        /**
         * If somehow the user deleted all permissions, then it should be
         * auto logged out and need to contact the administrator again.
         */
        if ($this->isPermissionsEmpty()) {
            auth()->guard('admin')->logout();

            session()->flash('error', __('admin::app.errors.403.message'));

            // Same answer as any signed-out request: a background request (the session probe included) gets a
            // 401, not a 302 its XHR would follow to a 200 sign-in page that reads as success.
            return $this->signIn($request);
        }

        $userLocaleCode = auth($guard)?->user()?->uiLocale()?->first()?->code;

        if ($userLocaleCode && app()->getLocale() !== $userLocaleCode) {
            app()->setLocale($userLocaleCode);
        } else {
            app()->setLocale(config('app.locale'));
        }

        return $next($request);
    }

    /**
     * Send a signed-out request to sign in.
     *
     * A background request (the Library grid, a save, an upload chunk) gets a 401 it can act on: redirected,
     * the browser followed the 302 and handed the script the sign-in page as if it were data, so the grid went
     * blank and a save looked like it did nothing. A page load remembers where it was going, so signing in
     * returns the user there.
     */
    protected function signIn(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => trans('admin::app.requests.session')], Response::HTTP_UNAUTHORIZED);
        }

        if ($request->isMethod('GET')) {
            return redirect()->guest(route('admin.session.create'));
        }

        return to_route('admin.session.create');
    }

    /**
     * Check for user, if they have empty permissions or not except admin.
     */
    public function isPermissionsEmpty(): bool
    {
        abort_unless($role = auth()->guard('admin')->user()->role, 403, 'This action is unauthorized.');

        if ($role->permission_type === 'all') {
            return false;
        }

        if (
            $role->permission_type !== 'all'
            && empty($role->permissions)
        ) {
            return true;
        }

        $this->checkIfAuthorized();

        return false;
    }

    /**
     * Check authorization.
     */
    public function checkIfAuthorized(): void
    {
        $acl = resolve('acl');

        if (! $acl) {
            return;
        }

        $routeName = Route::currentRouteName();

        if (! isset($acl->roles[$routeName])) {
            return;
        }

        foreach ($acl->alternateRoles[$routeName] ?? [] as $permission) {
            if (bouncer()->hasPermission($permission)) {
                return;
            }
        }

        bouncer()->allow($acl->roles[$routeName]);
    }
}
