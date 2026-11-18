<?php

declare(strict_types=1);

namespace Mothership\HistoryControl\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Mothership\User\Models\Admin;
use OwenIt\Auditing\Contracts\UserResolver;
use OwenIt\Auditing\Resolvers\UserResolver as GuardResolver;

/**
 * Who an audit row names. A request resolves through the configured guards as usual, and a REST call through
 * the api guard its middleware already signed in. A queued job has no signed-in user, so the admin whose
 * request dispatched it travels with the job in hidden Context and the job's audits name that person instead
 * of nobody (visual-review finding F4: blank User on History). Work nobody started (the scheduler, a console
 * command) still records no user.
 */
class ActorResolver implements UserResolver
{
    public const CONTEXT_KEY = 'audit_actor_id';

    public static function resolve()
    {
        $user = GuardResolver::resolve() ?? self::signedInThroughPassiveGuard();

        if ($user !== null) {
            return $user;
        }

        $id = Context::getHidden(self::CONTEXT_KEY);

        return $id === null ? null : Admin::query()->find($id);
    }

    /** Registered once at boot: every job dispatched while someone is signed in carries their id. */
    public static function rememberDispatcher(Repository $context): void
    {
        if ($context->hasHidden(self::CONTEXT_KEY)) {
            return;
        }

        $id = null;

        foreach ((array) Config::get('audit.user.guards', []) as $guard) {
            try {
                $id ??= Auth::guard($guard)->id();
            } catch (\Throwable) {
                continue;
            }
        }

        $id ??= self::signedInThroughPassiveGuard()?->getAuthIdentifier();

        if ($id !== null) {
            $context->addHidden(self::CONTEXT_KEY, (int) $id);
        }
    }

    /**
     * The user a passive guard (the REST api guard) already holds for this request, without asking it to
     * authenticate: resolving it here would pin that user on the guard before its own middleware runs.
     */
    private static function signedInThroughPassiveGuard(): ?Authenticatable
    {
        foreach ((array) Config::get('audit.user.passive_guards', []) as $name) {
            try {
                $guard = Auth::guard($name);
            } catch (\Throwable) {
                continue;
            }

            if ($guard->hasUser()) {
                return $guard->user();
            }
        }

        return null;
    }
}
