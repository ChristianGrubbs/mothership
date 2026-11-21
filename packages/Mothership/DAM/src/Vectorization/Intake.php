<?php

namespace Mothership\DAM\Vectorization;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\ContentStore;
use stdClass;

/**
 * The one way a conversion request is taken in, for the signed-in Library and Home routes, the REST API and the
 * `mothership` MCP server alike: the 20 MiB and 33,554,432-pixel limits, the raster check, the private input copy
 * and the replay-safe reservation (Requests::submit). Callers decide access first.
 */
class Intake
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_PIXELS = 33554432;

    public const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif', 'tif', 'tiff'];

    private const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/gif', 'image/tiff'];

    public function __construct(private Requests $requests, private ContentStore $content) {}

    /** Queue the asset's current original for conversion; the chosen format is published back onto the asset. */
    public function fromAsset(int $adminId, Asset $asset, string $format, string $requestId): stdClass
    {
        abort_unless(in_array($format, ['svg', 'pdf'], true), 422, 'Choose svg or pdf.');
        abort_unless(in_array(strtolower($asset->extension ?? ''), self::ASSET_EXTENSIONS, true), 422, 'Choose a supported raster image.');

        try {
            $object = $this->content->current($asset);
        } catch (ContentUnavailable) {
            abort(404, 'The original image is unavailable.');
        }

        abort_if($object->size > self::MAX_BYTES, 422, 'Choose an image up to 20 MiB.');

        try {
            $stream = $this->content->open($object);
        } catch (ContentUnavailable) {
            abort(404, 'The original image is unavailable.');
        }

        try {
            $bytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        abort_unless($dimensions && in_array($dimensions['mime'], self::MIMES, true)
            && $this->withinPixels(dimensions: $dimensions), 422, 'Choose a supported image up to 33,554,432 pixels.');

        return $this->reserve(adminId: $adminId, requestId: $requestId, bytes: $bytes, assetId: (int) $asset->id, format: $format);
    }

    /** Queue a one-off file the caller supplies; the result is the caller's own and is filed nowhere. */
    public function fromFile(int $adminId, string $path, string $requestId): stdClass
    {
        $size = @filesize($path);
        abort_unless(is_int($size) && $size > 0 && $size <= self::MAX_BYTES, 422, 'Choose an image up to 20 MiB.');
        $dimensions = @getimagesize($path);
        abort_unless($dimensions && in_array($dimensions['mime'], self::MIMES, true)
            && $this->withinPixels(dimensions: $dimensions), 422, 'Choose an image up to 33,554,432 pixels.');
        $bytes = file_get_contents($path);
        abort_unless(is_string($bytes), 503, 'Image could not be stored.');

        return $this->reserve(adminId: $adminId, requestId: $requestId, bytes: $bytes);
    }

    private function withinPixels(array $dimensions): bool
    {
        return $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= self::MAX_PIXELS;
    }

    private function reserve(int $adminId, string $requestId, string $bytes, ?int $assetId = null, ?string $format = null): stdClass
    {
        $path = 'vectorizations/input/'.Str::uuid();
        $disk = Storage::disk('private');
        abort_unless($disk->put($path, $bytes), 503, 'Image could not be stored.');
        $keep = false;

        try {
            $row = $this->requests->submit(adminId: $adminId, requestId: $requestId, inputPath: $path,
                sha: hash('sha256', $bytes), assetId: $assetId, format: $format);
            $keep = $row->input_path === $path;

            return $row;
        } finally {
            if (! $keep) {
                $disk->delete($path);
            }
        }
    }
}
