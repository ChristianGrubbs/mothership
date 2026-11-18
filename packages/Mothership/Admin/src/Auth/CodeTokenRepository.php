<?php

namespace Mothership\Admin\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Query\Builder;

/**
 * One-time codes in place of the framework's long random token, for every database-driver password broker
 * (`admins` resets and `admin_invites`). A code is 8 decimal digits, stored hashed with the row's `attempts`
 * counter. Eight digits are guessable, so every check spends an attempt first, in one statement, and a row
 * whose attempts are used up refuses every code, even the right one, until a new code replaces the row.
 *
 * A successful exists() consumes the code (compare-and-delete on the stored hash), so only one of two overlapping
 * requests holding the right code gets through. Do not call it as a pre-check, for instance through
 * `PasswordBroker::tokenExists()`: the check itself spends the code.
 */
class CodeTokenRepository extends DatabaseTokenRepository
{
    public const DIGITS = 8;

    public const MAX_ATTEMPTS = 5;

    public function createNewToken(): string
    {
        return str_pad((string) random_int(0, (10 ** self::DIGITS) - 1), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return parent::getPayload($email, $token) + ['attempts' => 0];
    }

    public function exists(CanResetPasswordContract $user, #[\SensitiveParameter] $token): bool
    {
        $email = $user->getEmailForPasswordReset();
        $record = (array) $this->getTable()->where('email', $email)->first();

        if (! $record || ! is_string($record['token'] ?? null) || $record['token'] === '' || $this->tokenExpired($record['created_at'])) {
            return false;
        }

        $code = self::normalize((string) $token);

        // A string that cannot be a code is no guess at one: refuse without a hash check or a spent attempt.
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        // Spend the attempt before the hash check, in one statement conditional on the stored hash, so parallel
        // requests never get more than MAX_ATTEMPTS hash checks against one stored code.
        if (! $this->spendAttempt(email: (string) $email, storedHash: $record['token'])) {
            return false;
        }

        if (! $this->hasher->check($code, $record['token'])) {
            return false;
        }

        // Consume it: only the request whose delete removes the row proceeds.
        return $this->getTable()->where('email', $email)->where('token', $record['token'])->delete() === 1;
    }

    /**
     * True when exactly one attempt was spent on the row holding $storedHash.
     */
    public function spendAttempt(string $email, string $storedHash): bool
    {
        return self::spendOn(table: $this->getTable(), email: $email, storedHash: $storedHash);
    }

    /**
     * The one attempt-spending statement, shared with invite redemption (UserInvites::verify).
     */
    public static function spendOn(Builder $table, string $email, string $storedHash): bool
    {
        return $table
            ->where('email', $email)
            ->where('token', $storedHash)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts') === 1;
    }

    /**
     * What a person typed, reduced to the digits: whitespace and hyphens removed.
     */
    public static function normalize(string $input): string
    {
        return (string) preg_replace('/[\s\-]+/u', '', $input);
    }

    /**
     * The code as shown in mail and on screen: `12345678` becomes `1234 5678`.
     */
    public static function display(string $code): string
    {
        return trim(chunk_split($code, intdiv(self::DIGITS, 2), ' '));
    }
}
