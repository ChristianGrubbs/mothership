<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Mockery\MockInterface;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A private-disk asset whose bytes are its ingested original; returns the original's sha256. */
function servedPrivateAsset(string $path, string $bytes): string
{
    Storage::disk(Directory::ASSETS_DISK_PRIVATE)->put($path, $bytes);

    return app(ContentStore::class)->current(registerServedAssetPath($path))->sha256;
}

/**
 * An asset whose original blob lives on a mocked S3 disk with a cached thumbnail; the blob answers the
 * cheap stat a read makes (exists + size), the thumbnail its visibility.
 */
function mockedS3ThumbnailDisk(string $path): MockInterface
{
    $bytes = 'remote-photo';
    $sha = hash('sha256', $bytes);
    $object = FileObject::factory()->create(['sha256' => $sha, 'size' => strlen($bytes), 'disk' => Directory::ASSETS_DISK_AWS, 'mime_type' => 'image/jpeg']);
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => basename($path), 'extension' => 'jpg', 'path' => $path]));
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL]);

    $thumbnail = DerivativeImages::thumbnail($sha, 'jpg');
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->with($object->path)->andReturn(true);
    $disk->shouldReceive('size')->with($object->path)->andReturn(strlen($bytes));
    $disk->shouldReceive('exists')->with($thumbnail)->andReturn(true);

    Storage::shouldReceive('disk')->with(Directory::ASSETS_DISK_AWS)->andReturn($disk);

    return $disk;
}

it('serves a cached sha-keyed thumbnail as a BinaryFileResponse on a local disk', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/photo.jpg';
    $upload = UploadedFile::fake()->image('photo.jpg', 50, 50);
    $fakeImage = file_get_contents($upload->getRealPath());
    $sha = servedPrivateAsset($path, $fakeImage);
    Storage::disk(Directory::ASSETS_DISK_PRIVATE)->put(DerivativeImages::thumbnail($sha, 'jpg'), $fakeImage);

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->getFile()->getPathname())->toEndWith(DerivativeImages::thumbnail($sha, 'jpg'));
});

it('serves a cached sha-keyed preview as a BinaryFileResponse on a local disk', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/photo.jpg';
    $upload = UploadedFile::fake()->image('photo.jpg', 50, 50);
    $fakeImage = file_get_contents($upload->getRealPath());
    $sha = servedPrivateAsset($path, $fakeImage);
    Storage::disk(Directory::ASSETS_DISK_PRIVATE)->put(DerivativeImages::preview($sha, 800, 'jpg'), $fakeImage);

    request()->merge(['path' => $path, 'size' => '800']);

    $response = (new FileController)->preview();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->getFile()->getPathname())->toEndWith(DerivativeImages::preview($sha, 800, 'jpg'));
});

it('serves an SVG original as its own thumbnail through the read seam', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/icon.svg';
    servedPrivateAsset($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull()
        ->and(Storage::disk(Directory::ASSETS_DISK_PRIVATE)->allFiles(DerivativeImages::ROOT))->toBe([]);
});

it('serves a PDF original for preview through the read seam', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/document.pdf';
    $fakePdf = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    servedPrivateAsset($path, file_get_contents($fakePdf->getRealPath()));

    request()->merge(['path' => $path, 'size' => '800']);

    $response = (new FileController)->preview();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
});

it('redirects a cached thumbnail on a public AWS disk to its direct S3 URL', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);
    $this->loginAsAdmin();

    $path = 'assets/Root/photo.jpg';
    $disk = mockedS3ThumbnailDisk($path);
    $thumbPath = DerivativeImages::thumbnail(hash('sha256', 'remote-photo'), 'jpg');
    $s3Url = 'https://s3.example.com/'.$thumbPath;
    $disk->shouldReceive('getVisibility')->with($thumbPath)->andReturn('public');
    $disk->shouldReceive('url')->with($thumbPath)->andReturn($s3Url);

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toBe($s3Url);
});

it('redirects a cached thumbnail on a private AWS disk to a signed S3 URL', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);
    $this->loginAsAdmin();

    $path = 'assets/Root/photo.jpg';
    $disk = mockedS3ThumbnailDisk($path);
    $thumbPath = DerivativeImages::thumbnail(hash('sha256', 'remote-photo'), 'jpg');
    $signedUrl = 'https://s3.example.com/'.$thumbPath.'?X-Amz-Signature=abc';
    $disk->shouldReceive('getVisibility')->with($thumbPath)->andReturn('private');
    $disk->shouldReceive('temporaryUrl')->with($thumbPath, Mockery::any())->andReturn($signedUrl);

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toBe($signedUrl);
});

/** A video whose poster sits on a mocked S3 disk; returns the disk and the poster's key. */
function mockedS3VideoPoster(string $path): array
{
    $bytes = 'remote-video';
    $sha = hash('sha256', $bytes);
    $object = FileObject::factory()->create(['sha256' => $sha, 'size' => strlen($bytes), 'disk' => Directory::ASSETS_DISK_AWS, 'mime_type' => 'video/mp4']);
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => basename($path), 'extension' => 'mp4', 'path' => $path, 'file_type' => 'video', 'mime_type' => 'video/mp4']));
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL]);

    $poster = DerivativeImages::poster($sha);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->with($object->path)->andReturn(true);
    $disk->shouldReceive('size')->with($object->path)->andReturn(strlen($bytes));
    $disk->shouldReceive('exists')->with($poster)->andReturn(true);

    Storage::shouldReceive('disk')->with(Directory::ASSETS_DISK_AWS)->andReturn($disk);

    return [$disk, $poster];
}

it('signs a poster URL when the bucket does not support object ACLs', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS, 'dam.content_store.remote_accel_prefix' => null]);
    $this->loginAsAdmin();

    $path = 'assets/Root/golive-large.mp4';
    [$disk, $poster] = mockedS3VideoPoster($path);
    $signedUrl = 'https://garage.example.com/'.$poster.'?X-Amz-Signature=abc';
    $disk->shouldReceive('getVisibility')->with($poster)->andThrow(UnableToRetrieveMetadata::visibility($poster, 'GetObjectAcl'));
    $disk->shouldReceive('temporaryUrl')->with($poster, Mockery::any())->andReturn($signedUrl);

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe($signedUrl);
});

it('hands a poster to nginx without asking the bucket for its ACL', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS, 'dam.content_store.remote_accel_prefix' => '/__dam_remote']);
    $this->loginAsAdmin();

    $path = 'assets/Root/golive-large.mp4';
    [$disk, $poster] = mockedS3VideoPoster($path);
    $disk->shouldNotReceive('getVisibility');
    $disk->shouldReceive('temporaryUrl')
        ->with($poster, Mockery::any(), ['ResponseContentType' => 'image/jpeg'])
        ->andReturn('http://10.0.0.5:3900/mothership/'.$poster.'?X-Amz-Signature=abc');

    request()->merge(['path' => $path]);

    $response = (new FileController)->thumbnail();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Accel-Redirect'))->toBe('/__dam_remote/http/10.0.0.5:3900/mothership/'.$poster.'?X-Amz-Signature=abc');
});

it('answers a held poster version with a 304 that nginx does not follow', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS, 'dam.content_store.remote_accel_prefix' => '/__dam_remote']);
    $this->loginAsAdmin();

    $path = 'assets/Root/golive-large.mp4';
    [$disk, $poster] = mockedS3VideoPoster($path);
    $sha = hash('sha256', 'remote-video');
    $disk->shouldReceive('temporaryUrl')->andReturn('http://10.0.0.5:3900/mothership/'.$poster.'?X-Amz-Signature=abc');

    request()->merge(['path' => $path, 'v' => $sha]);
    request()->headers->set('If-None-Match', '"'.substr(hash('sha256', 'dam-derivative|'.$sha), 0, 32).'"');

    $response = (new FileController)->thumbnail();

    expect($response->getStatusCode())->toBe(304)
        ->and($response->headers->has('X-Accel-Redirect'))->toBeFalse();
});

it('thumbnail aborts with 403 when user is not authenticated', function () {
    Auth::shouldReceive('check')->andReturn(false);

    request()->merge(['path' => 'assets/Root/photo.jpg']);

    expect(fn () => (new FileController)->thumbnail())->toThrow(HttpException::class);
});

it('preview aborts with 403 when user is not authenticated', function () {
    Auth::shouldReceive('check')->andReturn(false);

    request()->merge(['path' => 'assets/Root/photo.jpg', 'size' => '800']);

    expect(fn () => (new FileController)->preview())->toThrow(HttpException::class);
});
