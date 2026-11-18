<?php

namespace Mothership\AdminApi\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Bridge\UserRepository as BaseUserRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Mothership\User\Models\Admin;

/**
 * UserRepository backing Passport's OAuth2 password grant with the Admin model instead of App\Models\User.
 */
class UserRepository extends BaseUserRepository
{
    /**
     * Cache key prefix of the throwaway hash a client with no owner is verified against.
     */
    private const DUMMY_HASH_CACHE_KEY = 'admin-api:oauth:dummy-password-hash';

    /**
     * Get a user entity by user credentials.
     *
     * An integration client acts only for the admin it belongs to, so that admin is resolved from the client,
     * never from the supplied email. Every attempt then does the same work whatever the email: exactly one
     * password check against the owner's own hash, with no hashing. A guess for an unknown email, another
     * admin's email or the owner with a wrong password cannot be told apart by timing or by answer (all
     * invalid_grant). Access is granted only when the email is the owner's and the password matches.
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ): ?UserEntityInterface {
        $client = Passport::client()->newQuery()->whereKey($clientEntity->getIdentifier())->first();
        $owner = $client?->user_id ? Admin::query()->whereKey($client->user_id)->first() : null;

        // An inactive owner keeps its own hash: the same cost as an active one, and still refused below.
        $hash = is_string($owner?->password) && $owner->password !== '' ? $owner->password : $this->dummyHash();

        $passwordMatches = $this->hasher->check((string) $password, $hash);
        $emailMatches = $owner !== null && hash_equals(self::normalizeEmail((string) $owner->email), self::normalizeEmail((string) $username));

        // Truthy check (not === 0) so a status string "0" from emulated prepared statements still reads as inactive.
        if (! $owner || ! $owner->status || ! $emailMatches || ! $passwordMatches || $hash !== $owner->password) {
            return null;
        }

        return $this->makeUserEntity($owner);
    }

    /**
     * Make (and cache) the dummy hash now, outside any credential check. Called after a token response is
     * sent, so a later attempt for an ownerless client finds it warm.
     */
    public function warmDummyHash(): void
    {
        $this->dummyHash();
    }

    /**
     * A hash of a random secret made with the configured driver and work factors, so checking against it costs
     * what checking a real hash costs. The cache key names the driver and its cost parameters, and a cached hash
     * whose cost no longer matches the hasher (needsRehash) is replaced, so a cost change never leaves a cheaper
     * or dearer dummy behind.
     */
    private function dummyHash(): string
    {
        $key = self::DUMMY_HASH_CACHE_KEY.':'.self::costSignature();
        $hash = Cache::get($key);

        if (! is_string($hash) || $hash === '' || $this->hasher->needsRehash($hash)) {
            $hash = $this->hasher->make(Str::random(40));
            Cache::forever($key, $hash);
        }

        return $hash;
    }

    /**
     * The configured hashing driver and the work factors that set the cost of a check.
     */
    private static function costSignature(): string
    {
        $driver = (string) config('hashing.driver', 'bcrypt');

        $factors = match ($driver) {
            'argon', 'argon2id' => [config('hashing.argon.memory'), config('hashing.argon.time'), config('hashing.argon.threads')],
            default             => [config('hashing.bcrypt.rounds')],
        };

        return $driver.':'.implode('-', array_map(fn ($factor) => (int) $factor, $factors));
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Make a Passport user entity from an Admin model instance.
     *
     * @param  Admin  $user
     * @return User
     */
    protected function makeUserEntity($user)
    {
        return new User($user->id);
    }
}
