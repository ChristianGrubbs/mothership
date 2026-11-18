<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Database\Backfill\AttachmentSlotBackfill;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;

/*
 * P3a S2: every dam_asset_files row is a revision of an attachment slot. The backfill turns the old
 * shapes (a demoted original, a blob the old observer deduped, loose roles) into slots without losing
 * a row, and new writers land in slots on their own.
 */

beforeEach(function () {
    $this->loginAsAdmin();
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'poster.png', 'file_type' => 'image', 'extension' => 'png']));
});

/** A pre-S2 row: raw SQL, so it has no slot, exactly like data written before the migration. */
$legacyRow = function (int $assetId, string $role, ?FileObject $object = null): int {
    return DB::table('dam_asset_files')->insertGetId([
        'dam_asset_id'   => $assetId,
        'file_object_id' => ($object ?? FileObject::factory()->create())->id,
        'role'           => $role,
        'sort'           => 0,
        'created_at'     => now(),
    ]);
};

$legacyVersion = fn (int $assetId, int $number, int $fileId) => DB::table('dam_asset_versions')->insertGetId([
    'dam_asset_id' => $assetId, 'number' => $number, 'primary_asset_file_id' => $fileId, 'created_at' => now(),
]);

$revisions = fn (int $assetId) => DB::table('dam_asset_files')->where('dam_asset_id', $assetId)
    ->orderBy('attachment_id')->orderBy('revision_number')
    ->get(['id', 'file_object_id', 'role', 'attachment_id', 'revision_number', 'is_current']);

it('turns a demoted original into revision 1 of the representative slot', function () use ($legacyRow, $legacyVersion, $revisions) {
    $a = $legacyRow($this->asset->id, 'version');
    $b = $legacyRow($this->asset->id, 'original');
    $legacyVersion($this->asset->id, 1, $b);

    $report = app(AttachmentSlotBackfill::class)->run(assetIds: [$this->asset->id]);

    $rows = $revisions($this->asset->id);
    expect($rows->pluck('id')->all())->toBe([$a, $b])
        ->and($rows->pluck('revision_number')->all())->toBe([1, 2])
        ->and($rows->pluck('role')->all())->toBe(['version', 'original'])
        ->and($rows->pluck('is_current')->map(fn ($v) => (bool) $v)->all())->toBe([false, true])
        ->and($rows->pluck('attachment_id')->unique()->count())->toBe(1)
        ->and($report)->toMatchArray(['rows_before' => 2, 'rows_after' => 2, 'rows_inserted' => 0, 'slots_created' => 1, 'violations' => []]);
    expect(Attachment::findOrFail($rows[0]->attachment_id)->only(['role', 'is_representative', 'position']))
        ->toBe(['role' => 'original', 'is_representative' => true, 'position' => 0]);
});

it('restores a blob the old dedupe collapsed: A to B to A becomes three revisions and each version keeps its revision', function () use ($legacyRow, $legacyVersion, $revisions) {
    $blobA = FileObject::factory()->create();
    $a = $legacyRow($this->asset->id, 'original', $blobA);
    $b = $legacyRow($this->asset->id, 'version');
    $v1 = $legacyVersion($this->asset->id, 1, $b);
    $v2 = $legacyVersion($this->asset->id, 2, $a);

    $report = app(AttachmentSlotBackfill::class)->run(assetIds: [$this->asset->id]);

    $rows = $revisions($this->asset->id);
    expect($rows->pluck('revision_number')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('file_object_id')->all())->toBe([$blobA->id, $rows[1]->file_object_id, $blobA->id])
        ->and($rows[1]->id)->toBe($b)
        ->and($rows[2]->id)->toBe($a)
        ->and($rows->pluck('role')->all())->toBe(['version', 'version', 'original'])
        ->and($report['rows_inserted'])->toBe(1);
    expect(DB::table('dam_asset_versions')->whereIn('id', [$v1, $v2])->orderBy('number')->pluck('primary_asset_file_id')->all())
        ->toBe([$b, $a]);
});

it('gives every other role its own slot and reports history rows no version names', function () use ($legacyRow, $revisions) {
    $old = $legacyRow($this->asset->id, 'version');
    $original = $legacyRow($this->asset->id, 'original');
    $alternate = $legacyRow($this->asset->id, 'alternate');
    $sidecar = $legacyRow($this->asset->id, 'sidecar');

    $report = app(AttachmentSlotBackfill::class)->run(assetIds: [$this->asset->id]);

    $rows = $revisions($this->asset->id)->keyBy('id');
    expect($rows[$old]->attachment_id)->toBe($rows[$original]->attachment_id)
        ->and([$rows[$old]->revision_number, $rows[$original]->revision_number])->toBe([1, 2])
        ->and([$rows[$alternate]->revision_number, (bool) $rows[$alternate]->is_current])->toBe([1, true])
        ->and($rows->pluck('attachment_id')->unique()->count())->toBe(3)
        ->and($report['unchained_version_rows'])->toBe([$this->asset->id => [$old]]);
    expect(Attachment::where('dam_asset_id', $this->asset->id)->orderBy('position')->pluck('role')->all())
        ->toBe(['original', 'alternate', 'sidecar']);
});

it('writes nothing on a dry run and nothing more on a second real run', function () use ($legacyRow) {
    $legacyRow($this->asset->id, 'original');
    $legacyRow($this->asset->id, 'alternate');

    expect(Artisan::call('dam:attachments-migrate', ['--dry-run' => true, '--asset' => [$this->asset->id]]))->toBe(0)
        ->and(Attachment::where('dam_asset_id', $this->asset->id)->count())->toBe(0);

    expect(Artisan::call('dam:attachments-migrate', ['--asset' => [$this->asset->id]]))->toBe(0)
        ->and(Attachment::where('dam_asset_id', $this->asset->id)->count())->toBe(2);

    $again = app(AttachmentSlotBackfill::class)->run(assetIds: [$this->asset->id]);
    expect($again['assets_seen'])->toBe(0)
        ->and(Attachment::where('dam_asset_id', $this->asset->id)->count())->toBe(2);
});

it('lands new rows in slots: an attach opens one, a version joins the representative, and only current revisions are listed', function () {
    Storage::fake(Directory::getAssetDisk());
    AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'original']);
    AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'version']);
    $url = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files';

    $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'role' => 'sidecar'])->assertCreated();
    $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('old.txt', 'old'), 'role' => 'version'])->assertUnprocessable();

    $slots = Attachment::where('dam_asset_id', $this->asset->id)->orderBy('position')->get();
    expect($slots->pluck('role')->all())->toBe(['original', 'sidecar'])
        ->and($slots[0]->revisions()->pluck('is_current')->all())->toBe([true, false])
        ->and(collect($this->getJson($url)->assertOk()->json('files'))->pluck('role')->sort()->values()->all())->toBe(['original', 'sidecar']);
});

it('gives a mass-copied asset its own representative slot', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    $source = Directory::factory()->create(['name' => 'Slots source '.uniqid(), 'parent_id' => null]);
    $target = Directory::factory()->create(['name' => 'Slots target '.uniqid(), 'parent_id' => null]);
    Storage::disk($disk)->put('assets/Slots/menu.png', 'menu-bytes');
    $asset = Asset::factory()->create(['file_name' => 'menu.png', 'path' => 'assets/Slots/menu.png']);
    $asset->directories()->attach($source->id);

    (new MassCopy([$asset->id], [], $target->id, auth()->guard('admin')->id()))->handle();

    $copy = Asset::whereHas('directories', fn ($q) => $q->whereKey($target->id))->sole();
    $original = $copy->files()->where('role', 'original')->sole();
    expect($original->attachment_id)->not->toBeNull()
        ->and($original->attachment->only(['dam_asset_id', 'is_representative']))->toBe(['dam_asset_id' => $copy->id, 'is_representative' => true]);
});
