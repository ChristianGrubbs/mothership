<?php

use Mothership\DAM\Providers\DAMServiceProvider;
use Mothership\DAM\Services\Transfers\TransferSession;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    config([
        'app.url'                  => 'http://localhost',
        'dam.transfers.enabled'    => true,
        'dam.transfers.origin'     => 'https://transfers.example.test',
        'dam.transfers.shared_key' => bin2hex(random_bytes(32)),
    ]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    $this->person = Admin::factory()->create(['name' => 'Studio Person', 'role_id' => $role->id, 'status' => 1]);
    $this->actingAs($this->person, 'admin');
});

it('issues signed short lived identity from the session and ignores posted identity', function () {
    $response = $this->postJson(route('admin.dam.transfers.grant'), ['sub' => '999', 'name' => 'Impersonated'])
        ->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    [$body, $signature] = explode('.', $response->json('grant'));
    $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, config('dam.transfers.shared_key'), true)), '+/', '-_'), '=');
    expect($signature)->toBe($expected)
        ->and($claims['sub'])->toBe((string) $this->person->id)
        ->and($claims['name'])->toBe('Studio Person')
        ->and($claims['aud'])->toBe('mothership-transfers')
        ->and($claims['exp'] - $claims['iat'])->toBe(TransferSession::LIFETIME_SECONDS)
        ->and($response->json('expiresAt'))->toBe($claims['exp']);
    $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    $next = $this->postJson(route('admin.dam.transfers.grant'))->assertOk();
    $renewed = json_decode(base64_decode(strtr(explode('.', $next->json('grant'))[0], '-_', '+/')), true);
    expect($renewed['jti'])->not->toBe($claims['jti'])
        ->and($renewed['sub'])->toBe($claims['sub'])
        ->and($renewed['sid'])->toBe($claims['sid']);
});

it('mounts the shared runtime on Home and Transfers without embedding grants or keys', function () {
    $this->get(route('admin.dam.transfers.index', ['person' => $this->person->id]))->assertOk()
        ->assertSee('data-transfers-host', false)
        ->assertDontSee(config('dam.transfers.shared_key'), false)
        ->assertViewHas('transfers', fn (array $value) => $value['selectedUserId'] === (string) $this->person->id
            && $value['origin'] === 'https://transfers.example.test' && ! isset($value['grant']));
    $this->get(route('admin.home.index'))->assertOk()->assertSee('data-transfers-host', false);
});

it('keeps grant renewals separate from Home action limits and retains the Transfers limit', function () {
    $this->person->role->update(['permission_type' => 'all']);
    $this->mock(Vectorizer::class)->shouldReceive('ready')->andReturnTrue();
    $home = route('admin.home.vectorization.store');
    $grant = route('admin.dam.transfers.grant');
    // This empty action must reach validation both before and after grant renewals.
    $this->postJson($home, [])->assertUnprocessable();
    for ($renewal = 0; $renewal < 11; $renewal++) {
        $this->postJson($grant, [])->assertOk();
    }
    $this->postJson($home, [])->assertUnprocessable();
    for ($renewal = 11; $renewal < 120; $renewal++) {
        $this->postJson($grant, [])->assertOk();
    }
    $this->postJson($grant, [])->assertTooManyRequests();
    $this->postJson($home, [])->assertUnprocessable();
});

it('stops issuing grants after an account is disabled', function () {
    Admin::whereKey($this->person->id)->update(['status' => 0]);
    $this->postJson(route('admin.dam.transfers.grant'))->assertForbidden();
});

it('does not expose transfers while disabled or incorrectly configured', function (string $setting, mixed $value) {
    config([$setting => $value]);
    $this->postJson(route('admin.dam.transfers.grant'))->assertNotFound();
    $this->get(route('admin.dam.transfers.index'))->assertNotFound();
})->with([
    ['dam.transfers.enabled', false],
    ['dam.transfers.shared_key', ''],
    ['dam.transfers.origin', 'http://remote.example.test'],
    ['dam.transfers.origin', 'http://localhost'],
    ['dam.transfers.origin', 'https://user@example.test'],
    ['dam.transfers.origin', 'https://example.test/path'],
    ['dam.transfers.origin', 'https://example.test?query=1'],
]);

it('offers Transfers in the menu only when the page behind it can open', function (array $settings, bool $offered) {
    config($settings);

    $keys = array_column(DAMServiceProvider::menuWithoutUnavailable(menu: [['key' => 'dam'], ['key' => 'dam_transfers']]), 'key');

    expect($keys)->toContain('dam')
        ->and(in_array('dam_transfers', $keys, true))->toBe($offered);
})->with([
    'switched on, with an origin and a key' => [[], true],
    'switched off'                          => [['dam.transfers.enabled' => false], false],
    'no shared key'                         => [['dam.transfers.shared_key' => ''], false],
    'a key that is too short'               => [['dam.transfers.shared_key' => 'short'], false],
    'no origin'                             => [['dam.transfers.origin' => ''], false],
]);

it('allows a distinct loopback origin only in local or testing environments', function () {
    config(['dam.transfers.origin' => 'http://localhost:18341']);
    $this->postJson(route('admin.dam.transfers.grant'))->assertOk();
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(app(TransferSession::class)->origin())->toBeNull();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('bounds astral names for the signaling service and supplies an empty name fallback', function () {
    foreach ([str_repeat('😀', 128), ''] as $name) {
        $this->person->update(['name' => $name]);
        $response = $this->postJson(route('admin.dam.transfers.grant'))->assertOk();
        $body = explode('.', $response->json('grant'))[0];
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        expect($claims['name'])->not->toBe('')
            ->and(strlen(mb_convert_encoding($claims['name'], 'UTF-16LE', 'UTF-8')) / 2)->toBeLessThanOrEqual(200);
        if ($name === '') {
            expect($claims['name'])->toBe($this->person->email);
        }
    }
});

it('rejects API accounts even when their role has all permissions', function () {
    $this->person->role->update(['permission_type' => 'all']);
    $this->person->forceFill(['type' => 'api'])->save();
    $this->postJson(route('admin.dam.transfers.grant'))->assertForbidden();
});

it('requires a signed in session for grants', function () {
    auth('admin')->logout();
    $this->postJson(route('admin.dam.transfers.grant'))->assertUnauthorized();
});
