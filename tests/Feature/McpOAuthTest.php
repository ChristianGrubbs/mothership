<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mothership\AdminApi\Http\Middleware\McpAuthorization;
use Mothership\AdminApi\Http\Middleware\McpTokenRequest;
use Mothership\AdminApi\Http\Middleware\McpTokenThrottle;
use Mothership\AdminApi\Providers\AdminApiServiceProvider;
use Mothership\AdminApi\Providers\McpOAuthServiceProvider;
use Mothership\AdminApi\Services\McpAccessToken;
use Mothership\AdminApi\Services\McpOAuth;
use Mothership\AdminApi\Services\McpTokenExchanged;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\User\Models\Admin;

beforeEach(function () {
    config(['mcp-oauth.enabled' => true, 'mcp-oauth.issuer' => 'https://space.example']);
    $this->app->register(McpOAuthServiceProvider::class);
    Route::getRoutes()->refreshNameLookups();
    $this->mcpClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Web MCP test', redirectUris: ['https://client.example/callback'], confidential: false,
    );
    config(['mcp-oauth.clients' => [$this->mcpClient->getKey()]]);
    $this->mcpUser = Admin::factory()->create();
    $this->verifier = str_repeat('a', 43);
    $this->authorization = [
        'client_id'             => $this->mcpClient->getKey(),
        'redirect_uri'          => 'https://client.example/callback',
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'state'                 => 'test-state',
        'resource'              => 'https://space.example/mcp',
        'code_challenge_method' => 'S256',
        'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
    ];
    Route::get('/_mcp-auth-test', function () {
        return response()->json([
            'id'        => request()->user()->getKey(),
            'admin_id'  => auth('admin')->id(),
            'client_id' => request()->user()->token()->client_id,
        ]);
    })->middleware('mcp.auth');

    $this->getCode = function () {
        $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))
            ->assertOk()->assertSee('Allow connection');
        $approval = $this->post('/oauth/mcp/authorize', ['auth_token' => session('authToken')]);
        $approval->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
        expect($query['state'])->toBe('test-state')
            ->and($query['iss'])->toBe('https://space.example');

        return $query['code'];
    };
    $this->exchange = fn (string $code, array $overrides = []) => $this->postJson('/oauth/token', array_merge([
        'grant_type'    => 'authorization_code',
        'client_id'     => $this->mcpClient->getKey(),
        'redirect_uri'  => 'https://client.example/callback',
        'code'          => $code,
        'code_verifier' => $this->verifier,
        'resource'      => 'https://space.example/mcp',
    ], $overrides));
});

it('publishes resource and PKCE metadata only when enabled', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', 'https://space.example/mcp');
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('authorization_response_iss_parameter_supported', true)
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonMissingPath('registration_endpoint');
    config(['mcp-oauth.enabled' => false]);
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
    $this->getJson('/_mcp-auth-test')->assertNotFound();
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertNotFound();
});

it('sends an unauthenticated valid authorization request to the admin login', function () {
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))
        ->assertRedirect(route('admin.session.create'));
});

it('preserves consent only when a repeated normal login keeps the same account', function (bool $switchAccount) {
    $nextUser = $switchAccount ? Admin::factory()->create() : $this->mcpUser;
    $this->actingAs($this->mcpUser, 'admin')
        ->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $approvalToken = session('authToken');
    $this->postJson(route('admin.session.store'), ['email' => $nextUser->email, 'password' => 'password'])->assertOk();
    expect(auth('admin')->id())->toBe($nextUser->id);
    $response = $this->post('/oauth/mcp/authorize', ['auth_token' => $approvalToken]);
    if ($switchAccount) {
        $response->assertStatus(400)->assertSee('Nothing was approved.');
        expect(session()->has('authRequest'))->toBeFalse();
        $restart = $response->viewData('restart');
        expect($restart)->toStartWith('/oauth/mcp/authorize?');
        Route::getRoutes()->getByName('mcp.oauth.authorize')->flushController();
        $this->get($restart)->assertOk()->assertSee($nextUser->email)->assertSee('Allow connection');
        expect(session('authToken'))->not->toBe($approvalToken);
        $fresh = $this->post('/oauth/mcp/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($fresh->headers->get('Location'), PHP_URL_QUERY), $query);
        $issued = ($this->exchange)($query['code'])->assertOk();
        auth()->forgetGuards();
        $this->withToken($issued->json('access_token'))->getJson('/_mcp-auth-test')->assertOk()
            ->assertJsonPath('id', $nextUser->id);
    } else {
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        ($this->exchange)($query['code'])->assertOk();
    }
})->with(['same account' => false, 'changed account' => true]);

it('records only typed rejection diagnostics and preserves JSON failure responses', function () {
    $log = storage_path('logs/mcp-oauth.jsonl');
    $before = is_file($log) ? filesize($log) : 0;
    config(['mcp-oauth.logging' => true]);
    $this->actingAs($this->mcpUser, 'admin')->postJson('/oauth/mcp/authorize')
        ->assertStatus(400)->assertExactJson(['error' => 'invalid_request']);
    $stream = fopen($log, 'rb');
    fseek($stream, $before);
    $row = json_decode(trim(stream_get_contents($stream)), true, flags: JSON_THROW_ON_ERROR);
    fclose($stream);
    expect($row['event'])->toBe('oauth.authorization_rejected')
        ->and($row['ctx'])->toBe(['status' => 400, 'reason' => 'pending_missing'])
        ->and(array_keys($row))->toBe(['ts', 'run_id', 'level', 'event', 'loc', 'ctx']);
});

it('asks the client for a new request when the pending session was lost', function () {
    $response = $this->actingAs($this->mcpUser, 'admin')->post('/oauth/mcp/authorize')->assertStatus(400);
    expect($response->viewData('restart'))->toBeNull();
    $response->assertSee('Return to your AI app');
    expect(session()->has('authRequest'))->toBeFalse();
});

it('does not restart completed approvals or denials', function (string $method) {
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $approvalToken = session('authToken');
    $this->{$method}('/oauth/mcp/authorize', ['auth_token' => $approvalToken])->assertRedirect();
    expect(session()->has('mcpAuthorization'))->toBeFalse();
    $response = $this->post('/oauth/mcp/authorize', ['auth_token' => $approvalToken])->assertStatus(400);
    expect($response->viewData('restart'))->toBeNull();
})->with(['post', 'delete']);

it('keeps CSRF rejection after logout and login while giving the browser plain reconnect guidance', function (string $method, bool $debug) {
    config(['app.debug' => $debug]);
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $oldCsrf = session()->token();
    $approvalToken = session('authToken');
    $this->delete(route('admin.session.destroy'))->assertRedirect();
    $nextUser = Admin::factory()->create();
    $this->postJson(route('admin.session.store'), ['email' => $nextUser->email, 'password' => 'password'])->assertOk();
    expect(session()->token())->not->toBe($oldCsrf);
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $log = storage_path('logs/mcp-oauth.jsonl');
    $before = is_file($log) ? filesize($log) : 0;
    config(['mcp-oauth.logging' => true]);
    $this->{$method}('/oauth/mcp/authorize', ['_token' => $oldCsrf, 'auth_token' => $approvalToken])
        ->assertStatus(419)->assertSee('Return to your AI app')->assertDontSee('Continue to check');
    $stream = fopen($log, 'rb');
    fseek($stream, $before);
    $rows = array_filter(explode(PHP_EOL, trim(stream_get_contents($stream))));
    fclose($stream);
    expect($rows)->toHaveCount(1)
        ->and(json_decode($rows[0], true, flags: JSON_THROW_ON_ERROR)['ctx'])
        ->toBe(['status' => 419, 'reason' => 'csrf_expired']);
    config(['mcp-oauth.logging' => false]);
    expect(session()->has('authRequest'))->toBeFalse();
    $this->postJson('/oauth/mcp/authorize', ['_token' => $oldCsrf])->assertStatus(419)
        ->assertJsonMissingPath('restart');
    $this->post(route('admin.session.store'), ['_token' => $oldCsrf])->assertStatus(419)
        ->assertDontSee('Return to your AI app');
})->with([['post', true], ['post', false], ['delete', true], ['delete', false]]);

it('does not offer a dead-end retry when authorization configuration changes', function (string $change) {
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    match ($change) {
        'client'   => $this->mcpClient->update(['revoked' => true]),
        'redirect' => $this->mcpClient->update(['redirect_uris' => ['https://client.example/other']]),
        'resource' => session()->put('mcpResource', 'https://other.example/mcp'),
    };
    $response = $this->post('/oauth/mcp/authorize', ['auth_token' => session('authToken')])->assertStatus(400);
    expect($response->viewData('restart'))->toBeNull();
})->with(['client', 'redirect', 'resource']);

it('identifies the issuer on approval and denial while preserving registered callback query bytes', function (string $method) {
    $callback = 'https://client.example/callback?tenant=one&keep=a%2Fb&iss=old';
    $this->mcpClient->update(['redirect_uris' => [$callback]]);
    $this->authorization['redirect_uri'] = $callback;
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $response = $this->{$method}('/oauth/mcp/authorize', ['auth_token' => session('authToken')])->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith('https://client.example/callback?tenant=one&keep=a%2Fb&')
        ->and(substr_count($location, 'iss='))->toBe(1)
        ->and($query['iss'])->toBe('https://space.example')
        ->and($query['state'])->toBe('test-state');
    if ($method === 'post') {
        expect($query['code'])->toBeString();
        ($this->exchange)($query['code'], ['redirect_uri' => $callback])->assertOk();
    } else {
        expect($query['error'])->toBe('access_denied');
    }
})->with(['post', 'delete']);

it('identifies the issuer on a Passport authorization error response', function () {
    Route::get('/_mcp-oauth-error', function () {
        $error = new OAuthServerException('Authorization failed.', 9, 'access_denied', 401, null, 'https://client.example/callback');
        $error->setPayload(['error' => 'access_denied', 'state' => 'test-state']);
        throw new Laravel\Passport\Exceptions\OAuthServerException($error);
    })->middleware(['web', McpAuthorization::class]);
    $response = $this->actingAs($this->mcpUser, 'admin')
        ->get('/_mcp-oauth-error?'.http_build_query($this->authorization))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['error' => 'access_denied', 'state' => 'test-state', 'iss' => 'https://space.example']);
});

it('refuses a callback removed from the client while consent is pending', function () {
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $this->mcpClient->update(['redirect_uris' => ['https://client.example/replacement']]);
    $this->post('/oauth/mcp/authorize', ['auth_token' => session('authToken')])
        ->assertStatus(400)->assertHeaderMissing('Location');
});

it('rejects unsafe authorization inputs without redirecting', function (array $override) {
    $this->actingAs($this->mcpUser, 'admin')
        ->get('/oauth/mcp/authorize?'.http_build_query(array_merge($this->authorization, $override)))
        ->assertStatus(400)->assertHeaderMissing('Location');
})->with([
    'unregistered redirect' => [['redirect_uri' => 'https://attacker.example/callback']],
    'missing PKCE'          => [['code_challenge' => '']],
    'plain PKCE'            => [['code_challenge_method' => 'plain']],
    'wrong resource'        => [['resource' => 'https://other.example/mcp']],
    'extra scope'           => [['scope' => 'mcp:use *']],
    'invalid client id'     => [['client_id' => 'bad']],
]);

it('requires consent and binds a valid grant to its resource and account', function () {
    $issued = ($this->exchange)(($this->getCode)())->assertOk();
    $bearer = $issued->json('access_token');
    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('/_mcp-auth-test')->assertOk()
        ->assertJsonPath('id', $this->mcpUser->getKey())
        ->assertJsonPath('admin_id', $this->mcpUser->getKey())
        ->assertJsonPath('client_id', $this->mcpClient->getKey());
    auth()->forgetGuards();
    config(['mcp-oauth.issuer' => 'https://other.example']);
    $this->withToken($bearer)->getJson('/_mcp-auth-test')->assertUnauthorized();
});

it('rejects an incorrect PKCE verifier and wrong token resource', function () {
    $code = ($this->getCode)();
    ($this->exchange)($code, ['resource' => 'https://other.example/mcp'])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    ($this->exchange)($code, ['code_verifier' => str_repeat('b', 43)])->assertStatus(400);
});

it('rejects denied and replayed consent', function () {
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $authToken = session('authToken');
    $denied = $this->delete('/oauth/mcp/authorize', ['auth_token' => $authToken])->assertRedirect();
    parse_str(parse_url($denied->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['error' => 'access_denied', 'state' => 'test-state', 'iss' => 'https://space.example']);
    $this->post('/oauth/mcp/authorize', ['auth_token' => $authToken])->assertStatus(400);
});

it('rechecks active human status at consent and token use', function () {
    $code = ($this->getCode)();
    $issued = ($this->exchange)($code)->assertOk();
    $this->mcpUser->update(['status' => 0]);
    auth()->forgetGuards();
    $this->withToken($issued->json('access_token'))->getJson('/_mcp-auth-test')->assertUnauthorized();
});

it('rejects revoked tokens and clients', function (string $revoke) {
    $issued = ($this->exchange)(($this->getCode)())->assertOk();
    if ($revoke === 'client') {
        $this->mcpClient->update(['revoked' => true]);
    } else {
        Passport::token()->newQuery()->where('client_id', $this->mcpClient->getKey())->update(['revoked' => true]);
    }
    auth()->forgetGuards();
    $this->withToken($issued->json('access_token'))->getJson('/_mcp-auth-test')->assertUnauthorized();
})->with(['client', 'token']);

it('rejects browser sessions and REST tokens at the MCP boundary', function () {
    $this->actingAs($this->mcpUser, 'admin')->getJson('/_mcp-auth-test')->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
    $restClient = app(ClientRepository::class)->createPasswordGrantClient(name: 'REST test', provider: 'admins');
    $scopeRepository = app(ScopeRepository::class);
    $bridgeClient = app(Laravel\Passport\Bridge\ClientRepository::class)->getClientEntity($restClient->getKey());
    expect(fn () => $scopeRepository->finalizeScopes([new Scope('mcp:use')], 'password', $bridgeClient, (string) $this->mcpUser->getKey()))
        ->toThrow(OAuthServerException::class);
    // Existing password-grant scopes remain untouched; the password grant remains enabled.
    expect($scopeRepository->finalizeScopes([], 'password', $bridgeClient, (string) $this->mcpUser->getKey()))->toBe([])
        ->and(Passport::$passwordGrantEnabled)->toBeTrue();

    $entity = new AccessToken((string) $this->mcpUser->getKey(), [], $bridgeClient);
    $entity->setIdentifier(bin2hex(random_bytes(20)));
    $entity->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    $entity->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));
    app(AccessTokenRepository::class)->persistNewAccessToken($entity);
    auth()->forgetGuards();
    $this->withToken($entity->toString())->getJson('/_mcp-auth-test')->assertUnauthorized();
});

it('rotates refresh grants and rechecks the resource and active account', function () {
    $issued = ($this->exchange)(($this->getCode)())->assertOk();
    $refresh = [
        'grant_type'    => 'refresh_token',
        'client_id'     => $this->mcpClient->getKey(),
        'refresh_token' => $issued->json('refresh_token'),
        'resource'      => 'https://space.example/mcp',
    ];
    $this->postJson('/oauth/token', array_merge($refresh, ['resource' => 'https://other.example/mcp']))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    $rotated = $this->postJson('/oauth/token', $refresh)->assertOk();
    $this->postJson('/oauth/token', $refresh)->assertStatus(400);
    $this->mcpUser->update(['status' => 0]);
    $this->postJson('/oauth/token', array_merge($refresh, ['refresh_token' => $rotated->json('refresh_token')]))->assertStatus(400);
});

it('records the grant type of each MCP token exchange for its account without codes or tokens', function () {
    config(['dam_events.enabled' => true, 'mcp-oauth.onboard_client_ids' => ['claude' => $this->mcpClient->getKey(), 'chatgpt' => null]]);
    $exchanges = [];
    Event::listen(McpTokenExchanged::class, function (McpTokenExchanged $exchange) use (&$exchanges): void {
        $exchanges[] = $exchange;
    });
    $code = ($this->getCode)();
    // A different signed-in admin on the token request must not take the issued or refused rows.
    auth()->forgetGuards();
    $bystander = Admin::factory()->create();
    $this->actingAs($bystander, 'admin');
    $issued = ($this->exchange)($code)->assertOk();
    $refresh = [
        'grant_type'    => 'refresh_token', 'client_id' => $this->mcpClient->getKey(),
        'refresh_token' => $issued->json('refresh_token'), 'resource' => 'https://space.example/mcp',
    ];
    $this->postJson('/oauth/token', $refresh)->assertOk();
    $this->postJson('/oauth/token', $refresh)->assertStatus(400);

    expect(collect($exchanges)->map(fn ($e) => [$e->grantType, $e->provider, $e->status, $e->userId, $e->error])->all())->toBe([
        ['authorization_code', 'claude', 200, $this->mcpUser->getKey(), null],
        ['refresh_token', 'claude', 200, $this->mcpUser->getKey(), null],
        ['refresh_token', 'claude', 400, null, 'invalid_grant'],
    ]);
    $rows = DB::table('dam_events')->whereIn('name', ['craft.oauth.token.issued', 'craft.oauth.token.failed'])->orderBy('id')
        ->get(['name', 'outcome', 'actor_type', 'actor_key', 'surface', 'props']);
    expect($rows->pluck('name')->all())->toBe(['craft.oauth.token.issued', 'craft.oauth.token.issued', 'craft.oauth.token.failed'])
        ->and($rows->pluck('outcome')->all())->toBe(['success', 'success', 'failure'])
        ->and($rows->pluck('actor_key')->take(2)->map(fn ($key) => (string) $key)->all())->toBe(array_fill(0, 2, (string) $this->mcpUser->getKey()))
        ->and($rows[2]->actor_type)->toBe('system')
        ->and(json_decode($rows[1]->props, true))->toEqual(['grant_type' => 'refresh_token', 'provider' => 'claude']) // MySQL JSON reorders keys
        ->and(json_decode($rows[2]->props, true))->toMatchArray(['grant_type' => 'refresh_token', 'status' => 400, 'error_code' => 'invalid_grant'])
        ->and($rows->pluck('props')->implode(' '))->not->toContain($issued->json('refresh_token'));
});

it('returns issued MCP credentials when analytics actor lookup fails', function () {
    config(['dam_events.enabled' => true]);
    $code = ($this->getCode)();
    // The opt-out lookup inside actor resolution runs after Passport has issued; make it throw.
    // Consent already memoized this admin's opt-out, so start the exchange with fresh resolvers.
    $this->app->forgetInstance(OptOuts::class);
    $this->app->forgetInstance(ActorResolver::class);
    $this->app->forgetInstance(DamEvents::class);
    // Thrown from a query listener, not by renaming the table: a failed statement aborts the test's transaction on
    // PostgreSQL (SQLSTATE 25P02), and a RENAME TABLE commits it on MySQL.
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, OptOuts::TABLE)) {
            throw new RuntimeException('analytics opt-out lookup unavailable');
        }
    });

    $issued = ($this->exchange)($code)->assertOk();
    expect($issued->json('refresh_token'))->toBeString()
        ->and(DB::table('dam_events')->where('name', 'craft.oauth.token.issued')->count())->toBe(0);
});

it('issues and rotates configured thirty day refresh credentials after a re-signed expired access fixture', function (string $grant) {
    $previousAccess = Passport::tokensExpireIn();
    $previousRefresh = Passport::refreshTokensExpireIn();
    config(['api.access_token_ttl' => 3600, 'api.refresh_token_ttl' => 2592000]);
    $provider = new class($this->app) extends AdminApiServiceProvider
    {
        public function configurePassport(): void
        {
            $this->activatePassportApiClient();
        }
    };
    $provider->configurePassport();
    $this->app->forgetInstance(AuthorizationServer::class);
    Route::get('/_rest-refresh-auth-test', fn () => response()->json(['id' => auth('api')->id()]))->middleware('auth:api');

    try {
        $client = $this->mcpClient;
        if ($grant === 'password') {
            $this->mcpUser->update(['password' => bcrypt('fixture')]);
            $client = app(ClientRepository::class)->createPasswordGrantClient(name: 'REST lifetime test', provider: 'admins');
            $client->forceFill(['user_id' => $this->mcpUser->id, 'owner_type' => Admin::class, 'owner_id' => $this->mcpUser->id])->save();
            $issued = $this->postJson('/oauth/token', [
                'grant_type' => 'password', 'client_id' => $client->getKey(), 'client_secret' => $client->plainSecret,
                'username'   => $this->mcpUser->email, 'password' => 'fixture', 'scope' => '',
            ])->assertOk();
        } else {
            $issued = ($this->exchange)(($this->getCode)())->assertOk();
        }
        expect($issued->json('expires_in'))->toBeGreaterThanOrEqual(3590)->toBeLessThanOrEqual(3600);
        $stored = Passport::token()->newQuery()->where('client_id', $client->getKey())->sole();
        $refreshRow = Passport::refreshToken()->newQuery()->where('access_token_id', $stored->getKey())->sole();
        expect($refreshRow->expires_at->getTimestamp() - time())
            ->toBeGreaterThanOrEqual(2591990)->toBeLessThanOrEqual(2592000);

        // Sign an expired representation of this same persisted grant. League uses native time,
        // so Carbon travel would not prove rejection at the real bearer validation boundary.
        $bridge = app(Laravel\Passport\Bridge\ClientRepository::class)->getClientEntity($client->getKey());
        $expired = $grant === 'password'
            ? new AccessToken((string) $this->mcpUser->getKey(), [], $bridge)
            : new McpAccessToken((string) $this->mcpUser->getKey(), [new Scope('mcp:use')], $bridge);
        $expired->setIdentifier($stored->getKey());
        $expired->setExpiryDateTime(new DateTimeImmutable('-1 minute'));
        $expired->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));
        $stored->update(['expires_at' => new DateTimeImmutable('-1 minute')]);
        auth()->forgetGuards();
        $this->withToken($expired->toString())->getJson($grant === 'password' ? '/_rest-refresh-auth-test' : '/_mcp-auth-test')->assertUnauthorized();

        $refresh = [
            'grant_type'    => 'refresh_token', 'client_id' => $client->getKey(),
            'refresh_token' => $issued->json('refresh_token'), 'resource' => 'https://space.example/mcp',
        ];
        if ($grant === 'password') {
            unset($refresh['resource']);
            $refresh['client_secret'] = $client->plainSecret;
        } else {
            $this->postJson('/oauth/token', array_merge($refresh, ['resource' => 'https://other.example/mcp']))
                ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        }
        $rotated = $this->postJson('/oauth/token', $refresh)->assertOk();
        expect($rotated->json('expires_in'))->toBeGreaterThanOrEqual(3590)->toBeLessThanOrEqual(3600);
        $newAccess = Passport::token()->newQuery()->where('client_id', $client->getKey())->where('revoked', false)->sole();
        $newRefresh = Passport::refreshToken()->newQuery()->where('access_token_id', $newAccess->getKey())->sole();
        expect($newRefresh->expires_at->getTimestamp() - time())->toBeGreaterThanOrEqual(2591990)->toBeLessThanOrEqual(2592000);
        expect((string) $newAccess->user_id)->toBe((string) $this->mcpUser->getKey());
        auth()->forgetGuards();
        $probe = $this->withToken($rotated->json('access_token'))->getJson('/_mcp-auth-test');
        if ($grant === 'password') {
            $probe->assertUnauthorized();
            auth()->forgetGuards();
            $this->withToken($rotated->json('access_token'))->getJson('/_rest-refresh-auth-test')
                ->assertOk()->assertJsonPath('id', $this->mcpUser->getKey());
        } else {
            $probe->assertOk()->assertJsonPath('id', $this->mcpUser->getKey())->assertJsonPath('client_id', $client->getKey());
        }
        expect($stored->fresh()->revoked)->toBeTrue()->and($refreshRow->fresh()->revoked)->toBeTrue();
        $this->postJson('/oauth/token', $refresh)->assertStatus(400);
    } finally {
        Passport::tokensExpireIn($previousAccess);
        Passport::refreshTokensExpireIn($previousRefresh);
    }
})->with(['authorization_code', 'password']);

it('rejects expired signed tokens', function () {
    $bridge = app(Laravel\Passport\Bridge\ClientRepository::class)->getClientEntity($this->mcpClient->getKey());
    $entity = new McpAccessToken((string) $this->mcpUser->getKey(), [new Scope('mcp:use')], $bridge);
    $entity->setIdentifier(bin2hex(random_bytes(20)));
    $entity->setExpiryDateTime(new DateTimeImmutable('-1 minute'));
    $entity->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));
    app(AccessTokenRepository::class)->persistNewAccessToken($entity);
    $this->withToken($entity->toString())->getJson('/_mcp-auth-test')->assertUnauthorized();
});

it('refuses robot or disabled users and changed consent sessions', function () {
    $this->mcpUser->update(['type' => 'api']);
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertForbidden();
    $this->mcpUser->update(['type' => 'user', 'status' => 1]);
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $authToken = session('authToken');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->post('/oauth/mcp/authorize', ['auth_token' => $authToken])->assertStatus(400);
});

it('gives hosted MCP grants separate budgets while bounding abuse from the same source', function () {
    // More than both legacy 10/min OAuth and 60/min generic IP budgets, from the same IP.
    for ($i = 0; $i < 61; $i++) {
        ($this->exchange)('invalid-'.$i)->assertStatus(400);
    }
    config(['mcp-oauth.grant_rate_limit' => 2]);
    ($this->exchange)('repeat')->assertStatus(400);
    ($this->exchange)('repeat')->assertStatus(400);
    ($this->exchange)('repeat')->assertTooManyRequests();
    ($this->exchange)('independent')->assertStatus(400);

    // Grant variation never bypasses this source IP's client ceiling.
    config(['mcp-oauth.client_ip_rate_limit' => 1]);
    ($this->exchange)('different-again')->assertTooManyRequests();
    $other = app(ClientRepository::class)->createAuthorizationCodeGrantClient(name: 'Independent client', redirectUris: ['https://client.example/callback'], confidential: false);
    config(['mcp-oauth.clients' => [$this->mcpClient->getKey(), $other->getKey()]]);
    ($this->exchange)('independent-client', ['client_id' => $other->getKey()])->assertStatus(400);
});

it('keeps invalid grants from one IP from blocking a valid refresh or consent at another IP', function () {
    $issued = ($this->exchange)(($this->getCode)())->assertOk();
    config(['mcp-oauth.client_ip_rate_limit' => 20]);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/oauth/token', [
            'grant_type'    => 'refresh_token', 'client_id' => $this->mcpClient->getKey(),
            'refresh_token' => 'invalid-'.$i, 'resource' => 'https://space.example/mcp',
        ])->assertStatus(400);
    }
    $this->postJson('/oauth/token', [
        'grant_type'    => 'refresh_token', 'client_id' => $this->mcpClient->getKey(),
        'refresh_token' => 'another-invalid', 'resource' => 'https://space.example/mcp',
    ])->assertTooManyRequests();
    auth()->forgetGuards();
    Route::getRoutes()->getByName('mcp.oauth.authorize')->flushController();
    for ($i = 0; $i < 10; $i++) {
        $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertRedirect();
    }
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20']);
    $this->postJson('/oauth/token', [
        'grant_type'    => 'refresh_token', 'client_id' => $this->mcpClient->getKey(),
        'refresh_token' => $issued->json('refresh_token'), 'resource' => 'https://space.example/mcp',
    ])->assertOk();
    Route::getRoutes()->getByName('mcp.oauth.authorize')->flushController();
    $this->actingAs($this->mcpUser, 'admin')->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
});

it('isolates browser authorization budgets by active user and client', function () {
    $this->actingAs($this->mcpUser, 'admin');
    for ($i = 0; $i < 10; $i++) {
        $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    }
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertTooManyRequests();
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->get('/oauth/mcp/authorize?'.http_build_query($this->authorization))->assertOk();
    $other = app(ClientRepository::class)->createAuthorizationCodeGrantClient(name: 'Other browser client', redirectUris: ['https://client.example/callback'], confidential: false);
    config(['mcp-oauth.clients' => [$this->mcpClient->getKey(), $other->getKey()]]);
    $this->actingAs($this->mcpUser, 'admin');
    $this->get('/oauth/mcp/authorize?'.http_build_query(array_merge($this->authorization, ['client_id' => $other->getKey()])))->assertOk();
});

it('keeps the REST password quota and does not let an MCP client id bypass it', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/oauth/token', ['grant_type' => 'password', 'client_id' => 'missing', 'username' => 'rest-user'])
            ->assertUnauthorized()->assertJsonMissingPath('iss')->assertHeaderMissing('Location');
    }
    $this->postJson('/oauth/token', ['grant_type' => 'password', 'client_id' => $this->mcpClient->getKey(), 'username' => 'rest-user'])
        ->assertTooManyRequests();
    $this->postJson('/oauth/token', ['grant_type' => 'password', 'client_id' => 'missing', 'username' => 'different-user'])->assertUnauthorized();
    ($this->exchange)('mcp-after-rest')->assertStatus(400);
});

it('hashes refresh and browser discriminators and applies the limiter after route resolution', function () {
    $request = Request::create('/oauth/token', 'POST', [
        'client_id' => $this->mcpClient->getKey(), 'grant_type' => 'refresh_token', 'refresh_token' => 'probe',
    ]);
    $limits = RateLimiter::limiter('mcp-oauth-token')($request);
    expect($limits)->toHaveCount(2);
    foreach ($limits as $limit) {
        expect($limit->key)->not->toContain('probe')->not->toContain($this->mcpClient->getKey());
    }
    $route = Route::getRoutes()->getByName('passport.token');
    $resolved = app('router')->gatherRouteMiddleware($route);
    expect($resolved)->toContain(McpTokenThrottle::class)
        ->not->toContain(ThrottleRequests::class)
        ->not->toContain(ThrottleRequests::class.':oauth-token');
});

it('normalizes cached token middleware before validation without stacking legacy quotas', function () {
    $route = Route::getRoutes()->getByName('passport.token');
    $action = $route->getAction();
    $action['middleware'] = [McpTokenRequest::class, 'throttle', 'throttle:oauth-token'];
    $route->setAction($action);
    $route->computedMiddleware = null;
    app('router')->gatherRouteMiddleware($route);
    $this->app->instance('routes.cached', true);
    (new McpOAuthServiceProvider($this->app))->boot();
    (new McpOAuthServiceProvider($this->app))->boot();
    config(['mcp-oauth.grant_rate_limit' => 2]);
    ($this->exchange)('cached', ['resource' => 'wrong'])->assertStatus(400);
    ($this->exchange)('cached', ['resource' => 'wrong'])->assertStatus(400);
    ($this->exchange)('cached', ['resource' => 'wrong'])->assertTooManyRequests();
});

it('accepts HTTPS and fixed-port loopback client callbacks but keeps the issuer HTTPS-only', function (string $uri, bool $valid) {
    expect(app(McpOAuth::class)->validRedirect($uri))->toBe($valid);
})->with([
    'https'               => ['https://client.example/callback', true],
    'localhost port'      => ['http://localhost:47863/callback', true],
    'ipv4 loopback'       => ['http://127.0.0.1:47863/callback', true],
    'ipv6 loopback'       => ['http://[::1]:47863/callback', true],
    'uppercase localhost' => ['http://LOCALHOST:47863/callback', true],
    'loopback no port'    => ['http://localhost/callback', false],
    'loopback port zero'  => ['http://localhost:0/callback', false],
    'loopback port range' => ['http://localhost:65536/callback', false],
    'http public host'    => ['http://client.example:8080/callback', false],
    'loopback lookalike'  => ['http://localhost.example:47863/callback', false],
    'loopback trailing .' => ['http://localhost.:47863/callback', false],
    'percent-encoded'     => ['http://%6Cocalhost:47863/callback', false],
    'other ipv4 loopback' => ['http://127.0.0.2:47863/callback', false],
    'ipv4-mapped ipv6'    => ['http://[::ffff:127.0.0.1]:47863/callback', false],
    'userinfo host swap'  => ['http://localhost:47863@client.example/callback', false],
    'backslash host swap' => ['http://localhost:47863\\@client.example/callback', false],
    'loopback fragment'   => ['http://localhost:47863/callback#x', false],
    'loopback credential' => ['http://user:pw@localhost:47863/callback', false],
    'custom scheme'       => ['claude://localhost:47863/callback', false],
]);

it('refuses a client whose stored callbacks mix a valid and an invalid URI', function () {
    $policy = app(McpOAuth::class);
    $this->mcpClient->update(['redirect_uris' => ['https://client.example/callback', 'http://localhost:47863/callback']]);
    expect($policy->acceptsClient($this->mcpClient->fresh()))->toBeTrue();
    $this->mcpClient->update(['redirect_uris' => ['https://client.example/callback', 'http://client.example:8080/callback']]);
    expect($policy->acceptsClient($this->mcpClient->fresh()))->toBeFalse();
});

it('exact-matches the loopback port at authorization and token exchange', function () {
    $callback = 'http://localhost:47863/callback';
    $this->mcpClient->update(['redirect_uris' => [$callback]]);
    $this->actingAs($this->mcpUser, 'admin')
        ->get('/oauth/mcp/authorize?'.http_build_query(['redirect_uri' => 'http://localhost:47864/callback'] + $this->authorization))
        ->assertStatus(400)->assertHeaderMissing('Location');
    $this->authorization['redirect_uri'] = $callback;
    $code = ($this->getCode)();
    ($this->exchange)($code, ['redirect_uri' => 'http://localhost:47864/callback'])->assertStatus(400);
});

it('refuses a plain-HTTP issuer even on loopback', function () {
    config(['mcp-oauth.issuer' => 'http://localhost:8000']);
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
});

it('completes authorization and token exchange for a native loopback client', function () {
    $callback = 'http://localhost:47863/callback';
    $this->mcpClient->update(['redirect_uris' => [$callback]]);
    $this->authorization['redirect_uri'] = $callback;
    $code = ($this->getCode)();
    $token = ($this->exchange)($code, ['redirect_uri' => $callback])->assertOk()->json('access_token');
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/_mcp-auth-test')->assertOk()
        ->assertJsonPath('id', $this->mcpUser->getKey());
});

it('advertises an allowlisted public origin as its own issuer and resource, and falls back for any other Host', function () {
    config(['mcp-oauth.public_origins' => ['https://alias.example', 'http://plain.example', 'https://pathed.example/sub']]);
    $wellKnown = fn (string $origin) => [
        $this->getJson($origin.'/.well-known/oauth-protected-resource/mcp')->assertOk(),
        $this->getJson($origin.'/.well-known/oauth-authorization-server')->assertOk(),
    ];

    [$resource, $server] = $wellKnown('https://alias.example');
    $resource->assertJsonPath('resource', 'https://alias.example/mcp')
        ->assertJsonPath('authorization_servers', ['https://alias.example']);
    $server->assertJsonPath('issuer', 'https://alias.example')
        ->assertJsonPath('authorization_endpoint', 'https://alias.example/oauth/mcp/authorize')
        ->assertJsonPath('token_endpoint', 'https://alias.example/oauth/token');
    expect($this->getJson('https://alias.example/_mcp-auth-test')->assertUnauthorized()->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata="https://alias.example/.well-known/oauth-protected-resource/mcp"');

    // An unlisted Host, and entries that are not bare HTTPS origins, never become the issuer.
    foreach (['https://evil.example', 'http://plain.example', 'https://pathed.example'] as $origin) {
        [$resource, $server] = $wellKnown($origin);
        $resource->assertJsonPath('resource', 'https://space.example/mcp');
        $server->assertJsonPath('issuer', 'https://space.example');
        expect($this->getJson($origin.'/_mcp-auth-test')->headers->get('WWW-Authenticate'))
            ->toContain('resource_metadata="https://space.example/.well-known/');
    }
    expect(app(McpOAuth::class)->publicOrigins())->toBe(['https://space.example', 'https://alias.example']);
});

it('keeps APP_URL as the only issuer when no public origins are configured', function () {
    expect(config('mcp-oauth.public_origins'))->toBe([]);
    $this->getJson('https://alias.example/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('issuer', 'https://space.example');
    $this->get('https://alias.example/oauth/mcp/authorize?'.http_build_query(array_merge($this->authorization, ['resource' => 'https://alias.example/mcp'])))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    // The canonical flow is unchanged.
    $bearer = ($this->exchange)(($this->getCode)())->assertOk()->json('access_token');
    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('/_mcp-auth-test')->assertOk();
});

it('completes a flow on an allowlisted origin with that origin as iss and audience, bound to that origin', function () {
    config(['mcp-oauth.public_origins' => ['https://alias.example']]);
    $alias = array_merge($this->authorization, ['resource' => 'https://alias.example/mcp']);

    // The canonical resource is not this host's resource.
    $this->actingAs($this->mcpUser, 'admin')->get('https://alias.example/oauth/mcp/authorize?'.http_build_query($this->authorization))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    $this->get('https://alias.example/oauth/mcp/authorize?'.http_build_query($alias))->assertOk()->assertSee('Allow connection');
    $approval = $this->post('https://alias.example/oauth/mcp/authorize', ['auth_token' => session('authToken')])->assertRedirect();
    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['iss'])->toBe('https://alias.example');

    $exchange = [
        'grant_type' => 'authorization_code', 'client_id' => $this->mcpClient->getKey(), 'redirect_uri' => 'https://client.example/callback',
        'code'       => $query['code'], 'code_verifier' => $this->verifier, 'resource' => 'https://alias.example/mcp',
    ];
    $this->postJson('https://alias.example/oauth/token', array_merge($exchange, ['resource' => 'https://space.example/mcp']))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    $issued = $this->postJson('https://alias.example/oauth/token', $exchange)->assertOk();
    $bearer = $issued->json('access_token');
    $claims = json_decode(base64_decode(strtr(explode('.', $bearer)[1], '-_', '+/')), true);
    expect($claims['aud'])->toBe([$this->mcpClient->getKey(), 'https://alias.example/mcp']);

    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('https://alias.example/_mcp-auth-test')->assertOk()
        ->assertJsonPath('id', $this->mcpUser->getKey());
    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('https://space.example/_mcp-auth-test')->assertUnauthorized();

    // Refresh at the same origin keeps that audience.
    $refreshed = $this->postJson('https://alias.example/oauth/token', [
        'grant_type'    => 'refresh_token', 'client_id' => $this->mcpClient->getKey(),
        'refresh_token' => $issued->json('refresh_token'), 'resource' => 'https://alias.example/mcp',
    ])->assertOk();
    auth()->forgetGuards();
    $this->withToken($refreshed->json('access_token'))->getJson('https://alias.example/_mcp-auth-test')->assertOk();
});

it('keeps canonical-origin tokens working on the canonical origin when public origins are added', function () {
    config(['mcp-oauth.public_origins' => ['https://alias.example']]);
    $bearer = ($this->exchange)(($this->getCode)())->assertOk()->json('access_token');
    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('/_mcp-auth-test')->assertOk();
    auth()->forgetGuards();
    $this->withToken($bearer)->getJson('https://alias.example/_mcp-auth-test')->assertUnauthorized();
});
