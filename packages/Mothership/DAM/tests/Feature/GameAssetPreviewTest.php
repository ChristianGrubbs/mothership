<?php

/*
 * Blender and Unity previews without either app (ADR 0006): GenerateGameAssetThumbnail writes the embedded
 * thumbnail as a sha-keyed poster and records what it read, the thumbnail route serves it (running the job on
 * first request), and a Unity text asset's preview is its first bytes as JSON. Fixtures: GameAssetFixtures.
 */

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\GenerateGameAssetThumbnail;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PreviewKinds;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->disk = Storage::disk(Directory::getAssetDisk());
    $this->scratch = sys_get_temp_dir().'/game-asset-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scratch);
});

afterEach(fn () => File::deleteDirectory($this->scratch));

/** An asset whose current original is `$bytes`, stored as a document like every Blender and Unity file. */
function gameAssetFixture(string $name, string $bytes): Asset
{
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put('assets/'.$name, $bytes);

    return Asset::factory()->create([
        'file_name' => $name,
        'file_type' => 'document',
        'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        'mime_type' => 'application/octet-stream',
        'path'      => 'assets/'.$name,
    ]);
}

it('queues the game-asset job when a Blender file or Unity package is processed', function (string $name, bool $queued) {
    $asset = gameAssetFixture($name, 'bytes');
    Bus::fake([GenerateGameAssetThumbnail::class]);

    (new ProcessAssetUpload($asset->id))->handle(app(MetadataExtractionService::class));

    $queued
        ? Bus::assertDispatched(GenerateGameAssetThumbnail::class)
        : Bus::assertNotDispatched(GenerateGameAssetThumbnail::class);
})->with([
    'blend'        => ['scene.blend', true],
    'unitypackage' => ['kit.unitypackage', true],
    'prefab'       => ['Robot.prefab', false],
]);

it('writes a Blender file\'s embedded thumbnail as its poster and records what it read', function () {
    $bytes = gzencode(blendFixture());
    $asset = gameAssetFixture('scene.blend', $bytes);

    (new GenerateGameAssetThumbnail($asset->id))->handle();

    $poster = DerivativeImages::poster(hash('sha256', $bytes));
    $meta = $asset->fresh()->meta_data;
    expect($this->disk->exists($poster))->toBeTrue()
        ->and(getimagesizefromstring($this->disk->get($poster))['mime'])->toBe('image/jpeg')
        ->and($meta['thumbnail_path'])->toBe($poster)
        ->and($meta['game_asset'])->toMatchArray([
            'kind'            => 'blender',
            'sha256'          => hash('sha256', $bytes),
            'has_thumbnail'   => true,
            'blender_version' => '4.2',
            'compression'     => 'gzip',
        ])
        ->and($meta['game_asset']['counts'])->toMatchArray(['scenes' => 1, 'objects' => 2]);

    expect(AssetHelper::gameAssetPreview($asset->fresh()))->toMatchArray([
        'kind'           => 'blender',
        'read'           => true,
        'blenderVersion' => '4.2',
        'counts'         => ['scenes' => 1, 'objects' => 2],
        'textUrl'        => null,
    ]);
});

it('serves a Blender thumbnail on first request, generating it then', function () {
    $asset = gameAssetFixture('lazy.blend', blendFixture(format: 'large'));
    $this->loginAsAdmin();

    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    expect($asset->fresh()->meta_data['game_asset']['blender_version'])->toBe('5.0');
});

it('carries the facts on the info endpoint once the first thumbnail request has read the file', function () {
    $asset = gameAssetFixture('fresh.blend', blendFixture());
    $this->loginAsAdmin();

    // What the modal starts from: the payload of an unread file.
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('game_asset.kind', 'blender')->assertJsonPath('game_asset.read', false);

    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])->assertOk();

    $info = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('game_asset.read', true)
        ->assertJsonPath('game_asset.blenderVersion', '4.2')
        ->assertJsonPath('game_asset.counts.objects', 2);
    // The info endpoint is a metadata route: no content URL rides along.
    expect($info->json('game_asset'))->not->toHaveKeys(['thumbnailUrl', 'textUrl']);
});

it('records facts for an asset whose shared-bytes poster another asset already wrote', function () {
    $bytes = blendFixture();
    $first = gameAssetFixture('first.blend', $bytes);
    $second = gameAssetFixture('second.blend', $bytes);
    $this->loginAsAdmin();

    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($first->path)]), ['Accept' => 'image/*'])->assertOk();
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($second->path)]), ['Accept' => 'image/*'])->assertOk();

    $this->getJson(route('admin.dam.assets.info', $second->id))->assertOk()
        ->assertJsonPath('game_asset.read', true)
        ->assertJsonPath('game_asset.blenderVersion', '4.2');

    // Once its facts name these bytes, a card render serves the poster without reading the file again.
    Bus::fake([GenerateGameAssetThumbnail::class]);
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($second->path)]), ['Accept' => 'image/*'])
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    Bus::assertNotDispatched(GenerateGameAssetThumbnail::class);
});

it('re-reads a file whose recorded facts name other bytes, even when the current bytes already have a poster', function () {
    // The original was replaced: the facts still name the old bytes, and a poster for the new bytes exists
    // (written for another asset with the same bytes), so only the sha comparison can trigger the job.
    $bytes = blendFixture();
    $sha = hash('sha256', $bytes);
    $asset = gameAssetFixture('replaced.blend', $bytes);
    $asset->meta_data = ['game_asset' => ['kind' => 'blender', 'sha256' => str_repeat('0', 64), 'has_thumbnail' => false]];
    $asset->saveQuietly();
    $this->disk->put(DerivativeImages::poster($sha), 'stale poster bytes');
    $this->loginAsAdmin();

    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])->assertOk();

    expect($asset->fresh()->meta_data['game_asset'])->toMatchArray([
        'sha256'          => $sha,
        'has_thumbnail'   => true,
        'blender_version' => '4.2',
    ]);
});

it('makes the poster again when its file has been deleted although the facts say the file has a thumbnail', function () {
    $asset = gameAssetFixture('gone.blend', blendFixture(format: 'large'));
    $sha = hash('sha256', blendFixture(format: 'large'));
    $this->loginAsAdmin();
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])->assertOk();
    expect($this->disk->exists(DerivativeImages::poster($sha)))->toBeTrue()
        ->and($asset->fresh()->meta_data['game_asset']['has_thumbnail'])->toBeTrue();

    // The poster is lost (storage cleanup, a restore): the recorded sha still matches, and it is made again.
    $this->disk->delete(DerivativeImages::poster($sha));
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    expect($this->disk->exists(DerivativeImages::poster($sha)))->toBeTrue();
});

it('gives a Unity package whose preview names a huge picture no poster, without decoding it', function () {
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $bomb = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress('')).$chunk('IEND', '');
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Bomb.prefab', 'preview' => $bomb],
    ]);
    $asset = gameAssetFixture('bomb.unitypackage', file_get_contents($package));

    $before = memory_get_peak_usage();
    (new GenerateGameAssetThumbnail($asset->id))->handle();

    $meta = $asset->fresh()->meta_data;
    expect($meta['game_asset'])->toMatchArray(['has_thumbnail' => false, 'asset_count' => 1])
        ->and($meta)->not->toHaveKey('thumbnail_path')
        // 20000 x 20000 RGBA would be 1.6 GB in GD.
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(64 * 1048576);
});

it('serves a generated game-asset poster on a share link', function () {
    $asset = gameAssetFixture('shared.blend', blendFixture());
    (new GenerateGameAssetThumbnail($asset->id))->handle();
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('serves the Blender placeholder for a file without a thumbnail and reads it only once', function () {
    $asset = gameAssetFixture('bare.blend', blendFixture(thumbnail: null));
    $this->loginAsAdmin();

    // An <img> request: a browser navigation (Accept: text/html) is sent to the original instead.
    $first = $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])->assertOk();
    expect($first->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($first->getContent())->toContain('BLEND')
        ->and($asset->fresh()->meta_data['game_asset']['has_thumbnail'])->toBeFalse();

    Bus::fake([GenerateGameAssetThumbnail::class]);
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]), ['Accept' => 'image/*'])->assertOk();
    Bus::assertNotDispatched(GenerateGameAssetThumbnail::class);
});

it('writes a Unity package\'s prefab preview as its poster and records the contents', function () {
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Textures/Rust.png', 'preview' => gameAssetPng(255, 0, 0)],
        'aa000000000000000000000000000002' => ['pathname' => 'Assets/Prefabs/Crate.prefab', 'preview' => gameAssetPng(0, 0, 255)],
    ]);
    $bytes = file_get_contents($package);
    $asset = gameAssetFixture('kit.unitypackage', $bytes);

    (new GenerateGameAssetThumbnail($asset->id))->handle();

    $meta = $asset->fresh()->meta_data;
    $poster = DerivativeImages::poster(hash('sha256', $bytes));
    $image = imagecreatefromstring($this->disk->get($poster));
    $rgb = imagecolorat($image, 2, 2);
    expect($meta['thumbnail_path'])->toBe($poster)
        ->and($rgb & 0xFF)->toBeGreaterThan(200)
        ->and($meta['game_asset'])->toMatchArray([
            'kind'         => 'unity-package',
            'asset_count'  => 2,
            'preview_path' => 'Assets/Prefabs/Crate.prefab',
        ])
        ->and(array_column($meta['game_asset']['assets'], 'path'))->toBe(['Assets/Prefabs/Crate.prefab', 'Assets/Textures/Rust.png']);
});

it('records an unreadable file without a poster and without throwing', function () {
    $asset = gameAssetFixture('broken.unitypackage', 'definitely not a tar');

    (new GenerateGameAssetThumbnail($asset->id))->handle();

    $meta = $asset->fresh()->meta_data;
    expect($meta['game_asset'])->toMatchArray(['kind' => 'unity-package', 'readable' => false, 'has_thumbnail' => false])
        ->and($meta)->not->toHaveKey('thumbnail_path');
});

it('previews a Unity text asset as escaped JSON text, and a binary one as binary', function () {
    $yaml = "%YAML 1.1\n%TAG !u! tag:unity3d.com,2011:\n--- !u!1 &1\nGameObject:\n  m_Name: <b>Robot</b>\n";
    $text = gameAssetFixture('Robot.prefab', $yaml);
    $binary = gameAssetFixture('Data.asset', "\0\0\0\x15binary-serialized");
    $this->loginAsAdmin();

    $response = $this->getJson(route('admin.dam.file.preview', ['path' => urlencode($text->path), 'text' => 1]))
        ->assertOk()->assertJson(['binary' => false, 'text' => $yaml, 'truncated' => false]);
    expect($response->getContent())->not->toContain('<b>');

    $this->getJson(route('admin.dam.file.preview', ['path' => urlencode($binary->path), 'text' => 1]))
        ->assertOk()->assertJson(['binary' => true, 'text' => null]);

    expect(AssetHelper::gameAssetPreview($text))->toMatchArray(['kind' => 'unity-yaml', 'read' => false])
        ->and(AssetHelper::gameAssetPreview($text)['textUrl'])->toContain('text=1');
});

it('caps a Unity text preview at 256 KB', function () {
    $asset = gameAssetFixture('Big.unity', "%YAML 1.1\n".str_repeat("  m_Value: 1\n", 30000));
    $this->loginAsAdmin();

    $response = $this->getJson(route('admin.dam.file.preview', ['path' => urlencode($asset->path), 'text' => 1]))->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and(strlen($response->json('text')))->toBe(262144);
});

it('files Blender and Unity extensions under their own placeholders', function () {
    expect(AssetHelper::getFileTypeUsingExtension('BLEND'))->toBe('blender')
        ->and(AssetHelper::getFileTypeUsingExtension('unitypackage'))->toBe('unity')
        ->and(AssetHelper::getFileTypeUsingExtension('prefab'))->toBe('unity')
        ->and(AssetHelper::gameAssetPreview(new Asset(['extension' => 'pdf', 'path' => 'assets/a.pdf'])))->toBeNull();
});

it('keeps the model lists the Vue templates spell out equal to PreviewKinds', function () {
    // The browser DOM tests render these templates without PHP, so they hold literal arrays rather than
    // @js(PreviewKinds::...). This keeps the literals from drifting from the PHP list.
    $views = base_path('packages/Mothership/DAM/src/Resources/views/');
    $sorted = function (array $list): array {
        sort($list);

        return $list;
    };
    $literals = [
        'asset/preview-modal/card.blade.php'         => ["['3mf', 'glb', 'stl']", PreviewKinds::MODEL_VIEWER],
        'asset/preview-modal/viewer-modal.blade.php' => ["['3mf', 'glb', 'stl']", PreviewKinds::MODEL_VIEWER],
        'components/shared/asset-card.blade.php'     => ["['glb', '3mf', 'stl']", PreviewKinds::MODEL_VIEWER],
        'share/public/directory.blade.php'           => ["['glb', '3mf', 'stl']", PreviewKinds::MODEL_VIEWER],
        'components/explorer/asset-card.blade.php'   => ["['glb','3mf','stl']", PreviewKinds::MODEL_VIEWER],
        'asset/edit.blade.php'                       => ["['glb', 'gltf', 'stl', '3mf', 'obj', 'fbx', 'usdz']", PreviewKinds::MODEL_3D],
    ];

    foreach ($literals as $view => [$literal, $expected]) {
        expect(file_get_contents($views.$view))->toContain($literal.'.includes(')
            ->and($sorted(json_decode(str_replace("'", '"', $literal), true)))->toBe($sorted($expected));
    }
});
