<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Only a full-access account (role permission type `all`) gets through; anyone else gets 403. */
class FullAccessOnly
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(auth('admin')->user()?->role?->permission_type === 'all', 403);

        return $next($request);
    }
}
