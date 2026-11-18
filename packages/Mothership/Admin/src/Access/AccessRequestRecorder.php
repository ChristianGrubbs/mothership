<?php

namespace Mothership\Admin\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\Admin\Contracts\SsoProvider;
use Mothership\Admin\Sso\SsoIdentity;
use Mothership\User\Models\AdminAccessRequest;

/**
 * Parks a provider account that signed in without a linked admin. One row per provider account:
 * a repeat sign-in refreshes it and restarts its expiry. Expired rows are dropped and the number
 * of open rows is capped, oldest first, so anonymous sign-ins cannot grow the table without bound.
 */
class AccessRequestRecorder
{
    public function __construct(protected readonly AccessAuditLog $auditLog) {}

    public function record(SsoIdentity $identity, SsoProvider $provider, Request $request): AdminAccessRequest
    {
        $ttlHours = max(1, (int) config('admin.auth.access_requests.ttl_hours', 24));

        $maxOpen = max(1, (int) config('admin.auth.access_requests.max_pending', 20));

        $accessRequest = DB::transaction(function () use ($identity, $provider, $request, $ttlHours, $maxOpen): AdminAccessRequest {
            AdminAccessRequest::query()
                ->where('status', AdminAccessRequest::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->delete();

            $accessRequest = AdminAccessRequest::query()->updateOrCreate(
                ['provider' => $provider->getCode(), 'subject' => $identity->identifier],
                [
                    'email'          => Str::limit($identity->email, 250, ''),
                    'email_verified' => $identity->emailVerified,
                    'name'           => $identity->name === null ? null : Str::limit($identity->name, 250, ''),
                    'ip'             => $request->ip(),
                    'user_agent'     => Str::limit((string) $request->userAgent(), 500, ''),
                    'status'         => AdminAccessRequest::STATUS_PENDING,
                    'expires_at'     => now()->addHours($ttlHours),
                    'admin_id'       => null,
                ],
            );

            $newest = AdminAccessRequest::query()
                ->where('status', AdminAccessRequest::STATUS_PENDING)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($maxOpen)
                ->pluck('id');

            AdminAccessRequest::query()
                ->where('status', AdminAccessRequest::STATUS_PENDING)
                ->whereKeyNot($newest->all())
                ->delete();

            return $accessRequest;
        });

        $this->auditLog->write(event: 'access.requested', ctx: [
            'request_id'     => $accessRequest->id,
            'provider'       => $accessRequest->provider,
            'email_hash'     => $this->auditLog->hashEmail($accessRequest->email),
            'email_verified' => $accessRequest->email_verified,
        ]);

        return $accessRequest;
    }
}
