<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
});

function makeVideoOfHeight(int $height): Asset
{
    $asset = Asset::factory()->create([
        'file_name'     => "clip-{$height}.mp4",
        'file_type'     => 'video',
        'mime_type'     => 'video/mp4',
        'extension'     => 'mp4',
        'path'          => "assets/Root/clip-{$height}.mp4",
        'panorama_mode' => Asset::PANORAMA_FORCE_FLAT,
        'meta_data'     => ['ImageWidth' => (int) round($height * 16 / 9), 'ImageHeight' => $height],
    ]);

    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'original-video');

    return $asset;
}

it('queues a proxy for oversized videos and skips 1080p ones', function () {
    makeVideoOfHeight(2160);
    makeVideoOfHeight(1080);

    $this->artisan('dam:generate-video-previews')
        ->expectsOutputToContain('queued 1')
        ->assertExitCode(0);

    Queue::assertPushedOn('video-preview', GenerateVideoPreviewProxy::class);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
});

it('queues nothing on a dry run', function () {
    makeVideoOfHeight(2160);

    $this->artisan('dam:generate-video-previews --dry-run')
        ->expectsOutputToContain('would queue 1')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('honours the limit', function () {
    makeVideoOfHeight(1080);            // Skipped control: at max unproxied height, browser-playable
    $first = makeVideoOfHeight(4320);   // Eligible — the one --limit=1 must queue
    makeVideoOfHeight(2160);            // Eligible too — only the limit keeps it out

    $this->artisan('dam:generate-video-previews --limit=1')->assertExitCode(0);

    Queue::assertPushedOn('video-preview', GenerateVideoPreviewProxy::class);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, function (GenerateVideoPreviewProxy $job) use ($first) {
        return (new ReflectionProperty($job, 'assetId'))->getValue($job) === $first->id;
    });
});

it('skips oversized videos with existing finished proxy without --force', function () {
    $asset = makeVideoOfHeight(2160);

    // Create a DONE rendition for this asset
    Rendition::factory()->create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_DONE,
    ]);

    $this->artisan('dam:generate-video-previews')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('queues with --force even when finished proxy exists', function () {
    $asset = makeVideoOfHeight(2160);

    // Create a DONE rendition for this asset
    Rendition::factory()->create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_DONE,
    ]);

    $this->artisan('dam:generate-video-previews --force')
        ->assertExitCode(0);

    Queue::assertPushed(GenerateVideoPreviewProxy::class, fn ($job) => $job->force === true);
});

it('handles real-world asset shapes: empty metadata and browser-unplayable formats', function () {
    // Empty meta_data video → should QUEUE (unknown height)
    $emptyMetaVideo = Asset::factory()->create([
        'file_name'     => 'unknown-size.mp4',
        'file_type'     => 'video',
        'mime_type'     => 'video/mp4',
        'extension'     => 'mp4',
        'path'          => 'assets/Root/unknown-size.mp4',
        'panorama_mode' => Asset::PANORAMA_FORCE_FLAT,
        'meta_data'     => [],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($emptyMetaVideo->path, 'original-video');

    // 1080p ProRes MOV (not browser-playable) → should QUEUE
    $proresToVideo = Asset::factory()->create([
        'file_name'     => 'prores-1080.mov',
        'file_type'     => 'video',
        'mime_type'     => 'video/quicktime',
        'extension'     => 'mov',
        'path'          => 'assets/Root/prores-1080.mov',
        'panorama_mode' => Asset::PANORAMA_FORCE_FLAT,
        'meta_data'     => [
            'ImageWidth'  => 1920,
            'ImageHeight' => 1080,
            'codec_name'  => 'prores',
        ],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($proresToVideo->path, 'original-video');

    $this->artisan('dam:generate-video-previews')
        ->expectsOutputToContain('queued 2')
        ->assertExitCode(0);

    Queue::assertPushedOn('video-preview', GenerateVideoPreviewProxy::class);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 2);
});

it('queues an oversized video but skips a genuine panorama and ignores an image asset', function () {
    // Control: an ordinary oversized video that must be queued.
    $control = makeVideoOfHeight(2160);

    // A genuine 4K video panorama (file_type video, panorama_mode auto, ratio
    // 2.0 falls inside PanoramaClassifier's 1.95-2.05 equirectangular band —
    // same shape as VideoPreviewProfileTest's panorama fixture). Must NOT be
    // queued, and this asserts it is actually classified as a panorama so the
    // fixture can't silently regress to flat.
    $panorama = Asset::factory()->create([
        'file_name'     => 'immersive-4k.mp4',
        'file_type'     => 'video',
        'mime_type'     => 'video/mp4',
        'extension'     => 'mp4',
        'path'          => 'assets/Root/immersive-4k.mp4',
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => ['ImageWidth' => 4096, 'ImageHeight' => 2048],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($panorama->path, 'original-video');

    expect($panorama->panoramaClassification()['is_panorama'])->toBeTrue();

    // A large image asset. The command only walks file_type = 'video', so
    // this must never be pushed or counted at all.
    $image = Asset::factory()->create([
        'file_name' => 'huge-photo.jpg',
        'file_type' => 'image',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'path'      => 'assets/Root/huge-photo.jpg',
        'meta_data' => ['ImageWidth' => 6000, 'ImageHeight' => 4000],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($image->path, 'original-image');

    // The command's summary is a single console line ("queued N, skipped
    // M."), so Artisan::call()+output() is used instead of the artisan()
    // test helper's expectsOutputToContain(), which matches each expectation
    // against its own output line and fails past the first when a command
    // only ever prints one line.
    $exitCode = Artisan::call('dam:generate-video-previews');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('queued 1')
        ->and($output)->toContain('skipped 1');

    Queue::assertPushedOn('video-preview', GenerateVideoPreviewProxy::class);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, function (GenerateVideoPreviewProxy $job) use ($control) {
        $assetId = new ReflectionProperty($job, 'assetId');
        $assetId->setAccessible(true);

        return $assetId->getValue($job) === $control->id;
    });
});
