<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Browser-compatible thumbnail and preview of a HEIC/HEIF still, made with libvips (no PHP decoder reads HEIF).
 *
 * The caller has already staged the original (ContentStore::localPath(), ADR 0009), so the blob is only read.
 * Output is a WebP at the content-keyed derivative path (DerivativeImages), downscaled to fit `$maxSize`, rotated
 * to the orientation the file declares, with metadata dropped. AVIF is browser-decodable and stays out of this.
 */
class HeifPreviewService
{
    /**
     * ISO-BMFF `ftyp` major brands of a HEIC/HEIF still: `heic` (HEVC Main), `heix` (HEVC Main 10 and other extended
     * profiles), `heim`/`heis` (multiview and scalable stills, whose base image libheif reads) and the generic `mif1`.
     * The sequence brands (`hevc`, `hevx`, `hevm`, `hevs`, `msf1`) are deliberately absent.
     */
    private const BRANDS = ['heic', 'heix', 'heim', 'heis', 'mif1'];

    private const TIMEOUT_SECONDS = 60;

    /**
     * Whether the staged file is a HEIC/HEIF by its bytes, never by a name or a stored type. A `mif1` file that also
     * lists the `avif` brand is an AVIF still.
     */
    public static function isHeif(string $localSource): bool
    {
        $head = @file_get_contents($localSource, false, null, 0, 64);

        if (! is_string($head) || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($head, 8, 4), self::BRANDS, true) && ! str_contains($head, 'avif');
    }

    /**
     * Publish the WebP derivative of a staged HEIC/HEIF at `$destinationPath`. False when the file is not a HEIF
     * (the caller's ordinary path applies); a decoder or publishing failure throws, after logging and cleanup.
     */
    public function generateIfHeif(string $disk, string $localSource, string $destinationPath, int $maxSize): bool
    {
        if (! self::isHeif($localSource)) {
            return false;
        }

        if ($maxSize < 1 || $maxSize > 1920 || ! str_ends_with(strtolower($destinationPath), '.webp')) {
            throw new RuntimeException('HEIF preview size or destination is outside the supported range.');
        }

        $storage = Storage::disk($disk);
        if ($storage->exists($destinationPath)) {
            return true;
        }

        $runId = (string) Str::uuid();
        $startedAt = microtime(true);
        $outputPath = null;
        $pendingPath = null;

        try {
            $base = tempnam(sys_get_temp_dir(), 'dam_heif_out_');
            if ($base === false) {
                throw new RuntimeException('HEIF output temporary file could not be created.');
            }
            unlink($base);
            $outputPath = $base.'.webp';

            $this->log('selected', $runId, ['disk' => $disk, 'target_size' => $maxSize]);
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
                'vips', 'thumbnail', $localSource, $outputPath.'[Q=82,strip]', (string) $maxSize,
                '--height', (string) $maxSize,
                '--size', 'down',
            ]);
            if (! $result->successful() || ! is_file($outputPath)) {
                throw new RuntimeException('libvips could not decode the HEIF still.');
            }

            // A missing codec (no HEVC plugin in libheif) makes vips report "error in tile", exit 0 and write a black image;
            // that is a decode failure, not a preview, so it is never published. Profile warnings stay harmless.
            if (str_contains($result->errorOutput(), 'error in tile')) {
                throw new RuntimeException('libvips reported decode errors for the HEIF still.');
            }

            $info = @getimagesize($outputPath);
            if (! $info || ($info['mime'] ?? null) !== 'image/webp' || max((int) $info[0], (int) $info[1]) > $maxSize) {
                throw new RuntimeException('libvips generated an invalid HEIF derivative.');
            }

            $pendingPath = $destinationPath.'.pending.'.$runId;
            $output = fopen($outputPath, 'rb');
            if (! is_resource($output)) {
                throw new RuntimeException('HEIF derivative could not be opened for publishing.');
            }
            try {
                if (! $storage->put($pendingPath, $output)) {
                    throw new RuntimeException('HEIF derivative could not be staged.');
                }
            } finally {
                fclose($output);
            }

            if ($storage->exists($destinationPath)) {
                $storage->delete($pendingPath);
            } elseif (! $storage->move($pendingPath, $destinationPath) && ! $storage->exists($destinationPath)) {
                throw new RuntimeException('HEIF derivative could not be published.');
            }

            $this->log('generated', $runId, [
                'disk'        => $disk,
                'output_w'    => (int) $info[0],
                'output_h'    => (int) $info[1],
                'target_size' => $maxSize,
                'elapsed_ms'  => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return true;
        } catch (Throwable $error) {
            $this->log('failed', $runId, ['disk' => $disk, 'target_size' => $maxSize, 'error_type' => $error::class], true);

            throw $error;
        } finally {
            if ($pendingPath !== null && $storage->exists($pendingPath)) {
                $storage->delete($pendingPath);
            }
            if ($outputPath !== null && is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function log(string $event, string $runId, array $context, bool $failed = false): void
    {
        if ($failed || config('app.debug')) {
            Log::log($failed ? 'warning' : 'debug', json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => $runId,
                'level'  => $failed ? 'warning' : 'debug',
                'event'  => 'heif-preview.'.$event,
                'loc'    => self::class,
                'ctx'    => $context,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
