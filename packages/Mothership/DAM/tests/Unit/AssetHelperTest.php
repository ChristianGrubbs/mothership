<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;

it('should detect image file type from mime type', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    expect(AssetHelper::getFileType($file))->toBe('image');
});

it('should detect video file type from mime type', function () {
    $file = UploadedFile::fake()->create('test.mp4', 100, 'video/mp4');
    expect(AssetHelper::getFileType($file))->toBe('video');
});

it('should detect audio file type from mime type', function () {
    $file = UploadedFile::fake()->create('test.mp3', 100, 'audio/mpeg');
    expect(AssetHelper::getFileType($file))->toBe('audio');
});

it('should detect document file type from mime type', function () {
    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    expect(AssetHelper::getFileType($file))->toBe('document');
});

it('should detect image type using extension', function () {
    expect(AssetHelper::getFileTypeUsingExtension('jpg'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('jpeg'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('png'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('gif'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('svg'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('bmp'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('webp'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('tiff'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('tif'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('jfif'))->toBe('image');
});

it('should detect video type using extension', function () {
    expect(AssetHelper::getFileTypeUsingExtension('mp4'))->toBe('video');
    expect(AssetHelper::getFileTypeUsingExtension('mkv'))->toBe('video');
    expect(AssetHelper::getFileTypeUsingExtension('avi'))->toBe('video');
    expect(AssetHelper::getFileTypeUsingExtension('mov'))->toBe('video');
    expect(AssetHelper::getFileTypeUsingExtension('flv'))->toBe('video');
});

it('should detect audio type using extension', function () {
    expect(AssetHelper::getFileTypeUsingExtension('mp3'))->toBe('audio');
    expect(AssetHelper::getFileTypeUsingExtension('wav'))->toBe('audio');
    expect(AssetHelper::getFileTypeUsingExtension('aac'))->toBe('audio');
    expect(AssetHelper::getFileTypeUsingExtension('flac'))->toBe('audio');
});

it('should detect sheet type using extension', function () {
    expect(AssetHelper::getFileTypeUsingExtension('xls'))->toBe('sheet');
    expect(AssetHelper::getFileTypeUsingExtension('xlsx'))->toBe('sheet');
    expect(AssetHelper::getFileTypeUsingExtension('csv'))->toBe('sheet');
    expect(AssetHelper::getFileTypeUsingExtension('ods'))->toBe('sheet');
});

it('should detect file type using extension for documents', function () {
    expect(AssetHelper::getFileTypeUsingExtension('pdf'))->toBe('file');
    expect(AssetHelper::getFileTypeUsingExtension('doc'))->toBe('file');
    expect(AssetHelper::getFileTypeUsingExtension('docx'))->toBe('file');
    expect(AssetHelper::getFileTypeUsingExtension('txt'))->toBe('file');
    expect(AssetHelper::getFileTypeUsingExtension('rtf'))->toBe('file');
    expect(AssetHelper::getFileTypeUsingExtension('odt'))->toBe('file');
});

it('should type the 3D model extensions as model so they get the model placeholder', function () {
    foreach (['stl', 'glb', '3mf', 'STL'] as $extension) {
        expect(AssetHelper::getFileTypeUsingExtension($extension))->toBe('model');
    }
});

it('should keep an uploaded 3D model on the stored document type', function () {
    // dam_assets.file_type is an enum of image/video/document/audio; model is an icon bucket only.
    $file = UploadedFile::fake()->create('bracket.stl', 100, 'model/stl');
    expect(AssetHelper::getFileType($file))->toBe('document');
});

it('should return unspecified for unknown extensions', function () {
    expect(AssetHelper::getFileTypeUsingExtension('xyz'))->toBe('unspecified');
    expect(AssetHelper::getFileTypeUsingExtension('bin'))->toBe('unspecified');
    expect(AssetHelper::getFileTypeUsingExtension('dat'))->toBe('unspecified');
});

it('should handle case insensitive extension detection', function () {
    expect(AssetHelper::getFileTypeUsingExtension('JPG'))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('MP4'))->toBe('video');
    expect(AssetHelper::getFileTypeUsingExtension('PDF'))->toBe('file');
});

it('should truncate long file names with ellipsis', function () {
    $longName = 'this-is-a-very-long-file-name-that-exceeds-limit.png';
    $result = AssetHelper::getDisplayFileName($longName);

    expect(strlen($result))->toBeLessThanOrEqual(30);
    expect($result)->toContain('...');
    expect($result)->toEndWith('.png');
});

it('should not truncate short file names', function () {
    $shortName = 'short.png';
    expect(AssetHelper::getDisplayFileName($shortName))->toBe('short.png');
});

it('should not truncate file names at exactly 29 characters', function () {
    $exactName = str_repeat('a', 25).'.png';
    expect(AssetHelper::getDisplayFileName($exactName))->toBe($exactName);
});

it('should block OS metadata files by filename', function () {
    expect(AssetHelper::isForbiddenFile(null, null, '.DS_Store'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, null, '._.DS_Store'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, null, 'Thumbs.db'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, null, 'desktop.ini'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, null, 'photo.png'))->toBeFalse();
});

it('should identify forbidden file extensions', function () {
    expect(AssetHelper::isForbiddenFile('php', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('js', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('py', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('sh', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('bat', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('exe', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('pl', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('cgi', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('asp', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('aspx', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('jsp', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('rb', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('jar', null))->toBeTrue();
});

it('should identify forbidden mime types', function () {
    expect(AssetHelper::isForbiddenFile(null, 'application/x-php'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'application/x-javascript'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'text/javascript'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'application/javascript'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'text/x-python'))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'application/x-msdownload'))->toBeTrue();
});

it('should allow safe file extensions', function () {
    expect(AssetHelper::isForbiddenFile('jpg', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('png', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('pdf', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('mp4', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('doc', null))->toBeFalse();
});

it('should allow safe mime types', function () {
    expect(AssetHelper::isForbiddenFile(null, 'image/jpeg'))->toBeFalse();
    expect(AssetHelper::isForbiddenFile(null, 'application/pdf'))->toBeFalse();
    expect(AssetHelper::isForbiddenFile(null, 'video/mp4'))->toBeFalse();
});

it('should handle case insensitive extension check for forbidden files', function () {
    expect(AssetHelper::isForbiddenFile('PHP', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('Js', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile('EXE', null))->toBeTrue();
});

it('should return false when both extension and mime are null', function () {
    expect(AssetHelper::isForbiddenFile(null, null))->toBeFalse();
});

/**
 * An asset (Library path only, no on-disk mirror — the read seam never needs one, ADR 0009) whose
 * current original is a FileObject on `$disk`, so ContentStore::current()/holdsBlob() resolve it the
 * way AssetHelper::getPreviewUrl()'s S3 shortcut does.
 */
function assetWithRemoteOriginal(string $path, string $extension, string $mimeType, string $bytes, string $disk): Asset
{
    $sha256 = hash('sha256', $bytes);
    $objectPath = app(ContentStore::class)->objectPath($sha256, $extension);

    $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => basename($path), 'extension' => $extension, 'mime_type' => $mimeType, 'path' => $path,
    ]));

    $object = FileObject::factory()->create([
        'sha256' => $sha256, 'disk' => $disk, 'path' => $objectPath, 'size' => strlen($bytes), 'mime_type' => $mimeType,
    ]);

    AssetFile::factory()->create([
        'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL,
    ]);

    return $asset;
}

it('should return a signed s3 url of the asset\'s current original through the read seam', function (string $extension, string $mimeType) {
    config(['filesystems.default' => 's3']);

    $asset = assetWithRemoteOriginal('assets/Root/test.'.$extension, $extension, $mimeType, 'blob-bytes', Directory::ASSETS_DISK_AWS);
    $objectPath = app(ContentStore::class)->current($asset)->path;
    $expectedUrl = "https://s3.example.com/{$objectPath}?signature=test";

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->with($objectPath)->andReturn(true);
    $disk->shouldReceive('size')->with($objectPath)->andReturn(strlen('blob-bytes'));
    $disk->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $disk->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->with($objectPath, Mockery::type(Carbon::class), ['ResponseContentType' => $mimeType])
        ->andReturn($expectedUrl);

    Storage::shouldReceive('disk')
        ->with(Directory::ASSETS_DISK_AWS)
        ->andReturn($disk);

    // The direct URL always names the FileObject's own content-addressed locator, never the Library
    // path handed in — the two differ here on purpose so the assertion catches a regression to the
    // old, path-based behaviour (E1).
    expect($asset->path)->not->toBe($objectPath);
    expect(AssetHelper::getPreviewUrl($asset->path, 1356))
        ->toBe($expectedUrl);
})->with([
    'image' => ['png', 'image/png'],
    'video' => ['mp4', 'video/mp4'],
]);

it('never hands out a direct s3 url for inline content that needs the app CSP', function (string $extension, string $mimeType) {
    config(['filesystems.default' => 's3']);

    $asset = assetWithRemoteOriginal('assets/Root/active.'.$extension, $extension, $mimeType, 'blob-bytes', Directory::ASSETS_DISK_AWS);
    $objectPath = app(ContentStore::class)->current($asset)->path;

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->with($objectPath)->andReturn(true);
    $disk->shouldReceive('size')->with($objectPath)->andReturn(strlen('blob-bytes'));
    $disk->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $disk->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $disk->shouldNotReceive('temporaryUrl');
    $disk->shouldNotReceive('url');

    Storage::shouldReceive('disk')->with(Directory::ASSETS_DISK_AWS)->andReturn($disk);

    expect(AssetHelper::getPreviewUrl($asset->path, 1356))
        ->toContain(route('admin.dam.file.preview', [], false))
        ->toContain('path='.urlencode(urlencode($asset->path)));
})->with([
    'svg' => ['svg', 'image/svg+xml'],
    'pdf' => ['pdf', 'application/pdf'],
]);

it('should keep using the preview route on local storage', function () {
    config(['filesystems.default' => 'local']);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    $path = 'assets/Root/'.$file->getClientOriginalName();
    $assetId = 1356;
    $encodedPath = urlencode(urlencode($path));

    $previewUrl = AssetHelper::getPreviewUrl($path, $assetId);

    expect($previewUrl)
        ->toContain(route('admin.dam.file.preview', [], false))
        ->toContain("path={$encodedPath}");
});

it('should keep using the preview route on s3 when no asset owns the Library path', function () {
    config(['filesystems.default' => 's3']);

    $path = 'assets/Root/orphaned.pdf';
    $assetId = 1356;
    $encodedPath = urlencode(urlencode($path));

    $previewUrl = AssetHelper::getPreviewUrl($path, $assetId);

    expect($previewUrl)
        ->toContain(route('admin.dam.file.preview', [], false))
        ->toContain("path={$encodedPath}");
});

it('should keep using the preview route on s3 when the asset\'s blob is missing', function () {
    config(['filesystems.default' => 's3']);

    $asset = assetWithRemoteOriginal('assets/Root/gone.pdf', 'pdf', 'application/pdf', 'blob-bytes', Directory::ASSETS_DISK_AWS);
    $objectPath = app(ContentStore::class)->current($asset)->path;

    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->with($objectPath)->andReturn(false);

    Storage::shouldReceive('disk')
        ->with(Directory::ASSETS_DISK_AWS)
        ->andReturn($disk);

    $encodedPath = urlencode(urlencode($asset->path));
    $previewUrl = AssetHelper::getPreviewUrl($asset->path, 1356);

    expect($previewUrl)
        ->toContain(route('admin.dam.file.preview', [], false))
        ->toContain("path={$encodedPath}");
});

it('lets a confidently identified media family win over an incompatible extension, in the classifier and the accessor', function (string $mime, string $extension, string $expected) {
    $asset = new Asset(['mime_type' => $mime, 'extension' => $extension]);
    $asset->setRawAttributes(['mime_type' => $mime, 'extension' => $extension, 'file_type' => $expected]);

    expect(AssetHelper::classifyFile($mime, $extension))->toBe($expected)
        ->and($asset->file_type)->toBe($expected);
})->with([
    'wav named .dng'            => ['audio/x-wav', 'dng', 'audio'],
    'video named .dng'          => ['video/mp4', 'dng', 'video'],
    'pdf named .dng'            => ['application/pdf', 'dng', 'document'],
    'mp4 named .html'           => ['video/mp4', 'html', 'video'],
    'pdf named .mp4'            => ['application/pdf', 'mp4', 'document'],
    'real tiff dng'             => ['image/tiff', 'dng', 'image'],
    'dng with unknown mime'     => ['application/octet-stream', 'dng', 'image'],
    'dng with no mime'          => ['', 'dng', 'image'],
    'html is still a web page'  => ['text/html', 'html', 'web'],
    'octet-stream mp4 by name'  => ['application/octet-stream', 'mp4', 'video'],
]);

it('shortens a long multibyte file name on a character boundary, keeping the extension', function () {
    $name = str_repeat('é', 40).'.jpg';

    $display = AssetHelper::getDisplayFileName($name);

    expect(mb_check_encoding($display, 'UTF-8'))->toBeTrue()
        ->and($display)->toBe(str_repeat('é', 20).'...'.'.jpg')
        ->and(json_encode($display))->not->toBeFalse();
});

it('leaves a short file name and a long one without an extension alone or valid', function () {
    expect(AssetHelper::getDisplayFileName('short.jpg'))->toBe('short.jpg');

    $display = AssetHelper::getDisplayFileName(str_repeat('日', 40));

    expect(mb_check_encoding($display, 'UTF-8'))->toBeTrue();
});
