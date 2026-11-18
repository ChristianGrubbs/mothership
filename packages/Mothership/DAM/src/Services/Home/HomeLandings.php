<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

/**
 * The arrivals board: saved files grouped into landings, plus the uploads still on their way and the ones that failed.
 * Saves carry no session id, so a landing is the files from one producer and person that arrive close together.
 */
class HomeLandings
{
    public const STATUS_LANDED = 'landed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_SEEN = 'seen';

    public const STATUS_STOWING = 'stowing';

    public const STATUS_FAILED = 'failed';

    private const DOCKED_HOURS = 24;

    private const IN_FLIGHT_UPLOADS = [ChunkUpload::STATE_RECEIVING, ChunkUpload::STATE_QUEUED, ChunkUpload::STATE_ASSEMBLING];

    private const IN_FLIGHT_TRACKERS = [UploadTracker::STATE_PENDING, UploadTracker::STATE_PROCESSING, UploadTracker::STATE_PAUSED];

    public function __construct(private readonly AssetAccessDecision $access) {}

    /**
     * The files this person may list. Every count on Home starts here, so Home never reveals a hidden file.
     *
     * @return Builder<Asset>
     */
    public function visibleAssets(): Builder
    {
        $query = Asset::query()->visibleCaptures();
        $this->access->scopeListable(query: $query, context: AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, cargo: list<array<string, mixed>>, docked: list<array<string, mixed>>, more: int, truncated: bool, holding: array{available: false, reason: string}}
     */
    public function board(HomeSummaryContext $context): array
    {
        $scanLimit = (int) config('dam-home.summary.landing_scan_limit');
        $files = $this->visibleAssets()
            ->whereNotNull('dam_assets.created_at')
            ->orderByDesc('dam_assets.created_at')
            ->orderByDesc('dam_assets.id')
            ->limit($scanLimit + 1)
            ->toBase()
            ->get(['dam_assets.id', 'dam_assets.created_at', 'dam_assets.created_by', 'dam_assets.file_size']);

        $scanned = $files->take($scanLimit);
        $producers = $this->producers($scanned->map(fn (object $file): int => (int) $file->id)->all());
        $rows = [
            ...$this->landings(files: $scanned, producers: $producers, context: $context),
            ...$this->stowing($context),
            ...$this->failed($context),
        ];
        usort($rows, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $boardRows = (int) config('dam-home.summary.board_rows');
        $shown = array_slice($rows, 0, $boardRows);
        $cargo = array_slice(
            array_values(array_filter($rows, fn (array $row): bool => ($row['asset_ids'] ?? []) !== [])),
            0,
            (int) config('dam-home.summary.cargo_rows'),
        );
        $details = $this->assetDetails(assetIds: array_merge(...array_map(
            fn (array $row): array => $row['asset_ids'] ?? [],
            [...$shown, ...$cargo],
        )));

        return [
            'rows'      => array_map(fn (array $row): array => $this->present(row: $row, details: $details), $shown),
            'cargo'     => array_map(fn (array $row): array => $this->present(row: $row, details: $details), $cargo),
            'docked'    => $this->docked(files: $scanned, producers: $producers, now: $context->now),
            'more'      => max(0, count($rows) - $boardRows),
            'truncated' => $files->count() > $scanLimit,
            // Nothing reports a craft's outbox yet: the MCP rework adds those reports.
            'holding'   => ['available' => false, 'reason' => 'awaiting-outbox-reports'],
        ];
    }

    /**
     * Each craft that saved one of the files the board read, most recent first. A save is the only record of a craft's
     * activity, so this reports saves and never says a craft is connected right now.
     *
     * @param  Collection<int, \stdClass>  $files  newest first
     * @param  array<int, string>  $producers
     * @return list<array{producer: string, last_save_at: string, files_24h: int, hours: list<int>}>
     */
    private function docked(Collection $files, array $producers, CarbonImmutable $now): array
    {
        $docked = [];
        foreach ($files as $file) {
            $producer = $producers[(int) $file->id] ?? null;
            if ($producer === null) {
                continue;
            }

            $at = CarbonImmutable::parse($file->created_at);
            $docked[$producer] ??= ['producer' => $producer, 'last_save_at' => $at->toIso8601String(), 'files_24h' => 0, 'hours' => array_fill(0, self::DOCKED_HOURS, 0)];
            $hoursAgo = (int) floor(($now->getTimestamp() - $at->getTimestamp()) / 3600);
            if ($hoursAgo >= 0 && $hoursAgo < self::DOCKED_HOURS) {
                $docked[$producer]['files_24h']++;
                $docked[$producer]['hours'][self::DOCKED_HOURS - 1 - $hoursAgo]++;
            }
        }

        return array_values($docked);
    }

    /**
     * A row as Home receives it: what the landing is called, what kinds of file it holds and its first pictures.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Asset>  $details
     * @return array<string, mixed>
     */
    private function present(array $row, Collection $details): array
    {
        $assets = collect($row['asset_ids'] ?? [])->map(fn (int $assetId): ?Asset => $details->get($assetId))->filter()->values();
        unset($row['asset_ids']);
        $row['at'] = $row['at']->toIso8601String();
        $row['title'] = $this->landingTitle(assets: $assets);
        $row['kinds'] = $assets
            ->countBy(fn (Asset $asset): string => strtolower((string) $asset->extension))
            // PHP turns an all-digit extension such as "123" into an integer array key.
            ->map(fn (int $count, int|string $extension): array => ['extension' => (string) $extension, 'count' => $count])
            ->values()
            ->all();
        $row['previews'] = $assets
            ->take((int) config('dam-home.summary.cargo_pictures'))
            ->map(fn (Asset $asset): array => [
                'id'        => (int) $asset->id,
                'name'      => $asset->display_name,
                'type'      => (string) $asset->file_type,
                'url'       => route('admin.dam.assets.edit', $asset->id),
                'thumbnail' => route('admin.dam.file.thumbnail', ['path' => urlencode((string) $asset->path)]),
            ])
            ->all();

        return $row;
    }

    /**
     * One file is called by its own name. Several are called by the folder they share, when they share one that is not
     * the library's top folder.
     *
     * @param  Collection<int, Asset>  $assets  newest first
     */
    private function landingTitle(Collection $assets): ?string
    {
        $newest = $assets->first();
        if ($newest === null) {
            return null;
        }

        $folder = $newest->directories->first();
        $shared = $assets->count() > 1
            && $folder?->parent_id !== null
            && $assets->every(fn (Asset $asset): bool => $asset->directories->contains('id', $folder->id));

        return $shared ? (string) $folder->name : $newest->display_name;
    }

    /**
     * The landing's files, read through the same access check as every other count on Home.
     *
     * @param  list<int>  $assetIds
     * @return Collection<int, Asset>
     */
    private function assetDetails(array $assetIds): Collection
    {
        $details = collect();
        foreach (array_chunk(array_values(array_unique($assetIds)), 500) as $chunk) {
            $details = $details->merge(
                $this->visibleAssets()
                    ->withDisplayName()
                    ->with('directories:id,name,parent_id')
                    ->whereIn('dam_assets.id', $chunk)
                    ->get()
                    ->keyBy(fn (Asset $asset): int => (int) $asset->id)
                    ->all()
            );
        }

        return $details->keyBy(fn (Asset $asset): int => (int) $asset->id);
    }

    /**
     * @param  Collection<int, \stdClass>  $files  newest first
     * @param  array<int, string>  $producers
     * @return list<array<string, mixed>>
     */
    private function landings(Collection $files, array $producers, HomeSummaryContext $context): array
    {
        $assetIds = $files->map(fn (object $file): int => (int) $file->id)->all();
        $trackers = $this->trackers($assetIds);
        // Failures are shown only to the person who uploaded: a failed file in someone else's session may be hidden.
        $partial = $this->failuresBySession(
            UploadTracker::query()
                ->whereIn('id', array_values(array_unique($trackers)))
                ->where('user_id', $context->adminId)
                ->pluck('id')
                ->map(fn (mixed $trackerId): int => (int) $trackerId)
                ->all()
        );
        $gapMinutes = (int) config('dam-home.summary.landing_gap_minutes');

        $landings = [];
        $open = [];
        foreach ($files as $file) {
            $assetId = (int) $file->id;
            $at = CarbonImmutable::parse($file->created_at);
            $fresh = $context->isNew($at);
            $producer = $producers[$assetId] ?? null;
            $tracker = $producer === null ? ($trackers[$assetId] ?? null) : null;
            $source = match (true) {
                $producer !== null => 'craft:'.$producer.':'.(int) $file->created_by,
                $tracker !== null  => 'tracker:'.$tracker,
                default            => 'upload:'.(int) $file->created_by,
            };
            $key = $source.($fresh ? ':new' : ':seen');
            $index = $open[$key] ?? null;

            // One upload stays one landing however long it took. Other files must arrive close together.
            if ($index !== null && ($tracker !== null || $landings[$index]['oldest']->subMinutes($gapMinutes)->lessThanOrEqualTo($at))) {
                $landings[$index]['files']++;
                $landings[$index]['asset_ids'][] = $assetId;
                $landings[$index]['bytes'] += (int) $file->file_size;
                $landings[$index]['oldest'] = $at;

                continue;
            }

            $failedFiles = $tracker !== null ? ($partial[$tracker] ?? 0) : 0;
            $open[$key] = count($landings);
            $landings[] = [
                'key'          => 'asset:'.$assetId,
                'status'       => match (true) {
                    ! $fresh         => self::STATUS_SEEN,
                    $failedFiles > 0 => self::STATUS_PARTIAL,
                    default          => self::STATUS_LANDED,
                },
                'phase'        => null,
                'source'       => $producer !== null ? 'craft' : 'upload',
                'producer'     => $producer,
                'mine'         => (int) $file->created_by === $context->adminId,
                'asset_id'     => $assetId,
                'asset_ids'    => [$assetId],
                'files'        => 1,
                'failed_files' => $failedFiles,
                'bytes'        => (int) $file->file_size,
                'at'           => $at,
                'oldest'       => $at,
            ];
        }

        return array_map(function (array $landing): array {
            unset($landing['oldest']);

            return $landing;
        }, $landings);
    }

    /**
     * Uploads still arriving or being processed, one row per upload session.
     *
     * @return list<array<string, mixed>>
     */
    private function stowing(HomeSummaryContext $context): array
    {
        $freshAfter = $context->now->subHours((int) config('dam-home.summary.stowing_stale_hours'));
        $uploads = ChunkUpload::query()
            ->where('user_id', $context->adminId)
            ->whereIn('state', self::IN_FLIGHT_UPLOADS)
            ->where('expires_at', '>', $context->now)
            ->where('updated_at', '>=', $freshAfter)
            ->get(['upload_tracker_id', 'state', 'expected_bytes', 'received_bytes', 'updated_at'])
            ->groupBy(fn (ChunkUpload $upload): int => (int) $upload->upload_tracker_id);
        $trackers = UploadTracker::query()
            ->where('user_id', $context->adminId)
            ->whereIn('state', self::IN_FLIGHT_TRACKERS)
            ->where('updated_at', '>=', $freshAfter)
            ->get()
            ->keyBy('id');
        // Files of the same session that already finished still count toward its totals.
        $finished = ChunkUpload::query()
            ->where('user_id', $context->adminId)
            ->where('state', ChunkUpload::STATE_COMPLETED)
            ->whereIn('upload_tracker_id', $uploads->keys()->merge($trackers->keys())->unique()->reject(fn (int $trackerId): bool => $trackerId === 0)->values()->all())
            ->get(['upload_tracker_id', 'expected_bytes'])
            ->groupBy(fn (ChunkUpload $upload): int => (int) $upload->upload_tracker_id);
        $failures = $this->failuresBySession($trackers->keys()->all());

        $rows = [];
        foreach ($uploads as $trackerId => $group) {
            $tracker = $trackers->get($trackerId);
            $done = $finished->get($trackerId, collect());
            $doneBytes = (int) $done->sum('expected_bytes');
            $rows[] = $this->stowingRow(
                key: $trackerId === 0 ? 'uploads' : 'tracker:'.$trackerId,
                phase: $group->contains('state', ChunkUpload::STATE_RECEIVING) ? 'uploading' : 'processing',
                counts: [
                    'files'          => max((int) $tracker?->total_files, $group->count() + $done->count()),
                    'saved_files'    => (int) $tracker?->processed_files,
                    'bytes'          => (int) $group->sum('expected_bytes') + $doneBytes,
                    'received_bytes' => (int) $group->sum('received_bytes') + $doneBytes,
                ],
                at: CarbonImmutable::parse($group->max('updated_at')),
            );
        }

        foreach ($trackers->except($uploads->keys()->all()) as $tracker) {
            // Every file has an outcome, so nothing is still on its way.
            if ((int) $tracker->total_files > 0 && (int) $tracker->processed_files + ($failures[$tracker->id] ?? 0) >= (int) $tracker->total_files) {
                continue;
            }

            $done = $finished->get($tracker->id);
            $doneBytes = $done === null ? null : (int) $done->sum('expected_bytes');
            $rows[] = $this->stowingRow(
                key: 'tracker:'.$tracker->id,
                phase: 'processing',
                counts: [
                    'files'          => (int) $tracker->total_files,
                    'saved_files'    => (int) $tracker->processed_files,
                    // A session with no chunked files reports no byte figures, which is not the same as zero bytes.
                    'bytes'          => $doneBytes,
                    'received_bytes' => $doneBytes,
                ],
                at: CarbonImmutable::parse($tracker->updated_at),
            );
        }

        return $rows;
    }

    /**
     * @param  array{files: int, saved_files: int, bytes: int|null, received_bytes: int|null}  $counts
     * @return array<string, mixed>
     */
    private function stowingRow(string $key, string $phase, array $counts, CarbonImmutable $at): array
    {
        return [
            'key'          => $key,
            'status'       => self::STATUS_STOWING,
            'phase'        => $phase,
            'source'       => 'upload',
            'producer'     => null,
            'mine'         => true,
            'asset_id'     => null,
            'failed_files' => 0,
            'at'           => $at,
            ...$counts,
        ];
    }

    /**
     * Upload sessions that saved nothing. One that saved some files shows as a partial landing instead.
     *
     * @return list<array<string, mixed>>
     */
    private function failed(HomeSummaryContext $context): array
    {
        $after = $context->since ?? $context->now->subDays((int) config('dam-home.summary.attention_days'));

        $trackers = UploadTracker::query()
            ->where('user_id', $context->adminId)
            ->where('state', '!=', UploadTracker::STATE_CANCELLED)
            ->where('processed_files', 0)
            ->where('updated_at', '>', $after)
            ->get();
        $failures = $this->failuresBySession($trackers->modelKeys());

        return $trackers
            ->filter(function (UploadTracker $tracker) use ($failures): bool {
                $failed = $failures[$tracker->id] ?? 0;
                $settled = in_array($tracker->state, [UploadTracker::STATE_COMPLETED, UploadTracker::STATE_FAILED], true);

                return $failed > 0 && ($settled || $failed >= (int) $tracker->total_files);
            })
            ->map(fn (UploadTracker $tracker): array => [
                'key'          => 'tracker:'.$tracker->id,
                'status'       => self::STATUS_FAILED,
                'phase'        => null,
                'source'       => 'upload',
                'producer'     => null,
                'mine'         => true,
                'asset_id'     => null,
                'files'        => (int) $tracker->total_files,
                'failed_files' => $failures[$tracker->id],
                'bytes'        => null,
                'at'           => CarbonImmutable::parse($tracker->updated_at),
            ])
            ->values()
            ->all();
    }

    /** How many files this person failed to upload since the given moment, each file counted once. */
    public function failedFileCount(int $adminId, CarbonImmutable $after): int
    {
        $batchAssets = DB::table('dam_upload_batches')
            ->join('dam_upload_trackers', 'dam_upload_trackers.id', '=', 'dam_upload_batches.upload_tracker_id')
            ->where('dam_upload_trackers.user_id', $adminId)
            ->where('dam_upload_batches.state', UploadBatch::STATE_FAILED)
            ->where('dam_upload_batches.updated_at', '>=', $after)
            ->pluck('dam_upload_batches.asset_id')
            ->map(fn (mixed $assetId): int => (int) $assetId);
        $chunkAssets = DB::table('dam_chunk_uploads')
            ->where('user_id', $adminId)
            ->where('state', ChunkUpload::STATE_FAILED)
            ->where('updated_at', '>=', $after)
            ->pluck('asset_id');

        return $batchAssets->count() + $chunkAssets
            ->filter(fn (mixed $assetId): bool => $assetId === null || ! $batchAssets->contains((int) $assetId))
            ->count();
    }

    /**
     * Failed files per upload session. A file that failed while assembling has only a chunk record, one that failed
     * while processing has a batch record, and a file with both is the same file.
     *
     * @param  list<int>  $trackerIds
     * @return array<int, int>
     */
    private function failuresBySession(array $trackerIds): array
    {
        $failures = [];
        foreach (array_chunk($trackerIds, 500) as $chunk) {
            $batches = DB::table('dam_upload_batches')
                ->whereIn('upload_tracker_id', $chunk)
                ->where('state', UploadBatch::STATE_FAILED)
                ->get(['upload_tracker_id', 'asset_id'])
                ->groupBy('upload_tracker_id');
            $uploads = DB::table('dam_chunk_uploads')
                ->whereIn('upload_tracker_id', $chunk)
                ->where('state', ChunkUpload::STATE_FAILED)
                ->get(['upload_tracker_id', 'asset_id'])
                ->groupBy('upload_tracker_id');

            foreach ($chunk as $trackerId) {
                $batchAssets = $batches->get($trackerId, collect())->map(fn (object $batch): int => (int) $batch->asset_id);
                $assembly = $uploads->get($trackerId, collect())
                    ->filter(fn (object $upload): bool => $upload->asset_id === null || ! $batchAssets->contains((int) $upload->asset_id));
                $failures[$trackerId] = $batchAssets->count() + $assembly->count();
            }
        }

        return $failures;
    }

    /**
     * The producer each file was saved by, read in PHP so the query is the same on every database.
     *
     * @param  list<int>  $assetIds
     * @return array<int, string>
     */
    private function producers(array $assetIds): array
    {
        $producers = [];
        foreach (array_chunk($assetIds, 500) as $chunk) {
            DB::table('dam_creative_hub_saves')
                ->whereIn('asset_id', $chunk)
                ->whereNull('asset_file_id')
                ->whereNotNull('evidence')
                ->orderBy('id')
                ->get(['asset_id', 'evidence'])
                ->each(function (object $save) use (&$producers): void {
                    $record = json_decode((string) $save->evidence, true);
                    if (is_array($record) && is_string($record['producer'] ?? null) && $record['producer'] !== '') {
                        $producers[(int) $save->asset_id] ??= $record['producer'];
                    }
                });
        }

        return $producers;
    }

    /**
     * The upload session each file arrived in. Chunked uploads and multipart batches record it in different tables.
     *
     * @param  list<int>  $assetIds
     * @return array<int, int>
     */
    private function trackers(array $assetIds): array
    {
        $trackers = [];
        foreach (array_chunk($assetIds, 500) as $chunk) {
            foreach (['dam_chunk_uploads', 'dam_upload_batches'] as $table) {
                DB::table($table)
                    ->whereIn('asset_id', $chunk)
                    ->whereNotNull('upload_tracker_id')
                    ->get(['asset_id', 'upload_tracker_id'])
                    ->each(function (object $upload) use (&$trackers): void {
                        $trackers[(int) $upload->asset_id] ??= (int) $upload->upload_tracker_id;
                    });
            }
        }

        return $trackers;
    }
}
