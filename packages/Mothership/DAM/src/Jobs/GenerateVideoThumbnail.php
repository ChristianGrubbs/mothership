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
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\Process\Process;

class GenerateVideoThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(protected int $assetId) {}

    /**
     * Exposed for deterministic tests and diagnostics; handle() consumes this exact source.
     *
     * @return array{asset_file_id: int|null, disk: string, path: string, extension: string, mime: string, kind: string, label: string}|null
     */
    public function source(): ?array
    {
        $asset = Asset::find($this->assetId);
        $source = $asset?->playbackFile();

        if (! $source || ! in_array($source['kind'], ['video', 'pano360-video'], true)) {
            return null;
        }

        return $source;
    }

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);
        $source = $this->source();

        if ($asset && ! $source && $asset->file_type === 'video') {
            // Playback skips an original whose blob is gone, so no source is selected; record that
            // instead of leaving the card on its placeholder without a trace.
            try {
                $store = app(ContentStore::class);
                $store->ensureAvailable($store->current($asset));
            } catch (ContentUnavailable $e) {
                Log::warning('DAM video thumbnail: source file missing.', [
                    'asset'   => $asset->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (! $asset || ! $source) {
            return;
        }

        $store = app(ContentStore::class);

        try {
            $object = $this->sourceObject($store, $asset, $source);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM video thumbnail: source file missing.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $posterPath = DerivativeImages::poster($object->sha256);
        $ext = $source['extension'] ?: 'mp4';
        $local = null;
        $tmpJpg = null;

        try {
            $local = $store->localPath($object, $ext);
            $tmpJpg = $this->temporaryFile('damvid_thumb_', 'jpg');

            [$successful, $stderr] = $this->runFfmpeg([
                'ffmpeg',
                '-y',
                '-ss', '00:00:01',
                '-i', $local->path,
                '-vframes', '1',
                '-vf', 'scale=300:-2',
                '-q:v', '4',
                $tmpJpg,
            ]);

            if (! $successful || ! file_exists($tmpJpg) || filesize($tmpJpg) === 0) {
                [$retrySuccessful, $retryStderr] = $this->runFfmpeg([
                    'ffmpeg',
                    '-y',
                    '-i', $local->path,
                    '-vframes', '1',
                    '-vf', 'scale=300:-2',
                    '-q:v', '4',
                    $tmpJpg,
                ]);

                if (! $retrySuccessful || ! file_exists($tmpJpg) || filesize($tmpJpg) === 0) {
                    Log::warning('DAM video thumbnail: ffmpeg failed.', [
                        'asset'  => $asset->id,
                        'stderr' => $stderr.$retryStderr,
                    ]);

                    return;
                }
            }

            Storage::disk(Directory::getAssetDisk())->put($posterPath, file_get_contents($tmpJpg));

            // GenerateVideoPreviewProxy is dispatched alongside this job and may
            // record probe facts in meta_data while ffmpeg runs, so merge into a
            // freshly read row under a lock rather than saving the stale $asset.
            DB::transaction(function () use ($asset, $posterPath): void {
                $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                $locked->meta_data = array_merge(
                    is_array($locked->meta_data) ? $locked->meta_data : [],
                    ['thumbnail_path' => $posterPath],
                );
                $locked->saveQuietly();
            });

            // P5c (D16): the poster is the preview a video's perceptual hash is computed from.
            if (config('dam.similar.enabled', true)) {
                ComputeAssetPhash::dispatch($asset->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('DAM video thumbnail: exception while generating.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);
        } finally {
            $local?->release();

            if ($tmpJpg !== null) {
                @unlink($tmpJpg);
            }
        }
    }

    /**
     * The FileObject `source()` names: the asset's current original when it has no attachment id,
     * else that AssetFile's own blob (ADR 0009) — a proxy or generated panorama output. Never a
     * Library path.
     *
     * @param  array{asset_file_id: int|null, disk: string, path: string, extension: string, mime: string, kind: string, label: string}  $source
     *
     * @throws ContentUnavailable
     */
    protected function sourceObject(ContentStore $store, Asset $asset, array $source): FileObject
    {
        if ($source['asset_file_id'] === null) {
            return $store->current($asset);
        }

        $object = AssetFile::query()->with('fileObject')->find($source['asset_file_id'])?->fileObject;

        if ($object === null || ! $store->holdsBlob($object)) {
            throw new ContentUnavailable(
                reason: ContentUnavailable::BLOB_MISSING,
                fileObjectId: $object?->getKey(),
                assetId: $asset->id,
            );
        }

        return $object;
    }

    protected function temporaryFile(string $prefix, string $extension): string
    {
        $basePath = tempnam(sys_get_temp_dir(), $prefix);

        if ($basePath === false) {
            throw new \RuntimeException('Unable to create a temporary file.');
        }

        $path = $basePath.'.'.ltrim($extension, '.');

        if (! @rename($basePath, $path)) {
            @unlink($basePath);

            throw new \RuntimeException('Unable to prepare a temporary file.');
        }

        return $path;
    }

    /**
     * @return array{bool, string}
     */
    protected function runFfmpeg(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        return [$process->isSuccessful(), $process->getErrorOutput()];
    }
}
