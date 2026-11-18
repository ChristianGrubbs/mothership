<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Console\Commands\AiIndexReconcile;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PreviewKinds;

/**
 * Whether the AI can describe one file, and where that stands. The one rule list MediaSampler also reads, so the
 * pictures the sampler uses and the files the UI offers AI actions for cannot drift apart.
 *
 * A stack is its cover: the asset row, and so everything below, describes the current original (AttachmentLedger::promote
 * re-describes the asset when another file becomes the cover). A stacked file is not an asset and is never described
 * on its own, so it neither makes a stack eligible nor blocks it; `scope` says which one the answer is about.
 *
 * Source rules, in order (a file needs one picture to look at):
 *   1. JPG, PNG, GIF, WebP, BMP, any video, PDF: sampled from the original                                -> eligible
 *   2. A derivative of the current bytes that exists and decodes (provenance is the sha, never a stored path):
 *      DNG preview/thumbnail, 3D capture (or the asset's own capture of those bytes), Blender/Unity poster,
 *      the grid thumbnail of another image format (HEIC, TIFF, PSD, AVIF ...)                              -> eligible
 *   3. Such a derivative is still to come: DNG, GLB/3MF/STL, Blender/Unity package whose poster was read but is
 *      gone or not read yet, other image formats without a grid thumbnail yet                              -> awaiting_preview
 *   4. Anything else (SVG, audio, ZIP/web, text, other binaries, Blender/Unity with no embedded thumbnail)   -> unsupported
 * A caption made from these very bytes comes before all of that: it settles the file as eligible without looking at (or
 * decoding) any picture, so a poster since deleted does not take it away. Otherwise, for a file that has a source, the
 * index row decides: queued or running while its job is in the queue (AiIndexStore::liveness; a pending row with no
 * token has no job and is not "queued"), stalled when the job is gone, failed, or a `no_image` run on these very bytes
 * (one manual retry per content, `can_retry`); then whether the AI service answers (null = not known yet: neither
 * down nor claimed up).
 */
final class AiEligibility
{
    public const ELIGIBLE = 'eligible';

    public const AWAITING_PREVIEW = 'awaiting_preview';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public const FAILED = 'failed';

    public const UNSUPPORTED = 'unsupported';

    /** Written on a `done` row whose run found no picture. */
    public const NO_IMAGE = 'no_image';

    public const RASTER = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct(private AiBridgeClient $bridge) {}

    /** Whether the original itself is sampled (rule 1). */
    public static function sampledDirectly(Asset $asset): bool
    {
        $extension = strtolower((string) $asset->extension);

        return in_array($extension, self::RASTER, true) || $asset->file_type === 'video' || $extension === 'pdf';
    }

    /** An image format the grid makes a thumbnail of (HEIC, TIFF, PSD, AVIF ...) that the sampler cannot open itself. */
    private static function viaGridThumbnail(Asset $asset): bool
    {
        $extension = strtolower((string) $asset->extension);

        return $asset->file_type === 'image' && $extension !== 'svg' && ! in_array($extension, self::RASTER, true);
    }

    /** Paths a picture of these bytes may be at, best first. Keyed by the sha, so a placeholder or another file's art never appears. @return list<string> */
    private static function candidates(Asset $asset, ?string $sha256): array
    {
        if ($sha256 === null || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            return [];
        }

        $extension = strtolower((string) $asset->extension);

        if ($extension === 'dng') {
            return [DerivativeImages::dngPreview($sha256), DerivativeImages::dngThumbnail($sha256)];
        }

        if (in_array($extension, PreviewKinds::MODEL_VIEWER, true)) {
            $paths = [ModelFileThumbnailService::path($sha256, (int) $asset->id)];
            $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
            $original = ModelThumbnailService::checksum($asset);

            // The asset's own capture is named for its id and the original's bytes; any other thumbnail_path is not one.
            if ($original !== null && ($meta['thumbnail_path'] ?? null) === ModelThumbnailService::generatedPath($asset, $original)) {
                $paths[] = $meta['thumbnail_path'];
            }

            return array_values(array_filter($paths));
        }

        if (PreviewKinds::hasEmbeddedThumbnail($extension)) {
            return [DerivativeImages::poster($sha256)];
        }

        return self::viaGridThumbnail($asset) ? [DerivativeImages::thumbnail($sha256, $extension)] : [];
    }

    /** A derivative this large is not one of ours (they are made at 1920 px at most): it is never read into memory. */
    public const MAX_PICTURE_BYTES = 25_000_000;

    /**
     * The stored picture a sampler may read (rule 2), as an asset-disk path, or null. It exists, is within the byte cap
     * and decodes. A decoded verdict is kept for a day per version of that file (its content sha, path, size and time),
     * so a list of 500 files does not read and decode 500 pictures each time it is asked, and a derivative that is
     * rewritten is a new version that is judged again. Only a yes is kept, and a hit does not renew it. The byte cap is
     * applied on every call, hit or not.
     */
    public static function storedPicture(Asset $asset, ?string $sha256): ?string
    {
        $disk = Storage::disk(Directory::getAssetDisk());

        foreach (self::candidates($asset, $sha256) as $path) {
            if (! $disk->exists($path) || $disk->size($path) > self::MAX_PICTURE_BYTES) {
                continue;
            }

            $verdict = 'dam.ai-eligibility.decodes.'.$sha256.'.'.md5($path.'|'.$disk->size($path).'|'.$disk->lastModified($path));

            if (Cache::get($verdict) === true) {
                return $path;
            }

            if (self::decodes((string) $disk->get($path))) {
                Cache::put($verdict, true, 86400);

                return $path;
            }
        }

        return null;
    }

    /**
     * Whether these bytes are small enough for the sampler to open: within the byte cap and the decoder's pixel budget
     * (gd_max_pixels), judged by the header. Applied where a picture is decoded (here, and by MediaSampler where it reads
     * the file), so no path decodes something a cached verdict once let by.
     */
    public static function withinBounds(string $bytes): bool
    {
        $size = $bytes === '' || strlen($bytes) > self::MAX_PICTURE_BYTES ? false : @getimagesizefromstring($bytes);

        return $size !== false && (int) $size[0] * (int) $size[1] <= (int) config('ai-index.gd_max_pixels');
    }

    /**
     * Whether these bytes are a whole picture of a size the sampler may open. Its header being readable is not enough (a
     * truncated PNG has one): a JPEG must end where JPEGs end, and the picture is decoded for real. A picture beyond the
     * bounds is not accepted at all, never judged by its header: the derivatives the AI reads are made small (a DNG
     * preview is 1920 px), so a huge one is not a bounded derivative.
     */
    private static function decodes(string $bytes): bool
    {
        if (! self::withinBounds($bytes)) {
            return false;
        }

        // A cut-off JPEG still opens, as a picture of grey: its missing end marker gives it away.
        if ((@getimagesizefromstring($bytes))[2] === IMAGETYPE_JPEG && ! str_contains(substr($bytes, -32), "\xFF\xD9")) {
            return false;
        }

        return @imagecreatefromstring($bytes) !== false;
    }

    /** Rules 1 to 4 for one file: `eligible`, `awaiting_preview` or `unsupported`, with a reason key. @return array{state: string, reason: string, path?: string} */
    public static function source(Asset $asset, ?string $sha256 = null): array
    {
        $extension = strtolower((string) $asset->extension);

        if (self::sampledDirectly($asset)) {
            return ['state' => self::ELIGIBLE, 'reason' => 'original'];
        }

        $sha256 ??= self::currentSha($asset);

        if (($path = self::storedPicture($asset, $sha256)) !== null) {
            return ['state' => self::ELIGIBLE, 'reason' => 'preview', 'path' => $path];
        }

        if ($extension === 'dng') {
            return ['state' => self::AWAITING_PREVIEW, 'reason' => 'dng_preview'];
        }

        if (in_array($extension, PreviewKinds::MODEL_VIEWER, true)) {
            return ['state' => self::AWAITING_PREVIEW, 'reason' => 'model_capture'];
        }

        if (PreviewKinds::hasEmbeddedThumbnail($extension)) {
            // The job records what it read with the sha. Read, and it says the file has a thumbnail: the poster is
            // gone and is made again. Read, and it has none: nothing to describe.
            $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
            $facts = is_array($meta['game_asset'] ?? null) ? $meta['game_asset'] : null;

            return match (true) {
                $facts === null || ($facts['sha256'] ?? null) !== $sha256 => ['state' => self::AWAITING_PREVIEW, 'reason' => 'embedded_thumbnail'],
                ($facts['has_thumbnail'] ?? false) === true               => ['state' => self::AWAITING_PREVIEW, 'reason' => 'poster_missing'],
                default                                                   => ['state' => self::UNSUPPORTED, 'reason' => 'no_embedded_thumbnail'],
            };
        }

        if (self::viaGridThumbnail($asset)) {
            return ['state' => self::AWAITING_PREVIEW, 'reason' => 'thumbnail'];
        }

        return ['state' => self::UNSUPPORTED, 'reason' => 'file_type'];
    }

    /**
     * Cheap, storage-free check for list payloads: false only for a file whose type can never be described. A type that
     * may be described once its preview exists, and any other image format (its grid thumbnail may exist), is true: the
     * authoritative answer is the eligibility route, which looks at the file.
     */
    public static function typeMayBeDescribed(?string $extension, ?string $fileType): bool
    {
        $extension = strtolower((string) $extension);

        return in_array($extension, self::RASTER, true) || $fileType === 'video' || $extension === 'pdf' || $extension === 'dng'
            || in_array($extension, PreviewKinds::MODEL_VIEWER, true) || PreviewKinds::hasEmbeddedThumbnail($extension)
            || ($fileType === 'image' && $extension !== 'svg');
    }

    /** @return array<string, mixed> */
    public function forAsset(Asset $asset): array
    {
        $row = DB::table('dam_ai_index')->where('asset_id', $asset->id)->first();

        return $this->evaluate(asset: $asset, row: $row, reachable: $this->bridge->health());
    }

    /**
     * @param  iterable<Asset>  $assets
     * @return array<int, array<string, mixed>>
     */
    public function forAssets(iterable $assets): array
    {
        $assets = collect($assets)->keyBy('id');
        $ids = $assets->keys()->all();
        $rows = DB::table('dam_ai_index')->whereIn('asset_id', $ids)->get()->keyBy('asset_id');
        $stacks = Attachment::query()->whereIn('dam_asset_id', $ids)->selectRaw('dam_asset_id, count(*) as slots')->groupBy('dam_asset_id')->pluck('slots', 'dam_asset_id');
        $reachable = $this->bridge->health();

        return $assets->map(fn (Asset $asset) => $this->evaluate(asset: $asset, row: $rows->get($asset->id), reachable: $reachable, isStack: (int) ($stacks[$asset->id] ?? 0) > 1))->all();
    }

    /** @return array<string, mixed> */
    public function evaluate(Asset $asset, ?object $row, ?bool $reachable, ?bool $isStack = null): array
    {
        $isStack ??= Attachment::query()->where('dam_asset_id', $asset->id)->count() > 1;

        return self::result(...$this->decide(asset: $asset, row: $row, reachable: $reachable)) + ['scope' => $isStack ? 'stack_cover' : 'file'];
    }

    /**
     * @param  bool|null  $reachable  the AI service answers; null when that is not known yet (the first probe is still out): not
     *                                taken as down, and not claimed as up
     * @return array{state: string, reason?: ?string, canGenerate?: bool, canRename?: bool, canRetry?: bool, willRetry?: bool}
     */
    private function decide(Asset $asset, ?object $row, ?bool $reachable): array
    {
        $up = $reachable !== false;
        $sha = self::currentSha($asset);

        // A description of these very bytes settles it, whatever has become of the picture it was made from (a poster
        // since deleted): nothing is read or decoded for it.
        if ($row && filled($row->caption) && $sha !== null && ($row->content_sha256 ?? null) === $sha) {
            return ['state' => self::ELIGIBLE, 'reason' => 'described', 'canRename' => $up];
        }

        $source = self::source($asset, $sha);

        if ($source['state'] !== self::ELIGIBLE) {
            return ['state' => $source['state'], 'reason' => $source['reason']];
        }

        if ($row && filled($row->caption)) {
            return ['state' => self::ELIGIBLE, 'reason' => 'described', 'canRename' => $up];
        }

        $status = $row->status ?? null;

        switch ($row ? AiIndexStore::liveness($row) : AiIndexStore::LIVE_IDLE) {
            case AiIndexStore::LIVE_RUNNING:
                return ['state' => self::RUNNING, 'canRename' => true];
            case AiIndexStore::LIVE_QUEUED:
                // Waiting for its next try because the AI service did not answer the last one: say that, not "being created".
                if ($status === 'pending' && ($row->error ?? null) === class_basename(AiBridgeUnavailable::class)) {
                    return ['state' => self::SERVICE_UNAVAILABLE, 'reason' => 'retrying', 'willRetry' => true];
                }

                return ['state' => self::QUEUED, 'canRename' => true];
            case AiIndexStore::LIVE_LOST:
                // Its job is gone (worker lost, queue flushed): it can be asked for again, and the nightly reconcile will.
                return ['state' => self::FAILED, 'reason' => 'stalled', 'canGenerate' => $up, 'canRename' => $up, 'willRetry' => self::reconcileWillRetry(attempts: (int) ($row->attempts ?? 0))];
        }

        // A run on these very bytes found no picture, and nothing newer has appeared since: asking again says the same.
        // One manual try per content is allowed (`can_retry`), for a picture the checks above could not see.
        if ($status === 'done' && ($row->error ?? null) === self::NO_IMAGE && $sha !== null && $row->content_sha256 === $sha) {
            $newer = isset($source['path']) && $row->indexed_at !== null
                && Storage::disk(Directory::getAssetDisk())->lastModified($source['path']) >= strtotime((string) $row->indexed_at);

            if (! $newer) {
                return ['state' => self::FAILED, 'reason' => self::NO_IMAGE, 'canRetry' => $up && ! app(AiIndexStore::class)->retrySpent((int) $asset->id, $sha)];
            }
        }

        if ($reachable === false) {
            return ['state' => self::SERVICE_UNAVAILABLE, 'reason' => filled(config('ai-index.api')) ? 'unreachable' : 'not_configured'];
        }

        if ($status === 'failed') {
            return [
                'state'       => self::FAILED,
                'reason'      => ($row->error ?? null) === class_basename(AiBridgeUnavailable::class) ? 'unreachable' : 'failed',
                'canGenerate' => true,
                'canRename'   => true,
                'willRetry'   => self::reconcileWillRetry(attempts: (int) ($row->attempts ?? 0)),
            ];
        }

        return ['state' => self::ELIGIBLE, 'reason' => $source['reason'], 'canGenerate' => true, 'canRename' => true];
    }

    /** The nightly reconcile re-queues a failed row until it has spent this many tries, and only while indexing is on. */
    public static function reconcileWillRetry(int $attempts): bool
    {
        return (bool) config('ai-index.index_enabled') && $attempts < AiIndexReconcile::MAX_ATTEMPTS;
    }

    private static function currentSha(Asset $asset): ?string
    {
        try {
            return app(ContentStore::class)->current($asset)->sha256;
        } catch (ContentUnavailable) {
            return null;
        }
    }

    /** @return array{state: string, reason: ?string, message: ?string, can_generate: bool, can_rename: bool, can_retry: bool, will_retry: bool} */
    private static function result(string $state, ?string $reason = null, bool $canGenerate = false, bool $canRename = false, bool $canRetry = false, bool $willRetry = false): array
    {
        $key = match (true) {
            $state === self::AWAITING_PREVIEW && $reason === 'model_capture'     => 'awaiting-model',
            $state === self::SERVICE_UNAVAILABLE && $reason === 'not_configured' => 'not-configured',
            $state === self::SERVICE_UNAVAILABLE && $reason === 'retrying'       => 'service-retrying',
            $state === self::SERVICE_UNAVAILABLE                                 => 'service-unavailable',
            $state === self::FAILED && $reason === self::NO_IMAGE                => 'no-image',
            $state === self::FAILED && $reason === 'unreachable'                 => $willRetry ? 'unreachable-retry' : 'unreachable',
            $state === self::FAILED                                              => $willRetry ? 'failed-retry' : 'failed',
            $state === self::AWAITING_PREVIEW                                    => 'awaiting',
            $state === self::QUEUED, $state === self::RUNNING                    => 'working',
            $state === self::UNSUPPORTED                                         => 'unsupported',
            default                                                              => null,
        };

        return [
            'state'        => $state,
            'reason'       => $reason,
            'message'      => $key !== null ? trans('dam::app.admin.dam.asset.edit.ai.state.'.$key) : null,
            'can_generate' => $canGenerate,
            'can_rename'   => $canRename,
            'can_retry'    => $canRetry,
            'will_retry'   => $willRetry,
        ];
    }
}
