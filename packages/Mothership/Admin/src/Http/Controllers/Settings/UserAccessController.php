<?php

namespace Mothership\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\Admin\Access\AccessGrantor;
use Mothership\Admin\Exceptions\AccessDecisionRefused;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminAccessPreapproval;
use Mothership\User\Models\AdminAccessRequest;
use Mothership\User\Models\Role;

/**
 * Settings > Users: who is waiting for access and who is preapproved. Open only to an account whose role has
 * All permissions, whatever its other permissions. Extends UserController to share its role-grant and outrank
 * rules (PERM-08); every decision itself goes through AccessGrantor.
 */
class UserAccessController extends UserController
{
    /**
     * The lists come as JSON rather than in the page, so a name chosen by whoever signed in is never compiled
     * as part of the screen's Vue template.
     */
    public function overview(): JsonResponse
    {
        $this->ensureFullAdmin();

        $admins = Admin::query()->orderBy('name')->get(['id', 'name', 'email', 'type']);

        $accounts = $admins->reject(fn (Admin $admin): bool => $admin->isApiUser())->values();

        $emailsInUse = $admins->map(fn (Admin $admin): string => mb_strtolower((string) $admin->email))->flip();

        return new JsonResponse([
            'requests' => AdminAccessRequest::query()
                ->open()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AdminAccessRequest $accessRequest): array => [
                    'id'             => $accessRequest->id,
                    'email'          => $accessRequest->email,
                    'name'           => $accessRequest->name,
                    'provider'       => Str::headline($accessRequest->provider),
                    'email_verified' => $accessRequest->email_verified,
                    'requested_at'   => $accessRequest->updated_at?->toIso8601String(),
                    'expires_at'     => $accessRequest->expires_at->toIso8601String(),
                    'account_id'     => $accounts->first(
                        fn (Admin $admin): bool => mb_strtolower((string) $admin->email) === mb_strtolower($accessRequest->email)
                    )?->id,
                    'has_account'    => $emailsInUse->has(mb_strtolower($accessRequest->email)),
                ]),
            'preapprovals' => AdminAccessPreapproval::query()
                ->with(['role:id,name', 'creator:id,name'])
                ->orderBy('email')
                ->get()
                ->map(fn (AdminAccessPreapproval $preapproval): array => $this->preapprovalRow(
                    preapproval: $preapproval,
                    hasAccount: $emailsInUse->has($preapproval->email),
                )),
            'roles'    => $this->assignableRoles()->map(fn (Role $role): array => $this->roleOption(role: $role))->values(),
            'accounts' => $accounts->map(fn (Admin $admin): array => $this->accountOption(admin: $admin)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function preapprovalRow(AdminAccessPreapproval $preapproval, bool $hasAccount): array
    {
        return [
            'id'          => $preapproval->id,
            'email'       => $preapproval->email,
            'role'        => $preapproval->role?->name,
            'created_by'  => $preapproval->creator?->name,
            'created_at'  => $preapproval->created_at?->toIso8601String(),
            'has_account' => $hasAccount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleOption(Role $role): array
    {
        return ['id' => $role->id, 'name' => $role->name];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOption(Admin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email];
    }

    /**
     * Create an account with the chosen role from a waiting request.
     */
    public function approve(int $id): JsonResponse
    {
        $this->ensureFullAdmin();

        $roleId = $this->validatedRoleId();

        return $this->decide(function (Admin $actor) use ($id, $roleId): string {
            $accessRequest = AdminAccessRequest::query()->find($id);

            if ($accessRequest && app(AccessGrantor::class)->emailHasAdmin($accessRequest->email)) {
                throw new AccessDecisionRefused(trans('admin::app.user-access.requests.account-exists'));
            }

            app(AccessGrantor::class)->approve(requestId: $id, role: $this->grantableRole(roleId: $roleId, actor: $actor), actorId: (int) $actor->id);

            return trans('admin::app.user-access.requests.approved');
        });
    }

    /**
     * Let a waiting request sign in as an existing account.
     */
    public function link(int $id): JsonResponse
    {
        $this->ensureFullAdmin();

        $validated = $this->validate(request(), ['admin_id' => ['required', 'integer', 'exists:admins,id']]);

        return $this->decide(function (Admin $actor) use ($id, $validated): string {
            $target = $this->adminRepository->lockedAdmin((int) $validated['admin_id']);

            abort_if(! $target, 404);

            if ($this->outranksActor($target, $actor)) {
                throw new AccessDecisionRefused(trans('admin::app.settings.users.cannot-manage-higher-role'));
            }

            app(AccessGrantor::class)->link(adminRef: (string) $target->id, requestId: $id, actorId: (int) $actor->id);

            return trans('admin::app.user-access.requests.linked');
        });
    }

    public function deny(int $id): JsonResponse
    {
        $this->ensureFullAdmin();

        return $this->decide(function (Admin $actor) use ($id): string {
            app(AccessGrantor::class)->deny(requestId: $id, actorId: (int) $actor->id);

            return trans('admin::app.user-access.requests.denied');
        });
    }

    public function preapprove(): JsonResponse
    {
        $this->ensureFullAdmin();

        $validated = $this->validate(request(), ['email' => ['required', 'string', 'email', 'max:255']]);

        $roleId = $this->validatedRoleId();

        return $this->decide(function (Admin $actor) use ($validated, $roleId): string {
            app(AccessGrantor::class)->preapprove(
                email: $validated['email'],
                role: $this->grantableRole(roleId: $roleId, actor: $actor),
                actorId: (int) $actor->id,
            );

            return trans('admin::app.user-access.preapprovals.added');
        }, refusedField: 'email');
    }

    public function removePreapproval(int $id): JsonResponse
    {
        $this->ensureFullAdmin();

        return $this->decide(function (Admin $actor) use ($id): string {
            app(AccessGrantor::class)->removePreapproval(preapprovalId: $id, actorId: (int) $actor->id);

            return trans('admin::app.user-access.preapprovals.removed');
        });
    }

    /**
     * Run a decision under the full-admin lock, on the actor as committed now: a request that waited out its
     * actor's demotion or deactivation must not keep the authority it started with. A refusal answers 422,
     * against $refusedField when the form has a field to show it on.
     *
     * @param  callable(Admin): string  $decision  Returns the message to show.
     */
    private function decide(callable $decision, ?string $refusedField = null): JsonResponse
    {
        try {
            $message = $this->adminRepository->guardingActiveFullAdmin(field: 'role_id', write: function () use ($decision): string {
                $actor = $this->adminRepository->lockedActiveActor((int) auth()->guard('admin')->id());

                abort_if($actor?->role?->permission_type !== 'all', 403);

                return $decision($actor);
            });
        } catch (AccessDecisionRefused $exception) {
            if ($refusedField !== null) {
                throw ValidationException::withMessages([$refusedField => $exception->getMessage()]);
            }

            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['message' => $message]);
    }

    private function ensureFullAdmin(): void
    {
        abort_if(auth()->guard('admin')->user()?->role?->permission_type !== 'all', 403);
    }

    private function validatedRoleId(): int
    {
        return (int) $this->validate(request(), ['role_id' => ['required', 'integer', 'exists:roles,id']])['role_id'];
    }

    /**
     * The role as committed now, once the role-grant rule (PERM-08) allows this actor to hand it out.
     */
    private function grantableRole(int $roleId, Admin $actor): Role
    {
        $role = Role::query()->lockForUpdate()->find($roleId);

        abort_if(! $role, 404);

        if ($error = $this->roleAssignmentError($role, $actor)) {
            abort(403, trans($error));
        }

        return $role;
    }
}
