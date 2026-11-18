<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Share\SharedFiles;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * OD5 / A-contract 2-3 (2026-10-19): each stacked file of a shared item has its own thumbnail and in-page preview, and
 * the item has a zip of all its files. Thumbnails and still previews are sha-keyed derivatives; video, audio and PDF
 * previews are the original inline, only while the link allows downloads. Every route answers the link and the asset's
 * lifecycle like the download routes.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Stack preview '.uniqid()]);
    $this->asset = Asset::factory()->create(['file_name' => 'kit.png', 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'path' => 'assets/StackPreview/kit.png']);
    Storage::disk(Directory::getAssetDisk())->put($this->asset->path, fakePngBytes(8, 8));
    $this->asset->markGraphContentChanged()->save();
    $this->asset->directories()->attach($this->folder->id);

    $angle = $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files', [
        'file' => UploadedFile::fake()->image('angle.png', 40, 20), 'role' => AssetFile::ROLE_ALTERNATE,
    ])->assertCreated()->json('attachment_id');
    $this->files = [
        'main'  => (int) DB::table('dam_attachments')->where('dam_asset_id', $this->asset->id)->where('is_representative', true)->value('id'),
        'angle' => (int) $angle,
    ];

    foreach ([
        'clip'  => ['clip.mp4', 'video/mp4', AssetFile::ROLE_OUTPUT],
        'voice' => ['voice.mp3', 'audio/mpeg', AssetFile::ROLE_SOURCE],
        'spec'  => ['spec.pdf', 'application/pdf', AssetFile::ROLE_SIDECAR],
        'chair' => ['chair.glb', 'model/gltf-binary', AssetFile::ROLE_SOURCE],
    ] as $key => [$name, $mime, $role]) {
        $this->files[$key] = (int) attachPreviewFile($this->asset, $name, $mime, $role, label: $name)->attachment_id;
    }

    $this->assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $this->folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    Auth::guard('admin')->logout();
});

/** `thumb` or `preview` of one slot: by the asset link when `$assetId` is null, else by the container link's member route. */
function stackPreviewUrl(string $kind, Share $link, int $attachment, ?int $assetId = null): string
{
    $action = $kind === 'thumb' ? 'thumbnail' : 'preview';

    return $assetId === null
        ? route('dam.share.file_'.$action, ['token' => $link->urlToken(), 'attachment' => $attachment])
        : route('dam.share.asset_file_'.$action, ['token' => $link->urlToken(), 'assetId' => $assetId, 'attachment' => $attachment]);
}

function stackPreviewBody($response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) $base->getFile()->getContent(),
        $base instanceof StreamedResponse   => (string) $response->streamedContent(),
        default                             => (string) $response->getContent(),
    };
}

/** @return list<string> a zip body's entry names, in archive order */
function stackPreviewZipNames(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'dam-stack-zip-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $names = [];

    if ($zip->open($path) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
    }

    @unlink($path);

    return $names;
}

function stackPreviewObject(int $attachment): FileObject
{
    return AssetFile::query()->where('attachment_id', $attachment)->where('is_current', true)->firstOrFail()->fileObject;
}

it('serves a stacked still\'s thumbnail and preview as its own bounded sha-keyed derivatives, even after an admin view cached upscaled ones', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $object = stackPreviewObject($this->files['angle']);
    $assetFileId = (int) AssetFile::query()->where('attachment_id', $this->files['angle'])->where('is_current', true)->value('id');

    // An admin view first: it writes the shared thumbnail/preview paths at the full requested width.
    $this->loginAsAdmin();
    foreach (['thumb' => DerivativeImages::thumbnail($object->sha256, 'png'), 'preview' => DerivativeImages::preview($object->sha256, 1920, 'png')] as $flag => $adminPath) {
        $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $assetFileId, $flag => 1]))->assertOk();
        expect($disk->exists($adminPath))->toBeTrue("admin warmed {$flag}")
            ->and((int) (getimagesizefromstring($disk->get($adminPath))[0] ?? 0))->toBeGreaterThan(40, "admin upscaled {$flag}");
    }
    Auth::guard('admin')->logout();

    foreach ([[$this->assetLink, null], [$this->folderLink, $this->asset->id]] as [$link, $member]) {
        foreach (['thumb', 'preview'] as $kind) {
            $response = $this->get(stackPreviewUrl($kind, $link, $this->files['angle'], $member))->assertOk();

            expect((string) $response->headers->get('Content-Type'))->toStartWith('image/')
                ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment')
                ->and(array_slice((array) getimagesizefromstring(stackPreviewBody($response)), 0, 2))->toBe([40, 20], "never upscaled: {$kind}");
        }
    }

    expect($disk->exists(DerivativeImages::shareStill($object->sha256, 'thumbnail', 'png')))->toBeTrue()
        ->and($disk->exists(DerivativeImages::shareStill($object->sha256, 'preview-1920', 'png')))->toBeTrue()
        ->and($this->assetLink->fresh()->download_count)->toBe(0);
});

it('serves a main still\'s share thumbnail as its own bounded copy, even after the admin grid cached an upscaled one', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $bytes = fakePngBytes(40, 20);
    $main = Asset::factory()->create(['file_name' => 'wide.png', 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'path' => 'assets/StackPreview/wide.png']);
    $disk->put($main->path, $bytes);
    $main->markGraphContentChanged()->save();
    $main->directories()->attach($this->folder->id);
    $sha = hash('sha256', $bytes);

    // The admin grid first: it writes the shared thumbnail path at the full requested width.
    $this->loginAsAdmin();
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($main->path)]))->assertOk();
    expect($disk->exists(DerivativeImages::thumbnail($sha, 'png')))->toBeTrue('admin warmed the grid thumbnail')
        ->and((int) (getimagesizefromstring($disk->get(DerivativeImages::thumbnail($sha, 'png')))[0] ?? 0))->toBeGreaterThan(40, 'admin upscaled');
    Auth::guard('admin')->logout();

    $link = Share::factory()->forAsset($main->id)->create();

    foreach ([[$link, $main->id], [$this->folderLink, $main->id]] as [$share, $assetId]) {
        $response = $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $assetId]))->assertOk();

        expect(array_slice((array) getimagesizefromstring(stackPreviewBody($response)), 0, 2))->toBe([40, 20], 'never the upscaled admin copy');
    }

    expect($disk->exists(DerivativeImages::shareStill($sha, 'thumbnail', 'png')))->toBeTrue();
});

it('previews video, audio and PDF originals inline on an open link, ranged requests included, without counting', function () {
    foreach (['clip' => 'video/mp4', 'voice' => 'audio/mpeg', 'spec' => 'application/pdf'] as $key => $mime) {
        $response = $this->get(stackPreviewUrl('preview', $this->assetLink, $this->files[$key]))->assertOk();

        expect((string) $response->headers->get('Content-Type'))->toStartWith($mime)
            ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and(stackPreviewBody($response))->toBe('preview-fixture');
    }

    $this->get(stackPreviewUrl('preview', $this->folderLink, $this->files['clip'], $this->asset->id), ['Range' => 'bytes=0-6'])->assertStatus(206);
    expect($this->assetLink->fresh()->download_count)->toBe(0)
        ->and($this->folderLink->fresh()->download_count)->toBe(0);
});

it('gives a 3D model no preview and only its own capture as a thumbnail, never a meta_data path', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $png = fakePngBytes(8, 8);
    $disk->put('assets/StackPreview/secret.png', $png);
    $this->asset->update(['meta_data' => ['thumbnail_path' => 'assets/StackPreview/secret.png', 'cover_art_path' => 'assets/StackPreview/secret.png']]);

    $this->get(stackPreviewUrl('preview', $this->assetLink, $this->files['chair']))->assertNotFound();
    $this->get(stackPreviewUrl('thumb', $this->assetLink, $this->files['chair']))->assertNotFound();

    $disk->put(DerivativeImages::modelCapture(stackPreviewObject($this->files['chair'])->sha256, $this->asset->id), $png);
    expect(stackPreviewBody($this->get(stackPreviewUrl('thumb', $this->assetLink, $this->files['chair']))->assertOk()))->toBe($png);
});

it('serves a stacked PDF\'s poster as its thumbnail', function () {
    $png = fakePngBytes(8, 8);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster(stackPreviewObject($this->files['spec'])->sha256), $png);

    expect(stackPreviewBody($this->get(stackPreviewUrl('thumb', $this->folderLink, $this->files['spec'], $this->asset->id))->assertOk()))->toBe($png);
});

it('never decodes a stacked TIFF or PSD: it thumbnails as the poster, else the library\'s WebP thumbnail, else 404', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    Log::spy();

    foreach (['scan.tif' => 'image/tiff', 'cover.psd' => 'image/vnd.adobe.photoshop'] as $name => $mime) {
        $slot = (int) attachPreviewFile($this->asset, $name, $mime, AssetFile::ROLE_SOURCE, label: $name)->attachment_id;
        $sha = stackPreviewObject($slot)->sha256;

        // Nothing stored yet: 404 without a decode attempt (GD would fail on these bytes and log it) or a cached copy.
        $this->get(stackPreviewUrl('thumb', $this->assetLink, $slot))->assertNotFound();
        $this->get(stackPreviewUrl('preview', $this->assetLink, $slot))->assertNotFound();
        expect($disk->exists(DerivativeImages::shareStill($sha, 'thumbnail', pathinfo($name, PATHINFO_EXTENSION))))->toBeFalse($name);

        $webp = fakePngBytes(6, 6);
        $disk->put(DerivativeImages::thumbnail($sha, 'webp'), $webp);
        expect(stackPreviewBody($this->get(stackPreviewUrl('thumb', $this->folderLink, $slot, $this->asset->id))->assertOk()))->toBe($webp, "{$name} library thumbnail");

        $poster = fakePngBytes(7, 7);
        $disk->put(DerivativeImages::poster($sha), $poster);
        expect(stackPreviewBody($this->get(stackPreviewUrl('thumb', $this->assetLink, $slot))->assertOk()))->toBe($poster, "{$name} poster first");
    }

    Log::shouldNotHaveReceived('warning');
});

it('serves a stacked SVG as itself under the sandboxing CSP and nosniff, on open and preview-only links', function () {
    $svg = attachPreviewFile($this->asset, 'logo.svg', 'image/svg+xml', AssetFile::ROLE_SOURCE, label: 'logo.svg');
    $previewOnly = Share::factory()->forAsset($this->asset->id)->create(['allow_download' => false]);

    foreach ([$this->assetLink, $previewOnly] as $link) {
        foreach (['thumb', 'preview'] as $kind) {
            $response = $this->get(stackPreviewUrl($kind, $link, (int) $svg->attachment_id))->assertOk();

            expect(stackPreviewBody($response))->toBe('preview-fixture')
                ->and((string) $response->headers->get('Content-Type'))->toStartWith('image/svg+xml')
                ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment')
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
                ->not->toContain('script-src');
        }
    }

    expect($previewOnly->fresh()->download_count)->toBe(0);
});

/**
 * Every element whose own text starts with `$needle` inside the Vue mount (#app), as [tag, attributes]. A name in a
 * Vue-compiled text node would be evaluated; only a v-pre element keeps it literal.
 *
 * @return list<array{0: string, 1: string}>
 */
function stackPreviewTextHosts(string $html, string $needle): array
{
    $app = (string) substr($html, (int) strpos($html, 'id="app"'));
    preg_match_all('/<([a-z0-9]+)((?:\s[^>]*)?)>\s*'.preg_quote($needle, '/').'/i', $app, $matches, PREG_SET_ORDER);

    return array_map(fn ($match) => [$match[1], $match[2]], $matches);
}

it('keeps mustache syntax in shared names literal: every name on the asset and folder pages sits in a v-pre element', function () {
    $this->asset->update(['title' => '{{ 7*7 }} title', 'file_name' => '{{ 6*6 }}.png']);
    $this->folder->update(['name' => '{{ 5*5 }} folder']);

    $assetPage = (string) $this->get(route('dam.share.show', ['token' => $this->assetLink->urlToken()]))->assertOk()->getContent();
    $folderPage = (string) $this->get(route('dam.share.show', ['token' => $this->folderLink->urlToken()]))->assertOk()->getContent();

    foreach ([
        [$assetPage, '{{ 7*7 }} title', ['h1']],
        // A stack's strip under the stage is filled by v-text from the selected row; the name's only text host is its row in the Files list.
        [$assetPage, '{{ 6*6 }}.png', ['p']],
        [$folderPage, '{{ 5*5 }} folder', ['h1']],
        [$folderPage, '{{ 7*7 }} title', ['p']],
    ] as [$html, $needle, $tags]) {
        $hosts = stackPreviewTextHosts($html, $needle);

        // Positive control: the name is on the page as text, in the element expected to hold it.
        expect(array_column($hosts, 0))->toContain(...$tags);

        foreach ($hosts as [$tag, $attributes]) {
            expect($attributes)->toMatch('/\sv-pre(\s|=|$)/', "<{$tag}> holding {$needle} without v-pre");
        }
    }
});

it('reads the strip under the stage from the file on stage in a stack, and keeps a single file\'s own server-rendered values', function () {
    $stackPage = (string) $this->get(route('dam.share.show', ['token' => $this->assetLink->urlToken()]))->assertOk()->getContent();

    expect($stackPage)->toContain('data-dam-share-info')
        ->toContain('v-text="panel.info.name')->toContain('v-text="panel.info.type')->toContain('v-text="panel.info.size')
        ->and($stackPage)->toContain('.dam-share-stage iframe { min-height: 0 !important; }');

    // Each row hands the page its size as the Files list formats it, so a switch never formats bytes in script.
    preg_match("/:files='([^']*)'/", $stackPage, $found);
    $rows = collect(json_decode($found[1], true, 512, JSON_THROW_ON_ERROR))->keyBy('name');

    expect($rows['clip.mp4']['size_label'])->toBe(Number::fileSize(strlen('preview-fixture'), precision: 1))
        ->and($rows['kit.png']['extension'])->toBe('PNG')
        ->and($rows->every(fn (array $row) => $row['size'] === null ? $row['size_label'] === null : $row['size_label'] === Number::fileSize((int) $row['size'], precision: 1)))->toBeTrue();

    $single = Asset::factory()->create(['file_name' => 'single.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/StackPreview/single.png']);
    Storage::disk(Directory::getAssetDisk())->put($single->path, fakePngBytes(4, 4));
    $single->markGraphContentChanged()->save();
    $single->directories()->attach($this->folder->id);
    $singlePage = (string) $this->get(route('dam.share.show', ['token' => Share::factory()->forAsset($single->id)->create()->urlToken()]))->assertOk()->getContent();

    expect($singlePage)->not->toContain('data-dam-share-info')->not->toContain('panel.info.')
        ->and(stackPreviewTextHosts($singlePage, 'single.png'))->toContain(['dd', ' class="text-zinc-900 dark:text-slate-100 font-medium truncate" v-pre']);
});

it('zips every file of the item under its download name, counts one download, and refuses a zip with a missing file', function () {
    $response = $this->get(route('dam.share.files_zip', $this->assetLink->urlToken()))->assertOk()->assertHeader('Content-Type', 'application/zip');
    expect(stackPreviewZipNames($response->streamedContent()))->toBe(['kit.png', 'angle.png', 'clip.mp4', 'voice.mp3', 'spec.pdf', 'chair.glb'])
        ->and($this->assetLink->fresh()->download_count)->toBe(1);

    $member = $this->get(route('dam.share.asset_files_zip', ['token' => $this->folderLink->urlToken(), 'assetId' => $this->asset->id]))->assertOk();
    expect(stackPreviewZipNames($member->streamedContent()))->toHaveCount(6);

    $chair = stackPreviewObject($this->files['chair']);
    Storage::disk($chair->disk)->delete($chair->path);
    $this->get(route('dam.share.files_zip', $this->assetLink->urlToken()))->assertNotFound();
    expect($this->assetLink->fresh()->download_count)->toBe(1);
});

it('answers 404 for another asset\'s slot, a generated slot or an asset outside the folder, and follows the asset\'s lifecycle', function () {
    // Real PNG bytes in every slot, so a thumbnail or preview that slipped past authorization would answer 200, not a
    // decode-failure 404: each 404 below comes from the slot check alone.
    $png = fakePngBytes(6, 6);
    $still = function (Asset $owner, string $name, string $role) use ($png): int {
        $file = attachPreviewFile($owner, $name, 'image/png', $role, label: $name);
        Storage::disk($file->fileObject->disk)->put($file->fileObject->path, $png);
        DB::table('dam_file_objects')->where('id', $file->file_object_id)->update(['size' => strlen($png)]);

        return (int) $file->attachment_id;
    };
    $neighbour = Asset::factory()->create(['file_name' => 'neighbour.png', 'path' => 'assets/StackPreview/neighbour.png']);
    $neighbour->directories()->attach($this->folder->id);
    $theirs = $still($neighbour, 'theirs.png', AssetFile::ROLE_OUTPUT);
    $proxy = $still($this->asset, 'kit-proxy.png', AssetFile::ROLE_PROXY);
    $outside = Asset::factory()->create(['file_name' => 'outside.png', 'path' => 'assets/Elsewhere/outside.png']);
    $outsideSlot = $still($outside, 'out.png', AssetFile::ROLE_OUTPUT);
    $mine = $still($this->asset, 'mine.png', AssetFile::ROLE_OUTPUT);

    // Authorized control: the same kind of file, stacked on the shared asset, is served on both links.
    foreach (['thumb', 'preview'] as $kind) {
        foreach (['asset link' => [$this->assetLink, null], 'folder link' => [$this->folderLink, $this->asset->id]] as $case => [$link, $member]) {
            expect($this->get(stackPreviewUrl($kind, $link, $mine, $member))->getStatusCode())->toBe(200, "control {$kind}: {$case}");
        }
    }

    foreach (['thumb', 'preview'] as $kind) {
        foreach ([
            'neighbour, asset link'      => [$this->assetLink, null, $theirs],
            'neighbour, folder link'     => [$this->folderLink, $this->asset->id, $theirs],
            'proxy, asset link'          => [$this->assetLink, null, $proxy],
            'proxy, folder link'         => [$this->folderLink, $this->asset->id, $proxy],
            'outside, asset link'        => [$this->assetLink, null, $outsideSlot],
            'assetId outside the folder' => [$this->folderLink, $outside->id, $outsideSlot],
        ] as $case => [$link, $member, $slot]) {
            expect($this->get(stackPreviewUrl($kind, $link, $slot, $member))->getStatusCode())->toBe(404, "{$kind}: {$case}");
        }
    }
    $this->get(route('dam.share.asset_files_zip', ['token' => $this->folderLink->urlToken(), 'assetId' => $outside->id]))->assertNotFound();

    $viaAsset = fn (): array => [
        $this->get(stackPreviewUrl('thumb', $this->assetLink, $this->files['angle']))->getStatusCode(),
        $this->get(stackPreviewUrl('preview', $this->assetLink, $this->files['clip']))->getStatusCode(),
        $this->get(route('dam.share.files_zip', $this->assetLink->urlToken()))->getStatusCode(),
    ];
    $viaFolder = fn (): array => [
        $this->get(stackPreviewUrl('thumb', $this->folderLink, $this->files['angle'], $this->asset->id))->getStatusCode(),
        $this->get(stackPreviewUrl('preview', $this->folderLink, $this->files['clip'], $this->asset->id))->getStatusCode(),
        $this->get(route('dam.share.asset_files_zip', ['token' => $this->folderLink->urlToken(), 'assetId' => $this->asset->id]))->getStatusCode(),
    ];

    $this->asset->update(['availability' => Asset::AVAILABILITY_PENDING_REVIEW]);
    expect($viaAsset())->toBe([404, 404, 404])->and($viaFolder())->toBe([404, 404, 404]);

    $this->asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
    $this->asset->delete();
    expect($viaAsset())->toBe([410, 410, 410])->and($viaFolder())->toBe([404, 404, 404])
        ->and($this->assetLink->fresh()->download_count)->toBe(0)
        ->and($this->folderLink->fresh()->download_count)->toBe(0);
});

it('answers 410 on every new route for an expired or revoked link and 401 for a locked one', function () {
    $routes = fn (Share $a, Share $f): array => [
        stackPreviewUrl('thumb', $a, $this->files['angle']),
        stackPreviewUrl('preview', $a, $this->files['clip']),
        route('dam.share.files_zip', $a->urlToken()),
        stackPreviewUrl('thumb', $f, $this->files['angle'], $this->asset->id),
        stackPreviewUrl('preview', $f, $this->files['clip'], $this->asset->id),
        route('dam.share.asset_files_zip', ['token' => $f->urlToken(), 'assetId' => $this->asset->id]),
    ];

    foreach (['expired' => fn ($factory) => $factory->expired(), 'revoked' => fn ($factory) => $factory->revoked()] as $state => $apply) {
        $a = $apply(Share::factory()->forAsset($this->asset->id))->create();
        $f = $apply(Share::factory()->forDirectory($this->folder->id))->create();

        foreach ($routes($a, $f) as $url) {
            expect($this->get($url)->getStatusCode())->toBe(410, "{$state}: {$url}");
        }
    }

    $hash = Hash::make('pw-'.bin2hex(random_bytes(8)));
    $this->assetLink->forceFill(['password_hash' => $hash])->save();
    $this->folderLink->forceFill(['password_hash' => $hash])->save();

    foreach ($routes($this->assetLink->fresh(), $this->folderLink->fresh()) as $url) {
        expect($this->get($url)->getStatusCode())->toBe(401, "locked: {$url}");
    }
});

it('presents each file with its own routes, and only previews and no downloads on a preview-only link', function () {
    $token = $this->assetLink->urlToken();
    $rows = app(SharedFiles::class)->present(share: $this->assetLink, asset: $this->asset, token: $token, memberAssetId: null);
    $byName = collect($rows)->keyBy('name');

    expect(array_keys($rows[0]))->toBe(['attachment_id', 'file_id', 'checksum', 'name', 'extension', 'kind', 'size', 'is_main', 'label', 'thumb_url', 'preview_url', 'preview_kind', 'page_url', 'download_url'])
        ->and($byName->keys()->all())->toBe(['kit.png', 'angle.png', 'clip.mp4', 'voice.mp3', 'spec.pdf', 'chair.glb'])
        ->and($byName['kit.png'])->toMatchArray([
            'attachment_id' => $this->files['main'], 'is_main' => true, 'extension' => 'PNG', 'kind' => 'image', 'preview_kind' => 'image',
            'thumb_url'     => route('dam.share.thumbnail', ['token' => $token, 'assetId' => $this->asset->id]),
            'download_url'  => route('dam.share.download', ['token' => $token]),
        ])
        ->and($byName['clip.mp4'])->toMatchArray([
            'is_main'      => false, 'extension' => 'MP4', 'kind' => 'video', 'preview_kind' => 'video', 'size' => strlen('preview-fixture'), 'label' => 'clip.mp4',
            'thumb_url'    => route('dam.share.file_thumbnail', ['token' => $token, 'attachment' => $this->files['clip']]),
            'preview_url'  => route('dam.share.file_preview', ['token' => $token, 'attachment' => $this->files['clip']]),
            'download_url' => route('dam.share.file_download', ['token' => $token, 'attachment' => $this->files['clip']]),
        ])
        ->and($byName['chair.glb'])->toMatchArray(['kind' => 'model', 'preview_kind' => 'none', 'preview_url' => null])
        ->and($byName['spec.pdf'])->toMatchArray(['kind' => 'pdf', 'preview_kind' => 'pdf']);

    $previewOnly = Share::factory()->forDirectory($this->folder->id)->create(['allow_download' => false]);
    $closed = collect(app(SharedFiles::class)->present(share: $previewOnly, asset: $this->asset, token: $previewOnly->urlToken(), memberAssetId: $this->asset->id))->keyBy('name');

    expect($closed->pluck('download_url')->filter()->all())->toBe([])
        ->and($closed['clip.mp4'])->toMatchArray(['preview_kind' => 'none', 'preview_url' => null])
        ->and($closed['spec.pdf'])->toMatchArray(['preview_kind' => 'none', 'preview_url' => null])
        ->and($closed['angle.png'])->toMatchArray([
            'preview_kind' => 'image',
            'preview_url'  => route('dam.share.asset_file_preview', ['token' => $previewOnly->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $this->files['angle']]),
        ]);
});

it('lists each card\'s file count in the folder link\'s JSON listing', function () {
    $single = Asset::factory()->create(['file_name' => 'single.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/StackPreview/single.png']);
    Storage::disk(Directory::getAssetDisk())->put($single->path, fakePngBytes(4, 4));
    $single->markGraphContentChanged()->save();
    $single->directories()->attach($this->folder->id);

    $data = collect($this->getJson(route('dam.share.list_assets', $this->folderLink->urlToken()))->assertOk()->json('data'))->keyBy('id');

    expect($data[$this->asset->id]['file_count'])->toBe(6)
        ->and($data[$single->id]['file_count'])->toBe(1);
});

/** A 1x1 two-frame GIF89a (black, then white, looping): GD can write only a single frame. */
function stackPreviewAnimatedGif(): string
{
    $frame = fn (string $pixels): string => "\x21\xF9\x04\x00\x0A\x00\x00\x00"."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00".$pixels;

    return "GIF89a\x01\x00\x01\x00\x80\x00\x00"."\x00\x00\x00\xFF\xFF\xFF"
        ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"
        .$frame("\x02\x02\x44\x01\x00")
        .$frame("\x02\x02\x4C\x01\x00")
        .';';
}

it('PD8: previews and thumbnails a stacked GIF as a one-frame WebP still on a preview-only link, never the animated derivative', function () {
    $bytes = stackPreviewAnimatedGif();
    $decoder = new ImageManager(new Driver);
    expect($decoder->decode($bytes)->count())->toBe(2);

    $gif = attachPreviewFile($this->asset, 'spin.gif', 'image/gif', AssetFile::ROLE_ALTERNATE, label: 'spin.gif');
    $disk = Storage::disk($gif->fileObject->disk);
    $disk->put($gif->fileObject->path, $bytes);
    DB::table('dam_file_objects')->where('id', $gif->file_object_id)->update(['size' => strlen($bytes)]);

    // An animated copy another path cached for the same blob is never what the share serves.
    $sha = (string) $gif->fileObject->sha256;
    $disk->put(DerivativeImages::thumbnail($sha, 'gif'), $bytes);
    $disk->put(DerivativeImages::preview($sha, 1920, 'gif'), $bytes);

    $previewOnly = Share::factory()->forDirectory($this->folder->id)->create(['allow_download' => false]);
    $slot = ['token' => $previewOnly->urlToken(), 'assetId' => $this->asset->id, 'attachment' => (int) $gif->attachment_id];

    foreach (['dam.share.asset_file_preview', 'dam.share.asset_file_thumbnail'] as $route) {
        // Twice: the first answer is made, the second is read back from its own cache.
        foreach ([1, 2] as $pass) {
            $response = $this->get(route($route, $slot))->assertOk();
            $body = stackPreviewBody($response);

            expect((string) $response->headers->get('Content-Type'))->toStartWith('image/webp', "{$route} #{$pass}")
                ->and(substr($body, 8, 4))->toBe('WEBP')
                ->and($decoder->decode($body)->count())->toBe(1, "{$route} #{$pass}")
                ->and(array_slice((array) getimagesizefromstring($body), 0, 2))->toBe([1, 1], "never upscaled: {$route}");
        }
    }

    expect($previewOnly->fresh()->download_count)->toBe(0);
});

it('PD8: a main GIF previews and thumbnails as a one-frame WebP still on a preview-only link, and stays the original with downloads on', function () {
    $bytes = stackPreviewAnimatedGif();
    $decoder = new ImageManager(new Driver);
    $gif = Asset::factory()->create(['file_name' => 'spin.gif', 'extension' => 'gif', 'file_type' => 'image', 'mime_type' => 'image/gif', 'path' => 'assets/StackPreview/spin.gif']);
    Storage::disk(Directory::getAssetDisk())->put($gif->path, $bytes);
    $gif->markGraphContentChanged()->save();
    $gif->directories()->attach($this->folder->id);

    // An animated thumbnail another path cached for the same blob is never what a preview-only link serves.
    $sha = hash('sha256', $bytes);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::thumbnail($sha, 'gif'), $bytes);

    $closed = Share::factory()->forAsset($gif->id)->create(['allow_download' => false]);
    $open = Share::factory()->forAsset($gif->id)->create();
    $closedFolder = Share::factory()->forDirectory($this->folder->id)->create(['allow_download' => false]);

    foreach ([
        'asset link preview'    => route('dam.share.preview', ['token' => $closed->urlToken()]),
        'asset link thumbnail'  => route('dam.share.thumbnail', ['token' => $closed->urlToken(), 'assetId' => $gif->id]),
        'folder link preview'   => route('dam.share.asset_preview', ['token' => $closedFolder->urlToken(), 'assetId' => $gif->id]),
        'folder link thumbnail' => route('dam.share.thumbnail', ['token' => $closedFolder->urlToken(), 'assetId' => $gif->id]),
    ] as $label => $url) {
        // Twice: the first answer is made, the second is read back from its own cache.
        foreach ([1, 2] as $pass) {
            $body = stackPreviewBody($this->get($url)->assertOk());

            expect(substr($body, 8, 4))->toBe('WEBP', "{$label} #{$pass}")
                ->and($decoder->decode($body)->count())->toBe(1, "{$label} #{$pass}");
        }
    }

    // Positive control: with downloads on, the default preview is the original animated GIF.
    $original = stackPreviewBody($this->get(route('dam.share.preview', ['token' => $open->urlToken()]))->assertOk());
    expect($original)->toBe($bytes)->and($decoder->decode($original)->count())->toBe(2);
    // Positive control: with downloads on, the main GIF's thumbnail stays animated. It is the link's own share-* copy,
    // never the `thumbnail.gif` another path cached above.
    $thumbnail = stackPreviewBody($this->get(route('dam.share.thumbnail', ['token' => $open->urlToken(), 'assetId' => $gif->id]))->assertOk());
    expect(substr($thumbnail, 0, 6))->toBe('GIF89a')->and($decoder->decode($thumbnail)->count())->toBe(2)
        ->and(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::shareStill($sha, 'thumbnail', 'gif')))->toBeTrue();
    expect($closed->fresh()->download_count)->toBe(0);
});
