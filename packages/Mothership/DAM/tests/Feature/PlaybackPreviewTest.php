<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

// attachPreviewFile() and actAsPlaybackRestrictedAdmin() moved to
// tests/Support/DamTestHelpers.php so they are available to every DAM
// Feature spec file, including one run in isolation via a single-file
// verify.py invocation.

function attachPreviewAssetToDirectory(Asset $asset, Directory $directory): Asset
{
    $asset->directories()->attach($directory->id);

    return $asset;
}

it('routes imported 2:1 PNG and JPEG originals to the image panorama viewer', function (string $extension, string $mime) {
    $asset = Asset::factory()->create([
        'file_name'     => 'panorama.'.$extension,
        'file_type'     => 'image',
        'mime_type'     => $mime,
        'extension'     => $extension,
        'path'          => 'assets/Root/panorama.'.$extension,
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => ['legacy' => ['fields' => ['tech_resolution' => '3840x1920']]],
    ]);
    putAssetContent($asset, 'original-image');

    expect($asset->playbackSource())->toMatchArray([
        'kind'  => 'pano360-image',
        'mime'  => $mime,
        'label' => 'Original',
    ]);
})->with([
    'png'  => ['png', 'image/png'],
    'jpeg' => ['jpeg', 'image/jpeg'],
]);

it('picks the stitched 360 proxy for an osv asset', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'video/mp4',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    attachPreviewFile($asset, 'assets/Root/flight-lrf.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Original DJI Low-Resolution Proxy (LRF)', 0);
    $stitched = attachPreviewFile($asset, 'assets/Root/flight-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview (equirectangular)', 10, ['codec_name' => 'h264']);

    expect($asset->playbackSource())
        ->toMatchArray([
            'mime'  => 'video/mp4',
            'kind'  => 'pano360-video',
            'label' => 'Stitched 360 Preview (equirectangular)',
        ])
        ->and($asset->playbackSource()['url'])
        ->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $stitched->id]));
});

it('uses a browser playable mp4 original before proxies', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);

    putAssetContent($asset, 'original-video');
    attachPreviewFile($asset, 'assets/Root/clip-proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview', 0);

    expect($asset->playbackSource())
        ->toMatchArray([
            'mime'  => 'video/mp4',
            'kind'  => 'video',
            'label' => 'Original',
        ]);
});

it('falls back to a proxy when a playable original is missing from storage', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/missing-original.mp4',
    ]);
    $proxy = attachPreviewFile($asset, 'assets/Root/available-proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Available proxy');

    expect($asset->playbackSource())
        ->toMatchArray([
            'kind'  => 'video',
            'label' => 'Available proxy',
        ])
        ->and($asset->playbackSource()['url'])
        ->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]));
});

it('skips a missing stitched proxy and selects the next available one', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);
    $missing = attachPreviewFile($asset, 'assets/Root/missing-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview A', 0);
    Storage::disk(Directory::getAssetDisk())->delete($missing->fileObject->path);
    $available = attachPreviewFile($asset, 'assets/Root/available-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview B', 10);

    expect($asset->playbackSource()['url'])
        ->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $available->id]));
});

it('returns null when every browser playable candidate is missing from storage', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/missing-original.mp4',
    ]);
    $missing = attachPreviewFile($asset, 'assets/Root/missing-proxy.webm', 'video/webm', AssetFile::ROLE_PROXY, 'Missing proxy', technical: ['codec_name' => 'vp9']);
    Storage::disk(Directory::getAssetDisk())->delete($missing->fileObject->path);

    expect($asset->playbackSource())->toBeNull();
});

it('accepts supported browser video families', function (
    string $source,
    string $extension,
    string $mime,
    ?array $technical,
) {
    $asset = Asset::factory()->create([
        'file_name'  => $source === 'original' ? 'clip.'.$extension : 'flight.osv',
        'file_type'  => $source === 'original' ? 'video' : 'document',
        'mime_type'  => $source === 'original' ? $mime : 'application/octet-stream',
        'extension'  => $source === 'original' ? $extension : 'osv',
        'path'       => $source === 'original' ? 'assets/Root/clip.'.$extension : 'assets/Root/flight.osv',
        'asset_kind' => $source === 'original' ? 'video' : 'pano360',
        'meta_data'  => $source === 'original' ? $technical : null,
    ]);

    if ($source === 'original') {
        putAssetContent($asset, 'browser-video');
    } else {
        attachPreviewFile($asset, 'assets/Root/proxy.'.$extension, $mime, AssetFile::ROLE_PROXY, 'Browser proxy', technical: $technical);
    }

    expect($asset->playbackSource())
        ->not->toBeNull()
        ->and($asset->playbackSource()['kind'])->toBe('video');
})->with([
    'VP8 WebM original'       => ['original', 'webm', 'video/webm', ['codec_name' => 'vp8']],
    'VP9 WebM proxy'          => ['proxy', 'webm', 'video/webm', ['codec_name' => 'vp9']],
    'codec-absent WebM'       => ['proxy', 'webm', 'video/webm', null],
    'M4V proxy'               => ['proxy', 'm4v', 'video/x-m4v', ['codec_name' => 'h264']],
    'parameterized WebM MIME' => ['proxy', 'bin', 'video/webm; codecs=vp8', ['codec_name' => 'vp8']],
]);

it('falls back to a flat mp4 proxy when no stitched proxy exists', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    attachPreviewFile($asset, 'assets/Root/flight-lrf.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Unstitched LRF Proxy (Flat MP4)');

    expect($asset->playbackSource())
        ->toMatchArray([
            'kind'  => 'video',
            'label' => 'Unstitched LRF Proxy (Flat MP4)',
        ]);
});

it('returns null when the original and attachments are not browser playable', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    attachPreviewFile($asset, 'assets/Root/flight.srt', 'application/x-subrip', AssetFile::ROLE_SIDECAR, 'Telemetry');

    expect($asset->playbackSource())->toBeNull();
});

it('uses an equirectangular image in the 360 viewer', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'room.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/room.osv',
        'asset_kind' => 'pano360',
    ]);

    attachPreviewFile($asset, 'assets/Root/room.jpg', 'image/jpeg', AssetFile::ROLE_PROXY, 'Stitched 360 Panorama');

    expect($asset->playbackSource()['kind'])->toBe('pano360-image');
});

it('classifies a 2:1 original jpeg as a direct pano360 image', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'room.jpg',
        'file_type' => 'image',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'path'      => 'assets/Root/room.jpg',
        'meta_data' => ['ImageWidth' => 4000, 'ImageHeight' => 2000],
    ]);
    putAssetContent($asset, 'original-image');

    expect($asset->playbackSource())
        ->toMatchArray(['kind' => 'pano360-image'])
        ->and($asset->panoramaClassification())
        ->toMatchArray(['is_panorama' => true, 'direct_candidate' => true, 'requires_proxy' => false]);
});

it('classifies a 2:1 h264 original mp4 as a direct pano360 video', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'flight.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/flight.mp4',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 1920, 'codec_name' => 'h264'],
    ]);
    putAssetContent($asset, 'original-video');

    expect($asset->playbackSource())
        ->toMatchArray(['kind' => 'pano360-video'])
        ->and($asset->panoramaClassification())
        ->toMatchArray(['is_panorama' => true, 'direct_candidate' => true, 'requires_proxy' => false]);
});

it('classifies a 2:1 h264 original mov as a direct pano360 video', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'flight.mov',
        'file_type' => 'video',
        'mime_type' => 'video/quicktime',
        'extension' => 'mov',
        'path'      => 'assets/Root/flight.mov',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 1920, 'codec_name' => 'h264'],
    ]);
    putAssetContent($asset, 'original-video');

    expect($asset->playbackSource())
        ->not->toBeNull()
        ->toMatchArray(['kind' => 'pano360-video'])
        ->and($asset->panoramaClassification())
        ->toMatchArray(['is_panorama' => true, 'direct_candidate' => true, 'requires_proxy' => false]);
});

it('requires a proxy for a 2:1 hevc original mov and serves no raw playback source', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'flight.mov',
        'file_type' => 'video',
        'mime_type' => 'video/quicktime',
        'extension' => 'mov',
        'path'      => 'assets/Root/flight.mov',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 1920, 'codec_name' => 'hevc'],
    ]);
    putAssetContent($asset, 'original-video');

    expect($asset->playbackSource())->toBeNull()
        ->and($asset->panoramaClassification())
        ->toMatchArray(['is_panorama' => true, 'direct_candidate' => false, 'requires_proxy' => true]);
});

it('never serves an osv original raw to the browser', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);
    putAssetContent($asset, 'osv-bytes');

    expect($asset->playbackSource())->toBeNull()
        ->and($asset->panoramaClassification())
        ->toMatchArray(['is_panorama' => true, 'direct_candidate' => false, 'requires_proxy' => true]);
});

it('selects the newest completed generated proxy over a migrated stitched proxy', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    attachPreviewFile($asset, 'assets/Root/flight-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview (equirectangular)', 0, ['codec_name' => 'h264']);

    $olderGeneratedObject = FileObject::factory()->create([
        'disk'      => Directory::getAssetDisk(),
        'sha256'    => hash('sha256', 'flight-generated-old'),
        'size'      => strlen('older-generated'),
        'path'      => app(ContentStore::class)->objectPath(hash('sha256', 'flight-generated-old'), 'mp4'),
        'mime_type' => 'video/mp4',
        'technical' => ['codec_name' => 'h264'],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($olderGeneratedObject->path, 'older-generated');
    $olderGeneratedFile = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $olderGeneratedObject->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => 'Generated 360 Preview',
        'sort'           => 20,
    ]);
    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'recipe'                => Rendition::RECIPE_PANORAMA_4K,
        'status'                => Rendition::STATUS_DONE,
        'output_file_object_id' => $olderGeneratedObject->id,
        'finished_at'           => now()->subHour(),
    ]);

    $newerGeneratedObject = FileObject::factory()->create([
        'disk'      => Directory::getAssetDisk(),
        'sha256'    => hash('sha256', 'flight-generated-new'),
        'size'      => strlen('newer-generated'),
        'path'      => app(ContentStore::class)->objectPath(hash('sha256', 'flight-generated-new'), 'mp4'),
        'mime_type' => 'video/mp4',
        'technical' => ['codec_name' => 'h264'],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($newerGeneratedObject->path, 'newer-generated');
    $newerGeneratedFile = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $newerGeneratedObject->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => 'Generated 360 Preview',
        'sort'           => 30,
    ]);
    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'recipe'                => Rendition::RECIPE_PANORAMA_4K,
        'status'                => Rendition::STATUS_DONE,
        'output_file_object_id' => $newerGeneratedObject->id,
        'finished_at'           => now(),
    ]);

    expect($asset->fresh()->playbackSource())
        ->toMatchArray([
            'kind' => 'pano360-video',
        ])
        ->and($asset->fresh()->playbackSource()['url'])
        ->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $newerGeneratedFile->id]));
});

it('exposes a vtt sidecar as an authorized caption source', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);

    $caption = attachPreviewFile($asset, 'assets/Root/clip.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'English captions');

    expect($asset->captionSource())
        ->toMatchArray([
            'url'   => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $caption->id]),
            'mime'  => 'text/vtt',
            'label' => 'English captions',
        ]);
});

it('prefers the first sorted vtt across subtitle and sidecar roles', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);

    attachPreviewFile($asset, 'assets/Root/commentary.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'Commentary', 10);
    $subtitle = attachPreviewFile($asset, 'assets/Root/english.vtt', 'text/vtt; charset=utf-8', AssetFile::ROLE_SUBTITLE, 'English', 0);

    expect($asset->captionSource())
        ->toMatchArray([
            'url'   => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $subtitle->id]),
            'mime'  => 'text/vtt; charset=utf-8',
            'label' => 'English',
        ]);
});

it('serves playback attachments only through their owning asset', function () {
    $asset = Asset::factory()->create();
    $other = Asset::factory()->create();
    $proxy = attachPreviewFile($asset, 'assets/Root/private-proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Private proxy');

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');

    $this->get(route('admin.dam.file.asset-file', ['asset' => $other->id, 'assetFile' => $proxy->id]))
        ->assertNotFound();
});

it('serves an owned original path to an admin with view and directory access', function () {
    $directory = Directory::factory()->create();
    $path = 'assets/Allowed/original.jpg';
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(['path' => $path]), $directory);
    putAssetContent($asset, 'allowed-original');
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.fetch', ['path' => $path]))
        ->assertOk();
});

it('denies an owned original path in an inaccessible directory', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $path = 'assets/Denied/original.jpg';
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(['path' => $path]), $deniedDirectory);
    putAssetContent($asset, 'denied-original');
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.fetch', ['path' => $path]))
        ->assertNotFound();
});

it('serves an owned attachment path to an admin with view and directory access', function () {
    $directory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $directory);
    $proxy = attachPreviewFile($asset, 'assets/Allowed/proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.fetch', ['path' => $proxy->fileObject->path]))
        ->assertOk();
});

it('denies an owned attachment path in an inaccessible directory', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $proxy = attachPreviewFile($asset, 'assets/Denied/proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.fetch', ['path' => $proxy->fileObject->path]))
        ->assertNotFound();
});

it('denies a shared attachment path when any owning asset is inaccessible', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $allowedAsset = attachPreviewAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $deniedAsset = attachPreviewAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $attachment = attachPreviewFile($allowedAsset, 'assets/Shared/proxy.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    AssetFile::factory()->create([
        'dam_asset_id'   => $deniedAsset->id,
        'file_object_id' => $attachment->file_object_id,
        'role'           => AssetFile::ROLE_PROXY,
    ]);
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.fetch', ['path' => $attachment->fileObject->path]))
        ->assertNotFound();
});

it('rejects a storage path that has no owning asset', function () {
    $directory = Directory::factory()->create();
    $path = 'assets/Allowed/unowned.txt';
    Storage::disk(Directory::getAssetDisk())->put($path, 'unowned');
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $this->getJson(route('admin.dam.file.fetch', ['path' => $path]))
        ->assertNotFound();
});

it('denies a playback attachment without the asset view permission', function () {
    $directory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $directory);
    $proxy = attachPreviewFile($asset, 'assets/Allowed/no-view.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.edit']);

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]))
        ->assertForbidden();
});

it('denies a playback attachment in an inaccessible directory', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $proxy = attachPreviewFile($asset, 'assets/Denied/private.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]))
        ->assertNotFound();
});

it('requires authentication for playback attachments', function () {
    $asset = Asset::factory()->create();
    $proxy = attachPreviewFile($asset, 'assets/Root/authenticated.mp4', 'video/mp4', AssetFile::ROLE_PROXY);
    auth()->guard('admin')->logout();

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]))
        ->assertRedirect(route('admin.session.create'));
});

it('serves a vtt caption only with asset view and directory access', function () {
    $directory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $directory);
    $caption = attachPreviewFile($asset, 'assets/Allowed/captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $response = $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $caption->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/vtt');
});

it('denies a vtt caption in an inaccessible directory', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $caption = attachPreviewFile($asset, 'assets/Denied/captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR);
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $caption->id]))
        ->assertNotFound();
});

it('denies caption attachments outside the users permitted directories', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($deniedDirectory);
    $caption = attachPreviewFile($asset, 'assets/Root/private-caption.vtt', 'text/vtt', AssetFile::ROLE_SUBTITLE, 'Private captions');

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $allowedDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $caption->id]))
        ->assertNotFound();
});

it('renders the 360 viewer for a pano asset and the flat player for a plain video', function () {
    $pano = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);
    attachPreviewFile($pano, 'assets/Root/flight-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview');
    attachPreviewFile($pano, 'assets/Root/flight.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'English captions');

    $panoHtml = view('dam::asset.preview-modal', [
        'asset'               => $pano,
        'directoryAncestors'  => collect(),
    ])->render();

    $video = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);
    attachPreviewFile($video, 'assets/Root/clip.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'English captions');
    putAssetContent($video, 'original-video');

    $videoHtml = view('dam::asset.preview-modal', [
        'asset'               => $video,
        'directoryAncestors'  => collect(),
    ])->render();

    expect($panoHtml)
        ->toContain('data-dam-360-viewer')
        ->toContain('pano360-video')
        ->toContain('data-dam-360-caption-control')
        ->toContain('data-dam-360-caption-overlay')
        ->toContain('v-for="(cue, index) in videoActiveCues"')
        ->toContain('this._panoRuntime = runtime')
        ->toContain('this.panoDisposeRuntime(runtime)')
        ->and($videoHtml)
        ->toContain('data-dam-flat-video-player')
        ->toContain('data-dam-caption-control')
        ->toContain('data-dam-caption-track')
        ->toContain('@load="videoOnCaptionTrackLoad"')
        ->toContain('"playbackKind":"video"');
});

it('selects the osv proxy as the video thumbnail source', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    $proxy = attachPreviewFile($asset, 'assets/Root/flight-stitched.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Stitched 360 Preview');
    $source = (new GenerateVideoThumbnail($asset->id))->source();

    expect($source)
        ->toMatchArray([
            'asset_file_id' => $proxy->id,
            'path'          => $proxy->fileObject->path,
            'kind'          => 'pano360-video',
        ]);
});

it('streams a remote video source and persists a generated thumbnail', function () {
    Storage::fake('remote-playback');
    // Keep the fake adapter, but exercise the remote-source branch.
    config(['filesystems.disks.remote-playback.driver' => 's3']);

    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);
    $proxy = attachPreviewFile(
        $asset,
        'remote/flight-stitched.mp4',
        'video/mp4',
        AssetFile::ROLE_PROXY,
        'Stitched 360 Preview',
        technical: ['codec_name' => 'h264'],
        disk: 'remote-playback',
    );
    $remoteBytes = str_repeat('large-video-chunk', 262_144);
    Storage::disk('remote-playback')->put($proxy->fileObject->path, $remoteBytes);
    // A read checks the blob holds its recorded size.
    $proxy->fileObject->forceFill(['size' => strlen($remoteBytes)])->save();

    $job = new class($asset->id) extends GenerateVideoThumbnail
    {
        public array $temporaryPaths = [];

        protected function temporaryFile(string $prefix, string $extension): string
        {
            $path = parent::temporaryFile($prefix, $extension);
            $this->temporaryPaths[] = $path;

            return $path;
        }

        protected function runFfmpeg(array $command): array
        {
            file_put_contents($command[array_key_last($command)], 'jpeg-thumbnail');

            return [true, ''];
        }
    };

    $job->handle();

    $posterPath = DerivativeImages::poster($proxy->fileObject->sha256);
    Storage::disk(Directory::getAssetDisk())->assertExists($posterPath);
    expect(Storage::disk(Directory::getAssetDisk())->get($posterPath))
        ->toBe('jpeg-thumbnail')
        ->and($asset->refresh()->meta_data['thumbnail_path'])->toBe($posterPath)
        ->and(array_filter($job->temporaryPaths, 'file_exists'))->toBe([]);
});

it('reads a local video directly without creating a full source copy', function () {
    $disk = Directory::getAssetDisk();
    $asset = Asset::factory()->create([
        'file_name' => 'direct.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/direct.mp4',
    ]);
    putAssetContent($asset, 'original-video');
    $job = new class($asset->id) extends GenerateVideoThumbnail
    {
        public ?string $inputPath = null;

        public bool $inputWasSymlink = false;

        public ?string $inputLinkTarget = null;

        protected function runFfmpeg(array $command): array
        {
            $this->inputPath = $command[array_search('-i', $command, true) + 1];
            // Captured now: the staged symlink is released once handle() returns.
            $this->inputWasSymlink = is_link($this->inputPath);
            $this->inputLinkTarget = $this->inputWasSymlink ? readlink($this->inputPath) : null;
            file_put_contents($command[array_key_last($command)], 'jpeg-thumbnail');

            return [true, ''];
        }
    };
    $job->handle();

    // The source is the asset's current blob (ADR 0009), staged as a symlink: never the Library
    // path, never a byte copy.
    $object = app(ContentStore::class)->current($asset);
    expect($job->inputWasSymlink)->toBeTrue()
        ->and($job->inputLinkTarget)->toBe(Storage::disk($disk)->path($object->path))
        ->and(file_exists($job->inputPath))->toBeFalse();
    expect(Storage::disk($disk)->get($object->path))->toBe('original-video');
    Storage::disk($disk)->assertExists(DerivativeImages::poster($object->sha256));
});

it('keeps meta_data another job wrote while the thumbnail was generating', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'race.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/race.mp4',
        'meta_data' => ['Title' => 'Race'],
    ]);
    putAssetContent($asset, 'video-source');

    $job = new class($asset->id) extends GenerateVideoThumbnail
    {
        protected function runFfmpeg(array $command): array
        {
            // GenerateVideoPreviewProxy is dispatched alongside this job and
            // records probe facts after handle() has already loaded the asset.
            $concurrent = Asset::find($this->assetId);
            $concurrent->meta_data = array_merge($concurrent->meta_data, [
                'ImageWidth'       => 1920,
                'ImageHeight'      => 1080,
                'DimensionsSource' => 'ffprobe',
            ]);
            $concurrent->saveQuietly();

            file_put_contents($command[array_key_last($command)], 'jpeg-thumbnail');

            return [true, ''];
        }
    };

    $job->handle();

    $sha = hash('sha256', 'video-source');
    expect($asset->refresh()->meta_data)->toMatchArray([
        'Title'            => 'Race',
        'ImageWidth'       => 1920,
        'ImageHeight'      => 1080,
        'DimensionsSource' => 'ffprobe',
        'thumbnail_path'   => DerivativeImages::poster($sha),
    ]);
});

it('skips the thumbnail metadata write when the asset is deleted during generation', function () {
    Log::spy();
    $asset = Asset::factory()->create([
        'file_name' => 'gone.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/gone.mp4',
    ]);
    putAssetContent($asset, 'video-source');

    $job = new class($asset->id) extends GenerateVideoThumbnail
    {
        protected function runFfmpeg(array $command): array
        {
            Asset::query()->whereKey($this->assetId)->forceDelete();
            file_put_contents($command[array_key_last($command)], 'jpeg-thumbnail');

            return [true, ''];
        }
    };

    $job->handle();

    expect(Asset::find($asset->id))->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

it('cleans temporary files when ffmpeg fails both attempts', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);
    putAssetContent($asset, 'video-source');

    $job = new class($asset->id) extends GenerateVideoThumbnail
    {
        public array $temporaryPaths = [];

        protected function temporaryFile(string $prefix, string $extension): string
        {
            $path = parent::temporaryFile($prefix, $extension);
            $this->temporaryPaths[] = $path;

            return $path;
        }

        protected function runFfmpeg(array $command): array
        {
            return [false, 'fixture ffmpeg failure'];
        }
    };

    $job->handle();

    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::poster(hash('sha256', 'video-source')));
    expect($asset->refresh()->meta_data)->not->toHaveKey('thumbnail_path')
        ->and(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe('video-source')
        ->and($job->temporaryPaths)->toHaveCount(1)
        ->and(array_filter($job->temporaryPaths, 'file_exists'))->toBe([]);
});

it('hands an authorized file to the web server with X-Accel-Redirect when a prefix is configured', function () {
    config(['mothership.media_accel_prefix' => '/__dam_media/']);

    $directory = Directory::factory()->create();
    $path = 'assets/Allowed/Drone Footage/clip one.mp4';
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(['path' => $path, 'file_name' => 'clip one.mp4']), $directory);
    putAssetContent($asset, 'video-bytes');
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $response = $this->get(route('admin.dam.file.fetch', ['path' => $path]));

    $response->assertOk();

    // nginx serves the asset's blob; the Library path is only the name the request used (ADR 0009).
    expect($response->headers->get('X-Accel-Redirect'))
        ->toBe('/__dam_media/'.app(ContentStore::class)->current($asset)->path)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="clip one.mp4"')
        ->and($response->streamedContent ?? $response->getContent())->toBe('');
});

it('streams the file itself when no accel prefix is configured', function () {
    config(['mothership.media_accel_prefix' => null]);

    $directory = Directory::factory()->create();
    $path = 'assets/Allowed/plain.mp4';
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(['path' => $path]), $directory);
    putAssetContent($asset, 'video-bytes');
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.view']);

    $response = $this->get(route('admin.dam.file.fetch', ['path' => $path]));

    $response->assertOk();

    expect($response->headers->get('X-Accel-Redirect'))->toBeNull()
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('refuses an inaccessible file before any accel handoff', function () {
    config(['mothership.media_accel_prefix' => '/__dam_media/']);

    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $path = 'assets/Denied/secret.mp4';
    $asset = attachPreviewAssetToDirectory(Asset::factory()->create(['path' => $path]), $deniedDirectory);
    putAssetContent($asset, 'video-bytes');
    actAsPlaybackRestrictedAdmin($allowedDirectory, ['dam.asset.view']);

    $response = $this->get(route('admin.dam.file.fetch', ['path' => $path]));

    $response->assertNotFound();

    expect($response->headers->get('X-Accel-Redirect'))->toBeNull();
});

it('renders local 360 workflow controls without a CDN dependency', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'flight.osv',
        'file_type' => 'document',
        'mime_type' => 'application/octet-stream',
        'extension' => 'osv',
        'path'      => 'assets/Root/flight.osv',
    ]);

    $html = view('dam::asset.preview-modal', [
        'asset'              => $asset,
        'directoryAncestors' => collect(),
    ])->render();

    expect($html)
        ->toContain('data-dam-panorama-status')
        ->toContain('Build 360 preview')
        ->toContain('schedulePanoramaPoll')
        ->toContain('window.loadDamThree')
        ->not->toContain('cdnjs.cloudflare.com');
});

it('exposes the panorama workflow state, urls and browser playability gate to the component', function () {
    config(['mothership.panorama_processing_enabled' => true]);

    $asset = Asset::factory()->create([
        'file_name' => 'flight.osv',
        'file_type' => 'document',
        'mime_type' => 'application/octet-stream',
        'extension' => 'osv',
        'path'      => 'assets/Root/flight.osv',
    ]);

    $html = view('dam::asset.preview-modal', [
        'asset'              => $asset,
        'directoryAncestors' => collect(),
    ])->render();

    // The payload is embedded with Blade's @json, which escapes slashes, so
    // the URLs are asserted in the form they actually reach the browser in.
    $embedded = fn (string $url): string => trim(json_encode($url), '"');

    expect($html)
        ->toContain('"can_edit":true')
        ->toContain('"processing_enabled":true')
        ->toContain($embedded(route('admin.dam.assets.panorama.state', $asset->id)))
        ->toContain($embedded(route('admin.dam.assets.panorama.cancel', $asset->id)))
        ->toContain('panoramaVerifyDirectPlayback')
        ->toContain('canPlayType')
        ->toContain('browser_rejected')
        ->toContain('video/quicktime')
        ->toContain('panoOnVideoError')
        ->toContain('panoramaSourceReachable')
        ->toContain('MEDIA_ERR_SRC_NOT_SUPPORTED')
        ->toContain("redirect: 'manual'")
        ->toContain('opaqueredirect')
        ->toContain('X-Requested-With')
        ->toContain('data-dam-panorama-retry-playback')
        ->toContain('data-dam-panorama-status="card"')
        ->toContain('Rebuild 360 preview')
        ->toContain('Open 360 preview')
        ->toContain('Queued')
        ->toContain('Retry');
});

it('requests the reused panorama image with anonymous CORS before its src, so a cross-origin preview can become a WebGL texture', function () {
    $template = file_get_contents(base_path('packages/Mothership/DAM/src/Resources/views/asset/preview-modal/pano360/viewer.blade.php'));

    expect($template)->toMatch('/ref="panoImg"[\s\S]*?crossorigin="anonymous"[\s\S]*?:src="previewData\.playbackUrl"/');
});
