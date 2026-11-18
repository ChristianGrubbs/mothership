<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;

/*
 * P4 (D10): orientation. Ingest stores what the asset's dimensions describe as displayed (an EXIF quarter turn or a
 * rotated video swaps them); the Library filters on it; dam:orientation-backfill sets it for existing assets, with a
 * dry run that writes nothing.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->closed = Directory::factory()->create(['name' => 'Closed']);
    $this->member = organizeMember($this->open);
});

/** Runs the backfill, expects success, and returns its JSON report. */
function orientationBackfill(array $options): array
{
    expect(Artisan::call('dam:orientation-backfill', $options))->toBe(0);
    $output = Artisan::output();

    return json_decode(substr($output, 0, strrpos($output, '}') + 1), true);
}

it('stores the displayed orientation at ingest and when the metadata changes', function () {
    $wide = organizeAsset($this->open, 'wide', size: [1600, 900]);
    $tall = organizeAsset($this->open, 'tall', size: [900, 1600]);
    $square = organizeAsset($this->open, 'square', size: [1000, 1004]);
    $turned = organizeAsset($this->open, 'turned', ['meta_data' => ['ImageWidth' => 1600, 'ImageHeight' => 900, 'Orientation' => 6]]);
    $rotatedVideo = organizeAsset($this->open, 'phone', ['file_type' => 'video', 'extension' => 'mp4', 'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'Rotation' => 90]]);
    $unknown = organizeAsset($this->open, 'unknown');
    $document = organizeAsset($this->open, 'doc', ['file_type' => 'document', 'extension' => 'pdf', 'meta_data' => ['ImageWidth' => 1600, 'ImageHeight' => 900]]);

    expect([$wide, $tall, $square, $turned, $rotatedVideo, $unknown, $document])->each(fn ($asset) => $asset->value->refresh())
        ->and([$wide->orientation, $tall->orientation, $square->orientation, $turned->orientation, $rotatedVideo->orientation, $unknown->orientation, $document->orientation])
        ->toBe(['landscape', 'portrait', 'square', 'portrait', 'portrait', null, null]);

    $unknown->update(['meta_data' => ['ImageWidth' => 300, 'ImageHeight' => 200]]);
    expect($unknown->refresh()->orientation)->toBe('landscape');
});

it('reads the original\'s measured dimensions when the metadata has none', function () {
    $asset = organizeAsset($this->open, 'measured');
    attachPreviewFile($asset, $asset->path, 'image/jpeg', AssetFile::ROLE_ORIGINAL, technical: ['width' => 500, 'height' => 800]);

    $asset->update(['meta_data' => ['Make' => 'Camera']]);

    expect($asset->refresh()->orientation)->toBe('portrait');
});

it('filters by orientation, alone and with a search, never listing a hidden file', function () {
    $wideRed = organizeAsset($this->open, 'red-wide', size: [1600, 900]);
    $wideBlue = organizeAsset($this->open, 'blue-wide', size: [1200, 800]);
    $tallRed = organizeAsset($this->open, 'red-tall', size: [900, 1600]);
    $square = organizeAsset($this->open, 'tile', size: [500, 500]);
    organizeAsset($this->closed, 'red-hidden', size: [1600, 900]);

    expect(organizeListedIds(['filter_orientation' => 'landscape']))->toEqualCanonicalizing([$wideRed->id, $wideBlue->id])
        ->and(organizeListedIds(['filter_orientation' => 'portrait']))->toBe([$tallRed->id])
        ->and(organizeListedIds(['filter_orientation' => 'square']))->toBe([$square->id])
        ->and(organizeListedIds(['filter_orientation' => 'landscape', 'search' => 'red']))->toBe([$wideRed->id]);

    // With labels and favorites too.
    $label = (int) $this->postJson(route('admin.dam.labels.store'), ['name' => 'Hero'])->json('data.id');
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$wideRed->id, $tallRed->id]])->assertOk();
    DB::table('dam_asset_favorites')->insert(['admin_id' => $this->member->id, 'dam_asset_id' => $wideRed->id, 'created_at' => now()]);

    expect(organizeListedIds(['filter_orientation' => 'landscape', 'filter_labels' => [$label]]))->toBe([$wideRed->id])
        ->and(organizeListedIds(['filter_orientation' => 'portrait', 'filter_favorites' => 1]))->toBe([])
        ->and(organizeListedIds(['filter_orientation' => 'landscape', 'filter_favorites' => 1, 'filter_labels' => [$label], 'search' => 'red']))->toBe([$wideRed->id]);

    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'filter_orientation' => 'diagonal']))->assertUnprocessable();
});

it('backfills existing assets: the dry run reports and writes nothing, apply writes and reconciles', function () {
    $wide = organizeAsset($this->open, 'wide', size: [1600, 900]);
    $tall = organizeAsset($this->open, 'tall', size: [900, 1600]);
    $measured = organizeAsset($this->open, 'measured');
    attachPreviewFile($measured, $measured->path, 'image/jpeg', AssetFile::ROLE_ORIGINAL, technical: ['width' => 640, 'height' => 640]);
    $trashed = organizeAsset($this->open, 'trashed', size: [100, 300]);
    $trashed->delete();
    $stale = organizeAsset($this->open, 'stale');
    // As the rows stood before this release: no orientation, and one wrong value.
    DB::table('dam_assets')->update(['orientation' => null]);
    DB::table('dam_assets')->where('id', $stale->id)->update(['orientation' => 'landscape']);

    $dry = orientationBackfill(['--dry-run' => true]);

    expect($dry)->toMatchArray(['dry_run' => true, 'set' => 4, 'would_set' => 4, 'would_clear' => 1, 'violations' => []])
        ->and($dry['would_be'])->toMatchArray(['landscape' => 1, 'portrait' => 2, 'square' => 1])
        ->and($dry['after'])->toBe($dry['before'])
        ->and(DB::table('dam_assets')->whereNotNull('orientation')->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$stale->id]);

    $applied = orientationBackfill(['--apply' => true]);

    expect($applied)->toMatchArray(['dry_run' => false, 'set' => 4, 'cleared' => 1, 'violations' => []])
        ->and(Asset::withTrashed()->whereIn('id', [$wide->id, $tall->id, $measured->id, $trashed->id, $stale->id])->orderBy('id')->pluck('orientation')->all())
        ->toBe(['landscape', 'portrait', 'square', 'portrait', null]);

    // Idempotent.
    $again = orientationBackfill(['--apply' => true]);
    expect($again)->toMatchArray(['set' => 0, 'cleared' => 0]);

    expect(Artisan::call('dam:orientation-backfill'))->not->toBe(0);
});
