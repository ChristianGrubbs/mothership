<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Media\PanoramaWorkerResult;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use RuntimeException;

/**
 * Validates a worker's result against the panorama proxy profile and, only
 * if it passes, publishes it as a content-addressed object (via ContentStore)
 * and marks the rendition done — both inside one DB transaction, so a
 * validation failure or a crash mid-publish can never leave a half-linked
 * rendition and can never displace whatever proxy the asset was already
 * serving.
 */
final class RenditionPublisher
{
    private const REQUIRED_MIME = 'video/mp4';

    private const REQUIRED_WIDTH = 3840;

    private const REQUIRED_HEIGHT = 1920;

    private const REQUIRED_PIXEL_FORMAT = 'yuv420p';

    /**
     * `technical.audio` enum — see CliPanoramaWorker's docblock for the
     * full rationale (audio is not required to exist; when it does, only
     * these three values are ever acceptable output).
     */
    private const VALID_AUDIO_VALUES = ['aac', 'copied', 'none'];

    /**
     * @throws RuntimeException when the result fails validation. The message
     *                          never embeds a filesystem path — GeneratePanoramaPreview stores
     *                          and logs it directly as the rendition's redacted error code.
     */
    public function publish(Rendition $rendition, PanoramaWorkerResult $result): void
    {
        $this->validate($result);

        $sha256 = hash_file('sha256', $result->path);

        if ($sha256 === false) {
            throw new RuntimeException('checksum_failed');
        }

        $localSize = filesize($result->path);

        if ($localSize === false) {
            throw new RuntimeException('output_unreadable');
        }

        $disk = Directory::getAssetDisk();
        $path = $result->path;
        $technical = $result->technical;

        DB::transaction(function () use ($rendition, $disk, $path, $sha256, $technical): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();

            // Inside this transaction, so the FileObject record() creates or reuses commits (or
            // rolls back) together with the rendition's own reference to it below — a later
            // failure in this same publish can never strand an unreferenced row.
            $object = app(ContentStore::class)->putLocal(
                disk: $disk,
                localPath: $path,
                sha256: $sha256,
                mimeType: self::REQUIRED_MIME,
                extension: 'mp4',
                attributes: [
                    'sniffed_type' => self::REQUIRED_MIME,
                    'technical'    => $technical,
                ],
            );

            $output = AssetFile::firstOrCreate([
                'dam_asset_id'   => $locked->dam_asset_id,
                'file_object_id' => $object->id,
                'role'           => AssetFile::ROLE_PROXY,
            ], [
                'label' => 'Generated 360 Preview (4K)',
                'sort'  => 1000,
            ]);

            $locked->update([
                'output_file_object_id' => $object->id,
                'output_asset_file_id'  => $output->id,
                'status'                => Rendition::STATUS_DONE,
                'progress'              => 100,
                'error'                 => null,
                'finished_at'           => now(),
            ]);

            $rendition->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function validate(PanoramaWorkerResult $result): void
    {
        if ($result->mime !== self::REQUIRED_MIME) {
            throw new RuntimeException('invalid_mime');
        }

        if (! is_file($result->path) || ! is_readable($result->path)) {
            throw new RuntimeException('output_unreadable');
        }

        $size = filesize($result->path);

        if ($size === false || $size < 1) {
            throw new RuntimeException('empty_output');
        }

        $width = $result->technical['width'] ?? null;
        $height = $result->technical['height'] ?? null;
        $codec = strtolower((string) ($result->technical['codec_name'] ?? ''));

        if ($width === null || $height === null || $codec === '') {
            throw new RuntimeException('unreadable_technical_metadata');
        }

        if ((int) $width !== self::REQUIRED_WIDTH || (int) $height !== self::REQUIRED_HEIGHT) {
            throw new RuntimeException('invalid_dimensions');
        }

        if (! str_contains($codec, 'h264') && ! str_contains($codec, 'avc')) {
            throw new RuntimeException('invalid_codec');
        }

        // Global constraint: 3840x1920 h264 alone is not enough — a flat,
        // non-spherical, non-yuv420p, moov-trailing MP4 (or one carrying
        // an undecodable audio codec) at the right dimensions and codec
        // would otherwise publish green. All four fields below are
        // required as of this contract version; Task 6 must always report
        // them.
        $pixFmt = strtolower((string) ($result->technical['pix_fmt'] ?? ''));
        $spherical = $result->technical['spherical'] ?? null;
        $faststart = $result->technical['faststart'] ?? null;
        $audio = $result->technical['audio'] ?? null;

        if ($pixFmt === '' || ! is_bool($spherical) || ! is_bool($faststart) || ! is_string($audio)) {
            throw new RuntimeException('unreadable_technical_metadata');
        }

        if ($pixFmt !== self::REQUIRED_PIXEL_FORMAT) {
            throw new RuntimeException('invalid_pixel_format');
        }

        if ($spherical !== true) {
            throw new RuntimeException('missing_spherical_metadata');
        }

        // No legitimate output has the moov atom trailing the mdat: the
        // CLI produces the file, so it can verify this with complete
        // reliability, and a progressive-download player having to fetch
        // the whole proxy before the first frame is a total failure for
        // an "on-demand preview" feature — false-failure risk is zero.
        if ($faststart !== true) {
            throw new RuntimeException('invalid_faststart');
        }

        // Audio is NOT required to exist — 360 rig captures are routinely
        // silent and .osv sources may carry no audio stream at all, so
        // "none" is a legitimate, expected value, not a failure. This
        // only catches a codec browsers can't decode (opus/ac3/mp3/etc)
        // surviving into the MP4 container.
        if (! in_array($audio, self::VALID_AUDIO_VALUES, true)) {
            throw new RuntimeException('invalid_audio');
        }
    }
}
