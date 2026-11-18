<?php

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\Process\Process;

class DngPreviewService
{
    private float $deadline;

    /**
     * $tempDirectory holds the working copies (the system temp directory when null); tests give each
     * run its own so concurrent jobs sharing /tmp cannot leak files into their assertions.
     */
    public function __construct(private ?string $tempDirectory = null) {}

    public function ensure(Asset $asset, bool $thumbnail = false, int $budgetSeconds = 70, bool $cachedOnly = false): ?string
    {
        if (strtolower((string) $asset->extension) !== 'dng') {
            return null;
        }

        try {
            $object = app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable) {
            return null;
        }

        return $this->build(object: $object, assetId: (int) $asset->id, thumbnail: $thumbnail, budgetSeconds: $budgetSeconds, cachedOnly: $cachedOnly);
    }

    /**
     * The same preview or thumbnail for a blob that is not an asset's own original, such as a file in
     * a stack. Derivatives are keyed by the blob's sha, so a copy shares the asset's. A run that
     * outlives its budget queues nothing (the queued job rebuilds an asset's current original, not this
     * blob); the failure is remembered briefly so a tile does not retry on every request.
     */
    public function ensureForObject(FileObject $object, bool $thumbnail = true, int $budgetSeconds = 10): ?string
    {
        return $this->build(object: $object, assetId: null, thumbnail: $thumbnail, budgetSeconds: $budgetSeconds, cachedOnly: false);
    }

    private function build(FileObject $object, ?int $assetId, bool $thumbnail, int $budgetSeconds, bool $cachedOnly): ?string
    {
        $store = app(ContentStore::class);
        $identity = $object->sha256;
        // A cache/lock namespace only; the derivatives themselves are sha-keyed under
        // DerivativeImages (ADR 0009), so purgeContent() can reclaim them with their blob.
        $key = 'dng-v1-'.$identity;
        $previewPath = DerivativeImages::dngPreview($identity);
        $thumbPath = DerivativeImages::dngThumbnail($identity);
        $outputPath = $thumbnail ? $thumbPath : $previewPath;
        $disk = Storage::disk(Directory::getAssetDisk());
        if ($disk->exists($outputPath)) {
            return $outputPath;
        }
        if ($cachedOnly || Cache::has($key.'-failed')) {
            return null;
        }

        $this->deadline = microtime(true) + min(70, max(1, $budgetSeconds));
        $lock = Cache::lock($key, 90);
        try {
            $lock->block(min(5, $budgetSeconds));
        } catch (LockTimeoutException) {
            return $disk->exists($outputPath) ? $outputPath : null;
        }
        $temp = tempnam($this->tempDirectory ?? sys_get_temp_dir(), 'damdng_');
        $runId = (string) Str::uuid();
        $published = [];
        try {
            if ($disk->exists($outputPath)) {
                return $outputPath;
            }
            $stream = $store->open($object);
            try {
                file_put_contents($temp, $stream);
            } finally {
                fclose($stream);
            }
            if (hash_file('sha256', $temp) !== $identity) {
                throw new \RuntimeException('DNG source changed during preview generation.');
            }
            $imagePath = $this->extractEmbedded($temp);
            $method = 'embedded';
            if ($imagePath === null) {
                $method = 'raw';
                $imagePath = $temp.'.tiff';
                $this->process(['dcraw_emu', '-w', '-o', '1', '-h', '-T', '-Z', '.tiff', $temp])->mustRun();
            }
            ($this->process(['vips', 'thumbnail', $imagePath, $temp.'.jpg', '1920', '--height', '1920', '--size', 'down']))->mustRun();
            ($this->process(['vips', 'thumbnail', $temp.'.jpg', $temp.'.thumb.jpg', '300', '--height', '300', '--size', 'down']))->mustRun();
            foreach ([$previewPath => $temp.'.jpg', $thumbPath => $temp.'.thumb.jpg'] as $destination => $local) {
                $info = @getimagesize($local);
                if (! $info || $info['mime'] !== 'image/jpeg' || ! $disk->put($destination, file_get_contents($local))) {
                    throw new \RuntimeException('DNG preview could not be published.');
                }
                $published[] = $destination;
            }
            $this->log('generated', $runId, ['asset_id' => $assetId, 'method' => $method]);

            // The AI looks at this picture; an index run that found no image for the file starts over once.
            if ($assetId !== null) {
                AiIndexDispatcher::derivativeReady($assetId);
            }

            return $outputPath;
        } catch (\Throwable $error) {
            if ($published) {
                $disk->delete($published);
            }
            if ($assetId !== null && $budgetSeconds < 70 && microtime(true) >= $this->deadline) {
                GenerateDngPreview::dispatch($assetId);
            } else {
                Cache::put($key.'-failed', true, $assetId === null ? 60 : 300);
            }
            $this->log('failed', $runId, ['asset_id' => $assetId, 'error' => get_class($error)], true);

            return null;
        } finally {
            foreach ([$temp, $temp.'.embedded.jpg', $temp.'.tiff', $temp.'.jpg', $temp.'.thumb.jpg'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $lock->release();
        }
    }

    private function extractEmbedded(string $source): ?string
    {
        foreach (['PreviewImage', 'JpgFromRaw', 'OtherImage', 'ThumbnailImage'] as $tag) {
            $process = $this->process(['exiftool', '-b', '-'.$tag, $source]);
            $process->run();
            $bytes = $process->getOutput();
            $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            if (! $process->isSuccessful() || ! $info || $info['mime'] !== 'image/jpeg' || max($info[0], $info[1]) < 640) {
                continue;
            }
            $target = $source.'.embedded.jpg';
            file_put_contents($target, $bytes);
            // Embedded JPEGs often omit the parent RAW's orientation.
            $orientation = $this->process(['exiftool', '-s3', '-n', '-Orientation', $source]);
            $orientation->mustRun();
            $value = (int) trim($orientation->getOutput());
            if ($value >= 1 && $value <= 8) {
                ($this->process(['exiftool', '-overwrite_original', '-Orientation#='.$value, $target]))->mustRun();
            }

            return $target;
        }

        return null;
    }

    private function process(array $command): Process
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new \RuntimeException('DNG preview time budget exceeded.');
        }

        return (new Process($command))->setTimeout($remaining);
    }

    private function log(string $event, string $runId, array $context, bool $failed = false): void
    {
        if ($failed || config('app.debug')) {
            Log::log($failed ? 'warning' : 'debug', json_encode([
                'ts'    => now()->toIso8601String(), 'run_id' => $runId,
                'level' => $failed ? 'warning' : 'debug', 'event' => 'dng.'.$event,
                'loc'   => self::class, 'ctx' => $context,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
