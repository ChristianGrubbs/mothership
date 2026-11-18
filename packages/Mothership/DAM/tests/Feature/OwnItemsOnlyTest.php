<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\Tags\TagVocabulary;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * A normal user, as production's "User" role looks after the 2026-10-12 migration (everyday keys, delete_own_only),
 * under the `lists` access model: delete, rename, move, replace, restore, detail and relationship removal and
 * attachment removal are for their own items only. Every case runs as that user against an item they made, one a
 * colleague made that they can see, and one they cannot see; a full administrator is unaffected.
 */

const OWN_ONLY_PROD_KEYS = [
    'dam', 'dam.asset', 'dam.asset.download', 'dam.asset.edit', 'dam.asset.meta_data', 'dam.asset.property',
    'dam.asset.property.create', 'dam.asset.property.update', 'dam.asset.property.view', 'dam.asset.share',
    'dam.asset.tag', 'dam.asset.update', 'dam.asset.upload', 'dam.asset.view', 'dam.assets', 'dam.directory',
    'dam.directory.index', 'dam.directory.share', 'dam.directory.store', 'dam.duplicates.view', 'dam.pages',
    'dam.pages.organize', 'dam.shares', 'dam.shares.delete', 'dam.shares.index', 'dam.shares.revoke',
];

function ownOnlyAsset(Directory $folder, ?Admin $maker, string $name, array $visibleTo = []): Asset
{
    $path = 'assets/Root/'.$name.'-'.uniqid().'.png';
    $image = UploadedFile::fake()->image('x.png', 8, 8);
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($image->getRealPath()));

    $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name'  => basename($path), 'path' => $path, 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png',
        'created_by' => $maker?->id,
    ]));
    $asset->directories()->attach($folder->id);

    foreach ($visibleTo as $admin) {
        DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $admin->id]);
    }

    return $asset;
}

function ownOnlyFolder(Directory $parent, ?Admin $maker, array $visibleTo = []): Directory
{
    $folder = Directory::factory()->withParent($parent)->create(['created_by' => $maker?->id]);

    foreach ($visibleTo as $admin) {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id]);
    }

    return $folder;
}

/** An asset with a real original blob at a real Library path under `$folder`, so replacing or removing it is observable. */
function ownOnlyContent(Directory $folder, ?Admin $maker, string $fileName, string $bytes, array $visibleTo = []): Asset
{
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name'  => $fileName, 'path' => Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath().'/'.$fileName, 'extension' => $extension,
        'file_type'  => $extension === 'png' ? 'image' : 'document', 'mime_type' => $extension === 'png' ? 'image/png' : 'text/plain',
        'created_by' => $maker?->id,
    ]));
    $asset->directories()->attach($folder->id);

    foreach ($visibleTo as $admin) {
        DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $admin->id]);
    }

    return putAssetContent($asset->fresh(), $bytes);
}

/**
 * Everything a refused change must leave alone for one asset: its row, every revision and slot, the versions, the
 * folders it sits in, the trash flag and the sha256 of every blob its revisions name (null when a blob is gone).
 *
 * @return array<string, mixed>
 */
function ownOnlyGraph(Asset $asset): array
{
    $asset = Asset::withTrashed()->findOrFail($asset->id);
    $files = DB::table('dam_asset_files')->where('dam_asset_id', $asset->id)->orderBy('id')
        ->get(['id', 'file_object_id', 'role', 'is_current', 'revision_number', 'attachment_id'])->map(fn ($row) => (array) $row)->all();
    $blobs = [];

    foreach (FileObject::query()->whereIn('id', array_column($files, 'file_object_id'))->orderBy('id')->get() as $object) {
        $blobs[$object->id] = Storage::disk($object->disk)->exists($object->path) ? hash('sha256', Storage::disk($object->disk)->get($object->path)) : null;
    }

    return [
        'asset'    => (array) DB::table('dam_assets')->where('id', $asset->id)->first(['file_name', 'path', 'extension', 'file_type', 'file_size', 'deleted_at', 'merged_into_id', 'created_by']),
        'files'    => $files,
        'blobs'    => $blobs,
        'versions' => DB::table('dam_asset_versions')->where('dam_asset_id', $asset->id)->count(),
        'slots'    => DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'folders'  => DB::table('dam_asset_directory')->where('asset_id', $asset->id)->orderBy('directory_id')->pluck('directory_id')->all(),
    ];
}

/**
 * An asset with two revisions of its original and one extra attachment, made by `$maker` (the extra is attached in
 * their name). Returns the asset, the representative slot, its first revision and the extra slot.
 *
 * @return array{0: Asset, 1: int, 2: int, 3: int}
 */
function ownOnlyRevisioned(Directory $folder, ?Admin $maker, string $fileName, array $visibleTo, ?Admin $attacher = null): array
{
    $asset = ownOnlyContent($folder, $maker, $fileName, 'first edition', $visibleTo);
    putAssetContent($asset->fresh(), 'second edition');
    $first = AssetFile::query()->where('dam_asset_id', $asset->id)->orderBy('id')->firstOrFail();
    $before = auth('admin')->user();
    test()->actingAs($attacher ?? $maker ?? $before, 'admin');
    $extra = app(AttachmentLedger::class)->attach($asset->fresh(), UploadedFile::fake()->createWithContent('extra-'.uniqid().'.txt', 'extra bytes'), 'alternate', null);
    $before === null ? auth()->forgetGuards() : test()->actingAs($before, 'admin');

    return [$asset->fresh(), (int) $first->attachment_id, (int) $first->id, (int) $extra->attachment_id];
}

/** The graph is untouched and every blob it names is still there. */
function expectOwnOnlyGraphKept(Asset $asset, array $before): void
{
    $after = ownOnlyGraph($asset);

    expect($after)->toBe($before)
        ->and(array_filter($after['blobs'], fn ($sha) => $sha === null))->toBe([])
        ->and($after['blobs'])->not->toBe([]);
}

beforeEach(function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    // The prod User role, then the migration that grants it the everyday keys and delete-own-only.
    $this->role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => OWN_ONLY_PROD_KEYS]);
    DB::table('dam_role_settings')->insert([
        'role_id'         => $this->role->id, 'delete_own_only' => false, 'created_at' => now(), 'updated_at' => now(),
        'home_widget_ids' => json_encode(['recent', 'favorites', 'rss', 'trending', 'looper']),
    ]);
    (require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_12_000100_grant_user_role_everyday_dam_access.php'))->up();

    $this->me = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
    $this->colleague = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
    $this->boss = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]);

    $this->root = Directory::query()->findOrFail(1);
    $this->shared = ownOnlyFolder($this->root, $this->colleague, [$this->me, $this->colleague]);
    $this->own = ownOnlyAsset($this->shared, $this->me, 'own');
    $this->foreign = ownOnlyAsset($this->shared, $this->colleague, 'foreign', [$this->me, $this->colleague]);
    $this->hidden = ownOnlyAsset($this->shared, $this->colleague, 'hidden', [$this->colleague]);

    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($this->me, 'admin');
});

it('gives the migrated role the keys and delete-own-only the checks below depend on', function () {
    expect($this->role->fresh()->permissions)->toContain('dam.asset.destroy', 'dam.asset.files.destroy', 'dam.trash.restore')
        ->and((bool) DB::table('dam_role_settings')->where('role_id', $this->role->id)->value('delete_own_only'))->toBeTrue();
});

// ---- delete -----------------------------------------------------------------------------------------------------

it('deletes only the user\'s own file, in plain words for someone else\'s and as a missing one for a hidden file', function () {
    $this->deleteJson(route('admin.dam.assets.destroy', $this->foreign->id))->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.unauthorized'));
    $this->deleteJson(route('admin.dam.assets.destroy', $this->hidden->id))->assertNotFound();
    expect(Asset::query()->find($this->foreign->id))->not->toBeNull()->and(Asset::query()->find($this->hidden->id))->not->toBeNull()
        ->and(Storage::disk(Directory::getAssetDisk())->exists($this->foreign->path))->toBeTrue()
        ->and(Storage::disk(Directory::getAssetDisk())->exists($this->hidden->path))->toBeTrue();

    $this->deleteJson(route('admin.dam.assets.destroy', $this->own->id))->assertOk();
    expect(Asset::query()->find($this->own->id))->toBeNull();

    $this->actingAs($this->boss, 'admin');
    $this->deleteJson(route('admin.dam.assets.destroy', $this->foreign->id))->assertOk();
});

it('mass delete removes the user\'s own files, skips someone else\'s and says so', function () {
    $second = ownOnlyAsset($this->shared, $this->me, 'second');

    $response = $this->postJson(route('admin.dam.assets.mass_delete'), ['indices' => [$this->own->id, $second->id, $this->foreign->id, $this->hidden->id]])->assertOk();

    expect($response->json('message'))->toBe('2 deleted, 1 skipped because they belong to someone else.')
        ->and($response->json('skipped'))->toBe(1)
        ->and(Asset::query()->whereIn('id', [$this->own->id, $second->id])->count())->toBe(0)
        ->and(Asset::query()->whereIn('id', [$this->foreign->id, $this->hidden->id])->count())->toBe(2);

    $none = $this->postJson(route('admin.dam.assets.mass_delete'), ['indices' => [$this->foreign->id]])->assertForbidden();
    expect($none->json('message'))->toContain('Nothing was deleted')
        ->and(Asset::query()->find($this->foreign->id))->not->toBeNull();

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.mass_delete'), ['indices' => [$this->foreign->id]])->assertOk()
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.datagrid.mass-delete-success'));
});

it('folder delete and folder mass delete are for the user\'s own empty folders only', function () {
    $ownFolder = ownOnlyFolder($this->root, $this->me);
    $otherFolder = ownOnlyFolder($this->root, $this->colleague, [$this->me]);
    app(DirectoryPermissionService::class)->flush();

    $this->deleteJson(route('admin.dam.directory.destroy', $otherFolder->id))->assertForbidden();

    $mixed = $this->postJson(route('admin.dam.directory.mass_destroy'), ['indices' => [$otherFolder->id]])->assertForbidden();
    expect($mixed->json('message'))->toContain('Nothing was deleted')
        ->and(Directory::query()->find($otherFolder->id))->not->toBeNull();

    $both = $this->postJson(route('admin.dam.directory.mass_destroy'), ['indices' => [$ownFolder->id, $otherFolder->id]])->assertOk();
    expect($both->json('message'))->toBe('1 deleted, 1 skipped because they belong to someone else or are not empty.');
});

// ---- rename, move, replace --------------------------------------------------------------------------------------

it('renames a file only when the user made it', function () {
    $name = fn (Asset $asset) => pathinfo($asset->fresh()->file_name, PATHINFO_FILENAME);

    $refused = $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->foreign->id, 'file_name' => 'taken-over'])->assertForbidden();
    expect($refused->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-change'))
        ->and($name($this->foreign))->not->toBe('taken-over');
    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->hidden->id, 'file_name' => 'taken-over'])->assertNotFound();

    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->own->id, 'file_name' => 'mine-renamed'])->assertOk();
    expect($name($this->own))->toBe('mine-renamed');

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->foreign->id, 'file_name' => 'admin-renamed'])->assertOk();
});

it('moves a file only when the user made it', function () {
    $target = ownOnlyFolder($this->root, $this->me);
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.assets.moved'), ['move_item_id' => $this->foreign->id, 'new_parent_id' => $target->id])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
    $this->postJson(route('admin.dam.assets.moved'), ['move_item_id' => $this->hidden->id, 'new_parent_id' => $target->id])->assertNotFound();
    expect($this->foreign->directories()->pluck('dam_directories.id')->all())->toBe([$this->shared->id]);

    $this->postJson(route('admin.dam.assets.moved'), ['move_item_id' => $this->own->id, 'new_parent_id' => $target->id])->assertOk();
    expect($this->own->directories()->pluck('dam_directories.id')->all())->toBe([$target->id]);
});

it('mass move skips what is not the user\'s and says so', function () {
    $target = ownOnlyFolder($this->root, $this->me);
    app(DirectoryPermissionService::class)->flush();

    $none = $this->postJson(route('admin.dam.explorer.mass_move'), ['asset_ids' => [$this->foreign->id], 'target_directory_id' => $target->id])->assertForbidden();
    expect($none->json('message'))->toContain('Nothing was moved');

    $mixed = $this->postJson(route('admin.dam.explorer.mass_move'), ['asset_ids' => [$this->own->id, $this->foreign->id], 'target_directory_id' => $target->id])->assertOk();
    expect($mixed->json('message'))->toBe('1 moving, 1 skipped because they belong to someone else.');
});

it('renames and moves a folder only when the user made it, and never the Library root', function () {
    $ownFolder = ownOnlyFolder($this->root, $this->me);
    $theirs = ownOnlyFolder($this->root, $this->colleague, [$this->me]);
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.directory.update'), ['id' => $theirs->id, 'name' => 'Takeover'])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
    $this->postJson(route('admin.dam.directory.update'), ['id' => $this->root->id, 'name' => 'Renamed root'])->assertForbidden();
    expect($theirs->fresh()->name)->not->toBe('Takeover')->and($this->root->fresh()->name)->toBe('Root');
    $this->postJson(route('admin.dam.directory.update'), ['id' => $ownFolder->id, 'name' => 'Mine now'])->assertOk();
    expect($ownFolder->fresh()->name)->toBe('Mine now');

    $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $theirs->id, 'new_parent_id' => $ownFolder->id])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
    $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $this->root->id, 'new_parent_id' => $ownFolder->id])->assertForbidden();
    expect($theirs->fresh()->parent_id)->toBe($this->root->id);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.directory.update'), ['id' => $theirs->id, 'name' => 'Admin rename'])->assertOk();
});

it('replacing the file behind someone else\'s asset is refused, and the image editor saves a copy but not over theirs', function () {
    $file = UploadedFile::fake()->image('new.png', 8, 8);

    $this->post(route('admin.dam.assets.re_upload'), ['asset_id' => $this->foreign->id, 'file' => $file], ['Accept' => 'application/json'])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
    $this->post(route('admin.dam.assets.re_upload'), ['asset_id' => $this->hidden->id, 'file' => $file], ['Accept' => 'application/json'])->assertNotFound();

    $save = fn (Asset $asset, string $mode) => $this->post(route('admin.dam.assets.editor.save', $asset->id), [
        'revision' => (string) Str::uuid(), 'mode' => $mode, 'document' => '{}', 'render' => UploadedFile::fake()->image('r.png', 8, 8),
    ], ['Accept' => 'application/json']);

    $over = $save($this->foreign, 'version')->assertForbidden();
    expect($over->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-change'));
    // A copy is not a change to their file, so the own-items gate does not stop it: an empty document is refused for
    // being one (422), never for whose file it is. The successful copy is the layered-editor test below.
    $copy = $save($this->foreign, 'new');
    expect($copy->getStatusCode())->toBe(422);
});

// ---- trash ------------------------------------------------------------------------------------------------------

it('restores only the user\'s own trashed files', function () {
    $mineTrashed = ownOnlyAsset($this->shared, $this->me, 'mine-trashed');
    $theirsTrashed = ownOnlyAsset($this->shared, $this->colleague, 'theirs-trashed', [$this->me]);
    app(AssetTrashService::class)->trash(asset: $mineTrashed);
    app(AssetTrashService::class)->trash(asset: $theirsTrashed);

    $this->postJson(route('admin.dam.trash.restore', $theirsTrashed->id))->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-restore'));
    expect(Asset::withTrashed()->find($theirsTrashed->id)->trashed())->toBeTrue();

    $this->postJson(route('admin.dam.trash.restore', $mineTrashed->id))->assertOk();
    expect(Asset::withTrashed()->find($mineTrashed->id)->trashed())->toBeFalse();

    $listed = collect($this->getJson(route('admin.dam.trash.list'))->assertOk()->json('assets'))->keyBy('id');
    expect($listed[$theirsTrashed->id]['can_restore'])->toBeFalse();

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.trash.restore', $theirsTrashed->id))->assertOk();
});

// ---- attachments, details, relationships ------------------------------------------------------------------------

it('removes a stacked file only from the user\'s own asset or one they attached, and never unlinks a refused file\'s bytes', function () {
    $mine = attachPreviewFile($this->own, 'assets/Root/mine.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    $theirs = attachPreviewFile($this->foreign, 'assets/Root/theirs.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    $attachedByMe = attachPreviewFile($this->foreign, 'assets/Root/mine-on-theirs.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    $attachedByMe->forceFill(['created_by' => $this->me->id])->save();
    $theirs->forceFill(['created_by' => $this->colleague->id])->save();
    $theirBlob = $theirs->fileObject->path;
    $disk = Storage::disk(Directory::getAssetDisk());

    $refused = $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->foreign->id, $theirs->id]))->assertForbidden();
    expect($refused->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-delete'))
        ->and(AssetFile::query()->find($theirs->id))->not->toBeNull()
        ->and($disk->exists($theirBlob))->toBeTrue();
    $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->hidden->id, 1]))->assertNotFound();

    $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->own->id, $mine->id]))->assertOk();
    $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->foreign->id, $attachedByMe->id]))->assertOk();
    expect(AssetFile::query()->find($mine->id))->toBeNull()->and(AssetFile::query()->find($attachedByMe->id))->toBeNull();

    $this->actingAs($this->boss, 'admin');
    $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->foreign->id, $theirs->id]))->assertOk();
});

it('deletes a detail only on the user\'s own file, one at a time and in bulk', function () {
    $mine = AssetProperty::factory()->create(['dam_asset_id' => $this->own->id, 'name' => 'note', 'value' => 'a']);
    $theirs = AssetProperty::factory()->create(['dam_asset_id' => $this->foreign->id, 'name' => 'note', 'value' => 'b']);
    $theirs2 = AssetProperty::factory()->create(['dam_asset_id' => $this->foreign->id, 'name' => 'note2', 'value' => 'c']);

    $this->deleteJson(route('admin.dam.asset.properties.delete', [$this->foreign->id, $theirs->id]))->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-delete'));
    expect(AssetProperty::query()->find($theirs->id))->not->toBeNull();

    $bulk = $this->postJson(route('admin.dam.asset.properties.mass_delete', $this->own->id), ['indices' => [$mine->id, $theirs->id, $theirs2->id]])->assertOk();
    expect($bulk->json('message'))->toBe('1 deleted, 2 skipped because they belong to someone else.')
        ->and(AssetProperty::query()->find($mine->id))->toBeNull()
        ->and(AssetProperty::query()->whereIn('id', [$theirs->id, $theirs2->id])->count())->toBe(2);

    $again = AssetProperty::factory()->create(['dam_asset_id' => $this->own->id, 'name' => 'again', 'value' => 'a']);
    $this->deleteJson(route('admin.dam.asset.properties.delete', [$this->own->id, $again->id]))->assertOk();

    $this->actingAs($this->boss, 'admin');
    $this->deleteJson(route('admin.dam.asset.properties.delete', [$this->foreign->id, $theirs->id]))->assertOk();
});

it('removes a relationship only when the user made both files', function () {
    $second = ownOnlyAsset($this->shared, $this->me, 'second');
    $both = AssetRelationship::create(['from_asset_id' => $this->own->id, 'to_asset_id' => $second->id, 'type' => 'related-to']);
    $mixed = AssetRelationship::create(['from_asset_id' => $this->own->id, 'to_asset_id' => $this->foreign->id, 'type' => 'related-to']);

    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [$this->own->id, $mixed->id]))->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-delete'));
    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [$this->foreign->id, $mixed->id]))->assertForbidden();
    expect(AssetRelationship::query()->find($mixed->id))->not->toBeNull();

    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [$this->own->id, $both->id]))->assertOk();
    expect(AssetRelationship::query()->find($both->id))->toBeNull();

    $this->actingAs($this->boss, 'admin');
    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [$this->own->id, $mixed->id]))->assertOk();
});

// ---- REST -------------------------------------------------------------------------------------------------------

it('applies the same own-items rule to the REST API', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    $mineRest = ownOnlyAsset($this->shared, $caller, 'rest-own');
    $folderRest = ownOnlyFolder($this->root, $caller);
    $theirFolder = ownOnlyFolder($this->root, $this->colleague, [$caller]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->foreign->id, 'admin_id' => $caller->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    app(DirectoryPermissionService::class)->flush();
    $api = fn (string $method, string $route, array $params, array $body = []) => $this->withHeaders($headers)->json($method, route($route, $params), $body);

    $api('DELETE', 'admin.api.dam.assets.destroy', [$this->foreign->id])->assertForbidden();
    $api('PUT', 'admin.api.dam.assets.update', [$this->foreign->id], ['file_name' => 'api-takeover'])->assertForbidden();
    $api('PUT', 'admin.api.dam.directory.update', [$theirFolder->id], ['name' => 'Api takeover'])->assertForbidden();
    $api('DELETE', 'admin.api.dam.directory.delete', [$theirFolder->id])->assertForbidden();
    expect(Asset::query()->find($this->foreign->id))->not->toBeNull()->and($theirFolder->fresh()->name)->not->toBe('Api takeover');

    $api('PUT', 'admin.api.dam.assets.update', [$mineRest->id], ['file_name' => 'api-mine'])->assertOk();
    $api('PUT', 'admin.api.dam.directory.update', [$folderRest->id], ['name' => 'Api mine'])->assertOk();
    Bus::fake();
    $api('DELETE', 'admin.api.dam.directory.delete', [$folderRest->id])->assertStatus(202);
    $api('DELETE', 'admin.api.dam.assets.destroy', [$mineRest->id])->assertOk();
    expect(Asset::query()->find($mineRest->id))->toBeNull();

    $theirProperty = AssetProperty::factory()->create(['dam_asset_id' => $this->foreign->id, 'name' => 'note', 'value' => 'b']);
    $api('DELETE', 'admin.api.dam.property.delete', [$theirProperty->id])->assertForbidden();
    expect(AssetProperty::query()->find($theirProperty->id))->not->toBeNull();

    $theirFile = attachPreviewFile($this->foreign, 'assets/Root/api-theirs.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    $api('DELETE', 'admin.api.dam.assets.files.destroy', [$this->foreign->id, $theirFile->id])->assertForbidden();
    expect(AssetFile::query()->find($theirFile->id))->not->toBeNull()
        ->and(Storage::disk(Directory::getAssetDisk())->exists($theirFile->fileObject->path))->toBeTrue();
});

// ---- same-name uploads (the shared publisher: direct, chunked and every other caller) ----------------------------

it('publishes a same-name upload as a new file when the name belongs to someone else or to nobody, and replaces only the user\'s own', function () {
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'same.txt', 'their original bytes', [$this->me, $this->colleague]);
    $orphan = ownOnlyContent($this->shared, null, 'orphan.txt', 'nobody original bytes', [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'mine.txt', 'my first bytes', [$this->me]);
    $theirsBefore = ownOnlyGraph($theirs);
    $orphanBefore = ownOnlyGraph($orphan);
    $upload = fn (string $name, string $bytes) => $this->postJson(route('admin.dam.assets.upload'), [
        'files' => [UploadedFile::fake()->createWithContent($name, $bytes)], 'directory_id' => $this->shared->id,
    ])->assertCreated();

    $copy = $upload('same.txt', 'my bytes');
    expect($copy->json('files.0.id'))->not->toBe($theirs->id)
        ->and($copy->json('files.0.file_name'))->toBe('same(1).txt')
        ->and($copy->json('published_notes'))->toBe(['A file with this name already belongs to someone else, so yours was added as same(1).txt.'])
        ->and((int) Asset::query()->find($copy->json('files.0.id'))->created_by)->toBe($this->me->id);
    expectOwnOnlyGraphKept($theirs, $theirsBefore);

    $nobody = $upload('orphan.txt', 'my other bytes');
    expect($nobody->json('files.0.file_name'))->toBe('orphan(1).txt');
    expectOwnOnlyGraphKept($orphan, $orphanBefore);

    // Their own file is a replacement, a new version of the same entry, as before.
    $replaced = $upload('mine.txt', 'my second bytes');
    expect($replaced->json('files.0.id'))->toBe($mine->id)
        ->and($replaced->json('published_notes'))->toBe([trans('dam::app.admin.dam.duplicates.replaced', ['name' => 'mine.txt'])])
        ->and(DB::table('dam_asset_versions')->where('dam_asset_id', $mine->id)->count())->toBeGreaterThan(0);

    // A full administrator is unaffected: the same upload replaces their colleague's file.
    $this->actingAs($this->boss, 'admin');
    expect($upload('same.txt', 'the admin bytes')->json('files.0.id'))->toBe($theirs->id);
});

it('keeps someone else\'s file when a chunked upload, finalized with no session, carries its name', function () {
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'chunked.txt', 'their original bytes', [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'chunked-mine.txt', 'my first bytes', [$this->me]);
    $theirsBefore = ownOnlyGraph($theirs);
    $disk = Directory::getAssetDisk();
    // What the chunk finalizer hands the publisher: a staged file and the uploader as `owner_id`, with no session.
    $publish = function (string $name, string $bytes) use ($disk): Asset {
        $staged = 'staging/'.Str::uuid().'.txt';
        Storage::disk($disk)->put($staged, $bytes);
        auth()->forgetGuards();

        return app(AssetUploadPublisher::class)->publishStagedFile([
            'directory' => $this->shared, 'original_name' => $name, 'mime_type' => 'text/plain', 'extension' => 'txt',
            'source'    => $staged, 'sha256' => hash('sha256', $bytes), 'disk' => $disk, 'owner_id' => $this->me->id,
        ]);
    };

    $copy = $publish('chunked.txt', 'my chunked bytes');
    expect($copy->id)->not->toBe($theirs->id)
        ->and($copy->file_name)->toBe('chunked(1).txt')
        ->and((int) $copy->created_by)->toBe($this->me->id)
        ->and($copy->uploadReceipt->outcome)->toBe('created')
        ->and(ExactDuplicateNotices::publishedNote(receipt: $copy->uploadReceipt, originalName: 'chunked.txt', publishedName: $copy->file_name))
        ->toBe('A file with this name already belongs to someone else, so yours was added as chunked(1).txt.');
    expectOwnOnlyGraphKept($theirs, $theirsBefore);

    $replaced = $publish('chunked-mine.txt', 'my second chunked bytes');
    expect($replaced->id)->toBe($mine->id)->and($replaced->uploadReceipt->outcome)->toBe('versioned');
});

// ---- merge, split and finalize ------------------------------------------------------------------------------------

it('merges, splits and finalizes only files the user made: a foreign or ownerless file on either side changes nothing', function () {
    $dest = ownOnlyContent($this->shared, $this->me, 'dest.txt', 'my destination', [$this->me]);
    $mine = ownOnlyContent($this->shared, $this->me, 'source-mine.txt', 'my source', [$this->me]);
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'source-theirs.txt', 'their source', [$this->me, $this->colleague]);
    $orphan = ownOnlyContent($this->shared, null, 'source-orphan.txt', 'nobody source', [$this->me, $this->colleague]);
    $theirDest = ownOnlyContent($this->shared, $this->colleague, 'dest-theirs.txt', 'their destination', [$this->me, $this->colleague]);
    // Equal audience on purpose: the audience-widening rule never fires, only the own-items rule can refuse.
    $before = [$dest, $mine, $theirs, $orphan, $theirDest];
    $snapshots = array_map(fn (Asset $asset) => ownOnlyGraph($asset), $before);
    $merge = fn (Asset $into, array $sources) => $this->postJson(route('admin.dam.assets.merges.store', $into->id), ['sources' => array_map(fn (Asset $a) => $a->id, $sources)]);

    $merge($dest, [$theirs])->assertForbidden()->assertJsonPath('reason', 'own-only')
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.merge.own-only'));
    $merge($dest, [$mine, $theirs])->assertForbidden()->assertJsonPath('reason', 'own-only');
    $merge($dest, [$orphan])->assertForbidden()->assertJsonPath('reason', 'own-only');
    $merge($theirDest, [$mine])->assertForbidden()->assertJsonPath('reason', 'own-only');

    expect(AssetMerge::query()->count())->toBe(0);
    foreach ($before as $i => $asset) {
        expectOwnOnlyGraphKept($asset, $snapshots[$i]);
    }

    // A merge an administrator made earlier: neither finalize nor split is the user's to run over a foreign source.
    $this->actingAs($this->boss, 'admin');
    $adminMerge = $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$theirs->id]])->assertCreated()->json('merges.0.id');
    $this->actingAs($this->me, 'admin');
    $linked = ownOnlyGraph($theirs);
    $this->postJson(route('admin.dam.assets.merges.finalize', [$dest->id, $adminMerge]), ['confirm' => true])->assertForbidden()->assertJsonPath('reason', 'own-only');
    $this->postJson(route('admin.dam.assets.merges.split', [$dest->id, $adminMerge]))->assertForbidden()->assertJsonPath('reason', 'own-only');
    expect(Asset::withTrashed()->find($theirs->id)->trashed())->toBeFalse()
        ->and(AssetMerge::query()->find($adminMerge)->finalized_at)->toBeNull()
        ->and(AssetMerge::query()->find($adminMerge)->isActive())->toBeTrue()
        ->and(ownOnlyGraph($theirs))->toBe($linked);
    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.merges.split', [$dest->id, $adminMerge]))->assertOk();

    // Their own files merge, and finalizing sends the source to the trash, as before.
    $this->actingAs($this->me, 'admin');
    $own = $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$mine->id]])->assertCreated()->json('merges.0.id');
    $this->postJson(route('admin.dam.assets.merges.finalize', [$dest->id, $own]), ['confirm' => true])->assertOk();
    expect(Asset::withTrashed()->find($mine->id)->trashed())->toBeTrue();
});

it('applies the same merge rule through REST', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    $dest = ownOnlyContent($this->shared, $caller, 'rest-dest.txt', 'rest destination', [$caller]);
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'rest-theirs.txt', 'rest theirs', [$caller, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $caller, 'rest-mine.txt', 'rest mine', [$caller]);
    app(DirectoryPermissionService::class)->flush();
    $before = [ownOnlyGraph($dest), ownOnlyGraph($theirs)];

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.store', $dest->id), ['sources' => [$theirs->id]])
        ->assertForbidden()->assertJsonPath('reason', 'own-only');
    expect(AssetMerge::query()->count())->toBe(0);
    expectOwnOnlyGraphKept($dest, $before[0]);
    expectOwnOnlyGraphKept($theirs, $before[1]);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.store', $dest->id), ['sources' => [$mine->id]])->assertCreated();
});

// ---- promote and restore a revision --------------------------------------------------------------------------------

it('promotes an attachment and restores a revision only on a file the user made', function () {
    [$theirs, $theirSlot, $theirFirst, $theirExtra] = ownOnlyRevisioned($this->shared, $this->colleague, 'rev-theirs.txt', [$this->me, $this->colleague]);
    [$orphan, $orphanSlot, $orphanFirst, $orphanExtra] = ownOnlyRevisioned($this->shared, null, 'rev-orphan.txt', [$this->me, $this->colleague], $this->colleague);
    [$mine, $mineSlot, $mineFirst, $mineExtra] = ownOnlyRevisioned($this->shared, $this->me, 'rev-mine.txt', [$this->me]);
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];

    foreach ([[$theirs, $theirSlot, $theirFirst, $theirExtra], [$orphan, $orphanSlot, $orphanFirst, $orphanExtra]] as [$asset, $slot, $first, $extra]) {
        $this->postJson(route('admin.dam.assets.attachments.promote', [$asset->id, $extra]))->assertForbidden()
            ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
        $this->postJson(route('admin.dam.assets.attachments.restore', [$asset->id, $slot, $first]))->assertForbidden()
            ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
        expectOwnOnlyGraphKept($asset, $kept[$asset->id]);
    }

    $this->postJson(route('admin.dam.assets.attachments.restore', [$mine->id, $mineSlot, $mineFirst]))->assertOk();
    expect(AssetFile::query()->where('attachment_id', $mineSlot)->where('is_current', true)->value('revision_number'))->toBe(3);
    $this->postJson(route('admin.dam.assets.attachments.promote', [$mine->id, $mineExtra]))->assertOk();
    expect((bool) DB::table('dam_attachments')->where('id', $mineExtra)->value('is_representative'))->toBeTrue()
        ->and($mine->fresh()->file_size)->toBe(strlen('extra bytes'));

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.attachments.restore', [$theirs->id, $theirSlot, $theirFirst]))->assertOk();
});

it('applies the promote and restore rule through REST', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    [$theirs, $slot, $first, $extra] = ownOnlyRevisioned($this->shared, $this->colleague, 'rest-rev-theirs.txt', [$caller, $this->colleague]);
    [$orphan, $orphanSlot, $orphanFirst, $orphanExtra] = ownOnlyRevisioned($this->shared, null, 'rest-rev-orphan.txt', [$caller, $this->colleague], $this->colleague);
    [$mine, $mineSlot, $mineFirst, $mineExtra] = ownOnlyRevisioned($this->shared, $caller, 'rest-rev-mine.txt', [$caller]);
    app(DirectoryPermissionService::class)->flush();
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];
    $api = fn (string $route, array $params) => $this->withHeaders($headers)->postJson(route($route, $params));

    foreach ([[$theirs, $slot, $first, $extra], [$orphan, $orphanSlot, $orphanFirst, $orphanExtra]] as [$asset, $s, $f, $e]) {
        $api('admin.api.dam.assets.attachments.promote', ['asset' => $asset->id, 'attachment' => $e])->assertForbidden();
        $api('admin.api.dam.assets.attachments.restore', ['asset' => $asset->id, 'attachment' => $s, 'revision' => $f])->assertForbidden();
        expectOwnOnlyGraphKept($asset, $kept[$asset->id]);
    }

    $api('admin.api.dam.assets.attachments.restore', ['asset' => $mine->id, 'attachment' => $mineSlot, 'revision' => $mineFirst])->assertOk();
    $api('admin.api.dam.assets.attachments.promote', ['asset' => $mine->id, 'attachment' => $mineExtra])->assertOk();
});

// ---- moving a folder moves everything in it ------------------------------------------------------------------------

/**
 * One of the user's own folders holding one of their own sub-folders, plus (per `$kind`) a colleague's folder, a
 * colleague's file or an ownerless file somewhere inside. Returns the top folder and the foreign item's id.
 *
 * @return array{0: Directory, 1: int}
 */
function ownOnlyTreeWithForeign(string $kind, $test): array
{
    $top = ownOnlyFolder($test->root, $test->me);
    $sub = ownOnlyFolder($top, $test->me);
    ownOnlyContent($top, $test->me, 'tree-own-'.uniqid().'.txt', 'own');

    $foreign = match ($kind) {
        'folder' => ownOnlyFolder($sub, $test->colleague, [$test->me])->id,
        'file'   => ownOnlyContent($sub, $test->colleague, 'tree-theirs-'.uniqid().'.txt', 'theirs', [$test->me, $test->colleague])->id,
        'orphan' => ownOnlyContent($sub, null, 'tree-orphan-'.uniqid().'.txt', 'nobody', [$test->me, $test->colleague])->id,
        // One file the user can see and one they cannot: neither may show up in any number they are told.
        'hidden' => (function () use ($sub, $test): int {
            ownOnlyContent($sub, $test->colleague, 'tree-seen-'.uniqid().'.txt', 'seen', [$test->me, $test->colleague]);

            return ownOnlyContent($sub, $test->colleague, 'tree-unseen-'.uniqid().'.txt', 'unseen', [$test->colleague])->id;
        })(),
    };

    return [$top, $foreign];
}

it('moves a folder only when everything inside it, however deep, is the user\'s own', function (string $kind) {
    $target = ownOnlyFolder($this->root, $this->me);
    [$dirty, $foreignId] = ownOnlyTreeWithForeign($kind, $this);
    [$clean] = [ownOnlyFolder($this->root, $this->me)];
    ownOnlyContent(ownOnlyFolder($clean, $this->me), $this->me, 'clean-'.uniqid().'.txt', 'own');
    app(DirectoryPermissionService::class)->flush();
    $lists = fn () => [DB::table('dam_asset_user_access')->orderBy('asset_id')->orderBy('admin_id')->get()->all(), DB::table('dam_directory_user_access')->orderBy('directory_id')->orderBy('admin_id')->get()->all()];
    $accessBefore = $lists();
    $pathsBefore = Asset::query()->orderBy('id')->pluck('path', 'id')->all();

    $refused = $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $dirty->id, 'new_parent_id' => $target->id])->assertForbidden();
    expect($refused->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-subtree', ['count' => 1]))
        ->and($refused->json('foreign'))->toBe(1)
        ->and($dirty->fresh()->parent_id)->toBe($this->root->id)
        ->and($lists())->toEqual($accessBefore)
        ->and(Asset::query()->orderBy('id')->pluck('path', 'id')->all())->toBe($pathsBefore);
    Queue::assertNotPushed(MoveDirectoryStructure::class);

    $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $clean->id, 'new_parent_id' => $target->id])->assertOk();
    Queue::assertPushed(MoveDirectoryStructure::class, 1);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $dirty->id, 'new_parent_id' => $target->id])->assertOk();
})->with(['a colleague\'s folder' => 'folder', 'a colleague\'s file' => 'file', 'an ownerless file' => 'orphan']);

it('mass move leaves a folder with someone else\'s items where it is and says how many', function () {
    $target = ownOnlyFolder($this->root, $this->me);
    [$dirty] = ownOnlyTreeWithForeign('file', $this);
    $clean = ownOnlyFolder($this->root, $this->me);
    app(DirectoryPermissionService::class)->flush();

    $none = $this->postJson(route('admin.dam.explorer.mass_move'), ['directory_ids' => [$dirty->id], 'target_directory_id' => $target->id])->assertForbidden();
    expect($none->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-subtree-mass', ['count' => 1]))
        ->and($dirty->fresh()->parent_id)->toBe($this->root->id);
    Queue::assertNotPushed(MassMove::class);

    $mixed = $this->postJson(route('admin.dam.explorer.mass_move'), ['directory_ids' => [$dirty->id, $clean->id], 'target_directory_id' => $target->id])->assertOk();
    expect($mixed->json('skipped'))->toBe(1)
        ->and($mixed->json('message'))->toBe('1 moving, 1 skipped because they belong to someone else.');
    Queue::assertPushed(MassMove::class, 1);
});

it('re-checks a folder move under the job\'s locks: something of someone else\'s that arrived after the request stays put', function () {
    $target = ownOnlyFolder($this->root, $this->me);
    $single = ownOnlyFolder($this->root, $this->me);
    $inner = ownOnlyFolder($single, $this->me);
    $batch = ownOnlyFolder($this->root, $this->me);
    $batchInner = ownOnlyFolder($batch, $this->me);
    $fine = ownOnlyFolder($this->root, $this->me);
    app(DirectoryPermissionService::class)->flush();

    // Both passed the controller's check; now a colleague's file lands inside each, before the queued job runs.
    $arrived = ownOnlyContent($inner, $this->colleague, 'arrived-'.uniqid().'.txt', 'late', [$this->me, $this->colleague]);
    $arrivedBatch = ownOnlyContent($batchInner, null, 'arrived-batch-'.uniqid().'.txt', 'late', [$this->me, $this->colleague]);
    $arrivedBefore = ownOnlyGraph($arrived);

    (new MoveDirectoryStructure($single->id, $target->id, $this->me->id))->handle();
    (new MassMove([], [$batch->id, $fine->id], $target->id, $this->me->id))->handle();

    expect($single->fresh()->parent_id)->toBe($this->root->id)
        ->and($batch->fresh()->parent_id)->toBe($this->root->id)
        ->and($fine->fresh()->parent_id)->toBe($target->id);
    expectOwnOnlyGraphKept($arrived, $arrivedBefore);
    expect(Asset::query()->find($arrivedBatch->id)->directories()->pluck('dam_directories.id')->all())->toBe([$batchInner->id]);
});

// ---- Web Pages collections ------------------------------------------------------------------------------------------

it('renames, moves and deletes a page group, and files pages, only for what the user made', function () {
    $group = fn (string $name, ?Admin $maker, ?WebCollection $parent = null) => WebCollection::create(['name' => $name, 'parent_id' => $parent?->id, 'created_by' => $maker?->id]);
    $page = function (?Admin $maker, string $name) {
        $asset = ownOnlyContent($this->shared, $maker, $name, '<html></html>', [$this->me, $this->colleague]);
        $asset->forceFill(['file_type' => 'web'])->saveQuietly();

        return $asset;
    };
    $theirs = $group('Theirs', $this->colleague);
    $orphan = $group('Orphan', null);
    $mine = $group('Mine', $this->me);
    $holdsTheirChild = $group('Holds a colleague group', $this->me);
    $theirChild = $group('Colleague child', $this->colleague, $holdsTheirChild);
    $holdsTheirPage = $group('Holds a colleague page', $this->me);
    $theirPage = $page($this->colleague, 'their-page.html');
    $orphanPage = $page(null, 'orphan-page.html');
    $myPage = $page($this->me, 'my-page.html');
    DB::table('dam_web_collection_assets')->insert([['asset_id' => $theirPage->id, 'collection_id' => $holdsTheirPage->id], ['asset_id' => $orphanPage->id, 'collection_id' => $theirs->id]]);
    $placements = fn () => DB::table('dam_web_collection_assets')->orderBy('asset_id')->get()->map(fn ($row) => (array) $row)->all();
    $tree = fn () => WebCollection::query()->orderBy('id')->get(['id', 'name', 'parent_id', 'created_by'])->map->toArray()->all();
    $placedBefore = $placements();
    $treeBefore = $tree();
    $url = fn (WebCollection $c) => route('admin.dam.pages.collections.update', $c->id);

    foreach ([$theirs, $orphan] as $other) {
        $this->patchJson($url($other), ['name' => 'Takeover'])->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.dam.web-pages.collections.not-yours'));
        $this->patchJson($url($other), ['parent_id' => $mine->id])->assertForbidden();
        $this->deleteJson(route('admin.dam.pages.collections.destroy', $other->id))->assertForbidden();
    }

    $held = $this->deleteJson(route('admin.dam.pages.collections.destroy', $holdsTheirChild->id))->assertForbidden();
    expect($held->json('message'))->toBe(trans('dam::app.admin.dam.web-pages.collections.holds-others', ['limit' => 1]));
    $this->deleteJson(route('admin.dam.pages.collections.destroy', $holdsTheirPage->id))->assertForbidden();
    expect($tree())->toBe($treeBefore)->and($placements())->toBe($placedBefore);

    // Filing: a colleague's or ownerless page is not theirs to refile or unfile; their own page is.
    $refused = $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$theirPage->id, $orphanPage->id], 'collection_id' => $mine->id])->assertForbidden();
    expect($refused->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-pages'))
        ->and(array_column($refused->json('rejected'), 'reason'))->toBe(['not-yours', 'not-yours']);
    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$theirPage->id], 'collection_id' => null])->assertForbidden();
    expect($placements())->toBe($placedBefore);

    $mixed = $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$myPage->id, $theirPage->id], 'collection_id' => $mine->id])->assertOk();
    expect($mixed->json('placed'))->toBe([$myPage->id])->and(array_column($mixed->json('rejected'), 'reason'))->toBe(['not-yours']);
    expect(DB::table('dam_web_collection_assets')->where('asset_id', $theirPage->id)->value('collection_id'))->toBe($holdsTheirPage->id);

    $this->patchJson($url($mine), ['name' => 'Mine renamed'])->assertOk();
    $this->deleteJson(route('admin.dam.pages.collections.destroy', $mine->id))->assertOk();
    expect(WebCollection::query()->find($mine->id))->toBeNull();

    // A full administrator is unaffected by every one of these.
    $this->actingAs($this->boss, 'admin');
    $this->patchJson($url($theirs), ['name' => 'Admin rename'])->assertOk();
    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$theirPage->id], 'collection_id' => $theirs->id])->assertOk();
    $this->deleteJson(route('admin.dam.pages.collections.destroy', $holdsTheirChild->id))->assertOk();
});

it('presents per page group whether the user may modify and delete it, by the same rules the writes apply, without a query per group', function () {
    $group = fn (string $name, ?Admin $maker, ?WebCollection $parent = null) => WebCollection::create(['name' => $name, 'parent_id' => $parent?->id, 'created_by' => $maker?->id]);
    $mine = $group('Mine', $this->me);
    $theirs = $group('Theirs', $this->colleague);
    $orphan = $group('Orphan', null);
    $holdsTheirChild = $group('Holds a colleague group', $this->me);
    $group('Colleague child', $this->colleague, $holdsTheirChild);
    $holdsTheirPage = $group('Holds a colleague page', $this->me);
    $theirPage = ownOnlyContent($this->shared, $this->colleague, 'flag-page.html', '<html></html>', [$this->me, $this->colleague]);
    $theirPage->forceFill(['file_type' => 'web'])->saveQuietly();
    DB::table('dam_web_collection_assets')->insert(['asset_id' => $theirPage->id, 'collection_id' => $holdsTheirPage->id]);

    $flags = fn () => collect($this->getJson(route('admin.dam.pages.listing'))->assertOk()->json('children'))->mapWithKeys(fn ($child) => [$child['name'] => [$child['can_modify'], $child['can_delete']]])->all();

    // Root level only: the colleague's child group sits inside the first holder.
    expect($flags())->toEqual([
        'Holds a colleague group' => [true, false],
        'Holds a colleague page'  => [true, false],
        'Mine'                    => [true, true],
        'Orphan'                  => [false, false],
        'Theirs'                  => [false, false],
    ]);

    // The recursive tree (Move to…) carries the same flags on nested groups.
    $nested = collect($this->getJson(route('admin.dam.pages.listing', ['tree' => 1]))->assertOk()->json('children'))->firstWhere('name', 'Holds a colleague group')['children'][0];
    expect([$nested['name'], $nested['can_modify'], $nested['can_delete']])->toBe(['Colleague child', false, false]);

    // The flags are exactly what the writes then decide.
    $this->patchJson(route('admin.dam.pages.collections.update', $theirs->id), ['name' => 'Takeover'])->assertForbidden();
    $this->deleteJson(route('admin.dam.pages.collections.destroy', $holdsTheirPage->id))->assertForbidden();
    $this->patchJson(route('admin.dam.pages.collections.update', $holdsTheirPage->id), ['name' => 'Renamed'])->assertOk();

    // No creator identity travels with the flags.
    $first = $this->getJson(route('admin.dam.pages.listing'))->json('children.0');
    expect(array_keys($first))->not->toContain('created_by')->and(array_keys($first))->not->toContain('creator');

    // Constant queries however many groups there are.
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('admin.dam.pages.listing'))->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();

    foreach (range(1, 6) as $n) {
        $holder = $group('Extra '.$n, $n % 2 ? $this->me : $this->colleague);
        $group('Extra child '.$n, $this->colleague, $holder);
    }

    expect($count())->toBe($before);

    // A full administrator may change and delete every group.
    $this->actingAs($this->boss, 'admin');
    expect(collect($flags())->flatten()->unique()->all())->toBe([true]);
});

// ---- in-place image edits and the layered editor ---------------------------------------------------------------------

it('refuses every in-place image edit on someone else\'s or an ownerless image, and still edits their own', function () {
    config(['mothership.pim_enabled' => true]);
    $fake = UploadedFile::fake()->image('x.png', 200, 100);
    $png = file_get_contents($fake->getRealPath());
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'edit-theirs.png', $png, [$this->me, $this->colleague]);
    $orphan = ownOnlyContent($this->shared, null, 'edit-orphan.png', $png, [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'edit-mine.png', $png, [$this->me]);
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];
    $edits = [
        'resize'          => ['admin.dam.assets.image_edit.resize', ['width' => 10]],
        'adjust'          => ['admin.dam.assets.image_edit.adjust', ['brightness' => 10]],
        'filters'         => ['admin.dam.assets.image_edit.filters', ['greyscale' => true]],
        'transform'       => ['admin.dam.assets.image_edit.transform', ['rotation' => 90]],
        'bg-color-normal' => ['admin.dam.assets.image_edit.bg_color_normal', ['color' => '#ffffff']],
        'bg-color'        => ['admin.dam.assets.image_edit.bg_color', ['color' => '#ffffff', 'platform_id' => 1, 'model' => 'm']],
        'bg-ai'           => ['admin.dam.assets.image_edit.bg_ai', ['prompt' => 'a beach', 'platform_id' => 1, 'model' => 'm']],
        'bg-upload'       => ['admin.dam.assets.image_edit.bg_upload', ['image' => UploadedFile::fake()->image('bg.png', 8, 8), 'platform_id' => 1, 'model' => 'm']],
    ];

    foreach ([$theirs, $orphan] as $asset) {
        foreach ($edits as $label => [$route, $body]) {
            $response = $this->post(route($route, $asset->id), $body, ['Accept' => 'application/json']);
            expect($response->getStatusCode())->toBe(403, $label)
                ->and($response->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-edit-image'), $label);
        }
        expectOwnOnlyGraphKept($asset, $kept[$asset->id]);
    }

    $before = ownOnlyGraph($mine);
    $this->post(route('admin.dam.assets.image_edit.resize', $mine->id), ['width' => 10], ['Accept' => 'application/json'])->assertOk();
    expect(ownOnlyGraph($mine)['blobs'])->not->toBe($before['blobs']);

    $this->actingAs($this->boss, 'admin');
    $this->post(route('admin.dam.assets.image_edit.filters', $theirs->id), ['greyscale' => true], ['Accept' => 'application/json'])->assertOk();
});

it('saves a copy of someone else\'s image from the layered editor but never over it', function () {
    $fake = UploadedFile::fake()->image('x.png', 200, 100);
    $png = file_get_contents($fake->getRealPath());
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'layered-theirs.png', $png, [$this->me, $this->colleague]);
    $opened = $this->getJson(route('admin.dam.assets.editor.document', $theirs->id))->assertOk()->json();
    $before = ownOnlyGraph($theirs);
    $save = fn (string $mode) => $this->post(route('admin.dam.assets.editor.save', $theirs->id), [
        'revision' => $opened['revision'], 'mode' => $mode, 'document' => json_encode($opened['document']), 'render' => UploadedFile::fake()->image('render.png', 200, 100),
    ], ['Accept' => 'application/json']);

    $over = $save('version')->assertForbidden();
    expect($over->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-change'));
    expectOwnOnlyGraphKept($theirs, $before);

    $copy = $save('new')->assertOk();
    $made = Asset::query()->findOrFail($copy->json('asset_id'));
    expect($made->id)->not->toBe($theirs->id)
        ->and((int) $made->created_by)->toBe($this->me->id)
        ->and($made->file_name)->toBe('layered-theirs - edited.png');
    expectOwnOnlyGraphKept($theirs, $before);
});

// ---- trash: permanent deletion ------------------------------------------------------------------------------------------

it('permanently deletes a trashed file only when the user made it, and never unlinks a refused file\'s bytes', function () {
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'trash-theirs.txt', 'their trashed bytes', [$this->me, $this->colleague]);
    $orphan = ownOnlyContent($this->shared, null, 'trash-orphan.txt', 'nobody trashed bytes', [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'trash-mine.txt', 'my trashed bytes', [$this->me]);
    $role = $this->role->fresh();
    $role->permissions = array_values(array_unique([...$role->permissions, 'dam.trash.delete']));
    $role->save();

    foreach ([$theirs, $orphan, $mine] as $asset) {
        app(AssetTrashService::class)->trash(asset: $asset);
    }
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];

    foreach ([$theirs, $orphan] as $asset) {
        $this->deleteJson(route('admin.dam.trash.destroy', $asset->id))->assertForbidden()
            ->assertJsonPath('message', trans('dam::app.admin.permissions.denied-delete-trash'));
        expectOwnOnlyGraphKept($asset, $kept[$asset->id]);
    }

    $this->deleteJson(route('admin.dam.trash.destroy', $mine->id))->assertOk();
    expect(Asset::withTrashed()->find($mine->id))->toBeNull();

    $this->actingAs($this->boss, 'admin');
    $this->deleteJson(route('admin.dam.trash.destroy', $theirs->id))->assertOk();
    expect(Asset::withTrashed()->find($theirs->id))->toBeNull();
});

// ---- hidden items are never counted for the person they are hidden from ------------------------------------------------

it('answers a folder move with no numbers at all when the folder holds items the user cannot see', function () {
    $target = ownOnlyFolder($this->root, $this->me);
    [$dirty] = ownOnlyTreeWithForeign('hidden', $this);
    $mixed = ownOnlyFolder($this->root, $this->me);
    ownOnlyContent($mixed, $this->me, 'mixed-own-'.uniqid().'.txt', 'own');
    ownOnlyContent($mixed, $this->colleague, 'mixed-unseen-'.uniqid().'.txt', 'unseen', [$this->colleague]);
    $clean = ownOnlyFolder($this->root, $this->me);
    app(DirectoryPermissionService::class)->flush();
    $hidden = trans('dam::app.admin.permissions.own-only-subtree-hidden');

    $single = $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $dirty->id, 'new_parent_id' => $target->id])->assertForbidden();
    expect($single->json('message'))->toBe($hidden)
        ->and($single->json())->not->toHaveKey('foreign')
        ->and(preg_match('/\d/', (string) $single->getContent()))->toBe(0)
        ->and($dirty->fresh()->parent_id)->toBe($this->root->id);

    $mass = $this->postJson(route('admin.dam.explorer.mass_move'), ['directory_ids' => [$dirty->id, $mixed->id], 'target_directory_id' => $target->id])->assertForbidden();
    expect($mass->json('message'))->toBe($hidden)
        ->and(preg_match('/\d/', (string) $mass->json('message')))->toBe(0)
        ->and($dirty->fresh()->parent_id)->toBe($this->root->id)
        ->and($mixed->fresh()->parent_id)->toBe($this->root->id);
    Queue::assertNotPushed(MassMove::class);
    Queue::assertNotPushed(MoveDirectoryStructure::class);

    // Visible foreign items are still counted, and only those.
    [$visible] = ownOnlyTreeWithForeign('file', $this);
    app(DirectoryPermissionService::class)->flush();
    $counted = $this->postJson(route('admin.dam.directory.moved'), ['move_item_id' => $visible->id, 'new_parent_id' => $target->id])->assertForbidden();
    expect($counted->json('foreign'))->toBe(1);

    // The clean folder still moves, and a folder alongside a hidden-content one moves with it skipped.
    $both = $this->postJson(route('admin.dam.explorer.mass_move'), ['directory_ids' => [$dirty->id, $clean->id], 'target_directory_id' => $target->id])->assertOk();
    expect($both->json('skipped'))->toBe(1);
    Queue::assertPushed(MassMove::class, 1);
});

// ---- the details of someone else's file --------------------------------------------------------------------------

function ownOnlyField(string $code, string $type = 'text'): MetadataField
{
    return MetadataField::query()->create([
        'code'     => $code, 'label' => ucfirst($code), 'type' => $type, 'options' => null,
        'position' => (int) MetadataField::query()->max('position') + 1,
    ]);
}

/** Field code => stored value for one asset, read straight from the properties table. */
function ownOnlyFieldValues(Asset $asset): array
{
    return array_change_key_case(AssetProperty::query()->where('dam_asset_id', $asset->id)->whereNotNull('field_id')->orderBy('id')->pluck('value', 'name')->all());
}

it('edits field values only on the user\'s own file: set, clear, blank and empty are all refused on someone else\'s', function () {
    $note = ownOnlyField('note');
    $orphan = ownOnlyAsset($this->shared, null, 'detail-orphan', [$this->me, $this->colleague]);
    $this->actingAs($this->boss, 'admin');
    $this->putJson(route('admin.dam.assets.fields.update', $this->foreign->id), ['fields' => ['note' => 'theirs']])->assertOk();
    $this->putJson(route('admin.dam.assets.fields.update', $orphan->id), ['fields' => ['note' => 'nobody']])->assertOk();
    $this->actingAs($this->me, 'admin');
    $kept = [$this->foreign->id => ownOnlyFieldValues($this->foreign), $orphan->id => ownOnlyFieldValues($orphan)];
    expect($kept[$this->foreign->id])->not->toBe([]);

    foreach ([$this->foreign, $orphan] as $asset) {
        foreach (['changed', null, '', ' '] as $value) {
            $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['note' => $value]])->assertForbidden()
                ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
        }
        $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['note' => 'x'], 'expected' => ['note' => 'theirs']])->assertForbidden();
        expect(ownOnlyFieldValues($asset))->toBe($kept[$asset->id]);
    }

    $this->putJson(route('admin.dam.assets.fields.update', $this->own->id), ['fields' => ['note' => 'mine']])->assertOk();
    expect(ownOnlyFieldValues($this->own))->toBe(['note' => 'mine']);
    $this->putJson(route('admin.dam.assets.fields.update', $this->own->id), ['fields' => ['note' => null]])->assertOk();
    expect(ownOnlyFieldValues($this->own))->toBe([]);

    $this->actingAs($this->boss, 'admin');
    $this->putJson(route('admin.dam.assets.fields.update', $this->foreign->id), ['fields' => ['note' => 'by admin']])->assertOk();
    expect(ownOnlyFieldValues($this->foreign))->toBe(['note' => 'by admin']);
});

it('mass field edit changes the user\'s own files, skips someone else\'s and says so in plain words', function () {
    ownOnlyField('note');
    $mine = ownOnlyAsset($this->shared, $this->me, 'mass-own');
    $before = ownOnlyFieldValues($this->foreign);

    $mixed = $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$mine->id, $this->own->id, $this->foreign->id, $this->hidden->id], 'fields' => ['note' => 'bulk']])->assertOk();
    expect($mixed->json('count'))->toBe(2)
        ->and($mixed->json('skipped'))->toBe(1)
        ->and($mixed->json('message'))->toBe('2 updated, 1 skipped because they belong to someone else.')
        ->and(ownOnlyFieldValues($mine))->toBe(['note' => 'bulk'])
        ->and(ownOnlyFieldValues($this->foreign))->toBe($before);

    $none = $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$this->foreign->id], 'fields' => ['note' => null]])->assertForbidden();
    expect($none->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => 1]))
        ->and(ownOnlyFieldValues($this->foreign))->toBe($before);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$this->foreign->id], 'fields' => ['note' => 'by admin']])->assertOk()->assertJsonPath('count', 1);
});

it('applies the details rule to REST asset updates: fields, an empty tag list and availability', function () {
    ownOnlyField('note');
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->foreign->id, 'admin_id' => $caller->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    app(DirectoryPermissionService::class)->flush();
    $tag = Tag::create(['name' => 'rest-keep']);
    $this->foreign->tags()->attach($tag->id);
    $mineRest = ownOnlyAsset($this->shared, $caller, 'rest-detail-own');
    $update = fn (Asset $asset, array $body) => $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $asset->id), $body);
    $before = DB::table('dam_assets')->where('id', $this->foreign->id)->first(['availability', 'available_from', 'available_until', 'file_name']);

    foreach ([['fields' => ['note' => 'x']], ['fields' => ['note' => null]], ['tags' => []], ['tags' => [$tag->id + 100]], ['availability' => Asset::AVAILABILITY_ARCHIVED]] as $body) {
        $update($this->foreign, $body)->assertForbidden();
    }

    expect($this->foreign->tags()->pluck('dam_tags.id')->all())->toBe([$tag->id])
        ->and(ownOnlyFieldValues($this->foreign))->toBe([])
        ->and(DB::table('dam_assets')->where('id', $this->foreign->id)->first(['availability', 'available_from', 'available_until', 'file_name']))->toEqual($before);

    $update($mineRest, ['fields' => ['note' => 'mine'], 'tags' => [$tag->id]])->assertOk();
    expect(ownOnlyFieldValues($mineRest))->toBe(['note' => 'mine']);
});

it('adds, removes and bulk-assigns tags only on the user\'s own files', function () {
    $keep = Tag::create(['name' => 'theirs-keep']);
    $this->foreign->tags()->attach($keep->id);
    $mine = ownOnlyAsset($this->shared, $this->me, 'tag-own');
    $tags = fn (Asset $asset) => $asset->tags()->pluck('dam_tags.name')->sort()->values()->all();

    $this->postJson(route('admin.dam.assets.tag'), ['tag' => 'intruder', 'asset_id' => $this->foreign->id])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
    $this->postJson(route('admin.dam.assets.remove-tag'), ['tag' => 'theirs-keep', 'asset_id' => $this->foreign->id])->assertForbidden();
    expect($tags($this->foreign))->toBe(['theirs-keep']);

    $this->postJson(route('admin.dam.assets.tag'), ['tag' => 'mine', 'asset_id' => $mine->id])->assertSuccessful();
    $this->postJson(route('admin.dam.assets.remove-tag'), ['tag' => 'mine', 'asset_id' => $mine->id])->assertSuccessful();
    expect($tags($mine))->toBe([]);

    $bulk = $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['indices' => [$mine->id, $this->foreign->id], 'tags' => ['bulk']])->assertOk();
    expect($bulk->json('message'))->toContain('skipped')
        ->and($tags($mine))->toBe(['bulk'])
        ->and($tags($this->foreign))->toBe(['theirs-keep']);
    $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['indices' => [$this->foreign->id], 'tags' => ['bulk']])->assertForbidden();
    expect($tags($this->foreign))->toBe(['theirs-keep']);

    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->foreign->id, 'admin_id' => $caller->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.tag.add'), ['tag' => 'rest-intruder', 'asset_id' => $this->foreign->id])->assertForbidden();
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tag.delete'), ['tag' => 'theirs-keep', 'asset_id' => $this->foreign->id])->assertForbidden();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.tags.bulk_assign'), ['asset_ids' => [$this->foreign->id], 'tags' => ['rest-bulk']])->assertForbidden();
    expect($tags($this->foreign))->toBe(['theirs-keep']);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.assets.tag'), ['tag' => 'by-admin', 'asset_id' => $this->foreign->id])->assertCreated();
});

it('adds, edits and times details only on the user\'s own file: properties and availability', function () {
    $this->postJson(route('admin.dam.asset.property.store', $this->foreign->id), ['name' => 'intruder', 'type' => 'text', 'language' => 'English', 'value' => 'x'])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
    expect(AssetProperty::query()->where('dam_asset_id', $this->foreign->id)->count())->toBe(0);

    $this->postJson(route('admin.dam.asset.property.store', $this->own->id), ['name' => 'mine', 'type' => 'text', 'language' => 'English', 'value' => 'x'])->assertOk();

    $window = ['availability' => Asset::AVAILABILITY_ARCHIVED, 'available_from' => '2026-01-01T09:00:00Z', 'available_until' => '2026-02-01T09:00:00Z'];
    $before = DB::table('dam_assets')->where('id', $this->foreign->id)->first(['availability', 'available_from', 'available_until']);
    $this->putJson(route('admin.dam.assets.availability.update', $this->foreign->id), $window)->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
    expect(DB::table('dam_assets')->where('id', $this->foreign->id)->first(['availability', 'available_from', 'available_until']))->toEqual($before);
    $this->putJson(route('admin.dam.assets.availability.update', $this->own->id), $window)->assertOk();

    $this->actingAs($this->boss, 'admin');
    $this->putJson(route('admin.dam.assets.availability.update', $this->foreign->id), $window)->assertOk();
});

it('shows someone else\'s file as read-only with the reason, and the user\'s own as editable', function () {
    ownOnlyField('note');
    $theirs = $this->getJson(route('admin.dam.assets.info', $this->foreign->id))->assertOk();
    expect($theirs->json('can_edit_fields'))->toBeFalse()
        ->and($theirs->json('details_note'))->toBe('Only the person who added this file can edit its details.');
    $mine = $this->getJson(route('admin.dam.assets.info', $this->own->id))->assertOk();
    expect($mine->json('can_edit_fields'))->toBeTrue()->and($mine->json('details_note'))->toBeNull();

});

it('renders the details page read-only for someone else\'s file and follows the asset the user navigates to', function () {
    // Booleans, not assertSee: a failed assertion would print the whole page.
    $has = fn (string $html, string $needle): bool => str_contains($html, $needle);
    $theirs = $this->get(route('admin.dam.assets.edit', $this->foreign->id))->assertOk()->getContent();
    $mine = $this->get(route('admin.dam.assets.edit', $this->own->id))->assertOk()->getContent();
    $note = 'Only the person who added this file can edit its details.';

    // The page starts from the note for the file on screen; the tag box and the read-only list both follow it.
    $initial = fn (string $html): string => preg_match('/detailsNote:\s+(.*),\n/', $html, $found) === 1 ? $found[1] : '';
    expect($initial($theirs))->toContain($note)
        ->and($initial($mine))->toBe('null')
        ->and($has($theirs, '<div v-if="! detailsNote"'))->toBeTrue()
        ->and($has($theirs, 'data-dam-tags-readonly'))->toBeTrue();

    // The ‹ › navigation payload carries the same capability, both ways, so the controls swap without a reload.
    $payload = fn (Asset $asset) => $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->json('detailsNote');
    expect($payload($this->foreign))->toBe($note)
        ->and($payload($this->own))->toBeNull()
        ->and($payload($this->foreign))->toBe($note);
});

it('tells the files tab whether the user may rename and arrange', function () {
    $files = $this->getJson(route('admin.dam.assets.files.index', $this->foreign->id))->assertOk();
    expect($files->json('can_edit_details'))->toBeFalse();
    expect($this->getJson(route('admin.dam.assets.files.index', $this->own->id))->json('can_edit_details'))->toBeTrue();
});

// ---- renaming attached files ---------------------------------------------------------------------------------------

it('renames, re-roles and re-orders an attached file only on the user\'s own asset', function () {
    [$theirs, $slot, , $extra] = ownOnlyRevisioned($this->shared, $this->colleague, 'label-theirs.txt', [$this->me, $this->colleague]);
    [$orphan, $orphanSlot, , $orphanExtra] = ownOnlyRevisioned($this->shared, null, 'label-orphan.txt', [$this->me, $this->colleague], $this->colleague);
    [$mine, , , $mineExtra] = ownOnlyRevisioned($this->shared, $this->me, 'label-mine.txt', [$this->me]);
    app(DirectoryPermissionService::class)->flush();
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];

    foreach ([[$theirs, $slot, $extra], [$orphan, $orphanSlot, $orphanExtra]] as [$asset, $representative, $attached]) {
        $this->patchJson(route('admin.dam.assets.attachments.update', [$asset->id, $attached]), ['label' => 'Renamed by me'])->assertForbidden()
            ->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-label'));
        $this->patchJson(route('admin.dam.assets.attachments.update', [$asset->id, $attached]), ['role' => 'sidecar', 'position' => 0])->assertForbidden();
        $this->patchJson(route('admin.dam.assets.attachments.update', [$asset->id, $representative]), ['label' => null])->assertForbidden();
        $this->putJson(route('admin.dam.assets.attachments.order', $asset->id), ['order' => [$attached, $representative]])->assertForbidden();
        expectOwnOnlyGraphKept($asset, $kept[$asset->id]);
    }

    $renamed = $this->patchJson(route('admin.dam.assets.attachments.update', [$mine->id, $mineExtra]), ['label' => 'My new label'])->assertOk();
    expect(collect($renamed->json('files'))->firstWhere('attachment_id', $mineExtra)['label'])->toBe('My new label');

    $this->actingAs($this->boss, 'admin');
    $this->patchJson(route('admin.dam.assets.attachments.update', [$theirs->id, $extra]), ['label' => 'Admin label'])->assertOk();
    expect(DB::table('dam_attachments')->where('id', $extra)->value('label'))->toBe('Admin label');
});

it('applies the attached-file rename rule to REST', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    [$theirs, $slot, , $extra] = ownOnlyRevisioned($this->shared, $this->colleague, 'rest-label-theirs.txt', [$caller, $this->colleague]);
    [$mine, , , $mineExtra] = ownOnlyRevisioned($this->shared, $caller, 'rest-label-mine.txt', [$caller]);
    app(DirectoryPermissionService::class)->flush();
    $kept = ownOnlyGraph($theirs);

    $this->withHeaders($headers)->patchJson(route('admin.api.dam.assets.attachments.update', [$theirs->id, $extra]), ['label' => 'Renamed over REST'])->assertForbidden();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.attachments.order', $theirs->id), ['order' => [$extra, $slot]])->assertForbidden();
    expectOwnOnlyGraphKept($theirs, $kept);

    $this->withHeaders($headers)->patchJson(route('admin.api.dam.assets.attachments.update', [$mine->id, $mineExtra]), ['label' => 'REST label'])->assertOk();
    expect(DB::table('dam_attachments')->where('id', $mineExtra)->value('label'))->toBe('REST label');
});

// ---- thumbnails on someone else's file ---------------------------------------------------------------------------

function ownOnlyModelBytes(string $extension): string
{
    return match ($extension) {
        'glb'   => "glTF\x02\x00\x00\x00".str_repeat('g', 64),
        '3mf'   => "PK\x03\x04".str_repeat('m', 64),
        default => "solid part\nendsolid part\n",
    };
}

function ownOnlyModel(Directory $folder, ?Admin $maker, string $extension, array $visibleTo): Asset
{
    $asset = ownOnlyContent($folder, $maker, 'model-'.uniqid().'.'.$extension, ownOnlyModelBytes($extension), $visibleTo);
    $asset->update(['file_type' => 'document', 'mime_type' => $extension === 'glb' ? 'model/gltf-binary' : 'model/'.$extension]);

    return $asset->fresh();
}

dataset('model kinds', ['a GLB' => 'glb', 'a 3MF' => '3mf', 'an STL' => 'stl']);

it('captures and replaces a model\'s picture only on the user\'s own file, the first capture included, and a stacked file\'s likewise', function (string $extension) {
    $theirs = ownOnlyModel($this->shared, $this->colleague, $extension, [$this->me, $this->colleague]);
    $orphan = ownOnlyModel($this->shared, null, $extension, [$this->me, $this->colleague]);
    $withPicture = ownOnlyModel($this->shared, $this->colleague, $extension, [$this->me, $this->colleague]);
    $mine = ownOnlyModel($this->shared, $this->me, $extension, [$this->me]);
    $disk = Storage::disk(Directory::getAssetDisk());
    $capture = fn () => UploadedFile::fake()->image('capture.png', 64, 64);
    $post = fn (Asset $asset, array $extra = []) => $this->post(
        route('admin.dam.assets.model_thumbnail.store', $asset->id),
        ['image'  => $capture(), 'checksum' => ModelThumbnailService::checksum($asset)] + $extra,
        ['Accept' => 'application/json'],
    );
    $originalFile = fn (Asset $asset) => AssetFile::query()->where('dam_asset_id', $asset->id)->orderBy('id')->firstOrFail()->id;
    $filePost = fn (Asset $asset, array $extra = []) => $this->post(
        route('admin.dam.assets.file_model_thumbnail.store', $originalFile($asset)),
        ['image'  => $capture(), 'checksum' => ModelThumbnailService::checksum($asset)] + $extra,
        ['Accept' => 'application/json'],
    );
    $pictures = fn () => collect($disk->allFiles())->filter(fn ($path) => str_contains($path, 'thumbnail') || str_contains($path, 'model-capture'))
        ->mapWithKeys(fn ($path) => [$path => hash('sha256', $disk->get($path))])->sortKeys()->all();

    // The owner made a picture for one of the colleague's files; the user then changes nothing of anyone else's.
    $this->actingAs($this->colleague, 'admin');
    $post($withPicture)->assertOk()->assertJsonPath('stored', true);
    $filePost($withPicture)->assertOk()->assertJsonPath('stored', true);
    $this->actingAs($this->me, 'admin');
    $kept = $pictures();
    $meta = [$theirs->id => $theirs->fresh()->meta_data, $orphan->id => $orphan->fresh()->meta_data, $withPicture->id => $withPicture->fresh()->meta_data];
    expect($kept)->not->toBe([]);

    foreach ([$theirs, $orphan, $withPicture] as $asset) {
        foreach ([[], ['replace' => 1]] as $extra) {
            $post($asset, $extra)->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-thumbnail'));
            $filePost($asset, $extra)->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-thumbnail'));
        }
        expect($asset->fresh()->meta_data)->toBe($meta[$asset->id]);
    }
    expect($pictures())->toBe($kept);

    // The viewer is told it may not capture, so it sends nothing at all.
    $show = $this->getJson(route('admin.dam.assets.model_thumbnail.show', $theirs->id))->assertOk();
    expect($show->json('can_capture'))->toBeFalse()->and($show->json('can_replace'))->toBeFalse();
    $fileShow = $this->getJson(route('admin.dam.assets.file_model_thumbnail.show', $originalFile($theirs)))->assertOk();
    expect($fileShow->json('can_capture'))->toBeFalse()->and($fileShow->json('can_replace'))->toBeFalse();
    $own = $this->getJson(route('admin.dam.assets.model_thumbnail.show', $mine->id))->assertOk();
    expect($own->json('can_capture'))->toBeTrue()->and($own->json('can_replace'))->toBeTrue();

    $post($mine)->assertOk()->assertJsonPath('stored', true);
    $post($mine, ['replace' => 1])->assertOk()->assertJsonPath('stored', true);
    $filePost($mine)->assertOk()->assertJsonPath('stored', true);

    $this->actingAs($this->boss, 'admin');
    $post($theirs)->assertOk()->assertJsonPath('stored', true);
    $post($withPicture, ['replace' => 1])->assertOk()->assertJsonPath('stored', true);
    $filePost($withPicture, ['replace' => 1])->assertOk()->assertJsonPath('stored', true);
    $this->getJson(route('admin.dam.assets.model_thumbnail.show', $theirs->id))->assertOk()->assertJsonPath('can_capture', true);
})->with('model kinds');

it('sets a custom thumbnail on a non-image file only when the user made it', function () {
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'thumb-theirs.stl', ownOnlyModelBytes('stl'), [$this->me, $this->colleague]);
    $orphan = ownOnlyContent($this->shared, null, 'thumb-orphan.stl', ownOnlyModelBytes('stl'), [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'thumb-mine.stl', ownOnlyModelBytes('stl'), [$this->me]);
    $disk = Storage::disk(Directory::getAssetDisk());
    $set = fn (Asset $asset) => $this->post(route('admin.dam.assets.custom_thumbnail.store', $asset->id), ['thumbnail' => UploadedFile::fake()->image('cover.png', 32, 32)], ['Accept' => 'application/json']);
    $before = [$theirs->id => $theirs->fresh()->meta_data, $orphan->id => $orphan->fresh()->meta_data];
    $filesBefore = $disk->allFiles();

    foreach ([$theirs, $orphan] as $asset) {
        $set($asset)->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-thumbnail'));
        expect($asset->fresh()->meta_data)->toBe($before[$asset->id]);
    }
    expect($disk->allFiles())->toBe($filesBefore);

    $set($mine)->assertCreated();
    expect($mine->fresh()->meta_data)->toHaveKey('custom_thumbnail');

    $this->actingAs($this->boss, 'admin');
    $set($theirs)->assertCreated();
});

// ---- the 3D primary view -------------------------------------------------------------------------------------------

function ownOnlyGlb(): string
{
    $json = json_encode(['asset' => ['version' => '2.0'], 'scene' => 0, 'scenes' => [['nodes' => [0]]], 'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => []]], 'extensions' => (object) [], 'buffers' => [['byteLength' => 4]]]);
    $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');

    return 'glTF'.pack('VVVV', 2, 28 + strlen($json) + 4, strlen($json), 0x4E4F534A).$json.pack('VV', 4, 0x004E4942).'ABCD';
}

it('saves and restores a GLB primary view only on the user\'s own model, leaving the original, current and version rows and bytes of anyone else\'s untouched', function () {
    $asset = fn (?Admin $maker, array $visibleTo) => (function () use ($maker, $visibleTo): Asset {
        $model = ownOnlyContent($this->shared, $maker, 'view-'.uniqid().'.glb', ownOnlyGlb(), $visibleTo);
        $model->update(['file_type' => 'document', 'mime_type' => 'model/gltf-binary']);

        return $model->fresh();
    })();
    $theirs = $asset($this->colleague, [$this->me, $this->colleague]);
    $orphan = $asset(null, [$this->me, $this->colleague]);
    $mine = $asset($this->me, [$this->me]);
    $url = fn (Asset $model) => '/'.config('app.admin_url').'/dam/assets/primary-view/'.$model->id;
    $payload = fn (string $revision) => ['revision' => $revision, 'rotation' => [0, 1, 0, 0], 'translation' => [0, 0, 0], 'view' => ['distance' => 4, 'aspect' => 1.5]];
    $revision = fn (Asset $model) => $this->getJson($url($model))->assertOk()->json('revision');

    // An administrator saves a view on the colleague's model first, so there is a saved view to restore and a version row.
    $this->actingAs($this->boss, 'admin');
    $this->postJson($url($theirs), $payload($revision($theirs)))->assertOk();
    $this->postJson($url($orphan), $payload($revision($orphan)))->assertOk();
    $this->actingAs($this->me, 'admin');
    $kept = [$theirs->id => ownOnlyGraph($theirs), $orphan->id => ownOnlyGraph($orphan)];
    expect(array_column($kept[$theirs->id]['files'], 'role'))->toContain('version');

    foreach ([$theirs, $orphan] as $model) {
        $this->getJson($url($model))->assertOk()->assertJsonPath('can_edit', false);
        $current = $revision($model);
        $this->postJson($url($model), $payload($current))->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
        $this->deleteJson($url($model), ['revision' => $current])->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-change'));
        expectOwnOnlyGraphKept($model, $kept[$model->id]);
    }

    // The owner saves and restores their own.
    $first = ownOnlyGraph($mine);
    $this->postJson($url($mine), $payload($revision($mine)))->assertOk();
    expect(ownOnlyGraph($mine)['blobs'])->not->toBe($first['blobs'])
        ->and(array_column(ownOnlyGraph($mine)['files'], 'role'))->toContain('version');
    $this->deleteJson($url($mine), ['revision' => $revision($mine)])->assertOk();
    expect(array_filter(ownOnlyGraph($mine)['blobs'], fn ($sha) => $sha === null))->toBe([]);
});

// ---- reviewing AI tag suggestions on someone else's file ----------------------------------------------------------

/** An asset with an index row (caption model `$model`, which may be NULL) and open `photo` and `logo` suggestions. */
function ownOnlyReviewed(Directory $folder, ?Admin $maker, ?string $model, array $visibleTo): Asset
{
    $asset = ownOnlyAsset($folder, $maker, 'review', $visibleTo);
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'caption' => 'x', 'caption_model' => $model, 'created_at' => now(), 'updated_at' => now()]);

    foreach (['photo', 'logo'] as $term) {
        DB::table('dam_ai_tags')->insert(['asset_id' => $asset->id, 'term' => $term, 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $asset;
}

/** Suggestion rows and tag pivots of the given assets, exactly as stored. */
function ownOnlyReviewState(array $assets): array
{
    $ids = array_map(fn (Asset $asset) => $asset->id, $assets);

    return [
        'suggestions' => DB::table('dam_ai_tags')->whereIn('asset_id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'pivots'      => DB::table('dam_asset_tag')->whereIn('asset_id', $ids)->orderBy('asset_id')->orderBy('tag_id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

it('reviews AI tag suggestions in bulk only on the user\'s own files, whatever the caption model, and says what it skipped', function () {
    $foreign = [
        ownOnlyReviewed($this->shared, $this->colleague, 'qwen3.5:9b', [$this->me, $this->colleague]),
        ownOnlyReviewed($this->shared, $this->colleague, null, [$this->me, $this->colleague]),
        ownOnlyReviewed($this->shared, null, 'qwen3.5:9b', [$this->me, $this->colleague]),
        ownOnlyReviewed($this->shared, null, null, [$this->me, $this->colleague]),
    ];
    $mineModel = ownOnlyReviewed($this->shared, $this->me, 'qwen3.5:9b', [$this->me]);
    $mineNull = ownOnlyReviewed($this->shared, $this->me, null, [$this->me]);
    $before = ownOnlyReviewState($foreign);
    $items = fn (string $term) => array_map(fn (Asset $asset) => ['asset_id' => $asset->id, 'term' => $term], [...$foreign, $mineModel, $mineNull]);

    $accepted = $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => $items('photo')])->assertOk();
    expect($accepted->json('done'))->toBe(2)
        ->and($accepted->json('not_yours'))->toBe(4)
        ->and($accepted->json('message'))->toBe('2 updated, 4 skipped because they belong to someone else.')
        ->and(array_count_values(array_column($accepted->json('results'), 'outcome')))->toBe(['not_yours' => 4, 'accepted' => 2])
        ->and(ownOnlyReviewState($foreign))->toBe($before)
        ->and(DB::table('dam_ai_tags')->whereIn('asset_id', [$mineModel->id, $mineNull->id])->where('term', 'photo')->pluck('status')->all())->toBe(['accepted', 'accepted'])
        ->and(DB::table('dam_asset_tag')->whereIn('asset_id', [$mineModel->id, $mineNull->id])->pluck('source')->sort()->values()->all())->toBe(['ai', 'ai:qwen3.5:9b']);

    $dismissed = $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'dismiss', 'items' => $items('logo')])->assertOk();
    expect($dismissed->json('done'))->toBe(2)
        ->and($dismissed->json('not_yours'))->toBe(4)
        ->and(ownOnlyReviewState($foreign))->toBe($before)
        ->and(DB::table('dam_ai_tags')->whereIn('asset_id', [$mineModel->id, $mineNull->id])->where('term', 'logo')->pluck('status')->all())->toBe(['dismissed', 'dismissed']);

    $none = $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => array_slice($items('logo'), 0, 4)])->assertOk();
    expect($none->json('done'))->toBe(0)
        ->and($none->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => 4]))
        ->and(ownOnlyReviewState($foreign))->toBe($before);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => array_slice($items('photo'), 0, 2)])->assertOk()->assertJsonPath('done', 2);
    expect(DB::table('dam_asset_tag')->whereIn('asset_id', [$foreign[0]->id, $foreign[1]->id])->count())->toBe(2);
});

it('applies the AI review rule to REST bulk accept and dismiss', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    $theirs = ownOnlyReviewed($this->shared, $this->colleague, 'qwen3.5:9b', [$caller, $this->colleague]);
    $theirsNull = ownOnlyReviewed($this->shared, $this->colleague, null, [$caller, $this->colleague]);
    $orphan = ownOnlyReviewed($this->shared, null, null, [$caller, $this->colleague]);
    $mine = ownOnlyReviewed($this->shared, $caller, 'qwen3.5:9b', [$caller]);
    $mineNull = ownOnlyReviewed($this->shared, $caller, null, [$caller]);
    app(DirectoryPermissionService::class)->flush();
    $before = ownOnlyReviewState([$theirs, $theirsNull, $orphan]);
    $items = fn (string $term) => array_map(fn (Asset $asset) => ['asset_id' => $asset->id, 'term' => $term], [$theirs, $theirsNull, $orphan, $mine, $mineNull]);
    $bulk = fn (string $action, string $term) => $this->withHeaders($headers)->postJson(route('admin.api.dam.ai-review.bulk'), ['action' => $action, 'items' => $items($term)])->assertOk();

    $accepted = $bulk('accept', 'photo');
    expect($accepted->json('data.done'))->toBe(2)->and($accepted->json('data.not_yours'))->toBe(3)
        ->and(ownOnlyReviewState([$theirs, $theirsNull, $orphan]))->toBe($before)
        ->and(DB::table('dam_asset_tag')->whereIn('asset_id', [$mine->id, $mineNull->id])->count())->toBe(2);

    $dismissed = $bulk('dismiss', 'logo');
    expect($dismissed->json('data.done'))->toBe(2)->and($dismissed->json('data.not_yours'))->toBe(3)
        ->and(ownOnlyReviewState([$theirs, $theirsNull, $orphan]))->toBe($before);
});

// ---- the shared tag list -------------------------------------------------------------------------------------------

/** Tags, pivots and removal records exactly as stored. */
function ownOnlyTagState(): array
{
    return [
        'tags'       => DB::table('dam_tags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'pivots'     => DB::table('dam_asset_tag')->orderBy('asset_id')->orderBy('tag_id')->get()->map(fn ($row) => (array) $row)->all(),
        'tombstones' => DB::table('dam_asset_tag_tombstones')->orderBy('asset_id')->orderBy('tag_id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function ownOnlyTagFixture($test): array
{
    $orphan = ownOnlyAsset($test->shared, null, 'tag-orphan', [$test->me, $test->colleague]);
    $mineOnly = Tag::create(['name' => 'only-mine']);
    $onTheirs = Tag::create(['name' => 'on-theirs']);
    $onOrphan = Tag::create(['name' => 'on-nobodys']);
    $shared = Tag::create(['name' => 'on-both']);
    $removed = Tag::create(['name' => 'was-removed']);
    $test->own->tags()->attach([$mineOnly->id, $shared->id]);
    $test->foreign->tags()->attach([$onTheirs->id, $shared->id]);
    $orphan->tags()->attach([$onOrphan->id]);
    DB::table('dam_asset_tag_tombstones')->insert(['asset_id' => $test->foreign->id, 'tag_id' => $removed->id, 'removed_by' => $test->colleague->id, 'removed_at' => now()]);
    $test->role->update(['permissions' => array_values(array_unique([...$test->role->permissions, 'dam.tags', 'dam.tags.update', 'dam.tags.delete']))]);
    app(DirectoryPermissionService::class)->flush();

    return compact('mineOnly', 'onTheirs', 'onOrphan', 'shared', 'removed');
}

it('renames and deletes shared tags only when no one else\'s file carries them, and says so without any numbers', function () {
    $tags = ownOnlyTagFixture($this);
    $before = ownOnlyTagState();
    $words = trans('dam::app.admin.permissions.own-only-tag-vocabulary');

    // 'removed' is carried by nobody: only a decision on the colleague's file names it.
    foreach (['onTheirs', 'onOrphan', 'shared', 'removed'] as $name) {
        $this->deleteJson(route('admin.dam.tags.destroy', $tags[$name]->id))->assertForbidden()->assertJsonPath('message', $words);
        $this->putJson(route('admin.dam.tags.update', $tags[$name]->id), ['name' => 'renamed-'.$name])->assertForbidden()->assertJsonPath('message', $words);
        $this->postJson(route('admin.dam.tags.mass_delete'), ['indices' => [$tags[$name]->id]])->assertForbidden()->assertJsonPath('message', $words);
    }

    expect(ownOnlyTagState())->toBe($before);

    // A mass delete with one refused tag deletes none of them.
    $this->postJson(route('admin.dam.tags.mass_delete'), ['indices' => [$tags['mineOnly']->id, $tags['onTheirs']->id, $tags['removed']->id]])->assertForbidden()
        ->assertJsonPath('message', $words);
    expect(preg_match('/\d/', $words))->toBe(0)
        ->and(ownOnlyTagState())->toBe($before);

    $this->putJson(route('admin.dam.tags.update', $tags['mineOnly']->id), ['name' => 'renamed-mine'])->assertOk();
    expect(Tag::query()->find($tags['mineOnly']->id)->name)->toBe('renamed-mine');
    $this->postJson(route('admin.dam.tags.mass_delete'), ['indices' => [$tags['mineOnly']->id]])->assertOk();
    expect(Tag::query()->find($tags['mineOnly']->id))->toBeNull()
        ->and(ownOnlyTagState()['pivots'])->toBe(array_values(array_filter($before['pivots'], fn (array $row) => $row['tag_id'] !== $tags['mineOnly']->id)));

    $this->actingAs($this->boss, 'admin');
    $this->putJson(route('admin.dam.tags.update', $tags['onTheirs']->id), ['name' => 'by-admin'])->assertOk();
    $this->deleteJson(route('admin.dam.tags.destroy', $tags['shared']->id))->assertOk();
    expect(DB::table('dam_asset_tag')->where('tag_id', $tags['shared']->id)->count())->toBe(0);
    $this->putJson(route('admin.dam.tags.update', $tags['removed']->id), ['name' => 'removed-by-admin'])->assertOk();
    expect(Tag::query()->find($tags['removed']->id)->name)->toBe('removed-by-admin')
        ->and(DB::table('dam_asset_tag_tombstones')->where('tag_id', $tags['removed']->id)->count())->toBe(1);
    $this->deleteJson(route('admin.dam.tags.destroy', $tags['removed']->id))->assertOk();
    expect(DB::table('dam_asset_tag_tombstones')->where('tag_id', $tags['removed']->id)->count())->toBe(0);
});

it('refuses to merge a tag that only someone else\'s removal decision names, and keeps names, pivots and decisions', function () {
    $tags = ownOnlyTagFixture($this);
    $before = ownOnlyTagState();
    $vocabulary = app(TagVocabulary::class);
    $refuse = function (Tag $from, Tag $into) use ($vocabulary): void {
        try {
            $vocabulary->merge(from: $from, into: $into);
            $this->fail('merge was allowed');
        } catch (HttpException $refused) {
            expect($refused->getStatusCode())->toBe(403)->and($refused->getMessage())->toBe(trans('dam::app.admin.permissions.own-only-tag-vocabulary'));
        }
    };

    // Either end carried only by a decision on a file that is not the user's.
    $refuse($tags['removed'], $tags['mineOnly']);
    $refuse($tags['mineOnly'], $tags['removed']);
    expect(ownOnlyTagState())->toBe($before);

    // An admin may; the decision follows the merge instead of vanishing.
    $this->actingAs($this->boss, 'admin');
    $vocabulary->merge(from: $tags['removed'], into: $tags['mineOnly']);
    expect(Tag::query()->find($tags['removed']->id))->toBeNull()
        ->and(DB::table('dam_asset_tag_tombstones')->where('asset_id', $this->foreign->id)->where('tag_id', $tags['mineOnly']->id)->count())->toBe(1);
});

it('applies the shared-tag rule to REST delete', function () {
    $tags = ownOnlyTagFixture($this);
    $headers = organizeApiHeaders($this->role);
    app(DirectoryPermissionService::class)->flush();
    $before = ownOnlyTagState();

    foreach (['onTheirs', 'onOrphan', 'shared', 'removed'] as $name) {
        $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tags.destroy', $tags[$name]->id))->assertForbidden();
    }
    expect(ownOnlyTagState())->toBe($before);

    // The caller's own file carries only-mine, but the file is another admin's here (the caller is a different person), so it stays too.
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tags.destroy', $tags['mineOnly']->id))->assertForbidden();
    expect(ownOnlyTagState())->toBe($before);

    $unused = Tag::create(['name' => 'carried-by-nobody']);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tags.destroy', $unused->id))->assertOk();
    expect(Tag::query()->find($unused->id))->toBeNull();
});

// ---- stack details -------------------------------------------------------------------------------------------------

function ownOnlyStack(Asset $asset, ?string $basis, ?string $key): Asset
{
    DB::table('dam_assets')->where('id', $asset->id)->update(['stack_basis' => $basis, 'stack_key' => $key]);

    return $asset->fresh();
}

function ownOnlyStackOf(Asset $asset): array
{
    return (array) DB::table('dam_assets')->where('id', $asset->id)->first(['stack_basis', 'stack_key']);
}

it('starts, replaces or clears stack details only on the user\'s own file, an empty one included', function () {
    $theirs = ownOnlyStack($this->foreign, 'product', 'SKU-1');
    $orphan = ownOnlyStack(ownOnlyAsset($this->shared, null, 'stack-orphan', [$this->me, $this->colleague]), 'campaign', 'Spring');
    $empty = ownOnlyAsset($this->shared, $this->colleague, 'stack-empty', [$this->me, $this->colleague]);
    $emptyOrphan = ownOnlyAsset($this->shared, null, 'stack-empty-orphan', [$this->me, $this->colleague]);
    $emptyMine = ownOnlyAsset($this->shared, $this->me, 'stack-empty-mine', [$this->me, $this->colleague]);
    $mine = ownOnlyStack($this->own, 'product', 'MINE-1');
    $update = fn (Asset $asset, ?string $basis, ?string $key) => $this->putJson(route('admin.dam.assets.stack.update', $asset->id), ['basis' => $basis, 'key' => $key]);

    foreach ([$theirs, $orphan] as $asset) {
        $kept = ownOnlyStackOf($asset);
        $update($asset, null, null)->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
        $update($asset, 'use_case', 'Replaced')->assertForbidden();
        expect(ownOnlyStackOf($asset))->toBe($kept);
        // Saving what is already there changes nothing, so it is not an edit.
        $update($asset, $kept['stack_basis'], $kept['stack_key'])->assertOk();
    }

    // Starting a stack on someone else's (or nobody's) file is an addition to it: refused, nothing stored.
    foreach ([$empty, $emptyOrphan] as $asset) {
        $update($asset, 'other', 'Fresh')->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-details'));
        expect(ownOnlyStackOf($asset))->toBe(['stack_basis' => null, 'stack_key' => null]);
        // Leaving it empty changes nothing.
        $update($asset, null, null)->assertOk();
    }

    $update($emptyMine, 'other', 'Fresh')->assertOk();
    expect(ownOnlyStackOf($emptyMine))->toBe(['stack_basis' => 'other', 'stack_key' => 'Fresh']);

    $update($mine, 'campaign', 'MINE-2')->assertOk();
    $update($mine, null, null)->assertOk();
    expect(ownOnlyStackOf($mine))->toBe(['stack_basis' => null, 'stack_key' => null]);

    $this->actingAs($this->boss, 'admin');
    $update($theirs, null, null)->assertOk();
    expect(ownOnlyStackOf($theirs))->toBe(['stack_basis' => null, 'stack_key' => null]);
    foreach ([$empty, $emptyOrphan] as $asset) {
        $update($asset, 'other', 'By-admin')->assertOk();
        expect(ownOnlyStackOf($asset))->toBe(['stack_basis' => 'other', 'stack_key' => 'By-admin']);
    }
});

it('stores nothing when a stack that was empty at read time is filled by someone else first', function () {
    $empty = ownOnlyAsset($this->shared, $this->colleague, 'stack-race', [$this->me, $this->colleague]);
    $stale = Asset::query()->find($empty->id);
    ownOnlyStack($empty, 'product', 'THEIRS');

    // The service judges what is stored now, not the copy the caller read earlier.
    expect(fn () => app(AssetStackService::class)->set(asset: $stale, basis: 'other', key: 'Mine'))->toThrow(HttpException::class);
    expect(ownOnlyStackOf($empty))->toBe(['stack_basis' => 'product', 'stack_key' => 'THEIRS']);
});

it('applies the stack rule to REST', function () {
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    $theirs = ownOnlyStack(ownOnlyAsset($this->shared, $this->colleague, 'rest-stack-theirs', [$caller, $this->colleague]), 'product', 'SKU-9');
    $mine = ownOnlyStack(ownOnlyAsset($this->shared, $caller, 'rest-stack-mine', [$caller]), 'product', 'MINE-9');
    $empty = ownOnlyAsset($this->shared, $this->colleague, 'rest-stack-empty', [$caller, $this->colleague]);
    $emptyOrphan = ownOnlyAsset($this->shared, null, 'rest-stack-empty-orphan', [$caller, $this->colleague]);
    $emptyMine = ownOnlyAsset($this->shared, $caller, 'rest-stack-empty-mine', [$caller]);
    app(DirectoryPermissionService::class)->flush();
    $kept = ownOnlyStackOf($theirs);

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $theirs->id), ['basis' => null, 'key' => null])->assertForbidden();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $theirs->id), ['basis' => 'other', 'key' => 'Replaced'])->assertForbidden();
    expect(ownOnlyStackOf($theirs))->toBe($kept);

    foreach ([$empty, $emptyOrphan] as $asset) {
        $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $asset->id), ['basis' => 'other', 'key' => 'Fresh'])->assertForbidden();
        expect(ownOnlyStackOf($asset))->toBe(['stack_basis' => null, 'stack_key' => null]);
    }
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $emptyMine->id), ['basis' => 'other', 'key' => 'Fresh'])->assertOk();
    expect(ownOnlyStackOf($emptyMine))->toBe(['stack_basis' => 'other', 'stack_key' => 'Fresh']);

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $mine->id), ['basis' => 'other', 'key' => 'Changed'])->assertOk();
    expect(ownOnlyStackOf($mine))->toBe(['stack_basis' => 'other', 'stack_key' => 'Changed']);
});

it('stacks a suggested group by changing the user\'s own files and counting the others as skipped', function () {
    $mine = ownOnlyStack($this->own, 'product', 'bundle-x');
    $theirs = ownOnlyStack($this->foreign, 'campaign', 'Bundle-X');
    $orphan = ownOnlyStack(ownOnlyAsset($this->shared, null, 'bundle-orphan', [$this->me, $this->colleague]), 'use_case', 'bundle-x ');
    // Members that start with a matching key but no basis count as stackless to the group, yet are still someone else's.
    $emptyTheirs = ownOnlyAsset($this->shared, $this->colleague, 'bundle-empty-theirs', [$this->me, $this->colleague]);
    $emptyOrphan = ownOnlyAsset($this->shared, null, 'bundle-empty-orphan', [$this->me, $this->colleague]);
    DB::table('dam_assets')->whereIn('id', [$emptyTheirs->id, $emptyOrphan->id])->update(['stack_key' => 'bundle-x']);
    $apply = fn () => $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'key', 'group_key' => 'bundle-x', 'basis' => 'other', 'key' => 'bundle-x']);
    $kept = [$theirs->id => ownOnlyStackOf($theirs), $orphan->id => ownOnlyStackOf($orphan), $emptyTheirs->id => ownOnlyStackOf($emptyTheirs), $emptyOrphan->id => ownOnlyStackOf($emptyOrphan)];

    $result = $apply()->assertOk();
    expect($result->json('stacked'))->toBe(1)
        ->and($result->json('skipped'))->toBe(4)
        ->and(ownOnlyStackOf($emptyTheirs))->toBe($kept[$emptyTheirs->id])
        ->and(ownOnlyStackOf($emptyOrphan))->toBe($kept[$emptyOrphan->id])
        ->and(ownOnlyStackOf($mine))->toBe(['stack_basis' => 'other', 'stack_key' => 'bundle-x'])
        ->and(ownOnlyStackOf($theirs))->toBe($kept[$theirs->id])
        ->and(ownOnlyStackOf($orphan))->toBe($kept[$orphan->id]);

    $this->actingAs($this->boss, 'admin');
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'key', 'group_key' => 'bundle-x', 'basis' => 'product', 'key' => 'bundle-x'])->assertOk();
    expect(ownOnlyStackOf($theirs)['stack_basis'])->toBe('product');
});

it('starts a stack on an empty group only for the user\'s own files and skips the others', function () {
    $theirs = ownOnlyAsset($this->shared, $this->colleague, 'stem-theirs', [$this->me, $this->colleague]);
    $orphan = ownOnlyAsset($this->shared, null, 'stem-orphan', [$this->me, $this->colleague]);
    $mine = ownOnlyAsset($this->shared, $this->me, 'stem-mine', [$this->me, $this->colleague]);
    $ids = [$theirs->id, $orphan->id, $mine->id];
    DB::table('dam_assets')->whereIn('id', $ids)->update(['name_stem' => 'stem-group']);
    $apply = fn () => $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'stem', 'group_key' => 'stem-group', 'basis' => 'other', 'key' => 'Stem']);

    $result = $apply()->assertOk();
    expect($result->json('stacked'))->toBe(1)
        ->and($result->json('skipped'))->toBe(2)
        ->and(ownOnlyStackOf($mine))->toBe(['stack_basis' => 'other', 'stack_key' => 'Stem'])
        ->and(ownOnlyStackOf($theirs))->toBe(['stack_basis' => null, 'stack_key' => null])
        ->and(ownOnlyStackOf($orphan))->toBe(['stack_basis' => null, 'stack_key' => null]);

    DB::table('dam_assets')->whereIn('id', $ids)->update(['stack_basis' => null, 'stack_key' => null]);
    $this->actingAs($this->boss, 'admin');
    $all = $apply()->assertOk();
    expect($all->json('stacked'))->toBe(3)
        ->and(ownOnlyStackOf($theirs))->toBe(['stack_basis' => 'other', 'stack_key' => 'Stem'])
        ->and(ownOnlyStackOf($orphan))->toBe(['stack_basis' => 'other', 'stack_key' => 'Stem']);
});

// ---- folder-wide tagging ---------------------------------------------------------------------------------------------

it('counts only files the user can see when a folder-wide tag skips someone else\'s', function () {
    $orphan = ownOnlyAsset($this->shared, null, 'folder-orphan', [$this->me, $this->colleague]);
    ownOnlyAsset($this->shared, $this->colleague, 'folder-hidden-2', [$this->colleague]);
    $tagged = fn (Asset $asset) => DB::table('dam_asset_tag')->where('asset_id', $asset->id)->count();

    // Visible and not mine: foreign and the ownerless one. Hidden from me (two of them) must change neither number nor sentence.
    $response = $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['directory_ids' => [$this->shared->id], 'tags' => ['folder-wide']])->assertOk();
    expect($response->json('skipped'))->toBe(2)
        ->and($response->json('message'))->toContain('1 updated, 2 skipped')
        ->and($tagged($this->own))->toBe(1)
        ->and($tagged($this->foreign))->toBe(0)
        ->and($tagged($this->hidden))->toBe(0)
        ->and($tagged($orphan))->toBe(0);

    // Only hidden files are foreign to a folder of one's own: nothing to skip, nothing to report.
    $mine = ownOnlyFolder($this->root, $this->me);
    ownOnlyContent($mine, $this->me, 'folder-own-'.uniqid().'.txt', 'own');
    ownOnlyContent($mine, $this->colleague, 'folder-unseen-'.uniqid().'.txt', 'unseen', [$this->colleague]);
    app(DirectoryPermissionService::class)->flush();
    $clean = $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['directory_ids' => [$mine->id], 'tags' => ['folder-wide']])->assertOk();
    expect($clean->json('skipped'))->toBeNull()
        ->and($clean->json('message'))->not->toContain('skipped');
});

// ---- clearing what is already there -----------------------------------------------------------------------------------

function ownOnlyMultiSelect(): MetadataField
{
    return MetadataField::query()->create([
        'code'     => 'colours', 'label' => 'Colours', 'type' => 'multi_select',
        'options'  => [['value' => 'red', 'label' => 'Red'], ['value' => 'green', 'label' => 'Green'], ['value' => 'blue', 'label' => 'Blue']],
        'position' => (int) MetadataField::query()->max('position') + 1,
    ]);
}

/** Every stored field row, typed value and tag decision of one asset, exactly as stored. */
function ownOnlyDetails(Asset $asset): array
{
    return [
        'rows'       => AssetProperty::query()->where('dam_asset_id', $asset->id)->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all(),
        'values'     => app(FieldValues::class)->forAsset($asset->id)['values'],
        'pivots'     => DB::table('dam_asset_tag')->where('asset_id', $asset->id)->orderBy('tag_id')->get()->map(fn ($row) => (array) $row)->all(),
        'tombstones' => DB::table('dam_asset_tag_tombstones')->where('asset_id', $asset->id)->orderBy('tag_id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function ownOnlyFillDetails(Asset $asset, $test): void
{
    $kept = Tag::create(['name' => 'kept-'.$asset->id]);
    $gone = Tag::create(['name' => 'gone-'.$asset->id]);
    $asset->tags()->attach($kept->id);
    DB::table('dam_asset_tag_tombstones')->insert(['asset_id' => $asset->id, 'tag_id' => $gone->id, 'removed_by' => $test->colleague->id, 'removed_at' => now()]);
}

it('refuses null, blank and empty-list clears of an existing multi-select, notes and tag decisions on someone else\'s file: web, bulk and REST', function () {
    ownOnlyMultiSelect();
    ownOnlyField('note');
    $orphan = ownOnlyAsset($this->shared, null, 'clear-orphan', [$this->me, $this->colleague]);
    $headers = null;

    $this->actingAs($this->boss, 'admin');
    foreach ([$this->foreign, $orphan] as $asset) {
        $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['colours' => ['red', 'blue'], 'note' => 'keep me']])->assertOk();
        ownOnlyFillDetails($asset, $this);
    }
    $this->actingAs($this->me, 'admin');
    $kept = [$this->foreign->id => ownOnlyDetails($this->foreign), $orphan->id => ownOnlyDetails($orphan)];
    expect($kept[$this->foreign->id]['values'])->toBe(['colours' => ['red', 'blue'], 'note' => 'keep me'])
        ->and($kept[$this->foreign->id]['tombstones'])->not->toBe([]);

    foreach ([$this->foreign, $orphan] as $asset) {
        foreach ([[], null, '', ' '] as $blank) {
            $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['colours' => $blank, 'note' => $blank === [] ? null : $blank]])->assertForbidden();
        }
        $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['colours' => ['green']], 'expected' => ['colours' => ['red', 'blue']]])->assertForbidden();
        $bulk = $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$asset->id], 'fields' => ['colours' => [], 'note' => null]])->assertForbidden();
        expect($bulk->json('message'))->toBe(trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => 1]));
        $this->postJson(route('admin.dam.assets.remove-tag'), ['tag' => 'kept-'.$asset->id, 'asset_id' => $asset->id])->assertForbidden();
        expect(ownOnlyDetails($asset))->toBe($kept[$asset->id]);
    }

    // REST: a separate caller, so it starts from its own session.
    $headers = organizeApiHeaders($this->role);
    $caller = test()->apiCaller;
    $caller->update(['status' => 1]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $caller->id]);
    DB::table('dam_asset_user_access')->insert([['asset_id' => $this->foreign->id, 'admin_id' => $caller->id], ['asset_id' => $orphan->id, 'admin_id' => $caller->id]]);
    app(DirectoryPermissionService::class)->flush();

    foreach ([$this->foreign, $orphan] as $asset) {
        foreach ([['fields' => ['colours' => []]], ['fields' => ['colours' => null, 'note' => '']], ['tags' => []], ['fields' => ['colours' => []], 'tags' => []]] as $body) {
            $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $asset->id), $body)->assertForbidden();
        }
        expect(ownOnlyDetails($asset))->toBe($kept[$asset->id]);
    }

    // Controls: the owner clears their own, and an unrestricted role clears someone else's.
    $mine = ownOnlyAsset($this->shared, $caller, 'clear-mine', [$caller]);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $mine->id), ['fields' => ['colours' => ['green'], 'note' => 'mine']])->assertOk();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $mine->id), ['fields' => ['colours' => [], 'note' => null]])->assertOk();
    expect(ownOnlyDetails($mine)['values'])->toBe([]);

    app('auth')->forgetGuards();
    $this->actingAs($this->boss, 'admin');
    $this->putJson(route('admin.dam.assets.fields.update', $this->foreign->id), ['fields' => ['colours' => [], 'note' => null]])->assertOk();
    expect(ownOnlyDetails($this->foreign)['values'])->toBe([]);
    $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$orphan->id], 'fields' => ['colours' => []]])->assertOk()->assertJsonPath('count', 1);
});

it('keeps the bytes and metadata of an existing custom thumbnail when someone else tries to replace it, including at the service', function () {
    $theirs = ownOnlyContent($this->shared, $this->colleague, 'keep-thumb.stl', ownOnlyModelBytes('stl'), [$this->me, $this->colleague]);
    $mine = ownOnlyContent($this->shared, $this->me, 'my-thumb.stl', ownOnlyModelBytes('stl'), [$this->me]);
    $disk = Storage::disk(Directory::getAssetDisk());
    $set = fn (Asset $asset) => $this->post(route('admin.dam.assets.custom_thumbnail.store', $asset->id), ['thumbnail' => UploadedFile::fake()->image('cover.png', 32, 32)], ['Accept' => 'application/json']);
    $stored = fn (Asset $asset) => CustomThumbnailService::location($asset->fresh());

    // The owner (and an administrator) made the first thumbnail.
    $this->actingAs($this->colleague, 'admin');
    $set($theirs)->assertCreated();
    $first = $stored($theirs);
    $bytes = hash('sha256', $disk->get($first['path']));
    $meta = $theirs->fresh()->meta_data;
    $files = $disk->allFiles();
    expect($first)->not->toBeNull();

    $this->actingAs($this->me, 'admin');
    $set($theirs)->assertForbidden()->assertJsonPath('message', trans('dam::app.admin.permissions.own-only-thumbnail'));
    // The service asks again on the locked row, so a caller that skipped the controller is stopped too, and its upload is removed.
    expect(fn () => app(CustomThumbnailService::class)->store(asset: $theirs->fresh(), upload: UploadedFile::fake()->image('direct.png', 16, 16)))
        ->toThrow(HttpException::class);
    expect($theirs->fresh()->meta_data)->toBe($meta)
        ->and(hash('sha256', $disk->get($first['path'])))->toBe($bytes)
        ->and($disk->allFiles())->toBe($files);

    // Controls: the owner replaces their own, an unrestricted role replaces someone else's and the old bytes go.
    $set($mine)->assertCreated();
    $set($mine)->assertCreated();
    expect(collect($disk->allFiles())->filter(fn ($path) => str_starts_with($path, 'custom-thumbnails/'.$mine->id.'/'))->count())->toBe(1);
    $this->actingAs($this->boss, 'admin');
    $set($theirs)->assertCreated();
    expect($stored($theirs)['path'])->not->toBe($first['path'])
        ->and($disk->exists($first['path']))->toBeFalse();
});
