<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\AnalyticsReports;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Analytics\Rollups;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Optimization\Requests as Optimizations;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Requests as Vectorizations;
use Mothership\User\Models\Admin;

/*
 * P1b counting (§3.6.4): the rollup is exact and idempotent, a refresh or a re-download inside its window is one,
 * a retry is an attempt of the same operation, and every job has one terminal event.
 */

/** @return array<string, int> one rollup slice's figures, summed across its rows */
function analyticsRollupFigures(string $name): array
{
    $row = DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_ROLLUP)->where('name', $name)
        ->selectRaw('SUM(events) AS events, SUM(counted) AS counted, SUM(actors) AS actors, SUM(bots) AS bots')->first();

    return ['events' => (int) $row->events, 'counted' => (int) $row->counted, 'actors' => (int) $row->actors, 'bots' => (int) $row->bots];
}

it('rolls a known sequence up to exact figures, the same on every re-run', function () {
    $asset = Asset::factory()->create();
    [$first, $second] = [Admin::factory()->create(['status' => 1]), Admin::factory()->create(['status' => 1])];
    $actor = fn (Admin $admin) => new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id, roleId: (int) $admin->role_id);
    $download = fn (Admin $admin) => DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct'], actor: $actor($admin));
    $start = now()->startOfDay()->addHours(9);

    // First: 0, +5 (inside 10 minutes of 0), +16 (11 after +5): two downloads. Second: one. A bot: none.
    $served = [];
    foreach ([[$first, 0], [$first, 5], [$second, 7], [$first, 16]] as [$admin, $minutes]) {
        $this->travelTo($start->copy()->addMinutes($minutes));
        $served[] = $download($admin);
    }
    DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $asset->id, subjectType: 'asset', props: ['via' => 'direct'],
        actor: new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $first->id, suspectedBot: true));
    $this->travelBack();

    expect($served)->toBe([DamEvents::COUNTED, DamEvents::REPEAT, DamEvents::COUNTED, DamEvents::COUNTED]);

    $rollups = app(Rollups::class);
    $rollups->day(day: $start);
    $once = DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_ROLLUP)->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('id')->all())->all();
    expect(analyticsRollupFigures('asset.download.served'))->toBe(['events' => 4, 'counted' => 3, 'actors' => 2, 'bots' => 1]);

    $rollups->day(day: $start);
    $rollups->range(from: $start->copy()->subDays(2), to: $start->copy()->addDay());
    $again = DB::table(EventStore::COUNTS)->where('source', EventStore::SOURCE_ROLLUP)->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('id')->all())->all();

    expect($again)->toBe($once)
        ->and($this->artisan('dam:analytics:rollup')->run())->toBe(0)
        ->and(analyticsRollupFigures('asset.download.served'))->toBe(['events' => 4, 'counted' => 3, 'actors' => 2, 'bots' => 1]);

    $this->loginAsAdmin();
    expect(app(AnalyticsReports::class)->engagement(viewer: auth('admin')->user(), days: 7)['totals']['downloads'])->toBe(3);
});

it('counts a refreshed link page as one visit, in the analytics and in the link\'s own view count', function () {
    $share = analyticsSharedFile();
    $this->withHeaders(analyticsBrowserHeaders());
    // A fixed mid-morning start: on the wall clock, the 31-minute step crossed midnight after 23:29 UTC and the
    // day's rollup missed the later visit.
    $day = now()->startOfDay()->addHours(9);
    $this->travelTo($day);

    shareVisitRan($this->get(route('dam.share.show', $share->urlToken()))->assertOk());
    shareVisitRan($this->get(route('dam.share.show', $share->urlToken()))->assertOk());
    $this->call('HEAD', route('dam.share.show', $share->urlToken()));
    expect((int) $share->fresh()->view_count)->toBe(1);

    $this->travel(31)->minutes();
    shareVisitRan($this->get(route('dam.share.show', $share->urlToken()))->assertOk());

    app(Rollups::class)->day(day: $day);
    expect((int) $share->fresh()->view_count)->toBe(2)
        ->and(analyticsRollupFigures('share.link.visited'))->toMatchArray(['events' => 3, 'counted' => 2, 'actors' => 1]);

    // A crawler's visit is kept apart from every analytics figure, and one that never runs the page is no view.
    $this->flushHeaders()->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->get(route('dam.share.show', $share->urlToken()));
    app(Rollups::class)->day(day: $day);
    $this->travelBack();
    expect((int) $share->fresh()->view_count)->toBe(2)
        ->and(analyticsRollupFigures('share.link.visited'))->toMatchArray(['events' => 3, 'counted' => 2, 'actors' => 1, 'bots' => 1]);
});

it('stores a link unfurler\'s or headless scanner\'s visit as a suspected bot, and a browser\'s as a visit', function () {
    $share = analyticsSharedFile();
    $agents = [
        'SkypeUriPreview Preview/0.5 skype-url-preview@microsoft.com'                                                                => true,
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'                                                  => true,
        'WhatsApp/2.23.20.0 A'                                                                                                       => true,
        // Chrome ended Windows 7 at 109: a newer one there is a scanner.
        'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.7339.0 Safari/537.36'          => true,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'            => false,
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15'      => false,
        'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:115.0) Gecko/20100101 Firefox/115.0'                                            => false,
    ];

    foreach ($agents as $agent => $bot) {
        DB::table(EventStore::TABLE)->delete();
        $this->flushHeaders()->withHeaders(['User-Agent' => $agent])->get(route('dam.share.show', $share->urlToken()))->assertOk();

        expect(DB::table(EventStore::TABLE)->where('name', 'share.link.visited')->pluck('suspected_bot')->map(fn ($flag) => (bool) $flag)->all())
            ->toBe([$bot], $agent);
    }
});

it('counts a re-download inside ten minutes once, and never a HEAD, a resumed range or an inline view', function () {
    $share = analyticsSharedFile();
    $this->withHeaders(analyticsBrowserHeaders());
    $download = route('dam.share.download', $share->urlToken());
    // A fixed mid-morning start, as above: the 11-minute step crossed midnight after 23:49 UTC.
    $day = now()->startOfDay()->addHours(9);
    $this->travelTo($day);

    $this->get($download)->assertOk();
    $this->get($download)->assertOk();
    $this->call('HEAD', $download);
    $this->withHeaders(['Range' => 'bytes=4-'])->get($download);
    $this->flushHeaders()->withHeaders(analyticsBrowserHeaders());
    $this->get(route('dam.share.download', ['token' => $share->urlToken(), 'disposition' => 'inline']))->assertOk();

    expect((int) $share->fresh()->download_count)->toBe(1)
        ->and(DB::table(EventStore::TABLE)->where('name', 'asset.download.served')->count())->toBe(2);

    $this->travel(11)->minutes();
    $this->get($download)->assertOk();

    app(Rollups::class)->day(day: $day);
    $this->travelBack();
    expect((int) $share->fresh()->download_count)->toBe(2)
        ->and(analyticsRollupFigures('asset.download.served'))->toMatchArray(['events' => 3, 'counted' => 2]);
});

it('keeps an upload\'s retries as attempts of one upload with one terminal outcome', function () {
    $admin = Admin::factory()->create(['status' => 1]);
    $actor = new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id, roleId: (int) $admin->role_id);
    $folder = Directory::factory()->create();
    $upload = Str::random(24);
    $emit = fn (DamEvent $event, array $props = [], ?string $attempt = null) => DamEvents::emit(
        event: $event, subjectId: $folder->id, props: ['upload_id' => $upload, 'path' => 'chunked'] + $props, actor: $actor, businessId: $upload, attempt: $attempt,
    );

    $emit(DamEvent::UploadAttempted, ['attempt' => 1], '1');
    $emit(DamEvent::UploadAttempted, ['attempt' => 1], '1'); // the same attempt reported twice
    $emit(DamEvent::UploadFailed, ['error_code' => 'storage_unavailable']);
    $emit(DamEvent::UploadAttempted, ['attempt' => 2], '2');
    $emit(DamEvent::UploadSucceeded);
    $emit(DamEvent::UploadFailed, ['error_code' => 'late_duplicate']); // a failure reported after the success

    $rows = DB::table(EventStore::TABLE)->where('props', 'like', '%'.$upload.'%')->orderBy('id')->get(['name', 'outcome']);
    expect($rows->where('outcome', 'attempt')->count())->toBe(2)
        ->and($rows->whereIn('outcome', ['success', 'failure'])->pluck('name')->all())->toBe(['upload.file.succeeded']);

    $this->loginAsAdmin();
    expect(app(AnalyticsReports::class)->friction(viewer: auth('admin')->user(), days: 7)['upload'])
        ->toMatchArray(['uploads' => 1, 'attempts' => 2, 'succeeded' => 1, 'failed' => 0, 'completion' => 100]);
});

it('records one terminal event per optimization and vectorization job, however often it is finished', function () {
    Queue::fake();
    $admin = Admin::factory()->create(['status' => 1]);
    $asset = Asset::factory()->create();

    $optimization = app(Optimizations::class)->submit(adminId: $admin->id, requestId: (string) Str::uuid(), assetId: $asset->id, type: 'compress', options: [], sourceSha256: hash('sha256', 'source'));
    DB::table('dam_optimizations')->where('id', $optimization->id)->update(['status' => 'processing']);
    app(Optimizations::class)->finish(id: $optimization->id, success: false, error: 'source changed!'); // free text is reduced to a safe code
    app(Optimizations::class)->finish(id: $optimization->id, success: false, error: 'source changed!');

    app(Allowance::class)->set(adminId: $admin->id, remaining: 1, revision: 0);
    $vectorization = app(Vectorizations::class)->submit(adminId: $admin->id, requestId: (string) Str::uuid(), inputPath: 'input/a', sha: hash('sha256', 'a'), assetId: $asset->id);
    app(Vectorizations::class)->finish(id: $vectorization->id, success: false, code: 'provider_unavailable');
    app(Vectorizations::class)->finish(id: $vectorization->id, success: false, code: 'provider_unavailable');

    $terminals = DB::table(EventStore::TABLE)->where('name', 'like', 'processing.%')->orderBy('id')->get(['name', 'subject_id', 'actor_key', 'props']);
    expect($terminals->pluck('name')->all())->toBe(['processing.optimization.failed', 'processing.vectorization.failed'])
        ->and($terminals->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([$asset->id])
        ->and($terminals->pluck('actor_key')->unique()->all())->toBe([(string) $admin->id])
        ->and(json_decode($terminals[0]->props, true)['error_code'])->toBe('source_changed_')
        ->and(json_decode($terminals[1]->props, true)['error_code'])->toBe('provider_unavailable');
});

it('records a library search once, on its first page', function () {
    $this->loginAsAdmin();
    $folder = Directory::factory()->create();
    $this->withHeaders(analyticsBrowserHeaders());

    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id, 'search' => 'harbour']))->assertOk();
    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id, 'search' => 'harbour', 'page' => 2]))->assertOk();
    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id]))->assertOk();

    $served = DB::table(EventStore::TABLE)->where('name', 'search.served')->get(['surface', 'props']);
    expect($served)->toHaveCount(1)
        ->and($served[0]->surface)->toBe('library')
        ->and(json_decode($served[0]->props, true))->toMatchArray(['result_count' => 0, 'smart' => false, 'similar' => false, 'scope' => 'directory', 'filtered' => false]);
});
