<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GeneratePanoramaPreview;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Support\PanoramaLogger;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Idempotent orchestration for on-demand 360 preview generation (Task 3).
 *
 * request() is the single write path: it locks the source asset row, computes
 * a fingerprint from the source checksum + recipe + profile + worker version,
 * and either returns an existing active/completed attempt (collapsing
 * duplicates) or creates a new queued Rendition and dispatches the Task 4
 * conversion job. Task 4 owns everything that happens once the job runs.
 */
class PanoramaPreviewService
{
    use AssetAccessControl;

    public const WORKER = 'mothership-360-cli';

    public const WORKER_VERSION = 'panoforge-5bcfbe8-avata360.1';

    public const PROFILE = [
        'width'        => 3840, 'height' => 1920, 'video_codec' => 'h264',
        'pixel_format' => 'yuv420p', 'audio_codec' => 'aac',
        'faststart'    => true, 'spherical_metadata' => true,
    ];

    /**
     * Active statuses that make a rendition "in flight" — a duplicate build
     * request collapses onto one of these (or a still-materialized DONE row)
     * instead of spawning a parallel attempt.
     */
    private const ACTIVE_STATUSES = [Rendition::STATUS_QUEUED, Rendition::STATUS_RUNNING];

    private const RETRYABLE_STATUSES = [Rendition::STATUS_FAILED, Rendition::STATUS_CANCELLED];

    /**
     * Inspection state for the panorama preview UI/API: classification,
     * current playback, the latest attempt (if any), whether job creation is
     * currently allowed, and the action booleans a client uses to enable or
     * disable buttons.
     *
     * This runs on every asset detail view (edit()/show()), panorama or not,
     * so it stays deliberately cheap for the common non-panorama case: the
     * Rendition lookup (and its output-existence filesystem stat) is skipped
     * entirely when the asset isn't classified as a panorama, and $playbackSource
     * lets a caller that already computed Asset::playbackSource() (e.g.
     * AssetController::show()) hand it in instead of triggering a second,
     * identical playbackFile() resolution. A null source is itself an answer
     * ("nothing plays"): $playbackResolved says the caller already asked.
     */
    public function state(Asset $asset, ?array $playbackSource = null, bool $playbackResolved = false): array
    {
        $classification = $asset->panoramaClassification();
        $rendition = $classification['is_panorama'] ? $this->latestRendition($asset) : null;
        $stale = $rendition !== null
            && $rendition->status === Rendition::STATUS_DONE
            && ! $this->completedOutputExists($rendition);
        $hasActive = $rendition !== null && in_array($rendition->status, self::ACTIVE_STATUSES, true);

        $this->log('info', 'panorama_state_read', [
            'asset_id'          => $asset->id,
            'is_panorama'       => $classification['is_panorama'],
            'requires_proxy'    => $classification['requires_proxy'],
            'rendition_status'  => $rendition?->status,
            'rendition_stale'   => $stale,
        ], $rendition?->id);

        return [
            'classification'      => $classification,
            'playback'            => $playbackResolved ? $playbackSource : ($playbackSource ?? $asset->playbackSource()),
            'processing_enabled'  => (bool) config('mothership.panorama_processing_enabled'),
            'can_edit'            => $this->damCanEditAsset($asset->id),
            'rendition'           => $rendition ? $this->renditionPayload($rendition, $stale) : null,
            'actions'             => [
                'can_build'   => $classification['requires_proxy'] && ! $hasActive,
                'can_rebuild' => $classification['requires_proxy'],
                'can_cancel'  => $hasActive,
                'can_retry'   => $rendition !== null && in_array($rendition->status, self::RETRYABLE_STATUSES, true),
            ],
        ];
    }

    /**
     * state() plus every named action URL. The preview component drives all
     * six endpoints, so the URLs are built once, here, rather than in each
     * of the three places that hand the component its initial data (the edit
     * view, the previous/next-navigated show() JSON, and the Blade template's
     * own fallback when a caller renders the modal without a payload).
     */
    public function payload(Asset $asset, ?array $playbackSource = null, bool $playbackResolved = false): array
    {
        return [
            'state' => $this->state($asset, $playbackSource, $playbackResolved),
            'urls'  => [
                'state'   => route('admin.dam.assets.panorama.state', $asset->id),
                'build'   => route('admin.dam.assets.panorama.build', $asset->id),
                'rebuild' => route('admin.dam.assets.panorama.rebuild', $asset->id),
                'retry'   => route('admin.dam.assets.panorama.retry', $asset->id),
                'cancel'  => route('admin.dam.assets.panorama.cancel', $asset->id),
                'mode'    => route('admin.dam.assets.panorama.mode', $asset->id),
            ],
        ];
    }

    /**
     * Create (or collapse onto) a preview conversion attempt.
     *
     * $rebuild=true always creates a fresh attempt, preserving prior rows as
     * history — it never reuses an existing fingerprint match.
     *
     * $browserRejected carries the client's verdict that this browser could
     * not actually play a source the server classified as directly playable.
     * The server only sees container metadata — a video with no codec
     * metadata classifies as playable (PanoramaClassifier::serverDirectCandidate())
     * — so without the hint a stripped-metadata HEVC is refused the very
     * conversion it needs, and the user is left with a rejected source and no
     * button. An incompatible video is exactly what the proxy pipeline exists
     * for, so the hint lifts the direct_candidate refusal and nothing else:
     * the panorama and processing-enabled checks still apply unchanged.
     *
     * $requireRetryable is retry()'s precondition (latest attempt must be
     * failed/cancelled), evaluated *inside* the row lock below rather than
     * before this method is called — a double-clicked retry or two
     * concurrent retry requests both pass a pre-lock check, since neither
     * has committed yet; checking after lockForUpdate() serializes them, so
     * the second one sees the first attempt's fresh QUEUED row and 422s
     * instead of creating a second conversion.
     */
    public function request(
        Asset $asset,
        bool $rebuild = false,
        bool $requireRetryable = false,
        bool $browserRejected = false,
    ): Rendition {
        abort_unless(config('mothership.panorama_processing_enabled'), 409, '360 preview processing is disabled.');

        $classification = $asset->panoramaClassification();
        abort_unless($classification['is_panorama'], 422, 'Asset is not classified as a 360 panorama.');
        // Once an attempt exists for this asset the "directly playable, no
        // proxy needed" argument has already been overridden — refusing the
        // rebuild or retry that follows it would strand the user on a proxy
        // they cannot replace.
        $hasPriorAttempt = $this->latestRendition($asset) !== null;

        abort_if(
            $classification['direct_candidate'] && ! $browserRejected && ! $hasPriorAttempt,
            422,
            'Asset is directly playable and does not need a generated proxy.'
        );

        return DB::transaction(function () use ($asset, $rebuild, $requireRetryable): Rendition {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($requireRetryable) {
                $latest = $this->latestRendition($locked);
                abort_unless(
                    $latest !== null && in_array($latest->status, self::RETRYABLE_STATUSES, true),
                    422,
                    'No failed or cancelled attempt to retry.'
                );
            }

            $source = $locked->files()->with('fileObject')->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
            $profile = self::PROFILE + ['source_sha256' => $source->fileObject->sha256];
            $fingerprint = hash('sha256', json_encode([
                'source'         => $source->fileObject->sha256,
                'recipe'         => Rendition::RECIPE_PANORAMA_4K,
                'profile'        => $profile,
                'worker_version' => self::WORKER_VERSION,
            ], JSON_THROW_ON_ERROR));

            if (! $rebuild) {
                $existing = Rendition::query()
                    ->where('dam_asset_id', $locked->id)
                    ->where('fingerprint', $fingerprint)
                    ->whereIn('status', [Rendition::STATUS_QUEUED, Rendition::STATUS_RUNNING, Rendition::STATUS_DONE])
                    ->latest('id')->first();
                if ($existing && ($existing->status !== Rendition::STATUS_DONE || $this->completedOutputExists($existing))) {
                    $this->log('info', 'panorama_request_collapsed', [
                        'asset_id'     => $locked->id,
                        'rendition_id' => $existing->id,
                        'status'       => $existing->status,
                    ], $existing->id);

                    return $existing;
                }
            }

            $rendition = Rendition::create([
                'dam_asset_id'         => $locked->id,
                'source_asset_file_id' => $source->id,
                'recipe'               => Rendition::RECIPE_PANORAMA_4K,
                'fingerprint'          => $fingerprint,
                'parameters'           => $profile,
                'worker'               => self::WORKER,
                'worker_version'       => self::WORKER_VERSION,
                'status'               => Rendition::STATUS_QUEUED,
                'progress'             => 0,
            ]);

            $this->log('info', 'panorama_request_queued', [
                'asset_id'     => $locked->id,
                'rendition_id' => $rendition->id,
                'rebuild'      => $rebuild,
            ], $rendition->id);

            GeneratePanoramaPreview::dispatch($rendition->id)->afterCommit();

            return $rendition;
        });
    }

    /**
     * Force a new attempt on top of a failed or cancelled one. Delegates to
     * request()'s rebuild path so the fresh row goes through the same
     * fingerprinting and dispatch as any other attempt; the failed/cancelled
     * precondition is enforced by request() itself, inside the row lock, to
     * close the TOCTOU window a pre-lock check here would leave open.
     */
    public function retry(Asset $asset): Rendition
    {
        return $this->request($asset, rebuild: true, requireRetryable: true);
    }

    /**
     * Mark the active attempt (queued or running) as cancellation-requested.
     * Task 4's worker is responsible for observing cancel_requested_at and
     * transitioning the row to STATUS_CANCELLED.
     */
    public function cancel(Asset $asset): ?Rendition
    {
        $rendition = $this->latestRendition($asset);

        if ($rendition === null || ! in_array($rendition->status, self::ACTIVE_STATUSES, true)) {
            return null;
        }

        $rendition->forceFill(['cancel_requested_at' => now()])->save();

        $this->log('info', 'panorama_cancel_requested', [
            'asset_id'     => $asset->id,
            'rendition_id' => $rendition->id,
        ], $rendition->id);

        return $rendition;
    }

    public function setMode(Asset $asset, string $mode): Asset
    {
        abort_unless(in_array($mode, Asset::PANORAMA_MODES, true), 422, 'Invalid panorama mode.');

        $asset->panorama_mode = $mode;
        $asset->save();
        $asset->forgetPanoramaClassification();

        $this->log('info', 'panorama_mode_changed', [
            'asset_id' => $asset->id,
            'mode'     => $mode,
        ]);

        return $asset->refresh();
    }

    private function latestRendition(Asset $asset): ?Rendition
    {
        return Rendition::query()
            ->where('dam_asset_id', $asset->id)
            ->where('recipe', Rendition::RECIPE_PANORAMA_4K)
            ->latest('id')
            ->first();
    }

    private function renditionPayload(Rendition $rendition, bool $stale): array
    {
        return [
            'id'                  => $rendition->id,
            'status'              => $rendition->status,
            'progress'            => $rendition->progress,
            'recipe'              => $rendition->recipe,
            'error'               => $rendition->error,
            'cancel_requested_at' => optional($rendition->cancel_requested_at)->toIso8601String(),
            'started_at'          => optional($rendition->started_at)->toIso8601String(),
            'finished_at'         => optional($rendition->finished_at)->toIso8601String(),
            'stale'               => $stale,
        ];
    }

    private function completedOutputExists(Rendition $rendition): bool
    {
        $object = $rendition->outputFileObject;

        return $object !== null
            && Storage::disk($object->disk)->exists($object->path);
    }

    /**
     * Structured JSONL at decision points and state changes, written
     * through the same bare-JSONL sink (storage/logs/panorama-preview.jsonl)
     * Task 4's queue worker uses — never Laravel's default log stack, whose
     * LineFormatter prefix would make these lines unparseable. Never carries
     * file contents or absolute filesystem paths — only ids and status.
     *
     * $renditionId, when given, makes run_id stable and shared with every
     * other log line about that same rendition — including the worker's own
     * panorama_job_* events for it — via PanoramaLogger::runIdForRendition().
     * Calls with no rendition to correlate against (e.g. setMode()) get a
     * fresh run_id, since there is nothing for them to correlate with.
     */
    private function log(string $level, string $event, array $ctx, ?int $renditionId = null): void
    {
        (new PanoramaLogger)->write(
            PanoramaLogger::runIdForRendition($renditionId),
            $level,
            $event,
            'PanoramaPreviewService',
            $ctx,
        );
    }
}
