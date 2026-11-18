<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Jobs\ProcessBatchItem;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\ProcessingBatch;
use Mothership\DAM\Models\ProcessingBatchItem;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessDecision;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Support\BatchLog;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * Starts, reads, retries and cancels processing batches (P6a), for the Library's Process dialog, the viewer's
 * Export menu (a one-item batch) and REST alike.
 *
 * Every item is decided through the access seam for the requester when the batch starts, and again by the
 * runner when its job runs. An asset the requester cannot see — missing, hidden, trashed, merged or unavailable —
 * is skipped with one reason, `not_found`, so a batch never tells hidden from missing.
 */
class BatchService
{
    /** Raster formats a watermark overlay may be; ffmpeg cannot draw an SVG or a PDF. */
    public const OVERLAY_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff'];

    public function __construct(
        private readonly RecipeRegistry $recipes,
        private readonly AssetAccessDecision $access,
        private readonly Lineage $lineage,
    ) {}

    public static function maxItems(): int
    {
        return max(1, (int) config('dam.batch.max_items', 200));
    }

    /** @return array<string, mixed> validation rules for the request body shared by the web and REST routes */
    public static function rules(RecipeRegistry $recipes): array
    {
        return [
            'recipe'      => ['required', 'string', Rule::in(array_keys($recipes->all()))],
            'params'      => ['nullable', 'array'],
            'asset_ids'   => ['required', 'array', 'min:1', 'max:'.self::maxItems()],
            'asset_ids.*' => ['integer', 'min:1', 'distinct'],
            'save_as'     => ['nullable', Rule::in([ProcessingBatch::SAVE_ATTACH, ProcessingBatch::SAVE_NEW_ASSET])],
        ];
    }

    /**
     * Validates the recipe's parameters, decides every item, records the batch and queues one job per runnable
     * item. A selection over the limit is refused whole (422) rather than cut short.
     *
     * @throws ValidationException
     */
    public function create(BatchRequest $request): ProcessingBatch
    {
        $assetIds = array_values(array_unique(array_map('intval', $request->assetIds)));

        if (count($assetIds) > self::maxItems()) {
            throw ValidationException::withMessages(['asset_ids' => [trans('dam::app.admin.batch.validation.too-many', ['max' => self::maxItems()])]]);
        }

        $recipe = $this->recipes->get($request->recipe);
        $params = $this->recipes->validate($request->recipe, $request->params);
        $newAsset = $request->saveAs === ProcessingBatch::SAVE_NEW_ASSET;

        // Saving as a new entry files one into the source's folder: the requester must be one who may upload.
        abort_if($newAsset && ! self::adminMay($request->requester, 'dam.asset.upload'), 403, trans('dam::app.admin.batch.validation.new-asset-forbidden'));

        $overlay = isset($params['overlay_asset_id'])
            ? $this->overlayRevision(requester: $request->requester, via: $request->via, assetId: (int) $params['overlay_asset_id'])
            : null;

        if ($overlay !== null) {
            $params['overlay_asset_file_id'] = (int) $overlay->id;
        }

        $batch = DB::transaction(function () use ($request, $recipe, $params, $assetIds, $newAsset): ProcessingBatch {
            $batch = ProcessingBatch::create([
                'recipe'         => $recipe->key(),
                'params'         => $params,
                'save_as'        => $newAsset ? ProcessingBatch::SAVE_NEW_ASSET : ProcessingBatch::SAVE_ATTACH,
                'requested_by'   => $request->requester->id,
                'via'            => $request->via,
                'status'         => ProcessingBatch::STATUS_QUEUED,
                'correlation_id' => (string) Str::uuid(),
            ]);
            $assets = Asset::withTrashed()->whereIn('id', $assetIds)->get()->keyBy('id');

            foreach ($assetIds as $position => $assetId) {
                [$status, $error, $source] = $this->admit(batch: $batch, requester: $request->requester, recipe: $recipe, asset: $assets->get($assetId), assetId: $assetId);
                ProcessingBatchItem::create([
                    'batch_id' => $batch->id, 'position' => $position, 'asset_id' => $assetId, 'source_asset_file_id' => $source,
                    'status'   => $status, 'error' => $error, 'finished_at' => $status === ProcessingBatchItem::STATUS_SKIPPED ? now() : null,
                ]);
            }

            $batch->refreshCounts();

            return $batch;
        });

        BatchLog::within($batch->correlation_id);
        BatchLog::write('batch.created', [
            'batch_id' => (int) $batch->id, 'recipe' => $batch->recipe, 'via' => $batch->via, 'save_as' => $batch->save_as,
            'total'    => $batch->total, 'skipped' => $batch->skipped, 'requested_by' => (int) $batch->requested_by,
        ]);

        // After the commit, so a worker (or the sync connection) never looks for rows that are not there yet.
        try {
            $this->dispatch(batch: $batch, itemIds: $batch->items()->where('status', ProcessingBatchItem::STATUS_QUEUED)->pluck('id')->all());
        } catch (Throwable $e) {
            // The queue refused the jobs (no Redis, say): nothing will ever run them, so the queued items fail now
            // instead of leaving a batch that reads as running for good (G3-06).
            report($e);
            ProcessingBatchItem::query()->where('batch_id', $batch->id)->where('status', ProcessingBatchItem::STATUS_QUEUED)
                ->update(['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::WORKER_FAILED, 'finished_at' => now(), 'updated_at' => now()]);
            $batch->refreshCounts();
            BatchLog::write('batch.dispatch_failed', ['batch_id' => (int) $batch->id, 'recipe' => $batch->recipe], 'error');

            abort(503, trans('dam::app.admin.batch.validation.queue-unavailable'));
        }

        return $batch->fresh();
    }

    /** A batch only its requester reads or changes; anyone else gets the 404 a missing id gets. */
    public function ownedBy(int $batchId, Admin $viewer): ProcessingBatch
    {
        $batch = ProcessingBatch::query()->where('id', $batchId)->first();

        abort_unless($batch !== null && (int) $batch->requested_by === (int) $viewer->id, 404);

        return $batch;
    }

    /** Puts one failed item back in the queue; any other state answers 422. */
    public function retry(ProcessingBatch $batch, int $itemId): ProcessingBatch
    {
        $item = DB::transaction(function () use ($batch, $itemId): ProcessingBatchItem {
            $item = ProcessingBatchItem::query()->where('batch_id', $batch->id)->where('id', $itemId)->lockForUpdate()->first();

            abort_if($item === null, 404);
            abort_unless($item->status === ProcessingBatchItem::STATUS_FAILED, 422, trans('dam::app.admin.batch.validation.retry-failed-only'));

            $item->forceFill(['status' => ProcessingBatchItem::STATUS_QUEUED, 'error' => null, 'started_at' => null, 'finished_at' => null, 'duration_ms' => null])->save();
            $batch->forceFill(['finished_at' => null])->save();
            $batch->refreshCounts();

            return $item;
        });

        BatchLog::within($batch->correlation_id);
        BatchLog::write('batch.item.retried', ['batch_id' => (int) $batch->id, 'item_id' => (int) $item->id, 'recipe' => $batch->recipe, 'attempts' => (int) $item->attempts]);

        $this->dispatch(batch: $batch, itemIds: [(int) $item->id]);

        return $batch->fresh();
    }

    /** Cancels every item not yet started; a running item finishes, and what is done stays done. */
    public function cancel(ProcessingBatch $batch): ProcessingBatch
    {
        $cancelled = DB::transaction(function () use ($batch): int {
            ProcessingBatch::query()->where('id', $batch->id)->lockForUpdate()->first();
            $count = ProcessingBatchItem::query()->where('batch_id', $batch->id)->where('status', ProcessingBatchItem::STATUS_QUEUED)
                ->update(['status' => ProcessingBatchItem::STATUS_CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);
            // A batch with nothing left to cancel stays as it ended.
            if ($count > 0) {
                $batch->forceFill(['cancelled_at' => $batch->cancelled_at ?? now()])->save();
            }

            $batch->refreshCounts();

            return $count;
        });

        if ($batch->bus_batch_id !== null) {
            Bus::findBatch($batch->bus_batch_id)?->cancel();
        }

        BatchLog::within($batch->correlation_id);
        BatchLog::write('batch.cancelled', ['batch_id' => (int) $batch->id, 'recipe' => $batch->recipe, 'cancelled' => $cancelled]);

        return $batch->fresh();
    }

    /**
     * The batch with its items, for its requester. An item's file is named, and its output linked, only while the
     * viewer can still see it: one that has since become hidden reads like a missing one.
     *
     * @return array<string, mixed>
     */
    public function present(ProcessingBatch $batch, Admin $viewer): array
    {
        $items = $batch->items()->get();
        $visible = $this->visibleIds(viewer: $viewer, assetIds: $items->pluck('asset_id')->merge($items->pluck('output_asset_id'))->filter()->unique()->values()->all());
        $names = Asset::query()->whereIn('id', $visible)->get(['id', 'file_name', 'title'])->mapWithKeys(fn (Asset $asset) => [$asset->id => $asset->title ?: $asset->file_name]);
        $recipe = $this->recipes->has($batch->recipe) ? $this->recipes->get($batch->recipe) : null;

        return [
            'id'           => (int) $batch->id,
            'recipe'       => $batch->recipe,
            'recipe_label' => trans('dam::app.admin.batch.recipes.'.$batch->recipe),
            'output_label' => $recipe?->label($batch->params ?? []),
            'params'       => $batch->params ?? [],
            'save_as'      => $batch->save_as,
            'status'       => $batch->status,
            'counts'       => ['total' => $batch->total, 'done' => $batch->done, 'failed' => $batch->failed, 'skipped' => $batch->skipped, 'cancelled' => $batch->cancelled],
            'created_at'   => $batch->created_at?->toIso8601String(),
            'finished_at'  => $batch->finished_at?->toIso8601String(),
            'items'        => $items->map(function (ProcessingBatchItem $item) use ($visible, $names): array {
                $seen = in_array($item->asset_id, $visible, true);
                $output = null;

                if ($item->status === ProcessingBatchItem::STATUS_DONE && $item->output_asset_file_id !== null && $seen) {
                    $output = ['asset_id' => $item->asset_id, 'asset_file_id' => $item->output_asset_file_id,
                        'url'             => route('admin.dam.file.asset-file', ['asset' => $item->asset_id, 'assetFile' => $item->output_asset_file_id])];
                } elseif ($item->status === ProcessingBatchItem::STATUS_DONE && $item->output_asset_id !== null && in_array($item->output_asset_id, $visible, true)) {
                    $output = ['asset_id' => $item->output_asset_id, 'asset_file_id' => null, 'url' => route('admin.dam.assets.edit', $item->output_asset_id)];
                }

                return [
                    'id'            => (int) $item->id,
                    'position'      => $item->position,
                    'asset_id'      => $item->asset_id,
                    'asset_name'    => $seen ? ($names[$item->asset_id] ?? null) : null,
                    'asset_url'     => $seen ? route('admin.dam.assets.edit', $item->asset_id) : null,
                    'status'        => $item->status,
                    'error'         => $item->error,
                    'error_message' => $item->error === null ? null : trans('dam::app.admin.batch.errors.'.$item->error),
                    'attempts'      => $item->attempts,
                    'can_retry'     => $item->status === ProcessingBatchItem::STATUS_FAILED,
                    'output'        => $output,
                    'duration_ms'   => $item->duration_ms,
                ];
            })->values()->all(),
        ];
    }

    /**
     * The seam's answer for one item: changing the source entry for an attach, reading it for a new entry.
     */
    public function decide(Admin $requester, string $via, string $saveAs, int $assetId): AccessDecision
    {
        $purpose = $saveAs === ProcessingBatch::SAVE_NEW_ASSET ? AccessContext::PURPOSE_DOWNLOAD : AccessContext::PURPOSE_EDIT;

        return $this->access->for(AccessContext::forAdmin(admin: $requester, purpose: $purpose, via: $via), $assetId);
    }

    /** The reason an asset the seam answered for cannot run, or null when it can. */
    public static function refusal(AccessDecision $decision): ?string
    {
        return match (true) {
            ! $decision->visible  => ItemError::NOT_FOUND,
            ! $decision->allowed  => ItemError::NOT_ALLOWED,
            default               => null,
        };
    }

    /**
     * The watermark: an image the requester can open, as its current revision. A missing and a hidden id get one
     * message, so the field cannot be used to probe for assets.
     *
     * @throws ValidationException
     */
    public function overlayRevision(Admin $requester, string $via, int $assetId): AssetFile
    {
        $decision = $this->access->for(AccessContext::forAdmin(admin: $requester, purpose: AccessContext::PURPOSE_PREVIEW, via: $via), $assetId);
        $asset = $decision->visible ? $decision->asset : null;
        $revision = $asset !== null && $asset->file_type === 'image' && in_array(strtolower((string) $asset->extension), self::OVERLAY_EXTENSIONS, true)
            ? $this->lineage->currentRevision(assetId: (int) $asset->id)
            : null;

        if ($revision === null) {
            throw ValidationException::withMessages(['params.overlay_asset_id' => [trans('dam::app.admin.batch.validation.overlay-unavailable')]]);
        }

        return $revision;
    }

    /** Whether the admin's role grants a web permission: the session-free form of bouncer()->hasPermission(). */
    public static function adminMay(Admin $admin, string $permission): bool
    {
        $role = $admin->role;

        return $role !== null && ($role->permission_type === 'all' || $admin->hasPermission($permission));
    }

    /** @return array{0: string, 1: ?string, 2: ?int} status, error, source revision id */
    private function admit(ProcessingBatch $batch, Admin $requester, Recipe $recipe, ?Asset $asset, int $assetId): array
    {
        $refusal = self::refusal($this->decide(requester: $requester, via: $batch->via, saveAs: $batch->save_as, assetId: $assetId));

        if ($refusal !== null || $asset === null) {
            return [ProcessingBatchItem::STATUS_SKIPPED, $refusal ?? ItemError::NOT_FOUND, null];
        }

        if (! $recipe->accepts((string) $asset->file_type, $batch->params ?? [])) {
            return [ProcessingBatchItem::STATUS_SKIPPED, ItemError::NOT_APPLICABLE, null];
        }

        // The exact revision the output will be made from, fixed now: a newer upload while the item waits does not
        // change what it was asked to process.
        $source = $this->lineage->currentRevision(assetId: $assetId);

        return $source === null
            ? [ProcessingBatchItem::STATUS_SKIPPED, ItemError::SOURCE_UNAVAILABLE, null]
            : [ProcessingBatchItem::STATUS_QUEUED, null, (int) $source->id];
    }

    /** @param  list<int>  $itemIds */
    private function dispatch(ProcessingBatch $batch, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $jobs = array_map(fn (int $itemId) => new ProcessBatchItem(itemId: $itemId), $itemIds);
        $connection = ProcessBatchItem::connectionName();
        $queue = (string) config('dam.batch.queue', 'video-preview');

        if ($batch->bus_batch_id === null) {
            $pending = Bus::batch($jobs)->allowFailures()->name('dam-batch:'.$batch->id)->onConnection($connection)->onQueue($queue);
            // Recorded before dispatch: on the sync connection the jobs run inside dispatch().
            $batchId = (int) $batch->id;
            $pending->before(function (Batch $bus) use ($batchId): void {
                ProcessingBatch::query()->where('id', $batchId)->update(['bus_batch_id' => $bus->id]);
            });
            $pending->dispatch();

            return;
        }

        // A retry joins its batch when the queue still has it; otherwise it runs as a job of its own.
        $bus = Bus::findBatch($batch->bus_batch_id);

        if ($bus !== null && ! $bus->cancelled()) {
            $bus->add($jobs);

            return;
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }
    }

    /**
     * Of these asset ids, the ones the viewer can see now, in one listing query through the seam.
     *
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    private function visibleIds(Admin $viewer, array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $query = Asset::query()->whereIn('dam_assets.id', $assetIds)->select('dam_assets.id');
        $this->access->scopeListable($query, AccessContext::forAdmin(admin: $viewer, purpose: AccessContext::PURPOSE_LIST));

        return $query->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->all();
    }
}
