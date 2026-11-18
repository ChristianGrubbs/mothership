<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\PreviewKinds;

/**
 * Stores a browser-rendered 3D thumbnail (ADR 0006).
 *
 * The bytes arrive from a client that rendered the model itself, so nothing is
 * trusted: the upload is re-decoded and re-encoded through GD before it reaches
 * the disk, and it is bound to the sha256 of the asset's current original so a
 * capture of a superseded file cannot land.
 */
class ModelThumbnailService
{
    /** The formats with a client-side renderer; everything else is a 422. */
    public const EXTENSIONS = PreviewKinds::MODEL_VIEWER;

    public const LONGEST_SIDE = 512;

    public const JPEG_QUALITY = 85;

    /** Where every capture lands; a thumbnail_path under it is one of ours. */
    public const DIRECTORY = 'thumbnails/models/';

    public static function supports(Asset $asset): bool
    {
        return in_array(strtolower((string) $asset->extension), self::EXTENSIONS, true);
    }

    /**
     * The sha256 a capture must be bound to: the immutable original blob, which
     * survives a primary-view save (that publishes a `version` role file and
     * leaves `original` alone).
     */
    public static function checksum(Asset $asset): ?string
    {
        $sha = self::originalChecksumQuery()
            ->where('dam_asset_files.dam_asset_id', $asset->id)
            ->value('dam_file_objects.sha256');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /**
     * The same checksum as a correlated subquery, so a list endpoint or an
     * explorer page resolves every row in one round trip instead of one query
     * per card.
     */
    public static function checksumSubquery(string $assetColumn = 'dam_assets.id'): Builder
    {
        return self::originalChecksumQuery()
            ->whereColumn('dam_asset_files.dam_asset_id', $assetColumn)
            ->select('dam_file_objects.sha256')
            ->limit(1);
    }

    private static function originalChecksumQuery(): Builder
    {
        return AssetFile::query()
            ->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->where('dam_asset_files.role', AssetFile::ROLE_ORIGINAL)
            ->orderBy('dam_asset_files.id');
    }

    public static function generatedPath(Asset $asset, string $checksum): string
    {
        return self::DIRECTORY.$asset->id.'-'.substr($checksum, 0, 12).'.jpg';
    }

    public static function hasThumbnail(Asset $asset): bool
    {
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];

        return ! empty($meta['thumbnail_path']) || ! empty($meta['custom_thumbnail']) || ! empty($meta['stack_cover']['path']);
    }

    /**
     * Whether the viewer has nothing left to capture: the card has a picture AND the AI has a picture of the current
     * bytes (AiEligibility's own rule: a decoded capture bound to the sha, never a custom or stale card thumbnail). A
     * custom thumbnail therefore never stands in for the capture the AI describes.
     */
    public static function hasCapture(Asset $asset): bool
    {
        return self::hasThumbnail($asset) && AiEligibility::storedPicture($asset, self::checksum($asset)) !== null;
    }

    /**
     * The image a preview card shows under its 3D call to action, or null while
     * the model has no thumbnail, so the card keeps its text-only face instead
     * of the placeholder the thumbnail route answers 200 with.
     *
     * Built here rather than in the browser for two reasons. `v` names the
     * content revision (a custom thumbnail's revision, else the stored
     * capture's), so neither a placeholder cached for a day under a bare
     * `?path=` nor a capture that a forced re-capture overwrote in place is
     * reused on the next load. And the path goes through urlencode() inside
     * route(), the form FileController::thumbnail() decodes, so a `+` or `%`
     * in a file name survives the round trip.
     */
    public static function cardUrl(Asset $asset): ?string
    {
        if (! self::supports($asset) || ! $asset->path || ! self::hasThumbnail($asset)) {
            return null;
        }

        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $custom = $meta['custom_thumbnail'] ?? null;
        $generated = $meta['thumbnail_path'] ?? null;

        // A capture stored before thumbnail_revision existed falls back to its
        // `{assetId}-{sha12}` file name, which still tells it from the placeholder.
        $revision = match (true) {
            is_array($custom) && ! empty($custom['revision'])    => (string) $custom['revision'],
            ! empty($meta['stack_cover']['path'])                => substr(hash('sha256', $meta['stack_cover']['path']), 0, 12),
            ! empty($meta['thumbnail_revision'])                 => (string) $meta['thumbnail_revision'],
            is_string($generated) && $generated !== ''           => pathinfo($generated, PATHINFO_FILENAME),
            default                                              => null,
        };

        return route('admin.dam.file.thumbnail', array_filter([
            'path' => urlencode($asset->path),
            'v'    => $revision,
        ]));
    }

    /**
     * `captured` says a capture of these bytes was written for the AI; beside a custom thumbnail `stored` stays false.
     *
     * @return array{stored: bool, skipped: ?string, thumbnail_path: ?string, revision: ?string, captured: bool}
     */
    public function store(Asset $asset, UploadedFile $upload, string $checksum, bool $replace = false): array
    {
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];

        // A person who chose a thumbnail outranks anything generated, so the card keeps their picture. The AI still
        // needs a picture of the current bytes, so the render is kept beside it, bound to the sha, and the card meta
        // is left alone. Answering 200 rather than 409 keeps a backfill run moving.
        if (CustomThumbnailService::location($asset) !== null || ! empty($meta['custom_thumbnail']) || StackMemberState::hasCustomCover($meta)) {
            return $this->besideCustom(asset: $asset, upload: $upload, checksum: $checksum, replace: $replace);
        }

        $disk = Directory::getAssetDisk();
        $path = self::generatedPath($asset, $checksum);
        $existing = $meta['thumbnail_path'] ?? null;

        // Only this very capture of these bytes, and only while it is a usable picture, counts as existing: another or
        // older card thumbnail, and a zero-byte, truncated or undecodable file at the current path, are replaced.
        if (! $replace && $existing === $path && ModelFileThumbnailService::isUsable($existing)) {
            return ['stored' => false, 'skipped' => 'exists', 'thumbnail_path' => $existing, 'revision' => substr($checksum, 0, 12), 'captured' => false];
        }

        $jpeg = $this->encode($upload);
        $revision = substr(hash('sha256', $jpeg), 0, 12);

        abort_unless(Storage::disk($disk)->put($path, $jpeg), 500, 'Could not save the model thumbnail.');

        // GenerateVideoThumbnail's idiom: other writers touch meta_data while a
        // capture is in flight, so merge into a freshly locked row rather than
        // saving the copy this request read, and stay out of the audit trail.
        // A forced re-capture reuses $path, so the revision is what cardUrl()
        // names the new bytes by.
        $customAppeared = false;

        DB::transaction(function () use ($asset, $path, $revision, &$customAppeared): void {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $current = is_array($locked->meta_data) ? $locked->meta_data : [];

            if (! empty($current['custom_thumbnail']) || StackMemberState::hasCustomCover($current)) {
                $customAppeared = true;

                return;
            }

            unset($current['stack_cover']); // An explicit new capture replaces the borrowed member picture.
            $locked->meta_data = array_merge($current, ['thumbnail_path' => $path, 'thumbnail_revision' => $revision]);
            $locked->saveQuietly();
        });

        // A custom thumbnail was chosen while this capture was in flight: keep the render for the AI, not as the card.
        if ($customAppeared) {
            return $this->besideCustom(asset: $asset, upload: $upload, checksum: $checksum, replace: $replace);
        }

        // The AI looks at this picture; an index run that found no image for the file starts over once.
        AiIndexDispatcher::derivativeReady((int) $asset->id);

        return [
            'stored'         => true,
            'skipped'        => null,
            'thumbnail_path' => $path,
            'revision'       => $revision,
            'captured'       => true,
        ];
    }

    /**
     * The sha-bound capture the AI reads (ModelFileThumbnailService, ADR 0006 per file), written for an asset whose card
     * shows a custom thumbnail. Only for the asset's current original, only for those exact bytes, and never into the
     * asset row, so the chosen picture stays the card and the history is never copied.
     *
     * @return array{stored: bool, skipped: ?string, thumbnail_path: ?string, revision: ?string, captured: bool}
     */
    private function besideCustom(Asset $asset, UploadedFile $upload, string $checksum, bool $replace): array
    {
        $object = AssetFile::query()->where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)
            ->with('fileObject')->orderBy('id')->first()?->fileObject;
        $captured = $object !== null && hash_equals(strtolower((string) $object->sha256), strtolower($checksum))
            && app(ModelFileThumbnailService::class)->store(object: $object, upload: $upload, assetId: (int) $asset->id, replace: $replace)['stored'];

        return ['stored' => false, 'skipped' => 'custom', 'thumbnail_path' => null, 'revision' => null, 'captured' => $captured];
    }

    /**
     * Re-decode through GD and re-encode as a bounded JPEG. An upload that GD
     * refuses never reaches the asset disk, and a PNG capture never ships to a
     * grid as a multi-megabyte card image. Public so a stacked file's capture
     * (ModelFileThumbnailService) is bounded by the very same rule.
     */
    public function encode(UploadedFile $upload): string
    {
        $image = (new ImageManager(new Driver))->decode(file_get_contents($upload->getRealPath()));

        if (max($image->width(), $image->height()) > self::LONGEST_SIDE) {
            $image = $image->width() >= $image->height()
                ? $image->scale(width: self::LONGEST_SIDE)
                : $image->scale(height: self::LONGEST_SIDE);
        }

        return (string) $image->encode(new JpegEncoder(quality: self::JPEG_QUALITY));
    }
}
