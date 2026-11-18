<?php

namespace Mothership\DAM\Media;

use Closure;
use JsonException;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\AttemptDirectory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Production PanoramaWorker: streams the rendition's source file to an
 * attempt-specific local temp directory, shells out to Task 6's
 * `tools/media/mothership_360.py` CLI, and turns its JSONL progress/result
 * stream into progress() calls and a PanoramaWorkerResult.
 *
 * ============================================================================
 * CLI CONTRACT — this is the FIXED protocol. Task 6 must conform to this,
 * not the other way around; a mismatch here is a bug in whichever side
 * diverged from what's documented below.
 * ============================================================================
 *
 * Invocation: one subprocess per attempt, no stdin, JSON on argv:
 *
 *     python3 tools/media/mothership_360.py --request '<request-json>'
 *
 * Request JSON (the single --request argument):
 *
 *     {
 *       "protocol": 1,
 *       "rendition_id": 42,
 *       "recipe": "pano360-h264-4k",
 *       "source_path": "/tmp/mothership-panorama-42-a1b2c3d4/source.osv",
 *       "output_dir": "/tmp/mothership-panorama-42-a1b2c3d4",
 *       "parameters": {
 *         "width": 3840, "height": 1920, "video_codec": "h264",
 *         "pixel_format": "yuv420p", "audio_codec": "aac",
 *         "faststart": true, "spherical_metadata": true
 *       }
 *     }
 *
 * - `protocol` is this contract's version (PROTOCOL_VERSION below). The CLI
 *   should reject a protocol number it does not understand rather than
 *   guess at an unknown shape.
 * - `source_path` is a real local file already staged by this class before
 *   the process starts — the CLI never touches remote storage directly.
 * - `output_dir` is the SAME directory `source_path` lives in. The CLI
 *   MUST write its final output directly inside this directory — NO
 *   SUBDIRECTORIES — and MUST remove its own intermediates (two-pass logs,
 *   temp segments, partial/.tmp files) before emitting the `result` event,
 *   leaving only the one final file behind. A `result` whose path resolves
 *   into a subdirectory of `output_dir`, or outside it entirely, is
 *   rejected (see resultFromEvent()).
 * - `parameters` is `Rendition::parameters` verbatim — currently always
 *   `PanoramaPreviewService::PROFILE`, which carries EXACTLY these keys,
 *   every one of which the CLI MUST honor (produce output that actually
 *   has these properties, not merely echo them back):
 *     - `width` (int, 3840), `height` (int, 1920) — output frame size.
 *     - `video_codec` (string, "h264") — H.264/AVC video stream.
 *     - `pixel_format` (string, "yuv420p") — must match `technical.pix_fmt`
 *       in the `result` event exactly.
 *     - `audio_codec` (string, "aac") — the preferred re-encode target
 *       when audio must be transcoded; does not mean audio is required to
 *       exist (see `technical.audio` below).
 *     - `faststart` (bool, true) — moov atom must precede mdat.
 *     - `spherical_metadata` (bool, true) — inject spherical metadata (see
 *       "Spherical metadata flavor" below for exactly what that means).
 *   A future request MAY also carry `source_sha256` (present only in the
 *   fingerprinting step, not persisted onto the row, so it will not appear
 *   here today) and MAY carry keys beyond this list as the profile grows;
 *   the CLI MUST treat any key it does not recognize as a forward-
 *   compatible no-op, never an error.
 *
 * Response: JSONL on stdout, ONE JSON object per line, read incrementally
 * while the process runs (Process::start() + getIncrementalOutput()).
 * *** The CLI MUST flush stdout after every line (Python: pass
 * `flush=True` to every print(), or run the interpreter with `python3 -u`)
 * *** — stdio is block-buffered by default when writing to a pipe (as
 * opposed to a tty), so without an explicit flush every line queues in the
 * CLI's own buffer and arrives in one burst at process exit, silently
 * defeating incremental progress entirely. Three event types:
 *
 *     {"event": "progress", "percent": 54, "stage": "encoding"}
 *     {"event": "result", "path": "...", "mime": "video/mp4", "technical": {...}}
 *     {"event": "error", "code": "ffmpeg_crashed"}
 *
 * - `progress`: `percent` MUST be a JSON number (int or float), 0-100
 *   inclusive — a non-numeric or out-of-range value is DISCARDED (not
 *   coerced: this class no longer blindly (int)-casts whatever arrives,
 *   which previously admitted any malformed value as some number rather
 *   than rejecting it), so a malformed progress line is silently skipped
 *   rather than corrupting stored progress. `stage` MUST be a short slug:
 *   lowercase `[a-z0-9_-]`, at most 64 characters, and MUST NEVER be or
 *   contain a path — any other shape is sanitized down to "unknown" by
 *   this class rather than rejected outright, since a cosmetic stage label
 *   is not worth failing the whole attempt over. `percent` values across
 *   successive `progress` events MUST be monotonically non-decreasing —
 *   this class does not itself reorder or reject a backward step (the
 *   JOB clamps stored progress to `max(current, min(99, incoming))`, so a
 *   regression is absorbed rather than surfaced), but the CLI producing
 *   one is a contract violation, not an expected occurrence. The CLI MUST
 *   emit a terminal `{"event": "progress", "percent": 100, ...}` BEFORE
 *   the `result` event — `result` is the publication signal, not the
 *   completion-of-work signal, and a consumer watching progress alone
 *   should see 100 reached before the attempt is reported done.
 * - `result`: emitted AT MOST ONCE, only on success, and must be the last
 *   meaningful line. `mime` MUST be exactly `"video/mp4"` — this is not
 *   optional or example-only: `RenditionPublisher` hard-rejects any other
 *   value (`invalid_mime`), and an OMITTED `mime` silently defaults to
 *   `"application/octet-stream"` in this class's parsing, which then fails
 *   the same check anyway, just less clearly — always send it explicitly.
 *   `technical` MUST include, with these exact types:
 *     - `width` (JSON integer, 3840), `height` (JSON integer, 1920).
 *     - `codec_name` (string) — matched as a case-insensitive SUBSTRING
 *       against `h264` and `avc` (so `"h264"`, `"avc1"`, `"H.264"` after
 *       lowercasing all pass; anything not containing either fails).
 *     - `pix_fmt` (string, ffprobe-style) — MUST equal `"yuv420p"` exactly
 *       (case-insensitive).
 *     - `spherical` (JSON boolean) — MUST be `true`. See "Spherical
 *       metadata flavor" below for exactly what this asserts.
 *     - `faststart` (JSON boolean) — MUST be `true`. There is no
 *       legitimate output where this is `false`: the CLI produces the
 *       file, so it can verify the moov atom precedes mdat with complete
 *       reliability before ever emitting `result`.
 *     - `audio` (string enum) — exactly one of `"aac"` (freshly encoded
 *       AAC), `"copied"` (source audio stream copied through unchanged),
 *       or `"none"` (no audio stream in the output). Audio is NOT required
 *       to exist — 360 rig captures are routinely silent and `.osv`
 *       sources may carry no audio stream at all, so `"none"` is a
 *       legitimate, expected value. This field exists to catch a codec
 *       browsers can't decode (`opus`, `ac3`, `mp3`, etc.) surviving into
 *       the MP4 container — send any other string and `RenditionPublisher`
 *       rejects it (`invalid_audio`).
 *   `RenditionPublisher` validates every one of the above against the
 *   target profile before anything is published; a `result` event missing
 *   any of them, or reporting the wrong type for one, fails with
 *   `unreadable_technical_metadata` rather than silently defaulting.
 * - `error`: an explicit, CLI-detected failure with a short reason `code`
 *   (same slug shape as `stage`). When present, this class throws using
 *   that code as the error message, so it reaches `rendition.error` and
 *   `ctx.error_code` verbatim instead of the generic
 *   `worker_process_failed` — this is the real diagnostics channel
 *   operators need; use it whenever the CLI can identify why it failed.
 * - Any other/malformed line is silently ignored (forward-compatible) —
 *   except `error`, which always takes effect.
 *
 * Spherical metadata flavor: `spherical: true` asserts that PanoForge's
 * (Task 5's vendored `tools/media/panoforge/core/spherical.py`)
 * `inject_spherical()` has run against the output file, injecting BOTH
 * the legacy v1 flavor (Google Spherical Video XMP/RDF metadata,
 * `<GSpherical:...>`, embedded in a `uuid` box) AND the v2 flavor (`sv3d`
 * containing `svhd` + `proj`{`prhd`+`equi`} per the Spherical Video V2
 * RFC) — per the 2026-09-03 360 on-demand preview plan
 * ("inject spherical V1/V2 metadata to a second file... validate it with
 * FFprobe"). `st3d` (Stereoscopic3D) is deliberately NOT part of this
 * claim and is never written: per the RFC, `st3d` is `Mandatory: No` and
 * `sv3d` alone (with its required `svhd`+`proj` children) is sufficient
 * to mark a track spherical — `st3d` only carries stereo-layout
 * information, and its absence is the correct signal for our
 * monoscopic-only 3840x1920 equirectangular proxy, not a gap. (This
 * corrects an earlier draft of this docblock, which named `st3d` as
 * required before the pinned vendor source and the RFC were checked;
 * see Task 5's report for the verification.) `spherical: true` is a
 * claim about the v1 XMP + v2 `sv3d` flavors both being present, not
 * either one alone; `RenditionPublisher` cannot itself inspect the MP4
 * box structure to verify this, so it trusts this flag — the CLI's own
 * contract test (Task 5/6) is what actually proves `inject_spherical()`
 * wrote both.
 *
 * Exit code: read only after the process is no longer running. A nonzero
 * exit ALWAYS means failure (`worker_process_failed`, unless an explicit
 * `error` event already threw first), even if a `result` line was seen
 * beforehand. Zero exit with no `result` event ever parsed is also a
 * failure (`worker_produced_no_result`). Success requires exit 0 AND
 * exactly one valid `result` event. The CLI MUST exit non-zero on any
 * failure — a zero exit with no result is indistinguishable from a CLI bug
 * that simply forgot to emit one.
 *
 * Cancellation: entirely externally driven. This class polls $cancelled()
 * (which re-reads Rendition.cancel_requested_at fresh from the database)
 * about once every POLL_INTERVAL_MICROSECONDS while the process runs, and
 * once more immediately after it exits. On a positive check it calls
 * `$process->stop(3)` (SIGTERM, 3s grace, then SIGKILL) and throws
 * PanoramaCancelled. The CLI has no JSONL event for this and does not need
 * one — it is simply terminated. A CLI that wants to clean up gracefully
 * on termination should trap SIGTERM itself; nothing here waits for or
 * expects an acknowledgment.
 *
 * stderr: never read by this class (Process::start()'s stderr buffer,
 * which PHP holds in memory for the entire run, is left untouched). The
 * CLI MUST keep stderr near-silent — a chatty stderr just accumulates in
 * PHP's memory for hours with nothing ever consuming it. Put diagnostics
 * on stdout as a JSONL `error` event instead.
 *
 * ============================================================================
 * ATTEMPT-DIRECTORY CLEANUP CONTRACT (see PanoramaWorker's docblock for the
 * authoritative statement — restated here because this is the one concrete
 * implementation that actually creates the directory):
 * ============================================================================
 * On a SUCCESSFUL return, this class does NOT touch the attempt directory
 * — the returned PanoramaWorkerResult points inside it, and
 * GeneratePanoramaPreview removes it (recursively) only after
 * RenditionPublisher has copied the file elsewhere. On ANY thrown
 * exception (cancellation or failure), this class removes the ENTIRE
 * attempt directory itself, recursively, before the exception leaves
 * run() — the caller never receives a path to clean up in that case.
 */
final class CliPanoramaWorker implements PanoramaWorker
{
    /**
     * This contract's version, sent as `protocol` on every request. Bump
     * when the request/response shape changes in a way Task 6 must branch
     * on; the CLI should reject a version it does not recognize.
     */
    public const PROTOCOL_VERSION = 1;

    /**
     * ~1.5s between polls of process output and the cancellation flag.
     * Deliberately coarse: a 150ms poll over a potential 6-hour job is
     * ~144,000 `cancel_requested_at` queries; 1.5s keeps cancellation
     * responsive enough for a human-driven cancel button while cutting
     * that by ~10x.
     */
    private const POLL_INTERVAL_MICROSECONDS = 1_500_000;

    private const MAX_STAGE_LENGTH = 64;

    private const MAX_ERROR_CODE_LENGTH = 64;

    public function run(Rendition $rendition, Closure $progress, Closure $cancelled): PanoramaWorkerResult
    {
        $attemptDir = $this->attemptDir($rendition);
        mkdir($attemptDir, 0700, true);

        $sourcePath = $this->stageSource($rendition, $attemptDir);

        $request = [
            'protocol'     => self::PROTOCOL_VERSION,
            'rendition_id' => $rendition->id,
            'recipe'       => $rendition->recipe,
            'source_path'  => $sourcePath,
            'output_dir'   => $attemptDir,
            'parameters'   => $rendition->parameters ?? [],
        ];

        $process = new Process([
            'python3', base_path('tools/media/mothership_360.py'),
            '--request', json_encode($request, JSON_THROW_ON_ERROR),
        ]);
        $process->setTimeout(null);
        $process->start();

        $buffer = '';
        $result = null;

        try {
            while ($process->isRunning()) {
                if ($cancelled()) {
                    $process->stop(3);

                    throw new PanoramaCancelled('cancel requested');
                }

                $buffer .= $process->getIncrementalOutput();
                $result = $this->consumeLines($buffer, $attemptDir, $progress, $process) ?? $result;

                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }

            $buffer .= $process->getIncrementalOutput();
            $result = $this->consumeLines($buffer, $attemptDir, $progress, $process) ?? $result;

            if ($cancelled()) {
                throw new PanoramaCancelled('cancel requested');
            }

            if (! $process->isSuccessful()) {
                throw new RuntimeException('worker_process_failed');
            }

            if ($result === null) {
                throw new RuntimeException('worker_produced_no_result');
            }

            return $result;
        } catch (Throwable $e) {
            AttemptDirectory::removeRecursively($attemptDir);

            throw $e;
        }
    }

    /**
     * Parse whatever complete JSONL lines are currently in $buffer,
     * forwarding progress events and capturing a validated result event.
     * An `error` event throws immediately (after asking the process to
     * stop, since we are about to abandon it). Leaves any trailing partial
     * line in $buffer for the next call.
     */
    private function consumeLines(string &$buffer, string $attemptDir, Closure $progress, Process $process): ?PanoramaWorkerResult
    {
        $result = null;

        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $newline));
            $buffer = substr($buffer, $newline + 1);

            if ($line === '') {
                continue;
            }

            try {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($event) || ! isset($event['event'])) {
                continue;
            }

            if ($event['event'] === 'progress') {
                $percent = $this->parsePercent($event['percent'] ?? null);

                if ($percent !== null) {
                    $progress($percent, $this->parseStage($event['stage'] ?? ''));
                }

                continue;
            }

            if ($event['event'] === 'error') {
                // We are about to abandon this process in favor of an
                // explicit, CLI-reported failure — ask it to stop rather
                // than leaving it running unobserved for the rest of its
                // (potentially many-hour) lifetime.
                if ($process->isRunning()) {
                    $process->stop(3);
                }

                throw new RuntimeException($this->parseErrorCode($event['code'] ?? null));
            }

            if ($event['event'] === 'result') {
                $result = $this->resultFromEvent($event, $attemptDir);
            }
        }

        return $result;
    }

    /**
     * Rejects (returns null, does not coerce) anything that is not a JSON
     * number in [0, 100]. Previously this cast whatever arrived with
     * `(int)`, which silently turns any malformed value into *some*
     * number — the fix is to discard a malformed progress event rather
     * than admit a wrong one.
     */
    private function parsePercent(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $percent = (int) $value;

        return ($percent >= 0 && $percent <= 100) ? $percent : null;
    }

    /**
     * Constrains a raw `stage` value to a short slug. Anything else
     * (wrong type, too long, contains characters outside [a-z0-9_-] —
     * including a path) collapses to "unknown" rather than being rejected
     * outright, since a cosmetic label is not worth failing the attempt
     * over; GeneratePanoramaPreview::sanitizeStage() applies the same rule
     * again as a second, independent backstop before logging.
     */
    private function parseStage(mixed $value): string
    {
        if (! is_string($value)) {
            return 'unknown';
        }

        $stage = strtolower(trim($value));
        $stage = preg_replace('/[^a-z0-9_-]/', '', $stage) ?? '';
        $stage = substr($stage, 0, self::MAX_STAGE_LENGTH);

        return $stage !== '' ? $stage : 'unknown';
    }

    private function parseErrorCode(mixed $value): string
    {
        if (! is_string($value)) {
            return 'worker_reported_error';
        }

        $code = strtolower(trim($value));
        $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
        $code = substr($code, 0, self::MAX_ERROR_CODE_LENGTH);

        return $code !== '' ? $code : 'worker_reported_error';
    }

    /**
     * Validates a `result` event's `path`: it must resolve to a real file
     * that lives DIRECTLY inside the attempt directory — not outside it
     * (a realpath/symlink escape) and not nested in a subdirectory (the
     * contract requires flat output, no subdirectories).
     */
    private function resultFromEvent(array $event, string $attemptDir): PanoramaWorkerResult
    {
        $path = (string) ($event['path'] ?? '');
        $realPath = AttemptDirectory::realFileFlatWithin($attemptDir, $path);

        if ($realPath === false) {
            throw new RuntimeException('result_path_invalid');
        }

        return new PanoramaWorkerResult(
            $realPath,
            (string) ($event['mime'] ?? 'application/octet-stream'),
            is_array($event['technical'] ?? null) ? $event['technical'] : [],
        );
    }

    private function attemptDir(Rendition $rendition): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/mothership-panorama-'.$rendition->id.'-'.bin2hex(random_bytes(4));
    }

    private function stageSource(Rendition $rendition, string $attemptDir): string
    {
        // 'asset' is eager-loaded too: the source is always the ORIGINAL attachment, whose
        // extension() resolves from the owning asset's extension column, never the blob's own
        // (suffix-less) name — see AssetFile::extension().
        $source = $rendition->sourceAssetFile()->with(['fileObject', 'asset'])->firstOrFail();
        $object = $source->fileObject;

        $extension = $source->extension();
        $localPath = $attemptDir.'/source'.($extension !== '' ? '.'.$extension : '');

        // ContentStore::open() (ADR 0009), not localPath(): the CLI contract requires source_path
        // to live inside output_dir (this attempt's own directory), which ContentStore's private
        // staging directory does not.
        $stream = app(ContentStore::class)->open($object);

        $local = fopen($localPath, 'wb');

        if ($local === false) {
            fclose($stream);

            throw new RuntimeException('local_staging_failed');
        }

        stream_copy_to_stream($stream, $local);
        fclose($stream);
        fclose($local);

        return $localPath;
    }
}
