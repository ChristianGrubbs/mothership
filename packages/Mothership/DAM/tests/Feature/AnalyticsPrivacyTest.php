<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\DailySalt;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventRegistry;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\IngestToken;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\DAM\Analytics\Rollups;
use Mothership\DAM\Analytics\SearchTerms;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P1b privacy (§3.6.7): opt-out and DNT/GPC at emission and ingestion, the notice, k-suppression, retention and
 * forgetting, and the link visitor's key.
 */

/** One admin's batch: a preview and a search, through the real ingest route. */
function analyticsPrivacyIngest(Admin $admin, Asset $asset, array $headers = []): void
{
    $sid = (string) Str::uuid();
    test()->actingAs($admin, 'admin');
    test()->flushHeaders()->withHeaders(analyticsBrowserHeaders($headers))->postJson(route('admin.dam.analytics.ingest'), [
        't' => IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id),
        'e' => [
            analyticsClientEvent('asset.preview.opened', ['sid' => $sid, 's' => $asset->id, 'sf' => 'modal']),
            analyticsClientEvent('search.submitted', ['sid' => $sid, 'seq' => 2, 'p' => ['q_norm' => 'spring campaign', 'words' => 2, 'result_count' => 4]]),
        ],
    ])->assertNoContent();
}

/** @return array<string, int> count-only events by name, for one actor type */
function analyticsAggregateCounts(string $actorType): array
{
    return DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_AGGREGATE)->where('actor_type', $actorType)
        ->groupBy('name')->selectRaw('name, SUM(events) AS events')->pluck('events', 'name')->map(fn ($n) => (int) $n)->sortKeys()->all();
}

it('records an opted-out admin only in anonymous totals, at emission and at ingestion, and sends no collector', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $asset = Asset::factory()->create();
    app(OptOuts::class)->set(adminId: (int) $admin->id, optedOut: true);

    $this->actingAs($admin, 'admin');
    request()->headers->add(analyticsBrowserHeaders());
    expect(DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct']))->toBe(DamEvents::AGGREGATE);
    analyticsPrivacyIngest(admin: $admin, asset: $asset);

    expect(DB::table(EventStore::TABLE)->count())->toBe(0)
        ->and(DB::table(SearchTerms::TABLE)->count())->toBe(0)
        ->and(analyticsAggregateCounts(AnalyticsActor::ADMIN))->toBe(['asset.download.served' => 1, 'asset.preview.opened' => 1, 'search.submitted' => 1])
        ->and(DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_AGGREGATE)->where('role_id', '!=', 0)->exists())->toBeFalse();

    $this->get(route('admin.dam.analytics.privacy'))->assertOk()
        ->assertDontSee('id="dam-t"', false)
        ->assertSee(trans('dam::app.admin.analytics.privacy.opted-out-status'));
});

it('treats Do Not Track and Global Privacy Control as count-only for staff and link visitors, and never loads the collector', function (string $header) {
    $admin = Admin::factory()->create(['status' => 1]);
    $asset = Asset::factory()->create();
    $this->actingAs($admin, 'admin');

    // Emission reads the signal from the request in flight.
    $request = request();
    $request->headers->set($header, '1');
    expect(app(ActorResolver::class)->forAdmin(admin: $admin, request: $request))
        ->aggregateOnly->toBeTrue()->reason->toBe(AnalyticsActor::REASON_PRIVACY_SIGNAL);
    $request->headers->remove($header);

    analyticsPrivacyIngest(admin: $admin, asset: $asset, headers: [$header => '1']);
    $this->withHeaders(analyticsBrowserHeaders([$header => '1']))->get(route('admin.dam.analytics.privacy'))->assertOk()
        ->assertDontSee('id="dam-t"', false)
        ->assertSee(trans('dam::app.admin.analytics.privacy.signal-status'));

    $share = Share::factory()->forAsset($asset->id)->create();
    $this->withHeaders(analyticsBrowserHeaders([$header => '1']))->get(route('dam.share.show', $share->urlToken()))->assertOk()
        ->assertDontSee('id="dam-t"', false)
        ->assertSee(trans('dam::app.share.public.analytics-notice'));

    expect(DB::table(EventStore::TABLE)->count())->toBe(0)
        ->and(DB::table(SearchTerms::TABLE)->count())->toBe(0)
        ->and(analyticsAggregateCounts(AnalyticsActor::ADMIN))->toBe(['asset.preview.opened' => 1, 'search.submitted' => 1])
        ->and(analyticsAggregateCounts(AnalyticsActor::LINK_VISITOR))->toBe(['share.link.visited' => 1])
        // The link's own counter is not analytics: a count-only visit still counts.
        ->and((int) $share->fresh()->view_count)->toBe(1);

    // Without the signal the same pages carry the collector.
    $this->flushHeaders()->withHeaders(analyticsBrowserHeaders())->get(route('admin.dam.analytics.privacy'))->assertSee('id="dam-t"', false);
    $this->withHeaders(analyticsBrowserHeaders())->get(route('dam.share.show', $share->urlToken()))->assertSee('id="dam-t"', false);
})->with(['DNT', 'Sec-GPC']);

it('lets anyone signed in read the notice and opt out or back in, and shows the notice line on every admin page', function () {
    $member = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']])->id]);
    $this->actingAs($member, 'admin');

    $this->get(route('admin.dam.analytics.privacy'))->assertOk()
        ->assertSee(trans('dam::app.admin.analytics.privacy.opted-in-status'))
        ->assertSee(trans('dam::app.admin.analytics.footer.link'))
        ->assertSee(route('admin.dam.analytics.privacy'), false)
        ->assertSee('id="dam-t"', false);

    $this->post(route('admin.dam.analytics.privacy.update'), ['opt_out' => '1'])->assertRedirect(route('admin.dam.analytics.privacy'));
    expect(app(OptOuts::class)->has((int) $member->id))->toBeTrue();

    $this->post(route('admin.dam.analytics.privacy.update'), ['opt_out' => '0'])->assertRedirect();
    expect(app(OptOuts::class)->has((int) $member->id))->toBeFalse();

    $this->loginAsAdmin();
    $this->get(route('admin.dam.reports.engagement'))->assertOk()->assertSee(trans('dam::app.admin.analytics.footer.text'));
});

it('names a search word only once k different people searched it in the slice', function () {
    $this->loginAsAdmin();
    $viewer = auth('admin')->user();
    $people = array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $viewer->role_id]), range(1, 3));
    $keep = fn (Admin $admin, string $term) => app(SearchTerms::class)->keep(admin: $admin, searches: [['term' => $term, 'result_count' => 0, 'at' => now()]]);

    foreach ($people as $index => $admin) {
        $keep($admin, 'brand guidelines');
        $keep($admin, 'brand guidelines');
        $index < 2 && $keep($admin, 'rare word');
    }
    $keep($people[0], 'jane@example.com');

    $report = collect(app(SearchTerms::class)->report(viewer: $viewer, from: now()->subDay(), to: now()));

    expect($report->firstWhere('term', 'brand guidelines'))->toMatchArray(['searches' => 6, 'zero_results' => 6])
        ->and($report->pluck('term')->all())->not->toContain('rare word')
        ->and($report->where('term', null)->sum('searches'))->toBe(2)
        ->and(DB::table(SearchTerms::TABLE)->where('term_norm', 'jane@example.com')->exists())->toBeFalse();
});

it('prunes exactly at each retention boundary', function () {
    $actor = new AnalyticsActor(type: AnalyticsActor::ADMIN, key: '1');
    $at = fn (int $daysAgo) => now()->startOfDay()->subDays($daysAgo)->addHour();

    foreach (array_map($at, [91, 90, 89]) as $instant) {
        $this->travelTo($instant);
        DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 1], actor: $actor);
    }
    $this->travelBack();

    $countsDay = fn (int $months, int $days) => now()->startOfDay()->subMonthsNoOverflow($months)->addDays($days)->toDateString();
    foreach ([[25, -1], [25, 0], [24, 0]] as [$months, $days]) {
        app(EventStore::class)->increment(slice: ['day' => $countsDay($months, $days), 'name' => 'search.served', 'outcome' => 'none', 'actor_type' => 'admin']);
    }

    foreach ([31, 30, 29] as $daysAgo) {
        DB::table(SearchTerms::TABLE)->insert(['term_norm' => 'term '.$daysAgo, 'actor_key' => '1', 'role_id' => null, 'day' => now()->subDays($daysAgo)->toDateString(), 'result_count' => 1, 'created_at' => now()]);
    }

    $this->artisan('dam:analytics:prune', ['--dry-run' => true])->assertSuccessful();
    expect(DB::table(EventStore::TABLE)->count())->toBe(3);

    $this->artisan('dam:analytics:prune')->assertSuccessful();

    expect(DB::table(EventStore::TABLE)->pluck('occurred_at')->map(fn ($t) => Carbon::parse($t)->startOfDay()->diffInDays(now()->startOfDay()))->map(fn ($d) => (int) round(abs($d)))->sort()->values()->all())->toBe([89, 90])
        ->and(DB::table(EventStore::COUNTS)->orderBy('day')->pluck('day')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe([$countsDay(25, 0), $countsDay(24, 0)])
        ->and(DB::table(SearchTerms::TABLE)->orderBy('term_norm')->pluck('term_norm')->all())->toBe(['term 29', 'term 30']);
});

it('forgets one person exactly, recomputes their days, and keeps their opt-out', function () {
    $asset = Asset::factory()->create();
    [$forgotten, $kept] = [Admin::factory()->create(['status' => 1]), Admin::factory()->create(['status' => 1])];

    foreach ([$forgotten, $kept] as $admin) {
        analyticsPrivacyIngest(admin: $admin, asset: $asset);
    }
    app(OptOuts::class)->set(adminId: (int) $forgotten->id, optedOut: true);
    app(Rollups::class)->day(day: now());
    $views = fn () => (int) DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_ROLLUP)->where('name', 'asset.preview.opened')->sum('counted');
    expect($views())->toBe(2);

    $this->artisan('dam:analytics:forget', ['--admin' => $forgotten->id, '--dry-run' => true])->assertSuccessful();
    expect(DB::table(EventStore::TABLE)->where('actor_key', (string) $forgotten->id)->count())->toBe(2);

    $this->artisan('dam:analytics:forget', ['--admin' => $forgotten->id])->assertSuccessful();

    expect(DB::table(EventStore::TABLE)->where('actor_key', (string) $forgotten->id)->count())->toBe(0)
        ->and(DB::table(SearchTerms::TABLE)->where('actor_key', (string) $forgotten->id)->count())->toBe(0)
        ->and(DB::table(EventStore::TABLE)->where('actor_key', (string) $kept->id)->count())->toBe(2)
        ->and(DB::table(SearchTerms::TABLE)->where('actor_key', (string) $kept->id)->count())->toBe(1)
        ->and($views())->toBe(1)
        ->and(app(OptOuts::class)->has((int) $forgotten->id))->toBeTrue();
});

it('keys a link visitor by a daily secret that is never stored, and sets no cookie of its own', function () {
    $share = Share::factory()->forAsset(Asset::factory()->create()->id)->create();
    $salt = app(DailySalt::class);
    $key = fn (?Carbon $day = null) => $salt->visitorKey(shareId: (int) $share->id, truncatedIp: '203.0.113.0', browserFamily: 'Chrome', day: $day);

    expect($key())->toBe($key())->toMatch('/^[0-9a-f]{64}$/')
        ->and($key(now()->addDay()))->not->toBe($key())
        ->and($salt->visitorKey(shareId: (int) $share->id, truncatedIp: '203.0.114.0', browserFamily: 'Chrome'))->not->toBe($key());

    $response = $this->withHeaders(analyticsBrowserHeaders())->get(route('dam.share.show', $share->urlToken()))->assertOk();
    $cookies = collect($response->headers->getCookies())->map->getName()->all();
    expect(array_diff($cookies, [config('session.cookie'), 'XSRF-TOKEN']))->toBe([]);

    $stored = DB::table(EventStore::TABLE)->where('name', 'share.link.visited')->sole();
    expect($stored->actor_type)->toBe(AnalyticsActor::LINK_VISITOR)
        ->and(strlen((string) $stored->actor_key))->toBe(64)
        ->and(json_encode($stored))->not->toContain('203.0.113')->not->toContain('127.0.0');
});
