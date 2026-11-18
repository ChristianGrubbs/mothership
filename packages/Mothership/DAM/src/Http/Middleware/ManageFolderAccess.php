<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ManageFolderAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(auth('admin')->user()?->role?->permission_type === 'all', 403);

        return $next($request);
    }
}
