<?php

/*
 * Slice 2 acceptance seed (ADR 0009): the Library path is a logical name. With the file at an asset's
 * Library path deleted, every serving route still answers from the asset's current blob.
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null]);
});

function readSeamPng(): string
{
    $image = imagecreatetruecolor(640, 320);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150));
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

/** An uploaded asset (bytes ingested as its original) whose Library-path file has since gone. */
function assetWithoutLibraryFile(array $attributes, string $bytes): Asset
{
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($attributes['path'], $bytes);
    $asset = Asset::factory()->create($attributes);
    $asset->directories()->attach(Directory::whereNull('parent_id')->firstOrFail()->id);
    $disk->delete($asset->path);

    expect(app(ContentStore::class)->holdsBlob(app(ContentStore::class)->current($asset)))->toBeTrue();

    return $asset;
}

/** The bytes a local response carries (a BinaryFileResponse or an in-memory body). */
function readSeamBody($response): string
{
    $base = $response->baseResponse ?? $response;

    return method_exists($base, 'getFile') ? file_get_contents($base->getFile()->getPathname()) : (string) $base->getContent();
}

it('serves an image\'s original, thumbnail and preview with its Library-path file deleted', function () {
    $bytes = readSeamPng();
    $asset = assetWithoutLibraryFile([
        'file_name' => 'harbor.png', 'file_type' => 'image', 'mime_type' => 'image/png',
        'extension' => 'png', 'path' => 'assets/Root/Harbor/harbor.png',
    ], $bytes);
    $sha = hash('sha256', $bytes);

    $fetch = $this->get(route('admin.dam.file.fetch', ['path' => $asset->path]))->assertOk();
    expect(readSeamBody($fetch))->toBe($bytes)
        ->and($fetch->headers->get('Content-Type'))->toBe('image/png')
        ->and($fetch->headers->get('Content-Disposition'))->toBe('inline; filename=harbor.png');

    $thumbnail = $this->get(route('admin.dam.file.thumbnail', ['path' => $asset->path]), ['Accept' => 'image/*'])->assertOk();
    expect($thumbnail->headers->get('Content-Type'))->toBe('image/webp');
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::thumbnail($sha, 'png'));

    $this->get(route('admin.dam.file.preview', ['path' => $asset->path, 'size' => 300]))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::preview($sha, 300, 'png'));

    $source = $asset->playbackSource();
    expect($source['kind'])->toBe('image');
    expect(readSeamBody($this->get($source['url'])->assertOk()))->toBe($bytes);
});

it('plays a video original and its attachment with the Library-path file deleted', function () {
    $asset = assetWithoutLibraryFile([
        'file_name' => 'clip.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4',
        'extension' => 'mp4', 'path' => 'assets/Root/clip.mp4',
    ], 'mp4-original-bytes');
    $caption = attachPreviewFile($asset, 'assets/Root/clip.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'English');

    $playback = $asset->playbackFile();
    expect($playback['path'])->toBe(app(ContentStore::class)->current($asset)->path)
        ->and($playback['extension'])->toBe('mp4')
        ->and($playback['kind'])->toBe('video');

    $this->get(route('admin.dam.file.preview', ['path' => $asset->path]))
        ->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $vtt = $this->get(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $caption->id]))->assertOk();
    expect(readSeamBody($vtt))->toBe('preview-fixture');
});

it('answers 404 when the current blob itself is gone, recorded as unavailable content', function () {
    $asset = assetWithoutLibraryFile([
        'file_name' => 'lost.pdf', 'file_type' => 'document', 'mime_type' => 'application/pdf',
        'extension' => 'pdf', 'path' => 'assets/Root/lost.pdf',
    ], '%PDF-1.4 lost');
    $object = app(ContentStore::class)->current($asset);
    Storage::disk($object->disk)->delete($object->path);

    $this->getJson(route('admin.dam.file.fetch', ['path' => $asset->path]))
        ->assertNotFound()
        ->assertJson(['message' => trans('dam::app.admin.dam.file.not-found')]);
});

/*
 * A missing or truncated original is never a silent placeholder (review finding #4). Every route writes
 * the content_unavailable record first; a preview then answers 404, while a grid thumbnail (admin or
 * share) keeps its type placeholder so a card still renders.
 */
it('records a missing or truncated original before answering a preview or thumbnail', function (string $route, int $status, string $damage) {
    DB::table('dam_shares')->delete();
    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/image.svg', '<svg id="grid-placeholder"/>');
    $asset = assetWithoutLibraryFile([
        'file_name' => 'damaged.png', 'file_type' => 'image', 'mime_type' => 'image/png',
        'extension' => 'png', 'path' => 'assets/Root/damaged.png',
    ], readSeamPng());
    $object = app(ContentStore::class)->current($asset);
    $damage === 'deleted'
        ? Storage::disk($object->disk)->delete($object->path)
        : Storage::disk($object->disk)->put($object->path, substr(readSeamPng(), 0, 64));
    $log = storage_path(ContentStore::LOG);
    $before = is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;

    $response = match ($route) {
        'admin preview'   => $this->getJson(route('admin.dam.file.preview', ['path' => $asset->path, 'size' => 300])),
        'admin thumbnail' => $this->get(route('admin.dam.file.thumbnail', ['path' => $asset->path]), ['Accept' => 'image/*']),
        'share thumbnail' => $this->get(route('dam.share.thumbnail', [
            'token'   => Share::factory()->forAsset($asset->id)->create()->urlToken(),
            'assetId' => $asset->id,
        ])),
    };

    $response->assertStatus($status);
    if ($status === 200) {
        expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
    }

    $records = array_map(fn (string $line) => json_decode($line, true), array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $before));
    expect(collect($records)->where('event', 'content_unavailable')->pluck('ctx')->map(fn (array $ctx) => Arr::except($ctx, 'request_id'))->all())
        ->toContain(['reason' => 'blob_missing', 'file_object_id' => $object->id, 'disk' => $object->disk]);
    // Nothing was derived from the damaged bytes.
    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::thumbnail($object->sha256, 'png'));
    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::preview($object->sha256, 300, 'png'));
})->with([
    'admin preview'   => ['admin preview', 404],
    'admin thumbnail' => ['admin thumbnail', 200],
    'share thumbnail' => ['share thumbnail', 200],
])->with(['deleted', 'truncated']);

/**
 * An asset of `$kind` whose specialized thumbnail/preview source is already cached (a warm DNG preview,
 * a custom thumbnail, a captured model view, cover art, a job poster) or not (a cold DNG), then its
 * original damaged. Returns the asset, its original and the cached derivative's bytes.
 *
 * @return array{0: Asset, 1: FileObject, 2: ?string}
 */
function specializedDamagedAsset(string $kind, string $damage): array
{
    $rows = [
        'dng warm'     => ['raw.dng', 'image', 'image/x-adobe-dng', 'dng'],
        'dng cold'     => ['raw.dng', 'image', 'image/x-adobe-dng', 'dng'],
        'custom'       => ['cover.png', 'image', 'image/png', 'png'],
        'model'        => ['bracket.glb', 'document', 'model/gltf-binary', 'glb'],
        'audio cover'  => ['song.mp3', 'audio', 'audio/mpeg', 'mp3'],
        'video poster' => ['clip.mp4', 'video', 'video/mp4', 'mp4'],
        'pdf poster'   => ['brief.pdf', 'document', 'application/pdf', 'pdf'],
    ];
    [$name, $type, $mime, $extension] = $rows[$kind];
    $bytes = $kind === 'custom' ? readSeamPng() : $kind.'-original-bytes';
    $asset = assetWithoutLibraryFile([
        'file_name' => $name, 'file_type' => $type, 'mime_type' => $mime,
        'extension' => $extension, 'path' => 'assets/Root/'.$name,
    ], $bytes);
    $object = app(ContentStore::class)->current($asset);
    $storage = Storage::disk(Directory::getAssetDisk());
    $derivative = 'cached-'.str_replace(' ', '-', $kind);
    $meta = [];

    match ($kind) {
        'dng warm' => [
            $storage->put(DerivativeImages::dngThumbnail($object->sha256), $derivative),
            $storage->put(DerivativeImages::dngPreview($object->sha256), $derivative),
        ],
        'dng cold' => $derivative = null,
        'custom'   => [
            $meta['custom_thumbnail'] = ['path' => 'custom-thumbnails/'.$asset->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => 1],
            $storage->put($meta['custom_thumbnail']['path'], $derivative),
        ],
        'model'       => [$meta['thumbnail_path'] = 'model-thumbnails/'.$asset->id.'.jpg', $storage->put($meta['thumbnail_path'], $derivative)],
        'audio cover' => [$meta['cover_art_path'] = 'covers/'.$asset->id.'.jpg', $storage->put($meta['cover_art_path'], $derivative)],
        default       => [$meta['thumbnail_path'] = DerivativeImages::poster($object->sha256), $storage->put($meta['thumbnail_path'], $derivative)],
    };

    if ($meta !== []) {
        $asset->forceFill(['meta_data' => $meta])->saveQuietly();
    }

    $damage === 'deleted'
        ? $storage->delete($object->path)
        : $storage->put($object->path, 'short');

    return [$asset->fresh(), $object, $derivative];
}

/*
 * Round-2 review #1: the specialized branches (DNG, custom and model thumbnails, cover art, posters) check
 * the original before anything else. Every one writes the content_unavailable record; previews and the
 * share's inline view answer 404; thumbnails answer the card's type placeholder, never a cached derivative
 * of the damaged original. The one exception is a custom thumbnail on the admin route: it is art a user
 * uploaded independently of the original, so it is still shown (after the record is written).
 */
it('checks the original before every specialized preview and thumbnail branch', function (string $kind, string $route, string $expect, string $damage) {
    DB::table('dam_shares')->delete();
    Storage::fake('public');
    foreach (['image', 'video', 'audio', 'document'] as $type) {
        Storage::disk('public')->put('dam/grid/'.$type.'.svg', '<svg id="grid-'.$type.'"/>');
    }
    [$asset, $object, $derivative] = specializedDamagedAsset($kind, $damage);
    $log = storage_path(ContentStore::LOG);
    $before = is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    $share = fn () => Share::factory()->forAsset($asset->id)->create()->urlToken();

    $response = match ($route) {
        'admin thumbnail' => $this->get(route('admin.dam.file.thumbnail', ['path' => $asset->path]), ['Accept' => 'image/*']),
        'admin preview'   => $this->getJson(route('admin.dam.file.preview', ['path' => $asset->path])),
        'admin cover'     => $this->getJson(route('admin.dam.file.cover-art', $asset->id)),
        'share thumbnail' => $this->get(route('dam.share.thumbnail', ['token' => $share(), 'assetId' => $asset->id])),
        'share inline'    => $this->get(route('dam.share.download', ['token' => $share(), 'disposition' => 'inline'])),
    };

    match ($expect) {
        '404'         => $response->assertNotFound(),
        'placeholder' => $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml'),
        'custom'      => $response->assertOk()->assertHeader('Content-Type', 'image/png'),
    };
    $body = readSeamBody($response);
    if ($expect === 'custom') {
        expect($body)->toBe($derivative);
    } elseif ($derivative !== null) {
        expect($body)->not->toBe($derivative);
    }

    $records = array_map(fn (string $line) => json_decode($line, true), array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $before));
    expect(collect($records)->where('event', 'content_unavailable')->pluck('ctx')->map(fn (array $ctx) => Arr::except($ctx, 'request_id'))->all())
        ->toContain(['reason' => 'blob_missing', 'file_object_id' => $object->id, 'disk' => $object->disk]);
})->with([
    'dng warm, admin thumbnail'     => ['dng warm', 'admin thumbnail', 'placeholder'],
    'dng warm, admin preview'       => ['dng warm', 'admin preview', '404'],
    'dng warm, share thumbnail'     => ['dng warm', 'share thumbnail', 'placeholder'],
    'dng warm, share inline'        => ['dng warm', 'share inline', '404'],
    'dng cold, admin thumbnail'     => ['dng cold', 'admin thumbnail', 'placeholder'],
    'dng cold, admin preview'       => ['dng cold', 'admin preview', '404'],
    'dng cold, share thumbnail'     => ['dng cold', 'share thumbnail', 'placeholder'],
    'dng cold, share inline'        => ['dng cold', 'share inline', '404'],
    'custom, admin thumbnail'       => ['custom', 'admin thumbnail', 'custom'],
    'custom, share thumbnail'       => ['custom', 'share thumbnail', 'custom'],
    'model, admin thumbnail'        => ['model', 'admin thumbnail', 'placeholder'],
    'model, share thumbnail'        => ['model', 'share thumbnail', 'placeholder'],
    'audio cover, admin thumbnail'  => ['audio cover', 'admin thumbnail', 'placeholder'],
    'audio cover, admin cover art'  => ['audio cover', 'admin cover', '404'],
    'audio cover, share thumbnail'  => ['audio cover', 'share thumbnail', 'placeholder'],
    'video poster, admin thumbnail' => ['video poster', 'admin thumbnail', 'placeholder'],
    'video poster, share thumbnail' => ['video poster', 'share thumbnail', 'placeholder'],
    'pdf poster, admin thumbnail'   => ['pdf poster', 'admin thumbnail', 'placeholder'],
    'pdf poster, share thumbnail'   => ['pdf poster', 'share thumbnail', 'placeholder'],
])->with(['deleted', 'truncated']);
