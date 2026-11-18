<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mothership\DAM\Filesystem\FileStorer;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\Asset\AssetController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetResourceMapping;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\ZipEntryNames;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

/**
 * A real, trivial Imagick SVG-read-then-encode round-trip. extension_loaded('imagick') alone
 * proves the PHP extension exists, not that ImageMagick's own policy.xml (a common CI/Debian
 * hardening default) permits the SVG coder — a policy-blocked SVG read throws an ImagickException
 * exactly like a missing extension would functionally mean "cannot convert SVG here," so both
 * cases are treated the same way by this probe.
 */
function svgConversionActuallyWorks(): bool
{
    if (! extension_loaded('imagick')) {
        return false;
    }

    try {
        $imagick = new Imagick;
        $imagick->setBackgroundColor(new ImagickPixel('transparent'));
        $imagick->readImageBlob('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><rect width="4" height="4"/></svg>', 'image.svg');
        $imagick->setImageFormat('png');
        $imagick->clear();

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

it('should return the asset index page', function () {
    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSeeText(trans('dam::app.admin.dam.index.title'));
});

it('should return the asset edit page', function () {
    $assetId = Asset::factory()->create()->id;

    $this->get(route('admin.dam.assets.edit', $assetId))
        ->assertOk()
        ->assertSeeText(trans('dam::app.admin.dam.asset.edit.title'))
        ->assertSeeText(trans('dam::app.admin.dam.asset.edit.save-btn'))
        ->assertSeeText(trans('dam::app.admin.dam.asset.edit.save-btn'));
});

it('should return the asset detail page', function () {
    $asset = Asset::factory()->create();

    $this->get(route('admin.dam.assets.show', $asset->id))
        ->assertOk()
        ->assertJsonPath('asset.file_name', $asset->file_name);
});

it('show endpoint includes tags in response', function () {
    $asset = Asset::factory()->create();
    $tag = Tag::create(['name' => 'sunset']);
    $asset->tags()->attach($tag);

    $this->get(route('admin.dam.assets.show', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['tags']);
});

it('edit view passes directoryAncestors to blade', function () {
    $parent = Directory::factory()->create(['name' => 'Photography', 'parent_id' => null]);
    $child = Directory::factory()->create(['name' => 'Landscapes', 'parent_id' => $parent->id]);
    $asset = Asset::factory()->create();
    $child->assets()->attach($asset);

    $response = $this->get(route('admin.dam.assets.edit', $asset->id));
    $response->assertOk();
    $response->assertViewHas('directoryAncestors');
    $response->assertViewHas('fileSize');
});

it('answers the details form save without changing what describes the file\'s bytes (UX eval R5)', function () {
    $asset = Asset::factory()->create(['file_name' => 'notes.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_type' => 'document', 'file_size' => 16]);
    $before = $asset->only(['file_name', 'extension', 'mime_type', 'file_type', 'file_size', 'path']);

    // A role with dam.asset.update but no rename right: the details save must not rename or retype the file.
    $folder = Directory::factory()->create(['name' => 'Editors', 'parent_id' => null]);
    $folder->assets()->attach($asset->id);
    actAsPlaybackRestrictedAdmin($folder, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update']);

    $this->putJson(route('admin.dam.assets.update', $asset->id), [
        'file_name' => 'notes.html', 'extension' => 'html', 'mime_type' => 'text/html', 'file_type' => 'web', 'file_size' => 999,
    ])->assertOk()->assertJson([
        'success' => true,
        'message' => trans('dam::app.admin.dam.asset.datagrid.update-success'),
    ]);

    expect($asset->fresh()->only(array_keys($before)))->toBe($before);

    // Renaming stays with rename(), behind its own permission.
    $this->postJson(route('admin.dam.assets.rename'), ['id' => $asset->id, 'file_name' => 'renamed'])->assertForbidden();
    expect($asset->fresh()->file_name)->toBe('notes.txt');
});

it('ignores a client-supplied path in an asset update request', function () {
    $asset = Asset::factory()->create(['path' => 'assets/Root/original.png']);

    $response = $this->put(route('admin.dam.assets.update', $asset->id), [
        'id'        => $asset->id,
        'file_name' => 'updated-name.png',
        'path'      => 'assets/.objects/aa/attacker-supplied-path',
    ]);

    $response->assertOk();

    expect($asset->fresh()->path)->toBe('assets/Root/original.png');
});

it('should upload the asset file to the specified directory', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    // Folders are metadata (ADR 0009 slice 3): this one has no directory on disk, and needs none.
    $directory = Directory::factory()->create([
        'name'      => 'New',
        'parent_id' => null,
    ]);

    $fileName = 'sample-'.uniqid().'.png';
    $file = UploadedFile::fake()->image($fileName, 600, 600)->size(23);
    $bytes = $file->get();
    $uploadData = [
        'files'        => [$file],
        'directory_id' => $directory->id,
    ];
    $response = $this->postJson(route('admin.dam.assets.upload'), $uploadData);
    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.file-upload-success'),
        ])
        ->assertJsonStructure(['files' => [['id', 'file_name', 'path']]]);

    $uploadedFileName = $response->json('files.0.file_name');
    $uploadedPath = $response->json('files.0.path');

    $this->assertDatabaseHas($this->getFullTableName(Asset::class), [
        'file_name' => $uploadedFileName,
        'path'      => $uploadedPath,
    ]);

    // The bytes live only in the asset's blob: nothing under the Library tree, nothing left in staging.
    $object = app(ContentStore::class)->current(Asset::findOrFail($response->json('files.0.id')));

    expect($uploadedPath)->toBe('assets/New/'.$uploadedFileName)
        ->and(Storage::disk($disk)->get($object->path))->toBe($bytes)
        ->and(Storage::disk($disk)->allFiles('assets/New'))->toBe([])
        ->and(Storage::disk($disk)->allFiles(ContentStore::STAGING))->toBe([]);
});

it('uploads a folder without writing the Library tree, and versions an overwrite', function () {
    $disk = Directory::getAssetDisk();
    $root = Directory::factory()->create(['name' => 'Shoots', 'parent_id' => null]);
    $upload = fn (string $bytes) => $this->postJson(route('admin.dam.assets.upload_folder'), [
        'files'          => [UploadedFile::fake()->createWithContent('frame.txt', $bytes)],
        // The dropped folder's own name is not kept, so the file lands in the target directory.
        'relative_paths' => ['Card/frame.txt'],
        'directory_id'   => $root->id,
    ])->assertStatus(201);

    $first = Asset::findOrFail($upload('first-take')->json('files.0.id'));
    $second = Asset::findOrFail($upload('second-take')->json('files.0.id'));

    expect($second->id)->toBe($first->id)
        ->and($second->path)->toBe('assets/Shoots/frame.txt')
        ->and(Storage::disk($disk)->get(app(ContentStore::class)->current($second)->path))->toBe('second-take')
        ->and($second->versions()->count())->toBe(1)
        ->and(Storage::disk($disk)->allFiles('assets/Shoots'))->toBe([])
        ->and(Storage::disk($disk)->allFiles(ContentStore::STAGING))->toBe([]);
});

it('refuses a malformed SVG form upload as validation without storing an asset or blob', function () {
    $directory = Directory::factory()->create();
    $assets = Asset::query()->count();
    $objects = FileObject::query()->count();
    $svg = UploadedFile::fake()->createWithContent('broken.svg', '<svg xmlns="http://www.w3.org/2000/svg"><g></svg>');

    $this->postJson(route('admin.dam.assets.upload'), [
        'files' => [$svg], 'directory_id' => $directory->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(Asset::query()->count())->toBe($assets)
        ->and(FileObject::query()->count())->toBe($objects)
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles())->toBe([]);
});

it('sanitizes an uploaded SVG before it is hashed into its blob', function () {
    $disk = Directory::getAssetDisk();
    $directory = Directory::factory()->create(['name' => 'Vectors', 'parent_id' => null]);
    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><script>alert(1)</script><rect width="4" height="4"/></svg>',
    );

    $response = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$svg],
        'directory_id' => $directory->id,
    ])->assertStatus(201);

    $asset = Asset::findOrFail($response->json('files.0.id'));
    $object = app(ContentStore::class)->current($asset);
    $stored = Storage::disk($disk)->get($object->path);

    expect($stored)->not->toContain('<script')->toContain('<rect')
        ->and($object->sha256)->toBe(hash('sha256', $stored))
        ->and((int) $asset->file_size)->toBe(strlen($stored));
});

it('should re-upload the asset file to the specified directory and update the asset record', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $directory = Directory::factory()->create(['name' => 'Root']);

    $originalFileName = 'original-'.uniqid().'.png';
    $initialFilePath = 'assets/Root/'.$originalFileName;
    // A pre-slice-3 asset: the graph ingested its Library file as the first original.
    Storage::disk($disk)->put($initialFilePath, 'dummy content');
    $asset = Asset::factory()->create([
        'file_name' => $originalFileName,
        'path'      => $initialFilePath,
    ]);

    $asset->directories()->attach($directory->id);
    $firstOriginal = app(ContentStore::class)->current($asset);

    $newFile = UploadedFile::fake()->image('sample.png', 600, 600)->size(23);
    $newBytes = $newFile->get();

    $response = $this->postJson(route('admin.dam.assets.re_upload'), [
        'file'     => $newFile,
        'asset_id' => $asset->id,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.edit.file-re-upload-success'),
        ])
        ->assertJsonPath('file.id', $asset->id);

    $newFileName = $response->json('file.file_name');
    $expectedNewPath = 'assets/Root/'.$newFileName;

    $this->assertDatabaseHas($this->getFullTableName(Asset::class), [
        'id'        => $asset->id,
        'file_name' => $newFileName,
        'path'      => $expectedNewPath,
    ]);

    // Re-upload versions the asset (ADR 0009 slice 3): the new bytes are its original blob and the old
    // original is a version. Nothing is written at the new Library path or deleted at the old one.
    $reUploaded = $asset->fresh();

    expect(Storage::disk($disk)->get(app(ContentStore::class)->current($reUploaded)->path))->toBe($newBytes)
        ->and($reUploaded->versions()->count())->toBe(1)
        ->and($reUploaded->files()->where('role', AssetFile::ROLE_VERSION)->pluck('file_object_id')->all())->toBe([$firstOriginal->id])
        ->and(Storage::disk($disk)->exists($expectedNewPath))->toBeFalse()
        ->and(Storage::disk($disk)->get($initialFilePath))->toBe('dummy content')
        ->and(Storage::disk($disk)->allFiles(ContentStore::STAGING))->toBe([]);
});

it('should delete a asset successfully', function () {
    $assetId = Asset::factory()->create()->id;

    $this->delete(route('admin.dam.assets.destroy', $assetId))
        ->assertOk()
        ->assertJsonFragment(['message' => trans('dam::app.admin.dam.asset.delete-success')]);

    // P2 S4: a delete moves the asset to the trash.
    $this->assertSoftDeleted($this->getFullTableName(Asset::class), ['id' => $assetId]);
});

it('should mass delete the asset successfully', function () {
    $assetIds = Asset::factory()->createMany(3)->pluck('id')->toArray();

    $this->post(route('admin.dam.assets.mass_delete'), ['indices' => $assetIds])
        ->assertOk()
        ->assertJsonFragment(['message' => trans('dam::app.admin.dam.asset.datagrid.mass-delete-success')]);

    foreach ($assetIds as $id) {
        $this->assertSoftDeleted($this->getFullTableName(Asset::class), ['id' => $id]);
    }
});

it('should allow downloading the asset file', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $fileName = 'sample-'.uniqid().'.pdf';
    $filePath = 'assets/Root/'.$fileName;
    Storage::disk($disk)->put($filePath, 'dummy content');

    $asset = Asset::factory()->create([
        'file_name' => $fileName,
        'path'      => $filePath,
    ]);

    $response = $this->get(route('admin.dam.assets.download', $asset->id));

    $response->assertOk();
    $response->assertHeader('Content-Disposition');
});

it('names the local download after the display file_name, not the stale on-disk basename', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    // rename() only moves/renames the on-disk file when the FILE NAME itself changed; a
    // title-only rename (or any pre-existing asset with a stale path segment) leaves the path's
    // basename different from the current display file_name.
    $onDiskName = 'stale-disk-name-'.uniqid().'.pdf';
    $filePath = 'assets/Root/'.$onDiskName;
    Storage::disk($disk)->put($filePath, 'dummy content');

    $asset = Asset::factory()->create([
        'file_name' => 'renamed-display-name.pdf',
        'path'      => $filePath,
    ]);

    $response = $this->get(route('admin.dam.assets.download', $asset->id));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toContain('renamed-display-name.pdf')
        ->not->toContain($onDiskName);
});

it('custom_download serves the original bytes untouched when format matches the asset\'s own extension and no resize is requested', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);
    $originalBytes = Storage::disk($assetDisk)->get('assets/Root/'.$fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'jpg',
        'file_type' => 'image',
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=jpg');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
    expect($response->streamedContent())->toBe($originalBytes);
});

it('custom_download still re-encodes when a different format is requested', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);
    $originalBytes = Storage::disk($assetDisk)->get('assets/Root/'.$fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'jpg',
        'file_type' => 'image',
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=png');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->not->toBe($originalBytes);
});

it('custom_download still re-encodes a same-extension request when a resize is also requested', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);
    $originalBytes = Storage::disk($assetDisk)->get('assets/Root/'.$fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'jpg',
        'file_type' => 'image',
    ]);

    // format=jpg matches the asset's own extension, but width/height are also given: the
    // byte-identical passthrough must NOT trigger just because the format matches.
    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=jpg&width=100&height=100');

    $response->assertOk();
    expect($response->streamedContent())->not->toBe($originalBytes);
});

it('custom_download re-encodes when only width/height are requested, with no format at all', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);
    $originalBytes = Storage::disk($assetDisk)->get('assets/Root/'.$fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'jpg',
        'file_type' => 'image',
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?width=100&height=100');

    $response->assertOk();
    expect($response->streamedContent())->not->toBe($originalBytes);
});

it('custom_download serves an SVG original untouched when the requested format is its own extension', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $svgContent = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100"/></svg>';
    $fileName = 'sample-'.uniqid().'.svg';
    Storage::disk($assetDisk)->put('assets/Root/'.$fileName, $svgContent);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'svg',
        'file_type' => 'image',
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=svg');

    $response->assertOk();
    expect($response->streamedContent())->toBe($svgContent);
});

/** Whether this machine's vips renders SVG (libvips built with librsvg), the path custom_download takes when vips is installed. */
function vipsRendersSvg(): bool
{
    $vips = (new ExecutableFinder)->find((string) config('dam.batch.binaries.vips', 'vips'));

    if ($vips === null) {
        return false;
    }

    $list = new Process([$vips, '-l', 'foreign']);
    $list->run();

    return str_contains($list->getOutput(), 'svgload');
}

it('custom_download converts SVG to a different requested format', function () {
    // vips (librsvg) when it is installed, else Imagick. extension_loaded('imagick') alone is not enough: the
    // extension can be present while ImageMagick's own policy.xml blocks the SVG coder, so only a real, trivial SVG
    // read+encode proves the Imagick path can do the conversion this test exercises.
    $vipsInstalled = (new ExecutableFinder)->find((string) config('dam.batch.binaries.vips', 'vips')) !== null;
    if ($vipsInstalled ? ! vipsRendersSvg() : ! svgConversionActuallyWorks()) {
        $this->markTestSkipped('Neither vips with SVG support nor a working Imagick SVG coder is available here.');
    }

    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $svgContent = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="red"/></svg>';
    $fileName = 'sample-'.uniqid().'.svg';
    Storage::disk($assetDisk)->put('assets/Root/'.$fileName, $svgContent);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'svg',
        'file_type' => 'image',
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=png');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->not->toBe($svgContent);
});

it('custom_download renders an SVG with vips to a sized JPEG on white, with no Imagick needed', function () {
    if (! vipsRendersSvg()) {
        $this->markTestSkipped('vips with SVG support is not available here.');
    }

    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);
    $fileName = 'logo-'.uniqid().'.svg';
    Storage::disk($assetDisk)->put('assets/Root/'.$fileName, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect x="25" width="50" height="50" fill="red"/></svg>');
    $asset = Asset::factory()->create(['path' => 'assets/Root/'.$fileName, 'file_name' => $fileName, 'extension' => 'svg', 'file_type' => 'image']);
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'svg_*') ?: [];

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=jpg&width=400');

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->baseResponse->headers->get('Content-Disposition'))->toContain(pathinfo($fileName, PATHINFO_FILENAME).'.jpg');
    $bytes = (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    $size = getimagesizefromstring($bytes);
    expect([$size[0], $size[1]])->toBe([400, 200]);
    // The transparent corners are white, not black.
    $image = imagecreatefromstring($bytes);
    expect(imagecolorat($image, 2, 2) & 0xFFFFFF)->toBeGreaterThan(0xF0F0F0);
    // Only the download itself (removed after it is sent) is left from the work.
    expect(count(array_diff(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'svg_*') ?: [], $before)))->toBeLessThanOrEqual(1);
});

it('does not leak the reserved temp file when a raster conversion fails after allocation', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'jpg',
        'file_type' => 'image',
    ]);

    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'dam_img_*') ?: [];

    // An unsupported/garbage format string forces Intervention's save() to throw AFTER
    // reservedTempImagePath() has already reserved (and renamed onto) the suffixed path —
    // exactly the failure window deleteFileAfterSend(true) never covers.
    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=not-a-real-format');

    $response->assertStatus(500);

    $after = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'dam_img_*') ?: [];
    // Neither the suffixed path (would have been written by save()) nor an unsuffixed
    // tempnam()-reserved leftover survives a failed conversion.
    expect(array_diff($after, $before))->toBe([]);
});

it('returns a conversion error (never the original bytes) and leaks no temp file when Imagick conversion fails after allocation', function () {
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not available in this environment.');
    }

    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    // Not valid SVG/XML at all — forces Imagick::readImageBlob() to throw AFTER
    // reservedTempImagePath() has already reserved the suffixed temp path.
    $corruptSvg = 'this is not svg or xml content at all, just plain garbage text';
    $fileName = 'sample-'.uniqid().'.svg';
    Storage::disk($assetDisk)->put('assets/Root/'.$fileName, $corruptSvg);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
        'extension' => 'svg',
        'file_type' => 'image',
    ]);

    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'svg_*') ?: [];

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=png');

    $response->assertStatus(500);
    // The failure must surface as an explicit error, never a 200 handing back the original SVG
    // bytes (which would silently mismatch the requested format and the caller's expectations).
    expect($response->getContent())->not->toBe($corruptSvg);

    $after = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'svg_*') ?: [];
    expect(array_diff($after, $before))->toBe([]);
});

it('reservedTempImagePath() renames the reserved tempnam() file instead of leaving it behind', function () {
    $controller = app(AssetController::class);
    $method = new ReflectionMethod($controller, 'reservedTempImagePath');
    $method->setAccessible(true);

    $path = $method->invoke($controller, 'dam_leak_test_', 'png');

    try {
        expect($path)->toEndWith('.png');
        expect(file_exists($path))->toBeTrue();
        // The old tempnam()+append pattern left THIS path (the reserved-but-unsuffixed file)
        // behind as a permanent leak on every single conversion.
        $reservedPath = substr($path, 0, -strlen('.png'));
        expect(file_exists($reservedPath))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('should redirect to s3 presigned url on download when disk is aws', function () {
    // Storage::fake() always backs a disk with a real LocalFilesystemAdapter (ADR 0009's read seam
    // decides local-vs-remote from the adapter class), so the asset ingests normally here and its
    // FileObject is then repointed at a disk mocked to answer like S3 — the same technique
    // ContentStoreTest.php uses to exercise ContentStore::deliver()'s temporary-URL branch.
    $fileName = 'sample-'.uniqid().'.pdf';
    $asset = Asset::factory()->create(['file_name' => $fileName, 'path' => 'assets/Root/'.$fileName]);
    putAssetContent($asset, 'dummy content');

    $object = app(ContentStore::class)->current($asset);
    $object->forceFill(['disk' => Directory::ASSETS_DISK_AWS])->save();

    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.test/signed-download');
    Storage::set(Directory::ASSETS_DISK_AWS, $s3);

    $response = $this->get(route('admin.dam.assets.download', $asset->id));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBeEmpty();
});

it('should allow custom downloading of the asset', function () {
    $assetDisk = Directory::getAssetDisk();
    Storage::fake($assetDisk);

    $fileName = 'sample-'.uniqid().'.jpg';
    $file = UploadedFile::fake()->image($fileName, 600, 600)->size(23);
    Storage::disk($assetDisk)->putFileAs('assets/Root', $file, $fileName);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/'.$fileName,
        'file_name' => $fileName,
    ]);

    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $asset->id]).'?format=png');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
});

it('should rename the file name', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $originalName = 'original-name-'.uniqid().'.pdf';
    $newName = 'renamed-file-'.uniqid().'.pdf';

    $directory = 'uploads/assets/';
    $originalPath = $directory.$originalName;
    $newPath = $directory.$newName;

    $file = Asset::factory()->create([
        'file_name' => $originalName,
        'path'      => $originalPath,
        'title'     => 'Title stays unchanged',
    ]);

    $response = $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $file->id,
        'file_name' => $newName,
    ]);

    $response->assertStatus(200);

    $response->assertJson([
        'message' => trans('dam::app.admin.dam.index.directory.asset-renamed-success'),
    ]);

    $this->assertDatabaseHas('dam_assets', [
        'id'        => $file->id,
        'file_name' => $newName,
        'path'      => $newPath,
        'title'     => 'Title stays unchanged',
    ]);

    // ADR 0009 slice 3: rename is a path/file_name column change only — no physical file ever
    // existed at either name.
    expect(Storage::disk($disk)->allFiles())->toBe([]);
});

it('treats saving the name an asset already has as a no-op, not an error', function () {
    $asset = Asset::factory()->create(['file_name' => 'same-name.pdf', 'path' => 'uploads/assets/same-name.pdf', 'title' => 'Same title']);

    $this->postJson(route('admin.dam.assets.rename'), ['id' => $asset->id, 'file_name' => 'same-name.pdf', 'title' => 'Same title'])
        ->assertOk()
        ->assertJson(['code' => 'unchanged', 'message' => trans('dam::app.admin.dam.index.directory.asset-name-unchanged')]);

    expect($asset->fresh()->only(['file_name', 'title']))->toBe(['file_name' => 'same-name.pdf', 'title' => 'Same title'])
        ->and(trans('dam::app.admin.dam.index.directory.image-name-is-the-same'))->not->toContain('is already exist');
});

it('refuses to rename a file onto a name that already exists in the same directory', function () {
    $directory = 'uploads/assets/';

    $existing = Asset::factory()->create([
        'file_name' => 'taken.pdf',
        'path'      => $directory.'taken.pdf',
    ]);
    $asset = Asset::factory()->create([
        'file_name' => 'original.pdf',
        'path'      => $directory.'original.pdf',
    ]);

    $response = $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'file_name' => 'taken.pdf',
    ]);

    $response->assertStatus(404)
        ->assertJson([
            'message' => trans('dam::app.admin.dam.index.directory.asset-name-already-exist', ['asset_name' => $existing->file_name]),
        ]);

    expect($asset->fresh()->file_name)->toBe('original.pdf');
});

it('renames the canonical title and preserves the stored file extension', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $asset = Asset::factory()->create([
        'file_name' => 'original-name.pdf',
        'path'      => 'uploads/assets/original-name.pdf',
        'title'     => 'Old asset title',
        'meta_data' => ['Title' => 'Imported title', 'camera' => 'alpha'],
    ]);

    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'Title',
        'value'        => 'Preferred old title',
    ]);

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'title'     => 'Preferred new title',
        'file_name' => 'renamed-file.png',
    ])->assertOk()
        ->assertJsonPath('data.display_name', 'Preferred new title')
        ->assertJsonPath('data.file_name', 'renamed-file.pdf');

    $asset->refresh();

    expect($asset->title)->toBe('Preferred new title')
        ->and($asset->file_name)->toBe('renamed-file.pdf')
        ->and($asset->meta_data)->toBe(['Title' => 'Imported title', 'camera' => 'alpha'])
        ->and($asset->properties()->whereRaw('LOWER(name) = ?', ['title'])->value('value'))->toBe('Preferred new title')
        ->and($asset->display_name)->toBe('Preferred new title');

    expect(Storage::disk($disk)->allFiles())->toBe([]);
});

it('updates only the asset title when a title-only rename has no title property', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'title-only.jpg',
        'title'     => 'Old title',
        'meta_data' => ['camera' => 'alpha'],
    ]);

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'title'     => 'New title',
        'file_name' => $asset->file_name,
    ])->assertOk()
        ->assertJsonPath('data.display_name', 'New title');

    $asset->refresh();

    expect($asset->title)->toBe('New title')
        ->and($asset->properties()->whereRaw('LOWER(name) = ?', ['title'])->count())->toBe(0)
        ->and($asset->meta_data)->toBe(['camera' => 'alpha']);
});

it('updates the asset and property title during a title-only rename', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'property-title.jpg',
        'title'     => 'Old asset title',
        'meta_data' => ['camera' => 'alpha'],
    ]);
    $property = AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'tItLe',
        'value'        => 'Old property title',
    ]);

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'title'     => 'New canonical title',
        'file_name' => $asset->file_name,
    ])->assertOk()
        ->assertJsonPath('data.display_name', 'New canonical title');

    expect($asset->fresh()->title)->toBe('New canonical title')
        ->and($property->fresh()->value)->toBe('New canonical title')
        ->and($asset->fresh()->meta_data)->toBe(['camera' => 'alpha']);
});

it('clears every duplicate title property during a title-only rename', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'duplicate-title.jpg',
        'title'     => 'Old asset title',
        'meta_data' => [],
    ]);

    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'Title',
        'value'        => '',
    ]);
    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'TITLE',
        'value'        => 'Stale duplicate title',
    ]);

    $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => $asset->id,
        'title'     => '',
        'file_name' => $asset->file_name,
    ])->assertOk()
        ->assertJsonPath('data.display_name', 'duplicate-title.jpg');

    expect($asset->fresh()->title)->toBe('')
        ->and($asset->properties()->whereRaw('LOWER(name) = ?', ['title'])->orderBy('id')->pluck('value')->all())
        ->toBe(['', '']);
});

it('should reject uploading a forbidden file type', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    Storage::disk($disk)->makeDirectory('assets/New');

    $directory = Directory::factory()->create([
        'name'      => 'New',
        'parent_id' => null,
    ]);

    $file = UploadedFile::fake()->create('malicious.php', 100, 'application/x-php');

    $response = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$file],
        'directory_id' => $directory->id,
    ]);

    // A refusal is the uploader's to fix, so it is a 422 that says why, not a 500 "upload failed" (QRAI-06).
    $response->assertUnprocessable()
        ->assertJson(['success' => false, 'code' => 'forbidden_type'])
        ->assertJsonPath('reason.code', 'forbidden_type');
});

it('should return 404 when showing a non-existent asset', function () {
    $response = $this->getJson(route('admin.dam.assets.show', 99999));

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-show'),
        ]);
});

it('should return 404 when updating a non-existent asset', function () {
    $response = $this->putJson(route('admin.dam.assets.update', 99999), [
        'file_name' => 'test.png',
    ]);

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-update'),
        ]);
});

it('should return 404 when deleting a non-existent asset', function () {
    $response = $this->deleteJson(route('admin.dam.assets.destroy', 99999));

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-destroy'),
        ]);
});

it('should prevent deletion of an asset that has linked resources', function () {
    $asset = Asset::factory()->create();

    AssetResourceMapping::create([
        'type'          => 'product',
        'related_field' => 'image',
        'dam_asset_id'  => $asset->id,
    ]);

    $response = $this->deleteJson(route('admin.dam.assets.destroy', $asset->id));

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources'),
        ]);

    $this->assertDatabaseHas($this->getFullTableName(Asset::class), ['id' => $asset->id]);
});

it('should validate rename requires a valid file name', function () {
    $response = $this->postJson(route('admin.dam.assets.rename'), [
        'id'        => 1,
        'file_name' => '.hidden',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file_name']);
});

it('should validate upload requires files and directory_id', function () {
    $response = $this->postJson(route('admin.dam.assets.upload'), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['files', 'directory_id']);
});

it('should mass update assets and dispatch update events', function () {
    Event::fake();

    $assetIds = Asset::factory()->createMany(2)->pluck('id')->toArray();

    $this->postJson(route('admin.dam.assets.mass_update'), [
        'indices' => $assetIds,
        'value'   => 'enabled',
    ])
        ->assertOk()
        ->assertJsonFragment(['message' => trans('dam::app.admin.dam.asset.datagrid.mass-update-success')]);

    foreach ($assetIds as $id) {
        Event::assertDispatched('dam.asset.update.before', fn ($event, $payload) => $payload === $id);
        Event::assertDispatched('dam.asset.update.after', fn ($event, $payload) => $payload === $id);
    }
});

it('should return linked resources datagrid as json', function () {
    $asset = Asset::factory()->create();

    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->get(route('admin.dam.asset.linked_resources.index', ['dam_asset_id' => $asset->id]));

    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('should move asset from one directory to another', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $rootDir = Directory::factory()->create(['name' => 'Root']);
    $newDirectory = Directory::factory()->create(['name' => 'Screenshots', 'parent_id' => $rootDir->id]);

    $fileName = 'sample-'.uniqid().'.jpg';
    $originalPath = 'assets/Root/'.$fileName;

    $asset = Asset::factory()->create([
        'file_name' => $fileName,
        'path'      => $originalPath,
    ]);

    $asset->directories()->sync([$rootDir->id]);

    $response = $this->post(route('admin.dam.assets.moved'), [
        'move_item_id'  => $asset->id,
        'new_parent_id' => $newDirectory->id,
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => trans('dam::app.admin.dam.index.directory.asset-moved-success'),
        ]);

    $updatedAsset = Asset::find($asset->id);
    $expectedPath = 'assets/Root/Screenshots/'.$fileName;
    $this->assertEquals($expectedPath, $updatedAsset->path);

    // ADR 0009 slice 3: move is a path/file_name column change and a directory-pivot sync only —
    // no physical file ever existed at either name.
    expect(Storage::disk($disk)->allFiles())->toBe([]);
});

it('moves an asset even when nothing was ever written for it on disk', function () {
    [$source, $destination, $asset] = seedAssetMove();

    $originalName = $asset->file_name;

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $asset->id,
        'new_parent_id' => $destination->id,
    ])->assertOk();

    $asset->refresh();

    expect($asset->path)->toContain($destination->generatePath())
        ->and($asset->file_name)->toBe($originalName)
        ->and($asset->directories()->pluck('dam_directories.id')->all())->toBe([$destination->id]);
});

it('renders one naming editor, inline pencils, move picker, and no directory path panel', function () {
    $directoryPathEvents = [];
    Event::listen('mothership.dam.asset.edit.card.accordian.directory_path.before', function () use (&$directoryPathEvents) {
        $directoryPathEvents[] = 'before';
    });
    Event::listen('mothership.dam.asset.edit.card.accordian.directory_path.after', function () use (&$directoryPathEvents) {
        $directoryPathEvents[] = 'after';
    });

    $asset = Asset::factory()->create([
        'file_name' => 'campus-map.png',
        'title'     => 'Campus map',
    ]);

    $response = $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk();
    $html = $response->getContent();

    expect([
        'compact actions'       => str_contains($html, 'data-dam-asset-actions'),
        'title pencil'          => str_contains($html, 'data-dam-title-pencil'),
        'filename pencil'       => str_contains($html, 'data-dam-filename-pencil'),
        'title field'           => str_contains($html, 'name="title"'),
        'folder picker'         => str_contains($html, '<v-dam-folder-picker'),
        'directory panel value' => str_contains($html, '{{ displayAssetPath }}'),
    ])->toBe([
        'compact actions'       => true,
        'title pencil'          => true,
        'filename pencil'       => true,
        'title field'           => true,
        'folder picker'         => true,
        'directory panel value' => false,
    ]);

    expect($directoryPathEvents)->toBe(['before', 'after']);
});

it('offers the Details rename pencils only to a role that may rename (UX eval FAULT-10)', function () {
    $asset = Asset::factory()->create(['file_name' => 'campus-map.png']);
    $folder = Directory::factory()->create(['name' => 'Pencils', 'parent_id' => null]);
    $folder->assets()->attach($asset->id);
    $base = ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update'];

    actAsPlaybackRestrictedAdmin($folder, $base);
    $html = $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->getContent();
    expect(str_contains($html, 'data-dam-title-pencil'))->toBeFalse()
        ->and(str_contains($html, 'data-dam-filename-pencil'))->toBeFalse();

    actAsPlaybackRestrictedAdmin($folder, [...$base, 'dam.asset.rename']);
    $html = $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->getContent();
    expect(str_contains($html, 'data-dam-title-pencil'))->toBeTrue()
        ->and(str_contains($html, 'data-dam-filename-pencil'))->toBeTrue();
});

it('should download asset as a zip file', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $fileName = 'sample-'.uniqid().'.pdf';
    $filePath = 'assets/Root/'.$fileName;
    $bytes = str_repeat('compressible dummy content ', 400);
    Storage::disk($disk)->put($filePath, $bytes);

    $asset = Asset::factory()->create([
        'file_name' => $fileName,
        'path'      => $filePath,
    ]);

    $response = $this->get(route('admin.dam.assets.download_compressed', $asset->id));

    $response->assertOk();
    // Streamed to the client (never a temp archive or the whole file as one string), named after the asset.
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain(pathinfo($fileName, PATHINFO_FILENAME).'.zip')
        ->and($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and(glob(public_path('*.zip')))->toBe([]);

    $path = tempnam(sys_get_temp_dir(), 'dam_test_zip_');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue()
        ->and($zip->numFiles)->toBe(1);
    $entry = $zip->statIndex(0);
    expect($entry['name'])->toBe($fileName)
        ->and($zip->getFromIndex(0))->toBe($bytes)
        // It is the "compressed" download: DEFLATE, and smaller than the original.
        ->and($entry['comp_method'])->toBe(ZipArchive::CM_DEFLATE)
        ->and($entry['comp_size'])->toBeLessThan(strlen($bytes));
    $zip->close();
    unlink($path);
});

it('should return 404 when compressed-download targets a non-existent asset', function () {
    $this->get(route('admin.dam.assets.download_compressed', 99999))
        ->assertNotFound();
});

it('should return 404 when compressed-download file is missing from storage', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $asset = Asset::factory()->create([
        'file_name' => 'missing.pdf',
        'path'      => 'assets/Root/missing.pdf',
    ]);

    $this->get(route('admin.dam.assets.download_compressed', $asset->id))
        ->assertNotFound();
});

it('should return cached metadata for an asset', function () {
    $asset = Asset::factory()->create([
        'meta_data' => ['FileType' => 'JPEG', 'ImageWidth' => 800],
    ]);

    $this->getJson(route('admin.dam.assets.metadata', $asset->id))
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonPath('data.FileType', 'JPEG')
        ->assertJsonPath('data.ImageWidth', 800);
});

it('should flatten exif sub-keys from cached metadata', function () {
    $asset = Asset::factory()->create([
        'meta_data' => [
            'FileType'  => 'PNG',
            'exif'      => ['Make' => 'Canon', 'Model' => 'EOS'],
        ],
    ]);

    $response = $this->getJson(route('admin.dam.assets.metadata', $asset->id))
        ->assertOk()
        ->assertJson(['success' => true]);

    $data = $response->json('data');
    expect($data)->toHaveKey('Make');
    expect($data)->toHaveKey('Model');
    expect($data)->not->toHaveKey('exif');
});

it('should return error when asset has no metadata and file is missing', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $asset = Asset::factory()->create([
        'path'      => 'assets/Root/missing-file.jpg',
        'meta_data' => null,
    ]);

    $this->getJson(route('admin.dam.assets.metadata', $asset->id))
        ->assertOk()
        ->assertJson(['success' => false]);
});

function seedAssetMove(): array
{
    $src = Directory::factory()->create();
    $dst = Directory::factory()->create();

    // ADR 0009 slice 3: folders and asset content are database rows only — a move needs no file on
    // disk to begin with.
    $fileName = 'sample.jpg';
    $assetPath = sprintf('assets/%s/%s', $src->generatePath(), $fileName);

    $asset = Asset::factory()->create([
        'file_name' => $fileName,
        'path'      => $assetPath,
    ]);
    $src->assets()->attach($asset->id);

    return [$src, $dst, $asset];
}

it('moves a single asset from one directory to another (drag-move)', function () {
    [$src, $dst, $asset] = seedAssetMove();

    $response = $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $asset->id,
        'new_parent_id' => $dst->id,
    ]);

    $response->assertOk();

    $asset->refresh();
    $linkedDirectoryIds = $asset->directories()->pluck('dam_directories.id')->all();
    expect($linkedDirectoryIds)->toContain($dst->id);
    expect($linkedDirectoryIds)->not->toContain($src->id);
});

it('after drag-move, source directory.assets no longer lists the asset', function () {
    config()->set('dam.tree.show_assets', true);

    [$src, $dst, $asset] = seedAssetMove();

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $asset->id,
        'new_parent_id' => $dst->id,
    ])->assertOk();

    $srcResp = $this->getJson(route('admin.dam.directory.assets', ['id' => $src->id]));
    $srcResp->assertOk();
    $srcIds = collect($srcResp->json('data'))->pluck('id')->all();
    expect($srcIds)->not->toContain($asset->id);
});

it('after drag-move, target directory.assets includes the asset', function () {
    config()->set('dam.tree.show_assets', true);

    [$src, $dst, $asset] = seedAssetMove();

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $asset->id,
        'new_parent_id' => $dst->id,
    ])->assertOk();

    $dstResp = $this->getJson(route('admin.dam.directory.assets', ['id' => $dst->id]));
    $dstResp->assertOk();
    $dstIds = collect($dstResp->json('data'))->pluck('id')->all();
    expect($dstIds)->toContain($asset->id);
});

it('drag-move requires authentication', function () {
    auth('admin')->logout();
    $response = $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => 1,
        'new_parent_id' => 1,
    ]);
    expect(in_array($response->status(), [302, 401, 403], true))->toBeTrue();
});

it('edit view exposes all fields needed for Vue reactive display', function () {
    $asset = Asset::factory()->create([
        'file_size' => 2_097_152,
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->get(route('admin.dam.assets.edit', $asset->id))
        ->assertOk()
        ->assertViewHas('fileSize', '2.00 MB')
        ->assertViewHas('directoryAncestors');
});

it('edit view directoryAncestors is empty collection for asset without directory', function () {
    $asset = Asset::factory()->create();

    $response = $this->get(route('admin.dam.assets.edit', $asset->id));
    $response->assertOk();

    $ancestors = $response->viewData('directoryAncestors');
    expect($ancestors)->toBeInstanceOf(Collection::class);
    expect($ancestors->isEmpty())->toBeTrue();
});

it('edit view directoryAncestors contains parent chain for nested directory', function () {
    $root = Directory::factory()->create(['name' => 'Assets', 'parent_id' => null]);
    $sub = Directory::factory()->create(['name' => 'Photography', 'parent_id' => $root->id]);
    $leaf = Directory::factory()->create(['name' => 'Landscapes', 'parent_id' => $sub->id]);
    $asset = Asset::factory()->create();
    $leaf->assets()->attach($asset);

    $response = $this->get(route('admin.dam.assets.edit', $asset->id));
    $response->assertOk();

    $ancestors = $response->viewData('directoryAncestors');
    expect($ancestors->pluck('name')->toArray())->toBe(['Assets', 'Photography', 'Landscapes']);
});

it('serves a download as private, no-cache with a validator so the browser revalidates to a 304', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $filePath = 'assets/Root/cacheable-'.uniqid().'.pdf';
    Storage::disk($disk)->put($filePath, 'dummy content');

    $asset = Asset::factory()->create(['file_name' => basename($filePath), 'path' => $filePath]);

    $first = $this->get(route('admin.dam.assets.download', $asset->id))->assertOk();

    $cacheControl = (string) $first->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->toContain('no-cache')->not->toContain('no-store');
    expect($first->headers->get('ETag') ?? $first->headers->get('Last-Modified'))->not->toBeNull();

    $this->withHeaders(array_filter([
        'If-None-Match'     => $first->headers->get('ETag'),
        'If-Modified-Since' => $first->headers->get('Last-Modified'),
    ]))->get(route('admin.dam.assets.download', $asset->id))->assertStatus(304);
});

it('still answers 404 with no-store to a user without access to the folder, never a cacheable 200 or 304', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $filePath = 'assets/Root/restricted-'.uniqid().'.pdf';
    Storage::disk($disk)->put($filePath, 'dummy content');
    $asset = Asset::factory()->create(['file_name' => basename($filePath), 'path' => $filePath]);

    $member = Admin::factory()->create([
        'role_id' => Role::factory()->create([
            'permission_type' => 'custom',
            // The route ACL allows the download, so the asset's own access check decides.
            'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download'],
        ])->id,
    ]);
    $this->actingAs($member, 'admin');

    $response = $this->withHeaders(['If-None-Match' => '"anything"'])->get(route('admin.dam.assets.download', $asset->id));

    // Hidden answers like missing (404), and the error keeps no-store.
    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('denies a conditional download to a role that keeps dam.asset.download once the asset\'s own access is removed', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $filePath = 'assets/Root/revoked-'.uniqid().'.pdf';
    Storage::disk($disk)->put($filePath, 'dummy content');
    $asset = Asset::factory()->create(['file_name' => basename($filePath), 'path' => $filePath]);
    $directory = Directory::factory()->create();
    $directory->assets()->attach($asset->id);

    $member = Admin::factory()->create([
        'role_id' => Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download'],
        ])->id,
    ]);

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    // Folder access stays for the whole test; only the asset's own list changes (ADR 0010).
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->actingAs($member, 'admin');
    $first = $this->get(route('admin.dam.assets.download', $asset->id))->assertOk();
    $validators = array_filter([
        'If-None-Match'     => $first->headers->get('ETag'),
        'If-Modified-Since' => $first->headers->get('Last-Modified'),
    ]);

    // The asset's own list loses the member; the route ACL (dam.asset.download) and the folder access are unchanged.
    DB::table('dam_asset_user_access')->where('admin_id', $member->id)->delete();
    app(DirectoryPermissionService::class)->flush();

    $response = $this->withHeaders($validators)->get(route('admin.dam.assets.download', $asset->id));

    // Hidden answers like missing (404), and the error keeps no-store.
    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

// ADR 0010: a folder never widens a file's access. A file hidden from the Library is refused by every action that
// shares the asset check, including each download variant, even where the folder itself is open to the user.
it('refuses a folder-visible but file-private asset everywhere the Library hides it', function (string $comparisonTime) {
    // Freeze each comparison across the collector's 10-minute expiry bucket; keep full HTML equality.
    $this->travelTo(Carbon::parse($comparisonTime));
    Storage::fake(Directory::getAssetDisk());
    $filePath = 'assets/Root/private-'.uniqid().'.pdf';
    Storage::disk(Directory::getAssetDisk())->put($filePath, 'dummy content');
    $asset = Asset::factory()->create(['file_name' => basename($filePath), 'path' => $filePath]);
    $directory = Directory::factory()->create();
    $directory->assets()->attach($asset->id);

    $member = Admin::factory()->create([
        'role_id' => Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.update', 'dam.asset.download', 'dam.asset.download_compressed', 'dam.asset.custom_download', 'dam.asset.rename', 'dam.asset.moved', 'dam.asset.re_upload', 'dam.asset.destroy', 'dam.asset.meta_data'],
        ])->id,
    ]);

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->actingAs($member, 'admin');

    $listed = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->assertOk()->json('assets'))->pluck('id');
    expect($listed)->not->toContain($asset->id);

    // Each action answers the hidden asset exactly like an id that does not exist, so a probe
    // learns nothing. Debug pages embed the caller's line number; compare what production sends.
    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id): array => [
        ['GET', route('admin.dam.assets.download', $id), []],
        ['GET', route('admin.dam.assets.download_compressed', $id), []],
        ['GET', route('admin.dam.assets.custom_download', [$id, 'format' => 'png']), []],
        ['GET', route('admin.dam.assets.info', $id), []],
        ['GET', route('admin.dam.assets.edit', $id), []],
        ['GET', route('admin.dam.assets.show', $id), []],
        ['GET', route('admin.dam.assets.metadata', $id), []],
        ['PUT', route('admin.dam.assets.update', $id), ['file_name' => 'renamed.pdf']],
        ['DELETE', route('admin.dam.assets.destroy', $id), []],
        ['POST', route('admin.dam.assets.rename'), ['id'          => $id, 'file_name' => 'renamed']],
        ['POST', route('admin.dam.assets.moved'), ['move_item_id' => $id, 'new_parent_id' => $directory->id]],
        ['POST', route('admin.dam.assets.re_upload'), ['asset_id' => $id, 'file' => UploadedFile::fake()->create('new.pdf', 1)]],
    ];

    foreach (array_map(null, $requests($asset->id), $requests($missingId)) as [[$method, $url, $data], $missingRequest]) {
        $hidden = $this->json($method, $url, $data);
        $missing = $this->json(...$missingRequest);

        expect($hidden->getStatusCode())->toBe(404, $url)
            ->and($hidden->getContent())->toBe($missing->getContent(), $url);
    }

    // A browser's HTML pages answer the same way, headers included.
    foreach (['edit', 'download', 'download_compressed'] as $name) {
        $hidden = $this->get(route('admin.dam.assets.'.$name, $asset->id));
        $missing = $this->get(route('admin.dam.assets.'.$name, $missingId));

        expect($hidden->getStatusCode())->toBe(404, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name)
            ->and($hidden->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'), $name)
            ->and($hidden->headers->get('Cache-Control'))->toBe($missing->headers->get('Cache-Control'), $name);
    }

    expect(Asset::find($asset->id)?->file_name)->toBe($asset->file_name);

    // Once the asset's own list names the member, the same calls open: the Library's visibility and the actions agree.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    expect(collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->json('assets'))->pluck('id'))->toContain($asset->id);
    $this->get(route('admin.dam.assets.download', $asset->id))->assertOk();
    $this->get(route('admin.dam.assets.info', $asset->id))->assertOk();
    // The metadata action gained its access check here; a granted member still reads it.
    $this->getJson(route('admin.dam.assets.metadata', $asset->id))->assertOk()->assertJsonPath('success', true);
})->with(['before telemetry bucket' => '2026-10-13 22:59:59 UTC', 'after telemetry bucket' => '2026-10-13 23:00:01 UTC']);

// The internal "Copy link" for a file: the Library at the file's folder, told which file to open. A file the viewer
// cannot open answers exactly like an id that does not exist, and the route itself writes nothing.
it('sends a viewer of a file to the Library to open it, and answers a hidden file like a missing one', function () {
    Storage::fake(Directory::getAssetDisk());
    $filePath = 'assets/Root/open-'.uniqid().'.pdf';
    Storage::disk(Directory::getAssetDisk())->put($filePath, 'dummy content');
    $asset = Asset::factory()->create(['file_name' => basename($filePath), 'path' => $filePath]);
    $directory = Directory::factory()->create();
    $directory->assets()->attach($asset->id);

    $member = Admin::factory()->create([
        'role_id' => Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => ['dam', 'dam.asset', 'dam.asset.view'],
        ])->id,
    ]);

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $hidden = $this->get(route('admin.dam.assets.open', $asset->id));
    $missing = $this->get(route('admin.dam.assets.open', $missingId));

    expect($hidden->getStatusCode())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->getContent());

    $shares = DB::table('dam_shares')->count();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->get(route('admin.dam.assets.open', $asset->id))
        ->assertRedirect(route('admin.dam.assets.index', ['directory_id' => $directory->id, 'open_asset' => $asset->id]));

    expect(DB::table('dam_shares')->count())->toBe($shares);
});

// A per-file grant opens the file without revealing the folder it sits in: the redirect names no folder the viewer
// cannot open.
it('names no folder in the open redirect when the viewer cannot open the folder', function () {
    $asset = Asset::factory()->create();
    $directory = Directory::factory()->create();
    $directory->assets()->attach($asset->id);

    $member = Admin::factory()->create([
        'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id,
    ]);

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    $this->get(route('admin.dam.assets.open', $asset->id))
        ->assertRedirect(route('admin.dam.assets.index', ['open_asset' => $asset->id]));
});

it('shows a name without the characters that reorder it, so a file cannot dress as another type', function () {
    $asset = Asset::factory()->create(['file_name' => "report\u{202E}evil\u{202C} gpj.jpg", 'title' => null]);

    // Hebrew letters and direction marks stay: they are how real right-to-left names are written.
    $hebrew = Asset::factory()->create(['file_name' => "\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}.jpg", 'title' => null]);

    expect($asset->display_name)->toBe('reportevil gpj.jpg')
        ->and($hebrew->display_name)->toBe("\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}.jpg");
});

it('makes ZIP entry names safe to open anywhere and keeps them unique', function () {
    expect(ZipEntryNames::segment('CON.jpg'))->toBe('CON_.jpg')
        ->and(ZipEntryNames::segment('nul'))->toBe('nul_')
        ->and(ZipEntryNames::segment('Lpt9.tar.gz'))->toBe('Lpt9_.tar.gz')
        ->and(ZipEntryNames::segment('COM10.txt'))->toBe('COM10.txt')
        ->and(ZipEntryNames::segment('console.jpg'))->toBe('console.jpg')
        ->and(ZipEntryNames::segment('photo.jpg  '))->toBe('photo.jpg')
        ->and(ZipEntryNames::segment('photo...'))->toBe('photo')
        ->and(ZipEntryNames::segment('.hidden.jpg'))->toBe('hidden.jpg')
        ->and(ZipEntryNames::segment("report\u{202E}evil\u{202C} gpj.jpg"))->toBe('reportevil gpj.jpg')
        ->and(ZipEntryNames::segment('...'))->toBe('file')
        ->and(ZipEntryNames::path('Shoot/.cache/AUX.png'))->toBe('Shoot/cache/AUX_.png');

    $taken = [];

    // Different names that clean to the same one stay separate entries.
    expect(ZipEntryNames::unique('a.jpg', $taken))->toBe('a.jpg')
        ->and(ZipEntryNames::unique('a.jpg ', $taken))->toBe('a (2).jpg')
        ->and(ZipEntryNames::unique('.A.jpg', $taken))->toBe('A (3).jpg')
        ->and(ZipEntryNames::unique('Dir/CON.jpg', $taken))->toBe('Dir/CON_.jpg')
        ->and(ZipEntryNames::unique('Dir/con_.jpg', $taken))->toBe('Dir/con_ (2).jpg');
});

it('never makes a cleaned ZIP name both a file and a folder, in either entry order', function () {
    // File first: ".cache" cleans to "cache", so the folder "cache/" that follows is mapped to "cache (2)".
    $taken = [];
    expect(ZipEntryNames::unique('.cache', $taken))->toBe('cache')
        ->and(ZipEntryNames::unique('cache/photo.jpg', $taken))->toBe('cache (2)/photo.jpg')
        ->and(ZipEntryNames::unique('cache/other.jpg', $taken))->toBe('cache (2)/other.jpg');

    // Folder first: the later file ".cache" cannot take the folder's name.
    $taken = [];
    expect(ZipEntryNames::unique('cache/photo.jpg', $taken))->toBe('cache/photo.jpg')
        ->and(ZipEntryNames::unique('.cache', $taken))->toBe('cache (2)');

    // A numbered folder never lands on a folder or file that already exists under that number, in any case.
    $taken = [];
    expect(ZipEntryNames::unique('cache', $taken))->toBe('cache')
        ->and(ZipEntryNames::unique('CACHE (2)/a.jpg', $taken))->toBe('CACHE (2)/a.jpg')
        ->and(ZipEntryNames::unique('cache/b.jpg', $taken))->toBe('cache (3)/b.jpg')
        ->and(ZipEntryNames::unique('CACHE (2)/c.jpg', $taken))->toBe('CACHE (2)/c.jpg')
        ->and(ZipEntryNames::unique('cache/d.jpg', $taken))->toBe('cache (3)/d.jpg');

    // Two distinct folder names that clean to the same one (and the same name in another case) share one folder, and
    // the files inside keep their own unique names.
    $taken = [];
    expect(ZipEntryNames::unique('.Shots/a.jpg', $taken))->toBe('Shots/a.jpg')
        ->and(ZipEntryNames::unique('shots /a.jpg', $taken))->toBe('Shots/a (2).jpg')
        ->and(ZipEntryNames::unique('SHOTS/b.jpg', $taken))->toBe('Shots/b.jpg');
});

it('places a ZIP marker beside its entry without remapping the folder, and never over a real file of that name', function () {
    // A file called "cache" forces the folder to "cache (2)"; the marker for a missing cache/photo.jpg must stay in it.
    $taken = [];
    expect(ZipEntryNames::unique('cache', $taken))->toBe('cache');
    $entry = ZipEntryNames::unique('cache/photo.jpg', $taken);
    expect($entry)->toBe('cache (2)/photo.jpg')
        ->and(ZipEntryNames::uniqueBeside($entry.'.MISSING.txt', $taken))->toBe('cache (2)/photo.jpg.MISSING.txt')
        ->and(ZipEntryNames::unique('cache/other.jpg', $taken))->toBe('cache (2)/other.jpg');

    // A real file named like the marker: in either order, the two stay separate entries.
    $taken = [];
    $missing = ZipEntryNames::unique('bad.mp4', $taken);
    expect(ZipEntryNames::uniqueBeside($missing.'.MISSING.txt', $taken))->toBe('bad.mp4.MISSING.txt')
        ->and(ZipEntryNames::unique('bad.mp4.MISSING.txt', $taken))->toBe('bad (2).mp4.MISSING.txt');

    $taken = [];
    expect(ZipEntryNames::unique('bad.mp4.MISSING.txt', $taken))->toBe('bad.mp4.MISSING.txt');
    $missing = ZipEntryNames::unique('bad.mp4', $taken);
    expect(ZipEntryNames::uniqueBeside($missing.'.MISSING.txt', $taken))->toBe('bad (2).mp4.MISSING.txt');
});

it('keeps numbered ZIP folders collision-free and stable when files and source folders interleave', function () {
    // (a) The files "cache" and "CACHE (2)" come first, so the folder "cache" has to skip both names.
    $taken = [];
    $out = [
        ZipEntryNames::unique('cache', $taken),
        ZipEntryNames::unique('CACHE (2)', $taken),
        ZipEntryNames::unique('cache/a.jpg', $taken),
        ZipEntryNames::unique('cache/b.jpg', $taken),
        ZipEntryNames::unique('Cache/a.jpg', $taken),
    ];

    expect($out)->toBe(['cache', 'CACHE (2)', 'cache (3)/a.jpg', 'cache (3)/b.jpg', 'cache (3)/a (2).jpg'])
        ->and(count(array_unique(array_map('mb_strtolower', $out))))->toBe(count($out));

    // (b) "cache/a.jpg" is mapped to "cache (2)" (a file called "cache" exists); a distinct source folder literally
    // named "cache (2)" must not share that output folder, and both mappings stay put for later entries.
    $taken = [];
    $out = [
        ZipEntryNames::unique('cache', $taken),
        ZipEntryNames::unique('cache/a.jpg', $taken),
        ZipEntryNames::unique('cache (2)/b.jpg', $taken),
        ZipEntryNames::unique('cache/c.jpg', $taken),
        ZipEntryNames::unique('cache (2)/d.jpg', $taken),
        ZipEntryNames::unique('CACHE/e.jpg', $taken),
    ];

    expect($out)->toBe(['cache', 'cache (2)/a.jpg', 'cache (2) (2)/b.jpg', 'cache (2)/c.jpg', 'cache (2) (2)/d.jpg', 'cache (2)/e.jpg'])
        ->and(count(array_unique(array_map('mb_strtolower', $out))))->toBe(count($out));
});

it('refuses a zip without an index.html from Web Pages (context=pages) with a 422 and stores nothing', function () {
    Storage::fake(Directory::getAssetDisk());
    $directory = Directory::factory()->create(['name' => 'Pages', 'parent_id' => null]);
    $zipPath = tempnam(sys_get_temp_dir(), 'nz').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'no page here');
    $zip->close();
    $before = Asset::query()->count();

    $this->postJson(route('admin.dam.assets.upload'), ['files' => [new UploadedFile($zipPath, 'plain.zip', 'application/zip', null, true)], 'directory_id' => $directory->id, 'context' => 'pages'])
        ->assertUnprocessable()->assertJsonPath('success', false);

    expect(Asset::query()->count())->toBe($before);

    // The Library itself still files the same zip as a plain document.
    $this->postJson(route('admin.dam.assets.upload'), ['files' => [new UploadedFile($zipPath, 'plain.zip', 'application/zip', null, true)], 'directory_id' => $directory->id])->assertCreated();
});

it('answers a zip preflight for an empty folder with a 422 message instead of a redirect', function () {
    $empty = Directory::factory()->create(['name' => 'Nothing here', 'parent_id' => null]);

    $this->getJson(route('admin.dam.directory.zip_download', $empty->id), ['X-Zip-Preflight' => '1'])
        ->assertUnprocessable()->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.empty-directory'));
});

// LIB-28 / QRAI-01: the Library's and Web Pages' single-request upload replaces a same-name file only for an uploader who
// may re-upload that asset (`dam.asset.re_upload` plus edit rights on it). Anyone else keeps it intact, public links
// included, and gets a new asset under the next free name; the response tells the uploader which one happened.
it('replaces a same-name file only for an uploader who may re-upload it, and says what the upload did', function () {
    $directory = Directory::factory()->create(['name' => 'Shared '.uniqid(), 'parent_id' => null]);
    $upload = fn (string $bytes) => $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->createWithContent('notes.txt', $bytes)],
        'directory_id' => $directory->id,
    ])->assertCreated();
    $member = function (array $extra) use ($directory): Admin {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.upload', ...$extra]]);
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
        app(DirectoryPermissionService::class)->flush();

        return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    };
    $bytesOf = fn (int $id) => (function (Asset $asset) {
        $object = app(ContentStore::class)->current($asset);

        return Storage::disk($object->disk)->get($object->path);
    })(Asset::findOrFail($id));

    $originalId = $upload('the admin original')->json('files.0.id');

    $this->actingAs($member([]), 'admin');
    $refused = $upload('a user without re-upload');

    expect($refused->json('files.0.id'))->not->toBe($originalId)
        ->and($refused->json('files.0.file_name'))->toBe('notes(1).txt')
        ->and($refused->json('published_notes'))->toBe([trans('dam::app.admin.dam.duplicates.saved-as', ['name' => 'notes(1).txt'])])
        ->and($bytesOf($originalId))->toBe('the admin original');

    $editor = $member(['dam.asset.re_upload']);
    $this->actingAs($editor, 'admin');
    $replaced = $upload('an editor with re-upload');

    expect($replaced->json('files.0.id'))->toBe($originalId)
        ->and($replaced->json('published_notes'))->toBe([trans('dam::app.admin.dam.duplicates.replaced', ['name' => 'notes.txt'])])
        ->and($bytesOf($originalId))->toBe('an editor with re-upload')
        ->and((int) DB::table('dam_asset_versions')->where('dam_asset_id', $originalId)->orderByDesc('number')->value('created_by'))->toBe($editor->id);
});

// SETUP-07: PHP keeps only the first `max_file_uploads` files of a request and drops the rest before the app starts;
// public/index.php records that as a server variable. Every multi-file upload route then refuses the whole request,
// naming the limit, instead of storing what arrived and reporting success.
it('refuses an upload request PHP cut short at max_file_uploads, naming the limit, and stores nothing', function (string $path) {
    $directory = Directory::factory()->create(['name' => 'Batch '.uniqid(), 'parent_id' => null]);
    $files = [UploadedFile::fake()->createWithContent('one.txt', 'one'), UploadedFile::fake()->createWithContent('two.txt', 'two')];
    $before = Asset::count();
    $server = [AssetHelper::FILE_UPLOADS_EXCEEDED => '1'];

    $response = match ($path) {
        'web'    => $this->withServerVariables($server)->postJson(route('admin.dam.assets.upload'), ['files' => $files, 'directory_id' => $directory->id]),
        'folder' => $this->withServerVariables($server)->postJson(route('admin.dam.assets.upload_folder'), ['files' => $files, 'relative_paths' => ['Shoot/one.txt', 'Shoot/two.txt'], 'directory_id' => $directory->id]),
        'rest'   => $this->withServerVariables($server)->withHeaders($this->getAuthenticationHeaders('custom', ['api.dam.assets.upload']))
            ->post(route('admin.api.dam.assets.upload'), ['files' => $files, 'directory_id' => $directory->id]),
    };

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.datagrid.too-many-files', ['max' => (int) ini_get('max_file_uploads')]));
    expect(Asset::count())->toBe($before);
})->with(['web', 'folder', 'rest']);

// Review of LIB-28: the uploader's right to replace is asked again under the asset's row lock, which access-list
// rewrites also take. A revocation that commits after the first check (simulated here by the first check itself)
// leaves the file untouched and publishes the upload as a new asset, under either access model.
it('publishes a new asset when the uploader loses the right to replace between the check and the write', function (string $model) {
    if ($model === 'lists') {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'lists']);
        app(AccessModel::class)->flush();
    }

    $directory = Directory::factory()->create(['name' => 'Race '.uniqid(), 'parent_id' => null]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.upload', 'dam.asset.re_upload']]);
    $editor = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $editor->id]);
    $upload = fn (string $bytes) => $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->createWithContent('race.txt', $bytes)],
        'directory_id' => $directory->id,
    ])->assertCreated();

    $originalId = null;
    $revoke = function () use ($directory, $role, $editor, &$originalId): void {
        DB::table('dam_directory_role')->where(['directory_id' => $directory->id, 'role_id' => $role->id])->delete();
        DB::table('dam_asset_user_access')->where(['asset_id' => $originalId, 'admin_id' => $editor->id])->delete();
    };
    $publisher = new class(app(FileStorer::class), app(ContentStore::class), app(AccessModel::class), app(AccessListWriter::class)) extends AssetUploadPublisher
    {
        public ?Closure $afterFirstCheck = null;

        public function mayReplace(Asset $existing, ?int $actorId): bool
        {
            $allowed = parent::mayReplace(existing: $existing, actorId: $actorId);

            if ($this->afterFirstCheck !== null) {
                ($this->afterFirstCheck)();
                $this->afterFirstCheck = null;
            }

            return $allowed;
        }
    };
    $publisher->afterFirstCheck = $revoke;
    // Bound before the first request: the route keeps the controller, and its publisher, once resolved.
    $this->app->instance(AssetUploadPublisher::class, $publisher);
    $originalId = $upload('the admin original')->json('files.0.id');

    $this->actingAs($editor, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $raced = $upload('an editor whose access was just revoked');

    $original = Asset::findOrFail($originalId);
    $object = app(ContentStore::class)->current($original);

    expect($raced->json('files.0.id'))->not->toBe($originalId)
        ->and($raced->json('files.0.file_name'))->toBe('race(1).txt')
        ->and(Storage::disk($object->disk)->get($object->path))->toBe('the admin original')
        ->and(DB::table('dam_asset_versions')->where('dam_asset_id', $originalId)->count())->toBe(0);
})->with(['grants', 'lists']);

// NEW-A1: re-uploading a file from the details page looked its name up without excluding the asset being replaced,
// so the asset collided with its own path and was renamed `name(1)`. Its own name is kept; a name that another
// asset in the folder holds still earns the free-name suffix, and that other asset is untouched.
it('keeps the asset name on a same-name re-upload and suffixes only a name another asset holds', function (string $route) {
    $directory = Directory::factory()->create(['name' => 'Reupload '.uniqid(), 'parent_id' => null]);
    $seed = fn (string $name) => $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->createWithContent($name, 'original '.$name)],
        'directory_id' => $directory->id,
    ])->assertCreated()->json('files.0.id');
    $own = Asset::findOrFail($seed('poster.txt'));
    $other = Asset::findOrFail($seed('cover.txt'));
    $reUpload = fn (string $name) => ($route === 'web'
        ? $this->postJson(route('admin.dam.assets.re_upload'), ['file' => UploadedFile::fake()->createWithContent($name, 'new '.uniqid()), 'asset_id' => $own->id])
        : $this->withHeaders($this->getAuthenticationHeaders())
            ->post(route('admin.api.dam.assets.reUpload'), ['file' => UploadedFile::fake()->createWithContent($name, 'new '.uniqid()), 'asset_id' => $own->id], ['Accept' => 'application/json'])
    )->assertCreated();

    $reUpload('poster.txt');
    expect($own->fresh()->only(['file_name', 'path']))->toBe(['file_name' => 'poster.txt', 'path' => $own->path]);

    $reUpload('cover.txt');
    expect($own->fresh()->file_name)->toBe('cover(1).txt')
        ->and($own->fresh()->path)->toBe(dirname($own->path).'/cover(1).txt')
        ->and($other->fresh()->only(['file_name', 'path']))->toBe($other->only(['file_name', 'path']));
})->with(['web', 'rest']);

/**
 * Asset::displayNameSql() before meta_display_title: every lookup read straight from meta_data. Kept here as the
 * oracle the stored column must agree with, row for row and in order, on whichever database runs the suite.
 */
function legacyDisplayNameSql(): string
{
    $grammar = DB::connection()->getQueryGrammar();
    $properties = $grammar->wrapTable('dam_asset_properties');
    $assetId = $grammar->wrap('dam_assets.id');
    $candidates = ["(SELECT value FROM {$properties} WHERE dam_asset_id = {$assetId} AND LOWER(name) = 'title' AND TRIM(value) <> '' ORDER BY id LIMIT 1)"];

    foreach (['dam_assets.title', 'dam_assets.meta_data->Title', 'dam_assets.meta_data->title'] as $column) {
        $candidates[] = $grammar->wrap($column);
    }

    return 'COALESCE('.implode(', ', array_map(fn ($value) => "NULLIF(TRIM({$value}), '')", $candidates)).', '.$grammar->wrap('dam_assets.file_name').')';
}

it('sorts and labels by the same display name now that meta_data titles come from a generated column', function () {
    $root = Directory::whereNull('parent_id')->firstOrFail();
    $make = function (string $file, array $attributes = []) use ($root): Asset {
        $asset = Asset::create(array_merge([
            'file_name' => $file, 'file_type' => 'image', 'file_size' => 1, 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'path' => 'assets/Root/'.$file,
        ], $attributes));
        $asset->directories()->attach($root->id);

        return $asset;
    };

    $make('plain.jpg');
    $make('column-title.jpg', ['title' => '  Zulu column  ']);
    $make('blank-column.jpg', ['title' => '   ', 'meta_data' => ['Title' => 'Alpha capital']]);
    $make('capital.jpg', ['meta_data' => ['Title' => 'Bravo capital', 'title' => 'ignored lower']]);
    $make('lower.jpg', ['meta_data' => ['title' => '  Charlie lower  ']]);
    $make('blank-capital.jpg', ['meta_data' => ['Title' => '  ', 'title' => 'Delta fallback']]);
    $make('blank-both.jpg', ['meta_data' => ['Title' => '', 'title' => ' ']]);
    $make('number.jpg', ['meta_data' => ['Title' => 42]]);
    // Only characters a MySQL unicode_ci collation ignores: not blank to the json comparison, so it is the name.
    $make('ignorable-only.jpg', ['meta_data' => ['Title' => "\u{200B}", 'title' => 'fallback loses']]);
    $make('other-keys.jpg', ['meta_data' => ['Keywords' => 'sunset', 'nested' => ['Title' => 'not top level']]]);
    $make("Accent \u{00C9}clair.jpg", ['title' => "\u{00C9}clair"]);
    $make('UPPER.jpg', ['meta_data' => ['Title' => 'ECHO']]);
    $make('echo again.jpg', ['meta_data' => ['Title' => 'echo']]);
    $propertyTitled = $make('property.jpg', ['title' => 'Column loses', 'meta_data' => ['Title' => 'Meta loses']]);
    AssetProperty::factory()->create(['dam_asset_id' => $propertyTitled->id, 'name' => 'Title', 'value' => 'Foxtrot property']);
    $blankProperty = $make('blank-property.jpg', ['meta_data' => ['Title' => 'Golf from meta']]);
    AssetProperty::factory()->create(['dam_asset_id' => $blankProperty->id, 'name' => 'title', 'value' => '   ']);

    $columns = [DB::raw(Asset::displayNameSql().' AS name'), DB::raw(legacyDisplayNameSql().' AS legacy_name')];
    $rows = DB::table('dam_assets')->select(['id', ...$columns])->orderBy('id')->get();

    expect($rows)->toHaveCount(15);
    foreach ($rows as $row) {
        expect($row->name)->toBe($row->legacy_name, "asset {$row->id}");
    }

    $sorted = fn (string $expression, string $direction) => DB::table('dam_assets')->orderByRaw("{$expression} {$direction}")->orderBy('id')->pluck('id')->all();
    foreach (['asc', 'desc'] as $direction) {
        expect($sorted(Asset::displayNameSql(), $direction))->toBe($sorted(legacyDisplayNameSql(), $direction), $direction);
    }

    // The column is the same two lookups, so the label a card shows is unchanged.
    $names = Asset::query()->withDisplayName()->orderBy('id')->get()->pluck('display_name', 'file_name');
    expect($names['capital.jpg'])->toBe('Bravo capital')
        ->and($names['lower.jpg'])->toBe('Charlie lower')
        ->and($names['blank-capital.jpg'])->toBe('Delta fallback')
        ->and($names['blank-both.jpg'])->toBe('blank-both.jpg')
        ->and($names['property.jpg'])->toBe('Foxtrot property')
        ->and($names['blank-property.jpg'])->toBe('Golf from meta')
        ->and($names['column-title.jpg'])->toBe('Zulu column');
});

it('never writes, serializes or audits meta_display_title, however a loaded asset is copied or filled', function () {
    $asset = Asset::create([
        'file_name' => 'serialized.jpg', 'file_type' => 'image', 'file_size' => 1, 'mime_type' => 'image/jpeg',
        'extension' => 'jpg', 'path' => 'assets/Root/serialized.jpg', 'meta_data' => ['Title' => 'Kept out'],
    ]);

    $loaded = Asset::query()->findOrFail($asset->id);

    expect($loaded->getAttributes())->toHaveKey('meta_display_title', 'Kept out')
        ->and($loaded->toArray())->not->toHaveKey('meta_display_title');

    // A copy of a loaded row carries every column, the generated one included; saving it must still insert.
    $copy = $loaded->replicate();
    $copy->path = 'assets/Root/serialized-copy.jpg';
    $copy->save();
    expect($copy->exists)->toBeTrue()
        ->and(Asset::query()->findOrFail($copy->id)->getAttributes()['meta_display_title'])->toBe('Kept out');

    // forceFill() of the generated column is dropped from the update, which still lands the real change.
    $loaded->forceFill(['meta_display_title' => 'forced', 'meta_data' => ['Title' => 'Changed']])->save();
    expect(Asset::query()->findOrFail($asset->id)->getAttributes()['meta_display_title'])->toBe('Changed');

    // The history rows of those writes never mention the helper.
    $audits = DB::table('audits')->where('auditable_type', 'like', '%Asset')->get(['old_values', 'new_values']);
    expect($audits)->not->toBeEmpty();
    foreach ($audits as $audit) {
        expect($audit->old_values.$audit->new_values)->not->toContain('meta_display_title');
    }
});
