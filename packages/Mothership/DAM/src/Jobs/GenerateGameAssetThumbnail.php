<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\BlendFileReader;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\UnityPackageReader;

/**
 * The poster and facts of a Blender `.blend` or a Unity `.unitypackage`, read from the thumbnail each tool embeds
 * (no renderer, ADR 0006). The poster is sha-keyed like a PDF's (DerivativeImages::poster), so it is purged with
 * its blob. `meta_data['game_asset']` records what was read, with the sha it was read from: the thumbnail route
 * uses that sha to know a file without an embedded thumbnail was already tried, and does not run the job again.
 */
class GenerateGameAssetThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /** The longest side of a poster made from a Unity preview; Blender's are kept at their own size. */
    private const POSTER_SIDE = 512;

    public function __construct(protected int $assetId) {}

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);
        $kind = PreviewKinds::gameAssetKind($asset?->extension);

        if (! $asset || ! PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
            return;
        }

        $store = app(ContentStore::class);

        try {
            $object = $store->current($asset);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM game asset thumbnail: source file missing.', [
                'asset' => $asset->id, 'message' => $e->getMessage(),
            ]);

            return;
        }

        $local = null;

        try {
            $local = $store->localPath($object, strtolower((string) $asset->extension));

            [$facts, $jpeg] = $kind === PreviewKinds::KIND_BLENDER
                ? $this->readBlend($local->path)
                : $this->readUnityPackage($local->path);

            $posterPath = null;

            if ($jpeg !== null) {
                $posterPath = DerivativeImages::poster($object->sha256);
                Storage::disk(Directory::getAssetDisk())->put($posterPath, $jpeg);
            }

            $facts = ['kind' => $kind, 'sha256' => $object->sha256, 'has_thumbnail' => $posterPath !== null] + $facts;

            // ProcessAssetUpload's siblings may write meta_data while the file is read: merge into a fresh row.
            DB::transaction(function () use ($asset, $facts, $posterPath): void {
                $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                $meta = is_array($locked->meta_data) ? $locked->meta_data : [];
                $meta['game_asset'] = $facts;

                if ($posterPath !== null) {
                    $meta['thumbnail_path'] = $posterPath;
                }

                $locked->meta_data = $meta;
                $locked->saveQuietly();
            });

            if ($posterPath !== null) {
                AiIndexDispatcher::derivativeReady((int) $asset->id);
            }

            if ($posterPath !== null && config('dam.similar.enabled', true)) {
                ComputeAssetPhash::dispatch($asset->id)->afterCommit();
            }

            if (! ($facts['readable'] ?? true)) {
                Log::info('DAM game asset thumbnail: file not readable as its type.', ['asset' => $asset->id, 'kind' => $kind]);
            }
        } catch (\Throwable $e) {
            Log::warning('DAM game asset thumbnail: exception while generating.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);
        } finally {
            $local?->release();
        }
    }

    /** @return array{0: array<string, mixed>, 1: string|null} the facts, and the poster JPEG if there is one */
    private function readBlend(string $path): array
    {
        $info = (new BlendFileReader)->read($path);

        if ($info === null) {
            return [['readable' => false], null];
        }

        $jpeg = $info['thumbnail'] !== null ? BlendFileReader::thumbnailJpeg($info['thumbnail']) : null;

        return [[
            'readable'        => true,
            'blender_version' => $info['version'],
            'compression'     => $info['compression'],
            'counts'          => $info['counts'],
            'complete'        => $info['complete'],
        ], $jpeg];
    }

    /** @return array{0: array<string, mixed>, 1: string|null} the facts, and the poster JPEG if there is one */
    private function readUnityPackage(string $path): array
    {
        $package = (new UnityPackageReader)->read($path);

        if ($package === null) {
            return [['readable' => false], null];
        }

        $jpeg = null;

        // The PNG is untrusted. Its header is checked before GD decodes it, because a few hundred KB of PNG can
        // name a picture of billions of pixels; then it is decoded and re-encoded, never stored as it came.
        if ($package['preview_png'] !== null && UnityPackageReader::pngDimensions($package['preview_png']) !== null) {
            try {
                $jpeg = (string) (new ImageManager(new Driver))->decode($package['preview_png'])
                    ->scaleDown(width: self::POSTER_SIDE, height: self::POSTER_SIDE)
                    ->encode(new JpegEncoder(quality: 85));
            } catch (\Throwable) {
                $jpeg = null;
            }
        }

        return [[
            'readable'     => true,
            'assets'       => $package['assets'],
            'asset_count'  => $package['asset_count'],
            'truncated'    => $package['truncated'],
            'complete'     => $package['complete'],
            'stopped'      => $package['stopped'],
            'preview_path' => $jpeg !== null ? $package['preview_path'] : null,
        ], $jpeg];
    }
}
