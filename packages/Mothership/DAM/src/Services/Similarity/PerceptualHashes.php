<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Similarity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\LargeRasterPreviewService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\DuplicateLog;
use Throwable;

/**
 * P5c (D16): computes an asset's dHash from its generated preview, never from the original, and records the
 * near-duplicate pairs it forms (dam_asset_similar_pairs).
 *
 * The preview is the one the Library grid already serves:
 *  - a raster image: its `thumbnail` derivative (DerivativeImages), made here when it does not exist yet exactly as
 *    FileController makes it (libvips for an oversized raster, GD otherwise), so the grid reuses it;
 *  - a video, 360 panorama or PDF: its poster, only when one exists (GenerateVideoThumbnail queues a hash once it has
 *    written one);
 *  - a DNG: its extracted thumbnail or preview, when one exists.
 * Anything else, an SVG included, is skipped with a reason.
 *
 * An original is read into PHP only when GD can decode it and it fits the worker's memory (`gdSkipReason`): a
 * 250 MB PSD read whole ran the 512 MB default-queue worker out of memory, an uncatchable fatal that stopped every
 * upload finalization behind it (2026-11-10, asset 3865).
 *
 * A pair is two hashed (not flat) assets within the threshold whose current files are not the same bytes: exact
 * duplicates are P3b-2's notice, not a near-duplicate. Pairs are stored for every asset regardless of who may see
 * them; every reader filters both sides through the access seam.
 *
 * Idempotent: a row for the same bytes and algorithm is kept unless `force`.
 */
class PerceptualHashes
{
    public const STATUS_HASHED = 'hashed';

    public const STATUS_FLAT = 'flat';

    public const STATUS_SKIPPED = 'skipped';

    /** Thumbnail size, as FileController's thumbnail route. */
    private const THUMBNAIL_EDGE = 300;

    private const POSTER_KINDS = ['video'];

    /** Fallback for `dam.similar.thumbnail_max_bytes`. */
    private const THUMBNAIL_MAX_BYTES = 67_108_864;

    /** The header types GD decodes, by the `imagetypes()` bit that says this build has the codec. */
    private const GD_TYPES = [
        IMAGETYPE_GIF  => IMG_GIF,
        IMAGETYPE_JPEG => IMG_JPG,
        IMAGETYPE_PNG  => IMG_PNG,
        IMAGETYPE_WEBP => IMG_WEBP,
        IMAGETYPE_BMP  => IMG_BMP,
        IMAGETYPE_AVIF => IMG_AVIF,
    ];

    public function __construct(
        private ContentStore $store,
        private LargeRasterPreviewService $largeRasters,
    ) {}

    /**
     * Whether a skipped row may succeed on a later try: its preview was missing or failed, not unsuitable. `too_large`
     * and `unsupported` are settled: the same bytes give the same answer, and trying again is what the guard prevents.
     */
    public static function retryable(string $reason): bool
    {
        return in_array($reason, ['no_poster', 'no_preview', 'undecodable'], true) || str_starts_with($reason, 'error_');
    }

    public static function threshold(): int
    {
        return DHash::clampThreshold((int) config('dam.similar.threshold', 4));
    }

    /**
     * Makes sure the asset's hash is the one of its current bytes, and its pairs with it.
     *
     * @return string hashed, flat, skipped or unchanged
     */
    public function ensure(Asset $asset, bool $force = false): string
    {
        try {
            $object = $this->store->current($asset);
        } catch (ContentUnavailable) {
            return $this->record(asset: $asset, sha256: null, result: null, reason: 'no_original');
        }

        $existing = DB::table('dam_asset_phashes')->where('asset_id', $asset->id)->first(['sha256', 'algorithm', 'status', 'reason']);
        // A preview that was missing (a poster not made yet) or failed is looked for again; a decided row is kept.
        $settled = $existing !== null && ($existing->status !== self::STATUS_SKIPPED || ! self::retryable((string) $existing->reason));

        if (! $force && $settled && $existing->sha256 === $object->sha256 && $existing->algorithm === DHash::ALGORITHM) {
            return 'unchanged';
        }

        [$bytes, $reason] = $this->previewBytes(asset: $asset, object: $object);
        $result = $bytes === null ? null : DHash::fromBytes($bytes);

        return $this->record(asset: $asset, sha256: (string) $object->sha256, result: $result, reason: $bytes === null ? $reason : 'undecodable');
    }

    /**
     * Rebuilds the pairs of one hashed asset against every other hashed asset, through the band index.
     *
     * @return int pairs recorded
     */
    public function rebuildPairs(int $assetId): int
    {
        $row = DB::table('dam_asset_phashes')->where('asset_id', $assetId)->first();
        DB::table('dam_asset_similar_pairs')->where('asset_id', $assetId)->orWhere('other_asset_id', $assetId)->delete();

        if ($row === null || $row->status !== self::STATUS_HASHED || $row->dhash === null) {
            return 0;
        }

        $hash = (int) $row->dhash;
        $threshold = self::threshold();
        $radius = DHash::radius($threshold);
        $bands = DHash::bands($hash);
        $rows = [];

        $candidates = DB::table('dam_asset_phashes')
            ->where('status', self::STATUS_HASHED)
            ->where('asset_id', '<>', $assetId)
            ->where(function ($any) use ($bands, $radius): void {
                foreach ($bands as $index => $band) {
                    $any->orWhereIn('band'.$index, DHash::neighbours(band: $band, radius: $radius));
                }
            })
            ->orderBy('asset_id')
            ->select(['asset_id', 'dhash', 'sha256']);

        foreach ($candidates->cursor() as $candidate) {
            $distance = DHash::distance($hash, (int) $candidate->dhash);

            if ($distance > $threshold || ($row->sha256 !== null && $candidate->sha256 === $row->sha256)) {
                continue;
            }

            $low = min($assetId, (int) $candidate->asset_id);
            $high = max($assetId, (int) $candidate->asset_id);
            $rows[] = ['asset_id' => $low, 'other_asset_id' => $high, 'pair_key' => $low.':'.$high, 'distance' => $distance, 'created_at' => now()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('dam_asset_similar_pairs')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /** @param  array{hash: int, flat: bool}|null  $result */
    private function record(Asset $asset, ?string $sha256, ?array $result, string $reason): string
    {
        $status = match (true) {
            $result === null => self::STATUS_SKIPPED,
            $result['flat']  => self::STATUS_FLAT,
            default          => self::STATUS_HASHED,
        };
        $bands = $result === null ? [null, null, null, null] : DHash::bands($result['hash']);

        // The asset row first (ADR 0009), the lock a review group's Stack, Merge and Not duplicates hold on their members while they
        // revalidate: a group action never sees this asset's pairs rewritten between its check and its change.
        $pairs = DB::transaction(function () use ($asset, $sha256, $status, $reason, $result, $bands): int {
            Asset::withTrashed()->whereKey($asset->id)->lockedForGraph()->value('id');

            DB::table('dam_asset_phashes')->upsert([[
                'asset_id'    => (int) $asset->id,
                'sha256'      => $sha256,
                'algorithm'   => DHash::ALGORITHM,
                'status'      => $status,
                'reason'      => $status === self::STATUS_SKIPPED ? $reason : null,
                'dhash'       => $result['hash'] ?? null,
                'band0'       => $bands[0],
                'band1'       => $bands[1],
                'band2'       => $bands[2],
                'band3'       => $bands[3],
                'computed_at' => now(),
            ]], ['asset_id'], ['sha256', 'algorithm', 'status', 'reason', 'dhash', 'band0', 'band1', 'band2', 'band3', 'computed_at']);

            return $this->rebuildPairs((int) $asset->id);
        });

        DuplicateLog::write(event: $status === self::STATUS_SKIPPED ? 'phash.skipped' : 'phash.computed', ctx: [
            'asset_id' => (int) $asset->id,
            'status'   => $status,
            'reason'   => $status === self::STATUS_SKIPPED ? $reason : null,
            'pairs'    => $pairs,
        ]);

        return $status;
    }

    /**
     * The bytes of the asset's generated preview, or null and why there is none.
     *
     * @return array{0: ?string, 1: string}
     */
    private function previewBytes(Asset $asset, FileObject $object): array
    {
        $disk = Storage::disk(Directory::getAssetDisk());
        $extension = strtolower(trim((string) $asset->extension));
        $mime = strtolower(trim(explode(';', (string) ($object->mime_type ?: $asset->mime_type))[0]));

        try {
            if ($extension === 'dng') {
                foreach ([DerivativeImages::dngThumbnail($object->sha256), DerivativeImages::dngPreview($object->sha256)] as $path) {
                    if ($disk->exists($path)) {
                        return [(string) $disk->get($path), ''];
                    }
                }

                return [null, 'no_preview'];
            }

            if (in_array($asset->file_type, self::POSTER_KINDS, true) || $asset->asset_kind === 'pano360' || $extension === 'pdf') {
                $meta = is_array($asset->meta_data) ? $asset->meta_data : [];

                foreach ([$meta['thumbnail_path'] ?? null, DerivativeImages::poster($object->sha256)] as $path) {
                    if (is_string($path) && $path !== '' && $disk->exists($path)) {
                        return [(string) $disk->get($path), ''];
                    }
                }

                return [null, 'no_poster'];
            }

            if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml' || $extension === 'svg') {
                return [null, 'not_raster'];
            }

            return $this->thumbnail(object: $object, extension: $extension);
        } catch (Throwable $error) {
            return [null, mb_substr('error_'.strtolower(class_basename($error)), 0, 32)];
        }
    }

    /**
     * The raster's grid thumbnail, made and cached first when the grid has not asked for it yet; or null and why the
     * original is not read to make one.
     *
     * @return array{0: ?string, 1: string}
     */
    private function thumbnail(FileObject $object, string $extension): array
    {
        $disk = Directory::getAssetDisk();
        $storage = Storage::disk($disk);
        $destination = DerivativeImages::thumbnail($object->sha256, $extension);

        if ($storage->exists($destination)) {
            return [(string) $storage->get($destination), ''];
        }

        $local = $this->store->localPath($object, $extension);

        try {
            if ($this->largeRasters->generateIfNeeded(disk: $disk, sourcePath: (string) $object->path, destinationPath: $destination, maxSize: self::THUMBNAIL_EDGE, localSource: $local->path)) {
                return [(string) $storage->get($destination), ''];
            }

            $reason = self::gdSkipReason($local->path);

            if ($reason !== null) {
                return [null, $reason];
            }

            $bytes = (string) file_get_contents($local->path);
        } finally {
            $local->release();
        }

        $image = (new ImageManager(new Driver))->decode($bytes)->scale(width: self::THUMBNAIL_EDGE);
        $encoded = DerivativeImages::encodeFor($image, $extension);
        $storage->put($destination, $encoded);

        return [$encoded, ''];
    }

    /**
     * Why this original must not be read whole and handed to GD, or null when it may be. Judged by the header and the
     * file size, never by reading the file: the read, Intervention's copy of it and the decoded pixels all count
     * against the worker's memory_limit, and running out is a fatal no `catch` sees.
     *
     *  - `unsupported`: the header names a format this GD build cannot decode (PSD, TIFF, JPEG 2000 ...).
     *  - `too_large`: more pixels than GD is given (libvips takes a JPEG or PNG that large before this is asked;
     *    no other format has that path), or more bytes than `dam.similar.thumbnail_max_bytes`.
     *
     * A header PHP cannot name (HEIC before PHP 8.5) keeps the decode attempt, within the byte cap: its failure is
     * `error_*`, retried once the grid's own thumbnail exists.
     */
    private static function gdSkipReason(string $path): ?string
    {
        $info = @getimagesize($path);
        $type = $info === false ? null : (int) $info[2];

        if ($type !== null && ! (defined('IMAGETYPE_HEIF') && $type === constant('IMAGETYPE_HEIF'))) {
            if ((imagetypes() & (self::GD_TYPES[$type] ?? 0)) === 0) {
                return 'unsupported';
            }

            if ((int) $info[0] * (int) $info[1] >= LargeRasterPreviewService::pixelThreshold()) {
                return 'too_large';
            }
        }

        $size = @filesize($path);
        $cap = max(1, (int) config('dam.similar.thumbnail_max_bytes', self::THUMBNAIL_MAX_BYTES));

        return $size === false || $size > $cap ? 'too_large' : null;
    }
}
