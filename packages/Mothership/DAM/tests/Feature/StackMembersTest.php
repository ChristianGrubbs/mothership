<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Share\SharedFiles;
use Mothership\DAM\Services\Stack\StackMembers;

/*
 * The shared stack-member query (share links and the Creative Hub): the current revision of every slot except
 * Asset::UNSTACKED_ROLES, main first, then position, then id. Merge sources are answered by MergedSourceLinks, not here.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->asset = Asset::factory()->create(['file_name' => 'poster.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/poster.png']);
    Storage::disk(Directory::getAssetDisk())->put($this->asset->path, 'MAIN');
    $this->asset->markGraphContentChanged()->save();
});

function stackMembersAttach(object $test, Asset $asset, string $name, string $role): int
{
    return (int) $test->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent($name, strtoupper($name)), 'role' => $role,
    ])->assertCreated()->json('attachment_id');
}

it('lists the main file first, then the other current slots, and never a version, proxy or thumbnail', function () {
    $sidecar = stackMembersAttach($this, $this->asset, 'grade.xmp', AssetFile::ROLE_SIDECAR);
    $project = stackMembersAttach($this, $this->asset, 'poster.psd', AssetFile::ROLE_PROJECT);
    stackMembersAttach($this, $this->asset, 'poster-proxy.mp4', AssetFile::ROLE_PROXY);
    stackMembersAttach($this, $this->asset, 'poster-thumb.jpg', AssetFile::ROLE_THUMBNAIL);
    $retired = stackMembersAttach($this, $this->asset, 'old.xmp', AssetFile::ROLE_SIDECAR);
    AssetFile::query()->where('attachment_id', $retired)->update(['is_current' => false]);
    $members = app(StackMembers::class);
    $main = $members->mainAttachmentId(asset: $this->asset);

    expect($main)->toBe((int) DB::table('dam_attachments')->where('dam_asset_id', $this->asset->id)->where('is_representative', true)->value('id'))
        ->and($members->forAsset(asset: $this->asset)->map(fn (AssetFile $file) => (int) $file->attachment_id)->all())->toBe([$main, $sidecar, $project])
        ->and(app(SharedFiles::class)->forAsset($this->asset)->map(fn (AssetFile $file) => (int) $file->id)->all())
        ->toBe($members->forAsset(asset: $this->asset)->map(fn (AssetFile $file) => (int) $file->id)->all());
});

it('orders by main first, then position, then file id, whatever the insertion order', function () {
    $a = stackMembersAttach($this, $this->asset, 'a.xmp', AssetFile::ROLE_SIDECAR);
    $b = stackMembersAttach($this, $this->asset, 'b.xmp', AssetFile::ROLE_SIDECAR);
    $c = stackMembersAttach($this, $this->asset, 'c.xmp', AssetFile::ROLE_SIDECAR);
    $d = stackMembersAttach($this, $this->asset, 'd.xmp', AssetFile::ROLE_SIDECAR);
    $members = app(StackMembers::class);
    $main = $members->mainAttachmentId(asset: $this->asset);
    DB::table('dam_attachments')->where('id', $main)->update(['position' => 9]);
    DB::table('dam_attachments')->where('id', $a)->update(['position' => 4]);
    DB::table('dam_attachments')->where('id', $b)->update(['position' => 3]);
    DB::table('dam_attachments')->whereIn('id', [$c, $d])->update(['position' => 2]);
    $slots = fn ($files): array => $files->map(fn (AssetFile $file) => (int) $file->attachment_id)->all();

    // c and d share a position, so their file ids (insertion order) decide.
    expect($slots($members->forAsset(asset: $this->asset)))->toBe([$main, $c, $d, $b, $a])
        ->and($slots(app(SharedFiles::class)->forAsset($this->asset)))->toBe([$main, $c, $d, $b, $a]);
});

it('leaves out a current eligible slot whose file object is gone', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Needs a dangling file_object_id, which only SQLite can defer inside the test transaction.');
    }

    $kept = stackMembersAttach($this, $this->asset, 'kept.xmp', AssetFile::ROLE_SIDECAR);
    $orphan = stackMembersAttach($this, $this->asset, 'orphan.xmp', AssetFile::ROLE_SIDECAR);
    // The column is NOT NULL, so the gone object is a dangling id; the transaction rolls back before the check runs.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('dam_asset_files')->where('attachment_id', $orphan)->update(['file_object_id' => 2147483000]);
    $members = app(StackMembers::class);
    $main = $members->mainAttachmentId(asset: $this->asset);
    $slots = fn ($files): array => $files->map(fn (AssetFile $file) => (int) $file->attachment_id)->all();

    expect($slots($members->forAsset(asset: $this->asset)))->toBe([$main, $kept])
        ->and($slots(app(SharedFiles::class)->forAsset($this->asset)))->toBe([$main, $kept])
        ->and($members->find(asset: $this->asset, attachmentId: $orphan))->toBeNull()
        ->and(app(SharedFiles::class)->find($this->asset, $orphan))->toBeNull();
});

it('finds one member by slot id and answers null for a generated, superseded or foreign slot', function () {
    $sidecar = stackMembersAttach($this, $this->asset, 'grade.xmp', AssetFile::ROLE_SIDECAR);
    $proxy = stackMembersAttach($this, $this->asset, 'poster-proxy.mp4', AssetFile::ROLE_PROXY);
    $other = Asset::factory()->create(['file_name' => 'other.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/other.png']);
    Storage::disk(Directory::getAssetDisk())->put($other->path, 'OTHER');
    $other->markGraphContentChanged()->save();
    $foreign = stackMembersAttach($this, $other, 'theirs.xmp', AssetFile::ROLE_SIDECAR);
    $members = app(StackMembers::class);

    expect($members->find(asset: $this->asset, attachmentId: $sidecar)?->attachment_id)->toBe($sidecar)
        ->and($members->find(asset: $this->asset, attachmentId: $proxy))->toBeNull()
        ->and($members->find(asset: $this->asset, attachmentId: $foreign))->toBeNull();
});

it('does not hand out a merge source\'s own moved slots; SharedFiles answers it through MergedSourceLinks', function () {
    $source = Asset::factory()->create(['file_name' => 'source.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/source.png']);
    Storage::disk(Directory::getAssetDisk())->put($source->path, 'SOURCE');
    $source->markGraphContentChanged()->save();
    stackMembersAttach($this, $source, 'source.xmp', AssetFile::ROLE_SIDECAR);

    $this->postJson(route('admin.dam.assets.merges.store', $this->asset->id), ['sources' => [$source->id]])->assertCreated();
    $source = $source->fresh();

    expect(app(StackMembers::class)->forAsset(asset: $source))->toBeEmpty()
        ->and(app(StackMembers::class)->mainAttachmentId(asset: $source))->toBeNull()
        ->and(app(SharedFiles::class)->forAsset($source))->toHaveCount(2);
});

it('finds one current member by file id and answers null for a superseded, generated, merged, foreign or invalid file', function () {
    $sidecar = stackMembersAttach($this, $this->asset, 'grade.xmp', AssetFile::ROLE_SIDECAR);
    $proxy = stackMembersAttach($this, $this->asset, 'poster-proxy.mp4', AssetFile::ROLE_PROXY);
    $other = Asset::factory()->create(['file_name' => 'other.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/other.png']);
    Storage::disk(Directory::getAssetDisk())->put($other->path, 'OTHER');
    $other->markGraphContentChanged()->save();
    $foreign = stackMembersAttach($this, $other, 'theirs.xmp', AssetFile::ROLE_SIDECAR);
    $fileId = fn (int $slot) => (int) AssetFile::query()->where('attachment_id', $slot)->where('is_current', true)->value('id');
    $members = app(StackMembers::class);

    $current = $fileId($sidecar);
    expect($members->findFile(asset: $this->asset, fileId: $current)?->id)->toBe($current)
        ->and($members->findFile(asset: $this->asset, fileId: $fileId($proxy)))->toBeNull()
        ->and($members->findFile(asset: $this->asset, fileId: $fileId($foreign)))->toBeNull()
        ->and($members->findFile(asset: $this->asset, fileId: 0))->toBeNull()
        ->and($members->findFile(asset: $this->asset, fileId: -4))->toBeNull();

    // A superseded revision is a different file id than the slot's current one.
    AssetFile::query()->where('id', $current)->update(['is_current' => false]);
    expect($members->findFile(asset: $this->asset, fileId: $current))->toBeNull();
});
