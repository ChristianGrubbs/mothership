<?php

/*
 * Editor resources are blobs (ADR 0009, 2026-09-25 amendment). The images a layered image editor document
 * names (its base, an uploaded background) are FileObjects, never files under `assets/.editor/`. They are
 * shared, not copied, by save-as-new; reference-counted by AssetDeletionService; moved by
 * MoveDamAssetsToS3; and ingested from the pre-blob files by 2026_09_25_000501. The flow runs through the
 * real admin routes.
 */

use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\StorageIntegrity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->admin = $this->loginAsAdmin();
});

if (! function_exists('editorBlobsBody')) {
    /** The body of a local response, whatever response class carried it. */
    function editorBlobsBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        return match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse   => (string) $response->streamedContent(),
            default                             => (string) $base->getContent(),
        };
    }
}

if (! function_exists('editorBlobsAsset')) {
    /** A PNG uploaded into a fresh folder through the real upload route. */
    function editorBlobsAsset($test, string $name = 'layered.png'): Asset
    {
        $root = Directory::factory()->create(['name' => 'EditorBlobsRoot-'.Str::random(6), 'parent_id' => null]);
        $folderId = $test->postJson(route('admin.dam.directory.store'), ['name' => 'EditorBlobs', 'parent_id' => $root->id])
            ->assertOk()->json('data.id');
        $assetId = $test->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image($name, 200, 100)],
            'directory_id' => $folderId,
        ])->assertStatus(201)->json('files.0.id');

        return Asset::findOrFail($assetId);
    }
}

if (! function_exists('editorBlobsRender')) {
    /**
     * A 200x100 PNG filled with one colour, so its bytes (and blob) differ from a blank fake upload's. A
     * blank render would share the original's blob and hide which reference keeps that blob alive.
     */
    function editorBlobsRender(int $red, int $green, int $blue): UploadedFile
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        $path = tempnam(sys_get_temp_dir(), 'editor-render-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'render.png', 'image/png', null, true);
    }
}

if (! function_exists('editorBlobsSave')) {
    function editorBlobsSave($test, Asset $asset, array $opened, array $document, string $mode, ?UploadedFile $render = null): TestResponse
    {
        return $test->post(route('admin.dam.assets.editor.save', $asset->id), [
            'revision' => $opened['revision'],
            'mode'     => $mode,
            'document' => json_encode($document),
            'render'   => $render ?? UploadedFile::fake()->image('render.png', 200, 100),
        ], ['Accept' => 'application/json']);
    }
}

if (! function_exists('editorBlobsRemoteDisk')) {
    /**
     * Put `$object` on a mocked remote disk that issues temporary URLs, as S3 does, and forbid issuing one: the
     * editor must stream its images from the app's own origin (ADR 0009).
     */
    function editorBlobsRemoteDisk(FileObject $object, string $bytes): void
    {
        $object->forceFill(['disk' => 'editor-remote'])->save();
        $remote = Mockery::mock(FilesystemAdapter::class);
        $remote->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
        $remote->shouldReceive('providesTemporaryUrls')->andReturn(true);
        $remote->shouldReceive('exists')->with($object->path)->andReturn(true);
        $remote->shouldReceive('size')->with($object->path)->andReturn(strlen($bytes));
        $remote->shouldReceive('readStream')->with($object->path)->andReturnUsing(function () use ($bytes) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        });
        $remote->shouldNotReceive('temporaryUrl');
        Storage::set('editor-remote', $remote);
    }
}

if (! function_exists('editorBlobsAssertEveryResourceServes')) {
    /** Every image a reopened document names is served, byte for byte, from its resource's blob. */
    function editorBlobsAssertEveryResourceServes($test, array $opened): void
    {
        $store = app(ContentStore::class);

        expect($opened['resources'])->not->toBe([]);

        foreach ($opened['resources'] as $id => $url) {
            $object = ImageEditorResource::findOrFail($id)->fileObject;

            expect($object)->not->toBeNull()
                ->and($store->holdsBlob($object))->toBeTrue()
                ->and(editorBlobsBody($test->get($url)->assertOk()))->toBe(Storage::disk($object->disk)->get($object->path));
        }
    }
}

it('keeps a layered document\'s images in blobs through open, upload, save, reopen, save as new and delete', function () {
    $disk = Directory::getAssetDisk();
    $store = app(ContentStore::class);
    $asset = editorBlobsAsset($this);
    $original = $store->current($asset);
    $originalBytes = Storage::disk($disk)->get($original->path);

    // Open: the base is the original's own blob. Nothing is copied, and no working file is written.
    $opened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();
    $base = ImageEditorResource::findOrFail($opened['original_base']);

    expect($base->file_object_id)->toBe((int) $original->id)
        ->and($base->path)->toBeNull()
        ->and(editorBlobsBody($this->get($opened['resources'][$base->id])->assertOk()))->toBe($originalBytes)
        ->and(Storage::disk($disk)->allFiles('assets/.editor'))->toBe([]);

    // Upload a background: ingested as its own blob.
    $backdrop = UploadedFile::fake()->image('backdrop.jpg', 300, 150);
    $backdropBytes = (string) file_get_contents($backdrop->getRealPath());
    $uploaded = $this->post(route('admin.dam.assets.editor.upload', $asset->id), ['image' => $backdrop], ['Accept' => 'application/json'])
        ->assertOk()->json();
    $background = ImageEditorResource::with('fileObject')->findOrFail($uploaded['id']);

    expect($background->path)->toBeNull()
        ->and($background->fileObject->sha256)->toBe(hash('sha256', $backdropBytes))
        ->and($store->holdsBlob($background->fileObject))->toBeTrue()
        ->and(editorBlobsBody($this->get($uploaded['url'])->assertOk()))->toBe($backdropBytes);

    // Save a version that names the background, then reopen it: the same images come back.
    $document = $opened['document'];
    $document['background'] = array_merge($document['background'], ['type' => 'upload', 'image' => $background->id]);
    $document['annotations'][] = ['id' => 'label', 'type' => 'text', 'x' => 4, 'y' => 4, 'width' => 80, 'height' => 20, 'text' => 'Layered', 'size' => 16, 'color' => '#ff0000'];
    editorBlobsSave($this, $asset, $opened, $document, 'version', editorBlobsRender(200, 0, 0))->assertOk();

    // The edit is a new blob; the base still names the pre-edit original.
    expect((int) $store->current($asset->fresh())->id)->not->toBe((int) $original->id);

    $reopened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();

    expect($reopened['document']['background']['image'])->toBe($background->id)
        ->and($reopened['document']['annotations'][0]['text'])->toBe('Layered')
        ->and(array_keys($reopened['resources']))->toEqualCanonicalizing([$base->id, $background->id]);
    editorBlobsAssertEveryResourceServes($this, $reopened);

    // Save as new: the copy's resources name the same blobs. No bytes are copied.
    $copyId = editorBlobsSave($this, $asset, $reopened, $reopened['document'], 'new', editorBlobsRender(0, 0, 200))->assertOk()->json('asset_id');
    $copyResources = ImageEditorResource::where('asset_id', $copyId)->get();

    expect($copyResources->pluck('file_object_id')->all())
        ->toEqualCanonicalizing([(int) $original->id, (int) $background->file_object_id])
        ->and($copyResources->pluck('path')->filter()->all())->toBe([]);

    $copyOpened = $this->getJson(route('admin.dam.assets.editor.document', $copyId))->assertOk()->json();
    expect($copyOpened['document']['background']['image'])->toBe($copyResources->firstWhere('file_object_id', $background->file_object_id)->id);
    editorBlobsAssertEveryResourceServes($this, $copyOpened);
    expect(Storage::disk($disk)->allFiles('assets/.editor'))->toBe([]);

    // Delete the source (to the trash, then for good: P2 S4). Only the copy's editor document still names the pre-edit original and the
    // background, and that reference alone keeps both blobs.
    $this->delete(route('admin.dam.assets.destroy', $asset->id))->assertOk();
    $this->deleteJson(route('admin.dam.trash.destroy', $asset->id))->assertOk();
    app(AssetDeletionService::class)->processPendingFileDeletions();

    // No attachment or rendition names either blob any more: the editor resources alone keep them.
    expect(AssetFile::whereIn('file_object_id', [$original->id, $background->file_object_id])->exists())->toBeFalse()
        ->and(Rendition::whereIn('output_file_object_id', [$original->id, $background->file_object_id])->exists())->toBeFalse()
        ->and(FileObject::find($original->id))->not->toBeNull()
        ->and(FileObject::find($background->file_object_id))->not->toBeNull();
    Storage::disk($disk)->assertExists($original->path);
    Storage::disk($disk)->assertExists($background->fileObject->path);
    editorBlobsAssertEveryResourceServes($this, $this->getJson(route('admin.dam.assets.editor.document', $copyId))->assertOk()->json());

    // Delete the copy. Nothing names either image now, so both blobs are reclaimed.
    $this->delete(route('admin.dam.assets.destroy', $copyId))->assertOk();
    $this->deleteJson(route('admin.dam.trash.destroy', $copyId))->assertOk();
    app(AssetDeletionService::class)->processPendingFileDeletions();

    expect(ImageEditorResource::whereIn('asset_id', [$asset->id, $copyId])->exists())->toBeFalse()
        ->and(FileObject::find($original->id))->toBeNull()
        ->and(FileObject::find($background->file_object_id))->toBeNull();
    Storage::disk($disk)->assertMissing($original->path);
    Storage::disk($disk)->assertMissing($background->fileObject->path);
});

it('ingests pre-blob editor resources and reopens and saves their document after the migration', function () {
    $disk = Directory::getAssetDisk();
    $store = app(ContentStore::class);
    $asset = editorBlobsAsset($this, 'legacy.png');
    $original = $store->current($asset);

    // The pre-blob state the old controller wrote: working files under assets/.editor/<asset id>/, and
    // rows that name only that path.
    $legacy = function (string $kind, string $bytes, int $width, int $height) use ($asset, $disk): ImageEditorResource {
        $id = (string) Str::uuid();
        $path = 'assets/.editor/'.$asset->id.'/'.$id;
        Storage::disk($disk)->put($path, $bytes);

        return ImageEditorResource::create(['id' => $id, 'asset_id' => $asset->id, 'admin_id' => $this->admin->id, 'kind' => $kind, 'status' => 'ready', 'path' => $path, 'width' => $width, 'height' => $height]);
    };
    $backdrop = UploadedFile::fake()->image('old-backdrop.png', 120, 60);
    $backdropBytes = (string) file_get_contents($backdrop->getRealPath());
    $base = $legacy('base', Storage::disk($disk)->get($original->path), 200, 100);
    $background = $legacy('upload', $backdropBytes, 120, 60);
    $document = app(ImageEditorSchema::class)->initial($base->id, 200, 100);
    $document['background'] = array_merge($document['background'], ['type' => 'upload', 'image' => $background->id]);
    $record = ImageEditorDocument::create([
        'asset_id'         => $asset->id,
        'asset_version_id' => $asset->versions()->latest('number')->value('id'),
        'revision'         => (string) Str::uuid(),
        'content_path'     => $asset->path,
        'original_base'    => $base->id,
        'document'         => $document,
    ]);

    expect(app(StorageIntegrity::class)->report()['findings']['editor_resources_without_blob']['count'])->toBe(2);

    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_09_25_000501_content_address_image_editor_resources.php');
    $migration->up();

    // The base collapses onto its asset's original blob. The background gets a blob of its own. Both old
    // files stay for slice 4's reclaim.
    $base->refresh();
    $background->refresh();
    expect($base->file_object_id)->toBe((int) $original->id)
        ->and($background->fileObject->sha256)->toBe(hash('sha256', $backdropBytes))
        ->and($store->holdsBlob($background->fileObject))->toBeTrue()
        ->and(app(StorageIntegrity::class)->report()['findings']['editor_resources_without_blob']['count'])->toBe(0);
    Storage::disk($disk)->assertExists($base->path);
    Storage::disk($disk)->assertExists($background->path);

    // Reopen: the same document, and every image it names comes from a blob.
    $reopened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();
    expect($reopened['revision'])->toBe($record->revision)
        ->and(array_keys($reopened['resources']))->toEqualCanonicalizing([$base->id, $background->id]);
    editorBlobsAssertEveryResourceServes($this, $reopened);

    // A second run is a no-op: nothing left to ingest, no row or blob changes.
    $objects = FileObject::count();
    $second = app(StorageIntegrity::class)->repairEditorResources();
    expect($second['ingested']['count'] + $second['unresolved']['count'])->toBe(0)
        ->and(FileObject::count())->toBe($objects)
        ->and($base->fresh()->file_object_id)->toBe($base->file_object_id);

    // The migrated document saves, both as a version and as a new asset sharing its blobs.
    editorBlobsSave($this, $asset, $reopened, $reopened['document'], 'version')->assertOk();
    $again = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();
    editorBlobsAssertEveryResourceServes($this, $again);
    $copyId = editorBlobsSave($this, $asset, $again, $again['document'], 'new')->assertOk()->json('asset_id');

    expect(ImageEditorResource::where('asset_id', $copyId)->pluck('file_object_id')->all())
        ->toEqualCanonicalizing([(int) $base->file_object_id, (int) $background->file_object_id]);
    editorBlobsAssertEveryResourceServes($this, $this->getJson(route('admin.dam.assets.editor.document', $copyId))->assertOk()->json());
});

it('reports a pre-blob resource it cannot ingest, never reads outside the editor area, and changes nothing on a dry run', function () {
    $disk = Directory::getAssetDisk();
    $asset = editorBlobsAsset($this, 'unresolved.png');
    $row = fn (string $path) => ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $asset->id, 'admin_id' => $this->admin->id, 'kind' => 'upload', 'status' => 'ready', 'path' => $path, 'width' => 200, 'height' => 100]);

    $lost = $row('assets/.editor/'.$asset->id.'/'.Str::uuid());
    // A readable file outside the working area is never ingested, whatever a row says.
    Storage::disk($disk)->put('assets/Elsewhere/secret.png', 'not-an-editor-file');
    $outside = $row('assets/Elsewhere/secret.png');
    $pending = $row('assets/.editor/'.$asset->id.'/'.Str::uuid());
    $pendingFile = UploadedFile::fake()->image('pending.png', 200, 100);
    Storage::disk($disk)->put($pending->path, (string) file_get_contents($pendingFile->getRealPath()));

    $objects = FileObject::count();
    $dry = app(StorageIntegrity::class)->repairEditorResources(dryRun: true);
    expect($dry['ingested']['sample'])->toBe([$pending->id])
        ->and($dry['unresolved']['sample'])->toEqualCanonicalizing([$lost->id, $outside->id])
        ->and(FileObject::count())->toBe($objects)
        ->and($pending->fresh()->file_object_id)->toBeNull();

    $result = app(StorageIntegrity::class)->repairEditorResources();
    expect($result['ingested']['sample'])->toBe([$pending->id])
        ->and($lost->fresh()->file_object_id)->toBeNull()
        ->and($outside->fresh()->file_object_id)->toBeNull()
        ->and($pending->fresh()->file_object_id)->not->toBeNull();

    $report = app(StorageIntegrity::class)->report();
    expect($report['clean'])->toBeFalse()
        ->and($report['findings']['editor_resources_without_blob']['sample'])->toEqualCanonicalizing([$lost->id, $outside->id]);

    // A row with no blob answers 404, as a missing file always did.
    $this->get(route('admin.dam.assets.editor.resource', ['id' => $asset->id, 'resource' => $lost->id]))->assertNotFound();
});

it('moves editor resource blobs to S3 with every other FileObject, and the editor still serves them', function () {
    Storage::fake('s3');
    $asset = editorBlobsAsset($this, 'to-s3.png');
    $opened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();
    $backdrop = UploadedFile::fake()->image('s3-backdrop.png', 90, 45);
    $backdropBytes = (string) file_get_contents($backdrop->getRealPath());
    $uploaded = $this->post(route('admin.dam.assets.editor.upload', $asset->id), ['image' => $backdrop], ['Accept' => 'application/json'])
        ->assertOk()->json();
    $object = ImageEditorResource::findOrFail($uploaded['id'])->fileObject;

    $this->artisan('mothership:dam:move-asset-to-s3')
        ->expectsQuestion('Enter your Email', $this->admin->email)
        ->expectsQuestion('Enter your Password', 'password')
        ->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', 'no')
        ->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    expect($object->fresh()->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($object->path);
    expect(editorBlobsBody($this->get($uploaded['url'])->assertOk()))->toBe($backdropBytes);
    editorBlobsAssertEveryResourceServes($this, $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json());
    expect($opened['original_base'])->toBeString();
});

it('counts an editor-only reference when reaping a tombstone, removing an attachment or deleting the row', function () {
    $disk = Directory::getAssetDisk();
    $asset = editorBlobsAsset($this, 'editor-only.png');
    $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk();
    $backdrop = UploadedFile::fake()->image('only-here.jpg', 80, 40);
    $uploaded = $this->post(route('admin.dam.assets.editor.upload', $asset->id), ['image' => $backdrop], ['Accept' => 'application/json'])
        ->assertOk()->json();
    $object = ImageEditorResource::findOrFail($uploaded['id'])->fileObject;

    // A pending tombstone for a blob only an editor resource names: the reaper drops the tombstone and keeps the blob.
    $deletions = app(AssetDeletionService::class);
    $deletions->processPendingFileDeletions($deletions->queueFileDeletions([['disk' => $object->disk, 'path' => $object->path]]));

    expect(DB::table('dam_file_deletion_tombstones')->where('path', $object->path)->exists())->toBeFalse()
        ->and(FileObject::find($object->id))->not->toBeNull();
    Storage::disk($disk)->assertExists($object->path);

    // The same bytes attached to another asset, then detached: the editor resource still holds the blob.
    $other = editorBlobsAsset($this, 'attachment-holder.png');
    $attachment = AssetFile::create(['dam_asset_id' => $other->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_OTHER, 'label' => 'shared']);
    $this->delete(route('admin.dam.assets.files.destroy', ['asset' => $other->id, 'assetFile' => $attachment->id]))->assertOk();

    expect(AssetFile::find($attachment->id))->toBeNull()
        ->and(FileObject::find($object->id))->not->toBeNull();
    Storage::disk($disk)->assertExists($object->path);

    // A deletion site that forgot editor resources fails loudly on the RESTRICT key, never orphaning the image.
    expect(fn () => DB::transaction(fn () => FileObject::whereKey($object->id)->delete()))->toThrow(QueryException::class);
    expect(FileObject::find($object->id))->not->toBeNull();
});

it('streams an editor image from the app even when its blob sits on a disk that issues temporary URLs', function () {
    $asset = editorBlobsAsset($this, 'canvas.png');
    $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk();
    $backdrop = UploadedFile::fake()->image('canvas-layer.png', 64, 32);
    $bytes = (string) file_get_contents($backdrop->getRealPath());
    $uploaded = $this->post(route('admin.dam.assets.editor.upload', $asset->id), ['image' => $backdrop], ['Accept' => 'application/json'])
        ->assertOk()->json();
    editorBlobsRemoteDisk(ImageEditorResource::findOrFail($uploaded['id'])->fileObject, $bytes);

    // A redirect to the object store would taint the editor's canvas and break its export.
    $response = $this->get($uploaded['url'])->assertOk();

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe($bytes)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});
