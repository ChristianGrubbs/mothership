<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * A stack's picker and file strip draw each file as a small picture. The files payload names a `thumbnail_url`
 * only for a type that has a derivative, and `?thumb=1` answers with that derivative: never the original, and never
 * in place of `?download=1`.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Thumbs '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'menu.png', 'path' => 'assets/Thumbs/menu.png']));
    $this->asset->directories()->attach($this->folder->id);
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id;
});

$png = function (int $width, int $height): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
};

$attach = fn (object $test, string $name, string $content, string $label) => $test->postJson($test->base.'/files', [
    'file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => 'sidecar', 'label' => $label,
])->assertCreated()->json();

// A derivative cached on disk is a file response; the first request for a small raster answers with the bytes it just made.
$body = fn ($response) => match (true) {
    $response->baseResponse instanceof BinaryFileResponse => file_get_contents($response->baseResponse->getFile()->getPathname()),
    $response->baseResponse instanceof StreamedResponse   => $response->streamedContent(),
    default                                               => $response->getContent(),
};

$thumb = fn (object $test, array $file, array $query = ['thumb' => 1]) => $test->get(route('admin.dam.file.asset-file', ['asset' => $test->asset->id, 'assetFile' => $file['id']] + $query));

it('decides which files can show a small picture', function () {
    expect(StackFileThumbnail::kind('image/jpeg', 'a.jpg'))->toBe(StackFileThumbnail::KIND_RASTER)
        ->and(StackFileThumbnail::kind('image/png; charset=binary', null))->toBe(StackFileThumbnail::KIND_RASTER)
        ->and(StackFileThumbnail::kind('application/octet-stream', 'DJI_0157_D.DNG'))->toBe(StackFileThumbnail::KIND_DNG)
        ->and(StackFileThumbnail::kind('image/x-adobe-dng', null))->toBe(StackFileThumbnail::KIND_DNG)
        // A 3D file the viewer renders has no server derivative; it shows whatever a browser captured of it.
        ->and(StackFileThumbnail::kind('model/stl', 'part.stl'))->toBe(StackFileThumbnail::KIND_MODEL)
        ->and(StackFileThumbnail::kind('model/gltf-binary', null))->toBe(StackFileThumbnail::KIND_MODEL)
        ->and(StackFileThumbnail::kind('application/octet-stream', 'Part.GLB'))->toBe(StackFileThumbnail::KIND_MODEL)
        ->and(StackFileThumbnail::kind('application/vnd.ms-package.3dmanufacturing-3dmodel+xml', 'plate.3mf'))->toBe(StackFileThumbnail::KIND_MODEL)
        // No client renderer, so no capture: OBJ, FBX and glTF keep their type tile; the stored type beats a label suffix.
        ->and(StackFileThumbnail::kind('application/octet-stream', 'part.obj'))->toBeNull()
        ->and(StackFileThumbnail::kind('model/gltf+json', 'scene.gltf'))->toBeNull()
        ->and(StackFileThumbnail::kind('text/plain', 'part.stl'))->toBeNull()
        ->and(StackFileThumbnail::modelExtension('model/stl', null))->toBe('stl')
        ->and(StackFileThumbnail::modelExtension('image/png', 'part.glb'))->toBeNull()
        ->and(StackFileThumbnail::kind('image/svg+xml', 'logo.svg'))->toBeNull()
        ->and(StackFileThumbnail::kind('text/plain', 'notes.txt'))->toBeNull()
        // A HEIC/HEIF is decoded by libvips for both its small picture and its screen-sized preview.
        ->and(StackFileThumbnail::kind('image/heic', 'IMG_1.heic'))->toBe(StackFileThumbnail::KIND_HEIF)
        ->and(StackFileThumbnail::kind('image/heif; charset=binary', null))->toBe(StackFileThumbnail::KIND_HEIF)
        // Any other still gets the card's resize attempt (TIFF, PSD), but is not offered as a screen-sized preview.
        ->and(StackFileThumbnail::kind('image/tiff', 'scan.tif'))->toBe(StackFileThumbnail::KIND_IMAGE)
        ->and(StackFileThumbnail::kind('image/vnd.adobe.photoshop', 'cover.psd'))->toBe(StackFileThumbnail::KIND_IMAGE);
});

// The poster a job keeps beside a blob (`derivatives/<sha>/poster.jpg`) is what the Library card shows for a video,
// PDF, panorama, Blender or Unity file; a stacked copy of that file shows the same picture.
$withType = function (array $file, string $mime): string {
    $sha = FileObject::query()->whereKey(AssetFile::query()->whereKey($file['id'])->value('file_object_id'))->first();
    $sha->update(['mime_type' => $mime]);

    return $sha->sha256;
};

it('serves the poster of a stacked video, PDF or Blender file, and offers a thumbnail only once the poster exists', function (string $name, string $mime) use ($attach, $withType, $thumb, $body) {
    $file = $attach($this, $name, 'bytes of '.$name, $name);
    $sha = $withType($file, $mime);
    $payload = fn () => collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id')[$file['id']];

    // No poster yet: the tile keeps its type badge, and the thumbnail answers 404 rather than guessing.
    expect($payload()['thumbnail_url'])->toBeNull();
    $thumb($this, $file)->assertNotFound();

    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($sha), 'poster-of-'.$name);

    expect($payload()['thumbnail_url'])->toStartWith(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]).'&v=');
    expect($body($thumb($this, $file)->assertOk()))->toBe('poster-of-'.$name);
})->with([
    'video'   => ['clip.mp4', 'video/mp4'],
    'PDF'     => ['brochure.pdf', 'application/pdf'],
    'Blender' => ['scene.blend', 'application/octet-stream'],
]);

it('shows the cover tile the stack card\'s own picture, a custom thumbnail included', function () use ($attach, $png) {
    $attach($this, 'shot.png', $png(64, 48), 'shot.png');
    $cover = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $custom = ['path' => 'custom-thumbnails/'.$this->asset->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
    Storage::disk(Directory::getAssetDisk())->put($custom['path'], 'custom-card-art');
    $this->asset->update(['meta_data' => ['custom_thumbnail' => $custom]]);

    $payload = collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->firstWhere('id', $cover->id);

    expect($payload['thumbnail_url'])->not->toBeNull()
        ->and($this->get($payload['thumbnail_url'])->assertOk()->baseResponse->getFile()->getContent())->toBe('custom-card-art');
});

it('offers a thumbnail for a stacked TIFF or PSD only once a real raster derivative exists, and never a screen-sized preview', function (string $mime, string $name) use ($attach, $withType, $thumb) {
    $file = $attach($this, $name, 'bytes of '.$name, $name);
    $sha = $withType($file, $mime);
    $payload = fn () => collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id')[$file['id']];

    // The card's resize failed or has not run: no URL, so the tile keeps its type badge instead of a broken image.
    expect($payload()['thumbnail_url'])->toBeNull();
    $thumb($this, $file)->assertNotFound();

    $image = imagecreatetruecolor(32, 24);
    ob_start();
    imagewebp($image);
    $webp = ob_get_clean();
    imagedestroy($image);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::thumbnail($sha, 'webp'), $webp);

    $answer = $thumb($this, $file)->assertOk();
    $decoded = getimagesizefromstring(file_get_contents($answer->baseResponse->getFile()->getPathname()));

    expect($payload()['thumbnail_url'])->toStartWith(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]).'&v=')
        ->and($payload()['preview_url'])->toBeNull()
        ->and($decoded[0])->toBe(32)
        ->and($decoded['mime'])->toBe('image/webp');
})->with([
    'TIFF' => ['image/tiff', 'scan.tif'],
    'PSD'  => ['image/vnd.adobe.photoshop', 'cover.psd'],
]);

it('stops advertising a poster once its file is gone, even though the disk once said it existed', function () use ($attach, $withType, $thumb) {
    $file = $attach($this, 'clip.mp4', 'bytes of clip', 'clip.mp4');
    $sha = $withType($file, 'video/mp4');
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put(DerivativeImages::poster($sha), 'poster');
    $payload = fn () => collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id')[$file['id']]['thumbnail_url'];

    // Remembered as existing, then removed behind the cache's back.
    expect($payload())->not->toBeNull();
    $disk->delete(DerivativeImages::poster($sha));
    expect($payload())->not->toBeNull();

    // The failed serve is what tells the cache.
    $thumb($this, $file)->assertNotFound();

    expect($payload())->toBeNull();
});

it('keeps answering when the cache that remembers existing pictures is down', function () use ($attach, $withType) {
    $file = $attach($this, 'clip.mp4', 'bytes of clip', 'clip.mp4');
    $sha = $withType($file, 'video/mp4');
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($sha), 'poster');
    $asset = $this->asset->fresh();
    $row = AssetFile::query()->with(['attachment', 'fileObject'])->findOrFail($file['id']);

    Cache::partialMock()->shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    expect(StackFileThumbnail::source($row, $asset)['path'])->toBe(DerivativeImages::poster($sha));
});

it('names a thumbnail only for the files that have one', function () use ($attach, $png) {
    $picture = $attach($this, 'shot.png', $png(640, 480), 'shot.png');
    $notes = $attach($this, 'notes.txt', 'plain notes', 'notes.txt');

    $files = collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id');

    expect($files[$picture['id']]['thumbnail_url'])->toStartWith(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $picture['id'], 'thumb' => 1]).'&v=')
        ->and($files[$notes['id']]['thumbnail_url'])->toBeNull();
});

it('serves a raster file as a small cached copy, not the original', function () use ($attach, $png, $thumb, $body) {
    $original = $png(1600, 1200);
    $file = $attach($this, 'big.png', $original, 'big.png');

    $bytes = $body($thumb($this, $file)->assertOk());
    [$width, $height] = getimagesizefromstring($bytes);

    expect(max($width, $height))->toBeLessThanOrEqual(300)
        ->and(strlen($bytes))->toBeLessThan(strlen($original));
});

it('answers 404 for a thumbnail of a type with no derivative, and still serves its bytes without ?thumb', function () use ($attach, $thumb) {
    $file = $attach($this, 'notes.txt', 'plain notes', 'notes.txt');

    $thumb($this, $file)->assertNotFound();
    $thumb($this, $file, [])->assertOk();
});

it('lets ?download=1 win over ?thumb=1, so a download is always the original file', function () use ($attach, $png, $thumb, $body) {
    $original = $png(800, 600);
    $file = $attach($this, 'keep.png', $original, 'keep.png');

    expect($body($thumb($this, $file, ['thumb' => 1, 'download' => 1])->assertOk()))->toBe($original);
});

// A minimal TIFF head: byte order, 42, IFD0 at 8, then the given entries. An entry is a tag alone (one SHORT) or
// [tag, type, count, 4 value bytes]; DNGVersion as cameras write it is 4 BYTEs, 1.4.0.0.
$tiffHead = function (array $entries, bool $bigEndian = false): string {
    $p = $bigEndian ? ['n', 'N'] : ['v', 'V'];
    $head = ($bigEndian ? 'MM' : 'II').pack($p[0], 42).pack($p[1], 8).pack($p[0], count($entries));
    foreach ($entries as $entry) {
        [$tag, $type, $count, $value] = is_array($entry) ? $entry : [$entry, 3, 1, pack($p[0], 0)."\0\0"];
        $head .= pack($p[0], $tag).pack($p[0], $type).pack($p[1], $count).str_pad($value, 4, "\0");
    }

    return $head.pack($p[1], 0);
};
$dngVersion = [0xC612, 1, 4, "\x01\x04\x00\x00"];

it('recognises a DNG stored as image/tiff by a well-formed DNGVersion entry (PROD-13)', function () use ($tiffHead, $dngVersion) {
    expect(StackFileName::tiffHeadIsDng($tiffHead([0x0100, 0x0101, $dngVersion])))->toBeTrue()
        ->and(StackFileName::tiffHeadIsDng($tiffHead([0x0100, $dngVersion], bigEndian: true)))->toBeTrue()
        ->and(StackFileName::tiffHeadIsDng($tiffHead([0x0100, 0x0101])))->toBeFalse()
        ->and(StackFileName::tiffHeadIsDng('not a tiff'))->toBeFalse();
});

it('refuses a truncated or malformed DNGVersion entry', function () use ($tiffHead, $dngVersion) {
    $valid = $tiffHead([0x0100, $dngVersion]);

    // The header, the count and the tag bytes, but not the rest of the entry.
    expect(StackFileName::tiffHeadIsDng(substr($valid, 0, 8 + 2 + 12 + 2)))->toBeFalse()
        // The tag stored as one SHORT, or as BYTEs of the wrong count, or with major version 0.
        ->and(StackFileName::tiffHeadIsDng($tiffHead([0x0100, 0xC612])))->toBeFalse()
        ->and(StackFileName::tiffHeadIsDng($tiffHead([[0xC612, 1, 65535, "\x01\x04\x00\x00"]])))->toBeFalse()
        ->and(StackFileName::tiffHeadIsDng($tiffHead([[0xC612, 1, 4, "\x00\x04\x00\x00"]])))->toBeFalse()
        // An IFD offset inside the header, past the bytes read, or an absurd entry count.
        ->and(StackFileName::tiffHeadIsDng('II'.pack('v', 42).pack('V', 4).substr($valid, 8)))->toBeFalse()
        ->and(StackFileName::tiffHeadIsDng('II'.pack('v', 42).pack('V', 9000).substr($valid, 8)))->toBeFalse()
        ->and(StackFileName::tiffHeadIsDng('II'.pack('v', 42).pack('V', 8).pack('v', 60000).substr($valid, 10)))->toBeFalse();
});

it('names an extensionless label with its type for the files payload and the download (SETUP-08)', function () use ($attach, $png) {
    $file = $attach($this, 'shot.png', $png(64, 48), 'Hero shot');

    $payload = collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id')[$file['id']];

    expect($payload['file_name'])->toBe('Hero shot.png')
        ->and($payload['extension'])->toBe('png');

    $download = $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'download' => 1]))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toContain('Hero shot.png');
});

// A minimal one-page PDF: what an Illustrator file saved with PDF compatibility looks like to the sniffer.
$pdfPage = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 120 80]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n";

it('keeps a stacked Illustrator file an .ai beside a genuine PDF, with its application and its picture', function () use ($attach, $thumb, $withType, $pdfPage) {
    $illustrator = $attach($this, 'Banner Logo.ai', $pdfPage, 'Banner Logo.ai');
    $pdf = $attach($this, 'Brochure.pdf', $pdfPage.' ', 'Brochure.pdf');
    // What the live Library stores for a PDF-compatible .ai: the sniffed type, application/pdf.
    $withType($illustrator, 'application/pdf');
    $withType($pdf, 'application/pdf');
    $files = collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id');

    // The stored type stays PDF (it is what lets it preview and what technical info shows); the file stays an .ai.
    expect($files[$illustrator['id']]['mime_type'])->toBe('application/pdf')
        ->and($files[$illustrator['id']]['extension'])->toBe('ai')
        ->and($files[$illustrator['id']]['file_name'])->toBe('Banner Logo.ai')
        ->and($files[$illustrator['id']]['adobe'])->toMatchArray(['key' => 'illustrator', 'app' => 'Adobe Illustrator', 'pdf' => true])
        // The genuine PDF beside it is still a PDF: no Adobe identity, extension pdf.
        ->and($files[$pdf['id']]['extension'])->toBe('pdf')
        ->and($files[$pdf['id']]['adobe'])->toBeNull()
        // The Illustrator file is drawn by the PDF renderer, so its tile asks for a picture.
        ->and($files[$illustrator['id']]['thumbnail_url'])->not->toBeNull();

    $download = $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $illustrator['id'], 'download' => 1]))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toContain('Banner Logo.ai');

    // pdftoppm is part of the app's own PDF thumbnail path; without it the tile keeps the application mark.
    if (trim((string) shell_exec('command -v pdftoppm')) !== '') {
        $response = $thumb($this, $illustrator)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('image/jpeg');
    }
});

it('decides a stacked file is Illustrator only when its type is PDF and its name is .ai', function () {
    expect(StackFileThumbnail::kind('application/pdf', 'Logo.ai'))->toBe(StackFileThumbnail::KIND_PDF)
        ->and(StackFileThumbnail::kind('application/pdf', 'Logo.pdf'))->toBeNull()
        ->and(StackFileThumbnail::kind('application/pdf', 'Logo.indd'))->toBeNull()
        ->and(StackFileThumbnail::kind('application/postscript', 'Logo.ai'))->toBeNull();
});

it('views a stacked picture through a size-capped copy, never the original (PROD-03)', function () use ($attach, $png, $body) {
    $original = $png(2600, 1400);
    $picture = $attach($this, 'wide.png', $original, 'wide.png');
    $notes = $attach($this, 'notes.txt', 'plain notes', 'notes.txt');

    $files = collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id');

    expect($files[$picture['id']]['preview_url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $picture['id'], 'preview' => 1]))
        ->and($files[$notes['id']]['preview_url'])->toBeNull();

    [$width] = getimagesizefromstring($body($this->get($files[$picture['id']]['preview_url'])->assertOk()));

    expect($width)->toBeLessThanOrEqual(1920);
});

it('answers 404 for a preview copy it cannot make, never the original (PROD-03)', function () use ($attach, $png) {
    // A PNG signature, then bytes no decoder can read: stored as image/png, so the preview offers a copy.
    $broken = substr($png(64, 48), 0, 24).str_repeat('x', 4000);
    $file = $attach($this, 'broken.png', $broken, 'broken.png');

    $response = $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'preview' => 1]));

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($broken);
});

// History fixtures (spec S5 "Rights"): a blob row on the test asset, a preview proxy made from a given row, a role
// with dam.asset.view (plus extras) on the test folder, and reads by id and by blob locator.
$blobRow = function (object $test, string $content, array $row): AssetFile {
    $sha = hash('sha256', $content);
    $object = FileObject::firstOrCreate(['sha256' => $sha], ['path' => 'assets/.objects/'.substr($sha, 0, 2).'/'.$sha, 'disk' => Directory::getAssetDisk(), 'size' => strlen($content), 'mime_type' => 'image/png']);
    Storage::disk(Directory::getAssetDisk())->put($object->path, $content);

    return AssetFile::create(['dam_asset_id' => $test->asset->id, 'file_object_id' => $object->id] + $row);
};
$proxyOf = function (object $test, ?AssetFile $source, string $content) use ($blobRow): AssetFile {
    $proxy = $blobRow($test, $content, ['role' => 'proxy', 'is_current' => true]);

    if ($source !== null) {
        Rendition::factory()->create(['dam_asset_id' => $test->asset->id, 'source_asset_file_id' => $source->id, 'recipe' => Rendition::RECIPE_PREVIEW_1080P, 'status' => Rendition::STATUS_DONE, 'output_file_object_id' => $proxy->file_object_id]);
    }

    return $proxy;
};
$actAs = function (object $test, array $permissions): void {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', ...$permissions]]);
    DB::table('dam_directory_role')->insert(['directory_id' => $test->folder->id, 'role_id' => $role->id]);
    $test->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
};
$byId = fn (object $test, AssetFile|int $file, array $query = []) => $test->get(route('admin.dam.file.asset-file', ['asset' => $test->asset->id, 'assetFile' => is_int($file) ? $file : $file->id] + $query));
$byLocator = fn (object $test, AssetFile $row) => $test->get('/'.config('app.admin_url').'/dam/file/fetch/'.$row->fileObject->path);

it('keeps current stack files on dam.asset.view and puts history behind dam.asset.files.index', function () use ($attach, $png, $body, $blobRow, $proxyOf, $actAs, $byId, $byLocator) {
    $bytes = $png(64, 48);
    $other = $attach($this, 'other.png', $bytes, 'Other');
    // The cover, a superseded revision of it, and a proxy made from each: current files and their copies stay
    // readable (spec S4), a revision and a proxy of one are history (spec S5).
    $cover = $blobRow($this, $png(40, 30), ['role' => 'original', 'is_current' => true]);
    $revision = $blobRow($this, $png(41, 30), ['role' => 'version', 'is_current' => false]);
    $coverProxy = $proxyOf($this, $cover, $png(42, 30));
    $oldProxy = $proxyOf($this, $revision, $png(43, 30));

    $actAs($this, []);
    foreach ([[], ['download' => 1], ['preview' => 1], ['thumb' => 1]] as $query) {
        $byId($this, $other['id'], $query)->assertOk();
    }
    expect($body($byId($this, $other['id'], ['download' => 1])))->toBe($bytes);
    $byId($this, $cover)->assertOk();
    $byId($this, $coverProxy)->assertOk();
    $byLocator($this, $coverProxy)->assertOk();
    $byId($this, $revision, ['download' => 1])->assertForbidden();
    $byId($this, $oldProxy)->assertForbidden();
    $byLocator($this, $revision)->assertForbidden();
    $byLocator($this, $oldProxy)->assertForbidden();

    $actAs($this, ['dam.asset.files', 'dam.asset.files.index']);
    $byId($this, $revision, ['download' => 1])->assertOk();
    $byId($this, $oldProxy)->assertOk();
    $byLocator($this, $oldProxy)->assertOk();
});

it('reads as current what is still current content: a restored source, a blob a current row shares, an unexplained proxy', function () use ($png, $blobRow, $proxyOf, $actAs, $byId, $byLocator) {
    $current = $png(50, 30);
    $cover = $blobRow($this, $current, ['role' => 'original', 'is_current' => true]);
    // A revision whose content was restored: the cover holds the same blob again, so a proxy made from it is current.
    $restored = $blobRow($this, $current, ['role' => 'version', 'is_current' => false]);
    $restoredProxy = $proxyOf($this, $restored, $png(51, 30));
    // A revision whose blob another current file of the entry holds (a shared blob): its proxy is current too.
    $sharedBytes = $png(52, 30);
    $blobRow($this, $sharedBytes, ['role' => 'alternate', 'is_current' => true]);
    $sharedProxy = $proxyOf($this, $blobRow($this, $sharedBytes, ['role' => 'version', 'is_current' => false]), $png(53, 30));
    // One blob held by a current row and by a superseded one: the locator is readable through the current row.
    $both = $png(54, 30);
    $currentHolder = $blobRow($this, $both, ['role' => 'other', 'is_current' => true]);
    $historyHolder = $blobRow($this, $both, ['role' => 'version', 'is_current' => false]);
    // A proxy no rendition explains (an upload, an older import) is not provably history.
    $looseProxy = $proxyOf($this, null, $png(55, 30));
    // Still denied: a blob only a superseded revision holds.
    $gone = $blobRow($this, $png(56, 30), ['role' => 'version', 'is_current' => false]);

    $actAs($this, []);
    foreach ([$restoredProxy, $sharedProxy, $currentHolder, $looseProxy, $cover] as $row) {
        $byId($this, $row)->assertOk();
        $byLocator($this, $row)->assertOk();
    }
    $byLocator($this, $historyHolder)->assertOk();
    $byId($this, $historyHolder, ['download' => 1])->assertForbidden();
    $byId($this, $gone, ['download' => 1])->assertForbidden();
    $byLocator($this, $gone)->assertForbidden();
});

it('builds a zip from current files only: a proxy of a superseded revision is left out without the history key', function () use ($attach, $png, $blobRow, $proxyOf, $actAs) {
    $other = $attach($this, 'other.txt', 'other notes', 'other.txt');
    // The cover is named after the entry in a zip.
    $cover = $blobRow($this, $png(60, 30), ['role' => 'original', 'is_current' => true]);
    $oldProxy = $proxyOf($this, $blobRow($this, $png(61, 30), ['role' => 'version', 'is_current' => false]), $png(62, 30));
    $zip = fn (array $ids = []) => $this->get('/'.config('app.admin_url').'/dam/file/asset/'.$this->asset->id.'/files-zip'.($ids ? '?'.implode('&', array_map(fn ($id) => 'files[]='.$id, $ids)) : ''));
    $names = function ($response): array {
        $archive = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'dam_test_zip_');
        file_put_contents($path, $response->streamedContent());
        expect($archive->open($path))->toBeTrue();
        $out = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $out[] = $archive->getNameIndex($i);
        }
        $archive->close();
        unlink($path);
        sort($out);

        return $out;
    };

    $actAs($this, ['dam.asset.download_compressed']);
    expect($names($zip()->assertOk()))->toBe(['menu.png', 'other.txt']);
    // A named file that is not current content answers like a missing one, as any other unknown id does.
    $zip([$oldProxy->id])->assertNotFound();
    $zip([$other['id'], $oldProxy->id])->assertNotFound();
    expect($names($zip([$other['id'], $cover->id])->assertOk()))->toBe(['menu.png', 'other.txt']);

    $actAs($this, ['dam.asset.download_compressed', 'dam.asset.files', 'dam.asset.files.index']);
    $all = $names($zip()->assertOk());
    expect($all)->toHaveCount(3)->toContain('menu.png', 'other.txt');
    $zip([$oldProxy->id])->assertOk();
});

it('remembers whether a TIFF is a DNG only after reading it, so a failed read is tried again', function () use ($tiffHead, $dngVersion) {
    $head = $tiffHead([0x0100, $dngVersion]);
    $sha = hash('sha256', $head);
    $object = FileObject::create(['sha256' => $sha, 'path' => 'assets/.objects/'.substr($sha, 0, 2).'/'.$sha, 'disk' => Directory::getAssetDisk(), 'size' => strlen($head), 'mime_type' => 'image/tiff']);

    // The blob is not in the store yet: the read fails, and the answer is not remembered.
    expect(StackFileName::isDng($object))->toBeFalse();
    Storage::disk(Directory::getAssetDisk())->put($object->path, $head);
    expect(StackFileName::isDng($object))->toBeTrue();
});

it('reads a DNG again that the earlier sniff cache wrongly remembered as not a DNG', function () use ($tiffHead, $dngVersion) {
    $head = $tiffHead([0x0100, $dngVersion]);
    $sha = hash('sha256', $head);
    $object = FileObject::create(['sha256' => $sha, 'path' => 'assets/.objects/'.substr($sha, 0, 2).'/'.$sha, 'disk' => Directory::getAssetDisk(), 'size' => strlen($head), 'mime_type' => 'image/tiff']);
    Storage::disk(Directory::getAssetDisk())->put($object->path, $head);
    // The v2 cache stored false after a failed read; that entry survives a deploy and must not decide.
    Cache::forever('dam-dng-sniff-v2-'.$sha, false);

    expect(StackFileName::isDng($object))->toBeTrue();
});
