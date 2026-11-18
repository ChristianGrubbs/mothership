<?php

// Disposable browser-test fixtures only. Append assignments separately from bootstrap stdout.
// Local usage: php tests/e2e-pw/seed-panorama.php --env-file /tmp/panorama.env
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;

try {
    if ($argc !== 3 || $argv[1] !== '--env-file' || $argv[2] === '') {
        throw new InvalidArgumentException('Usage: php tests/e2e-pw/seed-panorama.php --env-file <output-path>');
    }
    $outputPath = $argv[2];

    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing')) {
        throw new RuntimeException('Panorama fixtures require APP_ENV=testing.');
    }

    $root = Directory::whereNull('parent_id')->where('name', 'Root')->firstOrFail();
    $directory = Directory::create(['name' => 'e2e-panorama-'.bin2hex(random_bytes(6)), 'parent_id' => $root->id]);
    $store = app(ContentStore::class);
    $ledger = app(AttachmentLedger::class);
    // Five seconds keeps the existing seek-to-three-seconds assertion meaningful.
    $video = __DIR__.'/assets/pano-5s.mp4';
    $disk = Directory::getAssetDisk();

    $ids = DB::transaction(function () use ($directory, $store, $ledger, $video, $disk): array {
        $ids = [];
        foreach ([
            'PANORAMA_ASSET_ID'        => ['build.osv', 'application/octet-stream', Asset::PANORAMA_AUTO],
            'PANORAMA_DIRECT_VIDEO_ID' => ['direct.mp4', 'video/mp4', Asset::PANORAMA_FORCE_360],
            'PANORAMA_FORCE_FLAT_ID'   => ['flat.mp4', 'video/mp4', Asset::PANORAMA_FORCE_FLAT],
            'PANORAMA_PROXY_ID'        => ['proxy.osv', 'application/octet-stream', Asset::PANORAMA_AUTO],
        ] as $key => [$name, $mime, $mode]) {
            // OSV originals only exercise classification: no conversion is invoked by this suite.
            // The proxy and direct playback contain the committed synthetic H.264 fixture's real bytes.
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $source = $extension === 'osv' ? __DIR__.'/assets/pano-source.osv' : $video;
            $asset = Asset::create([
                'file_name'     => $name, 'path' => 'assets/'.$directory->generatePath().'/'.$name,
                'file_type'     => $extension === 'osv' ? 'document' : 'video',
                'extension'     => $extension, 'mime_type' => $mime, 'file_size' => filesize($source),
                'panorama_mode' => $mode, 'meta_data' => ['ImageWidth' => 320, 'ImageHeight' => 160],
            ]);
            $asset->directories()->attach($directory->id);
            $object = $store->putLocal(disk: $disk, localPath: $source, mimeType: $mime, extension: $extension,
                attributes: ['technical' => ['width' => 320, 'height' => 160]]);
            $original = $ledger->attachObject(asset: $asset, object: $object, role: AssetFile::ROLE_ORIGINAL, label: $name);
            if ($key === 'PANORAMA_PROXY_ID') {
                $object = $store->putLocal(disk: $disk, localPath: $video, mimeType: 'video/mp4', extension: 'mp4',
                    attributes: ['technical' => ['width' => 320, 'height' => 160]]);
                $ledger->attachObject(asset: $asset, object: $object, role: AssetFile::ROLE_PROXY, label: 'Generated 360 Preview (4K)');
                Rendition::create([
                    'dam_asset_id' => $asset->id, 'source_asset_file_id' => $original->id,
                    'recipe'       => Rendition::RECIPE_PANORAMA_4K, 'status' => Rendition::STATUS_DONE,
                    'progress'     => 100, 'finished_at' => now(), 'output_file_object_id' => $object->id,
                ]);
            }
            $ids[$key] = $asset->id;
        }

        return $ids;
    });

    $assignments = '';
    foreach ($ids as $name => $id) {
        if (! preg_match('/\APANORAMA_(?:ASSET_ID|DIRECT_VIDEO_ID|FORCE_FLAT_ID|PROXY_ID)\z/', $name) || ! is_int($id) || $id < 1) {
            throw new RuntimeException('Invalid panorama fixture environment assignment.');
        }
        $assignments .= $name.'='.$id."\n";
    }

    if (file_put_contents($outputPath, $assignments, FILE_APPEND | LOCK_EX) !== strlen($assignments)) {
        throw new RuntimeException('Could not write panorama fixture environment assignments.');
    }
} catch (Throwable $error) {
    // Laravel's console exception handler can render an error and still exit zero
    // when this script is invoked directly rather than through artisan.
    fwrite(STDERR, 'Panorama fixture seed failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
