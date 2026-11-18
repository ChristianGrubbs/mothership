<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\ProcessingBatch;
use Mothership\DAM\Models\ProcessingBatchItem;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\BatchLog;
use Mothership\DAM\Support\LocalFile;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * Runs one batch item (P6a): claims it, asks the access seam again for the requester, stages the exact source
 * revision read-only, runs the recipe in the item's own directory under storage, files the output with lineage,
 * and settles the item with a user-safe reason when anything stops it. The source's blob is only ever read:
 * ContentStore stages a copy of it in the work directory, where every recipe writes.
 */
class BatchRunner
{
    public function __construct(
        private readonly RecipeRegistry $recipes,
        private readonly BatchService $batches,
        private readonly ContentStore $store,
        private readonly OutputPublisher $publisher,
        private readonly MediaTools $tools,
    ) {}

    public function run(int $itemId): void
    {
        // Claimed by one conditional update: a cancelled item, or one another worker took, is left alone.
        $claimed = ProcessingBatchItem::query()->where('id', $itemId)->where('status', ProcessingBatchItem::STATUS_QUEUED)->update([
            'status' => ProcessingBatchItem::STATUS_RUNNING, 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return;
        }

        $item = ProcessingBatchItem::query()->where('id', $itemId)->firstOrFail();
        $batch = ProcessingBatch::query()->where('id', $item->batch_id)->firstOrFail();
        BatchLog::within($batch->correlation_id);
        $this->settleCounts($batch);
        $started = hrtime(true);
        $ctx = ['batch_id' => (int) $batch->id, 'item_id' => (int) $item->id, 'asset_id' => (int) $item->asset_id, 'recipe' => $batch->recipe, 'attempt' => (int) $item->attempts];
        BatchLog::write('batch.item.started', $ctx);

        $workdir = null;
        $staged = [];

        try {
            $outcome = $this->process(batch: $batch, item: $item, workdir: $workdir, staged: $staged);
            $this->settle(item: $item, batch: $batch, outcome: $outcome, started: $started, ctx: $ctx);
        } catch (RecipeFailed $failed) {
            $this->settle(item: $item, batch: $batch, outcome: ['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => $failed->code(), 'exit_code' => $failed->exitCode], started: $started, ctx: $ctx);
        } catch (ContentUnavailable) {
            $this->settle(item: $item, batch: $batch, outcome: ['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::SOURCE_UNAVAILABLE], started: $started, ctx: $ctx);
        } catch (Throwable $e) {
            // Only the exception's class reaches the log: messages can carry paths or tool output.
            $this->settle(item: $item, batch: $batch, outcome: ['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::PROCESSING_FAILED, 'exception' => class_basename($e)], started: $started, ctx: $ctx);
        } finally {
            foreach ($staged as $file) {
                $file->release();
            }

            if ($workdir !== null) {
                self::removeDirectory($workdir);
            }
        }
    }

    /** The queue gave up on the item's job (a worker killed at its timeout): settle it if it is still running. */
    public function abandon(int $itemId): void
    {
        $item = ProcessingBatchItem::query()->where('id', $itemId)->where('status', ProcessingBatchItem::STATUS_RUNNING)->first();

        if ($item === null) {
            return;
        }

        $batch = ProcessingBatch::query()->where('id', $item->batch_id)->firstOrFail();
        BatchLog::within($batch->correlation_id);
        $item->forceFill(['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::WORKER_FAILED, 'finished_at' => now()])->save();
        BatchLog::write('batch.item.failed', ['batch_id' => (int) $batch->id, 'item_id' => (int) $item->id, 'recipe' => $batch->recipe, 'error' => ItemError::WORKER_FAILED], 'warning');
        $this->settleCounts($batch);
    }

    /**
     * @param  list<LocalFile>  $staged
     * @return array<string, mixed> the item's outcome
     */
    private function process(ProcessingBatch $batch, ProcessingBatchItem $item, ?string &$workdir, array &$staged): array
    {
        $requester = Admin::query()->where('id', $batch->requested_by)->first();
        $decision = $requester === null ? null : $this->batches->decide(requester: $requester, via: $batch->via, saveAs: $batch->save_as, assetId: (int) $item->asset_id);
        // The run-time half of the access rule: what was visible when the batch started may not be now.
        $refusal = $decision === null ? ItemError::NOT_FOUND : BatchService::refusal($decision);

        if ($refusal !== null) {
            return ['status' => ProcessingBatchItem::STATUS_SKIPPED, 'error' => $refusal];
        }

        /** @var Asset $asset */
        $asset = $decision->asset;
        $recipe = $this->recipes->get($batch->recipe);
        $params = $batch->params ?? [];

        if (! $recipe->accepts((string) $asset->file_type, $params)) {
            return ['status' => ProcessingBatchItem::STATUS_SKIPPED, 'error' => ItemError::NOT_APPLICABLE];
        }

        $source = AssetFile::query()->where('id', $item->source_asset_file_id)->where('dam_asset_id', $asset->id)->with('fileObject')->first();

        if ($source === null || $source->fileObject === null) {
            return ['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::SOURCE_UNAVAILABLE];
        }

        $overlay = null;

        if (isset($params['overlay_asset_id'])) {
            $overlay = $this->overlay(requester: $requester, batch: $batch, params: $params);

            if ($overlay === null) {
                return ['status' => ProcessingBatchItem::STATUS_FAILED, 'error' => ItemError::OVERLAY_UNAVAILABLE];
            }
        }

        $inputs = OutputPublisher::inputs(source: $source, overlay: $overlay);
        $fingerprint = $this->fingerprint(recipe: $recipe, params: $params, saveAs: $batch->save_as, inputs: $inputs);

        if ($reused = $this->reusable(assetId: (int) $asset->id, recipe: $recipe->key(), fingerprint: $fingerprint)) {
            return ['status' => ProcessingBatchItem::STATUS_DONE, 'reused' => true] + $reused;
        }

        $workdir = self::makeWorkdir();
        $extension = $source->extension() ?: (string) $asset->extension;
        // A copy, not ContentStore's usual symlink: a tool that wrote to its input would otherwise write to the blob.
        $staged[] = $input = $this->store->localPath(object: $source->fileObject, extension: $extension, under: $workdir, copy: true);
        $overlayPath = null;

        if ($overlay !== null) {
            $staged[] = $overlayFile = $this->store->localPath(object: $overlay->fileObject, extension: $overlay->extension() ?: 'png', under: $workdir, copy: true);
            $overlayPath = $overlayFile->path;
        }

        $output = $recipe->run(new RecipeRun(
            input: $input->path,
            inputExtension: $extension,
            fileType: (string) $asset->file_type,
            params: $params,
            workdir: $workdir,
            tools: $this->tools,
            overlay: $overlayPath,
        ));

        $published = $this->publisher->publish(
            source: $asset,
            sourceRevision: $source,
            recipe: $recipe,
            output: $output,
            label: $recipe->label($params).'.'.$output->extension,
            asNewAsset: $batch->save_as === ProcessingBatch::SAVE_NEW_ASSET,
            requesterId: (int) $batch->requested_by,
            fingerprint: $fingerprint,
            parameters: [...$params, ...$output->notes, 'recipe_version' => $recipe->version(), 'batch_id' => (int) $batch->id, 'batch_item_id' => (int) $item->id],
            inputs: $inputs,
        );

        return ['status' => ProcessingBatchItem::STATUS_DONE] + $published;
    }

    /** The overlay revision the batch fixed, while the requester can still open its entry; null otherwise. */
    private function overlay(Admin $requester, ProcessingBatch $batch, array $params): ?AssetFile
    {
        try {
            $current = $this->batches->overlayRevision(requester: $requester, via: $batch->via, assetId: (int) $params['overlay_asset_id']);
        } catch (ValidationException) {
            return null;
        }

        // The revision fixed when the batch started, not one uploaded since, so every item gets the same mark.
        $fixed = AssetFile::query()->where('id', $params['overlay_asset_file_id'] ?? $current->id)
            ->where('dam_asset_id', $current->dam_asset_id)->with('fileObject')->first();

        return $fixed?->fileObject === null ? null : $fixed;
    }

    /**
     * sha256 over the recipe, its version, the canonical parameters, where the output goes and the inputs' checksums
     * (roadmap §3.5), so a changed overlay or source makes a new output and a retry finds the one already made.
     */
    private function fingerprint(Recipe $recipe, array $params, string $saveAs, array $inputs): string
    {
        ksort($params);
        $shas = array_map(fn (array $input) => $input['role'].':'.$input['sha256'], $inputs);
        sort($shas);

        return hash('sha256', json_encode([$recipe->key(), $recipe->version(), $params, $saveAs, $shas], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** A finished output with this fingerprint whose file or entry is still there, or null. */
    private function reusable(int $assetId, string $recipe, string $fingerprint): ?array
    {
        $done = Rendition::query()->where('dam_asset_id', $assetId)->where('recipe', $recipe)->where('fingerprint', $fingerprint)
            ->where('status', Rendition::STATUS_DONE)->orderByDesc('id')->get();

        foreach ($done as $rendition) {
            if ($rendition->output_asset_file_id !== null
                && AssetFile::query()->where('id', $rendition->output_asset_file_id)->where('dam_asset_id', $assetId)->where('is_current', true)->exists()) {
                return ['asset_file_id' => (int) $rendition->output_asset_file_id, 'asset_id' => null, 'rendition_id' => (int) $rendition->id];
            }

            if ($rendition->output_asset_id !== null && Asset::query()->where('id', $rendition->output_asset_id)->exists()) {
                return ['asset_file_id' => null, 'asset_id' => (int) $rendition->output_asset_id, 'rendition_id' => (int) $rendition->id];
            }
        }

        return null;
    }

    private function settle(ProcessingBatchItem $item, ProcessingBatch $batch, array $outcome, int $started, array $ctx): void
    {
        $duration = (int) ((hrtime(true) - $started) / 1_000_000);
        $item->forceFill([
            'status'               => $outcome['status'],
            'error'                => $outcome['error'] ?? null,
            'output_asset_file_id' => $outcome['asset_file_id'] ?? null,
            'output_asset_id'      => $outcome['asset_id'] ?? null,
            'rendition_id'         => $outcome['rendition_id'] ?? null,
            'duration_ms'          => $duration,
            'finished_at'          => now(),
        ])->save();

        $event = match ($outcome['status']) {
            ProcessingBatchItem::STATUS_DONE    => 'batch.item.done',
            ProcessingBatchItem::STATUS_SKIPPED => 'batch.item.skipped',
            default                             => 'batch.item.failed',
        };
        BatchLog::write($event, $ctx + array_filter([
            'duration_ms' => $duration, 'error' => $outcome['error'] ?? null, 'exit_code' => $outcome['exit_code'] ?? null,
            'exception'   => $outcome['exception'] ?? null, 'rendition_id' => $outcome['rendition_id'] ?? null, 'reused' => $outcome['reused'] ?? null,
        ], fn ($value) => $value !== null), $outcome['status'] === ProcessingBatchItem::STATUS_FAILED ? 'warning' : 'info');

        $this->settleCounts($batch);
    }

    /** Recounts under the batch row's lock, so two workers finishing at once never leave a stale count. */
    private function settleCounts(ProcessingBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = ProcessingBatch::query()->where('id', $batch->id)->lockForUpdate()->first();
            $locked?->refreshCounts();

            if ($locked?->status === ProcessingBatch::STATUS_FINISHED || $locked?->status === ProcessingBatch::STATUS_CANCELLED) {
                BatchLog::write('batch.finished', ['batch_id' => (int) $locked->id, 'recipe' => $locked->recipe, 'status' => $locked->status,
                    'done'                                    => $locked->done, 'failed' => $locked->failed, 'skipped' => $locked->skipped, 'cancelled' => $locked->cancelled]);
            }
        });
    }

    /** The item's own directory under storage/app/dam-batch, never the system temp directory. */
    private static function makeWorkdir(): string
    {
        $root = storage_path('app/dam-batch');

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new \RuntimeException('workdir_failed');
        }

        $directory = $root.'/'.Str::uuid();

        if (! @mkdir($directory, 0700)) {
            throw new \RuntimeException('workdir_failed');
        }

        return $directory;
    }

    /** Removes the work directory, child first, unlinking (never following) any symlink inside it. */
    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            is_link($path) || ! is_dir($path) ? @unlink($path) : @rmdir($path);
        }

        @rmdir($directory);
    }
}
