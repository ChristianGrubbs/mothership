<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Filesystem\FileStorer;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\LibraryPaths;

/*
 * ADR 0009 slice 3 write seam: writers move bytes into a blob and hand the observer the FileObject, so an
 * asset's Library path names no file.
 */

beforeEach(function () {
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
    $this->store = app(ContentStore::class);
});

function stageBytes(string $bytes, string $extension = 'jpg'): string
{
    $path = app(ContentStore::class)->stagingPath($extension);
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);

    return $path;
}

it('moves a staged file into its blob and consumes the staging path', function () {
    $staged = stageBytes('slice-three-bytes');

    $object = $this->store->adoptStored(disk: $this->disk, sourcePath: $staged, mimeType: 'image/jpeg', extension: 'jpg');

    expect($object->sha256)->toBe(hash('sha256', 'slice-three-bytes'))
        ->and($this->store->holdsBlob($object))->toBeTrue()
        ->and(Storage::disk($this->disk)->exists($staged))->toBeFalse()
        ->and(Storage::disk($this->disk)->files(ContentStore::STAGING))->toBe([]);
});

it('reuses an intact blob for identical bytes and still consumes the duplicate staging file', function () {
    $first = $this->store->adoptStored(disk: $this->disk, sourcePath: stageBytes('same-bytes'));
    $staged = stageBytes('same-bytes');

    $second = $this->store->adoptStored(disk: $this->disk, sourcePath: $staged);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Storage::disk($this->disk)->exists($staged))->toBeFalse();
});

it('links a writer-supplied blob as the original without any file at the Library path', function () {
    $object = $this->store->adoptStored(disk: $this->disk, sourcePath: stageBytes('uploaded-photo'), mimeType: 'image/jpeg', extension: 'jpg');

    $asset = (new Asset([
        'file_name' => 'photo.jpg',
        'file_type' => 'image',
        'file_size' => strlen('uploaded-photo'),
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'path'      => 'assets/Root/photo.jpg',
    ]))->withGraphContent($object);
    $asset->save();

    expect(Storage::disk($this->disk)->exists('assets/Root/photo.jpg'))->toBeFalse()
        ->and($this->store->current($asset->fresh())->getKey())->toBe($object->getKey())
        ->and($asset->hasPendingGraphContentChange())->toBeFalse();
});

it('versions an in-place edit from a writer-supplied blob', function () {
    $v1 = $this->store->adoptStored(disk: $this->disk, sourcePath: stageBytes('version-one'));
    $asset = (new Asset([
        'file_name' => 'edit.png', 'file_type' => 'image', 'file_size' => 11,
        'mime_type' => 'image/png', 'extension' => 'png', 'path' => 'assets/Root/edit.png',
    ]))->withGraphContent($v1);
    $asset->save();

    $v2 = $this->store->adoptStored(disk: $this->disk, sourcePath: stageBytes('version-two!'));
    $asset->withGraphContent($v2)->update(['file_size' => 12]);

    $roles = AssetFile::query()->where('dam_asset_id', $asset->getKey())->pluck('role', 'file_object_id');

    expect($roles[$v2->getKey()])->toBe(AssetFile::ROLE_ORIGINAL)
        ->and($roles[$v1->getKey()])->toBe(AssetFile::ROLE_VERSION);
});

it('recreates a reaped FileObject row while its blob is intact, and refuses once the blob is gone', function () {
    $object = $this->store->adoptStored(disk: $this->disk, sourcePath: stageBytes('shared-bytes'));
    $stale = $object->replicate();
    $stale->setAttribute('id', $object->getKey());
    FileObject::query()->whereKey($object->getKey())->delete();

    $retained = $this->store->retain($stale);

    expect($retained->sha256)->toBe($object->sha256)
        ->and(FileObject::query()->where('sha256', $object->sha256)->count())->toBe(1);

    FileObject::query()->whereKey($retained->getKey())->delete();
    Storage::disk($this->disk)->delete($retained->path);

    expect(fn () => $this->store->retain($stale))->toThrow(ContentUnavailable::class);
});

it('drops an extension it cannot use in a staging name instead of failing the upload', function () {
    expect($this->store->stagingPath('JPG'))->toEndWith('.jpg')
        ->and($this->store->stagingPath('tar.gz'))->not->toContain('.tar')
        ->and($this->store->stagingPath(str_repeat('x', 40)))->toMatch('#^assets/\.staging/[0-9a-f-]{36}$#');
});

it('sweeps only abandoned staging files', function () {
    $storage = Storage::disk($this->disk);
    $old = stageBytes('abandoned');
    $fresh = stageBytes('in-flight');
    touch($storage->path($old), now()->subHours(25)->getTimestamp());

    expect($this->store->sweepStaging(disk: $this->disk))->toBe(1)
        ->and($storage->exists($old))->toBeFalse()
        ->and($storage->exists($fresh))->toBeTrue();
});

it('picks free names from dam_assets.path alone', function () {
    Storage::disk($this->disk)->put('assets/Root/on-disk-only.jpg', 'legacy');

    Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/taken.jpg', 'file_name' => 'taken.jpg']));

    expect(LibraryPaths::uniqueFileName('assets/Root', 'on-disk-only.jpg'))->toBe('on-disk-only.jpg')
        ->and(LibraryPaths::uniqueFileName('assets/Root/', 'taken.jpg'))->toBe('taken(1).jpg')
        ->and(LibraryPaths::uniqueFileName('assets/Root', 'README'))->toBe('README');
});

// Codex review of PR #63: two uploads can pick the same free name. The one whose insert loses to the UNIQUE
// constraint on dam_assets.path asks again in a fresh transaction, keeping the FileObject it already ingested.
it('retries an upload that loses its free name to a concurrent insert and lands at name(1)', function () {
    Queue::fake();
    $directory = Directory::create(['name' => 'name-race']);
    $publisher = new class(app(FileStorer::class), app(ContentStore::class), app(AccessModel::class), app(AccessListWriter::class)) extends AssetUploadPublisher
    {
        public int $allocations = 0;

        protected function generateUniqueFileName($directory, $fileName)
        {
            $name = parent::generateUniqueFileName($directory, $fileName);

            // The first time, a concurrent upload inserts under the same free name between allocation and insert.
            if (++$this->allocations === 1) {
                Asset::withoutEvents(fn () => Asset::factory()->create(['path' => $directory.'/'.$name, 'file_name' => $name]));
            }

            return $name;
        }
    };

    $asset = $publisher->publishUploadedFile([
        'directory'      => $directory,
        'original_name'  => 'photo.jpg',
        'mime_type'      => 'image/jpeg',
        'extension'      => 'jpg',
        'source'         => UploadedFile::fake()->createWithContent('photo.jpg', 'name-race-bytes'),
        'disk'           => $this->disk,
        'existing_asset' => null,
    ]);

    expect($publisher->allocations)->toBe(2)
        ->and($asset->file_name)->toBe('photo(1).jpg')
        ->and($asset->path)->toBe(Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/photo(1).jpg')
        ->and($this->store->current($asset->fresh())->sha256)->toBe(hash('sha256', 'name-race-bytes'))
        ->and($asset->directories()->pluck('dam_directories.id')->all())->toBe([$directory->id]);
});
