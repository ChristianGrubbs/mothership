<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Share\SharedFiles;

/*
 * OD5 (2026-10-19): a share link hands out the current revision of every stacked file of each asset it reaches, the main
 * file included, and nothing else: never a generated proxy or thumbnail, a superseded revision or another asset's slot.
 * Each answer follows the link (expired, revoked, locked, preview-only) and the asset's lifecycle exactly as the default
 * download does.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Stack files '.uniqid()]);
    $this->asset = stackFilesAsset('poster.png', $this->folder);
});

/** An asset whose original holds `MAIN-<name>`, filed in `$folder` when one is given. */
function stackFilesAsset(string $name, ?Directory $folder = null): Asset
{
    $asset = Asset::factory()->create(['file_name' => $name, 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/'.$name]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'MAIN-'.$name);
    $asset->markGraphContentChanged()->save();

    if ($folder !== null) {
        $asset->directories()->attach($folder->id);
    }

    return $asset;
}

/** Stacks a file on an asset through the Files tab endpoint, as an editor would; returns its slot id. */
function stackFilesAttach(object $test, Asset $asset, string $name, string $content, string $role): int
{
    return (int) $test->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => $role,
    ])->assertCreated()->json('attachment_id');
}

function stackFilesMainSlot(Asset $asset): int
{
    return (int) DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->where('is_representative', true)->value('id');
}

/** The downloaded bytes of a local-disk delivery. */
function stackFilesBytes($response): string
{
    return $response->baseResponse->getFile()->getContent();
}

function stackFilesSignOut(): void
{
    Auth::guard('admin')->logout();
    expect(Auth::guard('admin')->check())->toBeFalse();
}

it('serves the main file and every stacked file on asset and folder links, never a generated one', function () {
    $sidecar = stackFilesAttach($this, $this->asset, 'grade.xmp', 'SIDECAR', AssetFile::ROLE_SIDECAR);
    $project = stackFilesAttach($this, $this->asset, 'poster.psd', 'PROJECT', AssetFile::ROLE_PROJECT);
    $proxy = stackFilesAttach($this, $this->asset, 'poster-proxy.mp4', 'PROXY', AssetFile::ROLE_PROXY);
    $thumb = stackFilesAttach($this, $this->asset, 'poster-thumb.jpg', 'THUMB', AssetFile::ROLE_THUMBNAIL);
    $main = stackFilesMainSlot($this->asset);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    stackFilesSignOut();

    $urls = fn (int $attachment): array => [
        route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $attachment]),
        route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $attachment]),
    ];

    foreach ([$main => ['poster.png', 'MAIN-poster.png'], $sidecar => ['grade.xmp', 'SIDECAR'], $project => ['poster.psd', 'PROJECT']] as $attachment => [$name, $bytes]) {
        foreach ($urls($attachment) as $url) {
            expect(stackFilesBytes($this->get($url)->assertOk()->assertDownload($name)))->toBe($bytes);
        }
    }

    foreach ([$proxy, $thumb] as $attachment) {
        foreach ($urls($attachment) as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    expect(app(SharedFiles::class)->forAsset($this->asset)->map(fn (AssetFile $file) => (int) $file->attachment_id)->all())->toBe([$main, $sidecar, $project])
        ->and($assetLink->fresh()->download_count)->toBe(3)
        ->and($folderLink->fresh()->download_count)->toBe(3);
});

it('answers 404 for a neighbour\'s slot, an asset outside the folder and a slot with only superseded revisions', function () {
    $neighbour = stackFilesAsset('neighbour.png', $this->folder);
    $outside = stackFilesAsset('outside.png');
    $theirs = stackFilesAttach($this, $neighbour, 'theirs.xmp', 'THEIRS', AssetFile::ROLE_SIDECAR);
    $outsideSlot = stackFilesAttach($this, $outside, 'out.xmp', 'OUT', AssetFile::ROLE_SIDECAR);
    $retired = stackFilesAttach($this, $this->asset, 'old.xmp', 'OLD', AssetFile::ROLE_SIDECAR);
    AssetFile::query()->where('attachment_id', $retired)->update(['is_current' => false]);
    // A superseded revision of the main file joins the main slot (AssetFile::assignImpliedSlot) and stays private.
    attachPreviewFile($this->asset, 'poster-v0.png', 'image/png', AssetFile::ROLE_VERSION);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    stackFilesSignOut();

    $asset = fn (int $attachment) => route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $attachment]);
    $member = fn (int $assetId, int $attachment) => route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $assetId, 'attachment' => $attachment]);

    foreach ([
        'neighbour via asset link'   => $asset($theirs),
        'neighbour via folder link'  => $member($this->asset->id, $theirs),
        'outside via asset link'     => $asset($outsideSlot),
        'outside via folder link'    => $member($this->asset->id, $outsideSlot),
        'assetId outside the folder' => $member($outside->id, $outsideSlot),
        'superseded via asset link'  => $asset($retired),
        'superseded via folder link' => $member($this->asset->id, $retired),
    ] as $case => $url) {
        expect($this->get($url)->getStatusCode())->toBe(404, $case);
    }

    // The main slot still serves its current revision, never the superseded one.
    expect(stackFilesBytes($this->get($asset(stackFilesMainSlot($this->asset)))->assertOk()))->toBe('MAIN-poster.png')
        ->and($assetLink->fresh()->download_count)->toBe(1)
        ->and($folderLink->fresh()->download_count)->toBe(0);
});

it('answers a stacked file by the link and the asset\'s lifecycle, and counts nothing it refuses', function () {
    $notes = stackFilesAttach($this, $this->asset, 'notes.txt', 'NOTES', AssetFile::ROLE_SIDECAR);
    $expired = Share::factory()->forAsset($this->asset->id)->expired()->create();
    $revoked = Share::factory()->forAsset($this->asset->id)->revoked()->create();
    $locked = Share::factory()->forAsset($this->asset->id)->create();
    $locked->forceFill(['password_hash' => Hash::make('pw-'.bin2hex(random_bytes(8)))])->save();
    $previewOnly = Share::factory()->forAsset($this->asset->id)->create(['allow_download' => false]);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    stackFilesSignOut();

    $viaAsset = fn (Share $link): int => $this->get(route('dam.share.file_download', ['token' => $link->urlToken(), 'attachment' => $notes]))->getStatusCode();
    $viaFolder = fn (): int => $this->get(route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $notes]))->getStatusCode();

    expect($viaAsset($expired))->toBe(410)
        ->and($viaAsset($revoked))->toBe(410)
        ->and($viaAsset($locked->fresh()))->toBe(401)
        ->and($viaAsset($previewOnly))->toBe(403);

    $this->asset->update(['availability' => Asset::AVAILABILITY_PENDING_REVIEW]);
    expect($viaAsset($assetLink))->toBe(404, 'pending, asset link')
        ->and($viaFolder())->toBe(404, 'pending, folder link');

    $this->asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
    $this->asset->delete();
    expect($viaAsset($assetLink))->toBe(410, 'trashed, asset link')
        ->and($viaFolder())->toBe(404, 'trashed, folder link')
        ->and((int) Share::query()->sum('download_count'))->toBe(0);
});

it('lists a stacked working file on the shared page with its own download, and never a generated one', function () {
    $notes = stackFilesAttach($this, $this->asset, 'notes.txt', 'NOTES', AssetFile::ROLE_SIDECAR);
    stackFilesAttach($this, $this->asset, 'poster-proxy.mp4', 'PROXY', AssetFile::ROLE_PROXY);
    $share = Share::factory()->forAsset($this->asset->id)->create();
    stackFilesSignOut();

    expect($this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent())
        ->toContain('notes.txt')
        ->toContain(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $notes]))
        ->not->toContain('poster-proxy.mp4');
});

it('serves a merge source\'s link only its own stacked files, never the destination\'s, with the main file under the source\'s name', function () {
    $source = stackFilesAsset('source.png', $this->folder);
    $sourceSidecar = stackFilesAttach($this, $source, 'source.xmp', 'SOURCE-SIDECAR', AssetFile::ROLE_SIDECAR);
    $sourceProxy = stackFilesAttach($this, $source, 'source-proxy.mp4', 'SOURCE-PROXY', AssetFile::ROLE_PROXY);
    $destSidecar = stackFilesAttach($this, $this->asset, 'dest.xmp', 'DEST-SIDECAR', AssetFile::ROLE_SIDECAR);
    $destProxy = stackFilesAttach($this, $this->asset, 'dest-proxy.mp4', 'DEST-PROXY', AssetFile::ROLE_PROXY);
    $destMain = stackFilesMainSlot($this->asset);
    $sourceMain = stackFilesMainSlot($source);
    $link = Share::factory()->forAsset($source->id)->create();

    $this->postJson(route('admin.dam.assets.merges.store', $this->asset->id), ['sources' => [$source->id]])->assertCreated();
    $linked = fn (int $origin): int => (int) Attachment::query()->where('dam_asset_id', $this->asset->id)->where('origin_attachment_id', $origin)->sole()->id;
    $linkedMain = $linked($sourceMain);
    $linkedSidecar = $linked($sourceSidecar);
    stackFilesSignOut();

    $url = fn (int $attachment): string => route('dam.share.file_download', ['token' => $link->urlToken(), 'attachment' => $attachment]);

    // Its own files, now destination slots: the main one downloads under the source's name, not the destination's.
    expect(stackFilesBytes($this->get($url($linkedMain))->assertOk()->assertDownload('source.png')))->toBe('MAIN-source.png')
        ->and(stackFilesBytes($this->get($url($linkedSidecar))->assertOk()->assertDownload('source.xmp')))->toBe('SOURCE-SIDECAR');

    // The destination's own files, generated ones included, and the source's pre-merge slots are not this link's.
    foreach (['dest main' => $destMain, 'dest sidecar' => $destSidecar, 'dest proxy' => $destProxy, 'source proxy' => $sourceProxy, 'source pre-merge main' => $sourceMain, 'source pre-merge sidecar' => $sourceSidecar] as $case => $attachment) {
        expect($this->get($url($attachment))->getStatusCode())->toBe(404, $case);
    }

    expect(app(SharedFiles::class)->forAsset($source->fresh())->map(fn (AssetFile $file) => (int) $file->attachment_id)->all())->toBe([$linkedMain, $linkedSidecar])
        ->and($link->fresh()->download_count)->toBe(2);
});

it('refuses a merge source\'s default and per-slot routes once its main slot is re-roled to a generated role, counting nothing', function () {
    $png = fakePngBytes(8, 8);
    $source = Asset::factory()->create(['file_name' => 'source.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Stack/merge-'.uniqid().'.png']);
    Storage::disk(Directory::getAssetDisk())->put($source->path, $png);
    $source->markGraphContentChanged()->save();
    $sourceMain = stackFilesMainSlot($source);
    $link = Share::factory()->forAsset($source->id)->create();

    $this->postJson(route('admin.dam.assets.merges.store', $this->asset->id), ['sources' => [$source->id]])->assertCreated();
    $linkedMain = (int) Attachment::query()->where('dam_asset_id', $this->asset->id)->where('origin_attachment_id', $sourceMain)->sole()->id;
    $originalRole = (string) Attachment::query()->findOrFail($linkedMain)->role;

    $token = $link->urlToken();
    $slot = ['token' => $token, 'attachment' => $linkedMain];
    $routes = [
        'default download'  => route('dam.share.download', $token),
        'default preview'   => route('dam.share.preview', $token),
        'default thumbnail' => route('dam.share.thumbnail', ['token' => $token, 'assetId' => $source->id]),
        'slot download'     => route('dam.share.file_download', $slot),
        'slot preview'      => route('dam.share.file_preview', $slot),
        'slot thumbnail'    => route('dam.share.file_thumbnail', $slot),
        'files zip'         => route('dam.share.files_zip', $token),
    ];

    // Positive control: while the slot keeps its own role, every route serves it.
    stackFilesSignOut();
    foreach ($routes as $case => $url) {
        expect($this->get($url)->getStatusCode())->toBe(200, "control: {$case}");
    }
    $counted = (int) $link->fresh()->download_count;
    expect($counted)->toBeGreaterThan(0);

    $excluded = array_values(array_intersect(Asset::UNSTACKED_ROLES, AssetFile::ATTACHABLE_ROLES));
    expect($excluded)->toContain(AssetFile::ROLE_PROXY)->toContain(AssetFile::ROLE_THUMBNAIL);

    foreach ($excluded as $role) {
        $this->loginAsAdmin();
        $this->patchJson(route('admin.dam.assets.attachments.update', ['asset' => $this->asset->id, 'attachment' => $linkedMain]), ['role' => $role])->assertOk();
        stackFilesSignOut();

        foreach ($routes as $case => $url) {
            expect($this->get($url)->getStatusCode())->toBe(404, "{$role}: {$case}");
        }
        expect($link->fresh()->download_count)->toBe($counted, $role)
            ->and(app(SharedFiles::class)->forAsset($source->fresh())->all())->toBe([], $role);

        $this->loginAsAdmin();
        $this->patchJson(route('admin.dam.assets.attachments.update', ['asset' => $this->asset->id, 'attachment' => $linkedMain]), ['role' => $originalRole])->assertOk();
    }
});

it('shows the file panel with every row, the zip and the main pill on asset and folder links', function () {
    $notes = stackFilesAttach($this, $this->asset, 'notes.txt', 'NOTES', AssetFile::ROLE_SIDECAR);
    $main = stackFilesMainSlot($this->asset);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    stackFilesSignOut();
    $a = $assetLink->urlToken();
    $f = $folderLink->urlToken();

    foreach ([
        [route('dam.share.show', $a), route('dam.share.files_zip', $a), route('dam.share.file_download', ['token' => $a, 'attachment' => $notes])],
        [route('dam.share.asset_view', ['token' => $f, 'assetId' => $this->asset->id]), route('dam.share.asset_files_zip', ['token' => $f, 'assetId' => $this->asset->id]), route('dam.share.asset_file_download', ['token' => $f, 'assetId' => $this->asset->id, 'attachment' => $notes])],
    ] as [$page, $zip, $download]) {
        expect($this->get($page)->assertOk()->getContent())
            ->toContain('<v-dam-share-files')
            ->toContain('data-dam-share-files')
            ->toContain('data-dam-share-files-strip')
            ->toContain('data-dam-share-file="'.$main.'" data-dam-share-file-main="true"')
            ->toContain('data-dam-share-file="'.$notes.'" data-dam-share-file-main="false"')
            ->toContain('href="'.$zip.'"')
            ->toContain('href="'.$download.'"')
            ->toContain(e(trans('dam::app.share.public.files.download-all', ['count' => 2])))
            ->toContain(e(trans('dam::app.share.public.files.main')))
            ->toContain(e(trans('dam::app.share.public.files.download-main')));
    }
});

it('shows a preview-only link\'s files with View buttons and no download or zip control', function () {
    $notes = stackFilesAttach($this, $this->asset, 'notes.txt', 'NOTES', AssetFile::ROLE_SIDECAR);
    $share = Share::factory()->forAsset($this->asset->id)->create(['allow_download' => false]);
    stackFilesSignOut();

    expect($this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent())
        ->toContain('data-dam-share-file="'.$notes.'"')
        ->toContain('panel.select('.$notes.')')
        ->toContain('data-dam-share-preview-only')
        ->not->toContain('/download')
        ->not->toContain('files-zip')
        ->not->toContain('icon-dam-download');
});

it('badges a folder card with its file count only when it holds more than one file', function () {
    stackFilesAttach($this, $this->asset, 'notes.txt', 'NOTES', AssetFile::ROLE_SIDECAR);
    stackFilesAttach($this, $this->asset, 'grade.xmp', 'SIDECAR', AssetFile::ROLE_SIDECAR);
    stackFilesAsset('single.png', $this->folder);
    $share = Share::factory()->forDirectory($this->folder->id)->create();
    stackFilesSignOut();

    $html = $this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent();

    expect(substr_count($html, 'data-dam-share-file-count'))->toBe(1)
        ->and($html)->toContain(e(trans_choice('dam::app.share.public.files-count', 3, ['count' => 3])));
});

it('shows the no-preview card instead of the 3D viewer on a preview-only link', function () {
    $model = Asset::factory()->create(['file_name' => 'chair.glb', 'file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb', 'path' => 'assets/Stack/chair.glb']);
    $closed = Share::factory()->forAsset($model->id)->create(['allow_download' => false]);
    $open = Share::factory()->forAsset($model->id)->create();
    stackFilesSignOut();

    expect($this->get(route('dam.share.show', $closed->urlToken()))->assertOk()->getContent())
        ->not->toContain('<v-dam-model-viewer')
        ->toContain(e(trans('dam::app.share.public.gate.no-preview')));
    expect($this->get(route('dam.share.show', $open->urlToken()))->assertOk()->getContent())->toContain('<v-dam-model-viewer');
});

it('lists a related video on a preview-only link without any preview URL for it, so the page shows the no-preview card', function () {
    $clip = stackFilesAttach($this, $this->asset, 'clip.mp4', 'VIDEO-BYTES', AssetFile::ROLE_OUTPUT);
    // The upload's MIME type is sniffed from its bytes; the row must read as the video it is named for.
    DB::table('dam_file_objects')->whereIn('id', AssetFile::where('attachment_id', $clip)->pluck('file_object_id'))->update(['mime_type' => 'video/mp4']);
    $open = Share::factory()->forAsset($this->asset->id)->create();
    $closed = Share::factory()->forAsset($this->asset->id)->create(['allow_download' => false]);
    stackFilesSignOut();
    $row = 'data-dam-share-file="'.$clip.'"';

    // Positive control: with downloads on the same row is offered a preview of the original.
    expect($this->get(route('dam.share.show', $open->urlToken()))->assertOk()->getContent())
        ->toContain($row)
        ->toContain('file\\/'.$clip.'\\/preview'); // the row's preview URL, JSON-escaped in the component's :files

    $html = $this->get(route('dam.share.show', $closed->urlToken()))->assertOk()->getContent();
    $noPreview = collect(app(SharedFiles::class)->present(share: $closed, asset: $this->asset, token: $closed->urlToken(), memberAssetId: null))
        ->firstWhere('attachment_id', $clip);

    expect($html)->toContain($row)
        ->and($html)->toContain('panel.select('.$clip.')')
        // The stage shows the no-preview card for exactly this file: its row carries neither a preview nor a download URL.
        ->and($noPreview)->toMatchArray(['preview_url' => null, 'download_url' => null])
        ->and($noPreview['attachment_id'])->toBe($clip)
        ->and($html)->not->toContain('file\\/'.$clip.'\\/preview')
        ->and($html)->not->toContain('/file/'.$clip.'/preview');
});
