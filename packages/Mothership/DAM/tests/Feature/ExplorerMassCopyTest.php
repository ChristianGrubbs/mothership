<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('strips foreign member history from asset and directory batch copies', function (bool $directoryCopy) {
    Storage::fake(Directory::getAssetDisk());
    $source = Directory::factory()->create(['name' => 'Batch source']);
    $target = Directory::factory()->create(['name' => 'Batch destination']);
    $path = 'assets/Batch source/photo.png';
    Storage::disk(Directory::getAssetDisk())->put($path, 'copy-source');
    $asset = Asset::factory()->create(['file_name' => 'photo.png', 'path' => $path,
        'meta_data'                                => ['artist_note' => 'keep-active', 'stack_member_states' => ['foreign:sha' => ['artist_note' => 'source-only']]]]);
    $asset->directories()->attach($source->id);
    (new MassCopy(assetIds: $directoryCopy ? [] : [$asset->id], dirIds: $directoryCopy ? [$source->id] : [],
        targetId: $target->id, userId: auth('admin')->id()))->handle();
    $copy = Asset::where('id', '!=', $asset->id)->where('file_name', 'photo.png')->firstOrFail();
    expect($copy->meta_data)->toBe(['artist_note' => 'keep-active']);
})->with([false, true]);

it('mass copies directories to target by dispatching jobs', function () {
    Bus::fake();

    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => null]);
    $dir1 = Directory::factory()->create(['name' => 'DirA', 'parent_id' => null]);
    $dir2 = Directory::factory()->create(['name' => 'DirB', 'parent_id' => null]);

    $response = $this->postJson(route('admin.dam.explorer.mass_copy'), [
        'asset_ids'           => [],
        'directory_ids'       => [$dir1->id, $dir2->id],
        'target_directory_id' => $target->id,
    ]);

    $response->assertOk();
    Bus::assertDispatchedTimes(MassCopy::class, 1);
});

it('skips root directory (protected by isCopyable) in mass copy', function () {
    Bus::fake();

    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => null]);

    $response = $this->postJson(route('admin.dam.explorer.mass_copy'), [
        'asset_ids'           => [],
        'directory_ids'       => [$root->id],
        'target_directory_id' => $target->id,
    ]);

    $response->assertOk();
    Bus::assertNotDispatched(CopyDirectory::class);
});

it('returns 422 when target_directory_id is missing', function () {
    $response = $this->postJson(route('admin.dam.explorer.mass_copy'), [
        'asset_ids'     => [],
        'directory_ids' => [],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['target_directory_id']);
});

it('returns 422 when target_directory_id does not exist', function () {
    $response = $this->postJson(route('admin.dam.explorer.mass_copy'), [
        'asset_ids'           => [],
        'directory_ids'       => [],
        'target_directory_id' => 999999,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['target_directory_id']);
});

// ADR 0009 slice 3 (COPY-4X): the asset_ids eligibility filter used to gate on a physical file at
// the Library path (Storage::exists), which would silently drop every post-slice-3 asset. It now
// gates on a resolvable original FileObject instead.
it('queues only assets with a resolvable original FileObject, not ones gated on a physical file', function () {
    Bus::fake();
    Storage::fake(Directory::getAssetDisk());

    $target = Directory::factory()->create(['name' => 'MassCopyAssetTarget']);

    $disk = Directory::getAssetDisk();
    $withOriginalPath = 'assets/Root/has-original.jpg';
    Storage::disk($disk)->put($withOriginalPath, 'bytes');
    $withOriginal = Asset::factory()->create(['path' => $withOriginalPath]);

    // Never had bytes at its path, so the observer's legacy-ingest fallback never ran: no original.
    $withoutOriginal = Asset::factory()->create(['path' => 'assets/Root/no-original.jpg']);

    $response = $this->postJson(route('admin.dam.explorer.mass_copy'), [
        'asset_ids'           => [$withOriginal->id, $withoutOriginal->id],
        'directory_ids'       => [],
        'target_directory_id' => $target->id,
    ]);

    $response->assertOk();
    Bus::assertDispatched(MassCopy::class, function (MassCopy $job) use ($withOriginal, $withoutOriginal) {
        $assetIds = (fn () => $this->assetIds)->call($job);

        return in_array($withOriginal->id, $assetIds, true) && ! in_array($withoutOriginal->id, $assetIds, true);
    });
});
