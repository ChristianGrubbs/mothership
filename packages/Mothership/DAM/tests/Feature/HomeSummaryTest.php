<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Home\HomeVisits;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/** Puts the library on a remote store whose capacity file holds these figures; null leaves no file. */
function homeRemoteStore(?array $capacity): void
{
    $path = sys_get_temp_dir().'/home-store-capacity-'.Str::uuid().'.json';
    config([
        'filesystems.disks.'.Directory::getAssetDisk().'.driver' => 's3',
        'dam-home.store_capacity.path'                           => $path,
    ]);

    if ($capacity !== null) {
        file_put_contents($path, json_encode($capacity, JSON_THROW_ON_ERROR));
        test()->beforeApplicationDestroyed(fn () => @unlink($path));
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->homeAdmin = Admin::factory()->create();
    $this->actingAs($this->homeAdmin, 'admin');
});

/** Makes this person's last visit the given number of hours ago. */
function homeVisitedHoursAgo(Admin $admin, int $hours): void
{
    DB::table('dam_home_visits')->insert([
        'admin_id'          => $admin->id,
        'previous_visit_at' => now()->subHours($hours),
        'last_visit_at'     => now()->subMinutes(1),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/** @param  array<string, mixed>  $attributes */
function homeFile(CarbonImmutable $at, array $attributes = []): Asset
{
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create($attributes));
    DB::table('dam_assets')->where('id', $asset->id)->update(['created_at' => $at, 'updated_at' => $at]);

    return $asset;
}

function homeCraftSave(Asset $asset, Admin $admin, string $producer): void
{
    DB::table('dam_creative_hub_saves')->insert([
        'admin_id'       => $admin->id,
        'request_id'     => (string) Str::uuid(),
        'request_sha256' => str_repeat('a', 64),
        'asset_id'       => $asset->id,
        'receipt'        => '{}',
        'evidence'       => json_encode(['producer' => $producer]),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

/** @param  array<string, mixed>  $attributes */
function homeTracker(Admin $admin, array $attributes): UploadTracker
{
    return UploadTracker::create([
        'uuid'    => (string) Str::uuid(),
        'user_id' => $admin->id,
        ...$attributes,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function homeChunkUpload(Admin $admin, array $attributes): ChunkUpload
{
    return ChunkUpload::create([
        'uuid'               => (string) Str::uuid(),
        'user_id'            => $admin->id,
        'directory_id'       => Directory::factory()->create()->id,
        'original_name'      => 'clip.mov',
        'expected_bytes'     => 1000,
        'source_modified_ms' => 1,
        'source_fingerprint' => str_repeat('b', 64),
        'chunk_bytes'        => 100,
        'chunk_count'        => 10,
        'staged_root'        => 'staging/'.Str::random(8),
        'expires_at'         => now()->addDay(),
        ...$attributes,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function homeUploadRequest(Admin $admin, array $attributes): UploadRequest
{
    return UploadRequest::create([
        'title'           => 'Send files',
        'directory_id'    => Directory::factory()->create()->id,
        'token_hash'      => hash('sha256', Str::random(40)),
        'token_encrypted' => 'sealed',
        'created_by'      => $admin->id,
        'status'          => UploadRequest::STATUS_OPEN,
        ...$attributes,
    ]);
}

it('shows the first run lead before any file or craft has arrived', function () {
    $response = $this->getJson('/admin/home/summary')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertJsonPath('lead.state', 'first-run')
        ->assertJsonPath('lead.files', 0)
        ->assertJsonPath('lead.craft_docked', false)
        ->assertJsonPath('board.rows', []);
});

it('shows nothing new when every file arrived before the last visit', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    homeFile(now()->subHours(3)->toImmutable());

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('lead.state', 'nothing-new')
        ->assertJsonPath('lead.files', 0)
        ->assertJsonPath('board.rows.0.status', 'seen');
});

it('reports new files whose lead and landing figures add up', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    homeFile(now()->subMinutes(30)->toImmutable(), ['file_size' => 1000, 'created_by' => $this->homeAdmin->id]);
    homeFile(now()->subMinutes(28)->toImmutable(), ['file_size' => 2000, 'created_by' => $this->homeAdmin->id]);
    homeFile(now()->subHours(5)->toImmutable(), ['file_size' => 9000, 'created_by' => $this->homeAdmin->id]);

    $summary = $this->getJson('/admin/home/summary')->assertOk()->json();
    $landed = collect($summary['board']['rows'])->where('status', 'landed');

    expect($summary['lead']['state'])->toBe('new')
        ->and($summary['lead']['files'])->toBe(2)
        ->and($summary['lead']['bytes'])->toBe(3000)
        ->and($landed)->toHaveCount(1)
        ->and($landed->sum('files'))->toBe($summary['lead']['files'])
        ->and($landed->sum('bytes'))->toBe($summary['lead']['bytes']);
});

it('groups a craft save by producer and keeps new and seen files apart', function () {
    homeVisitedHoursAgo($this->homeAdmin, 1);
    foreach ([20, 18] as $minutes) {
        homeCraftSave(homeFile(now()->subMinutes($minutes)->toImmutable(), ['created_by' => $this->homeAdmin->id]), $this->homeAdmin, 'PhotoCraft');
    }
    homeCraftSave(homeFile(now()->subMinutes(65)->toImmutable(), ['created_by' => $this->homeAdmin->id]), $this->homeAdmin, 'PhotoCraft');

    $rows = collect($this->getJson('/admin/home/summary')->assertOk()->json('board.rows'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['status' => 'landed', 'source' => 'craft', 'producer' => 'PhotoCraft', 'files' => 2])
        ->and($rows[1])->toMatchArray(['status' => 'seen', 'producer' => 'PhotoCraft', 'files' => 1]);
});

it('splits files that arrive further apart than the landing gap', function () {
    homeVisitedHoursAgo($this->homeAdmin, 3);
    homeFile(now()->subMinutes(10)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    homeFile(now()->subMinutes(60)->toImmutable(), ['created_by' => $this->homeAdmin->id]);

    expect($this->getJson('/admin/home/summary')->assertOk()->json('board.rows'))->toHaveCount(2);
});

it('shows an upload still arriving, with its bytes', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 3, 'processed_files' => 1]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_RECEIVING, 'received_bytes' => 400]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'stowing')
        ->assertJsonPath('board.rows.0.phase', 'uploading')
        ->assertJsonPath('board.rows.0.files', 3)
        ->assertJsonPath('board.rows.0.saved_files', 1)
        ->assertJsonPath('board.rows.0.bytes', 1000)
        ->assertJsonPath('board.rows.0.received_bytes', 400);
});

it('shows an upload being processed once every byte has arrived', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 1]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_ASSEMBLING, 'received_bytes' => 1000]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'stowing')
        ->assertJsonPath('board.rows.0.phase', 'processing');
});

it('drops an upload that has gone silent or belongs to someone else', function () {
    homeChunkUpload($this->homeAdmin, ['state' => ChunkUpload::STATE_RECEIVING, 'expires_at' => now()->subMinute()]);
    homeChunkUpload(Admin::factory()->create(), ['state' => ChunkUpload::STATE_RECEIVING]);

    expect($this->getJson('/admin/home/summary')->assertOk()->json('board.rows'))->toBe([]);
});

it('shows a failed upload that saved nothing', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_FAILED, 'total_files' => 2, 'failed_files' => 2]);
    foreach (range(1, 2) as $file) {
        UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => homeFile(now()->subDay()->toImmutable())->id, 'state' => UploadBatch::STATE_FAILED]);
    }

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'failed')
        ->assertJsonPath('board.rows.0.failed_files', 2);
});

it('marks a new landing partial when part of its upload failed', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_COMPLETED, 'total_files' => 3, 'processed_files' => 2, 'failed_files' => 1]);
    $asset = homeFile(now()->subMinutes(5)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_COMPLETED, 'asset_id' => $asset->id]);
    UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => homeFile(now()->subMinutes(6)->toImmutable())->id, 'state' => UploadBatch::STATE_FAILED]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'partial')
        ->assertJsonPath('board.rows.0.failed_files', 1);
});

it('shows five rows and counts the rest', function () {
    homeVisitedHoursAgo($this->homeAdmin, 24);
    foreach (range(1, 7) as $step) {
        homeFile(now()->subMinutes($step * 30)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    }

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonCount(5, 'board.rows')
        ->assertJsonPath('board.more', 2)
        ->assertJsonPath('board.truncated', false)
        ->assertJsonPath('board.holding.available', false);
});

it('has nothing on Needs you and nothing away on a quiet library', function () {
    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('needs_you.items', [])
        ->assertJsonPath('needs_you.total', 0)
        ->assertJsonPath('needs_you.unavailable.0.kind', 'outbox-failures')
        ->assertJsonPath('away.shares.count', 0)
        ->assertJsonPath('away.upload_requests.count', 0);
});

it('lists what needs attention and what is away, with a total that adds up', function () {
    $asset = homeFile(now()->subDays(30)->toImmutable());
    Share::factory()->forAsset($asset->id)->create(['created_by' => $this->homeAdmin->id, 'expires_at' => now()->addDays(2)]);
    Share::factory()->forAsset($asset->id)->create(['created_by' => $this->homeAdmin->id, 'expires_at' => now()->addDays(60)]);
    Share::factory()->forAsset($asset->id)->create(['created_by' => $this->homeAdmin->id, 'expires_at' => now()->addDays(2), 'revoked_at' => now()]);
    homeUploadRequest($this->homeAdmin, ['expires_at' => now()->subDay()]);
    homeUploadRequest($this->homeAdmin, ['expires_at' => now()->addDay()]);
    homeChunkUpload($this->homeAdmin, ['state' => ChunkUpload::STATE_FAILED]);

    $summary = $this->getJson('/admin/home/summary')->assertOk()->json();
    $needs = collect($summary['needs_you']['items'])->pluck('count', 'kind');

    expect($needs->all())->toEqual(['failed-uploads' => 1, 'expiring-shares' => 1, 'expired-upload-requests' => 1])
        ->and($summary['needs_you']['total'])->toBe(3)
        ->and($summary['away']['shares']['count'])->toBe(2)
        ->and($summary['away']['upload_requests']['count'])->toBe(1);
});

it('never presents transfers as zero', function () {
    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('away.transfers.available', false)
        ->assertJsonPath('away.transfers.count', null);
});

it('counts only the files a restricted person may list', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $visible = homeFile(now()->subMinutes(10)->toImmutable(), ['file_size' => 100]);
    $hidden = homeFile(now()->subMinutes(9)->toImmutable(), ['file_size' => 5000]);
    $visible->directories()->attach($allowed->id);
    $hidden->directories()->attach($private->id);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->homeAdmin->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $summary = $this->actingAs($this->homeAdmin->fresh(), 'admin')->getJson(route('admin.home.summary'))->assertOk()->json();

    expect($summary['lead']['files'])->toBe(1)
        ->and($summary['lead']['bytes'])->toBe(100)
        ->and(collect($summary['board']['rows'])->sum('files'))->toBe(1)
        ->and(collect($summary['board']['cargo'])->flatMap(fn (array $row): array => $row['previews'])->pluck('id')->all())->toBe([$visible->id]);
});

it('names a landing after the folder its files share and lists what it holds', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $top = Directory::factory()->create(['parent_id' => null]);
    $folder = Directory::factory()->create(['name' => 'Harbour set', 'parent_id' => $top->id]);
    foreach (['plate-a.png' => 'png', 'plate-b.png' => 'png', 'scene.pcraft' => 'pcraft'] as $name => $extension) {
        homeFile(now()->subMinutes(20)->toImmutable(), ['file_name' => $name, 'extension' => $extension, 'created_by' => $this->homeAdmin->id])
            ->directories()->attach($folder->id);
    }

    $board = $this->getJson('/admin/home/summary')->assertOk()->json('board');
    $row = $board['rows'][0];

    expect($row['title'])->toBe('Harbour set')
        ->and(collect($row['kinds'])->pluck('count', 'extension')->all())->toEqual(['png' => 2, 'pcraft' => 1])
        ->and($row['previews'])->toHaveCount(3)
        ->and(array_keys($row['previews'][0]))->toEqual(['id', 'name', 'type', 'url', 'thumbnail'])
        ->and($row)->not->toHaveKey('asset_ids')
        ->and($board['cargo'][0]['key'])->toBe($row['key']);
});

it('names a single file after itself, whatever its extension', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $file = homeFile(now()->subMinutes(20)->toImmutable(), ['file_name' => 'archive.123', 'extension' => '123', 'created_by' => $this->homeAdmin->id]);

    $row = $this->getJson('/admin/home/summary')->assertOk()->json('board.rows.0');

    expect($row['title'])->toBe($file->fresh()->newQuery()->withDisplayName()->findOrFail($file->id)->display_name)
        ->and($row['kinds'])->toBe([['extension' => '123', 'count' => 1]]);
});

it('reports each craft by its saves over the last day, newest first', function () {
    homeCraftSave(homeFile(now()->subMinutes(30)->toImmutable(), ['created_by' => $this->homeAdmin->id]), $this->homeAdmin, 'artcraft');
    homeCraftSave(homeFile(now()->subHours(30)->toImmutable(), ['created_by' => $this->homeAdmin->id]), $this->homeAdmin, 'artcraft');
    homeCraftSave(homeFile(now()->subHours(2)->toImmutable(), ['created_by' => $this->homeAdmin->id]), $this->homeAdmin, 'blender');
    homeFile(now()->subMinutes(5)->toImmutable(), ['created_by' => $this->homeAdmin->id]);

    $docked = $this->getJson('/admin/home/summary')->assertOk()->json('board.docked');

    expect(array_column($docked, 'producer'))->toBe(['artcraft', 'blender'])
        ->and($docked[0]['files_24h'])->toBe(1)
        ->and($docked[0]['hours'])->toHaveCount(24)
        ->and($docked[0]['hours'][23])->toBe(1)
        ->and($docked[0]['last_save_at'])->toBe(now()->subMinutes(30)->toImmutable()->toIso8601String())
        ->and($docked[1]['hours'][21])->toBe(1);
});

it('says whose upload a landing is', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    homeFile(now()->subMinutes(20)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    homeFile(now()->subMinutes(50)->toImmutable(), ['created_by' => Admin::factory()->create()->id]);

    $rows = $this->getJson('/admin/home/summary')->assertOk()->json('board.rows');

    expect(array_column($rows, 'mine'))->toBe([true, false]);
});

it('keeps uploads in flight off Cargo and limits what Cargo lists', function () {
    config(['dam-home.summary.cargo_rows' => 1, 'dam-home.summary.cargo_pictures' => 2]);
    homeVisitedHoursAgo($this->homeAdmin, 2);
    foreach ([20, 21, 22] as $minutes) {
        homeFile(now()->subMinutes($minutes)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    }
    homeFile(now()->subMinutes(70)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    homeChunkUpload($this->homeAdmin, ['state' => ChunkUpload::STATE_RECEIVING, 'received_bytes' => 400]);

    $board = $this->getJson('/admin/home/summary')->assertOk()->json('board');
    $stowing = collect($board['rows'])->firstWhere('status', 'stowing');

    expect($stowing['title'])->toBeNull()
        ->and($stowing['kinds'])->toBe([])
        ->and($stowing['previews'])->toBe([])
        ->and($board['cargo'])->toHaveCount(1)
        ->and($board['cargo'][0]['status'])->toBe('landed')
        ->and($board['cargo'][0]['files'])->toBe(3)
        ->and($board['cargo'][0]['previews'])->toHaveCount(2);
});

it('says when a section is not permitted instead of showing zero', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    $this->homeAdmin->update(['role_id' => $role->id]);

    $this->actingAs($this->homeAdmin->fresh(), 'admin')->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('lead.available', false)
        ->assertJsonPath('lead.reason', 'not-permitted')
        ->assertJsonPath('board.reason', 'not-permitted')
        ->assertJsonPath('storage.reason', 'not-permitted')
        ->assertJsonPath('away.shares.count', null)
        ->assertJsonPath('away.upload_requests.reason', 'not-permitted')
        ->assertJsonPath('needs_you.unavailable.1', ['kind' => 'expiring-shares', 'reason' => 'not-permitted'])
        ->assertJsonPath('needs_you.unavailable.2', ['kind' => 'expired-upload-requests', 'reason' => 'not-permitted']);
});

it('ignores an older open that reaches the visit record late', function () {
    $visits = app(HomeVisits::class);
    $start = CarbonImmutable::parse('2026-11-18 12:00:00');

    $visits->record(adminId: $this->homeAdmin->id, now: $start->addMinutes(31));
    $visits->record(adminId: $this->homeAdmin->id, now: $start->addMinutes(30));
    $visits->record(adminId: $this->homeAdmin->id, now: $start->addMinutes(60));

    expect($visits->since($this->homeAdmin->id))->toBeNull();
});

it('marks a multipart landing partial and counts its failed file once', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_COMPLETED, 'total_files' => 2, 'processed_files' => 1, 'failed_files' => 1]);
    $asset = homeFile(now()->subMinutes(5)->toImmutable(), ['created_by' => $this->homeAdmin->id]);
    UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => $asset->id, 'state' => UploadBatch::STATE_PROCESSED]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_FAILED]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'partial')
        ->assertJsonPath('board.rows.0.failed_files', 1)
        ->assertJsonPath('needs_you.items.0', ['kind' => 'failed-uploads', 'count' => 1])
        ->assertJsonPath('needs_you.total', 1);
});

it('totals finished and unfinished files of an upload still arriving', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 2, 'processed_files' => 1]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_COMPLETED, 'expected_bytes' => 1000, 'received_bytes' => 1000]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_RECEIVING, 'expected_bytes' => 2000, 'received_bytes' => 500]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonCount(1, 'board.rows')
        ->assertJsonPath('board.rows.0.files', 2)
        ->assertJsonPath('board.rows.0.saved_files', 1)
        ->assertJsonPath('board.rows.0.bytes', 3000)
        ->assertJsonPath('board.rows.0.received_bytes', 1500);
});

it('counts each failed file once, whichever records it has', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_COMPLETED, 'total_files' => 2, 'failed_files' => 1]);
    $asset = homeFile(now()->subDay()->toImmutable());
    UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => $asset->id, 'state' => UploadBatch::STATE_FAILED]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_FAILED, 'asset_id' => $asset->id]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_FAILED]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('needs_you.items.0', ['kind' => 'failed-uploads', 'count' => 2])
        ->assertJsonPath('board.rows.0.status', 'failed')
        ->assertJsonPath('board.rows.0.failed_files', 2);
});

it('does not reveal failed files in someone else\'s upload session', function () {
    homeVisitedHoursAgo($this->homeAdmin, 2);
    $uploader = Admin::factory()->create();
    $tracker = homeTracker($uploader, ['state' => UploadTracker::STATE_COMPLETED, 'total_files' => 2, 'processed_files' => 1, 'failed_files' => 1]);
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $visible = homeFile(now()->subMinutes(5)->toImmutable(), ['created_by' => $uploader->id]);
    $hidden = homeFile(now()->subMinutes(6)->toImmutable(), ['created_by' => $uploader->id]);
    $visible->directories()->attach($allowed->id);
    $hidden->directories()->attach($private->id);
    UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => $visible->id, 'state' => UploadBatch::STATE_PROCESSED]);
    UploadBatch::create(['upload_tracker_id' => $tracker->id, 'asset_id' => $hidden->id, 'state' => UploadBatch::STATE_FAILED]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->homeAdmin->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->actingAs($this->homeAdmin->fresh(), 'admin')->getJson('/admin/home/summary')->assertOk()
        ->assertJsonCount(1, 'board.rows')
        ->assertJsonPath('board.rows.0.status', 'landed')
        ->assertJsonPath('board.rows.0.files', 1)
        ->assertJsonPath('board.rows.0.failed_files', 0)
        ->assertJsonPath('needs_you.total', 0);
});

it('counts a file as saved only once processing has finished', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 2]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_COMPLETED]);
    $arriving = homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_RECEIVING]);

    $this->getJson('/admin/home/summary')->assertOk()->assertJsonPath('board.rows.0.saved_files', 0);

    $arriving->update(['state' => ChunkUpload::STATE_COMPLETED]);

    $this->getJson('/admin/home/summary')->assertOk()->assertJsonPath('board.rows.0.saved_files', 0);
});

it('keeps the byte totals while finished chunks wait for processing', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 2]);
    foreach (range(1, 2) as $file) {
        homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_COMPLETED, 'expected_bytes' => 1000, 'received_bytes' => 1000]);
    }

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'stowing')
        ->assertJsonPath('board.rows.0.phase', 'processing')
        ->assertJsonPath('board.rows.0.bytes', 2000)
        ->assertJsonPath('board.rows.0.received_bytes', 2000);
});

it('shows a session as failed, not stowing, once every file has failed', function () {
    $tracker = homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 1]);
    homeChunkUpload($this->homeAdmin, ['upload_tracker_id' => $tracker->id, 'state' => ChunkUpload::STATE_FAILED]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonCount(1, 'board.rows')
        ->assertJsonPath('board.rows.0.status', 'failed');
});

it('reports no byte figures for an upload session without chunked files', function () {
    homeTracker($this->homeAdmin, ['state' => UploadTracker::STATE_PROCESSING, 'total_files' => 2]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('board.rows.0.status', 'stowing')
        ->assertJsonPath('board.rows.0.bytes', null)
        ->assertJsonPath('board.rows.0.received_bytes', null);
});

it('keeps the first run lead when only someone else saved a hidden craft file', function () {
    $private = Directory::factory()->create();
    $hidden = homeFile(now()->subDay()->toImmutable());
    $hidden->directories()->attach($private->id);
    homeCraftSave($hidden, Admin::factory()->create(), 'PhotoCraft');
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => Directory::factory()->create()->id]);
    $this->homeAdmin->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->actingAs($this->homeAdmin->fresh(), 'admin')->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('lead.state', 'first-run')
        ->assertJsonPath('lead.craft_docked', false);
});

it('says the store is unreachable when it cannot be measured', function () {
    config(['filesystems.disks.'.Directory::getAssetDisk().'.root' => sys_get_temp_dir().'/home-store-missing-'.Str::uuid()]);

    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('storage.available', false)
        ->assertJsonPath('storage.reason', 'store-unreachable')
        ->assertJsonPath('storage.count', null);
});

it('reads a remote store from a fresh capacity file', function () {
    homeRemoteStore(['free_bytes' => 400, 'total_bytes' => 1000, 'checked_at' => now()->subMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z')]);

    $this->getJson('/admin/home/data/storage')->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('free', 400)
        ->assertJsonPath('total', 1000)
        ->assertJsonPath('label', 'Umbrel')
        ->assertJsonPath('message', null);
    $this->getJson('/admin/home/summary')->assertOk()->assertJsonPath('storage.used', 600);
});

it('says a remote store is unreachable without a trustworthy capacity file', function (?array $capacity) {
    homeRemoteStore($capacity);

    $this->getJson('/admin/home/data/storage')->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('free', null)
        ->assertJsonPath('total', null)
        ->assertJsonPath('message', 'Umbrel unreachable');
    $this->getJson('/admin/home/summary')->assertOk()
        ->assertJsonPath('storage.available', false)
        ->assertJsonPath('storage.reason', 'store-unreachable');
})->with([
    'no file'                => [null],
    'older than limit'       => [['free_bytes' => 400, 'total_bytes' => 1000, 'checked_at' => '2026-11-18T11:44:59Z']],
    'from the future'        => [['free_bytes' => 400, 'total_bytes' => 1000, 'checked_at' => '2026-11-18T12:01:01Z']],
    'bad timestamp'          => [['free_bytes' => 400, 'total_bytes' => 1000, 'checked_at' => '2026-13-40T12:00:00Z']],
    'null byte in timestamp' => [['free_bytes' => 400, 'total_bytes' => 1000, 'checked_at' => "2026-11-18T12:00:00Z\0"]],
    'more free than total'   => [['free_bytes' => 2000, 'total_bytes' => 1000, 'checked_at' => '2026-11-18T12:00:00Z']],
    'figures as text'        => [['free_bytes' => '400', 'total_bytes' => '1000', 'checked_at' => '2026-11-18T12:00:00Z']],
]);

it('keeps one visit across a refresh and starts a new one after the gap', function () {
    $visits = app(HomeVisits::class);

    $this->get('/admin/home')->assertOk();
    expect($visits->since($this->homeAdmin->id))->toBeNull();

    $this->travel(10)->minutes();
    $this->get('/admin/home')->assertOk();
    expect($visits->since($this->homeAdmin->id))->toBeNull();

    $this->travel(31)->minutes();
    $this->get('/admin/home')->assertOk();
    expect($visits->since($this->homeAdmin->id)?->toDateTimeString())->toBe('2026-11-18 12:10:00');
});

it('does not count data requests or prefetches as a visit', function () {
    $this->getJson('/admin/home')->assertOk();
    $this->get('/admin/home', ['Sec-Purpose' => 'prefetch'])->assertOk();
    $this->get('/admin/home', ['Purpose' => 'prefetch'])->assertOk();
    $this->getJson('/admin/home/summary')->assertOk();

    expect(DB::table('dam_home_visits')->where('admin_id', $this->homeAdmin->id)->exists())->toBeFalse();
});
