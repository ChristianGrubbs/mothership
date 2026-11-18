<?php

namespace Mothership\Admin\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\View\View;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Services\UserInvites;
use Mothership\Core\Rules\AlphaNumericSpace;
use Mothership\Core\Rules\PasswordWithoutSurroundingWhitespace;
use Mothership\User\Models\Admin;

/**
 * Public page where an invitee types the one-time code from their invitation email and sets their name and password.
 * Every failure (used, expired, unknown or mismatched code, one with its 5 tries spent, an account that is no
 * longer a pending invite) answers with the same message, so the page cannot be used
 * to learn which addresses have accounts. The code is verified against the `admin_invites` row without a lock;
 * the account is then locked, re-checked, and the row consumed by compare-and-delete (hash and expiry) before it
 * is activated. The `token` request field carries the code.
 */
class InviteAcceptController extends Controller
{
    /**
     * Minimum time for a failed attempt (500 ms). It has to outlast the slowest failure: the account lookup plus
     * one bcrypt code check (about 250 ms at the production cost of 12 rounds), which a pending invite with a
     * wrong code reaches. No failure waits on a lock, because the code is checked before one is taken.
     */
    private const TIMEBOX_MICROSECONDS = 500_000;

    public function create(): View
    {
        return view('admin::users.invite.create');
    }

    /**
     * An old `/admin/invite/{token}` address only leads to the form: the code is typed, never carried in a URL.
     */
    public function legacyLink(): RedirectResponse
    {
        return redirect()->route('admin.invite.accept.create');
    }

    public function store(): RedirectResponse|JsonResponse
    {
        $this->validate(request(), [
            'token'    => 'required',
            'email'    => 'required|email',
            'name'     => ['required', 'string', 'max:100', new AlphaNumericSpace],
            'password' => ['required', 'confirmed', 'min:'.config('admin.auth.password_min'), new PasswordWithoutSurroundingWhitespace],
        ]);

        $invites = app(UserInvites::class);
        $accepted = null;

        // One timebox around the whole attempt (lookup, code check, locked re-check), so an unknown, active,
        // non-invited and pending address all take the same minimum time before they answer alike. A success
        // returns early.
        $response = (new Timebox)->call(function (Timebox $timebox) use ($invites, &$accepted): string {
            try {
                $result = $this->attempt($invites, $accepted);
            } catch (\Throwable $e) {
                report($e);

                return Password::INVALID_TOKEN;
            }

            if ($result === Password::PASSWORD_RESET) {
                $timebox->returnEarly();
            }

            return $result;
        }, self::TIMEBOX_MICROSECONDS);

        if ($response !== Password::PASSWORD_RESET || ! $accepted instanceof Admin) {
            return $this->invalid();
        }

        $invites->record(UserInvites::eventName('accepted'), $accepted, (int) $accepted->id);

        // As the reset flow: sign the person in and go home. A pending invitee is human and now active.
        auth()->guard('admin')->login($accepted);

        return request()->wantsJson()
            ? response()->json(['redirect_url' => route('admin.home.index')])
            : redirect()->route('admin.home.index');
    }

    private function attempt(UserInvites $invites, ?Admin &$accepted): string
    {
        $email = (string) request('email');
        $found = Admin::query()->where('email', $email)->first(['id', 'email']);

        if (! $found) {
            return Password::INVALID_TOKEN;
        }

        // The code check (a hash check, the slow part) happens before any lock, so a flood of wrong codes for
        // one address never queues on the account row and answers as fast as for an unknown address.
        $invite = $invites->verify((string) $found->email, (string) request('token'));

        if (! $invite) {
            return Password::INVALID_TOKEN;
        }

        return DB::transaction(function () use ($invites, $email, $found, $invite, &$accepted): string {
            // The account is locked by id as the first statement of the transaction, and everything that could
            // have changed while this request waited is judged again on the locked row: its email, that it is
            // still an inactive human account, and that the invite row still holds the verified hash and has not
            // expired. Deleting that row (compare-and-delete) is what makes the code single-use and refuses one
            // that a resend replaced or that expired during the wait.
            $locked = Admin::query()->whereKey($found->id)->lockForUpdate()->first();

            if (
                ! $locked
                || $locked->email !== $found->email
                || Str::lower((string) $locked->email) !== Str::lower($email)
                || $locked->status
                || $locked->isApiUser()
                || ! $invites->consume((string) $locked->email, (string) $invite->token)
            ) {
                return Password::INVALID_TOKEN;
            }

            $locked->name = (string) request('name');
            $locked->password = Hash::make((string) request('password'));
            $locked->status = 1;
            $locked->setRememberToken(Str::random(60));
            $locked->save();

            $accepted = $locked;

            return Password::PASSWORD_RESET;
        });
    }

    private function invalid(): RedirectResponse|JsonResponse
    {
        $error = trans('admin::app.invites.invalid-code');

        if (request()->wantsJson()) {
            return response()->json(['errors' => ['email' => [$error]]], 422);
        }

        return back()->withInput(request(['email']))->withErrors(['email' => $error]);
    }
}
