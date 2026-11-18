<?php

// The Home calendar (docs/features/event-calendar.md): entries are added here by hand, tasks can be unassigned,
// given or claimed, and a linked asset follows the Library's access.

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CalendarEvent;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->calendarAdmin = Admin::factory()->create();
    $this->actingAs($this->calendarAdmin, 'admin');
});

function calendarManualEvent(array $overrides = []): CalendarEvent
{
    return CalendarEvent::query()->create($overrides + [
        'source' => 'manual', 'kind' => 'event', 'title' => 'Gallery opening', 'starts_at' => '2026-11-09 10:00:00',
    ]);
}

it('adds a manual event and lists it in a range with its counts', function () {
    $id = $this->postJson('/admin/home/calendar/events', ['title' => 'Gallery opening', 'starts_at' => '2026-11-09T10:00', 'ends_at' => '2026-11-09T11:00', 'location' => 'Main gallery'])
        ->assertCreated()->assertJsonPath('event.source', 'manual')->assertJsonPath('event.starts_at', '2026-11-09T10:00')->json('event.id');
    $this->postJson("/admin/home/calendar/events/{$id}/tasks", ['title' => 'Photos', 'role' => 'Shooting'])->assertCreated();

    $this->getJson('/admin/home/calendar/events?from=2026-11-01&to=2026-12-01')->assertOk()
        ->assertJsonCount(1, 'events')->assertJsonPath('events.0.tasks', 1)->assertJsonPath('events.0.unassigned', 1);
    $this->getJson('/admin/home/calendar/events?from=2026-12-01&to=2027-01-01')->assertOk()->assertJsonCount(0, 'events');
});

it('lets a task sit unassigned, be claimed, be given to someone else and be finished', function () {
    $event = calendarManualEvent(['starts_at' => now()->addDays(3)->format('Y-m-d H:i:s')]);
    $other = Admin::factory()->create();
    $base = "/admin/home/calendar/events/{$event->id}/tasks";

    $taskId = $this->postJson($base, ['title' => 'Graphics', 'role' => 'Designing'])->assertCreated()->assertJsonPath('tasks.0.assignee', null)->json('tasks.0.id');
    $subId = $this->postJson($base, ['title' => 'Social square', 'parent_id' => $taskId])->assertCreated()->json('tasks.1.id');
    // One level only: a sub-item takes no sub-items.
    $this->postJson($base, ['title' => 'Too deep', 'parent_id' => $subId])->assertUnprocessable();

    $this->patchJson("/admin/home/calendar/tasks/{$taskId}", ['assignee_id' => $this->calendarAdmin->id])->assertOk()->assertJsonPath('tasks.0.assignee.id', $this->calendarAdmin->id);
    $this->getJson('/admin/home/calendar/mine')->assertOk()->assertJsonCount(1, 'mine')->assertJsonCount(1, 'unassigned');

    $this->patchJson("/admin/home/calendar/tasks/{$taskId}", ['assignee_id' => $other->id, 'status' => 'done'])->assertOk()->assertJsonPath('tasks.0.assignee.id', $other->id);
    $this->patchJson("/admin/home/calendar/tasks/{$taskId}", ['assignee_id' => null])->assertOk()->assertJsonPath('tasks.0.assignee', null);
    $this->getJson('/admin/home/calendar/mine')->assertOk()->assertJsonCount(0, 'mine');

    // Deleting a task takes its sub-items with it.
    $this->deleteJson("/admin/home/calendar/tasks/{$taskId}")->assertOk()->assertJsonCount(0, 'tasks');
});

it('links assets to an event or a sub-item once, and keeps them on the event when the task goes', function () {
    $event = calendarManualEvent();
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create());
    $taskId = $this->postJson("/admin/home/calendar/events/{$event->id}/tasks", ['title' => 'Photos'])->json('tasks.0.id');
    $link = "/admin/home/calendar/events/{$event->id}/assets";

    $this->postJson($link, ['asset_ids' => [$asset->id], 'task_id' => $taskId])->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.task_id', $taskId);
    $this->postJson($link, ['asset_ids' => [$asset->id], 'task_id' => $taskId])->assertOk()->assertJsonCount(1, 'assets');
    $this->getJson('/admin/home/calendar/events?from=2026-11-01&to=2026-12-01')->assertJsonPath('events.0.assets', 1);

    $this->deleteJson("/admin/home/calendar/tasks/{$taskId}")->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.task_id', null);
    $linkId = DB::table('dam_calendar_assets')->value('id');
    $this->deleteJson("/admin/home/calendar/assets/{$linkId}")->assertOk()->assertJsonCount(0, 'assets');
});

it('never links, lists or unlinks an asset outside the account folder grants', function () {
    $event = calendarManualEvent();
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(2)->create());
    $assets[0]->directories()->attach($allowed->id);
    $assets[1]->directories()->attach($private->id);
    // The full-access account links both.
    $this->postJson("/admin/home/calendar/events/{$event->id}/assets", ['asset_ids' => [$assets[0]->id, $assets[1]->id]])->assertOk()->assertJsonCount(2, 'assets');
    $privateLink = DB::table('dam_calendar_assets')->where('asset_id', $assets[1]->id)->value('id');

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $role->id], ['home_widget_ids' => json_encode(['calendar']), 'delete_own_only' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson("/admin/home/calendar/events/{$event->id}")->assertOk()->assertJsonCount(1, 'assets')
        ->assertJsonPath('assets.0.id', $assets[0]->id)->assertJsonPath('hidden_assets', 1);
    $this->deleteJson("/admin/home/calendar/assets/{$privateLink}")->assertNotFound();
    $this->getJson(route('admin.home.calendar.events.show', $event->id))->assertOk()->assertJsonMissing(['id' => $assets[1]->id]);
    $this->getJson(route('admin.home.calendar.assets.search'))->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[0]->id);
    // The grid counts only what this person may list.
    $this->getJson(route('admin.home.calendar.events', ['from' => '2026-11-01', 'to' => '2026-12-01']))->assertOk()->assertJsonPath('events.0.assets', 1);

    $other = calendarManualEvent(['title' => 'Second']);
    $this->postJson("/admin/home/calendar/events/{$other->id}/assets", ['asset_ids' => [$assets[1]->id]])->assertOk()->assertJsonCount(0, 'assets');
});

it('edits an entry, and deletes it only for its author', function () {
    $manual = calendarManualEvent(['created_by' => $this->calendarAdmin->id]);
    $this->postJson("/admin/home/calendar/events/{$manual->id}/tasks", ['title' => 'Photos'])->assertCreated();
    $this->patchJson("/admin/home/calendar/events/{$manual->id}", ['kind' => 'campaign', 'ends_at' => '2026-11-20T00:00'])->assertOk()->assertJsonPath('event.kind', 'campaign');

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $role->id], ['home_widget_ids' => json_encode(['calendar']), 'delete_own_only' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->deleteJson("/admin/home/calendar/events/{$manual->id}")->assertForbidden();

    $this->actingAs($this->calendarAdmin, 'admin');
    $this->deleteJson("/admin/home/calendar/events/{$manual->id}")->assertOk();
});

it('refuses a role without the calendar widget', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $role->id], ['home_widget_ids' => json_encode(['recent']), 'delete_own_only' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->getJson('/admin/home/calendar/events?from=2026-11-01&to=2026-12-01')->assertForbidden();
    $this->postJson('/admin/home/calendar/events', ['title' => 'No', 'starts_at' => '2026-11-09T10:00'])->assertForbidden();
});
