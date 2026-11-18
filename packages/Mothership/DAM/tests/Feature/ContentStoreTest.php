<?php

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Exceptions\NonContentAddressedLocator;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
    $this->store = app(ContentStore::class);
});

it('refuses malformed SVG staging without changing the upload or storing an asset or blob', function () {
    $dirty = '<svg xmlns="http://www.w3.org/2000/svg"><g></svg>';
    $upload = UploadedFile::fake()->createWithContent('malformed.svg', $dirty);
    $directory = Directory::factory()->create();
    $assets = Asset::query()->count();
    $objects = FileObject::query()->count();

    expect(fn () => app(AssetUploadPublisher::class)->publishUploadedFile(input: [
        'directory'      => $directory,
        'original_name'  => 'malformed.svg',
        'mime_type'      => 'image/svg+xml',
        'extension'      => 'svg',
        'source'         => $upload,
        'disk'           => $this->disk,
        'existing_asset' => null,
    ]))
        ->toThrow(ValidationException::class, 'The SVG upload is malformed and could not be sanitized.');

    expect(file_get_contents($upload->getRealPath()))->toBe($dirty)
        ->and(Storage::disk($this->disk)->allFiles())->toBe([])
        ->and(Asset::query()->count())->toBe($assets)
        ->and(FileObject::query()->count())->toBe($objects);
});

/** A FileObject whose blob holds `$bytes` on `$disk` (or the asset disk), written through the store. */
function storedObject(string $bytes, ?string $disk = null): FileObject
{
    $local = tempnam(sys_get_temp_dir(), 'content-store-');
    file_put_contents($local, $bytes);

    try {
        return app(ContentStore::class)->putLocal(disk: $disk ?? Directory::getAssetDisk(), localPath: $local, mimeType: 'video/mp4');
    } finally {
        @unlink($local);
    }
}

/** Expect `$read` to throw ContentUnavailable with `$reason`. */
function expectUnavailable(Closure $read, string $reason): void
{
    try {
        $read();
    } catch (ContentUnavailable $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    test()->fail("Expected ContentUnavailable ({$reason}).");
}

/** The last record ContentStore wrote to its JSONL log. */
function lastContentStoreRecord(): array
{
    $lines = file(storage_path(ContentStore::LOG), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return json_decode((string) end($lines), true) ?? [];
}

it('names an asset\'s current original and records an asset without one', function () {
    Storage::disk($this->disk)->put('assets/Root/current.mp4', 'current-bytes');
    $asset = registerServedAssetPath('assets/Root/current.mp4');

    expect($this->store->current($asset)->sha256)->toBe(hash('sha256', 'current-bytes'));

    $bare = registerServedAssetPath('assets/Root/never-ingested.mp4');
    expectUnavailable(fn () => $this->store->current($bare), ContentUnavailable::NO_ORIGINAL);

    expect(lastContentStoreRecord())->toMatchArray(['level' => 'warning', 'event' => 'content_unavailable'])
        ->and(lastContentStoreRecord()['ctx'])->toBe(['reason' => 'no_original', 'asset_id' => $bare->id]);
});

it('opens a blob stream, and refuses a missing or wrong-size blob', function () {
    $object = storedObject('stream-bytes');

    $stream = $this->store->open($object);
    expect(stream_get_contents($stream))->toBe('stream-bytes');
    fclose($stream);

    Storage::disk($this->disk)->put($object->path, 'short');
    expectUnavailable(fn () => $this->store->open($object), ContentUnavailable::BLOB_MISSING);

    Storage::disk($this->disk)->delete($object->path);
    expectUnavailable(fn () => $this->store->open($object), ContentUnavailable::BLOB_MISSING);

    expect(lastContentStoreRecord()['ctx'])->toMatchArray(['reason' => 'blob_missing', 'file_object_id' => $object->id]);
});

it('stages a symlink named by the caller\'s extension and removes it on release or destruction', function () {
    $object = storedObject('osv-bytes');
    $blob = Storage::disk($this->disk)->path($object->path);

    $local = $this->store->localPath($object, 'OSV');
    $directory = dirname($local->path);

    expect(basename($local->path))->toBe('source.osv')
        ->and(is_link($local->path))->toBeTrue()
        ->and(file_get_contents($local->path))->toBe('osv-bytes');

    $local->release();
    $local->release();

    expect(is_dir($directory))->toBeFalse()
        ->and(file_get_contents($blob))->toBe('osv-bytes');

    $scoped = $this->store->localPath($object, 'mp4');
    $scopedDirectory = dirname($scoped->path);
    unset($scoped);

    expect(is_dir($scopedDirectory))->toBeFalse()
        ->and(is_file($blob))->toBeTrue();
});

it('copies a remote blob to the staging path and streams it on delivery', function () {
    $object = storedObject('remote-bytes');
    $object->forceFill(['disk' => 'remote-content'])->save();
    $remote = Mockery::mock(Filesystem::class);
    $remote->shouldReceive('exists')->with($object->path)->andReturn(true);
    $remote->shouldReceive('size')->with($object->path)->andReturn(strlen('remote-bytes'));
    $remote->shouldReceive('readStream')->with($object->path)->andReturnUsing(function () {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'remote-bytes');
        rewind($stream);

        return $stream;
    });
    Storage::set('remote-content', $remote);

    $local = $this->store->localPath($object, 'mp4');

    expect(is_link($local->path))->toBeFalse()
        ->and(basename($local->path))->toBe('source.mp4')
        ->and(file_get_contents($local->path))->toBe('remote-bytes');
    $local->release();

    $response = $this->store->deliver($object, 'remote.mp4');
    ob_start();
    $response->sendContent();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and(ob_get_clean())->toBe('remote-bytes')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen('remote-bytes'));
});

it('delivers a local blob through nginx under the asset\'s name and type', function () {
    config(['mothership.media_accel_prefix' => '/__dam_media/']);
    $object = storedObject('accel-bytes');

    $response = $this->store->deliver($object, 'Drone clip ü.mp4', mimeType: 'video/mp4');

    expect($response->headers->get('X-Accel-Redirect'))->toBe('/__dam_media/'.$object->path)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($response->headers->get('Content-Disposition'))->toBe("inline; filename=\"Drone clip u.mp4\"; filename*=utf-8''Drone%20clip%20%C3%BC.mp4")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull()
        ->and($response->headers->has('X-Accel-Buffering'))->toBeFalse()
        ->and($response->getContent())->toBe('');

    $html = $this->store->deliver($object, 'page.html', mimeType: 'text/html');
    expect($html->headers->get('Content-Disposition'))->toBe('attachment; filename=page.html')
        ->and($html->headers->get('Content-Type'))->toBe('text/html');

    Storage::disk($this->disk)->delete($object->path);
    expectUnavailable(fn () => $this->store->deliver($object, 'gone.mp4'), ContentUnavailable::BLOB_MISSING);
});

it('falls back to a ranged file response without an accel prefix', function () {
    config(['mothership.media_accel_prefix' => null]);
    $object = storedObject('ranged-bytes');

    $response = $this->store->deliver($object, 'clip.mp4');

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->getFile()->getPathname())->toBe(Storage::disk($this->disk)->path($object->path))
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('redirects a remote blob to a temporary URL carrying its type and disposition', function () {
    $object = storedObject('s3-bytes');
    $object->forceFill(['disk' => 's3-content'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('temporaryUrl')->once()->withArgs(function (string $path, $expiration, array $options) use ($object): bool {
        return $path === $object->path
            && $options === ['ResponseContentType' => 'text/plain', 'ResponseContentDisposition' => 'attachment; filename=notes.txt'];
    })->andReturn('https://s3.test/blob?signed=1');
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'notes.txt', mimeType: 'text/plain');

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe('https://s3.test/blob?signed=1');
});

// ADR 0017: a store on a private network cannot take the browser's redirect, so nginx fetches the signed URL.
it('hands a remote blob to nginx by its signed URL when a remote accel prefix is set, same-origin callers too', function (bool $sameOrigin) {
    config(['dam.content_store.remote_accel_prefix' => '/__dam_remote/']);
    $object = storedObject('s3-bytes');
    $object->forceFill(['disk' => 's3-content', 'mime_type' => 'image/png'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('temporaryUrl')->once()->withArgs(function (string $path, $expiration, array $options) use ($object): bool {
        return $path === $object->path && $options['ResponseContentType'] === 'image/png';
    })->andReturn('http://10.0.0.5:9000/mothership/'.$object->path.'?X-Amz-Signature=abc%2Fdef&X-Amz-Expires=300');
    $s3->shouldNotReceive('readStream');
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'photo.png', sameOrigin: $sameOrigin);

    expect($response)->not->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('X-Accel-Redirect'))
        ->toBe('/__dam_remote/http/10.0.0.5:9000/mothership/'.$object->path.'?X-Amz-Signature=abc%2Fdef&X-Amz-Expires=300')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toContain('photo.png');
})->with([false, true]);

it('answers 404 for a stored file missing from a bucket without object ACLs', function (?string $prefix) {
    config(['dam.content_store.remote_accel_prefix' => $prefix]);
    $path = 'derivatives/4f/missing/poster.jpg';
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getVisibility')->with($path)->andThrow(UnableToRetrieveMetadata::visibility($path, 'GetObjectAcl'));
    $s3->shouldReceive('exists')->with($path)->andReturn(false);
    $s3->shouldNotReceive('temporaryUrl');
    Storage::set('s3-content', $s3);

    expect(fn () => $this->store->deliverStoredFile(disk: 's3-content', path: $path))->toThrow(NotFoundHttpException::class);
})->with([null, '/__dam_remote']);

it('streams a stored file whose key nginx could not carry unchanged', function () {
    config(['dam.content_store.remote_accel_prefix' => '/__dam_remote']);
    $path = 'covers/old name.jpg';
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, 'cover-bytes');
    rewind($stream);
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('exists')->with($path)->andReturn(true);
    $s3->shouldNotReceive('temporaryUrl');
    $s3->shouldReceive('readStream')->with($path)->andReturn($stream);
    Storage::set('s3-content', $s3);

    $response = $this->store->deliverStoredFile(disk: 's3-content', path: $path);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->has('X-Accel-Redirect'))->toBeFalse();
});

it('copies and moves on the configured S3 disk without reading an object ACL', function (string $operation) {
    $commands = new ArrayObject;
    $bucket = function (CommandInterface $command) use ($commands) {
        $commands[] = $command->getName();

        return $command->getName() === 'GetObjectAcl'
            ? Create::rejectionFor(new S3Exception('GetObjectAcl is not implemented', $command))
            : Create::promiseFor(new Result(['ContentLength' => 8]));
    };

    $disk = Storage::build(array_merge(config('filesystems.disks.s3'), [
        'key'                     => 'k',
        'secret'                  => 's',
        'region'                  => 'garage',
        'bucket'                  => 'mothership',
        'endpoint'                => 'http://garage.test:3900',
        'use_path_style_endpoint' => true,
        'handler'                 => $bucket,
    ]));

    expect($disk->{$operation}('assets/.staging/source.mp4', 'assets/.objects/ab/target.mp4'))->toBeTrue()
        ->and($commands->getArrayCopy())->toContain('CopyObject')
        ->and($commands->getArrayCopy())->not->toContain('GetObjectAcl');
})->with(['copy', 'move']);

it('keeps an IPv6 store host in brackets when handing a remote blob to nginx', function () {
    config(['dam.content_store.remote_accel_prefix' => '/__dam_remote']);
    $object = storedObject('s3-bytes');
    $object->forceFill(['disk' => 's3-content', 'mime_type' => 'image/png'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('temporaryUrl')->once()->andReturn('http://[2001:db8::5]:9000/mothership/'.$object->path.'?X-Amz-Signature=s');
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'photo.png');

    expect($response->headers->get('X-Accel-Redirect'))
        ->toBe('/__dam_remote/http/[2001:db8::5]:9000/mothership/'.$object->path.'?X-Amz-Signature=s');
});

it('keeps streaming remote content that needs the app CSP even with a remote accel prefix', function () {
    config(['dam.content_store.remote_accel_prefix' => '/__dam_remote']);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';
    $object = storedObject($svg);
    $object->forceFill(['disk' => 's3-content', 'mime_type' => 'image/svg+xml'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldNotReceive('temporaryUrl');
    $s3->shouldReceive('readStream')->andReturnUsing(function () use ($svg) {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $svg);
        rewind($stream);

        return $stream;
    });
    $s3->shouldReceive('size')->andReturn(strlen($svg));
    $s3->shouldReceive('exists')->andReturn(true);
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'logo.svg');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->has('X-Accel-Redirect'))->toBeFalse();
});

it('streams a remote raster through the app instead of redirecting when the caller needs it same-origin', function () {
    $png = 'png-bytes-for-a-canvas';
    $object = storedObject($png);
    $object->forceFill(['disk' => 's3-content', 'mime_type' => 'image/png'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('exists')->with($object->path)->andReturn(true);
    $s3->shouldReceive('size')->with($object->path)->andReturn(strlen($png));
    $s3->shouldReceive('readStream')->with($object->path)->andReturnUsing(function () use ($png) {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $png);
        rewind($stream);

        return $stream;
    });
    // A redirect to the object store would taint the image editor's canvas.
    $s3->shouldNotReceive('temporaryUrl');
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'layer', headers: ['Cache-Control' => 'private, no-store'], sameOrigin: true);
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($body)->toBe($png)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('streams a remote inline SVG through the app with the CSP instead of redirecting to the object', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("/admin/steal?c="+document.cookie)</script></svg>';
    $object = storedObject($svg);
    $object->forceFill(['disk' => 's3-content', 'mime_type' => 'image/svg+xml'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('exists')->with($object->path)->andReturn(true);
    $s3->shouldReceive('size')->with($object->path)->andReturn(strlen($svg));
    $s3->shouldReceive('readStream')->with($object->path)->andReturnUsing(function () use ($svg) {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $svg);
        rewind($stream);

        return $stream;
    });
    // Neither a signed nor a public object URL may be issued for it.
    $s3->shouldNotReceive('temporaryUrl');
    $s3->shouldNotReceive('url');
    Storage::set('s3-content', $s3);

    $response = $this->store->deliver($object, 'logo.svg');
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($body)->toBe($svg)
        ->and($this->store->temporaryUrl($object, 'logo.svg'))->toBeNull();

    // As a download it is inert, so the direct object URL is fine again.
    $download = Mockery::mock(FilesystemAdapter::class);
    $download->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $download->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $download->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.test/logo?signed=1');
    Storage::set('s3-content', $download);

    expect($this->store->deliver($object, 'logo.svg', disposition: 'attachment'))->toBeInstanceOf(RedirectResponse::class);
});

it('refuses to record a locator that is not the row\'s own blob', function () {
    $sha = hash('sha256', 'guarded');

    expect(fn () => FileObject::factory()->create(['sha256' => $sha, 'path' => 'assets/Root/guarded.bin']))
        ->toThrow(NonContentAddressedLocator::class);
    expect(fn () => FileObject::factory()->create(['sha256' => $sha, 'path' => $this->store->objectPath(hash('sha256', 'other'))]))
        ->toThrow(NonContentAddressedLocator::class);

    $object = FileObject::factory()->create(['sha256' => $sha, 'path' => $this->store->objectPath($sha, 'MP4')]);
    expect($object->path)->toBe('assets/.objects/'.substr($sha, 0, 2).'/'.$sha.'.mp4');

    expect(fn () => $object->update(['path' => 'assets/Photos/moved.mp4']))->toThrow(NonContentAddressedLocator::class);
    expect($object->fresh()->path)->toBe($this->store->objectPath($sha, 'mp4'));
});

it('reuses an intact blob, and repoints a row whose blob is missing at a verified copy', function () {
    Storage::disk($this->disk)->put('assets/Root/clip.mp4', 'clip-bytes');
    $sha = hash('sha256', 'clip-bytes');

    $first = $this->store->putStored(disk: $this->disk, sourcePath: 'assets/Root/clip.mp4');
    expect($first->path)->toBe($this->store->objectPath($sha))
        ->and(Storage::disk($this->disk)->get($first->path))->toBe('clip-bytes');

    $local = tempnam(sys_get_temp_dir(), 'content-store-');
    file_put_contents($local, 'clip-bytes');
    try {
        expect($this->store->putLocal(disk: $this->disk, localPath: $local, extension: 'mp4')->id)->toBe($first->id);
        Storage::disk($this->disk)->assertMissing($this->store->objectPath($sha, 'mp4'));

        Storage::disk($this->disk)->delete($first->path);
        $repaired = $this->store->putLocal(disk: $this->disk, localPath: $local, extension: 'mp4');
    } finally {
        @unlink($local);
    }

    expect($repaired->id)->toBe($first->id)
        ->and($repaired->fresh()->path)->toBe($this->store->objectPath($sha, 'mp4'))
        ->and($this->store->holdsBlob($repaired->fresh()))->toBeTrue();
});
