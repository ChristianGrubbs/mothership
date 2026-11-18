<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\VideoProxyPublisher;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function makePreviewRendition(): Rendition
{
    $asset = Asset::factory()->create([
        'file_name' => 'clip.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/clip.mp4',
    ]);

    return Rendition::factory()->create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
    ]);
}

function writeLocalProxy(?string $bytes = null): string
{
    // R18: Minimal ftyp-headed byte string for valid MP4 container
    if ($bytes === null) {
        $bytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100);
    }

    $path = tempnam(sys_get_temp_dir(), 'proxytest_').'.mp4';
    file_put_contents($path, $bytes);

    return $path;
}

it('publishes a valid 1080p proxy as a content-addressed proxy file', function () {
    $rendition = makePreviewRendition();
    // R18: Use ftyp-headed bytes (default for writeLocalProxy now)
    $proxyBytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100);
    $local = writeLocalProxy($proxyBytes);

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
            'audio'      => 'aac',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();

    expect($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->progress)->toBe(100)
        ->and($rendition->output_file_object_id)->not->toBeNull();

    $file = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('file_object_id', $rendition->output_file_object_id)
        ->first();

    expect($file)->not->toBeNull()
        ->and($file->role)->toBe(AssetFile::ROLE_PROXY)
        ->and($file->label)->toBe('Preview (1080p)')
        ->and($file->sort)->toBe(900)
        ->and($rendition->output_asset_file_id)->toBe($file->id);

    $sha = hash('sha256', $proxyBytes);

    Storage::disk(Directory::getAssetDisk())
        ->assertExists('assets/.objects/'.substr($sha, 0, 2).'/'.$sha.'.mp4');
});

it('refuses a proxy wider than 1920 and leaves the rendition unpublished', function () {
    $rendition = makePreviewRendition();
    $local = writeLocalProxy();

    try {
        expect(fn () => app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 3840,
            'height'     => 2160,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
        ]))->toThrow(RuntimeException::class, 'proxy_too_large');
    } finally {
        @unlink($local);
    }

    expect($rendition->refresh()->output_file_object_id)->toBeNull();
});

it('refuses a non-h264 proxy', function () {
    $rendition = makePreviewRendition();
    $local = writeLocalProxy();

    try {
        expect(fn () => app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'vp9',
            'pix_fmt'    => 'yuv420p',
        ]))->toThrow(RuntimeException::class, 'codec_rejected');
    } finally {
        @unlink($local);
    }

    expect($rendition->refresh()->output_file_object_id)->toBeNull();
});

// R19: Terminal no-op tests — various terminal statuses with invalid metadata and non-MP4 bytes
it('leaves a terminal rendition unpublished with STATUS_DONE regardless of invalid metadata', function () {
    $rendition = makePreviewRendition();
    $rendition->update(['status' => Rendition::STATUS_DONE, 'finished_at' => now(), 'error' => 'previous_error']);

    // Snapshot complete rendition row before publishing
    $before = $rendition->fresh()->getAttributes();

    // R19: Non-MP4 bytes + invalid dimensions — should NOT throw, just no-op
    $local = writeLocalProxy('not-an-mp4-file');

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 3840,  // Too large!
            'height'     => 2160,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();
    $after = $rendition->getAttributes();

    // Assert rendition row is wholly unchanged
    expect($after)->toBe($before)
        ->and($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->output_file_object_id)->toBeNull();

    $file = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('role', AssetFile::ROLE_PROXY)
        ->first();

    expect($file)->toBeNull();

    // Verify no proxy object was written
    $allObjects = Storage::disk(Directory::getAssetDisk())->allFiles('assets/.objects');
    expect($allObjects)->toBe([]);
});

it('leaves a terminal rendition unpublished with STATUS_FAILED regardless of invalid metadata', function () {
    $rendition = makePreviewRendition();
    $rendition->update(['status' => Rendition::STATUS_FAILED, 'finished_at' => now(), 'error' => 'previous_error']);

    // Snapshot complete rendition row before publishing
    $before = $rendition->fresh()->getAttributes();

    $local = writeLocalProxy('not-an-mp4-file');

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 3840,
            'height'     => 2160,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();
    $after = $rendition->getAttributes();

    // Assert rendition row is wholly unchanged
    expect($after)->toBe($before)
        ->and($rendition->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->output_file_object_id)->toBeNull();

    $file = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('role', AssetFile::ROLE_PROXY)
        ->first();

    expect($file)->toBeNull();

    $allObjects = Storage::disk(Directory::getAssetDisk())->allFiles('assets/.objects');
    expect($allObjects)->toBe([]);
});

it('leaves a terminal rendition unpublished with STATUS_CANCELLED regardless of invalid metadata', function () {
    $rendition = makePreviewRendition();
    $rendition->update(['status' => Rendition::STATUS_CANCELLED, 'finished_at' => now(), 'error' => 'previous_error']);

    // Snapshot complete rendition row before publishing
    $before = $rendition->fresh()->getAttributes();

    $local = writeLocalProxy('not-an-mp4-file');

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 3840,
            'height'     => 2160,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();
    $after = $rendition->getAttributes();

    // Assert rendition row is wholly unchanged
    expect($after)->toBe($before)
        ->and($rendition->status)->toBe(Rendition::STATUS_CANCELLED)
        ->and($rendition->output_file_object_id)->toBeNull();

    $file = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('role', AssetFile::ROLE_PROXY)
        ->first();

    expect($file)->toBeNull();

    $allObjects = Storage::disk(Directory::getAssetDisk())->allFiles('assets/.objects');
    expect($allObjects)->toBe([]);
});

it('refuses audio that is not aac or none', function () {
    $rendition = makePreviewRendition();
    $local = writeLocalProxy();

    try {
        expect(fn () => app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
            'audio'      => 'opus',
        ]))->toThrow(RuntimeException::class, 'audio_rejected');
    } finally {
        @unlink($local);
    }

    expect($rendition->refresh()->output_file_object_id)->toBeNull();
});

it('rejects non-MP4 container bytes even with valid technical facts', function () {
    $rendition = makePreviewRendition();
    $local = writeLocalProxy('not-an-mp4-file');

    try {
        expect(fn () => app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
            'audio'      => 'aac',
        ]))->toThrow(RuntimeException::class, 'container_rejected');
    } finally {
        @unlink($local);
    }

    expect($rendition->refresh()->output_file_object_id)->toBeNull();
});

it('publishes successfully with audio none', function () {
    $rendition = makePreviewRendition();
    $proxyBytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100);
    $local = writeLocalProxy($proxyBytes);

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
            'audio'      => 'none',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();

    expect($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->output_file_object_id)->not->toBeNull();
});

it('publishes successfully with no audio key', function () {
    $rendition = makePreviewRendition();
    $proxyBytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100);
    $local = writeLocalProxy($proxyBytes);

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();

    expect($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->output_file_object_id)->not->toBeNull();
});

it('updates label and sort when the same content-addressed object already has a proxy AssetFile', function () {
    // R21: Pre-create the FileObject and AssetFile with different label/sort
    $rendition = makePreviewRendition();
    $proxyBytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100);
    $sha256 = hash('sha256', $proxyBytes);
    $objectPath = 'assets/.objects/'.substr($sha256, 0, 2).'/'.$sha256.'.mp4';

    $preexistingObject = FileObject::factory()->create([
        'sha256'       => $sha256,
        'path'         => $objectPath,
        'disk'         => Directory::getAssetDisk(),
        'mime_type'    => 'video/mp4',
        'sniffed_type' => 'video/mp4',
        'size'         => strlen($proxyBytes),
    ]);

    $preexistingAssetFile = AssetFile::factory()->create([
        'dam_asset_id'   => $rendition->dam_asset_id,
        'file_object_id' => $preexistingObject->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => 'Something else',
        'sort'           => 5,
    ]);

    $local = writeLocalProxy($proxyBytes);

    try {
        app(VideoProxyPublisher::class)->publish($rendition, $local, [
            'width'      => 1920,
            'height'     => 1080,
            'codec_name' => 'h264',
            'pix_fmt'    => 'yuv420p',
            'audio'      => 'aac',
        ]);
    } finally {
        @unlink($local);
    }

    $rendition->refresh();

    expect($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->output_file_object_id)->toBe($preexistingObject->id);

    $updatedAssetFile = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('role', AssetFile::ROLE_PROXY)
        ->first();

    expect($updatedAssetFile)->not->toBeNull()
        ->and($updatedAssetFile->id)->toBe($preexistingAssetFile->id)
        ->and($updatedAssetFile->label)->toBe('Preview (1080p)')
        ->and($updatedAssetFile->sort)->toBe(900);

    // Verify exactly one proxy AssetFile
    $allProxies = AssetFile::where('dam_asset_id', $rendition->dam_asset_id)
        ->where('role', AssetFile::ROLE_PROXY)
        ->get();

    expect($allProxies)->toHaveCount(1);
});
