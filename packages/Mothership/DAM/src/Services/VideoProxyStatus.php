<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Support\VideoLog;

/**
 * The 1080p preview proxy's state as people see it (P6 D26), and the Retry that re-queues it.
 *
 * The job writes its rendition row only once a worker picks it up, so a queued retry has no row yet: Retry leaves a
 * marker in the cache naming the newest proxy rendition at that moment, and the asset reads as queued until a newer
 * row appears or the marker lapses. A retry while one is queued or running queues nothing more, and the job's own
 * admission (under the asset row lock) still refuses a second live attempt whatever the marker says.
 */
class VideoProxyStatus
{
    public const READY = 'ready';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    /** A proxy the asset needs and has never had, or whose file is gone. */
    public const MISSING = 'missing';

    public const NOT_NEEDED = 'not_needed';

    /** The download variant that hands out the published proxy as a web MP4 (`?variant=web_mp4`). */
    public const WEB_MP4_VARIANT = 'web_mp4';

    /** How long a queued retry reads as queued without its worker starting; afterwards Retry may be asked again. */
    public const QUEUED_FOR_SECONDS = 1800;

    /** Error codes the job records, by the short reason people see. Anything else is the generic one. */
    private const REASONS = [
        'ffmpeg_failed'         => 'ffmpeg-failed',
        'worker_timeout'        => 'timed-out',
        'stale_running'         => 'timed-out',
        'source_stage_failed'   => 'source-unreadable',
        'dimensions_unknown'    => 'output-rejected',
        'proxy_too_large'       => 'output-rejected',
        'codec_rejected'        => 'output-rejected',
        'pixel_format_rejected' => 'output-rejected',
        'audio_rejected'        => 'output-rejected',
        'container_rejected'    => 'output-rejected',
    ];

    public function __construct(protected VideoPreviewProfile $profile) {}

    /**
     * @return array{status: string, label: string, reason: ?string, error: ?string, retryable: bool, updated_at: ?string}
     */
    public function for(Asset $asset): array
    {
        if ($asset->file_type !== 'video' || $asset->panoramaClassification()['is_panorama']) {
            return $this->payload(status: self::NOT_NEEDED);
        }

        if ($asset->publishedWebProxy() !== null) {
            return $this->payload(status: self::READY);
        }

        $latest = $asset->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->fromCurrentContent((int) $asset->id)
            ->orderByDesc('id')
            ->first();

        if ($latest !== null && $latest->status === Rendition::STATUS_RUNNING) {
            return $this->isLive($latest)
                ? $this->payload(status: self::PROCESSING, updatedAt: $latest->started_at)
                : $this->payload(status: self::FAILED, error: 'stale_running', updatedAt: $latest->started_at);
        }

        if ($this->queuedMarker((int) $asset->id) !== null) {
            return $this->payload(status: self::QUEUED);
        }

        if ($latest !== null && in_array($latest->status, [Rendition::STATUS_FAILED, Rendition::STATUS_CANCELLED], true)) {
            return $this->payload(status: self::FAILED, error: (string) ($latest->error ?: 'transcode_failed'), updatedAt: $latest->finished_at ?? $latest->updated_at);
        }

        // A finished proxy whose file is gone, or none yet for a video that needs one.
        if ($latest !== null || $this->profile->needsProxy($asset)) {
            return $this->payload(status: self::MISSING);
        }

        return $this->payload(status: self::NOT_NEEDED);
    }

    /**
     * Re-queues the proxy job when the status allows it: failed or missing, with generation on. Idempotent: queued or
     * processing answers `already_queued` and queues nothing.
     *
     * @return array{outcome: string, status: array}
     */
    public function retry(Asset $asset, ?int $requestedBy): array
    {
        $assetId = (int) $asset->id;

        if (! config('mothership.video_preview.enabled')) {
            return $this->outcome(outcome: 'disabled', asset: $asset, requestedBy: $requestedBy);
        }

        // Under the asset row lock, so two Retry clicks at once queue one job: the second reads the first's marker.
        $decision = DB::transaction(function () use ($assetId): array {
            $locked = Asset::query()->where('id', $assetId)->lockForUpdate()->first();

            if ($locked === null) {
                return ['outcome' => 'missing_asset', 'force' => false];
            }

            $status = $this->for($locked)['status'];

            if (in_array($status, [self::QUEUED, self::PROCESSING], true)) {
                return ['outcome' => 'already_queued', 'force' => false];
            }

            if (! in_array($status, [self::FAILED, self::MISSING], true)) {
                return ['outcome' => 'not_retryable', 'force' => false];
            }

            $newestId = (int) Rendition::query()->where('dam_asset_id', $assetId)->where('recipe', Rendition::RECIPE_PREVIEW_1080P)->max('id');
            Cache::put(self::markerKey($assetId), ['after_id' => $newestId, 'at' => now()->toIso8601String()], self::QUEUED_FOR_SECONDS);

            // A finished row whose file is gone would stop an unforced job at admission; force lets it run again.
            $force = $locked->renditions()->where('recipe', Rendition::RECIPE_PREVIEW_1080P)->fromCurrentContent($assetId)
                ->where('status', Rendition::STATUS_DONE)->exists();

            return ['outcome' => 'queued', 'force' => $force];
        });

        if ($decision['outcome'] === 'queued') {
            GenerateVideoPreviewProxy::dispatch($assetId, $decision['force']);
        }

        return $this->outcome(outcome: $decision['outcome'], asset: $asset->fresh() ?? $asset, requestedBy: $requestedBy, force: $decision['force']);
    }

    /**
     * The Library card's badge for each video on a page: only the states worth a glance (queued, processing, failed),
     * in one query. It reads the newest proxy row per asset and the queued markers, without the per-file checks the
     * asset page makes.
     *
     * @param  list<int>  $videoAssetIds
     * @return array<int, string> asset id => status
     */
    public function badges(array $videoAssetIds): array
    {
        if ($videoAssetIds === []) {
            return [];
        }

        $latest = Rendition::query()
            ->whereIn('dam_asset_id', $videoAssetIds)
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->whereIn('id', Rendition::query()->selectRaw('MAX(id)')->whereIn('dam_asset_id', $videoAssetIds)
                ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)->groupBy('dam_asset_id'))
            ->get(['id', 'dam_asset_id', 'status', 'started_at'])
            ->keyBy('dam_asset_id');

        $markers = Cache::many(array_map(fn (int $id) => self::markerKey($id), $videoAssetIds));
        $badges = [];

        foreach ($videoAssetIds as $assetId) {
            $row = $latest->get($assetId);
            $marker = $markers[self::markerKey($assetId)] ?? null;

            $status = match (true) {
                $row !== null && $row->status === Rendition::STATUS_RUNNING                     => $this->isLive($row) ? self::PROCESSING : self::FAILED,
                is_array($marker) && (int) ($row?->id ?? 0) <= (int) ($marker['after_id'] ?? 0) => self::QUEUED,
                $row !== null && $row->status === Rendition::STATUS_FAILED                      => self::FAILED,
                default                                                                         => null,
            };

            if ($status !== null) {
                $badges[$assetId] = $status;
            }
        }

        return $badges;
    }

    public static function label(string $status): string
    {
        return trans('dam::app.admin.dam.asset.video-proxy.statuses.'.$status);
    }

    /** The Library card's words for a badge status: queued, processing or failed. */
    public static function badgeLabel(string $status): string
    {
        return trans('dam::app.admin.dam.asset.video-proxy.badges.'.$status);
    }

    /**
     * The published proxy as a "Web MP4 (1080p)" download: its bytes and the name it downloads under, or null when the
     * asset has none (the option is not offered then, and the variant answers 404).
     *
     * @return array{object: FileObject, filename: string}|null
     */
    public static function webMp4(Asset $asset): ?array
    {
        $object = $asset->file_type === 'video' ? $asset->publishedWebProxy()?->fileObject : null;

        if ($object === null) {
            return null;
        }

        $baseName = pathinfo((string) $asset->file_name, PATHINFO_FILENAME) ?: 'video';

        return ['object' => $object, 'filename' => $baseName.'-1080p.mp4'];
    }

    public static function markerKey(int $assetId): string
    {
        return 'dam:video-proxy:queued:'.$assetId;
    }

    /** The marker of a retry still waiting for its worker: none once a proxy row newer than the one it names exists. */
    protected function queuedMarker(int $assetId): ?array
    {
        $marker = Cache::get(self::markerKey($assetId));

        if (! is_array($marker)) {
            return null;
        }

        $newer = Rendition::query()->where('dam_asset_id', $assetId)->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('id', '>', (int) ($marker['after_id'] ?? 0))->exists();

        return $newer ? null : $marker;
    }

    /** A running row a worker may still own: started inside the window the job's own stale reclaim uses. */
    protected function isLive(Rendition $rendition): bool
    {
        return $rendition->started_at === null
            || $rendition->started_at->gte(now()->subSeconds(GenerateVideoPreviewProxy::STALE_RUNNING_AFTER));
    }

    protected function payload(string $status, ?string $error = null, mixed $updatedAt = null): array
    {
        $reason = $status === self::FAILED
            ? trans('dam::app.admin.dam.asset.video-proxy.reasons.'.(self::REASONS[$error] ?? 'transcode-failed'))
            : null;

        return [
            'status'     => $status,
            'label'      => self::label($status),
            'reason'     => $reason,
            'error'      => $status === self::FAILED ? $error : null,
            'retryable'  => in_array($status, [self::FAILED, self::MISSING], true) && (bool) config('mothership.video_preview.enabled'),
            'updated_at' => $updatedAt instanceof Carbon ? $updatedAt->toIso8601String() : null,
        ];
    }

    protected function outcome(string $outcome, Asset $asset, ?int $requestedBy, bool $force = false): array
    {
        VideoLog::write(event: 'proxy.retry_'.$outcome, ctx: ['asset_id' => (int) $asset->id, 'by' => (int) $requestedBy, 'force' => $force]);

        return ['outcome' => $outcome, 'status' => $this->for($asset)];
    }
}
