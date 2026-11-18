<?php

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\CollectorConfig;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\Ingest;
use Mothership\DAM\Analytics\IngestToken;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\DAM\Analytics\PosthogOutbox;
use Mothership\DAM\Analytics\ReplaySession;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;

beforeEach(function () {
    config(['dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'srv',
        'dam_events.replay.enabled'      => true, 'dam_events.replay.public_key' => 'pub']);
    $this->loginAsAdmin();
    $this->withHeader('User-Agent', 'Mozilla/5.0');
    request()->headers->set('User-Agent', 'Mozilla/5.0');
});

it('publishes only eligible admin replay config with the exact exporter pseudonym', function () {
    $this->get(route('admin.dam.analytics.privacy'))->assertOk();
    $admin = auth('admin')->user();
    $config = app(CollectorConfig::class)->forAdmin(admin: $admin, request: request());
    expect($config['replay']['public_key'])->toBe('pub')
        ->and(json_encode($config['replay']))->not->toContain('srv', $admin->email, $admin->name);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $payload = json_decode(DB::table(PosthogOutbox::TABLE)->value('payload'), true);
    expect($config['replay']['distinct_id'])->toBe($payload['properties']['distinct_id']);
    app(OptOuts::class)->set(adminId: (int) $admin->id, optedOut: true);
    expect(app(CollectorConfig::class)->forAdmin(admin: $admin, request: request()))->toBeNull();
});

it('relays actual SDK bytes only to fixed paths with bounded forwarding and no client credentials', function () {
    Http::fake(['https://us.i.posthog.com/*' => Http::response(['status' => 1])]);
    $body = gzencode('bounded recording fixture');
    $this->call('POST', route('admin.dam.analytics.replay', ['path' => 's']), server: [
        'CONTENT_TYPE'       => 'text/plain;charset=UTF-8', 'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'HTTP_AUTHORIZATION' => 'Bearer fixture', 'HTTP_COOKIE' => 'private=fixture',
    ], content: $body)->assertOk();
    Http::assertSent(fn ($request) => $request->url() === 'https://us.i.posthog.com/s/'
        && $request->body() === $body && $request->header('Content-Encoding') === ['gzip']
        && ! $request->hasHeader('Authorization') && ! $request->hasHeader('Cookie') && ! $request->hasHeader('X-CSRF-TOKEN'));
    foreach (['capture', 'static/array.js', 'https://other.example/s'] as $path) {
        $this->post(route('admin.dam.analytics.replay', ['path' => $path]))->assertNotFound();
    }
    Http::assertSentCount(1);
});

it('never saves the session from a beacon, so a concurrent request keeps what it stored', function () {
    // A consent page stores Passport's pending request while a replay beacon from another tab waits on PostHog.
    $sessionId = null;
    Http::fake(function () use (&$sessionId) {
        $sessionId = session()->getId();
        $concurrent = clone app('session')->driver();
        $concurrent->put('authRequest', 'pending-consent');
        $concurrent->save();

        return Http::response(['status' => 1]);
    });
    $response = $this->call('POST', route('admin.dam.analytics.replay', ['path' => 's']), server: [
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ], content: gzencode('bounded recording fixture'))->assertOk();

    $approval = clone app('session')->driver();
    $approval->setId($sessionId);
    $approval->flush();
    $approval->start();
    // The beacon's own copy is the stale one; the approval still finds the pending request.
    expect($sessionId)->not->toBeNull()
        ->and(session()->has('authRequest'))->toBeFalse()
        ->and($approval->get('authRequest'))->toBe('pending-consent');
    $response->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
});

it('saves nothing from a beacon rejected or throttled before it is handled, but saves a replaced session', function () {
    $handler = app('session')->driver()->getHandler();
    $saved = fn () => $handler->read(session()->getId()) !== '';
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    $this->get(route('admin.dam.analytics.privacy'))->assertOk();
    expect($saved())->toBeTrue();

    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $this->postJson($url, [])->assertStatus(419)->assertCookieMissing('XSRF-TOKEN');
    expect($saved())->toBeFalse();
    app()->offsetUnset(PreventRequestForgery::class);

    // Signing in again from a remember-me cookie replaces the session; the beacon must keep the replacement.
    $signIn = true;
    Http::fake(function () use (&$signIn) {
        if ($signIn) {
            $signIn = false;
            session()->migrate(true);
        }

        return Http::response('{}');
    });
    $response = $this->postJson($url, [])->assertOk();
    expect($saved())->toBeTrue();
    $response->assertCookie(config('session.cookie'), session()->getId());

    for ($attempt = 1; $attempt < 120; $attempt++) {
        $this->postJson($url, [])->assertOk();
        expect($saved())->toBeFalse();
    }
    $this->postJson($url, [])->assertTooManyRequests();
    expect($saved())->toBeFalse();
});

it('saves a sign-out from a beacon that keeps its session id', function () {
    $signedIn = clone app('session')->driver();
    $signedIn->setId(Str::random(40));
    $signedIn->flush();
    $signedIn->put(auth('admin')->getName(), auth('admin')->id());
    $signedIn->save();
    Http::fake(function () {
        auth('admin')->logout();

        return Http::response('{}');
    });

    $this->withCredentials()->withCookie(config('session.cookie'), $signedIn->getId())
        ->postJson(route('admin.dam.analytics.replay', ['path' => 's']), [])->assertOk();

    $signedIn->flush();
    $signedIn->start();
    expect(session()->getId())->toBe($signedIn->getId())
        ->and($signedIn->has(auth('admin')->getName()))->toBeFalse();
});

it('keeps the replay throttle independent of Home actions while retaining its own limit', function () {
    Http::fake(['*' => Http::response('{}')]);
    $this->mock(Vectorizer::class)->shouldReceive('ready')->andReturnTrue();
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    for ($attempt = 0; $attempt < 11; $attempt++) {
        $this->postJson($url, [])->assertOk();
    }
    // This authenticated action must reach its own validation, not inherit replay's attempts.
    $this->postJson(route('admin.home.vectorization.store'), [])->assertUnprocessable();
    for ($attempt = 11; $attempt < 120; $attempt++) {
        $this->postJson($url, [])->assertOk();
    }
    $this->postJson($url, [])->assertTooManyRequests();
    Http::assertSentCount(120);
});

it('denies unauthenticated opted-out privacy-signal and disabled replay before any upstream call', function () {
    Http::fake();
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    foreach (['DNT', 'Sec-GPC'] as $header) {
        $this->withHeader($header, '1')->postJson($url, [])->assertForbidden()->assertHeader('X-Dam-Replay-Enabled', '0');
        $this->flushHeaders()->withHeader('User-Agent', 'Mozilla/5.0');
    }
    $admin = auth('admin')->user();
    app(OptOuts::class)->set(adminId: (int) $admin->id, optedOut: true);
    $this->postJson($url, [])->assertForbidden();
    app(OptOuts::class)->set(adminId: (int) $admin->id, optedOut: false);
    config(['dam_events.replay.enabled' => false]);
    $this->postJson($url, [])->assertForbidden();
    auth('admin')->logout();
    $this->postJson($url, [])->assertUnauthorized();
    Http::assertNothingSent();
});

it('allows only configured-token remote settings and strips alternate endpoints and capture defaults', function () {
    Http::fake(['*' => Http::response(['sessionRecording' => ['endpoint' => 'https://other.example/s/', 'sampleRate' => '1.00',
        'minimumDurationMilliseconds'                                    => 0, 'consoleLogRecordingEnabled' => true, 'recordCanvas' => true,
        'networkPayloadCapture'                                          => ['recordBody' => true]], 'siteApps' => [['url' => 'https://other.example/script.js']]])]);
    $this->getJson(route('admin.dam.analytics.replay', ['path' => 'array/pub/config']))->assertOk()
        ->assertExactJson(['sessionRecording' => ['endpoint' => '/s/', 'sampleRate' => 1, 'minimumDurationMilliseconds' => 0], 'hasFeatureFlags' => false]);
    $this->getJson(route('admin.dam.analytics.replay', ['path' => 'array/unknown/config']))->assertNotFound();
    $this->getJson(route('admin.dam.analytics.replay', ['path' => 's']))->assertNotFound();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://us-assets.i.posthog.com/array/pub/config');
    config(['dam_events.posthog.host' => 'https://eu.i.posthog.com']);
    $this->getJson(route('admin.dam.analytics.replay', ['path' => 'array/pub/config']))->assertOk();
    Http::assertSent(fn ($request) => $request->url() === 'https://eu-assets.i.posthog.com/array/pub/config');
});

it('keeps the actual route CSRF middleware enabled for JSON form and gzip SDK uploads', function () {
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    Http::fake(['*' => Http::response('{}')]);
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    $this->withSession(['_token' => 'csrf'])->postJson($url, [])->assertStatus(419);
    Http::assertNothingSent();
    $this->withHeader('X-CSRF-TOKEN', 'csrf')->postJson($url, [])->assertOk();
    $this->post($url, ['data' => '{}'])->assertOk();
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_X_CSRF_TOKEN'                           => 'csrf', 'HTTP_USER_AGENT' => 'Mozilla/5.0'], content: gzencode('{}'))->assertOk();
    $this->flushHeaders()->withHeader('User-Agent', 'Mozilla/5.0');
    $this->withHeader('X-XSRF-TOKEN', 'invalid')->postJson($url, [])->assertStatus(419);
    $encrypter = app('encrypter');
    $cookie = $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).'csrf', false);
    $this->withHeader('X-XSRF-TOKEN', $cookie)->postJson($url, [])->assertOk();
    Http::assertSentCount(4);
});

it('never borrows the request replay id for a different explicitly named actor on first use', function () {
    $replay = (string) Str::uuid7();
    request()->headers->set(ReplaySession::HEADER, $replay);
    $other = Admin::factory()->create(['status' => 1]);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0],
        actor: app(ActorResolver::class)->forAdminId(adminId: $other->id));
    expect(DB::table(EventStore::TABLE)->orderByDesc('id')->value('replay_session_id'))->toBeNull();
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 1]);
    expect(DB::table(EventStore::TABLE)->orderByDesc('id')->value('replay_session_id'))->toBe($replay);
});

it('rejects oversize payloads and never follows upstream redirects or returns upstream error content', function () {
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    Http::fake(['*' => Http::response('private upstream detail', 302, ['Location' => 'https://other.example'])]);
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0'], content: str_repeat('x', 2_000_001))->assertStatus(413);
    Http::assertNothingSent();
    $this->postJson($url, [])->assertStatus(503)->assertDontSee('private upstream detail');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://us.i.posthog.com/s/'));
});

it('rejects unsupported SDK encodings and hides upstream failures', function () {
    $url = route('admin.dam.analytics.replay', ['path' => 's']);
    $connectionFailure = false;
    $connectionCalls = 0;
    Http::fake(function () use (&$connectionFailure, &$connectionCalls) {
        if ($connectionFailure) {
            $connectionCalls++;
            throw new ConnectionException('private connection detail');
        }

        return Http::response('private failure detail', 500);
    });
    foreach ([['CONTENT_TYPE' => 'text/html'], ['CONTENT_TYPE' => 'text/plain', 'HTTP_CONTENT_ENCODING' => 'unknown']] as $server) {
        $this->call('POST', $url, server: $server + ['HTTP_USER_AGENT' => 'Mozilla/5.0'], content: 'fixture')->assertStatus(415);
    }
    Http::assertNothingSent();
    $this->postJson($url, [])->assertStatus(503)->assertDontSee('private failure detail');
    $connectionFailure = true;
    $this->postJson($url, [])->assertStatus(503)->assertDontSee('private connection detail');
    expect($connectionCalls)->toBe(1);
});

it('links client and server steps to a real-shaped SDK session once and ignores invalid or reused links', function () {
    $id = (string) Str::uuid7();
    $admin = auth('admin')->user();
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => [[
        'id'  => (string) Str::uuid(), 'n' => 'home.feature.activated', 'ts' => now()->getTimestampMs(),
        'sid' => (string) Str::uuid(), 'seq' => 1, 'p' => ['widget_id' => 'library', 'action' => 'open'], 'rs' => $id,
    ]]];
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(1)
        ->and(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(0);
    request()->headers->set(ReplaySession::HEADER, $id);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $payloads = DB::table(PosthogOutbox::TABLE)->pluck('payload')->map(fn ($value) => json_decode($value, true));
    expect($payloads->pluck('properties.$session_id')->unique()->all())->toBe([$id]);
    $other = Admin::factory()->create(['status' => 1]);
    $this->actingAs($other, 'admin');
    $batch['t'] = IngestToken::issue(scope: 'admin', boundTo: (int) $other->id);
    $batch['e'][0]['id'] = (string) Str::uuid();
    $batch['e'][0]['sid'] = (string) Str::uuid();
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $other)->stored)->toBe(1);
    expect(DB::table(EventStore::TABLE)->where('event_id', $batch['e'][0]['id'])->value('replay_session_id'))->toBeNull();
    app(OptOuts::class)->set(adminId: (int) $other->id, optedOut: true);
    $batch['e'][0]['id'] = (string) Str::uuid();
    $batch['e'][0]['rs'] = (string) Str::uuid7();
    app(Ingest::class)->handle(batch: $batch, request: request(), admin: $other);
    expect(DB::table(EventStore::TABLE)->where('event_id', $batch['e'][0]['id'])->exists())->toBeFalse();
    app(OptOuts::class)->set(adminId: (int) $other->id, optedOut: false);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 1]);
    expect(DB::table(EventStore::TABLE)->orderByDesc('id')->value('replay_session_id'))->toBeNull();
    request()->headers->set(ReplaySession::HEADER, 'invalid');
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 2]);
    expect(DB::table(EventStore::TABLE)->orderByDesc('id')->value('replay_session_id'))->toBeNull();
});

it('keeps replay linkage data during rollback and allows the additive migration to be applied again', function () {
    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_21_150000_add_replay_session_to_dam_events.php');
    $migration->down();
    expect(Schema::hasColumn(EventStore::TABLE, 'replay_session_id'))->toBeTrue();
    $migration->up();
    expect(Schema::hasColumn(EventStore::TABLE, 'replay_session_id'))->toBeTrue();
});
