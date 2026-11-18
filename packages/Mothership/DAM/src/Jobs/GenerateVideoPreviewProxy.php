<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Services\VideoPreviewProfile;
use Mothership\DAM\Services\VideoProxyPublisher;
use Mothership\DAM\Support\LocalFile;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Transcodes an oversized video master into the 1080p preview proxy that
 * playback prefers (ADR 0003). Frame capture still reads the master, so this
 * job's output is a delivery convenience and never the asset's only copy —
 * a failure here degrades playback speed, never fidelity or availability.
 *
 * Lifecycle, deliberately simpler than GeneratePanoramaPreview: no progress
 * polling, no cancellation, no external worker contract. What it keeps from
 * that job is the part that matters — $tries = 1, so a failure is recorded
 * once with a redacted error rather than silently re-attempted by the queue,
 * and a retry is always a deliberate new rendition row.
 *
 * OPERATIONAL REQUIREMENT — this job routes onto the 'panorama' queue
 * connection, whose retry_after is derived from that job's 6-hour timeout and
 * therefore already exceeds TIMEOUT here. Never move it to a connection with
 * a shorter retry_after: the queue would re-reserve and fail the row on a
 * purely time-based check while the transcode was still running.
 */
class GenerateVideoPreviewProxy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 2 hours — long enough for a feature-length 4K source on CPU. */
    public const TIMEOUT = 7200;

    /**
     * R31: the ffmpeg process must give up before the queue worker does. The
     * worker's timer starts before handle() and a worker kill bypasses its
     * catch/finally, so ffmpeg gets TIMEOUT minus a 5-minute margin — enough
     * for the process to die and handle() to record the failure itself.
     */
    private const FFMPEG_TIMEOUT = self::TIMEOUT - 300;

    /**
     * R31: a RUNNING row older than this cannot belong to a live attempt
     * (the worker would have killed it at TIMEOUT); admission reclaims it as
     * stale rather than letting it block the asset forever. Public for the
     * proxy status the asset page shows (P6 D26), which reads the same line.
     */
    public const STALE_RUNNING_AFTER = self::TIMEOUT + 900;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    /** R33: this run's staged local copy of the source blob (ADR 0009), shared by the probe and the transcode. */
    private ?LocalFile $stagedSource = null;

    private const QUEUE_CONNECTION = 'panorama';

    private const QUEUE_NAME = 'video-preview';

    /**
     * R38: identifies this dispatch. Assigned once at construction, so it is
     * serialized into the queue payload and the reconstructed job the queue
     * hands failed() carries the same value. Admission records it on the
     * rendition (parameters.attempt), which is how failed() finds its own
     * row and never another attempt's.
     */
    public string $attemptId;

    /**
     * @param  int|null  $memberFileId  a stacked file other than the cover: the proxy is made from that file's own bytes
     *                                  (feedback #6). Null is the cover, the asset's current original.
     */
    public function __construct(protected int $assetId, public bool $force = false, protected ?int $memberFileId = null)
    {
        $this->attemptId = (string) Str::uuid();

        $this->onConnection(self::QUEUE_CONNECTION)->onQueue(self::QUEUE_NAME);
    }

    /**
     * Queues the proxy for a file just added to a stack (attached, or linked in by a merge) when it is a video that is
     * not the cover. Runs after the surrounding transaction commits; the job applies the skip rules itself.
     */
    public static function dispatchForMember(AssetFile $file): void
    {
        if (! config('mothership.video_preview.enabled') || ! $file->is_current || in_array($file->role, [...Asset::UNSTACKED_ROLES, AssetFile::ROLE_ORIGINAL], true)) {
            return;
        }

        $file->loadMissing('fileObject');
        $mime = strtolower((string) $file->fileObject?->mime_type);

        if (str_starts_with($mime, 'video/')) {
            self::dispatch((int) $file->dam_asset_id, false, (int) $file->getKey())->afterCommit();
        }
    }

    public function handle(): void
    {
        if (! config('mothership.video_preview.enabled')) {
            return;
        }

        $asset = Asset::find($this->assetId);

        if ($asset && $this->memberFileId !== null) {
            $this->handleMember($asset);

            return;
        }

        if (! $asset || ! app(VideoPreviewProfile::class)->needsProxy($asset)) {
            return;
        }

        if ($this->alreadyPublished($asset)) {
            return;
        }

        try {
            $object = app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM video preview: source file missing.', [
                'asset' => $asset->id, 'message' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $this->generate($asset, $object);
        } finally {
            // R33: the staged local copy of the source blob is released on every exit
            // path — skip, blocked admission, success, failure, or exception.
            $this->releaseStagedSource();
        }
    }

    /**
     * A stacked video other than the cover. A merge links a source's cover in as an `other` slot but never its derived
     * preview (AssetMergeService::UNLINKED_ROLES), and an attached video never had one, so such a member played raw
     * bytes or nothing. It gets a proxy made from its own content, unless it already has one or a browser can play it
     * as it is.
     */
    private function handleMember(Asset $asset): void
    {
        $member = AssetFile::query()->with('fileObject')->whereKey($this->memberFileId)
            ->where('dam_asset_id', $asset->id)->where('is_current', true)->first();

        /** @var FileObject|null $object */
        $object = $member?->fileObject;

        if ($member === null || $object === null || in_array($member->role, [...Asset::UNSTACKED_ROLES, AssetFile::ROLE_ORIGINAL], true)) {
            return;
        }

        // A slot an active merge linked in can still be split back out; a rendition naming it would pin it to the
        // destination (historyPinned()), so its proxy waits for the merge to be finalized.
        if ($this->linkedByActiveMerge($member)) {
            return;
        }

        $playback = $asset->stackMemberPlayback($member);

        // Null: not a video. A proxy of its own: nothing to add (unless forced).
        if ($playback === null || ($playback['is_proxy'] && ! $this->force)) {
            return;
        }

        try {
            $this->generate($asset, $object, $member, servedAsIs: $playback['state'] === 'ready');
        } finally {
            $this->releaseStagedSource();
        }
    }

    /**
     * The skip check, admission, and transcode for a source known to exist.
     */
    private function generate(Asset $asset, FileObject $object, ?AssetFile $member = null, bool $servedAsIs = false): void
    {
        if ($member !== null ? ($servedAsIs && $this->memberNeedsNoProxy($asset, $member)) : $this->probedSourceNeedsNoProxy($asset, $object)) {
            return;
        }

        // R24: the already-published guard and the rendition insert must be
        // atomic — otherwise two concurrent deliveries for the same asset can
        // both observe no RUNNING row and each start an expensive transcode.
        $rendition = DB::transaction(function () use ($asset, $object, $member): ?Rendition {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            // R31: under the asset lock, so reclaiming a dead attempt and
            // admitting its replacement happen as one step.
            $this->reclaimStaleRunning($locked);

            // A member's proxy may have been published (or started) while this attempt probed its source.
            if ($member !== null ? ($this->linkedByActiveMerge($member) || $this->memberProxyRunning($locked, $member) || $this->memberHasProxy($locked, $member)) : $this->alreadyPublished($locked)) {
                return null;
            }

            return Rendition::create([
                'dam_asset_id'         => $locked->id,
                // Lineage (P3a S6): the revision whose bytes this proxy is transcoded from.
                'source_asset_file_id' => $member?->getKey() ?? app(Lineage::class)->revisionWithSha(assetId: (int) $locked->id, sha256: $object->sha256)?->getKey(),
                'recipe'               => Rendition::RECIPE_PREVIEW_1080P,
                'status'               => Rendition::STATUS_RUNNING,
                'worker'               => 'ffmpeg',
                'progress'             => 0,
                'started_at'           => now(),
                'parameters'           => [
                    'max_width' => config('mothership.video_preview.max_width'),
                    'encoder'   => config('mothership.video_preview.encoder'),
                    'crf'       => config('mothership.video_preview.crf'),
                    'attempt'   => $this->attemptId,
                ],
            ]);
        });

        if ($rendition === null) {
            return;
        }

        $output = null;

        try {
            $input = $this->localSourcePath($object, $member !== null ? $member->extension() : (string) $asset->extension);
            $output = $this->temporaryFile();

            [$successful, $stderr] = $this->runFfmpeg($this->command($input, $output));

            if (! $successful || ! is_file($output) || filesize($output) === 0) {
                Log::warning('DAM video preview: ffmpeg failed.', [
                    'asset'  => $asset->id,
                    'stderr' => $stderr,
                ]);

                $this->fail($rendition, 'ffmpeg_failed');

                return;
            }

            app(VideoProxyPublisher::class)->publish($rendition, $output, $this->probe($output));
        } catch (Throwable $e) {
            Log::warning('DAM video preview: exception while generating.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);

            $this->fail($rendition, $this->errorCode($e));
        } finally {
            if ($output !== null) {
                @unlink($output);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function command(string $input, string $output): array
    {
        $maxWidth = (int) config('mothership.video_preview.max_width');
        $encoder = (string) config('mothership.video_preview.encoder');

        return [
            'ffmpeg',
            '-y',
            '-i', $input,
            '-vf', "scale='min({$maxWidth},iw)':-2",
            '-c:v', $encoder,
            ...$this->encoderQualityArgs($encoder),
            '-maxrate', (string) config('mothership.video_preview.max_rate'),
            '-bufsize', (string) config('mothership.video_preview.buf_size'),
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', (string) config('mothership.video_preview.audio_rate'),
            '-movflags', '+faststart',
            $output,
        ];
    }

    /**
     * R23: NVENC does not support `-preset veryfast` or `-crf` — it takes a
     * numbered preset and a rate-control mode with `-cq` for quality. Every
     * other encoder (libx264 default) keeps the original veryfast/crf pair.
     *
     * @return list<string>
     */
    private function encoderQualityArgs(string $encoder): array
    {
        $crf = (string) config('mothership.video_preview.crf');

        if ($encoder === 'h264_nvenc') {
            return ['-preset', 'p4', '-rc', 'vbr', '-cq', $crf];
        }

        return ['-preset', 'veryfast', '-crf', $crf];
    }

    /**
     * @param  list<string>  $command
     * @return array{0: bool, 1: string}
     */
    protected function runFfmpeg(array $command): array
    {
        $process = new Process($command, null, null, null, self::FFMPEG_TIMEOUT);
        $process->run();

        return [$process->isSuccessful(), (string) $process->getErrorOutput()];
    }

    /**
     * Runs the two ffprobe processes (video stream, audio codec) and
     * delegates all JSON interpretation to the pure parseProbe(). Every job
     * test overrides probe() itself, so keeping the parsing logic in its own
     * method (R25) is what lets it be exercised directly, with canned JSON,
     * instead of only ever through a fake.
     *
     * @return array<string, mixed>
     */
    protected function probe(string $path): array
    {
        $videoJson = $this->runFfprobe([
            'ffprobe',
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,codec_name,pix_fmt',
            '-of', 'json',
            $path,
        ]) ?? '';

        if ($this->parseProbe($videoJson, null) === []) {
            return [];
        }

        $audioJson = $this->runFfprobe([
            'ffprobe',
            '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_name',
            '-of', 'json',
            $path,
        ]);

        return $this->parseProbe($videoJson, $audioJson);
    }

    /**
     * Turns raw ffprobe JSON into the technical array VideoProxyPublisher
     * validates. Pure — no process, no filesystem — so it can be unit-tested
     * with canned JSON instead of a real ffprobe invocation (R25).
     *
     * $videoJson is the raw JSON from the v:0 probe; empty, malformed, or
     * carrying no stream all mean "no video stream" -> []. $audioJson is the
     * raw JSON from the a:0 probe, or null when that ffprobe process itself
     * failed — in that case the `audio` key is omitted entirely rather than
     * set to any value, so its absence is never read as "no audio". A
     * successful audio probe with no stream in it means the file genuinely
     * has no audio track -> `audio` = 'none'.
     *
     * @return array<string, mixed>
     */
    protected function parseProbe(string $videoJson, ?string $audioJson): array
    {
        $videoDecoded = json_decode($videoJson, true);
        $stream = is_array($videoDecoded) && is_array($videoDecoded['streams'][0] ?? null)
            ? $videoDecoded['streams'][0]
            : [];

        if ($stream === [] || $audioJson === null) {
            return $stream;
        }

        $audioDecoded = json_decode($audioJson, true);
        $audioStream = is_array($audioDecoded) && is_array($audioDecoded['streams'][0] ?? null)
            ? $audioDecoded['streams'][0]
            : null;

        $stream['audio'] = $audioStream === null ? 'none' : strtolower((string) ($audioStream['codec_name'] ?? ''));

        return $stream;
    }

    /**
     * @param  list<string>  $command
     */
    private function runFfprobe(array $command): ?string
    {
        $process = new Process($command, null, null, null, 120);
        $process->run();

        return $process->isSuccessful() ? (string) $process->getOutput() : null;
    }

    /**
     * A DONE row (unless forced) or a LIVE running row blocks admission. A
     * RUNNING row past STALE_RUNNING_AFTER is not live (R31): it must not
     * short-circuit handle() before admission gets the chance to reclaim it.
     */
    private function alreadyPublished(Asset $asset): bool
    {
        $cutoff = now()->subSeconds(self::STALE_RUNNING_AFTER);
        $liveRunning = function ($query) use ($cutoff): void {
            $query->where('status', Rendition::STATUS_RUNNING)
                ->where(fn ($started) => $started->whereNull('started_at')->orWhere('started_at', '>=', $cutoff));
        };

        // Only rows made from the current content block: after a promote or restore the old proxy is stale.
        $query = $asset->renditions()->where('recipe', Rendition::RECIPE_PREVIEW_1080P)->fromCurrentContent((int) $asset->id);

        if ($this->force) {
            return $query->where($liveRunning)->exists();
        }

        return $query->where(fn ($blocking) => $blocking->where('status', Rendition::STATUS_DONE)->orWhere($liveRunning))->exists();
    }

    /** Whether the member's slot is still linked by a merge that has not been finalized (read fresh, under the asset lock in admission). */
    private function linkedByActiveMerge(AssetFile $member): bool
    {
        return Attachment::query()->whereKey($member->attachment_id)->whereNotNull('merge_id')->exists();
    }

    /** Whether the member already plays through a proxy made from its own content (never when forced). */
    private function memberHasProxy(Asset $asset, AssetFile $member): bool
    {
        return ! $this->force && ($asset->stackMemberPlayback($member->refresh())['is_proxy'] ?? false);
    }

    /** Whether a live (not stale) proxy transcode of this very member is already running. */
    private function memberProxyRunning(Asset $asset, AssetFile $member): bool
    {
        $sources = AssetFile::query()->select('id')->where('dam_asset_id', $asset->id)->where('file_object_id', $member->file_object_id);

        return $asset->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_RUNNING)
            ->whereIn('source_asset_file_id', $sources)
            ->where(fn ($started) => $started->whereNull('started_at')->orWhere('started_at', '>=', now()->subSeconds(self::STALE_RUNNING_AFTER)))
            ->exists();
    }

    /**
     * A member's size is not recorded, so the source is probed: a file at or below 1080p that a browser decodes as it
     * is needs no proxy, provided the stored facts also let the viewer serve it as it is (the caller checks that, so a
     * stale stored codec never leaves a playable file stuck on "processing"). Unlike the cover's check this records nothing on the asset (its metadata describes the cover).
     */
    private function memberNeedsNoProxy(Asset $asset, AssetFile $member): bool
    {
        if ($this->force) {
            return false;
        }

        /** @var FileObject $object */
        $object = $member->fileObject;

        try {
            $probe = $this->probe($this->localSourcePath($object, $member->extension()));
        } catch (Throwable $e) {
            Log::warning('DAM video preview: could not probe a stacked file.', ['asset' => $asset->id, 'file' => $member->id, 'message' => $e->getMessage()]);

            return false;
        }

        $height = (int) ($probe['height'] ?? 0);

        return $height > 0
            && $height <= VideoPreviewProfile::MAX_UNPROXIED_HEIGHT
            && $asset->fileBrowserPlaybackType($member, probedTechnical: $probe) === 'video'
            // QuickTime and HEVC (the probe's codec_name, over the stored facts) are not played as they are.
            && ! $asset->fileNeedsPreviewProxy($member, probedTechnical: $probe);
    }

    /**
     * When the asset's known height is unknown, VideoPreviewProfile errs
     * toward proxying it. Before paying for a rendition row and a
     * transcode, this probes the SOURCE file directly: if it turns out to
     * already be at or below 1080p and the browser can play the original
     * as-is, there is nothing a proxy would add. An empty or failed probe
     * leaves the conservative default in place and proceeds.
     *
     * Once recorded, the probe can change what the asset IS, not only its
     * height — a 2:1 source becomes a panorama, which takes no ordinary
     * proxy — so a fresh instance is asked that one question (panorama
     * classification is memoized per instance). Playability stays judged
     * with the probe laid over stored metadata (R32): a stale stored codec
     * must never outvote what the file actually holds.
     */
    private function probedSourceNeedsNoProxy(Asset $asset, FileObject $object): bool
    {
        if (app(VideoPreviewProfile::class)->sourceHeight($asset) !== null) {
            return false;
        }

        try {
            $sourcePath = $this->localSourcePath($object, (string) $asset->extension);
        } catch (Throwable $e) {
            // An unstageable remote source keeps the conservative default and
            // proceeds; the transcode's own staging attempt records the failure.
            Log::warning('DAM video preview: could not stage the source for probing.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $probe = $this->probe($sourcePath);
        $height = (int) ($probe['height'] ?? 0);

        if ($this->recordProbedTechnical($asset, $probe)) {
            $recorded = $asset->fresh();

            if ($recorded === null || $recorded->panoramaClassification()['is_panorama']) {
                return true;
            }
        }

        // R32: judge playability on what the probe just read from the file
        // (e.g. codec_name prores/hevc), not only on empty stored metadata.
        return $height > 0
            && $height <= VideoPreviewProfile::MAX_UNPROXIED_HEIGHT
            && $asset->originalBrowserPlaybackType(probedTechnical: $probe) !== null
            && ! $asset->originalNeedsPreviewProxy(probedTechnical: $probe);
    }

    /**
     * An unknown-height asset stays unknown until something records what its
     * probe read — without this, every backfill run re-queues a small playable
     * master only for this job to skip it again, and a --limit batch never
     * gets past those ids. The codec is recorded under the probe's own
     * `codec_name` key, replacing any stale stored value, so
     * VideoPreviewProfile keeps judging playability on what the file holds
     * (browserPlaybackType accepts ANY supported codec it finds, so a stale
     * h264 kept beside the real one would outvote it): a 1080p ProRes or
     * HEVC master stays proxy-eligible.
     *
     * Written quietly: a derived technical fact, not an edit — no model events
     * (so no history/audit row) and no updated_at bump (the asset grid sorts
     * on it). Under a row lock with a fresh read, so a concurrent metadata
     * write is merged rather than lost, and dimensions extracted in the
     * meantime win over the probe's. Best-effort: a failed write is logged
     * and the skip decision proceeds on the probe alone.
     *
     * Returns whether the probe was written to the asset.
     *
     * @param  array<string, mixed>  $probe
     */
    private function recordProbedTechnical(Asset $asset, array $probe): bool
    {
        $width = (int) ($probe['width'] ?? 0);
        $height = (int) ($probe['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $codec = strtolower(trim((string) ($probe['codec_name'] ?? '')));

        try {
            return DB::transaction(function () use ($asset, $width, $height, $codec): bool {
                $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();

                if ($locked === null || app(VideoPreviewProfile::class)->sourceHeight($locked) !== null) {
                    return false;
                }

                $locked->meta_data = array_merge(
                    is_array($locked->meta_data) ? $locked->meta_data : [],
                    ['ImageWidth' => $width, 'ImageHeight' => $height],
                    $codec !== '' ? ['codec_name' => $codec] : [],
                    ['DimensionsSource' => 'ffprobe'],
                );
                $locked->timestamps = false;
                $locked->saveQuietly();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('DAM video preview: could not record probed dimensions.', [
                'asset'   => $asset->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * R31: Laravel calls this when the queue gives up on the job — above all
     * when the worker is killed at $timeout, which bypasses handle()'s
     * catch/finally and would otherwise leave the rendition RUNNING forever.
     *
     * The queue hands this a reconstructed job, not the instance that ran,
     * so there is no rendition id to go on. R38: at most one RUNNING row per
     * asset does not make that row this attempt's — a job that failed before
     * admission, or an old callback arriving after stale reclamation admitted
     * a replacement, would otherwise fail a live attempt. So only a RUNNING
     * row whose parameters.attempt equals this job's serialized attemptId is
     * touched; with no such row, this does nothing.
     */
    public function failed(?Throwable $exception = null): void
    {
        DB::transaction(function (): void {
            $running = Rendition::query()
                ->where('dam_asset_id', $this->assetId)
                ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
                ->where('status', Rendition::STATUS_RUNNING)
                ->where('parameters->attempt', $this->attemptId)
                ->lockForUpdate()
                ->get();

            foreach ($running as $rendition) {
                $rendition->update([
                    'status'      => Rendition::STATUS_FAILED,
                    'error'       => 'worker_timeout',
                    'finished_at' => now(),
                ]);
            }
        });

        Log::warning('DAM video preview: queue gave up on the job.', [
            'asset'   => $this->assetId,
            'attempt' => $this->attemptId,
        ]);
    }

    /**
     * R31: a RUNNING row whose attempt started longer ago than
     * STALE_RUNNING_AFTER is a dead worker's leftover (a hard kill that never
     * reached failed()). Marks it FAILED so admission can proceed. Called only
     * inside the asset-row-locked admission transaction.
     */
    private function reclaimStaleRunning(Asset $asset): void
    {
        $stale = $asset->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_RUNNING)
            ->where('started_at', '<', now()->subSeconds(self::STALE_RUNNING_AFTER))
            ->lockForUpdate()
            ->get();

        foreach ($stale as $rendition) {
            Log::warning('DAM video preview: reclaiming a stale running rendition.', [
                'asset'     => $asset->id,
                'rendition' => $rendition->id,
            ]);

            $rendition->update([
                'status'      => Rendition::STATUS_FAILED,
                'error'       => 'stale_running',
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * Runs inside its own transaction with a locked reload so a failure can
     * never overwrite a rendition that another process already brought to a
     * terminal state (mirrors the race guard in VideoProxyPublisher).
     */
    private function fail(Rendition $rendition, string $code): void
    {
        DB::transaction(function () use ($rendition, $code): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                Rendition::STATUS_DONE,
                Rendition::STATUS_FAILED,
                Rendition::STATUS_CANCELLED,
            ], true)) {
                return;
            }

            $locked->update([
                'status'      => Rendition::STATUS_FAILED,
                'error'       => $code,
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * Publisher failures already carry short, path-free codes; anything else
     * is reduced to a generic one so a filesystem path can never reach the
     * rendition row or an operator-facing error column.
     */
    private function errorCode(Throwable $e): string
    {
        $message = $e->getMessage();

        return preg_match('/^[a-z0-9_]{1,40}$/', $message) === 1 ? $message : 'transcode_failed';
    }

    /**
     * R33: a local filesystem path ffmpeg/ffprobe can open, staged through the seam (ADR 0009): a
     * symlink to the blob on a local disk, a downloaded copy otherwise. The source probe and the
     * transcode share one staged copy; releaseStagedSource() releases it when handle() exits.
     */
    private function localSourcePath(FileObject $object, string $extension): string
    {
        if ($this->stagedSource !== null) {
            return $this->stagedSource->path;
        }

        try {
            $this->stagedSource = app(ContentStore::class)->localPath($object, $extension);
        } catch (Throwable $e) {
            throw new \RuntimeException('source_stage_failed', previous: $e);
        }

        return $this->stagedSource->path;
    }

    private function releaseStagedSource(): void
    {
        $this->stagedSource?->release();
        $this->stagedSource = null;
    }

    protected function temporaryFile(string $prefix = 'damvid_proxy_', string $extension = 'mp4'): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);

        if ($base === false) {
            throw new \RuntimeException('temp_file_failed');
        }

        $path = $base.'.'.ltrim($extension, '.');
        @unlink($base);

        return $path;
    }
}
