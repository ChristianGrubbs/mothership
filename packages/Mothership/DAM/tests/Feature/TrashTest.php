<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Models\ActionRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * P2 S4: the 30-day trash. Every delete moves an asset to the trash, where it is missing everywhere (404 on every
 * per-asset route, admins included; 410 on its share links; out of every listing and count) until Restore puts it
 * back exactly as it was, or the Trash page's Delete permanently or dam:trash:purge after 30 days deletes it for good.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    $this->admin = $this->loginAsAdmin();
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->root = $root;
    $this->folder = Directory::factory()->create(['name' => 'Trash probe folder', 'parent_id' => $root->id]);
});

/** The body of a local response, whatever response class carried it. */
function trashBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
        $base instanceof StreamedResponse   => (string) $response->streamedContent(),
        default                             => (string) $base->getContent(),
    };
}

function trashPng(int $size, array $rgb, string $name = 'robot.png'): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    $path = tempnam(sys_get_temp_dir(), 'trash-png-');
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

/** Uploads into the folder the way the Library does (robot.png unless named); a name the folder holds is a new revision. */
function trashUpload($test, Directory $folder, ?UploadedFile $file = null): Asset
{
    $id = $test->postJson(route('admin.dam.assets.upload'), ['files' => [$file ?? trashPng(8, [30, 90, 150])], 'directory_id' => $folder->id])
        ->assertStatus(201)
        ->json('files.0.id');

    return Asset::findOrFail($id);
}

function trashFolderPath(Directory $folder): string
{
    return Directory::ASSETS_DIRECTORY.'/'.$folder->fresh()->generatePath();
}

/** Every row the asset owns or is named in, as stored. */
function trashSnapshot(int $id): array
{
    $rows = fn (string $table, string $column) => DB::table($table)->where($column, $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    return [
        'asset'         => (array) DB::table('dam_assets')->where('id', $id)->first(),
        'files'         => $rows('dam_asset_files', 'dam_asset_id'),
        'attachments'   => $rows('dam_attachments', 'dam_asset_id'),
        'versions'      => $rows('dam_asset_versions', 'dam_asset_id'),
        'properties'    => $rows('dam_asset_properties', 'dam_asset_id'),
        'comments'      => $rows('dam_asset_comments', 'dam_asset_id'),
        'tags'          => $rows('dam_asset_tag', 'asset_id'),
        'folders'       => $rows('dam_asset_directory', 'asset_id'),
        'shares'        => DB::table('dam_shares')->where('share_type', Share::TYPE_ASSET)->where('target_id', $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'relationships' => DB::table('dam_asset_relationships')->where('from_asset_id', $id)->orWhere('to_asset_id', $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function trashUseGrants(): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** A member granted `$folder` under grants, holding the Library keys plus the given trash keys. */
function trashMember(Directory $folder, array $trashKeys): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update', 'dam.asset.destroy', 'dam.trash', 'dam.trash.index', ...$trashKeys],
    ]);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
}

it('restores a deleted asset with its bytes, metadata, tags, properties, attachments, revisions and relationships', function () {
    $asset = trashUpload($this, $this->folder);
    $revision = trashPng(9, [200, 40, 10]);
    $bytes = (string) file_get_contents($revision->getRealPath());
    expect(trashUpload($this, $this->folder, $revision)->id)->toBe($asset->id);
    attachPreviewFile($asset, 'assets/Root/robot-captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, label: 'Captions');
    $asset->refresh();
    $asset->meta_data = array_merge($asset->meta_data ?? [], ['Title' => 'Robot', 'Credit' => 'Studio']);
    $asset->saveQuietly();
    Tag::query()->create(['name' => 'trash-round-trip'])->assets()->attach($asset->id);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Owner', 'value' => 'Media']);
    AssetComments::factory()->create(['dam_asset_id' => $asset->id, 'admin_id' => $this->admin->id, 'comments' => 'keep me']);
    $other = Asset::factory()->create();
    AssetRelationship::factory()->create(['from_asset_id' => $asset->id, 'to_asset_id' => $other->id, 'type' => AssetRelationship::TYPE_RELATED_TO]);
    Share::factory()->forAsset($asset->id)->create();

    $before = trashSnapshot($asset->id);
    $path = $asset->path;
    // A later second, so a trash or restore that touched updated_at shows (it caught one).
    $this->travel(3)->seconds();
    expect(collect($before['files'])->max('revision_number'))->toBe(2)
        ->and($before['versions'])->not->toBeEmpty()
        ->and($before['attachments'])->toHaveCount(2)
        ->and(hash('sha256', trashBody($this->get(route('admin.dam.assets.download', $asset->id))->assertOk())))->toBe(hash('sha256', $bytes));

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk()->assertJsonPath('success', true);

    $trashed = Asset::onlyTrashed()->findOrFail($asset->id);
    expect($trashed->trashed_from_path)->toBe($path)
        ->and($trashed->path)->toBe(Asset::TRASH_DIRECTORY.'/'.$asset->id.'/'.$asset->file_name)
        ->and((int) $trashed->deleted_by)->toBe($this->admin->id)
        ->and(Asset::find($asset->id))->toBeNull();
    $this->get(route('admin.dam.assets.download', $asset->id))->assertNotFound();

    $this->postJson(route('admin.dam.trash.restore', $asset->id))->assertOk()->assertJsonPath('success', true);

    expect(trashSnapshot($asset->id))->toBe($before);
    expect(hash('sha256', trashBody($this->get(route('admin.dam.assets.download', $asset->id))->assertOk())))->toBe(hash('sha256', $bytes));
});

it('refuses a restore while a folder delete is pending, and allows it once that has finished', function () {
    $asset = trashUpload($this, $this->folder);
    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();

    $request = ActionRequest::query()->create([
        'event_type' => EventType::DELETE_DIRECTORY->value,
        'status'     => 'pending',
        'admin_id'   => $this->admin->id,
    ]);

    $this->postJson(route('admin.dam.trash.restore', $asset->id))->assertStatus(409)->assertJsonPath('success', false);
    expect(Asset::onlyTrashed()->find($asset->id))->not->toBeNull();

    $request->update(['status' => 'completed']);

    $this->postJson(route('admin.dam.trash.restore', $asset->id))->assertOk();
});

it('restores under the next free name when its name was taken while it was in the trash', function () {
    $first = trashUpload($this, $this->folder);
    $name = $first->file_name;
    $this->deleteJson(route('admin.dam.assets.destroy', $first->id))->assertOk();

    // The name is free while the first one is in the trash: the same upload makes a new asset under it.
    $second = trashUpload($this, $this->folder);
    expect($second->id)->not->toBe($first->id)
        ->and($second->path)->toBe(trashFolderPath($this->folder).'/'.$name);

    $stem = pathinfo($name, PATHINFO_FILENAME);

    // The message names the new name instead of renaming silently (UX eval 2026-10-10, PERM-12).
    $this->postJson(route('admin.dam.trash.restore', $first->id))->assertOk()
        ->assertJsonPath('message', trans('dam::app.admin.dam.trash.restored-renamed', ['old' => $name, 'name' => $stem.'(1).png']));

    expect($first->fresh()->path)->toBe(trashFolderPath($this->folder).'/'.$stem.'(1).png')
        ->and($first->fresh()->file_name)->toBe($stem.'(1).png')
        ->and($second->fresh()->path)->toBe(trashFolderPath($this->folder).'/'.$name)
        ->and($first->fresh()->directories()->pluck('dam_directories.id')->all())->toBe([$this->folder->id]);
});

it('restores into the Library root when the folder it was in is gone', function () {
    $asset = trashUpload($this, $this->folder);

    (new DeleteDirectory($this->folder->id, $this->admin->id))->handle();

    expect(Directory::find($this->folder->id))->toBeNull()
        ->and(Asset::onlyTrashed()->whereKey($asset->id)->exists())->toBeTrue();

    $this->postJson(route('admin.dam.trash.restore', $asset->id))->assertOk();

    $restored = Asset::findOrFail($asset->id);
    expect($restored->path)->toBe(trashFolderPath($this->root).'/'.$asset->file_name)
        ->and($restored->trashed_from_path)->toBeNull()
        ->and($restored->directories()->pluck('dam_directories.id')->all())->toBe([$this->root->id]);
});

it('purges what has been in the trash for more than 30 days, reclaims its blob and runs daily', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $disk = Storage::disk(Directory::getAssetDisk());
    $make = function (string $name) use ($disk): array {
        $path = 'assets/Root/'.$name.'.bin';
        $disk->put($path, 'trash-purge-'.$name);
        $asset = Asset::factory()->create(['path' => $path, 'file_name' => $name.'.bin']);
        $asset->delete();

        return [$asset, $asset->files()->firstOrFail()->fileObject];
    };
    [$old, $oldObject] = $make('old');
    [$young, $youngObject] = $make('young');
    DB::table('dam_assets')->where('id', $old->id)->update(['deleted_at' => now()->subDays(30)->subMinute()]);
    DB::table('dam_assets')->where('id', $young->id)->update(['deleted_at' => now()->subDays(30)->addMinute()]);

    $this->artisan('dam:trash:purge')->expectsOutputToContain('"purged":1')->assertSuccessful();

    expect(Asset::withTrashed()->find($old->id))->toBeNull()
        ->and(FileObject::find($oldObject->id))->toBeNull()
        ->and(Asset::onlyTrashed()->whereKey($young->id)->exists())->toBeTrue()
        ->and(FileObject::find($youngObject->id))->not->toBeNull();
    $disk->assertMissing($oldObject->path);
    $disk->assertExists($youngObject->path);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'dam:trash:purge'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 3 * * *');

    Carbon::setTestNow();
});

it('lets only an admin holding the delete right delete permanently, and answers a hidden item like a missing one', function () {
    trashUseGrants();
    $hiddenFolder = Directory::factory()->create(['name' => 'Trash hidden folder', 'parent_id' => $this->root->id]);
    $visible = trashUpload($this, $this->folder);
    $hidden = trashUpload($this, $hiddenFolder);
    $visible->delete();
    $hidden->delete();
    $missingId = Asset::withTrashed()->max('id') + 1000;

    // Restore and view rights only: Delete permanently is refused.
    $this->actingAs(trashMember($this->folder, ['dam.trash.restore']), 'admin');
    $this->getJson(route('admin.dam.trash.list'))->assertOk()->assertJsonPath('assets.0.can_delete', false);
    $this->deleteJson(route('admin.dam.trash.destroy', $visible->id))->assertForbidden();
    expect(Asset::onlyTrashed()->whereKey($visible->id)->exists())->toBeTrue();

    // The delete right, but for an item the member never could see: it answers like an id that does not exist.
    $this->actingAs(trashMember($this->folder, ['dam.trash.restore', 'dam.trash.delete']), 'admin');
    $hiddenAnswer = $this->deleteJson(route('admin.dam.trash.destroy', $hidden->id));
    $missingAnswer = $this->deleteJson(route('admin.dam.trash.destroy', $missingId));
    expect($hiddenAnswer->getStatusCode())->toBe(404)->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
    $hiddenRestore = $this->postJson(route('admin.dam.trash.restore', $hidden->id));
    expect($hiddenRestore->getStatusCode())->toBe(404)
        ->and($hiddenRestore->getContent())->toBe($this->postJson(route('admin.dam.trash.restore', $missingId))->getContent())
        ->and(Asset::onlyTrashed()->whereKey($hidden->id)->exists())->toBeTrue();

    // A live asset is not in the trash: neither action reaches it.
    $live = trashUpload($this->actingAs($this->admin, 'admin'), $this->folder);
    $this->deleteJson(route('admin.dam.trash.destroy', $live->id))->assertNotFound();
    $this->postJson(route('admin.dam.trash.restore', $live->id))->assertNotFound();

    // A full admin deletes it for good.
    $this->getJson(route('admin.dam.trash.list'))->assertOk()->assertJsonPath('assets.0.can_delete', true);
    $this->deleteJson(route('admin.dam.trash.destroy', $visible->id))->assertOk()->assertJsonPath('success', true);
    expect(Asset::withTrashed()->find($visible->id))->toBeNull()->and(Asset::find($live->id))->not->toBeNull();
});

it('offers restore on the Trash page only to a role that holds it', function () {
    trashUseGrants();
    $asset = trashUpload($this, $this->folder);
    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();

    $this->actingAs(trashMember($this->folder, ['dam.trash.restore']), 'admin');
    $this->getJson(route('admin.dam.trash.list'))->assertOk()->assertJsonPath('assets.0.can_restore', true);

    $this->actingAs(trashMember($this->folder, []), 'admin');
    $this->getJson(route('admin.dam.trash.list'))->assertOk()->assertJsonPath('assets.0.can_restore', false);
});

it('lists the trashed assets the viewer could see, with who deleted each and when', function () {
    trashUseGrants();
    Carbon::setTestNow('2026-10-07 09:30:00');
    $hiddenFolder = Directory::factory()->create(['name' => 'Trash hidden folder', 'parent_id' => $this->root->id]);
    $visible = trashUpload($this, $this->folder);
    $hidden = trashUpload($this, $hiddenFolder, trashPng(8, [1, 2, 3], 'hidden.png'));
    $live = trashUpload($this, $this->folder, trashPng(7, [10, 10, 10], 'live.png'));
    $this->deleteJson(route('admin.dam.assets.destroy', $visible->id))->assertOk();
    $this->deleteJson(route('admin.dam.assets.destroy', $hidden->id))->assertOk();
    Carbon::setTestNow();

    $member = trashMember($this->folder, ['dam.trash.restore']);
    $this->actingAs($member, 'admin');
    $this->get(route('admin.dam.trash.index'))->assertOk();

    $list = $this->getJson(route('admin.dam.trash.list'))->assertOk();
    expect(collect($list->json('assets'))->pluck('id')->all())->toBe([$visible->id])
        ->and($list->json('meta.total'))->toBe(1)
        ->and($list->json('meta.retention_days'))->toBe(30)
        ->and($list->json('assets.0'))->toMatchArray([
            'file_name'  => $visible->file_name,
            'location'   => $this->folder->fresh()->generatePath(),
            'deleted_by' => $this->admin->name,
        ])
        ->and(Carbon::parse($list->json('assets.0.deleted_at'))->toDateTimeString())->toBe('2026-10-07 09:30:00')
        ->and(Carbon::parse($list->json('assets.0.purge_after'))->toDateTimeString())->toBe('2026-11-06 09:30:00')
        ->and(str_contains($list->getContent(), '"id":'.$hidden->id.','))->toBeFalse()
        ->and(collect($list->json('assets'))->pluck('id'))->not->toContain($live->id);

    // The member restores what it could see; it is back in the folder and out of the trash.
    $this->postJson(route('admin.dam.trash.restore', $visible->id))->assertOk();
    expect($this->getJson(route('admin.dam.trash.list'))->json('assets'))->toBe([])
        ->and(Asset::find($visible->id)?->path)->toBe(trashFolderPath($this->folder).'/'.$visible->file_name);
});

it('answers a trashed asset\'s share links with 410, leaves it out of a folder share and serves the link again after Restore', function () {
    $asset = trashUpload($this, $this->folder);
    $kept = trashUpload($this, $this->folder, trashPng(7, [10, 200, 10], 'kept.png'));
    $assetShare = Share::factory()->forAsset($asset->id)->create();
    $folderShare = Share::factory()->forDirectory($this->folder->id)->create();
    $listed = fn () => collect($this->getJson(route('dam.share.list_assets', $folderShare->urlToken()))->assertOk()->json('data'))->pluck('id')->all();
    expect($listed())->toContain($asset->id);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();

    $this->get(route('dam.share.show', $assetShare->urlToken()))->assertStatus(410);
    $this->get(route('dam.share.download', $assetShare->urlToken()))->assertStatus(410);
    $this->get(route('dam.share.thumbnail', ['token' => $assetShare->urlToken(), 'assetId' => $asset->id]))->assertStatus(410);
    $this->get(route('dam.share.asset_view', ['token' => $folderShare->urlToken(), 'assetId' => $asset->id]))->assertNotFound();
    expect($listed())->toBe([$kept->id])
        ->and(Share::query()->whereKey($assetShare->id)->exists())->toBeTrue();
    $folderPage = $this->get(route('dam.share.show', $folderShare->urlToken()))->assertOk();
    expect(str_contains($folderPage->getContent(), route('dam.share.thumbnail', ['token' => $folderShare->urlToken(), 'assetId' => $asset->id])))->toBeFalse();

    $this->postJson(route('admin.dam.trash.restore', $asset->id))->assertOk();

    $this->get(route('dam.share.show', $assetShare->urlToken()))->assertOk();
    $this->get(route('dam.share.download', $assetShare->urlToken()))->assertOk();
    expect($listed())->toContain($asset->id);
});

it('moves an asset to the trash from the REST API and the mass delete', function () {
    $headers = $this->getAuthenticationHeaders();
    $rest = Asset::factory()->create();
    $mass = Asset::factory()->createMany(2);

    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.assets.destroy', $rest->id))->assertOk();
    $this->postJson(route('admin.dam.assets.mass_delete'), ['indices' => $mass->pluck('id')->all()])->assertOk();

    foreach ([$rest, ...$mass] as $asset) {
        $this->assertSoftDeleted('dam_assets', ['id' => $asset->id]);
        expect(Asset::onlyTrashed()->findOrFail($asset->id)->path)->toStartWith(Asset::TRASH_DIRECTORY.'/'.$asset->id.'/');
    }
});

it('answers a trashed asset with 404 on its per-asset routes and leaves it out of listings and counts, for a full admin too', function () {
    $asset = trashUpload($this, $this->folder);
    $kept = trashUpload($this, $this->folder, trashPng(7, [90, 90, 10], 'kept.png'));
    $path = $asset->path;
    $asset->delete();
    $missingId = Asset::withTrashed()->max('id') + 1000;
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
    config(['dam.tree.show_assets' => true]);

    $requests = fn (int $id): array => [
        ['GET', route('admin.dam.assets.show', $id)],
        ['GET', route('admin.dam.assets.edit', $id)],
        ['GET', route('admin.dam.assets.info', $id)],
        ['GET', route('admin.dam.assets.download', $id)],
        ['GET', route('admin.dam.assets.download_compressed', $id)],
        ['GET', route('admin.dam.assets.custom_download', [$id, 'format' => 'png'])],
        ['GET', route('admin.dam.assets.metadata', $id)],
        ['GET', route('admin.dam.assets.files.index', $id)],
        ['GET', route('admin.dam.assets.relationships.index', $id)],
        ['GET', route('admin.dam.file.cover-art', $id)],
        ['DELETE', route('admin.dam.assets.destroy', $id)],
    ];
    foreach (array_map(null, $requests($asset->id), $requests($missingId)) as [$trashed, $missing]) {
        $answer = $this->json(...$trashed);
        expect($answer->getStatusCode())->toBe(404, $trashed[1])
            ->and($answer->getContent())->toBe($this->json(...$missing)->getContent(), $trashed[1]);
    }

    // Its old path and its trash path both read like a path nothing holds.
    $nothing = $this->get(route('admin.dam.file.fetch', 'assets/Root/nothing-here.png'))->getContent();
    foreach ([$path, Asset::onlyTrashed()->findOrFail($asset->id)->path] as $read) {
        expect($this->get(route('admin.dam.file.fetch', $read))->assertNotFound()->getContent())->toBe($nothing);
    }

    // Listings and counts.
    $explorer = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->assertOk()->json('assets'))->pluck('id');
    expect($explorer)->toContain($kept->id)->not->toContain($asset->id);
    $grid = collect($this->getJson(route('admin.dam.assets.index'), $ajax)->assertOk()->json('records'))->pluck('id')->map(fn ($id) => (int) $id);
    expect($grid)->toContain($kept->id)->not->toContain($asset->id);
    expect(collect($this->getJson(route('admin.dam.directory.assets', $this->folder->id))->assertOk()->json('data'))->pluck('id')->all())->toBe([$kept->id])
        ->and($this->postJson(route('admin.dam.directory.asset_counts'), ['ids' => [$this->folder->id]])->assertOk()->json('data'))->toBe([(string) $this->folder->id => 1])
        ->and($this->postJson(route('admin.dam.explorer.count_items'), ['asset_ids' => [$asset->id, $kept->id]])->assertOk()->json('file_count'))->toBe(1)
        ->and(collect($this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => $asset->id.','.$kept->id]))->assertOk()->json())->pluck('id')->all())->toBe([$kept->id])
        ->and(collect($this->getJson(route('admin.home.data', ['widget' => 'recent', 'limit' => 24]))->assertOk()->json('assets'))->pluck('id'))->not->toContain($asset->id);
});

/**
 * An AssetDeletionService that runs $before just ahead of each delete: another request's Restore (or Restore and
 * trash again) committing in the gap between the purge choosing an asset and the delete locking its row. SQLite
 * runs one connection, so the race is staged in that gap rather than raced.
 */
function trashDeletionsWith(Closure $before): AssetDeletionService
{
    return new class($before) extends AssetDeletionService
    {
        public function __construct(private Closure $before) {}

        public function delete(Asset $asset, bool $force = false, ...$rest): array
        {
            ($this->before)($asset);

            return parent::delete($asset, $force, ...$rest);
        }
    };
}

/** Another request's trash service, with the real deletions behind it. */
function trashElsewhere(): AssetTrashService
{
    return new AssetTrashService(new AssetDeletionService);
}

it('purges only what is still trashed and past 30 days under its delete\'s lock, skipping a restore or a re-trash since the list was read', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $disk = Storage::disk(Directory::getAssetDisk());
    $make = function (string $name) use ($disk): array {
        $path = 'assets/Root/'.$name.'.bin';
        $disk->put($path, 'trash-race-'.$name);
        $asset = Asset::factory()->create(['path' => $path, 'file_name' => $name.'.bin']);
        $asset->directories()->attach($this->folder->id);
        $object = $asset->files()->firstOrFail()->fileObject;
        $asset->delete();
        DB::table('dam_assets')->where('id', $asset->id)->update(['deleted_at' => now()->subDays(31)]);

        return [$asset, $object];
    };
    [$restored, $restoredObject] = $make('restored');
    [$retrashed, $retrashedObject] = $make('retrashed');
    [$expired, $expiredObject] = $make('expired');

    $this->app->instance(AssetDeletionService::class, trashDeletionsWith(function (Asset $asset) use ($restored, $retrashed): void {
        if ($asset->id === $restored->id) {
            trashElsewhere()->restore($asset);
        }

        if ($asset->id === $retrashed->id) {
            trashElsewhere()->restore($asset);
            trashElsewhere()->trash(Asset::findOrFail($asset->id));
        }
    }));

    $result = app(AssetTrashService::class)->purgeExpired();

    expect(Asset::find($restored->id))->not->toBeNull()
        ->and(FileObject::find($restoredObject->id))->not->toBeNull()
        ->and(Asset::onlyTrashed()->find($retrashed->id)?->deleted_at?->toDateTimeString())->toBe('2026-10-07 12:00:00')
        ->and(FileObject::find($retrashedObject->id))->not->toBeNull()
        ->and(Asset::withTrashed()->find($expired->id))->toBeNull()
        ->and(FileObject::find($expiredObject->id))->toBeNull()
        ->and($result)->toBe(['purged' => 1, 'skipped' => 2, 'failed' => 0]);
    $disk->assertExists($restoredObject->path);
    $disk->assertExists($retrashedObject->path);

    Carbon::setTestNow();
});

it('refuses to delete for good an asset restored after the Trash page checked it, answering as for an id not in the trash', function () {
    $asset = trashUpload($this, $this->folder);
    $live = trashUpload($this, $this->folder, trashPng(7, [1, 1, 1], 'live.png'));
    $object = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;
    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();

    $this->app->instance(AssetDeletionService::class, trashDeletionsWith(fn (Asset $candidate) => trashElsewhere()->restore($candidate)));
    $refused = $this->deleteJson(route('admin.dam.trash.destroy', $asset->id));
    $this->app->forgetInstance(AssetDeletionService::class);
    $notTrashed = $this->deleteJson(route('admin.dam.trash.destroy', $live->id));

    expect($refused->getStatusCode())->toBe(404)
        ->and($refused->getContent())->toBe($notTrashed->getContent())
        ->and(Asset::find($asset->id)?->path)->toBe(trashFolderPath($this->folder).'/'.$asset->file_name)
        ->and(FileObject::find($object->id))->not->toBeNull();
    Storage::disk(Directory::getAssetDisk())->assertExists($object->path);

    // The service answers the same way to a caller holding a stale, trashed model.
    $stale = Asset::withTrashed()->findOrFail($asset->id)->forceFill(['deleted_at' => now()]);
    expect(fn () => app(AssetTrashService::class)->purge($stale))->toThrow(ModelNotFoundException::class)
        ->and(Asset::find($asset->id))->not->toBeNull();
});

/** @return list<string> */
function trashZipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'trash-zip-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = basename((string) $zip->getNameIndex($i));
    }
    $zip->close();
    @unlink($path);
    sort($names);

    return $names;
}

it('keeps a trashed asset out of folder and selection ZIPs and out of keyword and semantic search, for a full admin and a member, until Restore', function () {
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);
    $live = trashUpload($this, $this->folder, trashPng(7, [10, 120, 10], 'live.png'));
    $trashed = trashUpload($this, $this->folder, trashPng(8, [120, 10, 10], 'gone.png'));
    $sheets = [];
    foreach ([$live, $trashed] as $asset) {
        DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => 'falcon mascot', 'caption' => 'falcon mascot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'text' => 'Mascot booking', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
        $sheets[$asset->id] = attachPreviewFile($asset, 'assets/Root/'.$asset->id.'-sheet.pdf', 'application/pdf', AssetFile::ROLE_SIDECAR)->id;
    }
    $this->deleteJson(route('admin.dam.assets.destroy', $trashed->id))->assertOk();

    $member = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create([
        'permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download', 'dam.asset.download_compressed', 'dam.directory', 'dam.directory.download_zip'],
    ])->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $member->role_id, 'created_at' => now(), 'updated_at' => now()]);
    $missingId = Asset::withTrashed()->max('id') + 1000;
    $viewers = ['full admin' => $this->admin, 'member' => $member];

    $check = function (bool $trashedIsBack) use ($viewers, $live, $trashed, $sheets, $missingId): void {
        foreach ($viewers as $who => $viewer) {
            $this->actingAs($viewer, 'admin');
            app(DirectoryPermissionService::class)->flush();
            $expected = $trashedIsBack ? [$live->id, $trashed->id] : [$live->id];
            sort($expected);

            // Folder ZIP.
            $entries = trashZipEntries(trashBody($this->get(route('admin.dam.directory.zip_download', $this->folder->id))->assertOk()));
            expect(in_array($live->file_name, $entries, true))->toBeTrue("{$who} folder zip live")
                ->and(in_array($trashed->file_name, $entries, true))->toBe($trashedIsBack, "{$who} folder zip trashed");

            // Selection ZIP: the attachments chosen from one asset.
            $selection = fn (int $id) => $this->get(route('admin.dam.file.asset-files-zip', ['asset' => $id, 'files' => [$sheets[$id] ?? $sheets[$live->id]]]));
            expect(trashZipEntries(trashBody($selection($live->id)->assertOk())))->not->toBe([]);
            if ($trashedIsBack) {
                expect(trashZipEntries(trashBody($selection($trashed->id)->assertOk())))->not->toBe([]);
            } else {
                $answer = $selection($trashed->id);
                expect($answer->getStatusCode())->toBe(404, "{$who} selection zip")
                    ->and($answer->getContent())->toBe($this->get(route('admin.dam.file.asset-files-zip', ['asset' => $missingId, 'files' => [$sheets[$trashed->id]]]))->getContent());
            }

            // Keyword (words that match) and semantic (words that do not, so only the vectors find them) candidates.
            foreach (['keyword' => 'mascot', 'semantic' => 'zebra crossing'] as $kind => $words) {
                $found = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => $words]))->assertOk()->json('assets'))
                    ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                expect($found)->toBe($expected, "{$who} {$kind} search");
            }
        }
    };

    $check(false);
    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.trash.restore', $trashed->id))->assertOk();
    $check(true);
});

it('names the live share links a file has in its Delete confirm, and says nothing about links when it has none (PERM-12)', function () {
    $asset = trashUpload($this, $this->folder);

    // The confirm's opening sentence, which has no character the page escapes.
    $opening = fn (int $count) => strtok(trans_choice('dam::app.admin.dam.asset.delete-live-links-confirm', $count, ['count' => $count]), '.');

    Share::factory()->forAsset($asset->id)->revoked()->create();
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertDontSee($opening(1), false);

    Share::factory()->forAsset($asset->id)->count(2)->create();
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertSee($opening(2), false);
});
