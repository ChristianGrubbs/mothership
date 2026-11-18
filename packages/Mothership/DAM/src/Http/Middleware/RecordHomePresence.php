<?php

namespace Mothership\DAM\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Mothership\DAM\Services\HomePresence;
use Mothership\DAM\Services\HomeTelemetry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecordHomePresence
{
    public function __construct(private readonly HomePresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $admin = auth('admin')->user();

        if ($admin && ! $admin->isApiUser() && $admin->status && $response->getStatusCode() < 500) {
            try {
                $this->presence->record(admin: $admin, now: CarbonImmutable::now());
            } catch (Throwable $error) {
                HomeTelemetry::record(event: 'presence.record_failed', ctx: [
                    'admin_id'  => $admin->id,
                    'exception' => $error::class,
                ]);
            }
        }

        return $response;
    }
}
