<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventRegistry;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\Ingest;
use Mothership\DAM\Analytics\IngestToken;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\DAM\Analytics\PosthogExporter;
use Mothership\DAM\Analytics\PosthogOutbox;
use Mothership\DAM\Analytics\Retention;
use Mothership\DAM\Models\Asset;

beforeEach(function () {
    config(['dam_events.posthog.enabled' => true, 'dam_events.posthog.key' => 'test-capture-key']);
    $this->loginAsAdmin();
    request()->headers->set('User-Agent', 'Mozilla/5.0');
});

it('exports versioned vital revisions intact and retries no snapshot twice', function () {
    $admin = auth('admin')->user();
    $props = ['metric' => 'INP', 'value' => 200, 'route' => 'admin.dam.index',
        'metric_id'    => 'v6-1234567890-1234567890123', 'metric_revision' => 1,
        'measurement'  => 'web-vitals-6.2.3', 'navigation_type' => 'navigate'];
    $events = [];
    foreach ([1, 2] as $revision) {
        $events[] = ['id' => (string) Str::uuid(), 'n' => 'client.web_vitals.sampled',
            'ts'          => now()->getTimestampMs(), 'sid' => (string) Str::uuid(), 'seq' => $revision,
            'p'           => array_replace($props, ['metric_revision' => $revision, 'value' => $revision * 200])];
    }
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => $events];
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(2)
        ->and(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(0);
    $payloads = DB::table(PosthogOutbox::TABLE)->orderBy('id')->pluck('payload')
        ->map(fn ($payload) => json_decode($payload, true));
    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['properties'])->toMatchArray($props)
        ->and($payloads[1]['properties'])->toMatchArray(array_replace($props, ['metric_revision' => 2, 'value' => 400]))
        ->and($payloads[0]['uuid'])->not->toBe($payloads[1]['uuid']);
});

it('exports bounded error diagnostics with dotted chunks and refuses raw error text', function () {
    $admin = auth('admin')->user();
    $props = ['kind'  => 'rejection', 'route' => 'admin.dam.index', 'error_type' => 'TypeError',
        'source_kind' => 'bundle', 'bundle' => 'three.module-BtCAaDxU.js', 'line' => 42, 'column' => 7];
    $event = ['id' => (string) Str::uuid(), 'n' => 'client.error.raised', 'ts' => now()->getTimestampMs(),
        'sid'      => (string) Str::uuid(), 'seq' => 1, 'p' => $props];
    $untrusted = array_replace($event, ['id' => (string) Str::uuid(), 'seq' => 2,
        'p'                                  => $props + ['message' => 'private-error-title', 'stack' => 'private-stack']]);
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => [$event, $untrusted]];
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(1);
    $payloads = DB::table(PosthogOutbox::TABLE)->pluck('payload')->map(fn ($value) => json_decode($value, true));
    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['properties'])->toMatchArray($props)
        ->and($payloads[0]['properties'])->not->toHaveKeys(['message', 'stack', 'filename'])
        ->and(json_encode($payloads))->not->toContain('private-error-title', 'private-stack');
});

it('uses the exporters bounded lock without inheriting the schedulers day-long overlap mutex', function () {
    $this->artisan('schedule:list')->assertSuccessful();
    $schedule = app(Schedule::class);
    $event = collect($schedule->events())->first(fn ($entry) => str_contains($entry->command ?? '', 'dam:analytics:export'));
    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeFalse()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    Http::fake(['*/capture/' => Http::response(['status' => 1])]);
    $held = Cache::lock('dam.posthog.export', 2400);
    expect($held->get())->toBeTrue();
    expect(app(PosthogExporter::class)->drain()['sent'])->toBe(0);
    Http::assertNothingSent();
    $this->travel(2401)->seconds();
    expect(app(PosthogExporter::class)->drain()['sent'])->toBe(1);
    Http::assertSentCount(1);
    // An old owner cannot release a replacement owner's lock.
    expect($held->release())->toBeFalse();
});

it('reports delivery health without recursively creating telemetry and keeps backlog age across retries', function () {
    $this->travelTo(now()->startOfSecond());
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $this->travel(5)->minutes();
    DB::table(PosthogOutbox::TABLE)->update(['available_at' => now()->addHour()]);
    $health = app(PosthogExporter::class)->health();
    expect($health)->toBe(['pending' => 1, 'failed' => 0, 'oldest_pending_age_seconds' => 300, 'last_delivered_at' => null]);
    $this->artisan('dam:analytics:export --health')->assertSuccessful();
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(1);
    DB::table(PosthogOutbox::TABLE)->update(['state' => 'failed']);
    expect(app(PosthogExporter::class)->health())->toMatchArray(['pending' => 0, 'failed' => 1, 'oldest_pending_age_seconds' => null]);
});

it('exports only validated deployment release metadata', function () {
    config(['dam_events.posthog.release' => '410e222812bc0bfa8dfa715ca7799728c2c8f94f']);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $payload = json_decode(DB::table(PosthogOutbox::TABLE)->value('payload'), true);
    expect($payload['properties']['release'])->toBe(config('dam_events.posthog.release'));
    DB::table(PosthogOutbox::TABLE)->delete();
    config(['dam_events.posthog.release' => 'https://private.example/name']);
    DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 0]);
    $payload = json_decode(DB::table(PosthogOutbox::TABLE)->value('payload'), true);
    expect($payload['properties'])->not->toHaveKey('release');
});

it('names the release after the commit the image was built from, else after DAM_ANALYTICS_RELEASE', function () {
    $keys = ['MOTHERSHIP_BUILD_COMMIT', 'DAM_ANALYTICS_RELEASE'];
    $set = function (string $key, ?string $value) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($value === null ? $key : $key.'='.$value);
        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    };
    // PHP keeps an environment value in three places; each is put back exactly as it was found.
    $before = array_map(fn (string $key) => [
        'env'     => array_key_exists($key, $_ENV) ? [$_ENV[$key]] : [],
        'server'  => array_key_exists($key, $_SERVER) ? [$_SERVER[$key]] : [],
        'process' => getenv($key),
    ], array_combine($keys, $keys));
    $release = function (?string $build, ?string $configured) use ($set) {
        $set(key: 'MOTHERSHIP_BUILD_COMMIT', value: $build);
        $set(key: 'DAM_ANALYTICS_RELEASE', value: $configured);

        return (require base_path('packages/Mothership/DAM/src/Config/dam_events.php'))['posthog']['release'];
    };
    $commit = '03b70f90ad9a2c2023e05947327626a3b9cddcc7';

    try {
        // A built image names its own commit, whatever DAM_ANALYTICS_RELEASE still says.
        expect($release(build: $commit, configured: '1e6b27fe45'))->toBe($commit)
            ->and($release(build: $commit, configured: null))->toBe($commit)
            // No build commit, or one that is not a full commit: the configured release, or none.
            ->and($release(build: 'unknown', configured: '1e6b27fe45'))->toBe('1e6b27fe45')
            ->and($release(build: null, configured: 'release-12'))->toBe('release-12')
            ->and($release(build: 'feature/branch', configured: null))->toBeNull();
    } finally {
        foreach ($before as $key => $was) {
            $set(key: $key, value: null);
            if ($was['env']) {
                $_ENV[$key] = $was['env'][0];
            }
            if ($was['server']) {
                $_SERVER[$key] = $was['server'][0];
            }
            if ($was['process'] !== false) {
                putenv($key.'='.$was['process']);
            }
        }
    }
});

it('marks only configured internal admins and exports device context', function () {
    $admin = auth('admin')->user();
    $event = fn (int $seq) => ['id' => (string) Str::uuid(), 'n' => 'route.viewed', 'ts' => now()->getTimestampMs(),
        'sid'                       => (string) Str::uuid(), 'seq' => $seq, 'p' => ['route' => 'admin.home.index', 'navigation' => 'document'],
        'x'                         => ['dc' => 'tablet', 'vw' => '1024-1439']];
    $send = fn (int $seq) => app(Ingest::class)->handle(batch: ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id),
        'e'                                                         => [$event($seq)]], request: request(), admin: $admin);

    config(['dam_events.internal_admin_ids' => [(int) $admin->id + 1]]);
    $send(1);
    config(['dam_events.internal_admin_ids' => [(int) $admin->id]]);
    $send(2);

    $payloads = DB::table(PosthogOutbox::TABLE)->orderBy('id')->pluck('payload')->map(fn ($value) => json_decode($value, true));
    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['event'])->toBe('route.viewed')
        ->and($payloads[0]['properties'])->toMatchArray(['route' => 'admin.home.index', 'navigation' => 'document',
            'device_class'                                       => 'tablet', 'viewport_width' => '1024-1439', 'internal_actor' => false, 'outcome' => 'none'])
        ->and($payloads[1]['properties']['internal_actor'])->toBeTrue();
});

it('ingests transfer and PWA events once with bounded properties and accurate outcomes', function () {
    $names = ['transfer.file.attempted', 'transfer.file.acknowledged', 'transfer.file.received', 'transfer.file.cancelled', 'transfer.file.declined', 'transfer.file.failed', 'pwa.installable', 'pwa.install.prompted', 'pwa.install.choice', 'pwa.installed', 'pwa.standalone.opened', 'pwa.update.ready'];
    $events = [];
    foreach ($names as $name) {
        $events[] = ['id' => (string) Str::uuid(), 'n' => $name, 'ts' => now()->getTimestampMs(), 'sid' => (string) Str::uuid(), 'seq' => 1,
            'sf'          => str_starts_with($name, 'pwa.') ? 'pwa' : 'transfers',
            'p'           => str_starts_with($name, 'pwa.') ? ['choice' => 'accepted'] : ['transfer_id' => 'bounded-operation', 'direction' => 'send', 'files' => 2]];
    }
    $admin = auth('admin')->user();
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => $events];
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(12)
        ->and(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(0)
        ->and(DamEvent::TransferCancelled->outcome())->toBe(EventRegistry::OUTCOME_ABANDON)
        ->and(DamEvent::PwaChoice->outcome())->toBe(EventRegistry::OUTCOME_NONE);
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(12);
    request()->headers->set('Sec-GPC', '1');
    foreach ($events as &$event) {
        $event['id'] = (string) Str::uuid();
    }
    unset($event);
    app(Ingest::class)->handle(batch: ['t' => $batch['t'], 'e' => $events], request: request(), admin: $admin);
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(12);
});

it('exports committed outcomes once without raw identity or local capabilities', function () {
    $asset = Asset::factory()->create();
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct']);
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(1);
    $entry = DB::table(PosthogOutbox::TABLE)->first();
    $payload = json_decode($entry->payload, true);
    expect($payload['properties']['distinct_id'])->toHaveLength(64)
        ->and($payload['properties']['$process_person_profile'])->toBeFalse()
        ->and($payload['properties'])->not->toHaveKey('view_token');
    Http::fake(['*/capture/' => Http::response(['status' => 1])]);
    expect(app(PosthogExporter::class)->drain())->toBe(['sent' => 1, 'failed' => 0, 'discarded' => 0]);
    app(PosthogExporter::class)->drain();
    Http::assertSentCount(1);
    expect(DB::table(PosthogOutbox::TABLE)->value('payload'))->toBeNull();
});

it('accepts and exports the Hub browser contract without counting no-op saves as success', function () {
    $events = [];
    $session = (string) Str::uuid();
    foreach (['hub.open.confirmed', 'hub.open.failed', 'hub.save.attempted', 'hub.save.succeeded', 'hub.save.failed', 'hub.save.unchanged'] as $name) {
        $events[] = ['id' => (string) Str::uuid(), 'n' => $name, 'ts' => now()->getTimestampMs(), 'sid' => $session, 'seq' => count($events) + 1, 'sf' => 'hub', 'p' => ['app_id' => 'photocraft', 'hub_operation_id' => (string) Str::uuid(), 'mode' => 'version']];
    }
    $admin = auth('admin')->user();
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => $events];
    $result = app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin);
    expect($result->stored)->toBe(6)
        ->and(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(0);
    $payloads = DB::table(PosthogOutbox::TABLE)->pluck('payload')->map(fn ($row) => json_decode($row, true));
    expect($payloads)->toHaveCount(6)
        ->and($payloads->firstWhere('event', 'hub.save.unchanged')['properties']['outcome'])->toBe('none')
        ->and($payloads->firstWhere('event', 'hub.save.succeeded')['properties']['app_id'])->toBe('photocraft');
});

it('accepts the Craft connect wizard contract and counts only completion as success', function () {
    $connect = (string) Str::uuid();
    $session = (string) Str::uuid();
    $events = [];
    foreach ([
        'hub.connect.step.opened'    => ['step' => 2, 'action' => 1, 'setup' => 'new'],
        'hub.connect.status.checked' => ['active_grant' => true, 'service_enabled' => true, 'worker' => 'responded', 'apps' => 12],
        'hub.connect.status.failed'  => ['error_code' => 'status_request'],
        'hub.connect.completed'      => ['setup' => 'new'],
    ] as $name => $props) {
        $events[] = ['id' => (string) Str::uuid(), 'n' => $name, 'ts' => now()->getTimestampMs(), 'sid' => $session, 'seq' => count($events) + 1, 'sf' => 'hub', 'p' => ['connect_id' => $connect, 'provider' => 'claude', ...$props]];
    }
    $admin = auth('admin')->user();
    $batch = ['t' => IngestToken::issue(scope: 'admin', boundTo: (int) $admin->id), 'e' => $events];
    expect(app(Ingest::class)->handle(batch: $batch, request: request(), admin: $admin)->stored)->toBe(4)
        ->and(EventRegistry::clientCatalogue('admin'))->toHaveKeys(['hub.connect.step.opened', 'hub.connect.completed'])
        ->and(EventRegistry::clientCatalogue('admin'))->not->toHaveKey('craft.oauth.token.issued');
    $outcomes = DB::table('dam_events')->where('name', 'like', 'hub.connect.%')->pluck('outcome', 'name');
    expect($outcomes->all())->toEqual([
        'hub.connect.step.opened'   => 'none', 'hub.connect.status.checked' => 'none',
        'hub.connect.status.failed' => 'failure', 'hub.connect.completed' => 'success',
    ]);
});

it('retries the same capture UUID after failure and honors a subsequent opt-out', function () {
    $asset = Asset::factory()->create();
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct']);
    $original = DB::table(PosthogOutbox::TABLE)->value('payload');
    Http::fake(['*/capture/' => Http::response([], 503)]);
    expect(app(PosthogExporter::class)->drain()['failed'])->toBe(1);
    expect(DB::table(PosthogOutbox::TABLE)->value('payload'))->toBe($original);
    app(OptOuts::class)->set(adminId: (int) auth('admin')->id(), optedOut: true);
    DB::table(PosthogOutbox::TABLE)->update(['available_at' => now()->subMinute()]);
    app(PosthogExporter::class)->drain();
    Http::assertSentCount(1);
    expect(DB::table(PosthogOutbox::TABLE)->where('payload', '!=', null)->count())->toBe(0);
});

it('keeps privacy-signal traffic aggregate-only and bootstrap milestones non-successful', function () {
    request()->headers->set('DNT', '1');
    $asset = Asset::factory()->create();
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct']);
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(0)
        ->and(DamEvent::HomeLaunchLoaded->outcome())->toBe(EventRegistry::OUTCOME_NONE)
        ->and(DamEvent::HomeLaunchSlow->outcome())->toBe(EventRegistry::OUTCOME_NONE);
});

it('correlates a later success without duplicating either transition and cascades local forgetting', function () {
    $asset = Asset::factory()->create();
    foreach ([DamEvent::OptimizationFailed, DamEvent::OptimizationSucceeded, DamEvent::OptimizationSucceeded] as $event) {
        DamEvents::emit(event: $event, subjectId: $asset->id, props: ['job_id' => 'qa-transition', 'kind' => 'image'], businessId: 'qa-transition');
    }
    $rows = DB::table(PosthogOutbox::TABLE)->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('event_id')->unique())->toHaveCount(1);
    $payloads = $rows->map(fn ($row) => json_decode($row->payload, true));
    expect($payloads->pluck('uuid')->unique())->toHaveCount(2);
    app(Retention::class)->forgetAdmin(adminId: (int) auth('admin')->id());
    expect(DB::table(PosthogOutbox::TABLE)->count())->toBe(0);
});

it('stops permanent rejection and does not hammer a service outage', function () {
    foreach (range(1, 2) as $index) {
        DamEvents::emit(event: DamEvent::DownloadServed, subjectId: Asset::factory()->create()->id, subjectType: 'asset', props: ['via' => 'direct']);
    }
    Http::fake(['*/capture/' => Http::sequence()->push([], 503)->push([], 400)]);
    app(PosthogExporter::class)->drain();
    Http::assertSentCount(1);
    app(PosthogExporter::class)->drain();
    expect(DB::table(PosthogOutbox::TABLE)->where('state', 'failed')->whereNull('payload')->count())->toBe(1);
});

it('retains configuration failures for recovery without blocking local ingestion', function () {
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: Asset::factory()->create()->id, subjectType: 'asset', props: ['via' => 'direct']);
    Http::fake(['*/capture/' => Http::sequence()->push([], 401)->push(['status' => 1])]);
    app(PosthogExporter::class)->drain();
    expect(DB::table(PosthogOutbox::TABLE)->value('state'))->toBe('pending')
        ->and(DB::table(PosthogOutbox::TABLE)->value('payload'))->not->toBeNull();
    DB::table(PosthogOutbox::TABLE)->update(['available_at' => now()->subMinute()]);
    expect(app(PosthogExporter::class)->drain()['sent'])->toBe(1);

    // SQLite injection checks the savepoint; other engines need their native failure-injection syntax.
    if (DB::getDriverName() !== 'sqlite') {
        return;
    }
    DB::statement("CREATE TRIGGER reject_posthog BEFORE INSERT ON dam_posthog_outbox BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    $before = DB::table(EventStore::TABLE)->count();
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: Asset::factory()->create()->id, subjectType: 'asset', props: ['via' => 'direct']);
    expect(DB::table(EventStore::TABLE)->count())->toBe($before + 1);
    DB::statement('DROP TRIGGER reject_posthog');
});
