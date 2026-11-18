<?php

namespace Mothership\Admin\Services;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Mothership\Admin\Auth\CodeTokenRepository;
use Mothership\Admin\Mail\Admin\InviteNotification;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\User\Models\Admin;

/**
 * Invitations to set a first password. A pending invite is an inactive human account with a row in
 * `admin_invites` (hashed one-time code, 7-day expiry through the `admin_invites` password broker). The row stays after
 * the code expires so the Users list can still offer "Resend invite"; accepting deletes it.
 */
class UserInvites
{
    public const BROKER = 'admin_invites';

    public const TABLE = 'admin_invites';

    public const MAX_PER_SUBMISSION = 50;

    public const VALID_DAYS = 7;

    public function broker(): PasswordBroker
    {
        return Password::broker(self::BROKER);
    }

    /**
     * Split pasted text (comma, semicolon, space or newline separated) into distinct lower-cased addresses.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public function parse(string $text): array
    {
        $valid = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $email = Str::lower($entry);

            if (strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[$email] = $email;
            } else {
                $invalid[$entry] = $entry;
            }
        }

        return ['valid' => array_values($valid), 'invalid' => array_values($invalid)];
    }

    public function isPending(Admin $admin): bool
    {
        return ! $admin->status && ! $admin->isApiUser()
            && DB::table(self::TABLE)->where('email', $admin->email)->exists();
    }

    /**
     * A fresh one-time code (8 digits); the broker deletes the invitee's previous row, so the old code stops
     * working and the attempt count starts again.
     */
    public function issue(Admin $admin): string
    {
        return $this->broker()->createToken($admin);
    }

    /**
     * The stored invite row for $email when $plainCode is its code and the row is still inside the broker's
     * expiry and has attempts left; null otherwise. This is the expensive part of a redemption (a hash check),
     * done before any lock is taken. Spaces and hyphens in the typed code are ignored. One attempt is spent in
     * a single statement before the hash check, so parallel requests never get more than MAX_ATTEMPTS checks
     * against one stored code, and a correct code on a spent row fails. The framework stores
     * `$hasher->make($code)`, so Hash::check is the matching check.
     */
    public function verify(string $email, string $plainCode): ?object
    {
        $row = DB::table(self::TABLE)->where('email', $email)->first();

        if (! $row || ! is_string($row->token) || $row->token === '') {
            return null;
        }

        if (! $row->created_at || Carbon::parse($row->created_at)->lt($this->oldestValidCreatedAt())) {
            return null;
        }

        $code = CodeTokenRepository::normalize($plainCode);

        // A string that cannot be a code is no guess at one: refuse without a hash check or a spent attempt.
        if (preg_match('/^\d{'.CodeTokenRepository::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        if (! CodeTokenRepository::spendOn(table: DB::table(self::TABLE), email: (string) $row->email, storedHash: $row->token)) {
            return null;
        }

        return Hash::check($code, $row->token) ? $row : null;
    }

    /**
     * Consume an invite: delete the row only if it still holds exactly the hash that verify() accepted and has
     * not expired, in one statement. True when exactly one row went. That makes a code single-use, refuses one
     * that a resend replaced while the request waited for the account lock, and refuses one that expired in
     * that wait.
     */
    public function consume(string $email, string $storedToken): bool
    {
        return DB::table(self::TABLE)
            ->where('email', $email)
            ->where('token', $storedToken)
            ->where('created_at', '>=', $this->oldestValidCreatedAt())
            ->delete() === 1;
    }

    /**
     * The earliest `created_at` an invite may carry and still be valid now: the one expiry both verify() and
     * consume() apply, taken from the broker's configuration. Bound as a Carbon value, so each driver formats it.
     */
    private function oldestValidCreatedAt(): Carbon
    {
        return Carbon::now()->subMinutes((int) config('auth.passwords.'.self::BROKER.'.expire'));
    }

    public function send(Admin $admin, string $token): void
    {
        $admin->notify(new InviteNotification($token));
    }

    public function forget(string $email): void
    {
        DB::table(self::TABLE)->where('email', $email)->delete();
    }

    /**
     * Best effort, like every other security event: recording never fails the invite.
     */
    public function record(string $event, Admin $subject, ?int $actorId): void
    {
        if (! class_exists(SecurityEventRecorder::class)) {
            return;
        }

        app(SecurityEventRecorder::class)->record(new SecurityEvent(
            name: $event,
            guard: 'admin',
            actorAdminId: $actorId,
            identifier: $subject->email,
        ));
    }

    public static function eventName(string $kind): string
    {
        return match ($kind) {
            'sent'     => SecurityEvent::INVITE_SENT,
            'resent'   => SecurityEvent::INVITE_RESENT,
            default    => SecurityEvent::INVITE_ACCEPTED,
        };
    }
}
