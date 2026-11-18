<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\VideoProxyStatus;

/*
 * P6 D26: the preview proxy's status on the asset page, the Library badge and the API; Retry (edit-gated, throttled,
 * idempotent while a job waits); and "Web MP4 (1080p)", the published proxy through the authorized download path.
 */

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    config(['mothership.video_preview.enabled' => true, 'mothership.media_accel_prefix' => null, 'app.debug' => false]);
});

if (! function_exists('p6vVideo')) {
    /** A 1080p H.264 MP4 the browser plays as it is, in a folder, with its original bytes in place. */
    function p6vVideo(array $attributes = [], string $bytes = 'ORIGINAL-VIDEO-BYTES'): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'file_name' => 'clip-'.uniqid().'.mp4',
            'file_type' => 'video',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'codec_name' => 'h264'],
        ], $attributes));
        Directory::factory()->create()->assets()->attach($asset->id);

        return putAssetContent($asset, $bytes);
    }
}

if (! function_exists('p6vPublishProxy')) {
    /** A published 1080p preview proxy: the blob, its proxy attachment and the finished rendition naming it. */
    function p6vPublishProxy(Asset $asset, string $bytes = 'PROXY-MP4-BYTES'): AssetFile
    {
        $sha = hash('sha256', $bytes.'|'.$asset->id);
        $object = FileObject::factory()->create([
            'sha256'    => $sha,
            'size'      => strlen($bytes),
            'disk'      => Directory::getAssetDisk(),
            'path'      => storeFixtureBlob($sha, $bytes, 'mp4'),
            'mime_type' => 'video/mp4',
            'technical' => ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
        ]);
        $file = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_PROXY, 'label' => 'Preview (1080p)', 'sort' => 900]);
        Rendition::factory()->create([
            'dam_asset_id'          => $asset->id,
            'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
            'status'                => Rendition::STATUS_DONE,
            'output_file_object_id' => $object->id,
            'finished_at'           => now(),
        ]);

        return $file;
    }
}

/** A 4K master: it needs a proxy, and has none yet. */
function p6vMaster(): Asset
{
    return p6vVideo(['meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160, 'codec_name' => 'h264']], 'MASTER-4K-BYTES');
}

/** The proxy row a worker writes when it picks the job up, and later finishes. */
function p6vProxyRow(Asset $asset, string $status, array $attributes = []): Rendition
{
    return Rendition::factory()->create(array_merge([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => $status,
        'started_at'   => now(),
    ], $attributes));
}

/** The status as all three readers see it: the state route, the asset page's show() and the REST asset read. */
function p6vStatusEverywhere(Asset $asset, array $apiHeaders): array
{
    $state = test()->getJson(route('admin.dam.assets.video-proxy.state', $asset->id))->assertOk()->json('data.status');
    $page = test()->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->json('videoProxy.status');
    $api = test()->withHeaders($apiHeaders)->getJson(route('admin.api.dam.assets.show', $asset->id))->assertOk()->json('data.video_proxy.status');

    return [$state, $page, $api];
}

it('refuses Retry without edit rights, and answers a hidden asset like a missing one', function () {
    $granted = Directory::factory()->create();
    $visible = p6vMaster();
    $granted->assets()->attach($visible->id);
    $hidden = p6vMaster();
    $missingId = Asset::max('id') + 1000;

    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view']);

    $this->getJson(route('admin.dam.assets.video-proxy.state', $visible->id))->assertOk()
        ->assertJsonPath('data.status', VideoProxyStatus::MISSING)
        ->assertJsonPath('data.can_retry', false);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $visible->id))->assertForbidden();

    // With the edit permission, an asset outside the member's folders is not there at all.
    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit']);
    $hiddenAnswer = $this->postJson(route('admin.dam.assets.video-proxy.retry', $hidden->id));
    $missingAnswer = $this->postJson(route('admin.dam.assets.video-proxy.retry', $missingId));
    expect($hiddenAnswer->getStatusCode())->toBe(404)
        ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
    $this->getJson(route('admin.dam.assets.video-proxy.state', $hidden->id))->assertNotFound();

    Queue::assertNothingPushed();
});

it('queues one job however often Retry is pressed while it waits', function () {
    $asset = p6vMaster();

    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202)
        ->assertJsonPath('outcome', 'queued')
        ->assertJsonPath('data.status', VideoProxyStatus::QUEUED)
        ->assertJsonPath('data.can_retry', false);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202)->assertJsonPath('outcome', 'already_queued');

    // Once the worker has written its running row, Retry still queues nothing.
    p6vProxyRow($asset, Rendition::STATUS_RUNNING);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202)
        ->assertJsonPath('outcome', 'already_queued')
        ->assertJsonPath('data.status', VideoProxyStatus::PROCESSING);

    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
});

it('throttles Retry per person and asset', function () {
    $asset = p6vMaster();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202);
    }

    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(429);

    // Another asset has its own allowance.
    $this->postJson(route('admin.dam.assets.video-proxy.retry', p6vMaster()->id))->assertStatus(202);
});

it('shows missing, queued, processing, failed with its reason, queued again and ready, on every reader', function () {
    $asset = p6vMaster();
    $api = $this->getAuthenticationHeaders();

    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::MISSING));
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertJsonPath('videoProxy.can_retry', true);

    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202);
    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::QUEUED));

    $row = p6vProxyRow($asset, Rendition::STATUS_RUNNING);
    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::PROCESSING));

    $row->update(['status' => Rendition::STATUS_FAILED, 'error' => 'ffmpeg_failed', 'finished_at' => now()]);
    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::FAILED));
    $this->getJson(route('admin.dam.assets.video-proxy.state', $asset->id))
        ->assertJsonPath('data.error', 'ffmpeg_failed')
        ->assertJsonPath('data.reason', trans('dam::app.admin.dam.asset.video-proxy.reasons.ffmpeg-failed'))
        ->assertJsonPath('data.can_retry', true);

    // Retry after a failure through the API: queued again, without forcing (there is no finished proxy to replace).
    $this->withHeaders($api)->postJson(route('admin.api.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202)->assertJsonPath('outcome', 'queued');
    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::QUEUED));
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 2);

    p6vPublishProxy($asset);
    expect(p6vStatusEverywhere($asset, $api))->toBe(array_fill(0, 3, VideoProxyStatus::READY));
});

it('reads a running row its worker abandoned as failed, so Retry is offered', function () {
    $asset = p6vMaster();
    p6vProxyRow($asset, Rendition::STATUS_RUNNING, ['started_at' => now()->subSeconds(GenerateVideoPreviewProxy::STALE_RUNNING_AFTER + 60)]);

    $this->getJson(route('admin.dam.assets.video-proxy.state', $asset->id))
        ->assertJsonPath('data.status', VideoProxyStatus::FAILED)
        ->assertJsonPath('data.reason', trans('dam::app.admin.dam.asset.video-proxy.reasons.timed-out'));
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $asset->id))->assertStatus(202)->assertJsonPath('outcome', 'queued');
});

it('has nothing to retry when the proxy is ready, not needed or generation is off', function () {
    $ready = p6vMaster();
    p6vPublishProxy($ready);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $ready->id))->assertStatus(409)->assertJsonPath('outcome', 'not_retryable');

    // A 1080p H.264 MP4 plays as it is.
    $small = p6vVideo();
    $this->getJson(route('admin.dam.assets.video-proxy.state', $small->id))->assertJsonPath('data.status', VideoProxyStatus::NOT_NEEDED);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $small->id))->assertStatus(409)->assertJsonPath('outcome', 'not_retryable');

    config(['mothership.video_preview.enabled' => false]);
    $master = p6vMaster();
    $this->getJson(route('admin.dam.assets.video-proxy.state', $master->id))->assertJsonPath('data.retryable', false);
    $this->postJson(route('admin.dam.assets.video-proxy.retry', $master->id))->assertStatus(409)->assertJsonPath('outcome', 'disabled');

    Queue::assertNothingPushed();
});

it('badges Library video cards only while their proxy is queued, processing or failed', function () {
    $folder = Directory::factory()->create();
    [$queued, $processing, $failed, $ready, $quiet] = [p6vMaster(), p6vMaster(), p6vMaster(), p6vMaster(), p6vMaster()];
    $folder->assets()->attach([$queued->id, $processing->id, $failed->id, $ready->id, $quiet->id]);

    $this->postJson(route('admin.dam.assets.video-proxy.retry', $queued->id))->assertStatus(202);
    p6vProxyRow($processing, Rendition::STATUS_RUNNING);
    p6vProxyRow($failed, Rendition::STATUS_FAILED, ['error' => 'ffmpeg_failed']);
    p6vPublishProxy($ready);

    $badges = collect($this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id]))->assertOk()->json('assets'))
        ->mapWithKeys(fn (array $asset) => [$asset['id'] => $asset['proxy_status']])->all();

    $expected = [
        $queued->id     => VideoProxyStatus::QUEUED,
        $processing->id => VideoProxyStatus::PROCESSING,
        $failed->id     => VideoProxyStatus::FAILED,
        $ready->id      => null,
        $quiet->id      => null,
    ];
    expect(array_intersect_key($badges, $expected))->toEqual($expected);

    // The Library grid (the datagrid gallery) carries the same badges, with their words.
    $grid = collect($this->getJson(route('admin.dam.assets.index', ['filters' => ['directory_id' => [$folder->id]]]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->json('records'))->keyBy('id');
    expect($grid->map(fn (array $record) => $record['proxy_status'])->only(array_keys($expected))->all())->toEqual($expected)
        ->and($grid[$failed->id]['proxy_status_label'])->toBe(trans('dam::app.admin.dam.asset.video-proxy.badges.failed'));
});

it('offers Web MP4 only when a proxy is published, and serves it through the authorized download', function () {
    $asset = p6vMaster();

    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonPath('webMp4Url', null);
    $this->get(route('admin.dam.assets.download', ['id' => $asset->id, 'variant' => 'web_mp4']))->assertNotFound();

    p6vPublishProxy($asset, 'PROXY-1080P-BYTES');

    $url = $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->json('webMp4Url');
    expect($url)->toBe(route('admin.dam.assets.download', ['id' => $asset->id, 'variant' => 'web_mp4']));

    $download = $this->get($url)->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($download->headers->get('Content-Disposition'))->toContain(pathinfo($asset->file_name, PATHINFO_FILENAME).'-1080p.mp4');
    ob_start();
    $download->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('PROXY-1080P-BYTES');

    // The plain download still hands out the original.
    $original = $this->get(route('admin.dam.assets.download', $asset->id))->assertOk();
    ob_start();
    $original->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('MASTER-4K-BYTES');
});

it('refuses Web MP4 of a hidden asset exactly like a missing one', function () {
    $granted = Directory::factory()->create();
    $hidden = p6vMaster();
    p6vPublishProxy($hidden);
    $missingId = Asset::max('id') + 1000;

    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download']);

    $hiddenAnswer = $this->get(route('admin.dam.assets.download', ['id' => $hidden->id, 'variant' => 'web_mp4']));
    $missingAnswer = $this->get(route('admin.dam.assets.download', ['id' => $missingId, 'variant' => 'web_mp4']));

    expect($hiddenAnswer->getStatusCode())->toBe(404)
        ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
});

it('hands out Web MP4 through the REST download link, and 404s when there is no proxy', function () {
    $asset = p6vMaster();
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', ['id' => $asset->id, 'variant' => 'web_mp4']))->assertNotFound();

    p6vPublishProxy($asset, 'PROXY-VIA-API');

    $link = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', ['id' => $asset->id, 'variant' => 'web_mp4']))
        ->assertOk()->json('data.download_url');
    expect($link)->toContain('variant=web_mp4');

    $served = $this->get($link)->assertOk();
    ob_start();
    $served->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('PROXY-VIA-API');

    // The link is signed: changing the variant breaks it.
    $this->get(str_replace('variant=web_mp4', 'variant=original', $link))->assertForbidden();
});

it('plays the published proxy in the Library preview and on the asset page', function () {
    $asset = p6vMaster();
    $proxy = p6vPublishProxy($asset);

    $source = $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->json('playbackSource');

    expect($source['is_proxy'])->toBeTrue()
        ->and($source['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]));

    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertSee('"playbackIsProxy":true', escape: false);
});
