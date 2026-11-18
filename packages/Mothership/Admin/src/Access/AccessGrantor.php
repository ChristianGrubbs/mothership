<?php

namespace Mothership\Admin\Access;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\Admin\Exceptions\AccessDecisionRefused;
use Mothership\Admin\Sso\SsoIdentity;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminAccessPreapproval;
use Mothership\User\Models\AdminAccessRequest;
use Mothership\User\Models\AdminIdentity;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;

/**
 * The only path that turns a provider account into an admin, or attaches one to an admin. It is reached from
 * the shell (mothership:access:*), from Settings > Users by an account whose role has All permissions
 * (UserAccessController), and from a provider sign-in that redeems a preapproval. It is never reached from an
 * MCP request.
 */
class AccessGrantor
{
    public function __construct(
        protected readonly AccessAuditLog $auditLog,
        protected readonly AdminRepository $adminRepository,
    ) {}

    /**
     * Create an admin from an open, verified access request, with the role named at the shell.
     */
    public function grant(int $requestId, ?string $roleName, bool $fullAdmin): Admin
    {
        return $this->approve(
            requestId: $requestId,
            role: $this->resolveRole(roleName: $roleName, fullAdmin: $fullAdmin),
        );
    }

    /**
     * Create an admin with this role from an open, verified access request and link the provider account to it.
     */
    public function approve(int $requestId, Role $role, ?int $actorId = null): Admin
    {
        $admin = DB::transaction(function () use ($requestId, $role): Admin {
            $accessRequest = $this->openVerifiedRequest($requestId);

            if ($this->emailHasAdmin($accessRequest->email)) {
                throw new AccessDecisionRefused("An admin with this request's email already exists. Run mothership:access:link <admin> {$accessRequest->id} instead.");
            }

            $admin = $this->createAdmin(name: $accessRequest->name, email: $accessRequest->email, role: $role);

            $this->linkRequest(admin: $admin, accessRequest: $accessRequest);

            return $admin;
        });

        $this->auditLog->write(event: 'access.granted', ctx: [
            'request_id' => $requestId,
            'admin_id'   => $admin->id,
            'role_id'    => $role->id,
            'full_admin' => $role->permission_type === 'all',
            'actor_id'   => $actorId,
        ]);

        return $admin;
    }

    /**
     * Attach the provider account of an open, verified access request to an existing admin.
     */
    public function link(string $adminRef, int $requestId, ?int $actorId = null): Admin
    {
        $admin = $this->findAdmin($adminRef);

        DB::transaction(fn () => $this->linkRequest(admin: $admin, accessRequest: $this->openVerifiedRequest($requestId)));

        $this->auditLog->write(event: 'access.linked', ctx: [
            'request_id' => $requestId,
            'admin_id'   => $admin->id,
            'actor_id'   => $actorId,
        ]);

        return $admin;
    }

    /**
     * Drop an open access request. Nothing is remembered against the provider account: if it signs in again it
     * waits as a new request.
     */
    public function deny(int $requestId, ?int $actorId = null): void
    {
        $accessRequest = DB::transaction(function () use ($requestId): AdminAccessRequest {
            $accessRequest = $this->openRequest($requestId);

            $accessRequest->delete();

            return $accessRequest;
        });

        $this->auditLog->write(event: 'access.denied', ctx: [
            'request_id' => $requestId,
            'provider'   => $accessRequest->provider,
            'email_hash' => $this->auditLog->hashEmail($accessRequest->email),
            'actor_id'   => $actorId,
        ]);
    }

    /**
     * Let an email address in ahead of its first provider sign-in. An address that already has an account is
     * refused: a preapproval never attaches a provider account to an existing admin.
     */
    public function preapprove(string $email, Role $role, ?int $actorId = null): AdminAccessPreapproval
    {
        $email = AdminAccessPreapproval::normalizeEmail($email);

        try {
            $preapproval = DB::transaction(function () use ($email, $role, $actorId): AdminAccessPreapproval {
                if ($this->emailHasAdmin($email)) {
                    throw new AccessDecisionRefused(trans('admin::app.user-access.preapprovals.account-exists'));
                }

                if (AdminAccessPreapproval::query()->where('email', $email)->lockForUpdate()->exists()) {
                    throw new AccessDecisionRefused(trans('admin::app.user-access.preapprovals.duplicate'));
                }

                return AdminAccessPreapproval::query()->create([
                    'email'      => $email,
                    'role_id'    => $role->id,
                    'created_by' => $actorId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new AccessDecisionRefused(trans('admin::app.user-access.preapprovals.duplicate'));
        }

        $this->auditLog->write(event: 'access.preapproved', ctx: [
            'preapproval_id' => $preapproval->id,
            'email_hash'     => $this->auditLog->hashEmail($email),
            'role_id'        => $role->id,
            'full_admin'     => $role->permission_type === 'all',
            'actor_id'       => $actorId,
        ]);

        return $preapproval;
    }

    public function removePreapproval(int $preapprovalId, ?int $actorId = null): void
    {
        $preapproval = DB::transaction(function () use ($preapprovalId): AdminAccessPreapproval {
            $preapproval = AdminAccessPreapproval::query()->lockForUpdate()->find($preapprovalId);

            if (! $preapproval) {
                throw new AccessDecisionRefused(trans('admin::app.user-access.preapprovals.gone'));
            }

            $preapproval->delete();

            return $preapproval;
        });

        $this->auditLog->write(event: 'access.preapproval_removed', ctx: [
            'preapproval_id' => $preapprovalId,
            'email_hash'     => $this->auditLog->hashEmail($preapproval->email),
            'actor_id'       => $actorId,
        ]);
    }

    /**
     * Spend the preapproval for this sign-in's email, if there is one: create the admin with its role, link the
     * provider account and delete the entry. Returns null when the sign-in should wait for access instead: no
     * entry, an email the provider did not verify, or an address that has gained an account since.
     *
     * Anonymous sign-ins take no lock unless their address is on the list.
     */
    public function redeemPreapproval(SsoIdentity $identity, string $provider): ?AdminIdentity
    {
        $email = AdminAccessPreapproval::normalizeEmail($identity->email);

        if (! AdminAccessPreapproval::query()->where('email', $email)->exists()) {
            return null;
        }

        if (! $identity->emailVerified) {
            $this->auditRefusedRedemption(email: $email, provider: $provider, reason: 'email_unverified');

            return null;
        }

        $refusal = null;

        try {
            $link = DB::transaction(function () use ($identity, $provider, $email, &$refusal): ?AdminIdentity {
                // Roles and admins first, the order every holder of the full-admin lock takes them in.
                $this->adminRepository->lockActiveFullAdmins();

                $preapproval = AdminAccessPreapproval::query()->where('email', $email)->lockForUpdate()->first();

                if (! $preapproval) {
                    return null;
                }

                if ($this->emailHasAdmin($email)) {
                    $refusal = 'account_exists';

                    return null;
                }

                $role = Role::query()->lockForUpdate()->findOrFail($preapproval->role_id);

                $admin = $this->createAdmin(name: $identity->name, email: $identity->email, role: $role);

                $link = AdminIdentity::query()->create([
                    'admin_id'      => $admin->id,
                    'provider'      => $provider,
                    'subject'       => $identity->identifier,
                    'email_at_link' => $identity->email,
                    'linked_at'     => now(),
                ]);

                AdminAccessRequest::query()
                    ->where('provider', $provider)
                    ->where('subject', $identity->identifier)
                    ->update(['status' => AdminAccessRequest::STATUS_GRANTED, 'admin_id' => $admin->id]);

                $preapproval->delete();

                $this->auditLog->write(event: 'access.preapproval_redeemed', ctx: [
                    'preapproval_id' => $preapproval->id,
                    'admin_id'       => $admin->id,
                    'role_id'        => $role->id,
                    'provider'       => $provider,
                    'email_hash'     => $this->auditLog->hashEmail($email),
                ]);

                return $link->setRelation('admin', $admin);
            });
        } catch (UniqueConstraintViolationException) {
            $link = null;
            $refusal = 'account_exists';
        }

        if ($refusal !== null) {
            $this->auditRefusedRedemption(email: $email, provider: $provider, reason: $refusal);
        }

        return $link;
    }

    /**
     * Remove an admin's provider links (all, or one provider's) and, on request, deactivate it.
     *
     * @return int The number of links removed.
     */
    public function revoke(string $adminRef, ?string $provider, bool $deactivate, bool $force): int
    {
        $admin = $this->findAdmin($adminRef);

        if ($deactivate && ! $force && $this->isLastActiveFullAdmin($admin)) {
            throw new AccessDecisionRefused('This is the last active full admin. Pass --force to deactivate it anyway.');
        }

        $removed = DB::transaction(function () use ($admin, $provider, $deactivate): int {
            $removed = AdminIdentity::query()
                ->where('admin_id', $admin->id)
                ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
                ->delete();

            if ($deactivate) {
                $admin->forceFill(['status' => 0])->save();
            }

            return $removed;
        });

        $this->auditLog->write(event: 'access.revoked', ctx: [
            'admin_id'    => $admin->id,
            'provider'    => $provider,
            'removed'     => $removed,
            'deactivated' => $deactivate,
        ]);

        return $removed;
    }

    public function findAdmin(string $adminRef): Admin
    {
        $admin = ctype_digit($adminRef)
            ? Admin::query()->find((int) $adminRef)
            : Admin::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($adminRef))])->first();

        if (! $admin) {
            throw new AccessDecisionRefused("No admin matches {$adminRef}.");
        }

        if ($admin->isApiUser()) {
            throw new AccessDecisionRefused('API users cannot sign in with a provider account.');
        }

        return $admin;
    }

    public function emailHasAdmin(string $email): bool
    {
        return Admin::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->exists();
    }

    /**
     * Every new admin gets a real role: several screens treat a missing role as full access.
     */
    protected function createAdmin(?string $name, string $email, Role $role): Admin
    {
        $admin = new Admin;

        $admin->forceFill([
            'name'         => Str::limit($name ?: Str::before($email, '@'), 250, ''),
            'email'        => $email,
            'password'     => null,
            'status'       => 1,
            'role_id'      => $role->id,
            'type'         => 'user',
            'timezone'     => config('app.timezone') ?: 'UTC',
            'ui_locale_id' => $this->defaultUiLocaleId(),
        ])->save();

        return $admin;
    }

    protected function linkRequest(Admin $admin, AdminAccessRequest $accessRequest): void
    {
        $taken = AdminIdentity::query()
            ->where('provider', $accessRequest->provider)
            ->where('subject', $accessRequest->subject)
            ->exists();

        if ($taken) {
            throw new AccessDecisionRefused('This provider account is already linked to an admin.');
        }

        AdminIdentity::query()->create([
            'admin_id'      => $admin->id,
            'provider'      => $accessRequest->provider,
            'subject'       => $accessRequest->subject,
            'email_at_link' => $accessRequest->email,
            'linked_at'     => now(),
        ]);

        $accessRequest->forceFill([
            'status'   => AdminAccessRequest::STATUS_GRANTED,
            'admin_id' => $admin->id,
        ])->save();
    }

    protected function openRequest(int $requestId): AdminAccessRequest
    {
        $accessRequest = AdminAccessRequest::query()->lockForUpdate()->find($requestId);

        if (! $accessRequest || ! $accessRequest->isOpen()) {
            throw new AccessDecisionRefused("Access request {$requestId} is not open (missing, expired or already granted). Ask for a fresh sign-in.");
        }

        return $accessRequest;
    }

    protected function openVerifiedRequest(int $requestId): AdminAccessRequest
    {
        $accessRequest = $this->openRequest($requestId);

        if (! $accessRequest->email_verified) {
            throw new AccessDecisionRefused("Access request {$requestId} has an email the provider did not verify, so it cannot be granted.");
        }

        return $accessRequest;
    }

    protected function auditRefusedRedemption(string $email, string $provider, string $reason): void
    {
        $this->auditLog->write(event: 'access.preapproval_refused', level: 'warning', ctx: [
            'provider'   => $provider,
            'email_hash' => $this->auditLog->hashEmail($email),
            'reason'     => $reason,
        ]);
    }

    /**
     * The role named at the shell.
     */
    protected function resolveRole(?string $roleName, bool $fullAdmin): Role
    {
        if ($fullAdmin === ($roleName !== null)) {
            throw new AccessDecisionRefused('Pass exactly one of --admin or --role=<name>.');
        }

        $role = $fullAdmin
            ? Role::query()->where('permission_type', 'all')->orderBy('id')->first()
            : Role::query()->where('name', $roleName)->get()->first(fn (Role $role): bool => $role->name === $roleName);

        if (! $role) {
            throw new AccessDecisionRefused($fullAdmin ? 'No role with full access exists.' : "No role is named {$roleName}.");
        }

        return $role;
    }

    protected function isLastActiveFullAdmin(Admin $admin): bool
    {
        if (! $admin->status || $admin->role?->permission_type !== 'all') {
            return false;
        }

        return ! Admin::query()
            ->whereKeyNot($admin->id)
            ->where('status', 1)
            ->where('type', 'user')
            ->whereHas('role', fn ($query) => $query->where('permission_type', 'all'))
            ->exists();
    }

    protected function defaultUiLocaleId(): ?int
    {
        $id = DB::table('locales')->where('code', config('app.locale'))->where('status', 1)->value('id')
            ?? DB::table('locales')->where('status', 1)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
