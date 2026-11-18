<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Providers\DAMServiceProvider;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\Share\SharePlayback;
use Mothership\DAM\Support\DerivativeImages;

uses(DatabaseTransactions::class);

// Large enough that a byte range is a real subset of the file.
const SHARED_MEDIA_FIXTURE_BYTES = 262144;

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
});

/** Ingests bytes as the asset's current original, as an upload's would be (ADR 0009): reads go through it. */
function placeAssetFile(Asset $asset): void
{
    $disk = Directory::getAssetDisk();
    $file = UploadedFile::fake()->image('test.png', 4, 4);
    Storage::disk($disk)->put($asset->path, file_get_contents($file->getRealPath()));
    $asset->markGraphContentChanged()->save();
}

it('renders the public asset viewer for a valid active token', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    $response = $this->get(route('dam.share.show', $share->urlToken()));

    $response->assertOk()
        ->assertSeeText($asset->file_name);
});

it('counts a view once the opened page reports back, never for a fetch that does not run it', function () {
    // PostHog UX review 2026-10-30, finding 5: mail link scanners fetched links in pairs and were counted as views.
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    $page = $this->get(route('dam.share.show', $share->urlToken()))->assertOk();
    expect($share->fresh()->view_count)->toBe(0)
        ->and($share->fresh()->last_accessed_at)->not->toBeNull();

    expect(shareVisitRan($page))->toBeTrue();
    shareVisitRan($page);
    expect($share->fresh()->view_count)->toBe(1);
});

it('counts the visitor\'s second load when the first never ran, and a refresh of a counted view only once', function () {
    $share = analyticsSharedFile();
    $url = route('dam.share.show', $share->urlToken());
    $this->withHeaders(analyticsBrowserHeaders());

    $this->get($url)->assertOk(); // fetched, never run: a scanner, or a tab closed before its collector flushed
    $second = $this->get($url)->assertOk();
    expect($share->fresh()->view_count)->toBe(0);

    shareVisitRan($second);
    expect($share->fresh()->view_count)->toBe(1);

    shareVisitRan($this->get($url)->assertOk()); // a refresh inside the visit window
    expect($share->fresh()->view_count)->toBe(1);
});

it('counts each view token once, and every token of a visitor who cannot be told apart', function () {
    $share = Share::factory()->forAsset(Asset::factory()->create()->id)->create();
    $repository = app(ShareRepository::class);
    $park = function (string $token) use ($share) {
        Cache::put('dam:share-view:'.$token, ['share' => (int) $share->id, 'visitor' => null], now()->addMinutes(5));
    };

    $park('anonymous-one');
    $park('anonymous-two');
    $repository->confirmVisit(share: $share, viewToken: 'anonymous-one');
    $repository->confirmVisit(share: $share, viewToken: 'anonymous-one');
    expect($share->fresh()->view_count)->toBe(1);

    $repository->confirmVisit(share: $share, viewToken: 'anonymous-two');
    expect($share->fresh()->view_count)->toBe(2);
});

it('counts a page load at once when the browser sends DNT, which runs no collector', function () {
    $share = Share::factory()->forAsset(Asset::factory()->create()->id)->create();

    $page = $this->withHeaders(['DNT' => '1'])->get(route('dam.share.show', $share->urlToken()))->assertOk();

    expect(shareVisitRan($page))->toBeFalse()
        ->and($share->fresh()->view_count)->toBe(1);
});

it('never counts a view token sent back through another link', function () {
    $asset = Asset::factory()->create();
    [$visited, $other] = [Share::factory()->forAsset($asset->id)->create(), Share::factory()->forAsset($asset->id)->create()];
    $page = $this->get(route('dam.share.show', $visited->urlToken()))->assertOk();
    preg_match('#"k":"([0-9a-f-]{36})"#', (string) $page->getContent(), $token);

    app(ShareRepository::class)->confirmVisit(share: $other, viewToken: $token[1]);

    expect($other->fresh()->view_count)->toBe(0)->and($visited->fresh()->view_count)->toBe(0);
});

it('shows the expired landing for an expired token', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->expired()->create();

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertStatus(410)
        ->assertSeeText(trans('dam::app.share.public.expired-title'));
});

it('shows the revoked landing for a revoked token', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->revoked()->create();

    $response = $this->get(route('dam.share.show', $share->urlToken()))
        ->assertStatus(410)
        ->assertSeeText(trans('dam::app.share.public.revoked-title'));

    // The tab title too, not "Link expired" (UX eval 2026-10-10, PERM-12).
    expect(preg_match('#<title>\s*([^<]*?)\s*</title>#', $response->getContent(), $title))->toBe(1)
        ->and($title[1])->toContain(trans('dam::app.share.public.revoked-title'))
        ->and($title[1])->not->toContain(trans('dam::app.share.public.expired-title'));
});

it('returns the not-found page for an unknown token', function () {

    $this->get(route('dam.share.show', str_repeat('Z', 40)))
        ->assertNotFound()
        ->assertSeeText(trans('dam::app.share.public.not-found-title'));
});

it('streams the asset file via the download route', function () {
    $asset = Asset::factory()->create();
    placeAssetFile($asset);
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.download', $share->urlToken()))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.str_replace(['"', "\r", "\n", "\0"], '', $asset->file_name).'"');

    expect($share->fresh()->download_count)->toBe(1);
});

it('rejects download on an expired asset share', function () {
    $asset = Asset::factory()->create();
    placeAssetFile($asset);
    $share = Share::factory()->forAsset($asset->id)->expired()->create();

    $this->get(route('dam.share.download', $share->urlToken()))
        ->assertStatus(410);
});

it('renders the directory gallery and lists only direct files', function () {
    $directory = Directory::factory()->create();
    $assetA = Asset::factory()->create();
    $assetB = Asset::factory()->create();
    $directory->assets()->attach([$assetA->id, $assetB->id]);

    $otherDir = Directory::factory()->create();
    $assetC = Asset::factory()->create();
    $otherDir->assets()->attach($assetC->id);

    $share = Share::factory()->forDirectory($directory->id)->create();

    $response = $this->get(route('dam.share.show', $share->urlToken()));

    $response->assertOk()
        ->assertSeeText($assetA->file_name)
        ->assertSeeText($assetB->file_name)
        ->assertDontSeeText($assetC->file_name);
});

it('serves an asset that belongs to the shared directory', function () {
    $directory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $directory->assets()->attach($asset->id);

    $share = Share::factory()->forDirectory($directory->id)->create();

    $this->get(route('dam.share.asset_view', ['token' => $share->urlToken(), 'assetId' => $asset->id]))
        ->assertOk()
        ->assertSeeText($asset->file_name);
});

it('serves a sha-keyed thumbnail for a shared image, even with its Library-path file deleted', function () {
    $image = imagecreatetruecolor(6, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    $sha = hash('sha256', $bytes);

    $asset = Asset::factory()->create([
        'file_name' => 'photo.png', 'file_type' => 'image', 'mime_type' => 'image/png',
        'extension' => 'png', 'path' => 'assets/Root/photo.png',
    ]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, $bytes);
    $asset->markGraphContentChanged()->save();
    Storage::disk(Directory::getAssetDisk())->delete($asset->path);

    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');

    // The link's own bounded copy, never the admin grid's thumbnail path.
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::shareStill($sha, 'thumbnail', 'png'));
});

it('rejects access to an asset that is NOT in the shared directory', function () {
    $directory = Directory::factory()->create();
    $otherDir = Directory::factory()->create();
    $foreignAsset = Asset::factory()->create();
    $otherDir->assets()->attach($foreignAsset->id);

    $share = Share::factory()->forDirectory($directory->id)->create();

    $this->get(route('dam.share.asset_view', ['token' => $share->urlToken(), 'assetId' => $foreignAsset->id]))
        ->assertNotFound();
});

it('rejects asset download outside the shared directory', function () {
    $directory = Directory::factory()->create();
    $otherDir = Directory::factory()->create();
    $foreignAsset = Asset::factory()->create();
    placeAssetFile($foreignAsset);
    $otherDir->assets()->attach($foreignAsset->id);

    $share = Share::factory()->forDirectory($directory->id)->create();

    $this->get(route('dam.share.asset_download', ['token' => $share->urlToken(), 'assetId' => $foreignAsset->id]))
        ->assertNotFound();
});

it('does not require authentication for any public share route', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    auth()->logout();

    $this->get(route('dam.share.show', $share->urlToken()))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Public share media range requests
|--------------------------------------------------------------------------
|
| The public viewer points <video>, <audio>, <img> and the PDF <iframe> at the
| share download route with ?disposition=inline, and response()->file() answers
| each browser Range header with its own 206. Ordinary seeking and buffer
| refills therefore arrive as dozens of separate requests through the same
| throttle that guards full-file downloads.
|
*/

/** Ingests bytes as the asset's current original, as an upload's would be (ADR 0009): reads go through it. */
function placeSharedVideoFile(Asset $asset, int $bytes = SHARED_MEDIA_FIXTURE_BYTES): void
{
    Storage::disk(Directory::getAssetDisk())->put($asset->path, str_repeat('m', $bytes));
    $asset->markGraphContentChanged()->save();
}

function makeSharedVideoAsset(string $fileName = 'campus-tour.mp4'): Asset
{
    $asset = Asset::factory()->create([
        'file_name' => $fileName,
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/'.$fileName,
        'file_size' => SHARED_MEDIA_FIXTURE_BYTES,
    ]);

    placeSharedVideoFile($asset);

    return $asset;
}

/**
 * One ranged GET of $length bytes starting at $offset, exactly as a media
 * element issues them while seeking, resuming or refilling its buffer.
 */
function seekSharedMedia(object $test, string $url, int $offset, int $length = 4096)
{
    // Per-request headers, not withHeaders(): that one persists onto every
    // later request in the same test, so the plain download assertions below
    // would silently become ranged requests too.
    return $test->get($url, [
        'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
    ]);
}

it('serves a full minute of ordinary video seeking on a public share', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    /*
     * Forty ranged requests is an unremarkable minute with a long video: the
     * browser re-requests on every scrub and every buffer refill. Before the
     * media allowance existed these were counted against the 20/minute
     * full-download budget and playback died on the twenty-first.
     */
    $statuses = [];

    for ($i = 0; $i < 40; $i++) {
        $statuses[] = seekSharedMedia($this, $inlineUrl, $i * 4096)->getStatusCode();
    }

    expect(array_count_values($statuses))->toBe([206 => 40]);
});

it('leaves the full-download budget intact while a shared video is playing', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    for ($i = 0; $i < 30; $i++) {
        seekSharedMedia($this, $inlineUrl, $i * 4096)->assertStatus(206);
    }

    $this->get(route('dam.share.download', $share->urlToken()))->assertOk();
});

it('still throttles repeated full downloads of a public share', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $downloadUrl = route('dam.share.download', $share->urlToken());

    for ($i = 0; $i < 20; $i++) {
        $this->get($downloadUrl)->assertOk();
    }

    $this->get($downloadUrl)->assertStatus(429);
});

it('withholds the media allowance from a full-file request that only claims inline disposition', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    /*
     * No Range header, so this is a whole-file transfer wearing the viewer's
     * query string. It must stay on the full-download budget, otherwise
     * ?disposition=inline becomes a way to buy a larger one.
     */
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    for ($i = 0; $i < 20; $i++) {
        $this->get($inlineUrl)->assertOk();
    }

    $this->get($inlineUrl)->assertStatus(429);
});

it('bounds the media allowance instead of exempting range requests', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    $allowance = DAMServiceProvider::SHARE_MEDIA_RANGE_LIMIT_PER_MINUTE;

    for ($i = 0; $i < $allowance; $i++) {
        seekSharedMedia($this, $inlineUrl, $i * 2048)->assertStatus(206);
    }

    seekSharedMedia($this, $inlineUrl, 0)->assertStatus(429);
});

it('keeps each share link media allowance separate', function () {
    $assetA = makeSharedVideoAsset();
    $assetB = Asset::factory()->create([
        'file_name' => 'orientation.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/orientation.mp4',
        'file_size' => SHARED_MEDIA_FIXTURE_BYTES,
    ]);
    placeSharedVideoFile($assetB);

    $shareA = Share::factory()->forAsset($assetA->id)->create();
    $shareB = Share::factory()->forAsset($assetB->id)->create();

    $urlA = route('dam.share.download', $shareA->urlToken()).'?disposition=inline';
    $urlB = route('dam.share.download', $shareB->urlToken()).'?disposition=inline';

    for ($i = 0; $i < DAMServiceProvider::SHARE_MEDIA_RANGE_LIMIT_PER_MINUTE; $i++) {
        seekSharedMedia($this, $urlA, $i * 2048);
    }

    seekSharedMedia($this, $urlA, 0)->assertStatus(429);
    seekSharedMedia($this, $urlB, 0)->assertStatus(206);
});

it('answers a shared media range request with intact range semantics', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    seekSharedMedia($this, $inlineUrl, 1024, 1024)
        ->assertStatus(206)
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Range', 'bytes 1024-2047/'.SHARED_MEDIA_FIXTURE_BYTES)
        ->assertHeader('Content-Length', '1024')
        ->assertHeader('Content-Type', 'video/mp4')
        // ContentStore::deliver() builds this header through Symfony's HeaderUtils::makeDisposition
        // (ADR 0009), which only quotes a filename when the HTTP token grammar requires it — a plain
        // name like this one does not.
        ->assertHeader('Content-Disposition', 'inline; filename=campus-tour.mp4');
});

it('refuses ranged media requests on shares that are not viewable', function () {
    $asset = makeSharedVideoAsset();

    $expired = Share::factory()->forAsset($asset->id)->expired()->create();
    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create();

    $expiredUrl = route('dam.share.download', $expired->urlToken()).'?disposition=inline';
    $revokedUrl = route('dam.share.download', $revoked->urlToken()).'?disposition=inline';
    $unknownUrl = route('dam.share.download', str_repeat('Z', 40)).'?disposition=inline';

    seekSharedMedia($this, $expiredUrl, 0)->assertStatus(410);
    seekSharedMedia($this, $revokedUrl, 0)->assertStatus(410);
    seekSharedMedia($this, $unknownUrl, 0)->assertNotFound();
});

it('does not count inline media playback as a share download', function () {
    $asset = makeSharedVideoAsset();
    $share = Share::factory()->forAsset($asset->id)->create();
    $inlineUrl = route('dam.share.download', $share->urlToken()).'?disposition=inline';

    for ($i = 0; $i < 30; $i++) {
        seekSharedMedia($this, $inlineUrl, $i * 4096)->assertStatus(206);
    }

    expect($share->fresh()->download_count)->toBe(0);

    $this->get(route('dam.share.download', $share->urlToken()))->assertOk();

    expect($share->fresh()->download_count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The media allowance belongs to the asset streams only
|--------------------------------------------------------------------------
|
| throttle:dam-share-download also guards dam.share.download_zip, and
| SharedViewerController::downloadZip builds a complete streamed archive that
| ignores both ?disposition=inline and the Range header. A ranged inline
| request for a zip is therefore still a whole-file transfer and has to stay in
| the shared per-address download budget.
|
*/

function makeSharedDirectoryZipShare(string $slug): array
{
    $directory = Directory::factory()->create();
    $asset = makeSharedVideoAsset($slug.'.mp4');
    $directory->assets()->attach($asset->id);

    $share = Share::factory()->forDirectory($directory->id)->create();

    return [$share, $asset];
}

it('keeps a directory zip in the ordinary download budget when it is dressed up as a media range request', function () {
    [$share] = makeSharedDirectoryZipShare('gallery');

    $zipUrl = route('dam.share.download_zip', $share->urlToken()).'?disposition=inline';

    for ($i = 0; $i < DAMServiceProvider::SHARE_DOWNLOAD_LIMIT_PER_MINUTE; $i++) {
        // The zip ignores the Range header entirely, which is the point: it
        // answers 200 with the whole archive however the request is framed.
        seekSharedMedia($this, $zipUrl, $i * 1024)->assertOk();
    }

    seekSharedMedia($this, $zipUrl, 0)->assertStatus(429);
});

it('shares one zip budget across every share token from the same address', function () {
    [$shareA] = makeSharedDirectoryZipShare('gallery-a');
    [$shareB] = makeSharedDirectoryZipShare('gallery-b');

    $zipA = route('dam.share.download_zip', $shareA->urlToken()).'?disposition=inline';
    $zipB = route('dam.share.download_zip', $shareB->urlToken()).'?disposition=inline';

    for ($i = 0; $i < DAMServiceProvider::SHARE_DOWNLOAD_LIMIT_PER_MINUTE; $i++) {
        seekSharedMedia($this, $zipA, $i * 1024)->assertOk();
    }

    // A second token must not reset the budget: zips are keyed per address,
    // not per share, exactly as they were before the media allowance existed.
    seekSharedMedia($this, $zipB, 0)->assertStatus(429);
});

it('reports a missing blob in a directory zip download as a manifest entry, never a silent skip', function () {
    $directory = Directory::factory()->create();
    $good = makeSharedVideoAsset('good.mp4');
    $bad = Asset::factory()->create([
        'file_name' => 'bad.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4',
        'extension' => 'mp4', 'path' => 'assets/Root/bad.mp4', 'file_size' => SHARED_MEDIA_FIXTURE_BYTES + 1,
    ]);
    // Distinct bytes from $good's: content-addressed blobs dedupe identical bytes (ADR 0009), and
    // deleting a shared blob would also fail the asset this test means to keep intact.
    placeSharedVideoFile($bad, SHARED_MEDIA_FIXTURE_BYTES + 1);
    $directory->assets()->attach([$good->id, $bad->id]);
    $object = $bad->files()->where('role', 'original')->firstOrFail()->fileObject;
    Storage::disk($object->disk)->delete($object->path);

    $share = Share::factory()->forDirectory($directory->id)->create();

    $response = $this->get(route('dam.share.download_zip', $share->urlToken()))->assertOk();

    $zipPath = tempnam(sys_get_temp_dir(), 'shared-zip-');
    file_put_contents($zipPath, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $missing = $zip->getFromName('Root/bad.mp4.MISSING.txt');
    $zip->close();
    unlink($zipPath);

    expect($names)->toContain('Root/good.mp4')->toContain('Root/bad.mp4.MISSING.txt')->not->toContain('Root/bad.mp4');
    expect($missing)->toContain('blob_missing');
});

it('still grants the media allowance to an asset streamed out of a directory share', function () {
    [$share, $asset] = makeSharedDirectoryZipShare('lecture');

    $inlineUrl = route('dam.share.asset_download', [
        'token'   => $share->urlToken(),
        'assetId' => $asset->id,
    ]).'?disposition=inline';

    // dam.share.asset_download is the second route the allowance covers;
    // narrowing it to exact route names must not drop this one.
    for ($i = 0; $i < 40; $i++) {
        seekSharedMedia($this, $inlineUrl, $i * 4096)->assertStatus(206);
    }
});

it('previews a shared 3D model with the model viewer', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'bracket.glb',
        'file_type' => 'document',
        'mime_type' => 'model/gltf-binary',
        'extension' => 'glb',
        'path'      => 'assets/Root/bracket.glb',
    ]);
    $share = Share::factory()->forAsset($asset->id)->create();
    // P2 S5b: the inline original has its own preview route, which a preview-only link still opens.
    $inlineUrl = route('dam.share.preview', $share->urlToken());

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertOk()
        ->assertSee('<v-dam-model-viewer', false)
        ->assertSee('url="'.e($inlineUrl).'"', false)
        ->assertSee('extension="glb"', false)
        // A shared model waits for a tap: a reloaded tab must not restart a large download by itself.
        ->assertSee('defer-load', false)
        ->assertSee('thumbnail-url="'.e(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id])).'"', false)
        ->assertDontSeeText(trans('dam::app.share.public.preview-not-available'));
});

it('previews a shared 360 image with the panorama viewer', function () {
    $asset = Asset::factory()->create(['panorama_mode' => Asset::PANORAMA_FORCE_360]);
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertOk()
        ->assertSee('<v-dam-public-panorama', false)
        ->assertDontSee('<v-zoomable-image', false);
});

it('keeps the zoomable viewer for an ordinary shared image', function () {
    $asset = Asset::factory()->create(['panorama_mode' => Asset::PANORAMA_FORCE_FLAT]);
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertOk()
        ->assertSee('<v-zoomable-image', false)
        ->assertDontSee('<v-dam-public-panorama', false)
        ->assertDontSee('<v-dam-model-viewer', false);
});

it('loads the DAM script bundle on the public asset page', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    // The 3D and 360 viewers depend on loaders this bundle defines; the
    // anonymous layout never fires the admin hook that normally includes it.
    $manifest = json_decode(file_get_contents(public_path('themes/dam/build/manifest.json')), true);

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertOk()
        ->assertSee('themes/dam/build/'.$manifest['src/Resources/assets/js/app.js']['file'], false);
});

/** The body a share thumbnail sent, whether streamed from a file or returned inline. */
function sharedThumbnailBody($response): string
{
    $base = $response->baseResponse ?? $response;

    return method_exists($base, 'getFile') ? file_get_contents($base->getFile()->getPathname()) : (string) $base->getContent();
}

/** An asset whose original is readable, as an upload's would be (ADR 0009). */
function sharedReadableAsset(string $fileName, string $fileType, string $mimeType, string $extension, array $attributes = []): Asset
{
    $asset = Asset::factory()->create(array_merge([
        'file_name' => $fileName, 'file_type' => $fileType, 'mime_type' => $mimeType,
        'extension' => $extension, 'path' => 'assets/Root/'.Str::lower(Str::random(6)).'-'.$fileName,
    ], $attributes));
    Storage::disk(Directory::getAssetDisk())->put($asset->path, $fileName.'-original-'.Str::random(8));
    $asset->markGraphContentChanged()->save();

    return $asset->fresh();
}

/** Stores a browser capture at the path this asset's own original yields (ADR 0006). */
function sharedCapture(Asset $asset, string $bytes): string
{
    $path = ModelThumbnailService::generatedPath($asset, ModelThumbnailService::checksum($asset));
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
    $asset->meta_data = array_merge($asset->meta_data ?? [], ['thumbnail_path' => $path]);
    $asset->save();

    return $path;
}

function sharedThumbnail($test, Asset $asset)
{
    $share = Share::factory()->forAsset($asset->id)->create();

    return $test->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]));
}

beforeEach(function () {
    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/document.svg', '<svg id="grid-document"/>');
    Storage::disk('public')->put('dam/grid/image.svg', '<svg id="grid-image"/>');
    Storage::disk('public')->put('dam/grid/video.svg', '<svg id="grid-video"/>');
});

it('serves the captured 3D view as a shared thumbnail for each model type', function (string $extension) {
    $asset = sharedReadableAsset(fileName: 'part.'.$extension, fileType: 'document', mimeType: 'application/octet-stream', extension: $extension);
    sharedCapture(asset: $asset, bytes: 'captured-'.$extension);

    $response = sharedThumbnail($this, $asset);

    $response->assertOk();
    expect(sharedThumbnailBody($response))->toBe('captured-'.$extension);
})->with(['glb', '3mf', 'stl']);

it('keeps the document placeholder for a shared 3D file nobody has captured yet', function () {
    $asset = sharedReadableAsset(fileName: 'chair.glb', fileType: 'document', mimeType: 'model/gltf-binary', extension: 'glb');

    $response = sharedThumbnail($this, $asset);

    $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect(sharedThumbnailBody($response))->toBe('<svg id="grid-document"/>');
});

it('never serves another asset\'s capture that a shared model\'s meta_data points at', function () {
    $foreign = sharedReadableAsset(fileName: 'secret.glb', fileType: 'document', mimeType: 'model/gltf-binary', extension: 'glb');
    $foreignPath = sharedCapture(asset: $foreign, bytes: 'foreign-capture');
    $asset = sharedReadableAsset(fileName: 'chair.glb', fileType: 'document', mimeType: 'model/gltf-binary', extension: 'glb');
    $asset->meta_data = array_merge($asset->meta_data ?? [], ['thumbnail_path' => $foreignPath]);
    $asset->save();

    $response = sharedThumbnail($this, $asset->fresh());

    expect(sharedThumbnailBody($response))->not->toBe('foreign-capture')->toBe('<svg id="grid-document"/>');
});

it('prefers a custom thumbnail over the captured 3D view on a share page', function () {
    $asset = sharedReadableAsset(fileName: 'chair.glb', fileType: 'document', mimeType: 'model/gltf-binary', extension: 'glb');
    sharedCapture(asset: $asset, bytes: 'captured-model-view');
    $custom = 'custom-thumbnails/'.$asset->id.'/'.Str::uuid()->toString().'.png';
    Storage::disk(Directory::getAssetDisk())->put($custom, 'custom-art');
    $asset->meta_data = array_merge($asset->meta_data ?? [], ['custom_thumbnail' => ['path' => $custom, 'mime_type' => 'image/png']]);
    $asset->save();

    $response = sharedThumbnail($this, $asset->fresh());

    $response->assertOk();
    expect(sharedThumbnailBody($response))->toBe('custom-art');
});

dataset('shared poster families', [
    'video'    => [['clip.mp4', 'video', 'video/mp4', 'mp4', []], '<svg id="grid-video"/>'],
    'pdf'      => [['brief.pdf', 'document', 'application/pdf', 'pdf', []], '<svg id="grid-document"/>'],
    'pano360'  => [['lobby.jpg', 'image', 'image/jpeg', 'jpg', ['asset_kind' => 'pano360']], '<svg id="grid-image"/>'],
]);

it('serves a shared asset\'s own poster even when meta_data names a foreign one', function (array $fixture) {
    [$name, $type, $mime, $extension, $attributes] = $fixture;
    $foreign = sharedReadableAsset(fileName: 'secret.mp4', fileType: 'video', mimeType: 'video/mp4', extension: 'mp4');
    $foreignPoster = DerivativeImages::poster(ModelThumbnailService::checksum($foreign));
    Storage::disk(Directory::getAssetDisk())->put($foreignPoster, 'foreign-poster');

    $asset = sharedReadableAsset(fileName: $name, fileType: $type, mimeType: $mime, extension: $extension, attributes: $attributes);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster(ModelThumbnailService::checksum($asset)), 'own-poster');
    $asset->meta_data = array_merge($asset->meta_data ?? [], ['thumbnail_path' => $foreignPoster]);
    $asset->save();

    $response = sharedThumbnail($this, $asset->fresh());

    $response->assertOk();
    expect(sharedThumbnailBody($response))->toBe('own-poster');
})->with('shared poster families');

it('shows the placeholder, never a foreign or legacy poster, when a shared asset has no poster of its own', function (array $fixture, string $placeholder) {
    [$name, $type, $mime, $extension, $attributes] = $fixture;
    $foreign = sharedReadableAsset(fileName: 'secret.mp4', fileType: 'video', mimeType: 'video/mp4', extension: 'mp4');
    $foreignPoster = DerivativeImages::poster(ModelThumbnailService::checksum($foreign));
    Storage::disk(Directory::getAssetDisk())->put($foreignPoster, 'foreign-poster');
    Storage::disk(Directory::getAssetDisk())->put('thumbnails/legacy-poster.jpg', 'legacy-poster');

    foreach ([$foreignPoster, 'thumbnails/legacy-poster.jpg'] as $recorded) {
        $asset = sharedReadableAsset(fileName: $name, fileType: $type, mimeType: $mime, extension: $extension, attributes: $attributes);
        $asset->meta_data = array_merge($asset->meta_data ?? [], ['thumbnail_path' => $recorded]);
        $asset->save();

        $response = sharedThumbnail($this, $asset->fresh());

        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        expect(sharedThumbnailBody($response))->toBe($placeholder);
    }
})->with('shared poster families');

it('serves a share thumbnail as private, no-cache and denies a conditional request once the share is revoked', function () {
    $asset = sharedReadableAsset(fileName: 'part.glb', fileType: 'document', mimeType: 'application/octet-stream', extension: 'glb');
    sharedCapture(asset: $asset, bytes: 'captured-glb');
    $share = Share::factory()->forAsset($asset->id)->create();
    $url = route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]);

    $first = $this->get($url)->assertOk();

    $cacheControl = (string) $first->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->toContain('no-cache')->not->toContain('public');

    $validators = array_filter([
        'If-None-Match'     => $first->headers->get('ETag'),
        'If-Modified-Since' => $first->headers->get('Last-Modified'),
    ]);
    expect($validators)->not->toBeEmpty();

    $share->update(['revoked_at' => now()]);

    $denied = $this->withHeaders($validators)->get($url);

    expect($denied->getStatusCode())->toBeIn([403, 404, 410])
        ->and((string) $denied->headers->get('Cache-Control'))->toContain('no-store');
});

// --- Finding 5 (2026-10-27): the image stage shows a screen-sized copy of a large still --------------------------------

/** A shared PNG of incompressible noise, so its original is over SharePlayback::DISPLAY_MIN_BYTES. */
function sharedLargeImage(int $width = 2400, int $height = 200): array
{
    $image = imagecreatetruecolor($width, $height);
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($image, null, 0);
    $bytes = ob_get_clean();

    $asset = Asset::factory()->create([
        'file_name' => 'wide.png', 'file_type' => 'image', 'mime_type' => 'image/png', 'extension' => 'png',
        'path'      => 'assets/Root/'.Str::lower(Str::random(6)).'-wide.png', 'panorama_mode' => Asset::PANORAMA_FORCE_FLAT,
    ]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, $bytes);
    $asset->markGraphContentChanged()->save();
    $asset->forceFill(['file_size' => strlen($bytes)])->save();

    return [$asset->fresh(), $bytes];
}

it('shows a large shared still on the stage as its screen-sized copy, preloads that URL, and zooms into the original', function () {
    [$asset, $bytes] = sharedLargeImage();
    expect(strlen($bytes))->toBeGreaterThan(SharePlayback::DISPLAY_MIN_BYTES);
    $share = Share::factory()->forAsset($asset->id)->create();
    $display = route('dam.share.display', $share->urlToken());
    $original = route('dam.share.preview', $share->urlToken());

    $this->get(route('dam.share.show', $share->urlToken()))->assertOk()
        ->assertSee('<link rel="preload" as="image" href="'.$display.'" fetchpriority="high">', false)
        ->assertSee('src="'.$display.'"', false)
        ->assertSee('full-src="'.$original.'"', false);

    $copy = $this->get($display)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $body = sharedThumbnailBody($copy);
    expect(strlen($body))->toBeLessThan(strlen($bytes))
        ->and(getimagesizefromstring($body)[0])->toBe(SharePlayback::DISPLAY_SIZE)
        ->and($copy->headers->get('Cache-Control'))->toContain('private');
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::shareStill(hash('sha256', $bytes), 'preview-1920', 'png'));

    // The original stays the preview route's answer, byte for byte, and neither read counts as a download.
    expect(sharedThumbnailBody($this->get($original)->assertOk()))->toBe($bytes)
        ->and($share->fresh()->download_count)->toBe(0);
});

it('serves a folder link member\'s stage copy and keeps a preview-only link\'s zoom on the copy', function () {
    [$asset] = sharedLargeImage();
    $folder = Directory::create(['name' => 'Display '.Str::random(6), 'parent_id' => null]);
    $asset->directories()->attach($folder->id);
    $share = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $member = ['token' => $share->urlToken(), 'assetId' => $asset->id];
    $display = route('dam.share.asset_display', $member);

    $this->get(route('dam.share.asset_view', $member))->assertOk()
        ->assertSee('src="'.$display.'"', false)
        ->assertSee('full-src=""', false)
        ->assertDontSee(route('dam.share.asset_preview', $member), false);

    $this->get($display)->assertOk()->assertHeader('Content-Type', 'image/webp');
});

it('keeps a small shared still on the original preview URL, and answers its display route with the original', function () {
    $asset = sharedReadableAsset('small.png', 'image', 'image/png', 'png', ['panorama_mode' => Asset::PANORAMA_FORCE_FLAT, 'file_size' => 2048]);
    $share = Share::factory()->forAsset($asset->id)->create();
    $preview = route('dam.share.preview', $share->urlToken());

    $this->get(route('dam.share.show', $share->urlToken()))->assertOk()
        ->assertSee('<link rel="preload" as="image" href="'.$preview.'" fetchpriority="high">', false)
        ->assertSee('full-src=""', false)
        ->assertDontSee(route('dam.share.display', $share->urlToken()), false);

    expect(sharedThumbnailBody($this->get(route('dam.share.display', $share->urlToken()))->assertOk()))
        ->toBe(sharedThumbnailBody($this->get($preview)));
});

it('answers a display route like the preview route for expired, revoked and unknown links', function () {
    [$asset] = sharedLargeImage(width: 2100, height: 180);
    $expired = Share::factory()->forAsset($asset->id)->create(['expires_at' => now()->subDay()]);
    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create();

    foreach (['expired' => $expired, 'revoked' => $revoked] as $name => $share) {
        $display = $this->get(route('dam.share.display', $share->urlToken()));
        expect($display->getStatusCode())->toBe($this->get(route('dam.share.preview', $share->urlToken()))->getStatusCode(), $name)
            ->and($display->getStatusCode())->not->toBe(200, $name)
            ->and((string) $display->headers->get('Content-Type'))->not->toContain('image/webp', $name);
    }

    $this->get(route('dam.share.display', Str::random(32)))->assertNotFound();
});
