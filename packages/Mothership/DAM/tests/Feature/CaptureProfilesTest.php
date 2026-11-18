<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\CaptureGroupingService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

function captureProfile($test, array $priority = ['osv', 'mp4', 'jpg']): int
{
    return $test->postJson('/admin/dam/configuration/capture-profiles', [
        'name'     => 'Drone', 'extensions' => [...$priority, 'srt', 'lrf'],
        'priority' => $priority, 'enabled' => true,
    ])->assertCreated()->json('profile.id');
}

function captureAsset(Directory $directory, string $name): Asset
{
    $asset = Asset::factory()->create(['file_name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION)]);
    $asset->directories()->attach($directory);

    return $asset;
}

it('saves ordered layouts and resolves extension overrides without discarding properties', function () {
    $this->putJson('/admin/dam/configuration/detail-layouts', ['layouts' => [
        ['match' => 'video', 'fields' => ['file_name', 'codec'], 'hide_empty' => true],
        ['match' => 'mp4', 'fields' => ['property:Camera', 'file_name', 'codec'], 'hide_empty' => true],
    ]])->assertOk();
    $asset = Asset::factory()->create(['file_type' => 'video', 'extension' => 'mp4']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Camera', 'value' => 'DJI']);
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('detail_fields.0.value', 'DJI')
        ->assertJsonPath('detail_fields.1.key', 'file_name')
        ->assertJsonCount(2, 'detail_fields')->assertJsonCount(1, 'properties');
});

it('previews before grouping and keeps same names in other folders separate', function () {
    $profile = captureProfile($this);
    $folder = Directory::factory()->create();
    $video = captureAsset($folder, 'flight.mp4');
    $sidecar = captureAsset($folder, 'flight.srt');
    $other = captureAsset(Directory::factory()->create(), 'flight.osv');
    $preview = $this->postJson('/admin/dam/configuration/capture-preview', ['profile_id' => $profile, 'directory_id' => $folder->id])
        ->assertOk()->assertJsonCount(1, 'groups')->json('groups');
    expect($sidecar->fresh()->capture_group_id)->toBeNull();
    $this->postJson('/admin/dam/configuration/capture-apply', ['profile_id' => $profile, 'directory_id' => $folder->id, 'groups' => $preview])->assertOk();
    expect($video->fresh()->capture_group_id)->not->toBeNull()
        ->and($sidecar->fresh()->capture_group_id)->toBe($video->fresh()->capture_group_id)
        ->and($other->fresh()->capture_group_id)->toBeNull();
    $this->getJson('/admin/dam/explorer/data?directory_id='.$folder->id)->assertOk()->assertJsonCount(1, 'assets');
});

it('selects the preferred file regardless of upload order and lets admins override and ungroup', function () {
    captureProfile($this);
    $folder = Directory::factory()->create();
    $sidecar = captureAsset($folder, 'flight.srt');
    $mp4 = captureAsset($folder, 'flight.mp4');
    app(CaptureGroupingService::class)->autoGroup(assetId: $mp4->id);
    $osv = captureAsset($folder, 'flight.osv');
    app(CaptureGroupingService::class)->autoGroup(assetId: $osv->id);
    $group = $this->getJson('/admin/dam/assets/'.$osv->id.'/capture')->assertOk()->json('group');
    expect($group['primary_asset_id'])->toBe($osv->id);
    $this->putJson('/admin/dam/assets/'.$osv->id.'/capture', ['primary_asset_id' => $mp4->id])->assertOk()->assertJsonPath('group.primary_asset_id', $mp4->id);
    $this->deleteJson('/admin/dam/assets/'.$mp4->id.'/capture')->assertOk();
    expect($sidecar->fresh()->capture_group_id)->toBeNull()->and($osv->fresh()->capture_group_id)->toBeNull();
    app(CaptureGroupingService::class)->autoGroup(assetId: $osv->id);
    expect($osv->fresh()->capture_group_id)->toBeNull();
});

it('leaves ambiguous overlapping profiles ungrouped', function () {
    captureProfile($this);
    captureProfile($this, ['mp4', 'osv']);
    $folder = Directory::factory()->create();
    $a = captureAsset($folder, 'same.mp4');
    captureAsset($folder, 'same.osv');
    app(CaptureGroupingService::class)->autoGroup(assetId: $a->id);
    expect($a->fresh()->capture_group_id)->toBeNull();
});

it('rejects a stale preview instead of applying a changed capture', function () {
    $profile = captureProfile($this);
    $folder = Directory::factory()->create();
    captureAsset($folder, 'flight.mp4');
    captureAsset($folder, 'flight.srt');
    $preview = $this->postJson('/admin/dam/configuration/capture-preview', ['profile_id' => $profile, 'directory_id' => $folder->id])->assertOk()->json('groups');
    captureAsset($folder, 'flight.osv');
    $this->postJson('/admin/dam/configuration/capture-apply', ['profile_id' => $profile, 'directory_id' => $folder->id, 'groups' => $preview])->assertStatus(409);
});

it('forbids configuration and grouping changes for a viewer', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.view']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->postJson('/admin/dam/configuration/capture-profiles', ['name' => 'Forbidden'])->assertForbidden();
    $this->putJson('/admin/dam/configuration/detail-layouts', ['layouts' => []])->assertForbidden();
    $asset = Asset::factory()->create();
    $this->deleteJson('/admin/dam/assets/'.$asset->id.'/capture')->assertForbidden();
});

it('lists companion attachments with usable download URLs without granting other-folder access', function () {
    captureProfile($this);
    $folder = Directory::factory()->create();
    $video = captureAsset($folder, 'clip.mp4');
    $sidecar = captureAsset($folder, 'clip.srt');
    $object = FileObject::factory()->create(['sha256' => hash('sha256', 'subtitle'), 'size' => strlen('subtitle'), 'disk' => 'private', 'mime_type' => 'text/plain']);
    Storage::fake('private');
    Storage::disk('private')->put($object->path, 'subtitle');
    $attachment = AssetFile::factory()->create(['dam_asset_id' => $sidecar->id, 'file_object_id' => $object->id, 'role' => 'original', 'label' => 'clip.srt']);
    app(CaptureGroupingService::class)->autoGroup(assetId: $video->id);
    $files = $this->getJson(route('admin.dam.assets.files.index', $video->id))->assertOk()->json('files');
    $row = collect($files)->firstWhere('id', $attachment->id);
    expect($row)->not->toBeNull()->and($row['asset_id'])->toBe($sidecar->id);
    $this->get($row['download_url'])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=clip.srt');
});

it('renders the settings editor and capture controls', function () {
    $response = $this->get('/admin/dam/configuration/capture')->assertOk();
    expect(str_contains($response->getContent(), 'data-capture-settings'))->toBeTrue();
    $asset = Asset::factory()->create();
    $response = $this->get(route('admin.dam.assets.edit', $asset->id).'?files')->assertOk();
    expect(str_contains($response->getContent(), 'data-capture-files'))->toBeTrue();
});

it('does not combine duplicate extensions or files with multiple directory memberships', function () {
    $profile = captureProfile($this);
    $folder = Directory::factory()->create();
    $a = captureAsset($folder, 'same.mp4');
    captureAsset($folder, 'same.mp4');
    captureAsset($folder, 'same.srt');
    $this->postJson('/admin/dam/configuration/capture-preview', ['profile_id' => $profile, 'directory_id' => $folder->id])->assertOk()->assertJsonCount(0, 'groups');
    $b = captureAsset($folder, 'other.mp4');
    $c = captureAsset($folder, 'other.srt');
    $c->directories()->attach(Directory::factory()->create());
    app(CaptureGroupingService::class)->autoGroup(assetId: $b->id);
    expect($b->fresh()->capture_group_id)->toBeNull();
});

it('preserves manual primary on later uploads and exposes every file after primary deletion', function () {
    captureProfile($this);
    $folder = Directory::factory()->create();
    $video = captureAsset($folder, 'same.mp4');
    $sidecar = captureAsset($folder, 'same.srt');
    app(CaptureGroupingService::class)->autoGroup(assetId: $video->id);
    $this->putJson('/admin/dam/assets/'.$video->id.'/capture', ['primary_asset_id' => $video->id])->assertOk();
    $preferred = captureAsset($folder, 'same.osv');
    app(CaptureGroupingService::class)->autoGroup(assetId: $preferred->id);
    $this->getJson('/admin/dam/assets/'.$video->id.'/capture')->assertJsonPath('group.primary_asset_id', $video->id);
    $video->delete();
    expect(Asset::query()->visibleCaptures()->whereIn('id', [$sidecar->id, $preferred->id])->count())->toBe(2);
});

it('keeps a capture on storage-only changes and detaches only a renamed member', function () {
    captureProfile($this);
    $folder = Directory::factory()->create();
    $video = captureAsset($folder, 'same.mp4');
    $preferred = captureAsset($folder, 'same.osv');
    $sidecar = captureAsset($folder, 'same.srt');
    app(CaptureGroupingService::class)->autoGroup(assetId: $video->id);
    $groupId = $video->fresh()->capture_group_id;
    $video->refresh()->update(['path' => 'assets/replaced/same.mp4']);
    expect($video->fresh()->capture_group_id)->toBe($groupId);
    $sidecar->refresh()->update(['file_name' => 'different.srt']);
    expect($sidecar->fresh()->capture_group_id)->toBeNull()
        ->and($video->fresh()->capture_group_id)->toBe($groupId)
        ->and($preferred->fresh()->capture_group_id)->toBe($groupId)
        ->and((bool) $video->fresh()->capture_grouping_disabled)->toBeFalse();
});

it('counts captures once throughout ancestor rollups and public share listings', function () {
    captureProfile($this);
    $parent = Directory::factory()->create();
    $folder = Directory::factory()->create(['parent_id' => $parent->id]);
    $video = captureAsset($folder, 'same.mp4');
    captureAsset($folder, 'same.srt');
    app(CaptureGroupingService::class)->autoGroup(assetId: $video->id);
    $repository = app(DirectoryRepository::class);
    expect($repository->getAssetCountsRollup()[$parent->id])->toBe(1)
        ->and($repository->getSubtreeAssetCounts([$parent->id])[$parent->id])->toBe(1);
    $share = Share::factory()->forDirectory($parent->id)->create();
    $this->getJson(route('dam.share.list_assets', $share->urlToken()))->assertOk()->assertJsonCount(1, 'data');
});

it('reassigns a grouped capture only to a profile covering all members', function () {
    captureProfile($this);
    $other = captureProfile($this, ['mp4', 'osv']);
    $folder = Directory::factory()->create();
    $video = captureAsset($folder, 'same.mp4');
    captureAsset($folder, 'same.osv');
    $preview = $this->postJson('/admin/dam/configuration/capture-preview', ['profile_id' => $other, 'directory_id' => $folder->id])->assertOk()->json('groups');
    $this->postJson('/admin/dam/configuration/capture-apply', ['profile_id' => $other, 'directory_id' => $folder->id, 'groups' => $preview])->assertOk();
    $third = captureProfile($this, ['osv', 'mp4']);
    $this->putJson('/admin/dam/assets/'.$video->id.'/capture', ['profile_id' => $third])->assertOk()->assertJsonPath('group.profile_id', $third);
    $invalid = captureProfile($this, ['jpg']);
    $this->putJson('/admin/dam/assets/'.$video->id.'/capture', ['profile_id' => $invalid])->assertUnprocessable();
    $this->getJson('/admin/dam/assets/'.$video->id.'/capture')->assertJsonPath('group.profile_id', $third);
});
