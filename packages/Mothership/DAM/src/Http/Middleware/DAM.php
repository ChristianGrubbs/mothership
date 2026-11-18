<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Mothership\DAM\Services\DamConfigurationFlags;

class DAM
{
    public function handle($request, Closure $next)
    {
        app(DamConfigurationFlags::class)->apply();

        return $next($request);
    }
}
