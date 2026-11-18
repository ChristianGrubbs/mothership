<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\PanoramaPreviewService;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

/** A video with competing attachments inserted out of sort order, one missing blob and three kinds of property. */
function showFixtureAsset(): Asset
{
    $dir = Directory::factory()->create(['parent_id' => null]);
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'path' => 'assets/Root/clip.mp4',
    ]);
    $asset->directories()->attach($dir->id);

    // Inserted out of sort order on purpose; two proxies tie on sort so the id decides.
    attachPreviewFile($asset, 'assets/Root/clip-original.mp4', 'video/mp4', AssetFile::ROLE_ORIGINAL, 'Original', 5, ['codec_name' => 'h264']);
    attachPreviewFile($asset, 'assets/Root/commentary.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'Commentary', 10);
    attachPreviewFile($asset, 'assets/Root/proxy-a.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Proxy A', 3, ['codec_name' => 'h264']);
    $missing = attachPreviewFile($asset, 'assets/Root/gone.vtt', 'text/vtt', AssetFile::ROLE_SUBTITLE, 'Gone', -5);
    attachPreviewFile($asset, 'assets/Root/proxy-b.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Proxy B', 3, ['codec_name' => 'h264']);
    attachPreviewFile($asset, 'assets/Root/english.vtt', 'text/vtt', AssetFile::ROLE_SUBTITLE, 'English', 0);
    Storage::disk(Directory::getAssetDisk())->delete($missing->fileObject->path);

    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Campus', 'type' => 'Text', 'value' => 'Main']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Approved', 'type' => 'Boolean', 'value' => '1']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Notes', 'type' => 'Textarea', 'value' => 'Free text, with "quotes" and commas.']);

    return $asset;
}

// The preview payload (assets/show) reads the asset's attachments, renditions and counts through many helpers; they
// must share one attachment read instead of each running their own (perf pass 2026-10-19, LB-02/D5).
it('reads the attachment rows of a shown asset a bounded number of times and keeps them out of the payload', function (Closure $make, int $cap) {
    $asset = $make();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->getJson(route('admin.dam.assets.show', $asset->id));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $response->assertOk()->assertJsonMissingPath('asset.files');
    $reads = $queries->filter(fn (string $sql) => preg_match('/^select \* from [`"]?dam_asset_files[`"]?/i', $sql) === 1)->count();

    expect($reads)->toBeLessThanOrEqual($cap);
})->with([
    // The parent code read the rows 7 times for a plain image and 5 for the fixture video.
    'plain image'     => [fn () => tap(Asset::factory()->create(), fn (Asset $asset) => $asset->directories()->attach(Directory::factory()->create(['parent_id' => null])->id)), 3],
    'competing files' => [fn () => showFixtureAsset(), 4],
]);

it('resolves playback, captions and counts for competing attachments exactly as an unloaded asset does', function () {
    $asset = showFixtureAsset();
    $fresh = Asset::find($asset->id);

    $response = $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonMissingPath('asset.files');

    $english = $asset->files()->where('label', 'English')->firstOrFail();

    expect($fresh->playbackSource())->not->toBeNull()
        // The first sorted caption that has its blob wins: the missing one (sort -5) and the later sidecar lose.
        ->and($fresh->captionSource()['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $english->id]))
        ->and($response->json('playbackSource'))->toBe($fresh->playbackSource())
        ->and($response->json('captionSource'))->toBe($fresh->captionSource())
        ->and($response->json('badgeCounts.properties'))->toBe($fresh->properties()->count())->toBe(3)
        ->and($response->json('badgeCounts.files'))->toBe($fresh->captureFileCount());

    // The loaded relation the controller builds gives the same answers as the per-call queries.
    $loaded = Asset::find($asset->id);
    $loaded->load(['files' => fn ($files) => $files->orderBy('id'), 'files.fileObject']);
    expect($loaded->playbackSource())->toBe($fresh->playbackSource())
        ->and($loaded->captionSource())->toBe($fresh->captionSource())
        ->and(app(ContentStore::class)->current($loaded)->getKey())->toBe(app(ContentStore::class)->current($fresh)->getKey());
});

it('hands the panorama state the playback source it was given, or resolves it, or accepts a resolved null', function () {
    $asset = showFixtureAsset();
    $service = app(PanoramaPreviewService::class);
    $own = $asset->playbackSource();
    $given = ['url' => 'https://example.test/given', 'mime' => 'video/mp4', 'kind' => 'video', 'label' => 'Given'];

    expect($own)->not->toBeNull()
        // Omitted: resolved from the asset.
        ->and($service->state($asset)['playback'])->toBe($own)
        // Supplied: used as given.
        ->and($service->state($asset, $given)['playback'])->toBe($given)
        // A null that was not declared resolved is still resolved from the asset (other callers' contract)...
        ->and($service->state($asset, null)['playback'])->toBe($own)
        // ...but a caller that already asked and got "nothing plays" is not asked to compute it again.
        ->and($service->state($asset, null, playbackResolved: true)['playback'])->toBeNull()
        ->and($service->payload($asset, null, playbackResolved: true)['state']['playback'])->toBeNull();
});
