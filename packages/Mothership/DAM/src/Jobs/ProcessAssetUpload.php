<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\Automation\AutomationTriggers;
use Mothership\DAM\Services\Automation\MetadataMappingService;
use Mothership\DAM\Services\CaptureGroupingService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\StackMemberState;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\PreviewKinds;
use Throwable;

#[MaxExceptions(3)]
class ProcessAssetUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RECLAIM_AFTER_SECONDS = 360;

    public int $timeout = 300;

    private const MAX_CLAIM_ATTEMPTS = 3;

    /**
     * Making another file the representative keeps the entry's custom thumbnail (it is art chosen for the entry, not
     * for any file's bytes). Any other run keeps it only while the bytes are the ones it was last processed for.
     *
     * Declared here, with a default, rather than promoted: a job queued before these properties existed unserializes
     * without them, and a typed property with no default would then be uninitialized.
     */
    protected bool $keepCustomThumbnail = false;

    /** The dispatcher replaced the original with other bytes: an entry never processed before has no marker to say so. */
    protected bool $bytesChanged = false;

    protected bool $preserveMemberArtwork = false;

    public function __construct(
        protected int $assetId,
        protected ?int $batchId = null,
        bool $keepCustomThumbnail = false,
        bool $bytesChanged = false,
        bool $preserveMemberArtwork = false,
    ) {
        $this->keepCustomThumbnail = $keepCustomThumbnail;
        $this->bytesChanged = $bytesChanged;
        $this->preserveMemberArtwork = $preserveMemberArtwork;
    }

    /** Batch jobs may be redelivered after a lease expires; the durable claim count bounds actual processing. */
    public function tries(): ?int
    {
        return $this->batchId === null ? null : 0;
    }

    public function backoff(): ?array
    {
        return $this->batchId === null ? null : [30, 120, 360];
    }

    /** A failed job may leave a durable batch open when the database was unavailable; the sweep rescues it later. */
    public function failed(Throwable $error): void
    {
        if ($this->batchId === null) {
            return;
        }

        try {
            UploadBatch::find($this->batchId)?->settle(
                failed: true,
                error: $error->getMessage(),
                staleProcessingBefore: now()->subSeconds(self::RECLAIM_AFTER_SECONDS),
            );
        } catch (Throwable $settlementError) {
            Log::warning('DAM finalization failure remains open for scheduled recovery.', [
                'batch' => $this->batchId, 'error_type' => get_class($settlementError),
            ]);
        }
    }

    public function handle(MetadataExtractionService $metadataService): void
    {
        $batch = $this->batchId ? UploadBatch::find($this->batchId) : null;

        if ($this->batchId !== null && $batch === null) {
            return;
        }

        $tracker = $batch?->tracker;

        if ($tracker && $tracker->shouldStop()) {
            return;
        }

        if ($batch !== null && ! $this->claimBatch($batch)) {
            return;
        }

        $asset = Asset::find($this->assetId);

        if (! $asset) {
            $batch?->settle(failed: false);

            return;
        }

        try {
            $disk = Directory::getAssetDisk();

            // The bytes the extraction below describes; the write re-reads them under the row lock.
            $sha = $this->currentSha($asset);
            $metaData = $this->extractMetadata($metadataService, $asset, $disk);

            // Also for a file with nothing to extract (a document, audio, a model): the art kept for the old bytes still has to go.
            if ($sha !== null) {
                $asset = $this->storeMetadata($asset, $metaData, $sha);
            }

            $this->attachAudioCoverArt($metadataService, $asset, $disk, $sha);

            app(CaptureGroupingService::class)->autoGroup(assetId: $asset->id);

            // P5b (D18, D19): embedded-metadata mappings on the fresh metadata, then the ingest rule trigger, which
            // sees what the mappings wrote. Either failing leaves the upload successful.
            try {
                app(MetadataMappingService::class)->applyAtIngest($asset->fresh() ?? $asset);
                app(AutomationTriggers::class)->ingest($asset);
            } catch (Throwable $e) {
                Log::warning('Metadata mapping or automation failed; upload remains successful.', [
                    'asset_id' => $asset->id, 'error_type' => get_class($e),
                ]);
            }

            $this->dispatchThumbnailJob($asset);

            // P5c (D16): the perceptual hash, from the preview the jobs above make (a raster's is made on the spot).
            if (config('dam.similar.enabled', true)) {
                ComputeAssetPhash::dispatch($asset->id)->afterCommit();
            }

            $batch?->settle(failed: false);
        } catch (Throwable $e) {
            Log::warning('DAM asset finalisation failed.', [
                'asset'   => $this->assetId,
                'batch'   => $this->batchId,
                'message' => $e->getMessage(),
            ]);

            $batch?->settle(failed: true, error: $e->getMessage());
        }
    }

    /** Only one delivery may process a batch; abandoned claims are reclaimed at most twice. */
    protected function claimBatch(UploadBatch $batch): bool
    {
        $retryDelay = self::RECLAIM_AFTER_SECONDS;
        $staleAt = now()->subSeconds($retryDelay);

        if (UploadBatch::query()->whereKey($batch->id)
            ->where('state', UploadBatch::STATE_PENDING)
            ->update([
                'state'             => UploadBatch::STATE_PROCESSING,
                'claim_attempts'    => 1,
                'recovery_attempts' => 0,
                'last_recovered_at' => null,
            ]) === 1) {
            return true;
        }

        if (UploadBatch::query()->whereKey($batch->id)
            ->where('state', UploadBatch::STATE_PROCESSING)
            ->where('updated_at', '<=', $staleAt)
            ->where('claim_attempts', '<', self::MAX_CLAIM_ATTEMPTS)
            ->update([
                'claim_attempts'    => DB::raw('claim_attempts + 1'),
                'recovery_attempts' => 0,
                'last_recovered_at' => null,
                'updated_at'        => now(),
            ]) === 1) {
            return true;
        }

        $current = UploadBatch::find($batch->id);

        if ($current?->state !== UploadBatch::STATE_PROCESSING) {
            return false;
        }

        if ($current->updated_at <= $staleAt && $current->claim_attempts >= self::MAX_CLAIM_ATTEMPTS) {
            $current->settle(failed: true, error: 'Processing stopped after repeated worker failures.', staleProcessingBefore: $staleAt);
        } else {
            $this->release($retryDelay);
        }

        return false;
    }

    /** The sha256 of the asset's current original, or null while it has none readable. */
    protected function currentSha(Asset $asset): ?string
    {
        try {
            return app(ContentStore::class)->current($asset->withoutRelations())->sha256;
        } catch (ContentUnavailable) {
            return null;
        }
    }

    /**
     * Stores the freshly extracted metadata over a freshly locked row. A capture or poster of the very bytes the asset
     * holds now can land while this job waits in the queue (the browser's 3D capture); writing the extraction alone
     * would erase it. A custom thumbnail stays when the bytes are the ones the entry was last processed for, or when a
     * promote asked for it; new bytes clear it, as they always did. Anything else describes the file as extracted.
     * An entry never processed has no marker: its art stays unless the dispatcher knows the bytes were replaced.
     * With nothing extracted the row keeps what it holds, minus art that belongs to other bytes.
     * If the original changed since the extraction read it, the write is skipped: the job for those bytes redoes it.
     *
     * @param  array<string, mixed>  $metaData
     */
    protected function storeMetadata(Asset $asset, array $metaData, ?string $sha): Asset
    {
        // Looked up before the row is locked: it asks the disk, and the capture of a sha never changes.
        $adoptable = ModelFileThumbnailService::adoptable(sha256: $sha, assetId: (int) $asset->id);
        // A cover whose file is still there stays the entry's cover until a new one replaces it (a failed run keeps it).
        $cover = is_array($asset->meta_data) ? ($asset->meta_data['cover_art_path'] ?? null) : null;
        $cover = is_string($cover) && $cover !== '' && Storage::disk(Directory::getAssetDisk())->exists($cover) ? $cover : null;

        $deletion = app(AssetDeletionService::class);
        $tombstones = [];

        $stored = DB::transaction(function () use ($asset, $metaData, $sha, $adoptable, $cover, $deletion, &$tombstones): Asset {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first() ?? $asset;

            if ($this->currentSha($locked) !== $sha) {
                Log::info('DAM asset finalisation: original changed during extraction; metadata not written.', ['asset' => $asset->id]);

                return $locked;
            }

            $current = is_array($locked->meta_data) ? $locked->meta_data : [];
            $kept = [];
            if (isset($current[StackMemberState::KEY])) {
                $kept[StackMemberState::KEY] = $current[StackMemberState::KEY];
            }
            foreach (['glb_primary_view', 'stack_cover'] as $memberKey) {
                if ($sha !== null && ($current[$memberKey]['sha256'] ?? null) === $sha) {
                    $kept[$memberKey] = $current[$memberKey];
                }
            }

            $marker = $current['processed_sha256'] ?? null;
            $sameBytes = $marker === null ? ! $this->bytesChanged : $marker === $sha;

            if (isset($current['custom_thumbnail']) && ($this->keepCustomThumbnail || $sameBytes)) {
                $kept['custom_thumbnail'] = $current['custom_thumbnail'];
            }

            $capture = $current['thumbnail_path'] ?? null;

            if (is_string($capture) && $sha !== null && (str_contains($capture, $sha) || str_ends_with($capture, '-'.substr($sha, 0, 12).'.jpg'))) {
                $kept['thumbnail_path'] = $capture;

                if (isset($current['thumbnail_revision'])) {
                    $kept['thumbnail_revision'] = $current['thumbnail_revision'];
                }
            }

            // These bytes were captured before (the stack's own capture, or another entry's): show that, not a blank.
            if (! isset($kept['thumbnail_path']) && ! isset($metaData['thumbnail_path'])) {
                $kept += $adoptable;
            }

            // Only for the same bytes: a cover is part of what the file holds, and a hashed one is not deleted when the content changes.
            if ($cover !== null && $sameBytes && ($current['cover_art_path'] ?? null) === $cover && ! isset($metaData['cover_art_path'])) {
                $kept['cover_art_path'] = $cover;
            }

            if ($sha !== null) {
                $kept['processed_sha256'] = $sha;
            }

            $base = $metaData !== [] ? $metaData : array_diff_key($current, array_flip(['custom_thumbnail', 'processed_sha256', 'thumbnail_path', 'thumbnail_revision']));

            $locked->update(['meta_data' => array_merge($base, $kept)]);

            // New bytes drop the custom thumbnail from the row: queue its files (raw upload and 300 px copy) in the same transaction.
            $dropped = $current['custom_thumbnail'] ?? null;

            if (is_array($dropped) && ! isset($kept['custom_thumbnail'])) {
                $disk = Directory::getAssetDisk();
                $tombstones = $deletion->queueFileDeletions(array_map(
                    fn (string $path): array => ['disk' => $disk, 'path' => $path],
                    CustomThumbnailService::storedPaths($dropped),
                ));
            }

            return $locked;
        });

        // After commit; a failed unlink stays on its durable tombstone for the retry.
        if ($tombstones !== []) {
            try {
                $deletion->processPendingFileDeletions($tombstones);
            } catch (Throwable $e) {
                Log::warning('DAM dropped custom thumbnail cleanup deferred: '.$e->getMessage(), ['asset' => $asset->id]);
            }
        }

        return $stored;
    }

    /**
     * Points the row at cover art that is already published and verified, keeping whatever else the row holds (a
     * capture, a custom thumbnail). The art sits under a name that carries its bytes, so nothing here can change the
     * cover the row points at now; only the sha comparison and the pointer swap happen under the lock, and no disk is
     * touched there. No cover file is deleted by this path, whether the swap happened, was refused (a job for older
     * bytes) or failed: another job may be about to point a row at the same name, and asset deletion reclaims covers.
     */
    protected function storeCoverArtPath(Asset $asset, string $coverPath, ?string $sha): bool
    {
        try {
            return DB::transaction(function () use ($asset, $coverPath, $sha): bool {
                $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

                if ($locked === null || $this->currentSha($locked) !== $sha) {
                    return false;
                }

                $locked->update(['meta_data' => array_merge(is_array($locked->meta_data) ? $locked->meta_data : [], ['cover_art_path' => $coverPath])]);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('DAM asset finalisation: the cover pointer could not be saved; the previous cover is kept.', ['asset' => $asset->id, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** Reads the asset's current original through the seam; a missing/unavailable blob yields no metadata. */
    protected function extractMetadata(MetadataExtractionService $service, Asset $asset, string $disk): array
    {
        $store = app(ContentStore::class);

        try {
            $object = $store->current($asset);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM asset finalisation: original unavailable for metadata extraction.', [
                'asset' => $asset->id, 'message' => $e->getMessage(),
            ]);

            return [];
        }

        $extension = strtolower((string) $asset->extension);
        $local = $store->localPath($object, $extension);

        try {
            return $service->extractMetadata(
                $local->path,
                disk: 'local',
                localPath: $local->path,
                originalFileName: $asset->file_name,
                extension: $extension,
            );
        } finally {
            $local->release();
        }
    }

    protected function attachAudioCoverArt(MetadataExtractionService $service, Asset $asset, string $disk, ?string $sha): void
    {
        if (! str_starts_with((string) $asset->mime_type, 'audio/')) {
            return;
        }

        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        if ($this->preserveMemberArtwork && (isset($meta['custom_thumbnail']) || isset($meta['stack_cover']) || isset($meta['cover_art_path']))) {
            return;
        }

        $store = app(ContentStore::class);

        try {
            $local = $store->localPath($store->current($asset), $asset->extension);
        } catch (ContentUnavailable) {
            return;
        }

        try {
            $coverData = $service->extractCoverArtData($local->path);

            if (! $coverData) {
                return;
            }

            // Written and verified before any row is locked, under a name that carries its bytes.
            $coverPath = $service->storeCoverArt($coverData, $asset->id, $disk, immutable: true);

            if ($coverPath) {
                $this->storeCoverArtPath($asset, $coverPath, $sha);
            }
        } finally {
            $local->release();
        }
    }

    protected function dispatchThumbnailJob(Asset $asset): void
    {
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        if ($this->preserveMemberArtwork && (isset($meta['custom_thumbnail']) || isset($meta['stack_cover'])
            || isset($meta['thumbnail_path']) || isset($meta['cover_art_path']))) {
            if ($asset->file_type === 'video') {
                GenerateVideoPreviewProxy::dispatch($asset->id);
            }

            return;
        }

        if ($asset->file_type === 'web') {
            BuildWebPageBundle::dispatch($asset->id, WebPageBundler::versionNumber($asset));

            return;
        }

        if ($asset->file_type === 'video' || $asset->asset_kind === 'pano360') {
            GenerateVideoThumbnail::dispatch($asset->id);

            // Playback prefers a 1080p proxy over an oversized master (ADR 0003).
            // The job applies its own skip rule, so dispatching for every video
            // is correct — a 1080p source simply returns without doing work.
            GenerateVideoPreviewProxy::dispatch($asset->id);

            return;
        }

        if (AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)) {
            GeneratePdfThumbnail::dispatch($asset->id);
        }
        if (strtolower((string) $asset->extension) === 'dng') {
            GenerateDngPreview::dispatch($asset->id)->afterCommit();
        }
        if (PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
            GenerateGameAssetThumbnail::dispatch($asset->id)->afterCommit();
        }
    }
}
