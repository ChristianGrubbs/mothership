<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\EventRegistry;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\Ingest;
use Mothership\DAM\Analytics\IngestToken;
use Mothership\DAM\Http\Controllers\AnalyticsIngestController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\User\Models\Admin;

/*
 * P1b ingest (§3.6.6, §6): the collector's endpoints accept only what the signed capability, the session and the
 * registry allow, derive the actor themselves, dedupe on the client's event id and keep the client's order.
 */

function analyticsIngestAs(Admin $admin, array $events, ?string $token = null, array $headers = [])
{
    test()->actingAs($admin, 'admin');

    return test()->withHeaders(analyticsBrowserHeaders($headers))->postJson(route('admin.dam.analytics.ingest'), [
        't' => $token ?? IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id),
        'e' => $events,
    ]);
}

function analyticsDrops(): array
{
    return DB::table(EventStore::COUNTS)->where('name', Ingest::DROPPED)->get()
        ->groupBy('detail')->map(fn ($rows) => (int) $rows->sum('events'))->sortKeys()->all();
}

it('keeps versioned metric snapshots and deduplicates their transport retries', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $props = ['metric' => 'CLS', 'value' => 300, 'route' => 'admin.dam.index',
        'metric_id'    => 'v6-1234567890-1234567890123', 'metric_revision' => 1,
        'measurement'  => 'web-vitals-6.2.3', 'navigation_type' => 'navigate'];
    $first = analyticsClientEvent('client.web_vitals.sampled', ['seq' => 1, 'p' => $props]);
    $second = analyticsClientEvent('client.web_vitals.sampled', ['seq' => 2,
        'p'                                                            => array_replace($props, ['value' => 800, 'metric_revision' => 2])]);
    analyticsIngestAs($admin, [$first, $second])->assertNoContent();
    analyticsIngestAs($admin, [$first, $second])->assertNoContent();

    $rows = DB::table(EventStore::TABLE)->where('name', 'client.web_vitals.sampled')->orderBy('seq')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->props, true))->toBe($props)
        ->and(json_decode($rows[1]->props, true)['metric_revision'])->toBe(2)
        ->and(analyticsDrops())->toBe([]);
});

it('accepts bounded error coordinates while refusing messages and arbitrary bundle names', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $props = ['kind'  => 'error', 'route' => 'admin.dam.index', 'error_type' => 'TypeError',
        'source_kind' => 'bundle', 'bundle' => 'app-Abc123_d.js', 'line' => 10, 'column' => 20];
    $document = ['kind' => 'error', 'route' => 'admin.dam.index', 'source_kind' => 'document', 'line' => 1200, 'column' => 30];
    analyticsIngestAs($admin, [
        analyticsClientEvent('client.error.raised', ['p' => $props]),
        analyticsClientEvent('client.error.raised', ['p' => $document]),
        analyticsClientEvent('client.error.raised', ['p' => $props + ['message' => 'private']]),
        analyticsClientEvent('client.error.raised', ['p' => array_replace($props, ['bundle' => 'customer-name.js'])]),
        analyticsClientEvent('client.error.raised', ['p' => array_replace($props, ['error_type' => 'private'])]),
    ])->assertNoContent();
    $rows = DB::table(EventStore::TABLE)->where('name', 'client.error.raised')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->props, true))->toBe($props)
        ->and(json_decode($rows[1]->props, true))->toBe($document)
        ->and(analyticsDrops())->toBe(['unknown_field' => 3]);
});

it('stores route views with declared device context and drops undeclared context', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $context = ['dc' => 'phone', 'vw' => 'lt640'];
    analyticsIngestAs($admin, [
        analyticsClientEvent('route.viewed', ['seq' => 1, 'p' => ['route' => 'admin.home.index', 'navigation' => 'document'], 'x' => $context]),
        analyticsClientEvent('filter.applied', ['seq' => 2, 'p' => ['filter' => 'tag'], 'x' => ['dc' => 'desktop']]),
        analyticsClientEvent('route.viewed', ['seq' => 3, 'p' => ['route' => 'admin.home.index', 'navigation' => 'spa'], 'x' => ['dc' => 'watch']]),
        analyticsClientEvent('route.viewed', ['seq' => 4, 'p' => ['route' => 'admin.home.index', 'navigation' => 'spa'], 'x' => $context + ['ua' => 'Safari']]),
        analyticsClientEvent('route.viewed', ['seq' => 5, 'p' => ['route' => 'admin.home.index', 'navigation' => 'spa'], 'x' => ['dc' => 'phone', 'vw' => '375']]),
    ])->assertNoContent();

    $rows = DB::table(EventStore::TABLE)->orderBy('seq')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->props, true))->toBe(['route' => 'admin.home.index', 'navigation' => 'document', 'device_class' => 'phone', 'viewport_width' => 'lt640'])
        ->and($rows[0]->outcome)->toBe(EventRegistry::OUTCOME_NONE)
        ->and(json_decode($rows[1]->props, true))->toBe(['filter' => 'tag', 'device_class' => 'desktop'])
        ->and(analyticsDrops())->toBe(['unknown_field' => 3]);
});

it('reads the session for an admin batch but never saves it', function () {
    $admin = Admin::factory()->create(['status' => 1]);

    analyticsIngestAs($admin, [analyticsClientEvent('filter.applied', ['seq' => 1, 'p' => ['filter' => 'extension']])])
        ->assertNoContent()->assertCookieMissing(config('session.cookie'));
    expect(app('session')->driver()->getHandler()->read(session()->getId()))->toBe('');
});

it('stores an admin batch with the server-derived actor, and a resent batch stores nothing new', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $asset = Asset::factory()->create();
    $events = [
        analyticsClientEvent('asset.preview.opened', ['s' => $asset->id, 'seq' => 1, 'sf' => 'modal', 'p' => ['visible_ms' => 1000]]),
        analyticsClientEvent('filter.applied', ['seq' => 2, 'p' => ['filter' => 'extension']]),
    ];

    analyticsIngestAs($admin, $events)->assertNoContent();
    // A retry of the same batch (a lost response, a beacon after a fetch): the same event ids.
    analyticsIngestAs($admin, $events)->assertNoContent();

    $rows = DB::table(EventStore::TABLE)->orderBy('seq')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('actor_key')->unique()->all())->toBe([(string) $admin->id])
        ->and($rows->pluck('actor_type')->unique()->all())->toBe(['admin'])
        ->and($rows->pluck('emitter')->unique()->all())->toBe(['client'])
        ->and($rows[0]->subject_type)->toBe('asset')
        ->and((int) $rows[0]->subject_id)->toBe($asset->id)
        ->and($rows[0]->outcome)->toBe('none');
});

it('refuses a forged actor, a forged scope, an expired token and a tampered one', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $other = Admin::factory()->create(['status' => 1]);
    $share = Share::factory()->forAsset(Asset::factory()->create()->id)->create();
    $event = [analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag']])];
    $valid = IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id);
    [$payload, $signature] = explode('.', $valid);

    // Someone else's capability, a share capability on the admin endpoint, an expired one, a re-signed payload.
    analyticsIngestAs($admin, $event, IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $other->id))->assertForbidden();
    analyticsIngestAs($admin, $event, IngestToken::issue(scope: EventRegistry::SCOPE_SHARE, boundTo: (int) $share->id))->assertForbidden();
    analyticsIngestAs($admin, $event, IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id, ttlMinutes: -1))->assertForbidden();
    $forged = rtrim(strtr(base64_encode(json_encode(['s' => 'admin', 'b' => $other->id, 'e' => now()->addHour()->getTimestamp()])), '+/', '-_'), '=');
    analyticsIngestAs($admin, $event, $forged.'.'.$signature)->assertForbidden();
    analyticsIngestAs($admin, $event, 'not-a-token')->assertForbidden();

    // The public endpoint: an admin capability, or a revoked link's.
    $public = fn (string $token) => test()->withHeaders(analyticsBrowserHeaders())->postJson(route('dam.analytics.ingest'), ['t' => $token, 'e' => $event]);
    $public($valid)->assertForbidden();
    $share->update(['revoked_at' => now()]);
    $public(IngestToken::issue(scope: EventRegistry::SCOPE_SHARE, boundTo: (int) $share->id))->assertForbidden();

    expect(DB::table(EventStore::TABLE)->count())->toBe(0)
        ->and($payload)->not->toBe('');
});

it('drops an unknown event, a server-only event, an undeclared field or prop, and a wrongly typed prop', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $asset = Asset::factory()->create();

    analyticsIngestAs($admin, [
        analyticsClientEvent('asset.secretly.exported'),
        // The server records downloads; a client cannot claim one.
        analyticsClientEvent('asset.download.served', ['s' => $asset->id]),
        analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag'], 'actor' => 'someone-else']),
        analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag', 'url' => 'https://example.test/?q=secret']]),
        analyticsClientEvent('filter.applied', ['p' => ['filter' => 'a free text value']]),
        analyticsClientEvent('asset.preview.opened', ['s' => $asset->id, 'p' => ['visible_ms' => 'long']]),
        analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag']]),
    ])->assertNoContent();

    expect(DB::table(EventStore::TABLE)->pluck('name')->all())->toBe(['filter.applied'])
        ->and(analyticsDrops())->toBe(['server_only_event' => 1, 'unknown_event' => 1, 'unknown_field' => 4]);
});

it('drops an event about a file the sender cannot see, or through a link that does not reach it', function () {
    $fixture = analyticsAccessFixture(['dam', 'dam.asset.view']);
    $share = Share::factory()->forAsset($fixture['visible']->id)->create();

    analyticsIngestAs($fixture['viewer'], [
        analyticsClientEvent('asset.preview.opened', ['s' => $fixture['visible']->id, 'seq' => 1]),
        analyticsClientEvent('asset.preview.opened', ['s' => $fixture['hidden']->id, 'seq' => 2]),
    ])->assertNoContent();

    test()->withHeaders(analyticsBrowserHeaders())->postJson(route('dam.analytics.ingest'), [
        't' => IngestToken::issue(scope: EventRegistry::SCOPE_SHARE, boundTo: (int) $share->id),
        'e' => [
            analyticsClientEvent('asset.preview.opened', ['s' => $fixture['visible']->id, 'sid' => (string) Str::uuid()]),
            analyticsClientEvent('asset.preview.opened', ['s' => $fixture['hidden']->id, 'sid' => (string) Str::uuid()]),
        ],
    ])->assertNoContent();

    expect(DB::table(EventStore::TABLE)->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->toBe([$fixture['visible']->id])
        ->and(analyticsDrops())->toBe(['restricted_subject' => 2]);
});

it('keeps a session to the actor who started it and a link visitor out of staff sessions', function () {
    $first = Admin::factory()->create(['status' => 1]);
    $second = Admin::factory()->create(['status' => 1]);
    $session = (string) Str::uuid();

    analyticsIngestAs($first, [analyticsClientEvent('filter.applied', ['sid' => $session, 'p' => ['filter' => 'tag']])])->assertNoContent();
    analyticsIngestAs($second, [analyticsClientEvent('filter.applied', ['sid' => $session, 'p' => ['filter' => 'tag']])])->assertNoContent();

    expect(DB::table(EventStore::TABLE)->where('session_id', $session)->pluck('actor_key')->all())->toBe([(string) $first->id])
        ->and(analyticsDrops())->toBe(['session_reuse' => 1]);
});

it('orders a session by its sequence whatever order the batches arrive in, and clamps a lying clock', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $session = (string) Str::uuid();
    $event = fn (int $seq, int $ts) => analyticsClientEvent('filter.applied', ['sid' => $session, 'seq' => $seq, 'ts' => $ts, 'p' => ['filter' => 'tag']]);
    $now = (int) now()->getTimestampMs();

    // The second batch reaches the server first; a client clock a day ahead and a year behind.
    analyticsIngestAs($admin, [$event(3, $now + 86_400_000), $event(4, $now)])->assertNoContent();
    analyticsIngestAs($admin, [$event(1, $now - 31_536_000_000), $event(2, $now - 1000)])->assertNoContent();

    $rows = DB::table(EventStore::TABLE)->where('session_id', $session)->orderBy('seq')->get();
    expect($rows->pluck('seq')->map(fn ($seq) => (int) $seq)->all())->toBe([1, 2, 3, 4])
        ->and($rows->pluck('id')->map(fn ($id) => (int) $id)->all())->not->toBe($rows->sortBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all())
        ->and(strtotime($rows[0]->occurred_at))->toBeGreaterThanOrEqual(now()->subHours(24)->subMinute()->getTimestamp())
        ->and(strtotime($rows[2]->occurred_at))->toBeLessThanOrEqual(now()->addMinutes(6)->getTimestamp());
});

it('answers 413 to an oversized body, 400 to one that is not JSON, and stores only the batch cap', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    test()->actingAs($admin, 'admin');
    $token = IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id);

    test()->withHeaders(analyticsBrowserHeaders())->call('POST', route('admin.dam.analytics.ingest'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('x', 16385))->assertStatus(413);
    test()->withHeaders(analyticsBrowserHeaders())->call('POST', route('admin.dam.analytics.ingest'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{nope')->assertStatus(400);

    // sendBeacon posts text/plain: the body is still read as JSON.
    $events = array_map(fn (int $seq) => analyticsClientEvent('filter.applied', ['seq' => $seq, 'p' => ['filter' => 'tag']]), range(1, 30));
    test()->withHeaders(analyticsBrowserHeaders())->call('POST', route('admin.dam.analytics.ingest'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['t' => $token, 'e' => $events]))->assertNoContent();

    expect(DB::table(EventStore::TABLE)->count())->toBe(25)
        ->and(analyticsDrops())->toBe(['over_batch' => 5]);
});

it('renews an admin capability close to expiry and never a share one', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $event = [analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag']])];

    analyticsIngestAs($admin, $event)->assertNoContent()->assertHeaderMissing(AnalyticsIngestController::RENEW_HEADER);

    $renewal = analyticsIngestAs($admin, [analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag']])], IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id, ttlMinutes: 10))
        ->assertNoContent()->headers->get(AnalyticsIngestController::RENEW_HEADER);
    expect(IngestToken::verify($renewal))->toMatchArray(['scope' => 'admin', 'bound' => (int) $admin->id]);
});

it('ingests a full batch in a constant number of queries', function () {
    $fixture = analyticsAccessFixture(['dam', 'dam.asset.view']);
    $assets = collect(range(1, 20))->map(function () use ($fixture) {
        $asset = Asset::factory()->create();
        DB::table('dam_asset_directory')->insert(['asset_id' => $asset->id, 'directory_id' => $fixture['granted']->id]);

        return $asset->id;
    });
    $batch = fn (int $size) => $assets->take($size)->values()->map(fn (int $id, int $i) => analyticsClientEvent('search.submitted', ['seq' => $i + 1, 'p' => ['q_norm' => 'brochure '.$i, 'words' => 2, 'result_count' => 3]]))
        ->merge($assets->take($size)->values()->map(fn (int $id, int $i) => analyticsClientEvent('asset.preview.opened', ['s' => $id, 'seq' => 100 + $i])))
        ->all();

    $count = function (array $events) use ($fixture): int {
        // Resolve the actor and caches once, so the two runs differ only in the batch.
        analyticsIngestAs($fixture['viewer'], [analyticsClientEvent('filter.applied', ['p' => ['filter' => 'tag']])]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        analyticsIngestAs($fixture['viewer'], $events)->assertNoContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Six times the events, not one more query: the subjects, ids, session owners, hidden names and both inserts
    // are each one statement for the batch. (The first run may also read the access model once.)
    $small = $count($batch(2));
    $large = $count($batch(12));

    expect($large)->toBeLessThanOrEqual($small)
        ->and(DB::table(EventStore::TABLE)->where('name', 'asset.preview.opened')->count())->toBe(14);
});

it('ships the collector and official metric algorithms within 8 KB minified and gzipped', function () {
    $manifest = json_decode((string) file_get_contents(public_path('themes/dam/build/manifest.json')), true);
    $file = public_path('themes/dam/build/'.$manifest['src/Resources/assets/js/analytics/collector.js']['file']);
    $gzipped = strlen(gzencode((string) file_get_contents($file), 9));

    expect($gzipped)->toBeLessThanOrEqual(8192);
});
