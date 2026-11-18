<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Services\Share\SharePlayback;

/*
 * P2 S6: a public link plays the asset's playback source, the published proxy when there is one, through a
 * share-scoped proxy route that checks the link, availability and trash. The download stays the original.
 */

uses(DatabaseTransactions::class);

const S6_ORIGINAL_BYTES = 'original-master-video-bytes';

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
});

/** A 4K master whose bytes are ingested as its original (ADR 0009), like an upload. */
function s6Video(string $name = 'tour'): Asset
{
    $path = "assets/Root/{$name}.mp4";
    Storage::disk(Directory::getAssetDisk())->put($path, S6_ORIGINAL_BYTES);

    return Asset::factory()->create([
        'file_name' => "{$name}.mp4",
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => $path,
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160],
    ]);
}

/** Publishes a 1080p proxy through the real job, with ffmpeg and ffprobe faked (the VideoPreviewProxyJobTest pattern). */
function s6PublishProxy(Asset $asset): AssetFile
{
    (new class($asset->id) extends GenerateVideoPreviewProxy
    {
        protected function runFfmpeg(array $command): array
        {
            file_put_contents($command[count($command) - 1], "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200));

            return [true, ''];
        }

        protected function probe(string $path): array
        {
            return ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];
        }
    })->handle();

    $proxy = SharePlayback::proxyFile($asset->fresh());
    expect($proxy)->not->toBeNull();

    return $proxy;
}

/** The body a streamed response sent. */
function s6Body($response): string
{
    $base = $response->baseResponse ?? $response;

    return method_exists($base, 'getFile') ? (string) file_get_contents($base->getFile()->getPathname()) : (string) $base->getContent();
}

it('plays the published proxy on an asset link\'s page, and the original when there is none', function () {
    $withProxy = s6Video('with-proxy');
    s6PublishProxy($withProxy);
    $plain = s6Video('plain');

    $proxied = Share::factory()->forAsset($withProxy->id)->create();
    $original = Share::factory()->forAsset($plain->id)->create();

    $this->get(route('dam.share.show', $proxied->urlToken()))->assertOk()
        ->assertSee('media-url="'.e(route('dam.share.proxy', $proxied->urlToken())).'"', false)
        ->assertSee('data-dam-share-proxy', false)
        // The download button still hands out the original.
        ->assertSee('href="'.route('dam.share.download', $proxied->urlToken()).'"', false);

    $this->get(route('dam.share.show', $original->urlToken()))->assertOk()
        ->assertSee('media-url="'.e(route('dam.share.preview', $original->urlToken())).'"', false)
        ->assertDontSee('data-dam-share-proxy', false);

    // A link without a proxy has no proxy bytes to hand out, never the original in its place.
    $this->get(route('dam.share.proxy', $original->urlToken()))->assertNotFound();
});

it('plays the proxy on a folder link\'s member page too', function () {
    $asset = s6Video();
    s6PublishProxy($asset);
    $folder = Directory::create(['name' => 'S6 folder', 'parent_id' => null]);
    $asset->directories()->attach($folder->id);
    $link = Share::factory()->forDirectory($folder->id)->create();

    $proxyUrl = route('dam.share.asset_proxy', ['token' => $link->urlToken(), 'assetId' => $asset->id]);

    $this->get(route('dam.share.asset_view', ['token' => $link->urlToken(), 'assetId' => $asset->id]))->assertOk()
        ->assertSee('media-url="'.e($proxyUrl).'"', false);

    $this->get($proxyUrl)->assertOk()->assertHeader('Content-Type', 'video/mp4');
});

it('streams the proxy\'s own bytes with Range support, while the download stays the original', function () {
    $asset = s6Video();
    $proxy = s6PublishProxy($asset);
    $link = Share::factory()->forAsset($asset->id)->create();
    $proxyBytes = (string) Storage::disk($proxy->fileObject->disk)->get($proxy->fileObject->path);

    $full = $this->get(route('dam.share.proxy', $link->urlToken()))->assertOk();
    expect(s6Body($full))->toBe($proxyBytes)->not->toBe(S6_ORIGINAL_BYTES);

    $this->get(route('dam.share.proxy', $link->urlToken()), ['Range' => 'bytes=4-11'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 4-11/'.strlen($proxyBytes))
        ->assertHeader('Content-Length', '8');

    $download = $this->get(route('dam.share.download', $link->urlToken()))->assertOk();
    expect(s6Body($download))->toBe(S6_ORIGINAL_BYTES);

    // Playing the proxy is not a download.
    expect($link->fresh()->download_count)->toBe(1);
});

it('checks the link, the password, availability and trash on the proxy route', function () {
    $asset = s6Video();
    s6PublishProxy($asset);

    $expired = Share::factory()->forAsset($asset->id)->expired()->create();
    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create();
    $this->get(route('dam.share.proxy', $expired->urlToken()))->assertStatus(410);
    $this->get(route('dam.share.proxy', $revoked->urlToken()))->assertStatus(410);
    $this->get(route('dam.share.proxy', str_repeat('Z', 40)))->assertNotFound();

    // A password: refused without the unlock cookie, Range or not; opened with it.
    $locked = Share::factory()->forAsset($asset->id)->create(['password_hash' => Hash::make('s6-pass')]);
    $this->get(route('dam.share.proxy', $locked->urlToken()), ['Range' => 'bytes=0-3'])->assertStatus(401);
    $cookie = $this->post(route('dam.share.unlock', $locked->urlToken()), ['password' => 's6-pass'])->getCookie(ShareGate::COOKIE, decrypt: false)->getValue();
    $this->call('GET', route('dam.share.proxy', $locked->urlToken()), [], [ShareGate::COOKIE => $cookie], [], ['HTTP_RANGE' => 'bytes=0-3'])->assertStatus(206);

    // A preview-only link still plays it.
    $previewOnly = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $this->get(route('dam.share.proxy', $previewOnly->urlToken()))->assertOk();
    $this->get(route('dam.share.download', $previewOnly->urlToken()))->assertForbidden();

    $live = Share::factory()->forAsset($asset->id)->create();
    $this->get(route('dam.share.proxy', $live->urlToken()))->assertOk();

    // The decided status answers: a draft 404, an archived or out-of-window asset 410, a trashed one 410.
    foreach ([
        [['availability' => Asset::AVAILABILITY_DRAFT], 404],
        [['availability' => Asset::AVAILABILITY_ARCHIVED], 410],
        [['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_until' => now()->subMinute()], 410],
        [['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_until' => null, 'deleted_at' => now()], 410],
    ] as [$attributes, $status]) {
        DB::table('dam_assets')->where('id', $asset->id)->update($attributes);
        expect($this->get(route('dam.share.proxy', $live->urlToken()))->getStatusCode())->toBe($status, json_encode(array_keys($attributes)));
    }

    // A folder link's member that is not out for use is simply not in it.
    DB::table('dam_assets')->where('id', $asset->id)->update(['deleted_at' => null, 'availability' => Asset::AVAILABILITY_DRAFT]);
    $folder = Directory::create(['name' => 'S6 gated folder', 'parent_id' => null]);
    $asset->directories()->attach($folder->id);
    $folderLink = Share::factory()->forDirectory($folder->id)->create();
    $this->get(route('dam.share.asset_proxy', ['token' => $folderLink->urlToken(), 'assetId' => $asset->id]))->assertNotFound();
});

it('gives proxy seeking the media allowance, not the whole-file download budget', function () {
    $asset = s6Video();
    s6PublishProxy($asset);
    $link = Share::factory()->forAsset($asset->id)->create();

    $statuses = [];
    for ($i = 0; $i < 40; $i++) {
        $statuses[] = $this->get(route('dam.share.proxy', $link->urlToken()), ['Range' => 'bytes=0-7'])->getStatusCode();
    }

    expect(array_count_values($statuses))->toBe([206 => 40]);
});
