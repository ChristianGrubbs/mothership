<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use RuntimeException;

/**
 * Publishes a 1080p preview proxy: validates the transcode's own probe
 * output against the preview profile and, only if it passes, writes the
 * bytes as a content-addressed object and marks the rendition done —
 * both inside one DB transaction, so a validation failure or a crash
 * mid-publish can never leave a half-linked rendition and can never
 * displace whatever proxy the asset was already serving.
 *
 * The sibling of RenditionPublisher (which owns the 4K panorama profile).
 * The two deliberately share only ContentStore: the object-writing rules
 * are universal, the profile rules are not.
 */
final class VideoProxyPublisher
{
    private const REQUIRED_MIME = 'video/mp4';

    private const MAX_WIDTH = 1920;

    private const REQUIRED_VIDEO_CODEC = 'h264';

    private const REQUIRED_PIXEL_FORMAT = 'yuv420p';

    public const LABEL = 'Preview (1080p)';

    /**
     * @param  array<string, mixed>  $technical  ffprobe-derived facts about the produced file.
     *
     * @throws RuntimeException with a short, path-free code the caller stores as the
     *                          rendition's redacted error.
     */
    public function publish(Rendition $rendition, string $localPath, array $technical): void
    {
        // R19: Terminal no-op at the start — if already terminal, return immediately
        $currentStatus = Rendition::query()->whereKey($rendition->id)->value('status');
        if (in_array($currentStatus, [
            Rendition::STATUS_DONE,
            Rendition::STATUS_FAILED,
            Rendition::STATUS_CANCELLED,
        ], true)) {
            $locked = Rendition::query()->whereKey($rendition->id)->firstOrFail();
            $rendition->setRawAttributes($locked->getAttributes(), true);

            return;
        }

        $this->validate($technical);

        // R18: Container check — verify MP4 ftyp header before hashing
        $this->verifyMp4Container($localPath);

        $sha256 = hash_file('sha256', $localPath);

        if ($sha256 === false) {
            throw new RuntimeException('checksum_failed');
        }

        $localSize = filesize($localPath);

        if ($localSize === false) {
            throw new RuntimeException('output_unreadable');
        }

        $disk = Directory::getAssetDisk();

        DB::transaction(function () use ($rendition, $disk, $localPath, $sha256, $technical): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();

            // R19: Keep race-safety check — if another process made it terminal, sync and return
            // (before writing anything: a terminal rendition must never gain an unreferenced row)
            if (in_array($locked->status, [
                Rendition::STATUS_DONE,
                Rendition::STATUS_FAILED,
                Rendition::STATUS_CANCELLED,
            ], true)) {
                $rendition->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            // Inside this transaction, so the FileObject record() creates or reuses commits (or
            // rolls back) together with the rendition's own reference to it below — a later
            // failure in this same publish can never strand an unreferenced row.
            $object = app(ContentStore::class)->putLocal(
                disk: $disk,
                localPath: $localPath,
                sha256: $sha256,
                mimeType: self::REQUIRED_MIME,
                extension: 'mp4',
                attributes: [
                    'sniffed_type' => self::REQUIRED_MIME,
                    'technical'    => $technical,
                ],
            );

            // R21: updateOrCreate ensures label and sort are always correct
            $output = AssetFile::updateOrCreate([
                'dam_asset_id'   => $locked->dam_asset_id,
                'file_object_id' => $object->id,
                'role'           => AssetFile::ROLE_PROXY,
            ], [
                'label' => self::LABEL,
                'sort'  => 900,
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

    /**
     * @param  array<string, mixed>  $technical
     */
    private function validate(array $technical): void
    {
        $width = (int) ($technical['width'] ?? 0);
        $height = (int) ($technical['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('dimensions_unknown');
        }

        if ($width > self::MAX_WIDTH) {
            throw new RuntimeException('proxy_too_large');
        }

        if (strtolower((string) ($technical['codec_name'] ?? '')) !== self::REQUIRED_VIDEO_CODEC) {
            throw new RuntimeException('codec_rejected');
        }

        if (strtolower((string) ($technical['pix_fmt'] ?? '')) !== self::REQUIRED_PIXEL_FORMAT) {
            throw new RuntimeException('pixel_format_rejected');
        }

        // R16: audio validation — reject if audio exists and is not 'aac' or 'none'
        if (array_key_exists('audio', $technical)) {
            $audio = strtolower((string) $technical['audio']);

            if ($audio !== 'aac' && $audio !== 'none') {
                throw new RuntimeException('audio_rejected');
            }
        }
    }

    /**
     * R18: Verify MP4 ftyp container header before hashing.
     * Reads first 12 bytes; if fewer than 8 or bytes 4–7 are not 'ftyp', rejects.
     *
     * @throws RuntimeException container_rejected on invalid container
     */
    private function verifyMp4Container(string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('output_unreadable');
        }

        try {
            $header = fread($handle, 12);
            if (strlen($header) < 8) {
                throw new RuntimeException('container_rejected');
            }

            $ftypMarker = substr($header, 4, 4);
            if ($ftypMarker !== 'ftyp') {
                throw new RuntimeException('container_rejected');
            }
        } finally {
            fclose($handle);
        }
    }
}
