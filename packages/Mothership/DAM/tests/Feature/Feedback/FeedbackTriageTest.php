<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Mothership\DAM\Models\FeedbackNote;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DAM\Services\Feedback\FeedbackNotifier;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Settings → Feedback, the triage page: who may open it, its filters,
 * saving status and the issue link, notes, and the bell notifications a new report and a status change send.
 */

function triagePerson(array $permissions = ['dam', 'dam.asset.view'], string $type = 'user', string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => $type]);
}

function triageManager(): Admin
{
    return triagePerson(permissions: ['dam', 'dam.feedback.manage']);
}

function triageReport(Admin $reporter, array $overrides = []): FeedbackReport
{
    return FeedbackReport::query()->create($overrides + [
        'admin_id'    => $reporter->id,
        'type'        => 'bug',
        'severity'    => 'annoying',
        'title'       => 'Group by stack hides the New button',
        'description' => 'At phone width the New button is pushed off screen.',
        'status'      => 'new',
        'page_url'    => '/admin/dam',
        'route_name'  => 'admin.dam.index',
        'context'     => ['browser' => 'Chrome 141', 'console' => []],
    ]);
}

/** @return list<int> who was sent a notification of $type about $report */
function triageNotified(string $type, FeedbackReport $report): array
{
    return DB::table('user_notifications')
        ->join('notifications', 'notifications.id', '=', 'user_notifications.notification_id')
        ->where('notifications.type', $type)
        ->get(['user_notifications.admin_id', 'notifications.context'])
        ->filter(fn (object $row): bool => (json_decode((string) $row->context, true)['dam_feedback_report_id'] ?? null) === $report->id)
        ->map(fn (object $row): int => (int) $row->admin_id)
        ->sort()
        ->values()
        ->all();
}

function triageGrid(array $query = []): array
{
    return test()->getJson(route('admin.dam.feedback.index', $query), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();
}

beforeEach(function () {
    config(['dam.feedback.enabled' => true]);
    RateLimiter::clear('feedback');
});

it('renders the triage page for a manager and answers 404 while the feature is off', function () {
    $this->actingAs(triageManager(), 'admin');

    $this->get(route('admin.dam.feedback.index'))->assertOk()->assertSee('v-dam-feedback-triage', false);

    config(['dam.feedback.enabled' => false]);
    $this->get(route('admin.dam.feedback.index'))->assertNotFound();
});

it('lists every report newest first with the reporter and where it came from', function () {
    $alice = triagePerson();
    $bob = triagePerson();
    $older = triageReport($alice, ['title' => 'Older']);
    $newer = triageReport($bob, ['title' => 'Newer', 'type' => 'idea', 'severity' => null, 'route_name' => 'admin.home.index']);
    $this->actingAs(triageManager(), 'admin');

    $grid = triageGrid();
    $ids = array_column($grid['records'], 'id');

    expect(array_slice($ids, 0, 2))->toBe([$newer->id, $older->id])
        ->and($grid['records'][0]['reporter_name'])->toBe($bob->name)
        ->and($grid['records'][0]['where'])->toBe('Home')
        ->and($grid['records'][1]['where'])->toBe('Library')
        ->and(collect($grid['columns'])->firstWhere('index', 'reporter')['options']['params']['options'])
        ->toContain(['label' => $alice->name, 'value' => $alice->id]);
});

it('filters by status, type, reporter and severity', function () {
    $alice = triagePerson();
    $bob = triagePerson();
    $bug = triageReport($alice);
    $idea = triageReport($bob, ['type' => 'idea', 'severity' => null, 'status' => 'triaged']);
    $fixed = triageReport($alice, ['severity' => 'blocking', 'status' => 'fixed']);
    $this->actingAs(triageManager(), 'admin');

    $mine = [$bug->id, $idea->id, $fixed->id];
    $ids = fn (array $filters): array => collect(triageGrid(['filters' => $filters])['records'])->pluck('id')->intersect($mine)->sort()->values()->all();

    expect($ids(['status' => ['new', 'triaged', 'in_progress']]))->toBe([$bug->id, $idea->id])
        ->and($ids(['status' => ['fixed']]))->toBe([$fixed->id])
        ->and($ids(['type' => ['idea']]))->toBe([$idea->id])
        ->and($ids(['reporter' => [(string) $alice->id]]))->toBe([$bug->id, $fixed->id])
        ->and($ids(['severity' => ['blocking']]))->toBe([$fixed->id])
        ->and($ids(['reporter' => [(string) $alice->id], 'status' => ['new']]))->toBe([$bug->id]);
});

it('refuses the page to a signed-in person without the manage key, and to an API account', function () {
    triageReport(triagePerson());

    $this->actingAs(triagePerson(), 'admin');
    $this->get(route('admin.dam.feedback.index'))->assertForbidden();
    $this->getJson(route('admin.dam.feedback.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();

    $this->actingAs(triagePerson(permissions: [], type: 'api', permissionType: 'all'), 'admin');
    $this->getJson(route('admin.dam.feedback.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
});

it('lets a custom role triage only while it holds dam.feedback.manage', function () {
    $report = triageReport(triagePerson());
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.feedback.manage']]);
    $triager = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => 'user']);
    $this->actingAs($triager, 'admin');

    $this->getJson(route('admin.dam.feedback.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'triaged'])->assertOk();

    $role->update(['permissions' => ['dam']]);
    $triager->refresh();

    $this->getJson(route('admin.dam.feedback.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'fixed'])->assertForbidden();
    $this->getJson(route('admin.dam.feedback.show', $report->id))->assertNotFound();
    expect($report->refresh()->status)->toBe('triaged');
});

it('lets an all-permission administrator triage', function () {
    triageReport(triagePerson());
    $this->actingAs(triagePerson(permissions: [], permissionType: 'all'), 'admin');

    $this->getJson(route('admin.dam.feedback.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
});

it('changes the status with a status-change note and tells the reporter through the bell', function () {
    $reporter = triagePerson();
    $report = triageReport($reporter);
    $manager = triageManager();
    $this->actingAs($manager, 'admin');

    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('report.status', 'in_progress');

    $note = FeedbackNote::query()->where('report_id', $report->id)->sole();
    expect($note->kind)->toBe(FeedbackNote::KIND_STATUS)
        ->and($note->from_status)->toBe('new')
        ->and($note->to_status)->toBe('in_progress')
        ->and($note->admin_id)->toBe($manager->id)
        ->and(triageNotified(FeedbackNotifier::TYPE_STATUS, $report))->toBe([$reporter->id]);

    $notification = DB::table('notifications')->where('type', FeedbackNotifier::TYPE_STATUS)->latest('id')->first();
    expect($notification->route)->toBe('admin.dam.feedback.mine')
        ->and($notification->title)->toContain('#'.$report->id)->toContain('In progress');

    // Saving the same status again changes nothing and sends nothing.
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'in_progress'])->assertOk();
    expect(FeedbackNote::query()->where('report_id', $report->id)->count())->toBe(1)
        ->and(triageNotified(FeedbackNotifier::TYPE_STATUS, $report))->toBe([$reporter->id]);
});

it('does not notify a reporter who changed their own report status', function () {
    $manager = triageManager();
    $report = triageReport($manager);
    $this->actingAs($manager, 'admin');

    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'fixed'])->assertOk();

    expect(triageNotified(FeedbackNotifier::TYPE_STATUS, $report))->toBe([]);
});

it('sets and clears the GitHub issue URL and refuses anything that is not a github.com issue', function () {
    $report = triageReport(triagePerson());
    $this->actingAs(triageManager(), 'admin');

    $url = 'https://github.com/example-org/mothership/issues/412';
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['github_issue_url' => $url])
        ->assertOk()
        ->assertJsonPath('report.github_issue_url', $url)
        ->assertJsonPath('report.status', 'new');

    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['github_issue_url' => 'https://evil.example/issues/1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('github_issue_url');
    expect($report->refresh()->github_issue_url)->toBe($url);

    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['github_issue_url' => null])->assertOk();
    expect($report->refresh()->github_issue_url)->toBeNull();
});

it('posts notes, internal unless marked visible, and the reporter reads only the visible ones', function () {
    $reporter = triagePerson();
    $report = triageReport($reporter);
    $this->actingAs(triageManager(), 'admin');

    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'Repro at 390 px, the flex row has no wrap.'])
        ->assertCreated()
        ->assertJsonPath('report.notes.0.visible_to_reporter', false);
    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'Thanks, a fix is on the way.', 'visible_to_reporter' => true])
        ->assertCreated()
        ->assertJsonCount(2, 'report.notes');

    // The reporter reads their report, with its notes, on My reports.
    $this->actingAs($reporter, 'admin');
    $notes = $this->getJson(route('admin.dam.feedback.show', $report->id))->assertOk()->json('report.notes');

    expect(array_column($notes, 'body'))->toBe(['Thanks, a fix is on the way.']);
});

it('refuses notes and saves from someone who cannot triage', function () {
    $reporter = triagePerson();
    $report = triageReport($reporter);
    $this->actingAs($reporter, 'admin');

    $this->postJson(route('admin.dam.feedback.notes.store', $report->id), ['body' => 'I can triage myself?'])->assertForbidden();
    $this->patchJson(route('admin.dam.feedback.update', $report->id), ['status' => 'fixed'])->assertForbidden();
    expect(FeedbackNote::query()->where('report_id', $report->id)->count())->toBe(0);
});

it('tells everyone who may triage about a new report, never the reporter or an API account', function () {
    $managerA = triageManager();
    $managerB = triagePerson(permissions: [], permissionType: 'all');
    $bystander = triagePerson();
    $robot = triagePerson(permissions: [], type: 'api', permissionType: 'all');
    $reporterManager = triageManager();
    $this->actingAs($reporterManager, 'admin');

    $id = $this->postJson(route('admin.dam.feedback.store'), [
        'type'        => 'bug',
        'severity'    => 'minor',
        'title'       => 'Dark mode toggle flickers',
        'description' => 'It flashes white first.',
    ])->assertCreated()->json('report.id');

    $notified = triageNotified(FeedbackNotifier::TYPE_CREATED, FeedbackReport::query()->findOrFail($id));

    expect($notified)->toContain($managerA->id, $managerB->id)
        ->not->toContain($bystander->id)
        ->not->toContain($robot->id)
        ->not->toContain($reporterManager->id);

    $notification = DB::table('notifications')->where('type', FeedbackNotifier::TYPE_CREATED)->latest('id')->first();
    expect($notification->route)->toBe('admin.dam.feedback.index')
        ->and(json_decode((string) $notification->route_params, true))->toBe(['report' => $id]);
});

it('opens the drawer from a notification link', function () {
    $report = triageReport(triagePerson());
    $this->actingAs(triageManager(), 'admin');

    $this->get(route('admin.dam.feedback.index', ['report' => $report->id]))->assertOk()->assertSee('const fromUrl = '.$report->id.';', false);
});
