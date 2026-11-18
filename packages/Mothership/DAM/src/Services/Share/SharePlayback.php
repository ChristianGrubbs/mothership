<?php

namespace Mothership\DAM\Services\Share;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Share;

/**
 * P2 S6: what a public link's video player plays. The choice is the detail player's own, Asset::playbackFile(): the
 * published proxy when there is one, otherwise the original. A link only swaps the URL for its share-scoped one
 * (dam.share.proxy, or dam.share.preview for the original), and the proxy route asks this same method which file to
 * stream, so the page and the bytes cannot disagree.
 */
final class SharePlayback
{
    /**
     * The attachment the detail player would stream instead of the original: a flat video proxy. Null when it would
     * play the original, or when its pick is a 360 rendition the public page has no player for.
     */
    public static function proxyFile(Asset $asset): ?AssetFile
    {
        $file = $asset->playbackFile();

        if ($file === null || $file['asset_file_id'] === null || $file['kind'] !== 'video') {
            return null;
        }

        return AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->where('id', $file['asset_file_id'])
            ->with('fileObject')
            ->first();
    }

    /**
     * The page's player source for $asset through $share (opened with $token): the proxy route and the proxy's type,
     * or the preview route and the asset's own type.
     *
     * @return array{url: string, mime: string, is_proxy: bool}
     */
    public static function source(Share $share, Asset $asset, string $token): array
    {
        $assetLink = $share->share_type === Share::TYPE_ASSET;
        $proxy = self::proxyFile($asset);

        if ($proxy !== null) {
            return [
                'url'      => $assetLink
                    ? route('dam.share.proxy', ['token' => $token])
                    : route('dam.share.asset_proxy', ['token' => $token, 'assetId' => $asset->id]),
                'mime'     => (string) ($proxy->fileObject?->mime_type ?: 'video/mp4'),
                'is_proxy' => true,
            ];
        }

        return [
            'url'      => self::previewUrl(share: $share, asset: $asset, token: $token),
            'mime'     => (string) $asset->mime_type,
            'is_proxy' => false,
        ];
    }

    /**
     * The stills the stage shows as a screen-sized copy rather than the whole file, by MIME, with the extension the copy
     * is encoded from (DerivativeImages' kept-format rule). GIF (animation), SVG, HEIF and DNG keep preview()'s answer.
     */
    public const DISPLAY_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Below this an original is about the size of its copy, so the stage shows it as it is. */
    public const DISPLAY_MIN_BYTES = 1048576;

    /** The width the stage's copy is scaled down to: the share's own `share-preview-1920`, which its stacked stills already use. */
    public const DISPLAY_SIZE = 1920;

    /**
     * The image stage's source (and its preload): dam.share.display / asset_display for a large still, whose controller
     * makes the same choice from the bytes it serves, otherwise previewUrl(). The original stays on previewUrl().
     */
    public static function stageUrl(Share $share, Asset $asset, string $token): string
    {
        if (! self::displaysCopy(mime: (string) $asset->mime_type, bytes: (int) $asset->file_size)) {
            return self::previewUrl(share: $share, asset: $asset, token: $token);
        }

        return $share->share_type === Share::TYPE_ASSET
            ? route('dam.share.display', ['token' => $token])
            : route('dam.share.asset_display', ['token' => $token, 'assetId' => $asset->id]);
    }

    /** Whether a still of this type and size is shown as its screen-sized copy. */
    public static function displaysCopy(string $mime, int $bytes): bool
    {
        return isset(self::DISPLAY_MIMES[strtolower(trim(explode(';', $mime)[0]))]) && $bytes > self::DISPLAY_MIN_BYTES;
    }

    /** The original shown inline through this link (dam.share.preview / asset_preview). */
    public static function previewUrl(Share $share, Asset $asset, string $token): string
    {
        return $share->share_type === Share::TYPE_ASSET
            ? route('dam.share.preview', ['token' => $token])
            : route('dam.share.asset_preview', ['token' => $token, 'assetId' => $asset->id]);
    }
}
