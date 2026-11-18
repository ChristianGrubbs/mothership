<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\AnalyticsReports;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventRegistry;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\IngestToken;
use Mothership\DAM\Analytics\Pseudonym;
use Mothership\DAM\Analytics\Rollups;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P1b reports (§3.6.8): access before aggregation, and who may see journeys and names.
 */

const ANALYTICS_REPORT_ROUTES = ['admin.dam.reports.engagement', 'admin.dam.reports.friction', 'admin.dam.reports.adoption', 'admin.dam.reports.journeys'];

/**
 * The same activity on a file the viewer can see and one they cannot, by three full admins in one session each:
 * views, downloads, a link visit, a failed upload into each folder, and a search for the hidden file's name.
 *
 * @return array{session: string, staff: list<Admin>}
 */
function analyticsSeedActivity(array $fixture): array
{
    $staff = [Admin::factory()->create(['status' => 1, 'name' => 'Priya Analytics']), Admin::factory()->create(['status' => 1]), Admin::factory()->create(['status' => 1])];
    $session = (string) Str::uuid();

    foreach ($staff as $index => $admin) {
        test()->actingAs($admin, 'admin');
        $sid = $index === 0 ? $session : (string) Str::uuid();
        test()->withHeaders(analyticsBrowserHeaders())->postJson(route('admin.dam.analytics.ingest'), [
            't' => IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id),
            'e' => [
                analyticsClientEvent('asset.preview.opened', ['sid' => $sid, 'seq' => 1, 's' => $fixture['visible']->id, 'sf' => 'modal']),
                analyticsClientEvent('asset.preview.opened', ['sid' => $sid, 'seq' => 2, 's' => $fixture['hidden']->id, 'sf' => 'modal']),
                analyticsClientEvent('search.submitted', ['sid' => $sid, 'seq' => 3, 'p' => ['q_norm' => 'secret-merger-plan', 'words' => 1, 'result_count' => 1]]),
                analyticsClientEvent('search.submitted', ['sid' => $sid, 'seq' => 4, 'p' => ['q_norm' => 'visible-analytics-file', 'words' => 1, 'result_count' => 0]]),
            ],
        ])->assertNoContent();

        $actor = new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id, roleId: (int) $admin->role_id);
        foreach (['visible' => $fixture['granted'], 'hidden' => $fixture['other']] as $which => $folder) {
            DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $fixture[$which]->id, subjectType: 'asset', props: ['via' => 'direct'], actor: $actor);
            $upload = $which.'-'.$index;
            DamEvents::emit(event: DamEvent::UploadAttempted, subjectId: $folder->id, props: ['upload_id' => $upload, 'attempt' => 1, 'path' => 'direct'], actor: $actor, businessId: $upload, attempt: '1');
            DamEvents::emit(event: DamEvent::UploadFailed, subjectId: $folder->id, props: ['upload_id' => $upload, 'path' => 'direct', 'error_code' => $which.'_folder_code'], actor: $actor, businessId: $upload);
        }
    }

    foreach (['visible', 'hidden'] as $which) {
        $share = Share::factory()->forAsset($fixture[$which]->id)->create(['name' => $which.' link name']);
        test()->withHeaders(analyticsBrowserHeaders())->get(route('dam.share.show', $share->urlToken()));
    }

    app(Rollups::class)->day(day: now());

    return ['session' => $session, 'staff' => $staff];
}

it('never counts or names a hidden asset\'s events in any report', function () {
    $fixture = analyticsAccessFixture(['dam', 'dam.reports', 'dam.reports.view', 'dam.reports.journeys']);
    $seeded = analyticsSeedActivity($fixture);
    $viewer = $fixture['viewer'];
    $reports = app(AnalyticsReports::class);

    // The figures: one file of the two, one folder of the two.
    $engagement = $reports->engagement(viewer: $viewer, days: 7);
    expect($engagement['totals'])->toMatchArray(['views' => 3, 'downloads' => 3, 'visits' => 1])
        ->and(collect($engagement['assets'])->pluck('id')->all())->toBe([$fixture['visible']->id])
        ->and(collect($engagement['links'])->pluck('name')->all())->toBe(['visible link name'])
        ->and($engagement['visible'])->toBe(1);

    $friction = $reports->friction(viewer: $viewer, days: 7);
    expect($friction['upload'])->toMatchArray(['uploads' => 3, 'failed' => 3, 'codes' => ['visible_folder_code' => 3]])
        ->and(collect($friction['terms'])->pluck('term')->all())->toBe(['visible-analytics-file']);

    $journey = $reports->timeline(viewer: $viewer, sessionId: $seeded['session'], identify: false);
    expect(collect($journey['events'])->pluck('subject.id')->filter()->unique()->values()->all())->toBe([$fixture['visible']->id]);

    // The pages: the hidden file, its folder's failure code, its link and its search never appear.
    $this->actingAs($viewer, 'admin');
    foreach ([...ANALYTICS_REPORT_ROUTES, 'admin.dam.reports.journey'] as $route) {
        $page = $this->withHeaders(analyticsBrowserHeaders())->get(route($route, ['days' => 7, 'session' => $seeded['session']]))->assertOk();
        $page->assertDontSee('secret-merger-plan')->assertDontSee('hidden_folder_code')->assertDontSee('hidden link name');
    }

    $this->get(route('admin.dam.reports.engagement', ['days' => 7]))->assertSee('visible-analytics-file.jpg')->assertSee('visible link name');

    // A full admin sees both, so the fixture really holds what the viewer was spared.
    $this->actingAs($seeded['staff'][1], 'admin');
    $this->get(route('admin.dam.reports.engagement', ['days' => 7]))->assertSee('secret-merger-plan.pdf')->assertSee('hidden link name');
    $this->get(route('admin.dam.reports.friction', ['days' => 7]))->assertSee('hidden_folder_code')->assertSee('secret-merger-plan');
});

it('counts and names a collection link only for whoever manages the collection, and forgets a deleted one', function () {
    $fixture = analyticsAccessFixture(['dam', 'dam.reports', 'dam.reports.view']);
    $viewer = $fixture['viewer'];
    $admin = Admin::factory()->create(['status' => 1]);
    $visitor = new AnalyticsActor(type: AnalyticsActor::LINK_VISITOR, key: 'visitor-1');
    $links = [];

    foreach (['theirs' => $admin, 'mine' => $viewer] as $which => $owner) {
        $collection = AssetCollection::query()->forceCreate(['name' => $which.' collection label', 'owner_id' => $owner->id, 'audience' => AssetCollection::AUDIENCE_PUBLIC]);
        $share = app(ShareRepository::class)->createForCollection(collectionId: $collection->id, expiresAt: null, userId: $owner->id, name: $which.' collection link');
        DamEvents::emit(event: DamEvent::ShareVisited, subjectId: $collection->id, subjectType: 'collection', props: ['share_type' => 'collection', 'view_token' => (string) Str::uuid()], surface: 'share', actor: $visitor, shareId: $share->id);
        // A member the viewer can see, downloaded through the link: the member is visible, the link is not.
        DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $fixture['visible']->id, subjectType: 'asset', props: ['via' => 'direct'], surface: 'share', actor: $visitor, shareId: $share->id);
        $links[$which] = [$collection, $share];
    }
    app(Rollups::class)->day(day: now());
    $engagement = fn (Admin $as) => app(AnalyticsReports::class)->engagement(viewer: $as, days: 7);

    $mine = $engagement($viewer);
    expect(collect($mine['links'])->map(fn ($link) => [$link['name'], $link['target'], $link['visits']])->all())->toBe([['mine collection link', 'mine collection label', 1]])
        ->and($mine['totals'])->toMatchArray(['visits' => 1, 'downloads' => 2]);

    $all = $engagement($admin);
    expect(collect($all['links'])->pluck('target')->sort()->values()->all())->toBe(['mine collection label', 'theirs collection label'])
        ->and($all['totals']['visits'])->toBe(2);

    // Creating each link recorded share.link.created; friction counts only the links the viewer manages.
    $created = fn (Admin $as) => app(AnalyticsReports::class)->friction(viewer: $as, days: 7)['share']['created'];
    expect($created($viewer))->toBe(1)->and($created($admin))->toBe(2);

    // A staff member's journey through either link: the visible member stays, an unmanaged link's id does not.
    $session = (string) Str::uuid();
    foreach ($links as [, $share]) {
        DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $fixture['visible']->id, subjectType: 'asset', props: ['via' => 'direct'], surface: 'share',
            actor: new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id, roleId: (int) $admin->role_id), shareId: $share->id);
    }
    DB::table(EventStore::TABLE)->where('actor_key', (string) $admin->id)->update(['session_id' => $session]);
    $journey = fn (Admin $as) => collect(app(AnalyticsReports::class)->timeline(viewer: $as, sessionId: $session, identify: false)['events']);
    expect($journey($viewer)->pluck('share_id')->all())->toBe([null, $links['mine'][1]->id])
        ->and($journey($viewer)->pluck('subject.id')->unique()->all())->toBe([$fixture['visible']->id])
        ->and($journey($admin)->pluck('share_id')->all())->toBe([$links['theirs'][1]->id, $links['mine'][1]->id]);

    // A deleted collection (its link goes with it) leaves no visits behind, even for a full admin.
    DB::table('dam_shares')->where('id', $links['theirs'][1]->id)->delete();
    $links['theirs'][0]->delete();
    expect($engagement($admin)['totals']['visits'])->toBe(1)
        ->and($created($admin))->toBe(1)
        ->and(collect($engagement($admin)['links'])->pluck('id')->all())->toBe([$links['mine'][1]->id])
        ->and($journey($viewer)->pluck('share_id')->all())->toBe([null, $links['mine'][1]->id])
        ->and($journey($admin)->pluck('share_id')->all())->toBe([null, $links['mine'][1]->id]);
});

it('answers 403 on every analytics report to a role without the keys, and 404 on a session it cannot see', function () {
    $everythingElse = collect(config('acl'))->pluck('key')->unique()
        ->reject(fn (string $key) => str_starts_with($key, 'dam.reports'))->values()->all();
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $everythingElse])->id]);
    $this->actingAs($member, 'admin');

    foreach ([...ANALYTICS_REPORT_ROUTES, 'admin.dam.reports.journey'] as $route) {
        $this->get(route($route, ['session' => (string) Str::uuid()]))->assertForbidden();
    }

    // The dashboards key alone does not open journeys.
    $viewOnly = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.reports', 'dam.reports.view']])->id]);
    $this->actingAs($viewOnly, 'admin');
    $this->get(route('admin.dam.reports.engagement'))->assertOk()->assertDontSee(route('admin.dam.reports.journeys'));
    $this->get(route('admin.dam.reports.journeys'))->assertForbidden();
    $this->get(route('admin.dam.reports.journey', ['session' => (string) Str::uuid()]))->assertForbidden();

    $this->loginAsAdmin();
    $this->get(route('admin.dam.reports.journey', ['session' => (string) Str::uuid()]))->assertNotFound();
});

it('shows a pseudonym in journeys unless the role may identify people', function () {
    $fixture = analyticsAccessFixture(['dam', 'dam.reports', 'dam.reports.journeys']);
    $seeded = analyticsSeedActivity($fixture);
    $named = $seeded['staff'][0];
    $pseudonym = Pseudonym::for(actorType: AnalyticsActor::ADMIN, actorKey: (string) $named->id);

    $this->actingAs($fixture['viewer'], 'admin');
    $this->get(route('admin.dam.reports.journeys', ['days' => 7]))->assertOk()->assertSee($pseudonym)->assertDontSee('Priya Analytics');
    $this->get(route('admin.dam.reports.journey', ['session' => $seeded['session'], 'days' => 7]))->assertOk()->assertSee($pseudonym)->assertDontSee('Priya Analytics');

    $role = Role::query()->find($fixture['viewer']->role_id);
    $role->update(['permissions' => [...$role->permissions, 'dam.reports.identify']]);
    $this->actingAs($fixture['viewer']->fresh(), 'admin');
    $this->get(route('admin.dam.reports.journey', ['session' => $seeded['session'], 'days' => 7]))->assertOk()->assertSee('Priya Analytics');

    expect($pseudonym)->toMatch('/^User [0-9a-f]{4}$/');
});

it('audits every report view and never grants the keys through Use Sandbox defaults', function () {
    $this->loginAsAdmin();

    foreach (['engagement', 'friction', 'adoption', 'journeys'] as $report) {
        $this->get(route('admin.dam.reports.'.$report, ['days' => 30]))->assertOk();
    }

    $audited = DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::REPORT_VIEWED)->pluck('ctx')
        ->map(fn ($ctx) => json_decode((string) $ctx, true)['report'] ?? null)->all();
    expect($audited)->toBe(['engagement', 'friction', 'adoption', 'journeys']);

    expect(array_intersect(['dam.reports.view', 'dam.reports.journeys', 'dam.reports.identify'], array_merge(...array_values(RoleBuilder::CAPABILITIES))))->toBe([]);
});

it('suppresses a role slice of fewer than k people in the adoption report', function () {
    $this->loginAsAdmin();
    $small = Role::factory()->create(['permission_type' => 'all']);
    $large = Role::factory()->create(['permission_type' => 'all']);
    $people = [
        ...array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $small->id]), range(1, 2)),
        ...array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $large->id]), range(1, 3)),
    ];

    foreach ($people as $admin) {
        DamEvents::emit(event: DamEvent::SearchServed, props: ['result_count' => 2, 'smart' => false, 'similar' => true], actor: new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id, roleId: (int) $admin->role_id));
    }

    $reports = app(AnalyticsReports::class);
    $viewer = auth('admin')->user();

    expect($reports->adoption(viewer: $viewer, days: 7, roleId: $small->id)['features']['find_similar'])->toBe(['actors' => null, 'uses' => null, 'repeat' => 0])
        ->and($reports->adoption(viewer: $viewer, days: 7, roleId: $large->id)['features']['find_similar'])->toBe(['actors' => 3, 'uses' => 3, 'repeat' => 0])
        ->and($reports->adoption(viewer: $viewer, days: 7, roleId: null)['features']['find_similar']['actors'])->toBe(5);

    $this->get(route('admin.dam.reports.adoption', ['role' => $small->id]))->assertOk()->assertSee(trans('dam::app.admin.analytics.suppressed'));
});

it('buckets adoption weeks from Monday and renders date-only labels in the app timezone', function () {
    $this->loginAsAdmin();
    $originalTimezone = config('app.timezone');
    $originalPhpTimezone = date_default_timezone_get();
    $originalLocale = app()->getLocale();

    try {
        $reports = app(AnalyticsReports::class);
        $viewer = auth('admin')->user();

        foreach (['America/New_York', 'UTC'] as $timezone) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
            $small = Role::factory()->create(['permission_type' => 'all']);
            $large = Role::factory()->create(['permission_type' => 'all']);
            $boundaryRole = Role::factory()->create(['permission_type' => 'all']);
            $sundayStaff = array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $small->id]), range(1, 2));
            $mondayStaff = array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $large->id]), range(1, 3));
            $boundaryStaff = array_map(fn () => Admin::factory()->create(['status' => 1, 'role_id' => $boundaryRole->id]), range(1, 3));

            // Ingest converts each client epoch to the receiver/app timezone before EventStore stores
            // the offset-free wall timestamp, so adoption weeks follow the app timezone at ingestion.
            $fixtures = [
                [$sundayStaff, '2026-10-11 12:00:00 UTC'],
                [$mondayStaff, '2026-10-12 12:00:00 UTC'],
                // 03:00 UTC is Monday in UTC but Sunday 22:00 in Chicago.
                [$boundaryStaff, '2026-10-12 03:00:00 UTC'],
            ];

            foreach ($fixtures as [$staff, $instant]) {
                foreach ($staff as $admin) {
                    Carbon::setTestNow(Carbon::parse($instant)->setTimezone($timezone));
                    $this->actingAs($admin, 'admin')
                        ->withHeaders(analyticsBrowserHeaders())
                        ->postJson(route('admin.dam.analytics.ingest'), [
                            't' => IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id),
                            'e' => [analyticsClientEvent('filter.applied', ['sid' => (string) Str::uuid(), 'p' => ['filter' => 'extension']])],
                        ])->assertNoContent();
                }
            }

            foreach (['en_US', 'en_GB'] as $locale) {
                config(['app.timezone' => $timezone, 'app.locale' => $locale]);
                app()->setLocale($locale);
                Carbon::setLocale($locale);
                Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00', $timezone));

                $all = $reports->adoption(viewer: $viewer, days: 7, roleId: null);
                $boundaryWeek = $timezone === 'UTC' ? '2026-10-12' : '2026-10-05';
                $boundaryWeeks = $reports->adoption(viewer: $viewer, days: 7, roleId: $boundaryRole->id)['weeks'];

                expect(array_keys($all['weeks']))->toBe(['2026-10-05', '2026-10-12'])
                    ->and($all['weeks']['2026-10-05']['filters'])->toBeGreaterThanOrEqual(2)
                    ->and($all['weeks']['2026-10-12']['filters'])->toBeGreaterThanOrEqual(3)
                    ->and($reports->adoption(viewer: $viewer, days: 7, roleId: $small->id)['weeks']['2026-10-05']['filters'])->toBeNull()
                    ->and(array_keys($boundaryWeeks))->toBe([$boundaryWeek])
                    ->and($boundaryWeeks[$boundaryWeek]['filters'])->toBe(3);

                $this->get(route('admin.dam.reports.adoption', ['days' => 7]))
                    ->assertOk()
                    ->assertSee('October 12, 2026')
                    ->assertSee('October 5, 2026');
            }
        }
    } finally {
        config(['app.timezone' => $originalTimezone, 'app.locale' => $originalLocale]);
        date_default_timezone_set($originalPhpTimezone);
        app()->setLocale($originalLocale);
        Carbon::setTestNow();
        Carbon::setLocale($originalLocale);
    }
});
