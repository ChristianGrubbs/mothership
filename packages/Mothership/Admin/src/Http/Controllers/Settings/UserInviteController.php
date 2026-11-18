<?php

namespace Mothership\Admin\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\Admin\Services\UserInvites;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * Admin side of "Invite people": create pending accounts and send (or resend) their one-time invite code. Extends
 * UserController to share its role-grant and outrank rules (PERM-08) rather than copy them.
 */
class UserInviteController extends UserController
{
    /**
     * Invite one or more addresses with one role.
     */
    public function invite(): JsonResponse
    {
        $this->validate(request(), [
            'emails'  => ['required', 'string', 'max:20000'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $invites = app(UserInvites::class);
        $parsed = $invites->parse((string) request()->input('emails'));

        if (count($parsed['valid']) + count($parsed['invalid']) > UserInvites::MAX_PER_SUBMISSION) {
            throw ValidationException::withMessages([
                'emails' => trans('admin::app.invites.too-many', ['max' => UserInvites::MAX_PER_SUBMISSION]),
            ]);
        }

        if ($parsed['valid'] === [] && $parsed['invalid'] === []) {
            throw ValidationException::withMessages(['emails' => trans('admin::app.invites.none')]);
        }

        $roleId = (int) request()->input('role_id');

        // The create permission and the role-grant rule are decided under the full-admin lock, in the same
        // transaction that creates the accounts, exactly as store() does for a single user.
        $result = $this->adminRepository->guardingActiveFullAdmin(field: 'role_id', write: function () use ($invites, $parsed, $roleId) {
            $actor = $this->lockedActorWith('settings.users.users.create');

            if ($error = $this->roleAssignmentError(Role::query()->lockForUpdate()->find($roleId), $actor)) {
                return new JsonResponse(['message' => trans($error)], JsonResponse::HTTP_FORBIDDEN);
            }

            $existing = DB::table('admins')
                ->whereIn(DB::raw('lower(email)'), $parsed['valid'])
                ->pluck('email')
                ->map(fn ($email): string => Str::lower((string) $email))
                ->all();

            $created = [];
            $skipped = [];

            foreach ($parsed['valid'] as $email) {
                if (in_array($email, $existing, true)) {
                    $skipped[] = $email;

                    continue;
                }

                Event::dispatch('user.admin.create.before');

                $admin = $this->adminRepository->create($this->pendingAccount(email: $email, roleId: $roleId, actor: $actor));

                $created[] = [$admin, $invites->issue($admin)];
            }

            return ['actor' => $actor, 'created' => $created, 'skipped' => $skipped];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $invited = [];

        foreach ($result['created'] as [$admin, $token]) {
            Event::dispatch('user.admin.create.after', $admin);

            $invites->send($admin, $token);
            $invites->record(UserInvites::eventName('sent'), $admin, (int) $result['actor']->id);

            $invited[] = $admin->email;
        }

        return new JsonResponse([
            'message' => trans('admin::app.invites.result', [
                'invited' => count($invited),
                'skipped' => count($result['skipped']),
                'invalid' => count($parsed['invalid']),
            ]),
            'invited' => $invited,
            'skipped' => $result['skipped'],
            'invalid' => $parsed['invalid'],
        ]);
    }

    /**
     * Send a pending invitee a new code; the previous one stops working.
     */
    public function resend(int $id): JsonResponse
    {
        $invites = app(UserInvites::class);

        $result = $this->adminRepository->guardingActiveFullAdmin(field: 'id', write: function () use ($invites, $id) {
            $actor = $this->lockedActorWith('settings.users.users.create');

            $target = $this->adminRepository->lockedAdmin($id);

            abort_if(! $target || ! $invites->isPending($target), 404);

            if ($this->outranksActor($target, $actor)) {
                return $this->outrankedResponse();
            }

            return ['actor' => $actor, 'admin' => $target, 'token' => $invites->issue($target)];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $invites->send($result['admin'], $result['token']);
        $invites->record(UserInvites::eventName('resent'), $result['admin'], (int) $result['actor']->id);

        return new JsonResponse(['message' => trans('admin::app.invites.resent')]);
    }

    /**
     * An inactive account with a random hash nobody knows, named after the address until the invitee picks a name.
     *
     * @return array<string, mixed>
     */
    private function pendingAccount(string $email, int $roleId, Admin $actor): array
    {
        $localPart = Str::before($email, '@');

        return [
            'name'               => Str::limit(Str::headline($localPart) ?: $localPart, 100, ''),
            'email'              => $email,
            'password'           => Hash::make(Str::random(64)),
            'role_id'            => $roleId,
            'status'             => 0,
            'use_gravatar'       => false,
            'timezone'           => $actor->timezone ?: config('app.timezone', 'UTC'),
            'ui_locale_id'       => $actor->ui_locale_id ?: $this->defaultLocaleId(),
            'catalog_locale_id'  => $actor->catalog_locale_id ?: $this->defaultLocaleId(),
            'default_channel_id' => $actor->default_channel_id ?: core()->getDefaultChannel()?->id,
        ];
    }

    private function defaultLocaleId(): ?int
    {
        $id = DB::table('locales')->where('code', core()->getDefaultLocaleCodeFromDefaultChannel())->where('status', 1)->value('id')
            ?? DB::table('locales')->where('status', 1)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
