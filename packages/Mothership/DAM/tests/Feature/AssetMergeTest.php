<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Mothership\AdminApi\Models\Apikey;
use Mothership\AdminApi\Services\ApiUserProvisioner;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Services\Merge\MergeGate;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * P3b-1 (D7): merging existing assets into a stack links, never moves. The destination's new slots name the
 * sources' blobs; each source stays as it was, hidden as `merged`, until a split gives it back exactly or a finalize
 * makes the merge permanent.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    $this->admin = $this->loginAsAdmin();
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->folder = Directory::factory()->create(['name' => 'Merge probe folder', 'parent_id' => $root->id]);
});

function mergeBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
        $base instanceof StreamedResponse   => (string) $response->streamedContent(),
        default                             => (string) $base->getContent(),
    };
}

function mergePng(int $size, array $rgb, string $name): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    $path = tempnam(sys_get_temp_dir(), 'merge-png-');
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

/** Uploads into the folder the way the Library does; a name the folder holds becomes a new revision. */
function mergeUpload($test, Directory $folder, UploadedFile $file): Asset
{
    $id = $test->postJson(route('admin.dam.assets.upload'), ['files' => [$file], 'directory_id' => $folder->id])
        ->assertStatus(201)
        ->json('files.0.id');

    return Asset::findOrFail($id);
}

/** A new blob with the given bytes, as an upload would store it. */
function mergeBlob(string $bytes, string $mime = 'image/png', string $extension = 'png'): FileObject
{
    $sha = hash('sha256', $bytes);
    $path = app(ContentStore::class)->objectPath($sha, $extension);
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);

    return FileObject::factory()->create(['sha256' => $sha, 'size' => strlen($bytes), 'disk' => Directory::getAssetDisk(), 'path' => $path, 'mime_type' => $mime]);
}

/** Every row the asset owns or is named in, as stored. */
function mergeSnapshot(int $id): array
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

/** Which assets name each blob: what "reference counts unchanged" means when references are rows, not counters. */
function mergeBlobReferences(): array
{
    return DB::table('dam_file_objects')->orderBy('id')->get(['id', 'sha256'])
        ->mapWithKeys(fn ($object) => [$object->sha256 => FileObject::query()->find($object->id)->isReferenced()])
        ->all();
}

function mergeUseGrants(): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** A member under grants, granted the folders, holding the given ACL keys. */
/** A small valid JPEG whose bytes depend on `$tag`, standing for a captured picture. */
function mergeJpeg(string $tag): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, crc32($tag) & 255, (crc32($tag) >> 8) & 255, (crc32($tag) >> 16) & 255));
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function mergeMember(array $folders, array $permissions): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    foreach ($folders as $folder) {
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
}

dataset('mergeAccessModels', [AccessModel::GRANTS, AccessModel::LISTS]);
dataset('mergeViewers', ['view-only member', 'all-folders role', 'full admin']);

function mergeUseModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** The read-only ACL keys of an ordinary member: they open, download and read an asset and its folder (and pick it for a product), nothing more. */
const MERGE_READ_KEYS = [
    'dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.asset.download', 'dam.asset.download_compressed', 'dam.asset.meta_data', 'dam.asset.files', 'dam.asset.files.index',
    'dam.asset.property', 'dam.asset.property.view', 'dam.asset.comment', 'dam.asset.comment.index', 'dam.asset.relationships',
    'dam.asset.relationships.index', 'dam.directory', 'dam.directory.index', 'dam.directory.download_zip', 'dam.asset_assign', 'history', 'history.view',
];

/**
 * A viewer who reads the folder and the given assets under the access model: an ordinary member holding the read
 * keys (granted the folder, or on its and the assets' access lists), a role with every folder holding the same keys
 * (all folders under grants; lists still name it), or a full admin, who sees everything under both.
 */
function mergeViewer(string $kind, string $model, Directory $folder, array $assetIds): Admin
{
    $role = $kind === 'full admin'
        ? Role::factory()->create(['permission_type' => 'all', 'permissions' => []])
        : Role::factory()->create(['permission_type' => 'custom', 'permissions' => MERGE_READ_KEYS]);
    $viewer = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);

    if ($kind === 'all-folders role') {
        DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($kind === 'view-only member' && $model === AccessModel::GRANTS) {
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    if ($kind !== 'full admin' && $model === AccessModel::LISTS) {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $viewer->id]);
        DB::table('dam_asset_user_access')->insert(array_map(fn (int $id) => ['asset_id' => $id, 'admin_id' => $viewer->id], $assetIds));
    }

    app(DirectoryPermissionService::class)->flush();

    return $viewer;
}

/** An asset whose original is the given bytes, filed in the folder, as the graph observer ingests a stored file. */
function mergeStoredAsset(string $name, string $bytes, array $attributes, Directory $folder): Asset
{
    $path = 'assets/Root/'.Str::lower(Str::random(6)).'-'.$name;
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
    $asset = Asset::factory()->create(array_merge(['file_name' => $name, 'path' => $path], $attributes));
    $asset->directories()->attach($folder->id);

    return $asset->fresh();
}

/** A web page asset in the folder with its bundle built, as a Library upload of a site ZIP leaves it. */
function mergeWebAsset(Directory $folder): Asset
{
    $path = tempnam(sys_get_temp_dir(), 'merge-site-').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<title>shared</title><p>source page</p>');
    $zip->close();
    $asset = mergeStoredAsset('site.zip', (string) file_get_contents($path), ['file_type' => 'web', 'extension' => 'zip', 'mime_type' => 'application/zip'], $folder);
    @unlink($path);
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    return $asset->fresh();
}

/** Uploads the bytes in chunks the way the Library's resumable upload does, then runs its finalizer. */
function mergeChunkUpload($test, Directory $folder, string $name, string $mime, string $bytes): Asset
{
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $folder->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $test->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $folder->id, 'original_name' => $name, 'mime_type' => $mime,
        'size'         => strlen($bytes), 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', $bytes), 'relative_path' => null, 'preserve_root' => false,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::query()->where('uuid', $uuid)->firstOrFail();

    foreach (str_split($bytes, (int) $upload->chunk_bytes) as $index => $chunk) {
        $start = $index * (int) $upload->chunk_bytes;
        $test->call('PUT', route('admin.dam.assets.chunk-upload.store', [$upload->uuid, $index]), server: [
            'CONTENT_TYPE'        => 'application/octet-stream',
            'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, $upload->expected_bytes),
            'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
        ], content: $chunk)->assertOk();
    }

    $test->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    return Asset::query()->findOrFail($upload->fresh()->asset_id);
}

/**
 * Gives the asset `$count` more slots, each on its own blob, and `$count` tags and properties, written in bulk. The
 * tag and property names are shared by every asset given them, so two sources contribute the same ones.
 */
function mergeBulkSlots(Asset $asset, int $count, string $prefix): void
{
    $now = now();
    $position = (int) DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->max('position');

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('dam_file_objects')->insert(array_map(fn (int $i) => [
            'sha256' => hash('sha256', "{$prefix}-{$i}"), 'size' => 1, 'disk' => Directory::getAssetDisk(), 'path' => "objects/bulk/{$prefix}-{$i}", 'mime_type' => 'text/plain', 'created_at' => $now,
        ], $chunk));
        DB::table('dam_attachments')->insert(array_map(fn (int $i) => [
            'dam_asset_id' => $asset->id, 'role' => AssetFile::ROLE_SIDECAR, 'label' => "{$prefix}-{$i}", 'position' => $position + $i, 'is_representative' => false, 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
        DB::table('dam_tags')->insertOrIgnore(array_map(fn (int $i) => ['name' => "bulk-tag-{$i}", 'created_at' => $now, 'updated_at' => $now], $chunk));
        DB::table('dam_asset_properties')->insert(array_map(fn (int $i) => [
            'dam_asset_id' => $asset->id, 'name' => "bulk-{$i}", 'type' => 'Text', 'language' => 'en', 'value' => "{$prefix} {$i}", 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $objects = DB::table('dam_file_objects')->where('path', 'like', "objects/bulk/{$prefix}-%")->pluck('id', 'path');
    $slots = DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->where('label', 'like', "{$prefix}-%")->pluck('id', 'label');
    $tags = DB::table('dam_tags')->where('name', 'like', 'bulk-tag-%')->pluck('id', 'name');

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('dam_asset_files')->insert(array_map(fn (int $i) => [
            'dam_asset_id'  => $asset->id, 'file_object_id' => $objects["objects/bulk/{$prefix}-{$i}"], 'role' => AssetFile::ROLE_SIDECAR, 'label' => "{$prefix}-{$i}",
            'attachment_id' => $slots["{$prefix}-{$i}"], 'revision_number' => 1, 'is_current' => true, 'created_at' => $now,
        ], $chunk));
        DB::table('dam_asset_tag')->insert(array_map(fn (int $i) => ['asset_id' => $asset->id, 'tag_id' => $tags["bulk-tag-{$i}"], 'created_at' => $now, 'updated_at' => $now], $chunk));
    }
}

/** A destination and a source with something of everything a merge reconciles. */
function mergeFixture($test): array
{
    $dest = mergeUpload($test, $test->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($test, $test->folder, mergePng(8, [200, 30, 30], 'source.png'));
    // A second revision of the source's original, so its history travels too.
    expect(mergeUpload($test, $test->folder, mergePng(9, [210, 40, 40], 'source.png'))->id)->toBe($source->id);
    $captions = attachPreviewFile($source->fresh(), 'assets/Root/source-captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, label: 'Captions');
    $proxy = attachPreviewFile($source->fresh(), 'assets/Root/source-proxy.jpg', 'image/jpeg', AssetFile::ROLE_PROXY);

    $shared = Tag::query()->create(['name' => 'merge-shared']);
    $sourceOnly = Tag::query()->create(['name' => 'merge-source-only']);
    $destOnly = Tag::query()->create(['name' => 'merge-dest-only']);
    $shared->assets()->attach([$dest->id, $source->id]);
    $sourceOnly->assets()->attach($source->id);
    $destOnly->assets()->attach($dest->id);
    AssetProperty::factory()->create(['dam_asset_id' => $dest->id, 'name' => 'Owner', 'value' => 'Media', 'language' => 'en']);
    AssetProperty::factory()->create(['dam_asset_id' => $source->id, 'name' => 'owner', 'value' => 'Athletics', 'language' => 'en']);
    AssetProperty::factory()->create(['dam_asset_id' => $source->id, 'name' => 'Credit', 'value' => 'Studio', 'language' => 'en']);
    AssetComments::factory()->create(['dam_asset_id' => $source->id, 'admin_id' => $test->admin->id, 'comments' => 'keep me with the source']);
    $other = Asset::factory()->create();
    AssetRelationship::factory()->create(['from_asset_id' => $source->id, 'to_asset_id' => $other->id, 'type' => AssetRelationship::TYPE_RELATED_TO]);
    AssetRelationship::factory()->create(['from_asset_id' => $other->id, 'to_asset_id' => $source->id, 'type' => AssetRelationship::TYPE_RELATED_TO]);
    $share = Share::factory()->forAsset($source->id)->create();

    return ['dest' => $dest->fresh(), 'source' => $source->fresh(), 'captions' => $captions, 'proxy' => $proxy, 'share' => $share, 'other' => $other,
        'tags'     => ['shared' => $shared, 'source' => $sourceOnly, 'dest' => $destOnly]];
}

/** The destination's own rows that a merge then split must leave exactly as they were. */
function mergeDestinationRows(int $id): array
{
    return array_intersect_key(mergeSnapshot($id), array_flip(['files', 'attachments', 'versions', 'properties', 'tags', 'folders', 'shares', 'relationships']));
}

it('links a source into the destination without moving it, and a split gives back identical files, tags, properties, labels and links', function () {
    $f = mergeFixture($this);
    $sourceBefore = mergeSnapshot($f['source']->id);
    $destBefore = mergeDestinationRows($f['dest']->id);
    $sourceShas = AssetMergeService::sha256Set($f['source']->id);
    $destShas = AssetMergeService::sha256Set($f['dest']->id);
    $references = mergeBlobReferences();
    $objects = FileObject::query()->count();

    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])
        ->assertCreated()
        ->assertJsonPath('merges.0.source.id', $f['source']->id)
        ->assertJsonPath('merges.0.files', 2);

    // Linked, not moved: the source's rows are exactly as they were but for the merge pointer.
    $merged = mergeSnapshot($f['source']->id);
    expect($merged['asset']['merged_into_id'])->toBe($f['dest']->id)
        ->and(array_merge($merged, ['asset' => array_merge($merged['asset'], ['merged_into_id' => null])]))->toBe($sourceBefore);

    $merge = AssetMerge::query()->sole();
    expect($merge->isActive())->toBeTrue()
        ->and((int) $merge->merged_by)->toBe($this->admin->id)
        ->and($merge->snapshot['sha256'])->toBe($sourceShas);

    // Each destination slot records the source slot it came from; the proxy stays on the source.
    $slots = Attachment::query()->where('dam_asset_id', $f['dest']->id)->where('merge_id', $merge->id)->orderBy('position')->get();
    $sourceOriginal = Attachment::query()->where('dam_asset_id', $f['source']->id)->where('is_representative', true)->sole();
    expect($slots->pluck('origin_attachment_id')->all())->toBe([$sourceOriginal->id, $f['captions']->attachment_id])
        ->and($slots->pluck('role')->all())->toBe([AssetFile::ROLE_OTHER, AssetFile::ROLE_SIDECAR])
        ->and($slots->pluck('label')->all())->toBe(['source.png', 'Captions']);

    // The destination now holds every source file but the proxy, history included, on the same blobs.
    $proxySha = FileObject::query()->find($f['proxy']->file_object_id)->sha256;
    $expected = collect([...$destShas, ...array_values(array_diff($sourceShas, [$proxySha]))])->sort()->values()->all();
    expect(AssetMergeService::sha256Set($f['dest']->id))->toBe($expected)
        ->and(FileObject::query()->count())->toBe($objects)
        ->and(mergeBlobReferences())->toBe($references);

    // Reconciled metadata: the union of tags, the destination's Owner kept, the source's Credit added.
    $destTags = Asset::query()->find($f['dest']->id)->tags()->orderBy('name')->pluck('name')->all();
    expect($destTags)->toBe(['merge-dest-only', 'merge-shared', 'merge-source-only'])
        ->and(AssetProperty::query()->where('dam_asset_id', $f['dest']->id)->orderBy('name')->pluck('value', 'name')->all())->toBe(['Credit' => 'Studio', 'Owner' => 'Media']);

    $this->postJson(route('admin.dam.assets.merges.split', [$f['dest']->id, $merge->id]))->assertOk()->assertJsonPath('merges', []);

    expect(mergeSnapshot($f['source']->id))->toBe($sourceBefore)
        ->and(mergeDestinationRows($f['dest']->id))->toBe($destBefore)
        ->and(AssetMergeService::sha256Set($f['source']->id))->toBe($sourceShas)
        ->and(AssetMergeService::sha256Set($f['dest']->id))->toBe($destShas)
        ->and(FileObject::query()->count())->toBe($objects)
        ->and(mergeBlobReferences())->toBe($references)
        ->and($merge->fresh()->split_at)->not->toBeNull();

    // Back in the Library: its page, its download and its share link answer again.
    $this->get(route('admin.dam.assets.download', $f['source']->id))->assertOk();
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertOk();
});

it('previews the reconciliation, the access change, the links, the incoming edges and the comments before merging', function () {
    $f = mergeFixture($this);

    $preview = $this->getJson(route('admin.dam.assets.merges.preview', [$f['dest']->id, 'sources' => [$f['source']->id]]))->assertOk();

    expect($preview->json('blocked'))->toBeNull()
        ->and($preview->json('destination.id'))->toBe($f['dest']->id)
        ->and(collect($preview->json('sources.0.files'))->pluck('label')->all())->toBe(['source.png', 'Captions'])
        ->and(collect($preview->json('sources.0.kept_on_source'))->pluck('role')->all())->toBe([AssetFile::ROLE_PROXY])
        ->and($preview->json('sources.0.comments'))->toBe(1)
        ->and(collect($preview->json('sources.0.links.direct'))->pluck('id')->all())->toBe([$f['share']->id])
        ->and(collect($preview->json('sources.0.incoming.edges'))->pluck('from.id')->all())->toBe([$f['other']->id])
        ->and($preview->json('sources.0.access.widens'))->toBeFalse()
        ->and(collect($preview->json('metadata.tags'))->mapWithKeys(fn ($tag) => [$tag['name'] => $tag['result']])->all())
        ->toBe(['merge-dest-only' => 'kept', 'merge-shared' => 'kept', 'merge-source-only' => 'added']);

    $properties = collect($preview->json('metadata.properties'))->keyBy('name');
    expect($properties['Owner']['result'])->toBe('destination')
        ->and($properties['Owner']['value'])->toBe('Media')
        ->and($properties['Owner']['conflict'])->toBeTrue()
        ->and($properties['Credit']['result'])->toBe('source:'.$f['source']->id)
        ->and($properties['Credit']['conflict'])->toBeFalse();

    // Without the union, the source's own tag is listed as not added, and the merge then leaves it off.
    $without = $this->getJson(route('admin.dam.assets.merges.preview', [$f['dest']->id, 'sources' => [$f['source']->id], 'union_tags' => 0]))->assertOk();
    expect(collect($without->json('metadata.tags'))->firstWhere('name', 'merge-source-only')['result'])->toBe('not_added');
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id], 'union_tags' => false])->assertCreated();
    expect(Asset::query()->find($f['dest']->id)->tags()->pluck('name')->all())->not->toContain('merge-source-only');

    // A preview writes nothing.
    expect(AssetMerge::query()->count())->toBe(1);
});

it('asks per slot after a post-merge revision: keeps it on the destination as its own slot, or copies it back to the source', function () {
    $f = mergeFixture($this);
    $extra = attachPreviewFile($f['source'], 'assets/Root/source-notes.txt', 'text/plain', AssetFile::ROLE_SIDECAR, label: 'Notes');
    $sourceBefore = mergeSnapshot($f['source']->id);
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();
    $slotFor = fn (int $origin) => Attachment::query()->where('dam_asset_id', $f['dest']->id)->where('origin_attachment_id', $origin)->sole();
    $ledger = app(AttachmentLedger::class);

    // After the merge, the destination edits two linked slots and leaves the third alone.
    $representative = Attachment::query()->where('dam_asset_id', $f['source']->id)->where('is_representative', true)->sole();
    $newOriginal = mergeBlob((string) file_get_contents(mergePng(10, [5, 220, 5], 'x.png')->getRealPath()));
    $newCaptions = mergeBlob("WEBVTT\n\n00:00.000 --> 00:01.000\nEdited", 'text/vtt', 'vtt');
    $ledger->appendRevision(asset: $f['dest'], attachmentId: $slotFor($representative->id)->id, object: $newOriginal, note: 'Retouched');
    $ledger->appendRevision(asset: $f['dest'], attachmentId: $slotFor($f['captions']->attachment_id)->id, object: $newCaptions, note: 'Edited');

    $plan = collect($this->getJson(route('admin.dam.assets.merges.split_preview', [$f['dest']->id, $merge->id]))->assertOk()->json('slots'))->keyBy('origin_attachment_id');
    expect($plan[$representative->id]['asks'])->toBeTrue()
        ->and($plan[$representative->id]['new_revisions'])->toBe(1)
        ->and($plan[$f['captions']->attachment_id]['asks'])->toBeTrue()
        ->and($plan[$extra->attachment_id]['asks'])->toBeFalse()
        ->and($plan[$representative->id]['default'])->toBe(AssetMergeService::KEEP_ON_DESTINATION);

    // The original's retouch goes back to the source; the captions' edit stays on the destination (the default).
    $representativeSlot = $slotFor($representative->id)->id;
    $captionsSlot = $slotFor($f['captions']->attachment_id)->id;
    $this->postJson(route('admin.dam.assets.merges.split', [$f['dest']->id, $merge->id]), ['choices' => [$representativeSlot => 'source']])->assertOk();

    // The source has its own files back, plus the retouch as its newest original.
    $source = Asset::query()->findOrFail($f['source']->id);
    $after = mergeSnapshot($source->id);
    expect($source->merged_into_id)->toBeNull()
        ->and(app(ContentStore::class)->current($source)->sha256)->toBe($newOriginal->sha256)
        ->and(collect($after['files'])->where('attachment_id', $representative->id)->max('revision_number'))->toBe(3)
        ->and(collect($after['files'])->where('attachment_id', $f['captions']->attachment_id)->pluck('id')->all())
        ->toBe(collect($sourceBefore['files'])->where('attachment_id', $f['captions']->attachment_id)->pluck('id')->all())
        ->and($after['tags'])->toBe($sourceBefore['tags'])
        ->and($after['properties'])->toBe($sourceBefore['properties'])
        ->and($after['comments'])->toBe($sourceBefore['comments'])
        ->and($after['shares'])->toBe($sourceBefore['shares']);
    expect(AssetFile::query()->where('attachment_id', $representative->id)->where('is_current', true)->sole()->note)->toContain('destination.png');

    // The captions slot is the destination's own now, its history whole; the other two slots left with the merge.
    $forked = Attachment::query()->findOrFail($captionsSlot);
    expect($forked->merge_id)->toBeNull()
        ->and($forked->origin_attachment_id)->toBe($f['captions']->attachment_id)
        ->and(AssetFile::query()->where('attachment_id', $captionsSlot)->where('is_current', true)->sole()->fileObject->sha256)->toBe($newCaptions->sha256)
        ->and(Attachment::query()->whereKey($representativeSlot)->exists())->toBeFalse()
        ->and(Attachment::query()->where('dam_asset_id', $f['dest']->id)->where('origin_attachment_id', $extra->attachment_id)->exists())->toBeFalse()
        ->and(Attachment::query()->where('dam_asset_id', $f['dest']->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1]);

    // Every blob still exists while something names it.
    expect(FileObject::query()->whereKey($newOriginal->id)->exists())->toBeTrue()
        ->and(FileObject::query()->whereKey($newCaptions->id)->exists())->toBeTrue();
});

it('refuses to purge a destination while a merge is active, and a finalized source goes through the normal trash', function () {
    $f = mergeFixture($this);
    // A thread on the source: the fixture's comment, and a reply by someone else, both from last week.
    $author = Admin::factory()->create(['status' => 1, 'name' => 'Jordan Reply']);
    $thread = AssetComments::query()->where('dam_asset_id', $f['source']->id)->sole();
    $reply = AssetComments::factory()->create(['dam_asset_id' => $f['source']->id, 'admin_id' => $author->id, 'parent_id' => $thread->id, 'comments' => 'agreed, keep it']);
    DB::table('dam_asset_comments')->whereIn('id', [$thread->id, $reply->id])->update(['created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()->addHour()]);
    $sourceComments = DB::table('dam_asset_comments')->where('dam_asset_id', $f['source']->id)->orderBy('id')->get();
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();

    // The destination may go to the trash, but it is not deleted for good while the merge is active.
    $this->deleteJson(route('admin.dam.assets.destroy', $f['dest']->id))->assertOk();
    $this->deleteJson(route('admin.dam.trash.destroy', $f['dest']->id))->assertStatus(409)
        ->assertJsonPath('message', trans('dam::app.admin.dam.trash.merge-active'));
    $this->travel(31)->days();
    app(AssetTrashService::class)->purgeExpired();
    expect(Asset::onlyTrashed()->whereKey($f['dest']->id)->exists())->toBeTrue()
        ->and(Asset::withTrashed()->whereKey($f['source']->id)->exists())->toBeTrue();
    $this->travelBack();

    // The source's link closes with its destination in the trash.
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertStatus(410);
    $this->postJson(route('admin.dam.trash.restore', $f['dest']->id))->assertOk();
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertOk();

    // Finalize needs an explicit confirm, and then cannot be split.
    $this->postJson(route('admin.dam.assets.merges.finalize', [$f['dest']->id, $merge->id]))->assertUnprocessable();
    expect($merge->fresh()->isActive())->toBeTrue();
    $this->postJson(route('admin.dam.assets.merges.finalize', [$f['dest']->id, $merge->id]), ['confirm' => true])->assertOk()
        ->assertJsonCount(1, 'merges')->assertJsonPath('merges.0.status', 'finalized')->assertJsonPath('merges.0.comments.count', 2);
    $this->postJson(route('admin.dam.assets.merges.split', [$f['dest']->id, $merge->id]))->assertNotFound();

    // The source's thread is copied onto the destination: authors, text, times and the reply's parent, attributed.
    $copies = DB::table('dam_asset_comments')->where('dam_asset_id', $f['dest']->id)->orderBy('id')->get();
    expect($copies)->toHaveCount(2)
        ->and($copies->pluck('admin_id')->all())->toBe($sourceComments->pluck('admin_id')->all())
        ->and($copies->pluck('comments')->all())->toBe($sourceComments->pluck('comments')->all())
        ->and($copies->pluck('created_at')->all())->toBe($sourceComments->pluck('created_at')->all())
        ->and($copies->pluck('updated_at')->all())->toBe($sourceComments->pluck('updated_at')->all())
        ->and($copies->pluck('origin_comment_id')->map(fn ($id) => (int) $id)->all())->toBe($sourceComments->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and($copies->pluck('merged_from_name')->unique()->all())->toBe(['source.png'])
        ->and((int) $copies[1]->parent_id)->toBe((int) $copies[0]->id)
        ->and($copies[0]->parent_id)->toBeNull();

    // The finalized source waits in the trash, hidden: not listed, not restorable, its link gone.
    $source = Asset::onlyTrashed()->findOrFail($f['source']->id);
    expect($source->merged_into_id)->toBe($f['dest']->id)
        ->and(Attachment::query()->where('dam_asset_id', $f['dest']->id)->whereNotNull('merge_id')->exists())->toBeFalse();
    $bystander = mergeUpload($this, $this->folder, mergePng(8, [50, 50, 50], 'bystander.png'));
    $this->deleteJson(route('admin.dam.assets.destroy', $bystander->id))->assertOk();
    expect(collect($this->getJson(route('admin.dam.trash.list'))->assertOk()->json('assets'))->pluck('id')->all())->toContain($bystander->id)->not->toContain($source->id);
    $this->postJson(route('admin.dam.trash.restore', $source->id))->assertNotFound();
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertStatus(410);

    // The normal purge deletes it after the retention period; the destination keeps every file it was given, the
    // copied comments with their attribution, and the finalized merge in its "Merged from" list.
    $destShas = AssetMergeService::sha256Set($f['dest']->id);
    $this->travel(31)->days();
    app(AssetTrashService::class)->purgeExpired();
    expect(Asset::withTrashed()->whereKey($source->id)->exists())->toBeFalse()
        ->and(DB::table('dam_asset_comments')->where('dam_asset_id', $source->id)->exists())->toBeFalse()
        ->and(DB::table('dam_asset_comments')->where('dam_asset_id', $f['dest']->id)->orderBy('id')->pluck('comments')->all())->toBe($sourceComments->pluck('comments')->all())
        ->and(AssetMergeService::sha256Set($f['dest']->id))->toBe($destShas);
    $this->getJson(route('admin.dam.assets.merges.index', $f['dest']->id))->assertOk()
        ->assertJsonPath('merges.0.status', 'finalized')->assertJsonPath('merges.0.source.name', 'source.png')->assertJsonPath('merges.0.files', 2);
    $page = $this->get(route('admin.dam.assets.edit', $f['dest']->id))->assertOk()->getContent();
    // The comments tab's data carries the attribution its panel shows as "From source.png".
    expect($page)->toContain('"merged_from_name":"source.png"')->toContain('agreed, keep it');
    foreach (AssetFile::query()->where('dam_asset_id', $f['dest']->id)->with('fileObject')->get() as $file) {
        expect($file->fileObject)->not->toBeNull()
            ->and(Storage::disk(Directory::getAssetDisk())->exists($file->fileObject->path))->toBeTrue();
    }
});

it('keeps a link revoked after the merge revoked through a split', function () {
    $f = mergeFixture($this);
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertOk();

    $this->postJson(route('admin.dam.assets.merges.revoke_links', [$f['dest']->id, $merge->id]))->assertOk()->assertJsonPath('revoked', 1);
    $revokedStatus = $this->get(route('dam.share.show', $f['share']->urlToken()))->getStatusCode();
    expect($revokedStatus)->not->toBe(200);

    $this->postJson(route('admin.dam.assets.merges.split', [$f['dest']->id, $merge->id]))->assertOk();

    expect($f['share']->fresh()->revoked_at)->not->toBeNull();
    $this->get(route('dam.share.show', $f['share']->urlToken()))->assertStatus($revokedStatus);
    $this->get(route('dam.share.download', $f['share']->urlToken()))->assertStatus($revokedStatus);
});

it('refuses a nested merge, and answers a merged, trashed or hidden participant like a missing one', function () {
    $a = mergeUpload($this, $this->folder, mergePng(8, [1, 2, 3], 'a.png'));
    $b = mergeUpload($this, $this->folder, mergePng(8, [4, 5, 6], 'b.png'));
    $c = mergeUpload($this, $this->folder, mergePng(8, [7, 8, 9], 'c.png'));
    $d = mergeUpload($this, $this->folder, mergePng(8, [9, 9, 9], 'd.png'));
    $this->postJson(route('admin.dam.assets.merges.store', $b->id), ['sources' => [$a->id]])->assertCreated();

    // B holds an active merge, so B cannot be merged into C.
    $this->postJson(route('admin.dam.assets.merges.store', $c->id), ['sources' => [$b->id]])->assertStatus(409)
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.merge.nested'));
    expect($this->getJson(route('admin.dam.assets.merges.preview', [$c->id, 'sources' => [$b->id]]))->assertOk()->json('blocked.reason'))->toBe('nested');

    // A is merged, D trashed: each answers like an id that does not exist, as a source or a destination.
    $d->delete();
    $missing = Asset::withTrashed()->max('id') + 1000;
    foreach ([[$c->id, $a->id], [$c->id, $d->id], [$a->id, $c->id], [$d->id, $c->id]] as [$dest, $source]) {
        $asMissing = $dest === $c->id ? [$c->id, $missing] : [$missing, $c->id];
        foreach (['store' => 'postJson', 'preview' => 'getJson'] as $route => $method) {
            $send = fn (int $to, int $from) => $route === 'store'
                ? $this->postJson(route('admin.dam.assets.merges.store', $to), ['sources' => [$from]])
                : $this->getJson(route('admin.dam.assets.merges.preview', [$to, 'sources' => [$from]]));
            $answer = $send($dest, $source);
            expect($answer->getStatusCode())->toBe(404, "{$route} {$dest}<-{$source}")
                ->and($answer->getContent())->toBe($send(...$asMissing)->getContent());
        }
    }

    // A merge record of another destination, or one already split, answers like none.
    $merge = AssetMerge::query()->sole();
    $this->postJson(route('admin.dam.assets.merges.split', [$c->id, $merge->id]))->assertNotFound();
    $this->postJson(route('admin.dam.assets.merges.split', [$b->id, $merge->id]))->assertOk();
    $this->postJson(route('admin.dam.assets.merges.split', [$b->id, $merge->id]))->assertNotFound();

    // Bad input: no sources, the destination itself, a repeat.
    foreach ([[], [$c->id], [$a->id, $a->id], ['x']] as $sources) {
        $this->postJson(route('admin.dam.assets.merges.store', $c->id), ['sources' => $sources])->assertUnprocessable();
    }
});

it('refuses a merge that widens access without sharing authority on that source, with 403', function () {
    mergeUseGrants();
    $open = Directory::factory()->create(['name' => 'Open folder', 'parent_id' => $this->folder->parent_id]);
    $closed = Directory::factory()->create(['name' => 'Closed folder', 'parent_id' => $this->folder->parent_id]);
    $dest = mergeUpload($this, $open, mergePng(8, [10, 10, 10], 'open.png'));
    $source = mergeUpload($this, $closed, mergePng(8, [20, 20, 20], 'closed.png'));
    $editor = mergeMember([$open, $closed], ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update']);
    // Someone who opens the destination's folder but not the source's: the merge would show them the source's files.
    $viewer = mergeMember([$open], ['dam', 'dam.asset', 'dam.asset.view']);

    $this->actingAs($editor, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $preview = $this->getJson(route('admin.dam.assets.merges.preview', [$dest->id, 'sources' => [$source->id]]))->assertOk();
    expect($preview->json('blocked.reason'))->toBe('widening')
        ->and($preview->json('sources.0.access.widens'))->toBeTrue()
        ->and(collect($preview->json('sources.0.access.gains'))->pluck('id')->all())->toContain($viewer->id)
        ->and($preview->json('sources.0.access.allowed'))->toBeFalse();
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.merge.widening'));
    expect(AssetMerge::query()->count())->toBe(0)
        ->and($source->fresh()->merged_into_id)->toBeNull();

    // The source's owner may widen it.
    DB::table('dam_assets')->where('id', $source->id)->update(['created_by' => $editor->id]);
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();

    // Without the edit right on every participant: 403 for a visible one, never a merge.
    $other = mergeUpload($this->actingAs($this->admin, 'admin'), $open, mergePng(8, [30, 30, 30], 'other.png'));
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->postJson(route('admin.dam.assets.merges.store', $other->id), ['sources' => [$dest->id]])->assertForbidden();
});

it('serves a merged source\'s link only from the slots that came from it while the merge is active', function () {
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'source.png'));
    $destAlternate = attachPreviewFile($dest, 'assets/Root/destination.webp', 'image/webp', AssetFile::ROLE_ALTERNATE, label: 'Destination WebP');
    $sourceAlternate = attachPreviewFile($source, 'assets/Root/source.webp', 'image/webp', AssetFile::ROLE_ALTERNATE, label: 'Source WebP');
    $sourceBytes = mergeBody($this->get(route('admin.dam.assets.download', $source->id))->assertOk());
    $share = Share::factory()->forAsset($source->id)->create();
    $destShare = Share::factory()->forAsset($dest->id)->create();

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    $linkedAlternate = Attachment::query()->where('dam_asset_id', $dest->id)->where('origin_attachment_id', $sourceAlternate->attachment_id)->sole();

    // The source's link: its own original, its own alternate (now a destination slot), nothing of the destination's.
    expect(hash('sha256', mergeBody($this->get(route('dam.share.download', $share->urlToken()))->assertOk())))->toBe(hash('sha256', $sourceBytes));
    $this->get(route('dam.share.file_download', [$share->urlToken(), $linkedAlternate->id]))->assertOk();
    $this->get(route('dam.share.file_download', [$share->urlToken(), $destAlternate->attachment_id]))->assertNotFound();
    $page = $this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent();
    expect($page)->toContain('Source WebP')->not->toContain('Destination WebP');

    // A revision the destination adds to the source's slot is what the source's link serves.
    $original = Attachment::query()->where('dam_asset_id', $dest->id)->where('origin_attachment_id', Attachment::query()->where('dam_asset_id', $source->id)->where('is_representative', true)->value('id'))->sole();
    $edited = (string) file_get_contents(mergePng(12, [1, 250, 1], 'e.png')->getRealPath());
    app(AttachmentLedger::class)->appendRevision(asset: $dest, attachmentId: $original->id, object: mergeBlob($edited), note: 'Retouched');
    expect(hash('sha256', mergeBody($this->get(route('dam.share.download', $share->urlToken()))->assertOk())))->toBe(hash('sha256', $edited));

    // The destination's own link keeps serving the destination, the merged slots included.
    $this->get(route('dam.share.file_download', [$destShare->urlToken(), $destAlternate->attachment_id]))->assertOk();
    $this->get(route('dam.share.file_download', [$destShare->urlToken(), $linkedAlternate->id]))->assertOk();
});

it('answers a merged source exactly like a missing asset on every read path, under each access model, for each kind of viewer', function (string $model, string $kind) {
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like', 'dam.tree.show_assets' => true]);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);
    Storage::fake('public');
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'source.png'));
    $sheet = attachPreviewFile($source, 'assets/Root/source-sheet.pdf', 'application/pdf', AssetFile::ROLE_SIDECAR);
    $alternate = attachPreviewFile($source, 'assets/Root/source.webp', 'image/webp', AssetFile::ROLE_ALTERNATE, label: 'Source WebP');
    foreach ([$dest, $source] as $asset) {
        DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => 'falcon mascot', 'caption' => 'falcon mascot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'text' => 'Mascot booking', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    }
    $comment = AssetComments::factory()->create(['dam_asset_id' => $source->id, 'admin_id' => $this->admin->id, 'comments' => 'said only on the source']);
    // MySQL and PostgreSQL number audit versions in a trigger; SQLite has none, so number them as they would.
    DB::table('audits')->where('tags', 'asset')->whereNull('version_id')->update(['version_id' => 1]);
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    mergeUseModel($model);
    $viewer = mergeViewer(kind: $kind, model: $model, folder: $this->folder, assetIds: [$dest->id, $source->id]);
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $missing = Asset::withTrashed()->max('id') + 1000;
    $missingComment = AssetComments::query()->max('id') + 1000;
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
    // Each read, for an asset id and the comment on it: the merged source's answer is compared with the missing one's.
    $reads = fn (int $id, int $commentId): array => [
        'show'               => [route('admin.dam.assets.show', $id)],
        'info'               => [route('admin.dam.assets.info', $id)],
        'download'           => [route('admin.dam.assets.download', $id)],
        'metadata'           => [route('admin.dam.assets.metadata', $id)],
        'files'              => [route('admin.dam.assets.files.index', $id)],
        'relationships'      => [route('admin.dam.assets.relationships.index', $id)],
        'merges'             => [route('admin.dam.assets.merges.index', $id)],
        'attachment'         => [route('admin.dam.file.asset-file', [$id, $sheet->id])],
        'attachment zip'     => [route('admin.dam.file.asset-files-zip', ['asset' => $id, 'files' => [$sheet->id]])],
        'revisions'          => [route('admin.dam.assets.attachments.revisions', [$id, $sheet->attachment_id])],
        'history'            => [route('admin.history.index', ['asset', $id]), $ajax],
        'history version'    => [route('admin.history.version.view', ['asset', $id, 1])],
        'comment'            => [route('admin.dam.asset.comments.index', $commentId)],
        'link: view'         => [route('dam.share.asset_view', [$folderLink->urlToken(), $id])],
        'link: download'     => [route('dam.share.asset_download', [$folderLink->urlToken(), $id])],
        'link: file'         => [route('dam.share.asset_file_download', [$folderLink->urlToken(), $id, $alternate->attachment_id])],
        'link: thumbnail'    => [route('dam.share.thumbnail', [$folderLink->urlToken(), $id])],
    ];
    $send = fn (array $read) => $this->getJson($read[0], $read[1] ?? []);
    $zipNames = function (TestResponse $response): array {
        $zip = tempnam(sys_get_temp_dir(), 'merge-zip-');
        file_put_contents($zip, mergeBody($response->assertOk()));
        $archive = new ZipArchive;
        expect($archive->open($zip))->toBeTrue();
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = basename((string) $archive->getNameIndex($i));
        }
        $archive->close();
        @unlink($zip);

        return $names;
    };
    $linkListing = fn () => collect($this->getJson(route('dam.share.list_assets', $folderLink->urlToken()))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

    // Before the merge this viewer reads the source on every path, so each 404 below is the merge's, not a refusal.
    foreach ($reads($source->id, $comment->id) as $name => $read) {
        expect($send($read)->getStatusCode())->toBe(200, "{$kind} before the merge: {$name}");
    }
    expect($linkListing())->toContain($source->id)
        ->and($zipNames($this->get(route('dam.share.download_zip', $folderLink->urlToken()))))->toContain($source->file_name);

    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();
    // Error pages carry a telemetry token whose expiry is bucketed by the clock; pin it so paired answers compare byte for byte.
    $this->freezeTime();

    foreach (array_map(null, array_keys($reads($source->id, $comment->id)), $reads($source->id, $comment->id), $reads($missing, $missingComment)) as [$name, $merged, $absent]) {
        $answer = $send($merged);
        expect($answer->getStatusCode())->toBe(404, "{$kind}: {$name}")
            ->and($answer->getContent())->toBe($send($absent)->getContent(), "{$kind}: {$name}")
            ->and($answer->getContent())->not->toContain('said only on the source');
    }

    // Its Library path reads like a path nothing holds: original, preview and thumbnail.
    $nothing = 'assets/Root/nothing-here.png';
    expect($this->get(route('admin.dam.file.fetch', $source->path))->assertNotFound()->getContent())->toBe($this->get(route('admin.dam.file.fetch', $nothing))->getContent());
    foreach (['admin.dam.file.thumbnail', 'admin.dam.file.preview'] as $route) {
        $answer = $this->get(route($route, ['path' => $source->path]), ['Accept' => 'image/*']);
        expect($answer->getStatusCode())->toBe(404, "{$kind} {$route}")
            ->and($answer->getContent())->toBe($this->get(route($route, ['path' => $nothing]), ['Accept' => 'image/*'])->getContent());
    }

    // Listings, search and counts, the folder link's listing and ZIP included.
    $explorer = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->assertOk()->json('assets'))->pluck('id');
    expect($explorer)->toContain($dest->id)->not->toContain($source->id);
    foreach (['keyword' => 'mascot', 'semantic' => 'zebra crossing'] as $search => $words) {
        $found = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => $words]))->assertOk()->json('assets'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        expect($found)->toBe([$dest->id], "{$kind} {$search} search");
    }
    expect(collect($this->getJson(route('admin.dam.directory.assets', $this->folder->id))->assertOk()->json('data'))->pluck('id')->all())->toBe([$dest->id])
        ->and($this->postJson(route('admin.dam.directory.asset_counts'), ['ids' => [$this->folder->id]])->assertOk()->json('data'))->toBe([(string) $this->folder->id => 1])
        ->and($this->postJson(route('admin.dam.explorer.count_items'), ['asset_ids' => [$source->id, $dest->id]])->assertOk()->json('file_count'))->toBe(1)
        ->and(collect($this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => $source->id.','.$dest->id]))->assertOk()->json())->pluck('id')->all())->toBe([$dest->id])
        ->and($zipNames($this->get(route('admin.dam.directory.zip_download', $this->folder->id))))->toContain($dest->file_name)->not->toContain($source->file_name)
        ->and($linkListing())->toBe([$dest->id])
        ->and($zipNames($this->get(route('dam.share.download_zip', $folderLink->urlToken()))))->toContain($dest->file_name)->not->toContain($source->file_name);

    // P3b-2's duplicate matching reads through the seam's listing scope: the source's blob now also names the
    // destination's slot, and a match on it finds the destination, never the merged source.
    $sha = FileObject::query()->find(app(ContentStore::class)->current($source)->id)->sha256;
    $matches = AssetFile::query()->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
        ->where('dam_file_objects.sha256', $sha)
        ->tap(fn ($query) => app(AssetAccessDecision::class)->scopeListable($query, AccessContext::forAdmin($viewer), 'dam_asset_files.dam_asset_id'))
        ->distinct()->pluck('dam_asset_files.dam_asset_id')->map(fn ($id) => (int) $id)->all();
    expect($matches)->toBe([$dest->id]);

    // REST, with a key of every scope.
    if ($kind === 'full admin') {
        $headers = $this->getAuthenticationHeaders();
        $rest = fn (int $id): array => [
            ['GET', route('admin.api.dam.assets.show', $id)],
            ['GET', route('admin.api.dam.assets.metadata', $id)],
            ['GET', route('admin.api.dam.assets.download', $id)],
            ['GET', route('admin.api.dam.assets.files.index', $id)],
            ['GET', route('admin.api.dam.assets.merges.index', $id)],
            ['GET', route('admin.api.dam.assets.attachments.revisions', [$id, $sheet->attachment_id])],
            ['PUT', route('admin.api.dam.assets.update', $id), ['file_name' => 'renamed.png']],
            ['DELETE', route('admin.api.dam.assets.destroy', $id)],
        ];
        foreach (array_map(null, $rest($source->id), $rest($missing)) as [$merged, $absent]) {
            $answer = $this->withHeaders($headers)->json(...$merged);
            expect($answer->getStatusCode())->toBe(404, $merged[1])
                ->and($answer->getContent())->toBe($this->withHeaders($headers)->json(...$absent)->getContent(), $merged[1]);
        }
        $restIndex = collect($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.index', ['limit' => 100]))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        expect($restIndex)->toContain($dest->id)->not->toContain($source->id);
    }
})->with('mergeAccessModels', 'mergeViewers');

it('merges, previews, splits and finalizes through the REST API exactly as in the admin UI', function () {
    $headers = $this->getAuthenticationHeaders();
    $f = mergeFixture($this);
    $second = mergeUpload($this, $this->folder, mergePng(8, [90, 90, 90], 'second.png'));
    $sourceBefore = mergeSnapshot($f['source']->id);

    $webPreview = $this->getJson(route('admin.dam.assets.merges.preview', [$f['dest']->id, 'sources' => [$f['source']->id, $second->id]]))->assertOk()->json();
    $restPreview = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.preview', [$f['dest']->id, 'sources' => [$f['source']->id, $second->id]]))
        ->assertOk()->assertJsonPath('success', true)->json('data');
    expect(collect($restPreview)->except('sources')->all())->toBe(collect($webPreview)->except('sources')->all())
        ->and(collect($restPreview['sources'])->map(fn ($row) => collect($row)->except('access')->all())->all())
        ->toBe(collect($webPreview['sources'])->map(fn ($row) => collect($row)->except('access')->all())->all());

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id, $second->id]])
        ->assertCreated()->assertJsonPath('success', true)->assertJsonCount(2, 'data.merges');
    [$first, $other] = AssetMerge::query()->orderBy('id')->get()->all();

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.index', $f['dest']->id))->assertOk()->assertJsonCount(2, 'data.merges')
        ->assertJsonPath('data.merges.0.comments.count', 1);
    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.split_preview', [$f['dest']->id, $first->id]))->assertOk()
        ->assertJsonPath('data.merge.source.id', $f['source']->id);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.split', [$f['dest']->id, $first->id]))->assertOk()->assertJsonCount(1, 'data.merges');
    expect(mergeSnapshot($f['source']->id))->toBe($sourceBefore);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.finalize', [$f['dest']->id, $other->id]))->assertUnprocessable();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.finalize', [$f['dest']->id, $other->id]), ['confirm' => true])->assertOk();
    expect(Asset::onlyTrashed()->whereKey($second->id)->exists())->toBeTrue();
});

function mergeRolelessIntegration(string $permissionType = 'all'): Admin
{
    $actor = app(ApiUserProvisioner::class)->provisionForIntegration('Merge regression')['admin'];
    $client = app(ClientRepository::class)->createPasswordGrantClient('Merge regression', 'admins', confidential: true);
    Apikey::factory()->create([
        'admin_id'        => $actor->id,
        'oauth_client_id' => $client->getKey(),
        'permission_type' => $permissionType,
        'permissions'     => ['api.dam.assets.merges.preview', ...array_values(MergeGate::API_SCOPES)],
    ]);
    Passport::actingAs($actor, [], 'api');

    return $actor;
}

it('merges and splits for a provisioned roleless integration without changing source files', function (string $permissionType) {
    $f = mergeFixture($this);
    $before = mergeSnapshot($f['source']->id);
    $actor = mergeRolelessIntegration($permissionType);
    expect($actor->role_id)->toBeNull()->and($actor->isApiUser())->toBeTrue();

    $this->getJson(route('admin.api.dam.assets.merges.preview', [$f['dest']->id, 'sources' => [$f['source']->id]]))->assertOk();
    $this->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id], 'union_tags' => false])->assertCreated();
    $merge = AssetMerge::query()->sole();
    expect($merge->source_id)->toBe($f['source']->id)->and($merge->dest_id)->toBe($f['dest']->id);
    $this->postJson(route('admin.api.dam.assets.merges.split', [$f['dest']->id, $merge->id]))->assertOk();
    expect(mergeSnapshot($f['source']->id))->toBe($before);

    $this->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->active()->sole();
    $this->postJson(route('admin.api.dam.assets.merges.revoke_links', [$f['dest']->id, $merge->id]))->assertForbidden();
    expect($f['share']->fresh()->revoked_at)->toBeNull();
    $f['source']->fresh()->forceFill(['created_by' => $actor->id])->save();
    $this->postJson(route('admin.api.dam.assets.merges.revoke_links', [$f['dest']->id, $merge->id]))->assertOk();
    expect($f['share']->fresh()->revoked_at)->not->toBeNull();
    $this->postJson(route('admin.api.dam.assets.merges.finalize', [$f['dest']->id, $merge->id]), ['confirm' => true])->assertOk();
    expect(Asset::onlyTrashed()->whereKey($f['source']->id)->exists())->toBeTrue();
})->with(['all', 'custom']);

it('rechecks a roleless integration under the merge lock and refuses changed authority', function (string $change, string $action) {
    $f = mergeFixture($this);
    $actor = mergeRolelessIntegration('custom');
    if ($action === 'revoke') {
        $f['source']->forceFill(['created_by' => $actor->id])->save();
    }
    $merge = null;
    if ($action !== 'merge') {
        $this->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
        $merge = AssetMerge::query()->sole();
    }
    $state = fn () => [mergeSnapshot($f['source']->id), mergeDestinationRows($f['dest']->id), AssetMerge::query()->get()->toArray(), $f['share']->fresh()->toArray()];
    $before = $state();
    $locking = 0;
    Event::listen(AssetMergeService::EVENT_LOCKING, function () use ($actor, $change, &$locking) {
        $locking++;
        match ($change) {
            'disabled'      => Admin::query()->whereKey($actor->id)->update(['status' => 0]),
            'revoked'       => Apikey::query()->where('admin_id', $actor->id)->update(['revoked' => true]),
            'scope removed' => Apikey::query()->where('admin_id', $actor->id)->firstOrFail()->forceFill(['permissions' => ['api.dam.assets.merges.preview']])->save(),
        };
    });

    $route = ['merge' => 'store', 'revoke' => 'revoke_links'][$action] ?? $action;
    $this->postJson(route('admin.api.dam.assets.merges.'.$route, $merge ? [$f['dest']->id, $merge->id] : $f['dest']->id), ['sources' => [$f['source']->id], 'confirm' => true])->assertForbidden();
    expect($locking)->toBe(1)->and($state())->toBe($before);
})->with(['disabled', 'revoked', 'scope removed'])->with(['merge', 'split', 'finalize', 'revoke']);

it('does not let a roleless integration widen a source unless it owns that source', function () {
    $f = mergeFixture($this);
    Share::factory()->forAsset($f['dest']->id)->create();
    $actor = mergeRolelessIntegration();
    $before = [mergeSnapshot($f['source']->id), mergeDestinationRows($f['dest']->id)];
    $this->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertForbidden()->assertJsonPath('reason', 'widening');
    expect([mergeSnapshot($f['source']->id), mergeDestinationRows($f['dest']->id)])->toBe($before)
        ->and(AssetMerge::query()->count())->toBe(0);
    $f['source']->forceFill(['created_by' => $actor->id])->save();
    $this->postJson(route('admin.api.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
});

it('still requires a Library role for a provisioned roleless integration', function () {
    $actor = mergeRolelessIntegration();
    try {
        app(MergeGate::class)->assertStillMayChange(actor: $actor, via: AccessContext::VIA_LIBRARY, action: 'merge', assets: []);
        $this->fail('A roleless integration must not gain Library merge permission.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('refuses a REST key without the merge route\'s scope', function () {
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.merges.index']);
    $dest = Asset::factory()->create();
    $source = Asset::factory()->create();

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.index', $dest->id))->assertOk();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertForbidden();
    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.preview', [$dest->id, 'sources' => [$source->id]]))->assertForbidden();
    expect(AssetMerge::query()->count())->toBe(0);
});

it('offers Merge into… in the Library only to an editor, and lists the merges on the destination\'s page', function () {
    config(['dam.explorer.enabled' => true]);
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'source.png'));

    $library = $this->get(route('admin.dam.assets.index'))->assertOk()->getContent();
    expect($library)->toContain('data-dam-merge-action')->toContain('v-dam-merge-dialog')->toContain('data-dam-ctx-add-to-stack')->toContain('data-dam-ctx-create-stack');
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    expect($this->get(route('admin.dam.assets.edit', $dest->id))->assertOk()->getContent())->toContain('v-dam-asset-merges');
    $this->getJson(route('admin.dam.assets.merges.index', $dest->id))->assertOk()->assertJsonPath('can_edit', true)->assertJsonPath('merges.0.source.name', 'source.png');

    // A viewer without the edit right sees no merge action and cannot merge.
    $viewer = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view']])->id]);
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect($this->get(route('admin.dam.assets.index'))->assertOk()->getContent())->not->toContain('data-dam-merge-action')->not->toContain('data-dam-ctx-add-to-stack')->not->toContain('data-dam-ctx-create-stack');
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [Asset::factory()->create()->id]])->assertForbidden();
    $this->postJson(route('admin.dam.assets.merges.split', [$dest->id, AssetMerge::query()->sole()->id]))->assertForbidden();
    expect(AssetMerge::query()->sole()->isActive())->toBeTrue();
});

it('counts a public link that serves the destination but not the source as widening, its own or a folder link above it', function (string $link, string $surface) {
    mergeUseGrants();
    $linked = Directory::factory()->create(['name' => 'Linked folder', 'parent_id' => $this->folder->parent_id]);
    $inner = Directory::factory()->create(['name' => 'Inner folder', 'parent_id' => $linked->id]);
    $closed = Directory::factory()->create(['name' => 'Closed folder', 'parent_id' => $this->folder->parent_id]);
    $dest = mergeUpload($this, $inner, mergePng(8, [10, 10, 10], 'linked.png'));
    $source = mergeUpload($this, $closed, mergePng(8, [20, 20, 20], 'closed.png'));
    // The one member sees both folders, so every admin who opens the destination opens the source: no admin gains.
    $editor = mergeMember([$linked, $inner, $closed], ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update']);

    if ($surface === 'library') {
        $actor = $editor;
        $this->actingAs($editor, 'admin');
        $preview = fn () => $this->getJson(route('admin.dam.assets.merges.preview', [$dest->id, 'sources' => [$source->id]]))->assertOk()->json();
        $store = fn () => $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]]);
    } else {
        $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.merges.preview', 'api.dam.assets.merges.store']);
        $actor = Admin::query()->findOrFail(Apikey::query()->latest('id')->value('admin_id'));
        $actor->update(['role_id' => $editor->role_id]);
        $preview = fn () => $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.merges.preview', [$dest->id, 'sources' => [$source->id]]))->assertOk()->json('data');
        $store = fn () => $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]]);
    }
    app(DirectoryPermissionService::class)->flush();

    // An expired link serves nobody: no widening yet.
    Share::factory()->forAsset($dest->id)->expired()->create();
    expect($preview()['sources'][0]['access'])->toMatchArray(['gains' => [], 'links' => 0, 'widens' => false, 'allowed' => true]);

    $share = $link === 'asset link' ? Share::factory()->forAsset($dest->id)->create() : Share::factory()->forDirectory($linked->id)->create();
    $blocked = $preview();
    expect($blocked['blocked']['reason'] ?? null)->toBe('widening')
        ->and($blocked['sources'][0]['access'])->toMatchArray(['gains' => [], 'links' => 1, 'widens' => true, 'allowed' => false]);
    $store()->assertForbidden()->assertJsonPath('reason', 'widening');
    expect(AssetMerge::query()->count())->toBe(0)
        ->and($source->fresh()->merged_into_id)->toBeNull()
        ->and(MergeGate::reachingLinks(assetId: $dest->id))->toBe([$share->id]);

    // Sharing authority on the source (its owner) may widen it.
    DB::table('dam_assets')->where('id', $source->id)->update(['created_by' => $actor->id]);
    $store()->assertCreated();
})->with(['asset link', 'folder link above the destination'])->with(['library', 'rest']);

it('asks every merge rule again under the lock: a change made before the lock refuses the merge and writes nothing', function (string $race, int $status, ?string $reason) {
    mergeUseGrants();
    $open = Directory::factory()->create(['name' => 'Open folder', 'parent_id' => $this->folder->parent_id]);
    $closed = Directory::factory()->create(['name' => 'Closed folder', 'parent_id' => $this->folder->parent_id]);
    $dest = mergeUpload($this, $open, mergePng(8, [10, 10, 10], 'open.png'));
    $source = mergeUpload($this, $closed, mergePng(8, [20, 20, 20], 'closed.png'));
    $editor = mergeMember([$open, $closed], ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update']);
    $this->actingAs($editor, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $state = fn () => [mergeSnapshot($source->id), array_diff_key(mergeDestinationRows($dest->id), ['shares' => true]), AssetMerge::query()->count()];
    $before = $state();
    $locking = 0;

    // Between the controller's checks and the service's row locks.
    Event::listen(AssetMergeService::EVENT_LOCKING, function () use (&$locking, $race, $editor, $closed, $open, $dest) {
        $locking++;
        match ($race) {
            'the source folder grant revoked'         => DB::table('dam_directory_role')->where('role_id', $editor->role_id)->where('directory_id', $closed->id)->delete(),
            'the edit key removed'                    => Role::query()->findOrFail($editor->role_id)->forceFill(['permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->save(),
            'the actor disabled'                      => DB::table('admins')->where('id', $editor->id)->update(['status' => 0]),
            'a viewer granted the destination folder' => mergeMember([$open], ['dam', 'dam.asset', 'dam.asset.view']),
            'a public link on the destination'        => Share::factory()->forAsset($dest->id)->create(),
        };
    });

    $answer = $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]]);

    expect($locking)->toBe(1)
        ->and($answer->getStatusCode())->toBe($status)
        ->and($answer->json('reason'))->toBe($reason)
        ->and($state())->toBe($before);
})->with([
    'the source folder grant revoked'         => ['the source folder grant revoked', 404, null],
    'the edit key removed'                    => ['the edit key removed', 403, null],
    'the actor disabled'                      => ['the actor disabled', 403, null],
    'a viewer granted the destination folder' => ['a viewer granted the destination folder', 403, 'widening'],
    'a public link on the destination'        => ['a public link on the destination', 403, 'widening'],
]);

it('asks again under the lock before a split, a finalize or a link revoke, in the Library and over REST', function (string $action, string $surface) {
    $f = mergeFixture($this);
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();
    $state = fn () => [mergeSnapshot($f['source']->id), mergeSnapshot($f['dest']->id), $merge->fresh()->toArray(), $f['share']->fresh()->toArray()];
    $before = $state();
    $headers = $surface === 'rest' ? $this->getAuthenticationHeaders() : [];
    $route = ($surface === 'rest' ? 'admin.api.dam.assets.merges.' : 'admin.dam.assets.merges.').($action === 'revoke' ? 'revoke_links' : $action);

    // The Library actor's role loses the edit key; the REST actor's key is revoked.
    Event::listen(AssetMergeService::EVENT_LOCKING, fn () => $surface === 'rest'
        ? Apikey::query()->latest('id')->update(['revoked' => true])
        : $this->admin->role->forceFill(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->save());

    $this->withHeaders($headers)->postJson(route($route, [$f['dest']->id, $merge->id]), ['confirm' => true])->assertForbidden();

    expect($state())->toBe($before)
        ->and($merge->fresh()->isActive())->toBeTrue();
})->with(['split', 'finalize', 'revoke'])->with(['library', 'rest']);

it('publishes an upload onto a merged source\'s path as a new asset, and the split still gives the source back exactly', function (string $how) {
    Queue::fake();
    config(['dam.explorer.upload.chunk_bytes' => 64]);
    $f = mergeFixture($this);
    $sourceBefore = mergeSnapshot($f['source']->id);
    $this->postJson(route('admin.dam.assets.merges.store', $f['dest']->id), ['sources' => [$f['source']->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();
    $file = mergePng(11, [0, 120, 0], 'source.png');
    $bytes = (string) file_get_contents($file->getRealPath());

    $uploaded = match ($how) {
        'library upload' => mergeUpload($this, $this->folder, $file),
        'folder upload'  => Asset::query()->findOrFail($this->postJson(route('admin.dam.assets.upload_folder'), [
            'files' => [$file], 'relative_paths' => [$this->folder->name.'/source.png'], 'directory_id' => $this->folder->parent_id, 'preserve_root' => true,
        ])->assertStatus(201)->json('files.0.id')),
        'chunked upload' => mergeChunkUpload($this, $this->folder, 'source.png', 'image/png', $bytes),
    };

    // A new asset under the next free name, holding the upload; the source is untouched and still merged.
    $merged = mergeSnapshot($f['source']->id);
    expect($uploaded->id)->not->toBe($f['source']->id)
        ->and($uploaded->file_name)->toBe('source(1).png')
        ->and($uploaded->path)->toBe(str_replace('source.png', 'source(1).png', $f['source']->path))
        ->and(app(ContentStore::class)->current($uploaded)->sha256)->toBe(hash('sha256', $bytes))
        ->and($merged['asset']['merged_into_id'])->toBe($f['dest']->id)
        ->and(array_merge($merged, ['asset' => array_merge($merged['asset'], ['merged_into_id' => null])]))->toBe($sourceBefore);

    $this->postJson(route('admin.dam.assets.merges.split', [$f['dest']->id, $merge->id]))->assertOk();
    expect(mergeSnapshot($f['source']->id))->toBe($sourceBefore)
        ->and(Asset::query()->find($uploaded->id)?->file_name)->toBe('source(1).png');
})->with(['library upload', 'folder upload', 'chunked upload']);

it('keeps a tag or property every remaining contribution still names, whichever source splits first or is finalized', function (string $order) {
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $a = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'a.png'));
    $b = mergeUpload($this, $this->folder, mergePng(8, [30, 200, 30], 'b.png'));
    $shared = Tag::query()->create(['name' => 'merge-both-sources']);
    $shared->assets()->attach([$a->id, $b->id]);
    AssetProperty::factory()->create(['dam_asset_id' => $a->id, 'name' => 'Credit', 'value' => 'Alpha', 'language' => 'en']);
    AssetProperty::factory()->create(['dam_asset_id' => $b->id, 'name' => 'credit', 'value' => 'Beta', 'language' => 'en']);
    $destBefore = mergeDestinationRows($dest->id);
    $tags = fn () => Asset::query()->find($dest->id)->tags()->pluck('name')->all();
    $credit = fn () => AssetProperty::query()->where('dam_asset_id', $dest->id)->pluck('value')->all();

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$a->id]])->assertCreated();
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$b->id]])->assertCreated();
    $mergeOf = fn (Asset $source) => AssetMerge::query()->where('source_id', $source->id)->sole();
    // The first source's value is the destination's; the second contributed the same tag and key.
    expect($tags())->toBe(['merge-both-sources'])->and($credit())->toBe(['Alpha']);

    [$first, $firstHow, $second, $kept] = match ($order) {
        'a splits, then b'              => [$a, 'split', $b, 'Beta'],
        'b splits, then a'              => [$b, 'split', $a, 'Alpha'],
        'a is finalized, then b splits' => [$a, 'finalize', $b, 'Alpha'],
    };
    if ($firstHow === 'split') {
        $plan = $this->getJson(route('admin.dam.assets.merges.split_preview', [$dest->id, $mergeOf($first)->id]))->assertOk();
        expect($plan->json('tags_removed'))->toBe([])->and($plan->json('properties_removed'))->toBe([]);
    }
    $this->postJson(route('admin.dam.assets.merges.'.$firstHow, [$dest->id, $mergeOf($first)->id]), ['confirm' => true])->assertOk();

    // The other contribution still names both: kept, the value now the remaining contributor's.
    expect($tags())->toBe(['merge-both-sources'])->and($credit())->toBe([$kept]);

    $this->postJson(route('admin.dam.assets.merges.split', [$dest->id, $mergeOf($second)->id]))->assertOk();

    if ($firstHow === 'split') {
        // Both gone: the destination is exactly as it was.
        expect(mergeDestinationRows($dest->id))->toBe($destBefore);
    } else {
        // The finalized source's contribution is the destination's for good.
        expect($tags())->toBe(['merge-both-sources'])->and($credit())->toBe(['Alpha']);
    }
})->with(['a splits, then b', 'b splits, then a', 'a is finalized, then b splits']);

it('derives a merge source link\'s web page, DNG preview and model capture from the slot that came from it, and answers 404 once it is gone', function (string $kind) {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/document.svg', '<svg id="grid-document"/>');
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = match ($kind) {
        'web page' => mergeWebAsset($this->folder),
        'DNG'      => mergeStoredAsset('photo.dng', 'dng-original', ['file_type' => 'image', 'mime_type' => 'image/x-adobe-dng', 'extension' => 'dng'], $this->folder),
        'model'    => mergeStoredAsset('part.glb', 'glb-original', ['file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb'], $this->folder),
    };
    $own = app(ContentStore::class)->current($source)->sha256;
    // What each kind derives from the source's own bytes, served before the merge.
    match ($kind) {
        'web page' => null,
        'DNG'      => [$disk->put(DerivativeImages::dngPreview($own), 'preview-of-own'), $disk->put(DerivativeImages::dngThumbnail($own), 'thumb-of-own')],
        'model'    => $disk->put(ModelThumbnailService::generatedPath($source, $own), 'capture-of-own'),
    };
    $share = Share::factory()->forAsset($source->id)->create();
    $reads = fn (): array => match ($kind) {
        'web page' => ['page' => getRawUrl(route('dam.share.page', ['token' => $share->urlToken()]).'/')],
        'DNG'      => ['inline' => $this->get(route('dam.share.download', ['token' => $share->urlToken(), 'disposition' => 'inline'])), 'thumbnail' => $this->get(route('dam.share.thumbnail', [$share->urlToken(), $source->id]))],
        'model'    => ['thumbnail' => $this->get(route('dam.share.thumbnail', [$share->urlToken(), $source->id]))],
    };
    $bodies = fn (): array => array_map(fn (TestResponse $response) => $response->getStatusCode().' '.mergeBody($response), $reads());
    $ownBodies = match ($kind) {
        'web page' => ['page' => '200'],
        'DNG'      => ['inline' => '200 preview-of-own', 'thumbnail' => '200 thumb-of-own'],
        'model'    => ['thumbnail' => '200 capture-of-own'],
    };
    $matchesOwn = function (array $bodies) use ($kind, $ownBodies) {
        foreach ($bodies as $read => $body) {
            $kind === 'web page' ? expect($body)->toStartWith('200 ')->toContain('<title>shared</title>') : expect($body)->toBe($ownBodies[$read]);
        }
    };
    $matchesOwn($bodies());

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    // Merged, the slot still holds the source's own bytes: the same derivatives.
    $matchesOwn($bodies());

    // The destination revises the slot: nothing derived from the old bytes is served any more.
    $slot = Attachment::query()->where('dam_asset_id', $dest->id)->where('origin_attachment_id', Attachment::query()->where('dam_asset_id', $source->id)->where('is_representative', true)->value('id'))->sole();
    $revised = mergeBlob($kind.'-revised', $source->mime_type, $source->extension);
    app(AttachmentLedger::class)->appendRevision(asset: $dest, attachmentId: $slot->id, object: $revised, note: 'Revised');
    if ($kind === 'DNG') {
        $disk->put(DerivativeImages::dngPreview($revised->sha256), 'preview-of-revision');
        $disk->put(DerivativeImages::dngThumbnail($revised->sha256), 'thumb-of-revision');
    }
    match ($kind) {
        // The bundle was built from the source's own bytes; the revision has none.
        'web page' => expect($bodies()['page'])->toStartWith('404 ')->not->toContain('<title>shared</title>'),
        'DNG'      => expect($bodies())->toBe(['inline' => '200 preview-of-revision', 'thumbnail' => '200 thumb-of-revision']),
        // The capture was of the old bytes: the type placeholder, never the stale capture.
        'model'    => expect($bodies())->toBe(['thumbnail' => '200 <svg id="grid-document"/>']),
    };

    // The slot is removed from the destination: the link serves nothing of it.
    AssetFile::query()->where('attachment_id', $slot->id)->orderByDesc('revision_number')->get()->each->delete();
    $slot->delete();
    foreach ($reads() as $read => $response) {
        expect($response->getStatusCode())->toBe(404, "{$kind} {$read}");
    }
})->with(['web page', 'DNG', 'model']);

// A source's picture lived on its own row (a browser's 3D capture, a custom thumbnail, an audio file's cover art); the
// merge hides that row, so the stack's other-file tile reads the picture from beside the blob instead.
it('carries a merged 3D source\'s capture to its stacked file, and keeps it once its own capture is gone', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeStoredAsset('part.glb', 'glb-original', ['file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb'], $this->folder);
    $sha = app(ContentStore::class)->current($source)->sha256;
    $disk->put(ModelThumbnailService::generatedPath($source, $sha), mergeJpeg('capture-of-part'));

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();

    $file = collect($this->getJson(route('admin.dam.assets.files.index', $dest->id))->assertOk()->json('files'))->firstWhere('sha256', $sha);
    expect($file['thumbnail_url'])->not->toBeNull()
        ->and($disk->get(DerivativeImages::modelCapture($sha, $dest->id)))->toBe(mergeJpeg('capture-of-part'))
        ->and(mergeBody($this->get($file['thumbnail_url'])->assertOk()))->toBe(mergeJpeg('capture-of-part'));

    // The source's own capture goes (a purge of the finalized source): the stack's copy still serves.
    $disk->delete(ModelThumbnailService::generatedPath($source, $sha));
    expect(mergeBody($this->get($file['thumbnail_url'])->assertOk()))->toBe(mergeJpeg('capture-of-part'));
});

it('does not overwrite a capture the destination already has when it merges a 3D source', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeStoredAsset('part.glb', 'glb-original', ['file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb'], $this->folder);
    $sha = app(ContentStore::class)->current($source)->sha256;
    $disk->put(ModelThumbnailService::generatedPath($source, $sha), mergeJpeg('capture-from-source'));
    $disk->put(DerivativeImages::modelCapture($sha, $dest->id), mergeJpeg('capture-already-here'));

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();

    expect($disk->get(DerivativeImages::modelCapture($sha, $dest->id)))->toBe(mergeJpeg('capture-already-here'));
});

it('keeps a merged source\'s custom thumbnail and audio cover art on its stacked file', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $clip = mergeStoredAsset('talk.mp3', 'mp3-bytes', ['file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3'], $this->folder);
    $disk->put('covers/'.$clip->id.'.jpg', 'art-of-talk');
    $clip->update(['meta_data' => ['cover_art_path' => 'covers/'.$clip->id.'.jpg']]);
    $memo = mergeStoredAsset('memo.pdf', 'pdf-bytes', ['file_type' => 'document', 'mime_type' => 'application/pdf', 'extension' => 'pdf'], $this->folder);
    $custom = ['path' => 'custom-thumbnails/'.$memo->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
    $disk->put($custom['path'], 'custom-art-of-memo');
    $memo->update(['meta_data' => ['custom_thumbnail' => $custom]]);

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$clip->id, $memo->id]])->assertCreated();

    $files = collect($this->getJson(route('admin.dam.assets.files.index', $dest->id))->assertOk()->json('files'))->keyBy('file_name');
    expect(mergeBody($this->get($files['talk.mp3']['thumbnail_url'])->assertOk()))->toBe('art-of-talk')
        ->and(mergeBody($this->get($files['memo.pdf']['thumbnail_url'])->assertOk()))->toBe('custom-art-of-memo');
});

$glbSource = function (object $test, Asset $dest) {
    $disk = Storage::disk(Directory::getAssetDisk());
    $source = mergeStoredAsset('part.glb', 'glb-original', ['file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb'], $test->folder);
    $sha = app(ContentStore::class)->current($source)->sha256;
    $disk->put(ModelThumbnailService::generatedPath($source, $sha), mergeJpeg('capture-from-source'));
    $source->update(['meta_data' => ['thumbnail_path' => ModelThumbnailService::generatedPath($source, $sha)]]);

    return [$source->fresh(), $sha];
};

it('serves the stack\'s own capture of a 3D file before the picture its source asset had', function () use ($glbSource) {
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    [$source, $sha] = $glbSource($this, $dest);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::modelCapture($sha, $dest->id), mergeJpeg('capture-made-for-the-stack'));

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();

    $file = collect($this->getJson(route('admin.dam.assets.files.index', $dest->id))->assertOk()->json('files'))->firstWhere('sha256', $sha);
    $response = $this->get($file['thumbnail_url'])->assertOk();

    // Which file answers, not only what it holds: the stack's capture, not the source asset's.
    expect($response->baseResponse->getFile()->getFilename())->toBe('model-capture-a'.$dest->id.'.jpg')
        ->and(mergeBody($response))->toBe(mergeJpeg('capture-made-for-the-stack'));
});

it('never shows a source asset\'s picture on a merged slot revised to other bytes, to a viewer without history access', function () use ($glbSource) {
    mergeUseGrants();
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    [$source, $sha] = $glbSource($this, $dest);
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    $slot = Attachment::query()->where('dam_asset_id', $dest->id)->where('origin_attachment_id', Attachment::query()->where('dam_asset_id', $source->id)->where('is_representative', true)->value('id'))->sole();
    $file = AssetFile::query()->where('attachment_id', $slot->id)->where('is_current', true)->sole();
    // The destination's own capture of the old bytes goes too, so only the source asset's picture could answer.
    $disk->delete(DerivativeImages::modelCapture($sha, $dest->id));

    $viewer = mergeViewer('view-only member', AccessModel::GRANTS, $this->folder, [$dest->id]);
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $thumb = fn (AssetFile $row) => route('admin.dam.file.asset-file', ['asset' => $dest->id, 'assetFile' => $row->id, 'thumb' => 1]);

    // Still the source's bytes: its picture answers.
    expect(mergeBody($this->get($thumb($file))->assertOk()))->toBe(mergeJpeg('capture-from-source'));

    $this->actingAs($this->admin, 'admin');
    app(AttachmentLedger::class)->appendRevision(asset: $dest, attachmentId: $slot->id, object: mergeBlob('glb-revised', 'model/gltf-binary', 'glb'), note: 'Revised');
    $revised = AssetFile::query()->where('attachment_id', $slot->id)->where('is_current', true)->sole();
    $this->actingAs($viewer, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->get($thumb($revised))->assertNotFound();
    expect($this->getJson(route('admin.dam.assets.files.index', $dest->id))->json('files.*.thumbnail_url'))->not->toContain($thumb($revised));
});

it('copies a capture only once the merge has committed, never over one that exists, and leaves nothing behind when the merge rolls back', function () use ($glbSource) {
    $disk = Storage::disk(Directory::getAssetDisk());
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    [$source, $sha] = $glbSource($this, $dest);
    $own = DerivativeImages::modelCapture($sha, $dest->id);

    try {
        DB::transaction(function () use ($dest, $source) {
            app(AssetMergeService::class)->merge($dest, [$source->id], true, $this->admin, 'library');
            throw new RuntimeException('rolled back after the merge');
        });
    } catch (RuntimeException) {
    }

    expect($disk->exists($own))->toBeFalse();

    $created = ModelFileThumbnailService::copyIfAbsent(ModelThumbnailService::generatedPath($source, $sha), $sha, $dest->id);
    $again = ModelFileThumbnailService::copyIfAbsent(ModelThumbnailService::generatedPath($source, $sha), $sha, $dest->id);
    $disk->put($own, mergeJpeg('edited since'));
    $overwrite = ModelFileThumbnailService::copyIfAbsent(ModelThumbnailService::generatedPath($source, $sha), $sha, $dest->id);

    expect([$created, $again, $overwrite])->toBe([true, false, false])
        ->and($disk->get($own))->toBe(mergeJpeg('edited since'));
});

it('tells someone combining two stacks to split one first, in plain words, in the preview and on the merge', function () {
    $first = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'first.png'));
    $second = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'second.png'));
    $third = mergeUpload($this, $this->folder, mergePng(8, [30, 200, 30], 'third.png'));
    $fourth = mergeUpload($this, $this->folder, mergePng(8, [30, 30, 200], 'fourth.png'));
    $this->postJson(route('admin.dam.assets.merges.store', $first->id), ['sources' => [$second->id]])->assertCreated();
    $this->postJson(route('admin.dam.assets.merges.store', $third->id), ['sources' => [$fourth->id]])->assertCreated();

    $preview = $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $first->id, 'sources' => [$third->id]]))->assertOk();
    $refusal = $this->postJson(route('admin.dam.assets.merges.store', $first->id), ['sources' => [$third->id]])->assertStatus(409);

    foreach ([$preview->json('blocked.message'), $refusal->json('message')] as $message) {
        expect($message)->toBe(trans('dam::app.admin.dam.asset.merge.nested-both'))->toContain('Split')->not->toContain('merged into');
    }
    expect($refusal->json('reason'))->toBe('nested');
});

it('refuses to add a stack to another stack in plain language, and refuses a stack the user cannot edit', function () {
    mergeUseGrants();
    $first = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'first.png'));
    $second = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'second.png'));
    $third = mergeUpload($this, $this->folder, mergePng(8, [30, 200, 30], 'third.png'));
    $this->postJson(route('admin.dam.assets.merges.store', $first->id), ['sources' => [$second->id]])->assertCreated();

    // The stack as a source is refused with words a person can act on, not a rule number.
    $refusal = $this->postJson(route('admin.dam.assets.merges.store', $third->id), ['sources' => [$first->id]])->assertStatus(409);
    expect($refusal->json('message'))->toBe(trans('dam::app.admin.dam.asset.merge.nested'))
        ->and($refusal->json('message'))->toContain('already a stack')->not->toContain('merged into');

    // A member who may only view the stack is refused before the merge rules speak, and nothing moves.
    $member = mergeMember([$this->folder], ['dam', 'dam.asset', 'dam.asset.view']);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $before = Attachment::query()->where('dam_asset_id', $first->id)->count();
    $this->postJson(route('admin.dam.assets.merges.store', $first->id), ['sources' => [$third->id]])->assertForbidden();
    expect(Attachment::query()->where('dam_asset_id', $first->id)->count())->toBe($before)
        ->and($third->fresh()->merged_into_id)->toBeNull();
});

it('keeps the files payload of a stack to the same number of queries however many files were merged into it', function () use ($glbSource) {
    $payloadQueries = function (Asset $dest): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('admin.dam.assets.files.index', $dest->id))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $stack = function (int $pictures) use ($glbSource) {
        $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'dest-'.$pictures.'.png'));
        $ids = [];
        for ($i = 0; $i < $pictures; $i++) {
            $ids[] = mergeUpload($this, $this->folder, mergePng(8, [20 + $i, 90, 10 * $i], 'part-'.$pictures.'-'.$i.'.png'))->id;
        }
        [$model] = $glbSource($this, $dest);
        $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [...$ids, $model->id]])->assertCreated();

        return $dest;
    };

    $small = $stack(1);
    $large = $stack(6);
    $payloadQueries($small);

    expect($payloadQueries($large))->toBe($payloadQueries($small));
});

it('names the merge that linked a file into a stack, so its row can offer Remove from stack, and that Split gives the file back as its own item in the folder', function () {
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'member.png'));
    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id]])->assertCreated();
    $merge = AssetMerge::query()->sole();

    $files = collect($this->getJson(route('admin.dam.assets.files.index', $dest->id))->assertOk()->json('files'));
    expect($files->firstWhere('is_representative', true)['merge_id'])->toBeNull()
        ->and($files->firstWhere('is_representative', false)['merge_id'])->toBe((int) $merge->id);

    $index = $this->getJson(route('admin.dam.assets.merges.index', $dest->id))->assertOk();
    expect($index->json('folder'))->toBe($this->folder->name);

    // The row's action is Split on that merge: nothing is deleted, the file is its own asset in the folder again.
    $this->postJson(route('admin.dam.assets.merges.split', ['asset' => $dest->id, 'merge' => $merge->id]), ['choices' => []])->assertOk();

    expect($source->fresh()->merged_into_id)->toBeNull()
        ->and($source->fresh()->trashed())->toBeFalse()
        ->and($source->directories()->pluck('dam_directories.id')->all())->toContain($this->folder->id);
});

it('renders Remove from stack on a merged file\'s row instead of the delete action', function () {
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));

    $page = $this->get(route('admin.dam.assets.edit', $dest->id))->assertOk()->getContent();

    expect($page)->toContain('data-dam-file-unstack')->toContain('removeFromStack')->toContain('promoteNow')
        ->and($page)->toContain('v-if="!file.merge_id && file.role');
});

it('binds at most 2,100 values per statement when a merge, its split and a finalize touch more than 2,100 slots, blobs, tags and properties', function () {
    $count = 2150;
    $dest = mergeUpload($this, $this->folder, mergePng(8, [10, 60, 200], 'destination.png'));
    $source = mergeUpload($this, $this->folder, mergePng(8, [200, 30, 30], 'source.png'));
    $other = mergeUpload($this, $this->folder, mergePng(8, [30, 200, 30], 'other.png'));
    mergeBulkSlots(asset: $source, count: $count, prefix: 'source');
    mergeBulkSlots(asset: $other, count: $count, prefix: 'other');
    if (DB::getDriverName() === 'pgsql') {
        // Refresh this transaction's bulk fixture statistics before merge and preview joins; earlier fixtures roll back.
        DB::statement('ANALYZE dam_assets, dam_asset_files, dam_file_objects, dam_attachments, dam_tags, dam_asset_tag, dam_asset_properties');
    }
    $sourceBefore = mergeSnapshot($source->id);
    expect(count($sourceBefore['files']))->toBeGreaterThan(2100)
        ->and(count($sourceBefore['tags']))->toBeGreaterThan(2100)
        ->and(count($sourceBefore['properties']))->toBeGreaterThan(2100);

    $most = 0;
    $widest = '';
    DB::listen(function ($query) use (&$most, &$widest) {
        if (count($query->bindings) > $most) {
            $most = count($query->bindings);
            $widest = $query->sql;
        }
    });

    $this->postJson(route('admin.dam.assets.merges.store', $dest->id), ['sources' => [$source->id, $other->id]])->assertCreated();
    $merges = AssetMerge::query()->orderBy('id')->get();
    $this->getJson(route('admin.dam.assets.merges.preview', [$dest->id, 'sources' => [mergeUpload($this, $this->folder, mergePng(8, [1, 1, 1], 'probe.png'))->id]]))->assertOk();
    $this->getJson(route('admin.dam.assets.merges.split_preview', [$dest->id, $merges[0]->id]))->assertOk();
    $this->postJson(route('admin.dam.assets.merges.split', [$dest->id, $merges[0]->id]))->assertOk();
    $this->getJson(route('admin.dam.assets.merges.index', $dest->id))->assertOk();
    $this->postJson(route('admin.dam.assets.merges.finalize', [$dest->id, $merges[1]->id]), ['confirm' => true])->assertOk();

    expect($most)->toBeLessThanOrEqual(2100, "{$most} values bound by: ".Str::limit($widest, 200))
        ->and(mergeSnapshot($source->id))->toBe($sourceBefore);
});
