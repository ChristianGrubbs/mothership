<?php

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\Client as PassportClient;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\AdminApi\Repositories\UserRepository;
use Mothership\User\Models\Admin;

it('should return access token with valid credentials', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $admin = Admin::factory()->create(['password' => bcrypt('password')]);

    $clientRepo = new ClientRepository;
    // Passport 13 dropped userId + redirect args from createPasswordGrantClient.
    $client = $clientRepo->createPasswordGrantClient(
        'Auth Test Client', 'admins', confidential: true
    );

    $client->forceFill([
        'user_id'    => $admin->id,
        'owner_type' => Admin::class,
        'owner_id'   => $admin->id,
    ])->save();

    Apikey::factory()->create([
        'permission_type' => 'all',
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
    ]);

    $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'password',
        'scope'         => '',
    ])
        ->assertOk()
        ->assertJsonStructure([
            'token_type',
            'expires_in',
            'access_token',
            'refresh_token',
        ]);
});

it('should return error with invalid credentials', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $admin = Admin::factory()->create(['password' => bcrypt('password')]);

    $clientRepo = new ClientRepository;
    // Passport 13 dropped userId + redirect args from createPasswordGrantClient.
    $client = $clientRepo->createPasswordGrantClient(
        'Auth Test Client', 'admins', confidential: true
    );

    $client->forceFill([
        'user_id'    => $admin->id,
        'owner_type' => Admin::class,
        'owner_id'   => $admin->id,
    ])->save();

    Apikey::factory()->create([
        'permission_type' => 'all',
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
    ]);

    $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'wrongpassword',
        'scope'         => '',
    ])
        ->assertStatus(400);
});

it('should return error with invalid client credentials', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $admin = Admin::factory()->create(['password' => bcrypt('password')]);

    $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => '00000000-0000-0000-0000-000000000000',
        'client_secret' => 'invalid-secret',
        'username'      => $admin->email,
        'password'      => 'password',
        'scope'         => '',
    ])
        ->assertStatus(401);
});

it('should return 401 when no token is provided', function () {
    $this->json('GET', route('admin.api.locales.index'), [], [
        'Accept' => 'application/json',
    ])
        ->assertUnauthorized();
});

it('should return 401 with invalid bearer token', function () {
    $this->json('GET', route('admin.api.locales.index'), [], [
        'Authorization' => 'Bearer invalid-token-string',
        'Accept'        => 'application/json',
    ])
        ->assertUnauthorized();
});

it('should return 401 json when no token and no Accept header', function () {
    $response = $this->call('GET', route('admin.api.locales.index'));

    $response->assertStatus(401);
    expect($response->json('error'))->not->toBeNull();
});

it('should return 401 json for invalid token without Accept header', function () {
    $response = $this->call('GET', route('admin.api.locales.index'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer invalid-token-string',
    ]);

    $response->assertStatus(401);
    expect($response->json('error'))->not->toBeNull();
});

it('should return 406 when Accept header is not application/json', function () {
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders([
        'Authorization' => $headers['Authorization'],
        'Accept'        => 'text/html',
    ])->get(route('admin.api.locales.index'))
        ->assertStatus(406);
});

it('should return access token with refresh token', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $admin = Admin::factory()->create(['password' => bcrypt('password')]);

    $clientRepo = new ClientRepository;
    // Passport 13 dropped userId + redirect args from createPasswordGrantClient.
    $client = $clientRepo->createPasswordGrantClient(
        'Auth Test Client', 'admins', confidential: true
    );

    $client->forceFill([
        'user_id'    => $admin->id,
        'owner_type' => Admin::class,
        'owner_id'   => $admin->id,
    ])->save();

    Apikey::factory()->create([
        'permission_type' => 'all',
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
    ]);

    $tokenResponse = $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'password',
        'scope'         => '',
    ])->json();

    $this->postJson('/oauth/token', [
        'grant_type'    => 'refresh_token',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'refresh_token' => $tokenResponse['refresh_token'],
        'scope'         => '',
    ])
        ->assertOk()
        ->assertJsonStructure([
            'token_type',
            'expires_in',
            'access_token',
            'refresh_token',
        ]);
});

it('answers another admin\'s credentials on a client with invalid_grant, the same as a wrong password (FAULT-13)', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $owner = Admin::factory()->create(['password' => bcrypt('password')]);
    $other = Admin::factory()->create(['password' => bcrypt('password')]);

    $client = (new ClientRepository)->createPasswordGrantClient('Auth Test Client', 'admins', confidential: true);
    $client->forceFill(['user_id' => $owner->id, 'owner_type' => Admin::class, 'owner_id' => $owner->id])->save();
    Apikey::factory()->create(['permission_type' => 'all', 'admin_id' => $owner->id, 'oauth_client_id' => $client->getKey()]);

    $attempt = fn (string $username, string $password) => $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $username,
        'password'      => $password,
        'scope'         => '',
    ]);

    $wrongUser = $attempt($other->email, 'password')->assertStatus(400)->json();
    $wrongPassword = $attempt($owner->email, 'wrongpassword')->assertStatus(400)->json();

    expect($wrongUser['error'])->toBe('invalid_grant')
        ->and($wrongUser)->toBe($wrongPassword);
});

it('mounts only the token endpoints, not Passport\'s unconfigured browser or device flows (FAULT-12)', function () {
    $this->get('/oauth/authorize')->assertNotFound();
    $this->get('/oauth/device')->assertNotFound();
    $this->postJson('/oauth/device/code')->assertNotFound();

    expect(route('passport.token', absolute: false))->toBe('/oauth/token')
        ->and(route('passport.token.refresh', absolute: false))->toBe('/oauth/token/refresh');
});

/**
 * A hasher that counts the work an OAuth attempt does: every make() and every check(), and which hash was checked.
 */
function countingHasher(Hasher $inner): Hasher
{
    return new class($inner) implements Hasher
    {
        public int $makes = 0;

        public array $checked = [];

        public function __construct(private Hasher $inner) {}

        public function info($hashedValue)
        {
            return $this->inner->info($hashedValue);
        }

        public function make($value, array $options = [])
        {
            $this->makes++;

            return $this->inner->make($value, $options);
        }

        public function check($value, $hashedValue, array $options = [])
        {
            $this->checked[] = $hashedValue;

            return $this->inner->check($value, $hashedValue, $options);
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }

        public function reset(): void
        {
            $this->makes = 0;
            $this->checked = [];
        }
    };
}

/**
 * Point the hashing config at one driver with cheap work factors, and return a hasher built from it.
 */
function oauthHasher(string $driver, int $rounds = 4): Hasher
{
    if ($driver === 'argon2id') {
        config(['hashing.driver' => 'argon2id', 'hashing.argon' => ['memory' => 1024, 'time' => 1, 'threads' => 1]]);

        return countingHasher(new Argon2IdHasher(['memory' => 1024, 'time' => 1, 'threads' => 1]));
    }

    config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => $rounds]);

    return countingHasher(new BcryptHasher(['rounds' => $rounds]));
}

function ownedPasswordClient(Admin $owner): PassportClient
{
    $client = (new ClientRepository)->createPasswordGrantClient('Timing Test Client', 'admins', confidential: true);
    $client->forceFill(['user_id' => $owner->id, 'owner_type' => Admin::class, 'owner_id' => $owner->id])->save();

    return new PassportClient((string) $client->getKey());
}

it('does the same work for every guess: one check against the owner\'s hash and no hashing, cold or warm', function (string $driver, int $rounds) {
    if ($driver === 'argon2id' && ! defined('PASSWORD_ARGON2ID')) {
        $this->markTestSkipped('This PHP build has no Argon2id support.');
    }

    $hasher = oauthHasher($driver, $rounds);
    $owner = Admin::factory()->create(['email' => 'Owner@Example.com', 'password' => $hasher->make('password'), 'status' => 1]);
    $other = Admin::factory()->create(['password' => $hasher->make('password'), 'status' => 1]);
    $entity = ownedPasswordClient($owner);
    $repository = new UserRepository($hasher);

    $guesses = [
        'owner, wrong password'  => [$owner->email, 'wrong'],
        'unknown email'          => ['nobody-'.Str::random(8).'@example.com', 'password'],
        'another admin'          => [$other->email, 'password'],
        'owner, right password'  => ['  owner@example.COM ', 'password'],
    ];

    foreach (['cold', 'warm'] as $cache) {
        if ($cache === 'cold') {
            Cache::flush();
        }

        foreach ($guesses as $label => [$email, $password]) {
            $hasher->reset();
            $granted = $repository->getUserEntityByUserCredentials($email, $password, 'password', $entity);

            expect($hasher->makes)->toBe(0, "$cache/$label hashed")
                ->and($hasher->checked)->toBe([$owner->password], "$cache/$label checked something else")
                ->and($granted !== null)->toBe($label === 'owner, right password', "$cache/$label grant");
        }
    }
})->with([
    'bcrypt'           => ['bcrypt', 4],
    'bcrypt, 5 rounds' => ['bcrypt', 5],
    'argon2id'         => ['argon2id', 0],
]);

it('refuses an inactive owner with the same single check against its own hash', function () {
    $hasher = oauthHasher('bcrypt');
    $owner = Admin::factory()->create(['password' => $hasher->make('password'), 'status' => 0]);
    $entity = ownedPasswordClient($owner);
    $hasher->reset();

    expect((new UserRepository($hasher))->getUserEntityByUserCredentials($owner->email, 'password', 'password', $entity))->toBeNull()
        ->and($hasher->makes)->toBe(0)
        ->and($hasher->checked)->toBe([$owner->password]);
});

it('checks an ownerless client against a warmed dummy hash at the current cost, and replaces it when the cost changes', function (string $driver) {
    if ($driver === 'argon2id' && ! defined('PASSWORD_ARGON2ID')) {
        $this->markTestSkipped('This PHP build has no Argon2id support.');
    }

    Cache::flush();
    $hasher = oauthHasher($driver, 4);
    $owner = Admin::factory()->create(['password' => $hasher->make('password')]);
    $entity = ownedPasswordClient($owner);
    $owner->delete();
    $repository = new UserRepository($hasher);

    // Warmed after a token response (AdminApiServiceProvider's terminating hook), never during a check.
    $hasher->reset();
    $repository->warmDummyHash();
    expect($hasher->makes)->toBe(1);

    foreach (['nobody@example.com', 'someone@example.com'] as $email) {
        $hasher->reset();
        expect($repository->getUserEntityByUserCredentials($email, 'password', 'password', $entity))->toBeNull()
            ->and($hasher->makes)->toBe(0)
            ->and($hasher->checked)->toHaveCount(1)
            ->and($hasher->needsRehash($hasher->checked[0]))->toBeFalse();
    }

    if ($driver === 'bcrypt') {
        // A cost change gets a dummy at the new cost: a stale one would check faster or slower than real hashes.
        $stale = $hasher->checked[0];
        $hasher = oauthHasher('bcrypt', 5);
        $repository = new UserRepository($hasher);
        $repository->warmDummyHash();
        $hasher->reset();
        $repository->getUserEntityByUserCredentials('nobody@example.com', 'password', 'password', $entity);

        expect($hasher->makes)->toBe(0)
            ->and($hasher->checked[0])->not->toBe($stale)
            ->and(password_get_info($hasher->checked[0])['options']['cost'])->toBe(5);
    }
})->with(['bcrypt', 'argon2id']);

it('warms the dummy hash after a token response, so the next attempt does not hash', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Cache::flush();

    $this->postJson('/oauth/token', ['grant_type' => 'password', 'client_id' => 'missing', 'client_secret' => 'x', 'username' => 'a@b.c', 'password' => 'x', 'scope' => ''])
        ->assertStatus(401);

    $cached = Cache::get('admin-api:oauth:dummy-password-hash:'.config('hashing.driver').':'.(int) config('hashing.bcrypt.rounds'));

    expect($cached)->toBeString()
        ->and(app('hash')->needsRehash($cached))->toBeFalse();
});

it('answers an unknown email and another admin\'s email with the same invalid_grant body', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $owner = Admin::factory()->create(['password' => bcrypt('password')]);
    $other = Admin::factory()->create(['password' => bcrypt('password')]);

    $client = (new ClientRepository)->createPasswordGrantClient('Timing Body Client', 'admins', confidential: true);
    $client->forceFill(['user_id' => $owner->id, 'owner_type' => Admin::class, 'owner_id' => $owner->id])->save();
    Apikey::factory()->create(['permission_type' => 'all', 'admin_id' => $owner->id, 'oauth_client_id' => $client->getKey()]);

    $attempt = fn (string $username) => $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $username,
        'password'      => 'password',
        'scope'         => '',
    ])->assertStatus(400)->json();

    $unknown = $attempt('nobody-'.Str::random(8).'@example.com');

    expect($unknown['error'])->toBe('invalid_grant')
        ->and($unknown)->toBe($attempt($other->email));
});
