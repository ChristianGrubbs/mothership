<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Services\Security\SecurityReports;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/** P1a: the two staff reports sit behind dam.reports.security, which Sandbox defaults never grant. */
it('answers 403 on both reports to a role holding every other permission', function () {
    $everythingElse = collect(config('acl'))->pluck('key')->unique()
        ->reject(fn (string $key) => str_starts_with($key, 'dam.reports'))->values()->all();
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $everythingElse])->id]);

    $this->actingAs($member, 'admin');

    $this->get(route('admin.dam.reports.login-access'))->assertForbidden();
    $this->get(route('admin.dam.reports.operational-health'))->assertForbidden();
});

it('never grants the reports key through Use Sandbox defaults', function () {
    $this->loginAsAdmin();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);

    // "Use Sandbox defaults" ticks every capability the Roles editor lists, which is exactly this set.
    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                     => 'Sandbox',
        'permission_type'          => 'custom',
        'permissions'              => [],
        'dam_role_builder_managed' => '1',
        'dam_capabilities'         => array_keys(RoleBuilder::CAPABILITIES),
        'dam_home_widgets'         => [],
    ])->assertRedirect();

    expect($role->fresh()->permissions)->not->toBeEmpty()->not->toContain('dam.reports')->not->toContain('dam.reports.security');
});

it('shows a full admin both reports by ids and hashes only', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create(['file_name' => 'board-minutes-confidential.pdf']);
    $optimization = (string) Str::uuid();
    DB::table('dam_optimizations')->insert([
        'id'              => $optimization, 'asset_id' => $asset->id, 'admin_id' => auth('admin')->id(), 'request_id' => (string) Str::uuid(),
        'type'            => 'pdf', 'options' => '{}', 'source_sha256' => str_repeat('b', 64), 'status' => 'failed', 'error' => 'clop_failed',
        'http_request_id' => 'failed-optimize-trace', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $hash = SecurityEventRecorder::identifierHash(identifier: 'someone@example.test');
    DB::table(SecurityEventRecorder::TABLE)->insert([
        ['occurred_at' => now(), 'event' => SecurityEvent::LOGIN_FAILED, 'identifier_hash' => $hash, 'ctx' => '{"account_exists":false}'],
        ['occurred_at' => now(), 'event' => SecurityEvent::LOGIN_SUCCEEDED, 'identifier_hash' => null, 'ctx' => null],
    ]);

    $this->get(route('admin.dam.reports.login-access', ['days' => 7]))->assertOk()
        ->assertSee(substr($hash, 0, 8))
        ->assertDontSee($hash)
        ->assertDontSee('someone@example.test');

    $this->get(route('admin.dam.reports.operational-health', ['days' => 7]))->assertOk()
        ->assertSee('failed-optimize-trace')
        ->assertSee('dam_optimizations#'.$optimization)
        ->assertSee('clop_failed')
        ->assertDontSee('board-minutes-confidential');

    expect(DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::REPORT_VIEWED)->count())->toBe(2);
});

/**
 * One failed row in each status table for $assetId, request id "{$label}-{table}". A null $assetId writes only the
 * three tables that hold work not yet tied to a file (a vectorization of an upload, an upload batch, a chunk).
 *
 * @return list<string> the "table#id" of every row written
 */
function reportFailedWork(?int $assetId, string $label, int $trackerId, int $directoryId): array
{
    $written = [];
    $at = ['created_at' => now(), 'updated_at' => now()];

    if ($assetId !== null) {
        $id = (string) Str::uuid();
        DB::table('dam_optimizations')->insert($at + ['id' => $id, 'asset_id' => $assetId, 'admin_id' => 1, 'request_id' => (string) Str::uuid(),
            'type'                                         => 'pdf', 'options' => '{}', 'source_sha256' => str_repeat('c', 64), 'status' => 'failed', 'error' => 'clop_failed', 'http_request_id' => "{$label}-opt"]);
        $written[] = "dam_optimizations#{$id}";
        $written[] = 'dam_renditions#'.DB::table('dam_renditions')->insertGetId($at + ['dam_asset_id' => $assetId, 'recipe' => 'webp', 'status' => 'failed', 'http_request_id' => "{$label}-ren"]);
        DB::table('dam_ai_index')->where('asset_id', $assetId)->delete();
        $written[] = 'dam_ai_index#'.DB::table('dam_ai_index')->insertGetId($at + ['asset_id' => $assetId, 'status' => 'failed', 'http_request_id' => "{$label}-ai"]);
    }

    $id = (string) Str::uuid();
    DB::table('dam_vectorizations')->insert($at + ['id' => $id, 'admin_id' => 1, 'request_id' => (string) Str::uuid(), 'input_sha' => str_repeat('d', 64),
        'input_path'                                    => 'vectorize/in.png', 'charged' => false, 'status' => 'failed', 'error_code' => 'trace_failed', 'source_asset_id' => $assetId, 'http_request_id' => "{$label}-vec"]);
    $written[] = "dam_vectorizations#{$id}";
    $written[] = 'dam_upload_batches#'.DB::table('dam_upload_batches')->insertGetId($at + ['upload_tracker_id' => $trackerId, 'asset_id' => $assetId, 'state' => 'failed', 'http_request_id' => "{$label}-bat"]);
    $written[] = 'dam_chunk_uploads#'.DB::table('dam_chunk_uploads')->insertGetId($at + ['uuid' => (string) Str::uuid(), 'upload_tracker_id' => $trackerId, 'user_id' => 1,
        'directory_id'                                                                          => $directoryId, 'original_name' => 'chunk.bin', 'expected_bytes' => 1, 'source_modified_ms' => 1, 'source_fingerprint' => 'f', 'chunk_bytes' => 1,
        'chunk_count'                                                                           => 1, 'state' => 'failed', 'staged_root' => 'chunks/x', 'expires_at' => now()->addDay(), 'asset_id' => $assetId, 'failure_code' => 'assembly_failed',
        'http_request_id'                                                                       => "{$label}-chk"]);

    return $written;
}

/*
 * §3.6.8: operational health counts and lists only work on files the viewer can open, under either access model,
 * through every status table. One fixture: a file reachable each way the model allows, one nobody grants the
 * viewer, and work tied to no file; three viewers: a custom role, an all_directories role, a full admin.
 */
it('counts and lists only work on files the viewer can open', function (string $model, array $expected) {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();

    $reportRole = fn () => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.reports', 'dam.reports.security']]);
    $custom = Admin::factory()->create(['role_id' => $reportRole()->id, 'status' => 1]);
    $allDirectories = Admin::factory()->create(['role_id' => $reportRole()->id, 'status' => 1]);
    DB::table('dam_role_settings')->insert(['role_id' => $allDirectories->role_id, 'all_directories' => true]);
    $viewers = ['custom' => $custom, 'all_directories' => $allDirectories, 'full' => Admin::factory()->create(['status' => 1])];
    $stranger = Admin::factory()->create();

    $granted = Directory::create(['name' => 'P1a granted folder', 'parent_id' => null]);
    $elsewhere = Directory::create(['name' => 'P1a other folder', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert(['role_id' => $custom->role_id, 'directory_id' => $granted->id]);
    $group = DB::table('dam_groups')->insertGetId(['name' => 'P1a report group', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $custom->id]);
    $everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');

    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => $stranger->id, 'directory_id' => $elsewhere->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $file = function (Directory $folder, ?int $owner, array $users = [], array $groups = []) {
        $asset = Asset::factory()->create();
        DB::table('dam_assets')->where('id', $asset->id)->update(['created_by' => $owner]);
        DB::table('dam_asset_directory')->where('asset_id', $asset->id)->delete();
        DB::table('dam_asset_directory')->insert(['asset_id' => $asset->id, 'directory_id' => $folder->id]);
        DB::table('dam_asset_user_access')->where('asset_id', $asset->id)->delete();
        DB::table('dam_asset_group_access')->where('asset_id', $asset->id)->delete();
        DB::table('dam_asset_user_access')->insert(array_map(fn ($id) => ['asset_id' => $asset->id, 'admin_id' => $id], $users));
        DB::table('dam_asset_group_access')->insert(array_map(fn ($id) => ['asset_id' => $asset->id, 'group_id' => $id], $groups));

        return $asset->id;
    };
    // Each file is reached one way only: grants through its folder, lists through its own list or owner.
    $assets = [
        'trace-granted'  => $file($granted, $stranger->id),
        'trace-owned'    => $file($elsewhere, $custom->id),
        'trace-user'     => $file($elsewhere, $stranger->id, users: [$custom->id]),
        'trace-group'    => $file($elsewhere, $stranger->id, groups: [$group]),
        'trace-everyone' => $file($elsewhere, $stranger->id, groups: [$everyone]),
        'trace-hidden'   => $file($elsewhere, $stranger->id),
        'trace-unlinked' => null,
    ];
    $rows = [];
    foreach ($assets as $label => $assetId) {
        $rows[$label] = reportFailedWork(assetId: $assetId, label: $label, trackerId: $tracker->id, directoryId: $elsewhere->id);
    }

    foreach ($viewers as $name => $viewer) {
        $visible = $expected[$name] === '*' ? array_keys($assets) : $expected[$name];
        $report = app(SecurityReports::class)->operationalHealth(days: 7, viewer: $viewer);

        $shown = collect($report['failures'])->map(fn (array $row) => $row['table'].'#'.$row['id'])->sort()->values()->all();
        $failedCounts = collect($report['statuses'])->filter(fn (array $row) => $row['status'] === 'failed')
            ->groupBy('table')->map(fn ($group) => $group->sum('count'))->sortKeys()->all();
        $wanted = collect($visible)->flatMap(fn (string $label) => $rows[$label]);

        expect($shown)->toBe($wanted->sort()->values()->all(), "{$model}: {$name} sees exactly these rows")
            ->and($failedCounts)->toBe($wanted->countBy(fn (string $row) => strstr($row, '#', true))->sortKeys()->all(), "{$model}: {$name} counts");

        $page = $this->actingAs($viewer, 'admin')->get(route('admin.dam.reports.operational-health', ['days' => 7]))->assertOk();
        foreach (array_keys($assets) as $label) {
            in_array($label, $visible, true) ? $page->assertSee($label.'-') : $page->assertDontSee($label.'-');
        }
    }
})->with([
    'grants' => [AccessModel::GRANTS, ['custom' => ['trace-granted'], 'all_directories' => '*', 'full' => '*']],
    // Lists: all_directories no longer bypasses (the rebuild turns it into a group), so only Everyone reaches it.
    'lists'  => [AccessModel::LISTS, ['custom' => ['trace-owned', 'trace-user', 'trace-group', 'trace-everyone'], 'all_directories' => ['trace-everyone'], 'full' => '*']],
]);
