<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Observers\CompoundAssetObserver;
use Mothership\DAM\Services\AssetDeletionService;

class CountingCompoundAssetObserverFixture extends CompoundAssetObserver
{
    public int $hashCount = 0;

    protected function hashFile(Filesystem $storage, string $path): string
    {
        $this->hashCount++;

        return parent::hashFile($storage, $path);
    }
}

class FailingCompoundAssetObserverFixture extends CompoundAssetObserver
{
    protected function afterAttachmentSwap(Asset $asset, AssetFile $newOriginal): void
    {
        throw new RuntimeException('Injected graph transition failure.');
    }
}

beforeEach(function () {
    $this->loginAsAdmin();
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
});

it('links a FileObject and original AssetFile when an asset is created with a path', function () {
    $content = 'initial-asset-content';
    $path = 'assets/Root/initial-doc.txt';
    Storage::disk($this->disk)->put($path, $content);

    $asset = Asset::create([
        'file_name' => 'initial-doc.txt',
        'file_type' => 'document',
        'file_size' => strlen($content),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);

    $expectedSha256 = hash('sha256', $content);

    $fileObject = FileObject::where('sha256', $expectedSha256)->first();
    expect($fileObject)->not->toBeNull();
    expect($fileObject->size)->toBe(strlen($content));
    expect($fileObject->disk)->toBe($this->disk);
    // First ingest records the blob, never the Library path (ADR 0009).
    expect($fileObject->path)->toBe('assets/.objects/'.substr($expectedSha256, 0, 2).'/'.$expectedSha256);
    Storage::disk($this->disk)->assertExists($fileObject->path);

    $assetFile = AssetFile::where('dam_asset_id', $asset->id)
        ->where('file_object_id', $fileObject->id)
        ->where('role', AssetFile::ROLE_ORIGINAL)
        ->first();

    expect($assetFile)->not->toBeNull();
    expect($assetFile->sort)->toBe(0);
    expect(AssetVersion::where('dam_asset_id', $asset->id)->count())->toBe(0);
});

it('creates a new FileObject, demotes previous original to version, and creates AssetVersion on content replacement', function () {
    $initialContent = 'version-1-content';
    $path = 'assets/Root/versioned-asset.txt';
    Storage::disk($this->disk)->put($path, $initialContent);

    $asset = Asset::create([
        'file_name' => 'versioned-asset.txt',
        'file_type' => 'document',
        'file_size' => strlen($initialContent),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);

    $v1Sha256 = hash('sha256', $initialContent);
    $v1FileObject = FileObject::where('sha256', $v1Sha256)->first();
    expect($v1FileObject)->not->toBeNull();

    $initialOriginalAssetFile = AssetFile::where('dam_asset_id', $asset->id)
        ->where('file_object_id', $v1FileObject->id)
        ->where('role', AssetFile::ROLE_ORIGINAL)
        ->first();
    expect($initialOriginalAssetFile)->not->toBeNull();

    // Replace file bytes in-place and explicitly mark this save as ingestion.
    $v2Content = 'version-2-replacement-content';
    Storage::disk($this->disk)->put($path, $v2Content);
    $asset->markGraphContentChanged()->update(['file_size' => strlen($v2Content)]);

    $v2Sha256 = hash('sha256', $v2Content);
    $v2FileObject = FileObject::where('sha256', $v2Sha256)->first();
    expect($v2FileObject)->not->toBeNull();
    expect($v2FileObject->id)->not->toBe($v1FileObject->id);
    expect($v2FileObject->path)->toBe('assets/.objects/'.substr($v2Sha256, 0, 2).'/'.$v2Sha256);

    $v1FileObject->refresh();
    expect($v1FileObject->path)->toBe('assets/.objects/'.substr($v1Sha256, 0, 2).'/'.$v1Sha256);

    // Old AssetFile should be demoted to role=version
    $demotedAssetFile = AssetFile::find($initialOriginalAssetFile->id);
    expect($demotedAssetFile->role)->toBe(AssetFile::ROLE_VERSION);

    // New original AssetFile should be created
    $v2OriginalAssetFile = AssetFile::where('dam_asset_id', $asset->id)
        ->where('file_object_id', $v2FileObject->id)
        ->where('role', AssetFile::ROLE_ORIGINAL)
        ->first();
    expect($v2OriginalAssetFile)->not->toBeNull();
    expect($v2OriginalAssetFile->sort)->toBe(0);

    // AssetVersion record created with number = 1
    $version1 = AssetVersion::where('dam_asset_id', $asset->id)
        ->where('number', 1)
        ->first();
    expect($version1)->not->toBeNull();
    expect($version1->primary_asset_file_id)->toBe($v2OriginalAssetFile->id);

    // Lineage survives: total files is 2 (1 original, 1 version)
    expect($asset->files()->count())->toBe(2);
    expect($asset->versions()->count())->toBe(1);

    // Every advertised immutable object must still reopen to its identity.
    foreach (FileObject::query()->get() as $historicalObject) {
        expect(Storage::disk($historicalObject->disk)->exists($historicalObject->path))->toBeTrue();
        expect(hash('sha256', Storage::disk($historicalObject->disk)->get($historicalObject->path)))
            ->toBe($historicalObject->sha256);
    }

    expect(Storage::disk($this->disk)->get($asset->path))->toBe($v2Content);
});

it('increments version numbers monotonically across multiple content replacements', function () {
    $path = 'assets/Root/multi-version.txt';
    Storage::disk($this->disk)->put($path, 'v1');

    $asset = Asset::create([
        'file_name' => 'multi-version.txt',
        'file_type' => 'document',
        'file_size' => 2,
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);

    // Replace with v2
    Storage::disk($this->disk)->put($path, 'v2-data');
    $asset->markGraphContentChanged()->update(['file_size' => strlen('v2-data')]);

    // Replace with v3
    Storage::disk($this->disk)->put($path, 'v3-data-even-longer');
    $asset->markGraphContentChanged()->update(['file_size' => strlen('v3-data-even-longer')]);

    expect($asset->versions()->count())->toBe(2);

    $versions = $asset->versions()->orderBy('number', 'asc')->get();
    expect($versions[0]->number)->toBe(1);
    expect($versions[1]->number)->toBe(2);

    // 1 original + 2 version role AssetFiles
    expect($asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->count())->toBe(1);
    expect($asset->files()->where('role', AssetFile::ROLE_VERSION)->count())->toBe(2);
});

it('keeps FileObject.path on the blob through a pure rename / move', function () {
    $content = 'rename-test-content';
    $oldPath = 'assets/Root/old-name.txt';
    Storage::disk($this->disk)->put($oldPath, $content);

    $asset = Asset::create([
        'file_name' => 'old-name.txt',
        'file_type' => 'document',
        'file_size' => strlen($content),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $oldPath,
    ]);

    $sha256 = hash('sha256', $content);
    $blob = 'assets/.objects/'.substr($sha256, 0, 2).'/'.$sha256;
    $fileObject = FileObject::where('sha256', $sha256)->first();
    expect($fileObject->path)->toBe($blob);

    // Move file on storage and update asset path
    $newPath = 'assets/Root/new-name.txt';
    Storage::disk($this->disk)->move($oldPath, $newPath);

    $asset->update([
        'file_name' => 'new-name.txt',
        'path'      => $newPath,
    ]);

    $fileObject->refresh();
    expect($fileObject->path)->toBe($blob);
    expect(hash('sha256', Storage::disk($this->disk)->get($fileObject->path)))->toBe($sha256);
    expect(FileObject::count())->toBe(1);
    expect(AssetVersion::count())->toBe(0);
});

it('keeps a shared locator on the blob when one owner is renamed, and readable after that owner is deleted', function () {
    $content = 'shared-blob-content';
    $pathA = 'assets/Root/asset-a.txt';
    $pathB = 'assets/Root/asset-b.txt';

    Storage::disk($this->disk)->put($pathA, $content);
    Storage::disk($this->disk)->put($pathB, $content);

    $assetA = Asset::create([
        'file_name' => 'asset-a.txt',
        'file_type' => 'document',
        'file_size' => strlen($content),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $pathA,
    ]);

    $assetB = Asset::create([
        'file_name' => 'asset-b.txt',
        'file_type' => 'document',
        'file_size' => strlen($content),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $pathB,
    ]);

    $sha256 = hash('sha256', $content);
    $sharedFileObject = FileObject::where('sha256', $sha256)->first();

    $blob = 'assets/.objects/'.substr($sha256, 0, 2).'/'.$sha256;

    expect(FileObject::where('sha256', $sha256)->count())->toBe(1);
    expect($sharedFileObject->path)->toBe($blob);

    // Rename asset A
    $newPathA = 'assets/Root/asset-a-renamed.txt';
    Storage::disk($this->disk)->move($pathA, $newPathA);

    $assetA->update([
        'file_name' => 'asset-a-renamed.txt',
        'path'      => $newPathA,
    ]);

    $sharedFileObject->refresh();
    expect($sharedFileObject->path)->toBe($blob);
    expect(hash('sha256', Storage::disk($this->disk)->get($sharedFileObject->path)))->toBe($sha256);
    expect(AssetVersion::count())->toBe(0);

    app(AssetDeletionService::class)->delete($assetA);

    $sharedFileObject->refresh();
    expect(AssetFile::query()->where('dam_asset_id', $assetB->id)->where('file_object_id', $sharedFileObject->id)->exists())
        ->toBeTrue();
    expect(Storage::disk($this->disk)->exists($sharedFileObject->path))->toBeTrue();
    expect(hash('sha256', Storage::disk($this->disk)->get($sharedFileObject->path)))->toBe($sha256);
});

it('keeps every step of an A to B to A to B checksum cycle as its own revision of one slot', function () {
    $path = 'assets/Root/cyclic.txt';
    $contentA = 'cycle-A';
    $contentB = 'cycle-content-B';
    Storage::disk($this->disk)->put($path, $contentA);

    $asset = Asset::create([
        'file_name' => 'cyclic.txt',
        'file_type' => 'document',
        'file_size' => strlen($contentA),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);

    foreach ([$contentB, $contentA, $contentB] as $content) {
        Storage::disk($this->disk)->put($path, $content);
        $asset->markGraphContentChanged()->update(['file_size' => strlen($content)]);
    }

    // P3a S2: a revision is never reused or deleted, so returning to earlier bytes appends a revision.
    $revisions = $asset->files()->with('fileObject')->orderBy('revision_number')->get();
    expect($revisions->pluck('revision_number')->all())->toBe([1, 2, 3, 4])
        ->and($revisions->pluck('attachment_id')->unique()->count())->toBe(1)
        ->and($revisions->pluck('role')->all())->toBe(['version', 'version', 'version', 'original'])
        ->and($revisions->pluck('is_current')->all())->toBe([false, false, false, true])
        ->and($revisions->pluck('fileObject.sha256')->all())->toBe(array_map(fn ($c) => hash('sha256', $c), [$contentA, $contentB, $contentA, $contentB]))
        ->and($revisions->pluck('file_object_id')->unique()->count())->toBe(2);
    expect($asset->attachments()->sole()->is_representative)->toBeTrue();

    // Version N names the revision that became current at N.
    expect($asset->versions()->orderBy('number')->get()->map(fn ($v) => [$v->number, $v->primary_asset_file_id])->all())
        ->toBe([[1, $revisions[1]->id], [2, $revisions[2]->id], [3, $revisions[3]->id]]);

    foreach ($asset->files()->with('fileObject')->get() as $attachment) {
        expect(hash('sha256', Storage::disk($attachment->fileObject->disk)->get($attachment->fileObject->path)))
            ->toBe($attachment->fileObject->sha256);
    }
});

it('hashes once on create and replacement and does not hash metadata-only saves', function () {
    $path = 'assets/Root/hash-count.txt';
    $contentA = str_repeat('A', 4 * 1024 * 1024);
    $contentB = str_repeat('B', 4 * 1024 * 1024);
    Storage::disk($this->disk)->put($path, $contentA);
    $observer = new CountingCompoundAssetObserverFixture;

    $asset = Asset::withoutEvents(fn () => Asset::create([
        'file_name' => 'hash-count.txt',
        'file_type' => 'document',
        'file_size' => strlen($contentA),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]));
    $observer->created($asset);
    expect($observer->hashCount)->toBe(1);

    Asset::withoutEvents(fn () => $asset->update(['title' => 'Metadata only']));
    $observer->updated($asset);
    expect($observer->hashCount)->toBe(1);

    Storage::disk($this->disk)->put($path, $contentB);
    $asset->markGraphContentChanged();
    Asset::withoutEvents(fn () => $asset->update(['file_size' => strlen($contentB)]));
    $observer->updated($asset);
    expect($observer->hashCount)->toBe(2);
});

it('versions an explicit same-size content change that dirties no column', function () {
    $this->freezeSecond();
    $path = 'assets/Root/same-size.png';
    Storage::disk($this->disk)->put($path, 'bytes-AAAA');
    $asset = Asset::create([
        'file_name' => 'same-size.png', 'file_type' => 'image', 'file_size' => 10,
        'mime_type' => 'image/png', 'extension' => 'png', 'path' => $path,
    ]);

    // The in-place editor's shape: same path, same length, same second.
    Storage::disk($this->disk)->put($path, 'bytes-BBBB');
    $asset->markGraphContentChanged();
    $asset->file_size = 10;
    $asset->touch();

    $original = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->firstOrFail();
    expect($original->fileObject->sha256)->toBe(hash('sha256', 'bytes-BBBB'))
        ->and($asset->files()->where('role', AssetFile::ROLE_VERSION)->count())->toBe(1)
        ->and($asset->versions()->count())->toBe(1);
});

it('rolls back the asset row and graph when a transition fails after swapping attachments', function () {
    $path = 'assets/Root/fault-injection.txt';
    Storage::disk($this->disk)->put($path, 'fault-A');
    $asset = Asset::create([
        'file_name' => 'fault-injection.txt',
        'file_type' => 'document',
        'file_size' => strlen('fault-A'),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);
    $originalId = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->id;

    Asset::flushEventListeners();
    Asset::observe(FailingCompoundAssetObserverFixture::class);

    try {
        Storage::disk($this->disk)->put($path, 'fault-content-B');

        expect(fn () => $asset->markGraphContentChanged()->update([
            'file_size' => strlen('fault-content-B'),
        ]))->toThrow(RuntimeException::class, 'Injected graph transition failure.');

        $asset->refresh();
        expect($asset->file_size)->toBe(strlen('fault-A'));
        expect($asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'))->toBe($originalId);
        expect($asset->files()->where('role', AssetFile::ROLE_VERSION)->count())->toBe(0);
        expect($asset->versions()->count())->toBe(0);
    } finally {
        Asset::flushEventListeners();
        Asset::observe(CompoundAssetObserver::class);
    }
});

it('observer does not fail user operations when file is missing from storage', function () {
    $asset = Asset::create([
        'file_name' => 'non-existent.txt',
        'file_type' => 'document',
        'file_size' => 10,
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => 'assets/Root/non-existent.txt',
    ]);

    expect($asset->exists)->toBeTrue();
    expect(AssetFile::where('dam_asset_id', $asset->id)->count())->toBe(0);

    // Updating should also not throw
    $asset->update(['file_name' => 'updated-non-existent.txt']);
    expect($asset->file_name)->toBe('updated-non-existent.txt');
});
