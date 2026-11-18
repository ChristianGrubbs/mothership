<?php

namespace Mothership\DAM\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LargeRasterPreviewService
{
    private const DEFAULT_PIXEL_THRESHOLD = 32_000_000;

    private const OUTPUT_SUFFIX_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const SOURCE_MIMES = ['image/jpeg', 'image/png'];

    /** The pixel count from which a raster is libvips's, not GD's (`mothership.large_raster_preview_pixels`). */
    public static function pixelThreshold(): int
    {
        return max(1, (int) config('mothership.large_raster_preview_pixels', self::DEFAULT_PIXEL_THRESHOLD));
    }

    /**
     * Generate a bounded derivative with libvips when GD would need to decode an
     * oversized raster in PHP. Returns false when the ordinary GD path should be used.
     *
     * `$localSource` is a real path the caller already staged (ContentStore::localPath(), ADR 0009); then
     * `$sourcePath` is not read and `$disk` only receives the derivative.
     */
    public function generateIfNeeded(
        string $disk,
        string $sourcePath,
        string $destinationPath,
        int $maxSize,
        ?string $localSource = null,
    ): bool {
        if ($maxSize < 1 || $maxSize > 1920) {
            throw new RuntimeException('Large raster preview size is outside the supported range.');
        }

        $storage = Storage::disk($disk);
        if ($storage->exists($destinationPath)) {
            return true;
        }

        $runId = (string) Str::uuid();
        $startedAt = microtime(true);
        $inputPath = $localSource ?? $this->localPath(storage: $storage, path: $sourcePath);
        $temporaryInput = false;
        $outputPath = null;
        $pendingPath = null;
        $sourceWidth = null;
        $sourceHeight = null;
        $sourceMime = null;

        try {
            if ($inputPath === null) {
                $inputPath = $this->copyToPrivateTemp(storage: $storage, path: $sourcePath);
                $temporaryInput = true;
            }

            $sourceInfo = @getimagesize($inputPath);
            if (! $sourceInfo) {
                return false;
            }
            $sourceMime = $sourceInfo['mime'] ?? null;
            if (! in_array($sourceMime, self::SOURCE_MIMES, true)) {
                return false;
            }
            // A `.webp` destination (see DerivativeImages) gets WebP bytes; any other
            // destination keeps the source format so path and content stay coherent.
            $outputMime = str_ends_with(strtolower($destinationPath), '.webp') ? 'image/webp' : $sourceMime;
            $suffix = self::OUTPUT_SUFFIX_BY_MIME[$outputMime];

            [$sourceWidth, $sourceHeight] = [(int) $sourceInfo[0], (int) $sourceInfo[1]];
            if (($sourceWidth * $sourceHeight) < self::pixelThreshold()) {
                return false;
            }

            $outputPath = $this->temporaryOutputPath($suffix);
            $command = [
                'vips', 'thumbnail', $inputPath, $outputPath, (string) $maxSize,
                '--height', (string) $maxSize,
                '--size', 'down',
            ];
            $this->log('selected', $runId, [
                'disk'        => $disk,
                'mime'        => $sourceMime,
                'source_w'    => $sourceWidth,
                'source_h'    => $sourceHeight,
                'target_size' => $maxSize,
            ]);
            $result = Process::timeout(120)->run($command);
            if (! $result->successful() || ! is_file($outputPath)) {
                throw new RuntimeException('libvips could not generate the large raster derivative.');
            }

            $outputInfo = @getimagesize($outputPath);
            if (
                ! $outputInfo
                || ($outputInfo['mime'] ?? null) !== $outputMime
                || max((int) $outputInfo[0], (int) $outputInfo[1]) > $maxSize
            ) {
                throw new RuntimeException('libvips generated an invalid large raster derivative.');
            }

            $pendingPath = $destinationPath.'.pending.'.$runId;
            $output = fopen($outputPath, 'rb');
            if (! is_resource($output)) {
                throw new RuntimeException('Large raster derivative could not be opened for publishing.');
            }
            try {
                if (! $storage->put($pendingPath, $output)) {
                    throw new RuntimeException('Large raster derivative could not be staged.');
                }
            } finally {
                fclose($output);
            }

            if ($storage->exists($destinationPath)) {
                $storage->delete($pendingPath);
            } elseif (! $storage->move($pendingPath, $destinationPath)) {
                if (! $storage->exists($destinationPath)) {
                    throw new RuntimeException('Large raster derivative could not be published.');
                }
            }

            $this->log('generated', $runId, [
                'disk'        => $disk,
                'mime'        => $sourceMime,
                'output_mime' => $outputMime,
                'source_w'    => $sourceWidth,
                'source_h'    => $sourceHeight,
                'output_w'    => (int) $outputInfo[0],
                'output_h'    => (int) $outputInfo[1],
                'target_size' => $maxSize,
                'elapsed_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return true;
        } catch (Throwable $error) {
            $this->log('failed', $runId, [
                'disk'       => $disk,
                'mime'       => $sourceMime,
                'source_w'   => $sourceWidth,
                'source_h'   => $sourceHeight,
                'error_type' => $error::class,
            ], true);

            throw $error;
        } finally {
            if ($pendingPath !== null && $storage->exists($pendingPath)) {
                $storage->delete($pendingPath);
            }
            if ($outputPath !== null && is_file($outputPath)) {
                unlink($outputPath);
            }
            if ($temporaryInput && $inputPath !== null && is_file($inputPath)) {
                unlink($inputPath);
            }
        }
    }

    private function localPath(mixed $storage, string $path): ?string
    {
        if (! $storage instanceof FilesystemAdapter) {
            return null;
        }

        try {
            $absolute = $storage->path($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($absolute) && is_file($absolute) ? $absolute : null;
    }

    private function copyToPrivateTemp(mixed $storage, string $path): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'dam_raster_');
        if ($temporary === false) {
            throw new RuntimeException('Large raster input temporary file could not be created.');
        }
        chmod($temporary, 0600);

        $source = $storage->readStream($path);
        $destination = fopen($temporary, 'wb');
        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            unlink($temporary);

            throw new RuntimeException('Large raster input could not be streamed to private temporary storage.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Large raster input could not be copied to private temporary storage.');
            }
        } catch (Throwable $error) {
            unlink($temporary);

            throw $error;
        } finally {
            fclose($source);
            fclose($destination);
        }

        return $temporary;
    }

    private function temporaryOutputPath(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'dam_raster_out_');
        if ($base === false) {
            throw new RuntimeException('Large raster output temporary file could not be created.');
        }
        unlink($base);

        return $base.'.'.$suffix;
    }

    private function log(string $event, string $runId, array $context, bool $failed = false): void
    {
        if ($failed || config('app.debug')) {
            Log::log($failed ? 'warning' : 'debug', json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => $runId,
                'level'  => $failed ? 'warning' : 'debug',
                'event'  => 'large-raster-preview.'.$event,
                'loc'    => self::class,
                'ctx'    => $context,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
