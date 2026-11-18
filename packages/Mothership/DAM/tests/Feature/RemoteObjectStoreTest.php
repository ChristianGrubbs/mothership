<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Tests\Support\RemoteDiskFake;

/**
 * ADR 0017: asset bytes on an S3-compatible store. The disk here is RemoteDiskFake, real bytes behind an adapter
 * that reports itself non-local, so the object-store branches run and their server-side copies can be counted.
 */
beforeEach(function () {
    config(['dam.asset_disk' => 's3']);
    $this->remote = RemoteDiskFake::install('s3');
    $this->store = app(ContentStore::class);
    $this->local = tempnam(sys_get_temp_dir(), 'remote-store-');
});

afterEach(function () {
    @unlink($this->local);
});

it('picks the asset disk from MOTHERSHIP_ASSET_DISK, falling back to FILESYSTEM_DISK', function () {
    config(['filesystems.default' => 'public', 'dam.asset_disk' => 's3']);
    expect(Directory::getAssetDisk())->toBe('s3');

    config(['filesystems.default' => 's3', 'dam.asset_disk' => 'private']);
    expect(Directory::getAssetDisk())->toBe('private');

    config(['filesystems.default' => 's3', 'dam.asset_disk' => null]);
    expect(Directory::getAssetDisk())->toBe('s3');

    config(['filesystems.default' => 'public', 'dam.asset_disk' => null]);
    expect(Directory::getAssetDisk())->toBe('private');
});

it('reports the object store as remote and the private disk as local', function () {
    Storage::fake('private');

    expect($this->store->isLocal('s3'))->toBeFalse()
        ->and($this->store->isLocal('private'))->toBeTrue();
});

it('writes a local file straight to its blob key, with no temp key and no server-side copy', function () {
    file_put_contents($this->local, 'remote-bytes');
    $sha = hash('sha256', 'remote-bytes');

    $object = $this->store->putLocal(disk: 's3', localPath: $this->local, extension: 'bin');

    expect($object->path)->toBe($this->store->objectPath($sha, 'bin'))
        ->and(Storage::disk('s3')->get($object->path))->toBe('remote-bytes')
        ->and($this->remote->calls)->toBe([['writeStream', $object->path]]);
});

it('refuses a short remote write without deleting the key, so a concurrent intact copy is never lost', function () {
    RemoteDiskFake::install('s3', writeStream: function (string $path, $resource, $driver): bool {
        $driver->write($path, substr((string) stream_get_contents($resource), 0, 3));

        return true;
    });
    file_put_contents($this->local, 'remote-bytes');

    expect(fn () => $this->store->putLocal(disk: 's3', localPath: $this->local))
        ->toThrow(RuntimeException::class, 'object_write_incomplete');

    // Left for the next writer: it is not intact, so the next write of these bytes replaces it.
    $objectPath = $this->store->objectPath(hash('sha256', 'remote-bytes'));
    expect(Storage::disk('s3')->get($objectPath))->toBe('rem');

    RemoteDiskFake::install('s3');
    Storage::disk('s3')->put($objectPath, 'rem');
    $this->store->putLocal(disk: 's3', localPath: $this->local);
    expect(Storage::disk('s3')->get($objectPath))->toBe('remote-bytes');
});

it('leaves the key alone when the remote write itself fails', function () {
    RemoteDiskFake::install('s3', writeStream: fn (): bool => false);
    file_put_contents($this->local, 'remote-bytes');

    expect(fn () => $this->store->putLocal(disk: 's3', localPath: $this->local))
        ->toThrow(RuntimeException::class, 'object_write_failed');
});

it('adopts a staged remote object with one copy to its blob key, deleting the source only after', function () {
    Storage::disk('s3')->put('assets/.staging/upload.bin', 'staged-bytes');
    $sha = hash('sha256', 'staged-bytes');
    $this->remote->calls = [];

    $object = $this->store->adoptStored(disk: 's3', sourcePath: 'assets/.staging/upload.bin', sha256: $sha);

    expect($this->remote->calls)->toBe([['copy', 'assets/.staging/upload.bin', $object->path]])
        ->and(Storage::disk('s3')->get($object->path))->toBe('staged-bytes')
        ->and(Storage::disk('s3')->exists('assets/.staging/upload.bin'))->toBeFalse();
});

it('keeps the staged source when the remote blob comes out the wrong size', function () {
    RemoteDiskFake::install('s3', copy: function (string $from, string $to, $driver): bool {
        $driver->write($to, 'short');

        return true;
    });
    Storage::disk('s3')->put('assets/.staging/upload.bin', 'staged-bytes');

    expect(fn () => $this->store->adoptStored(disk: 's3', sourcePath: 'assets/.staging/upload.bin', sha256: hash('sha256', 'staged-bytes')))
        ->toThrow(RuntimeException::class, 'object_write_incomplete');

    expect(Storage::disk('s3')->get('assets/.staging/upload.bin'))->toBe('staged-bytes');
});

it('copies a stored remote object to its blob key with one server-side copy', function () {
    Storage::disk('s3')->put('assets/Root/clip.bin', 'clip-bytes');
    $this->remote->calls = [];

    $object = $this->store->putStored(disk: 's3', sourcePath: 'assets/Root/clip.bin');

    expect($this->remote->calls)->toBe([['copy', 'assets/Root/clip.bin', $object->path]])
        ->and(Storage::disk('s3')->get($object->path))->toBe('clip-bytes');
});

it('stages uploads on the asset disk when it is local, and on the local staging disk when it is remote', function () {
    Storage::fake('private');

    expect($this->store->uploadStagingDisk('private'))->toBe('private')
        ->and($this->store->uploadStagingDisk('s3'))->toBe(ContentStore::STAGING_DISK);
});

it('streams a locally staged upload to its remote blob once and keeps it as the warm copy', function () {
    $staging = Storage::fake(ContentStore::STAGING_DISK);
    $staging->put('assets/.staging/upload.bin', 'staged-bytes');
    $sha = hash('sha256', 'staged-bytes');
    $this->remote->calls = [];

    $object = $this->store->adoptStored(disk: 's3', sourcePath: 'assets/.staging/upload.bin', sha256: $sha, sourceDisk: ContentStore::STAGING_DISK);

    expect($object->disk)->toBe('s3')
        ->and($this->remote->calls)->toBe([['writeStream', $object->path]])
        ->and(Storage::disk('s3')->get($object->path))->toBe('staged-bytes')
        ->and($staging->exists('assets/.staging/upload.bin'))->toBeFalse()
        ->and($staging->get(ContentStore::WARM.'/'.$sha))->toBe('staged-bytes');
});

it('refuses to adopt across disks from a source that is not local', function () {
    Storage::fake('private')->put('assets/.staging/upload.bin', 'staged-bytes');

    expect(fn () => $this->store->adoptStored(disk: 'private', sourcePath: 'assets/.staging/upload.bin', sourceDisk: 's3'))
        ->toThrow(InvalidArgumentException::class);
});

it('stages a remote blob from its warm copy with a hard link, and copies it for a caller that edits', function () {
    $staging = Storage::fake(ContentStore::STAGING_DISK);
    $staging->put('assets/.staging/upload.bin', 'warm-bytes');
    $object = $this->store->adoptStored(disk: 's3', sourcePath: 'assets/.staging/upload.bin', sourceDisk: ContentStore::STAGING_DISK);
    $warm = $staging->path(ContentStore::WARM.'/'.$object->sha256);

    // The remote copy is changed behind the store's back: a download would show it, the warm copy does not.
    Storage::disk('s3')->put($object->path, 'gone-bytes');

    $linked = $this->store->localPath($object);
    $copied = $this->store->localPath($object, copy: true);

    expect(file_get_contents($linked->path))->toBe('warm-bytes')
        ->and(fileinode($linked->path))->toBe(fileinode($warm))
        ->and(file_get_contents($copied->path))->toBe('warm-bytes')
        ->and(fileinode($copied->path))->not->toBe(fileinode($warm));

    // The sweep may remove the warm name while a job holds the link: the job keeps its bytes.
    unlink($warm);
    expect(file_get_contents($linked->path))->toBe('warm-bytes');

    $linked->release();
    $copied->release();
});

it('downloads a remote blob whose warm copy is missing or the wrong size', function () {
    $staging = Storage::fake(ContentStore::STAGING_DISK);
    $staging->put('assets/.staging/upload.bin', 'remote-bytes');
    $object = $this->store->adoptStored(disk: 's3', sourcePath: 'assets/.staging/upload.bin', sourceDisk: ContentStore::STAGING_DISK);
    $staging->put(ContentStore::WARM.'/'.$object->sha256, 'short');

    $file = $this->store->localPath($object);

    expect(file_get_contents($file->path))->toBe('remote-bytes')
        ->and(is_link($file->path))->toBeFalse();

    $file->release();
});

it('sweeps warm copies past their keep time and leaves fresh ones', function () {
    config(['dam.staging.keep_hours' => 6]);
    $staging = Storage::fake(ContentStore::STAGING_DISK);
    $staging->put(ContentStore::WARM.'/old', 'old');
    $staging->put(ContentStore::WARM.'/fresh', 'fresh');
    touch($staging->path(ContentStore::WARM.'/old'), now()->subHours(7)->getTimestamp());

    expect($this->store->sweepWarm())->toBe(1)
        ->and($staging->exists(ContentStore::WARM.'/old'))->toBeFalse()
        ->and($staging->exists(ContentStore::WARM.'/fresh'))->toBeTrue();
});
