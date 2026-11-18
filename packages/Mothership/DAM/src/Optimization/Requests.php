<?php

namespace Mothership\DAM\Optimization;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Jobs\OptimizeAsset;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Support\RequestId;
use stdClass;
use Throwable;

class Requests
{
    /** A failure or expiry older than this is no longer shown inline (the row stays; only the notice stops). */
    public const STALE_AFTER_DAYS = 7;

    /** Plain-language text per failure kind. The row's `error` code never reaches the user, only these. */
    public const MESSAGE_PROVIDER_UNREACHABLE = "The optimizer on the Studio isn't running, so no copy was made. Your original is unchanged. Try again later or tell an admin.";

    public const MESSAGE_FAILED = 'Optimization failed. Your original is unchanged. Try again.';

    public const MESSAGE_WORKER_FAILED = 'The optimizer stopped before it finished, so no copy was made. Your original is unchanged. Try again.';

    public const MESSAGE_SOURCE_UNAVAILABLE = "The original file couldn't be read, so no copy was made. Your original is unchanged.";

    public const MESSAGE_EXPIRED = 'This optimization expired before it could finish. Your original is unchanged.';

    public const MESSAGE_STALLED = 'Still waiting for the optimizer to start — this usually means it isn\'t running.';

    /**
     * $attachmentId names the stacked file optimized (null for the main file) and $sourceRevisionId its revision, read
     * when the request was made; the main file's revision is looked up by checksum, as before.
     */
    public function submit(int $adminId, string $requestId, int $assetId, string $type, array $options, string $sourceSha256, ?int $attachmentId = null, ?int $sourceRevisionId = null): stdClass
    {
        ksort($options);
        $encodedOptions = json_encode($options, JSON_THROW_ON_ERROR);

        $existing = DB::table('dam_optimizations')
            ->where('admin_id', $adminId)
            ->where('request_id', $requestId)
            ->first();

        if ($existing) {
            return $this->replayOrConflict($existing, $assetId, $attachmentId, $type, $encodedOptions, $sourceSha256);
        }

        $id = (string) Str::uuid();

        return DB::transaction(function () use ($id, $adminId, $requestId, $assetId, $attachmentId, $sourceRevisionId, $type, $encodedOptions, $sourceSha256) {
            // insertOrIgnore, not insert(): two requests can both pass the read above (no row yet) and
            // race here. A plain insert() would throw on the loser's unique (admin_id, request_id)
            // violation and abort this transaction; insertOrIgnore quietly reports 0 affected rows
            // instead, so the loser can reload whichever row actually landed and apply the same
            // replay-or-409 rule a genuinely sequential retry gets (round-1 review finding, 2026-09-26).
            $inserted = DB::table('dam_optimizations')->insertOrIgnore([
                'id'              => $id,
                'asset_id'        => $assetId,
                'attachment_id'   => $attachmentId,
                'admin_id'        => $adminId,
                'request_id'      => $requestId,
                'type'            => $type,
                'options'         => $encodedOptions,
                'source_sha256'   => $sourceSha256,
                // The revision optimized, fixed now: promoting another file before this settles must not lose it.
                'source_asset_file_id' => $sourceRevisionId ?? app(Lineage::class)->revisionWithSha(assetId: $assetId, sha256: $sourceSha256)?->getKey(),
                'status'               => 'queued',
                'http_request_id'      => RequestId::current(),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            if ($inserted === 0) {
                $winner = DB::table('dam_optimizations')
                    ->where('admin_id', $adminId)
                    ->where('request_id', $requestId)
                    ->first();

                return $this->replayOrConflict($winner, $assetId, $attachmentId, $type, $encodedOptions, $sourceSha256);
            }

            // This database queue uses the reservation transaction's default connection.
            OptimizeAsset::dispatch(id: $id)
                ->onConnection('vectorization')
                ->onQueue('vectorization')
                ->beforeCommit();
            HomeTelemetry::record(event: 'optimization.queued', ctx: [
                'admin_id' => $adminId, 'generation_id' => $id, 'asset_id' => $assetId,
            ]);

            return DB::table('dam_optimizations')->where('id', $id)->first();
        });
    }

    /** The same rule a purely sequential retry gets: identical payload replays the winning row, anything
     *  else is a 409. Shared by the ordinary retry path and the insertOrIgnore race-loser path above. */
    private function replayOrConflict(?stdClass $existing, int $assetId, ?int $attachmentId, string $type, string $encodedOptions, string $sourceSha256): stdClass
    {
        abort_if($existing === null, 409, 'Request already belongs to a different asset, type or option set.');

        $existingOptions = json_decode((string) $existing->options, true) ?? [];
        ksort($existingOptions);
        abort_unless(hash_equals($existing->source_sha256, $sourceSha256)
            && (int) $existing->asset_id === $assetId
            && ($existing->attachment_id === null ? null : (int) $existing->attachment_id) === $attachmentId
            && $existing->type === $type
            && json_encode($existingOptions, JSON_THROW_ON_ERROR) === $encodedOptions, 409, 'Request already belongs to a different asset, type or option set.');

        return $existing;
    }

    /**
     * Settle a queued/processing row. $outcome ('optimized'|'no_reduction') only matters when
     * $success is true. $error carries a short failure code; 'source_changed' and 'expired' settle
     * to the `expired` status (no allowance-style refund exists here), anything else to `failed`.
     */
    public function finish(string $id, bool $success, ?string $outcome = null, ?int $oldBytes = null, ?int $newBytes = null, ?string $outputExtension = null, ?string $outputPath = null, string $error = ''): void
    {
        $initial = DB::table('dam_optimizations')->where('id', $id)->first();

        if (! $initial) {
            return;
        }

        DB::transaction(function () use ($id, $success, $outcome, $oldBytes, $newBytes, $outputExtension, $outputPath, $error) {
            $row = DB::table('dam_optimizations')->where('id', $id)->lockForUpdate()->first();

            if (! $row || in_array($row->status, ['completed', 'failed', 'expired'], true)) {
                return;
            }

            if ($error === 'expired') {
                $stale = $row->status === 'queued'
                    ? Carbon::parse($row->created_at)->lt(now()->subMinutes(30))
                    : Carbon::parse($row->updated_at)->lt(now()->subMinutes(20));

                if (! $stale) {
                    return;
                }
            }

            if ($success && $row->status !== 'processing') {
                return;
            }

            $status = 'failed';
            $finalOutcome = null;
            $assetFileId = null;

            if ($success && $outcome === 'optimized' && $outputPath !== null) {
                $assetFileId = app(AssetOutputs::class)->publish(row: $row, extension: (string) $outputExtension, localPath: $outputPath);
                $status = $assetFileId !== null ? 'completed' : 'expired';
                $finalOutcome = $assetFileId !== null ? 'optimized' : null;
            } elseif ($success && $outcome === 'optimized') {
                // Defensive: an "optimized" settlement without its staged file is a caller bug, never a
                // reason to attach nothing silently and call it complete.
                $status = 'failed';
            } elseif ($success) {
                $status = 'completed';
                $finalOutcome = 'no_reduction';
            } elseif (in_array($error, ['source_changed', 'expired'], true)) {
                $status = 'expired';
            }

            DB::table('dam_optimizations')->where('id', $id)->update([
                'status'           => $status,
                'outcome'          => $finalOutcome,
                'old_bytes'        => $status === 'completed' ? $oldBytes : $row->old_bytes,
                'new_bytes'        => $finalOutcome === 'optimized' ? $newBytes : $row->new_bytes,
                'output_extension' => $finalOutcome === 'optimized' ? $outputExtension : $row->output_extension,
                'asset_file_id'    => $assetFileId,
                'error'            => $status === 'failed' ? $error : null,
                'updated_at'       => now(),
            ]);
            HomeTelemetry::record(event: 'optimization.settled', ctx: [
                'admin_id' => (int) $row->admin_id, 'generation_id' => $id, 'status' => $status,
                'reason'   => $status === 'failed' ? substr($error, 0, 40) : null,
            ]);
            // P1b: the job's one terminal event, in the same transaction as the status it reports.
            DamEvents::emit(
                event: $status === 'completed' ? DamEvent::OptimizationSucceeded : DamEvent::OptimizationFailed,
                subjectId: (int) $row->asset_id,
                props: ['job_id' => substr(str_replace('-', '', (string) $id), 0, 32), 'kind' => (string) $row->type]
                    + ($status === 'completed' ? [] : ['error_code' => $status === 'expired' ? 'expired' : (substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $error) ?: 'unknown', 0, 48))]),
                actor: app(ActorResolver::class)->forAdminId(adminId: (int) $row->admin_id),
                businessId: (string) $id,
            );
        });
    }

    public function publicResult(stdClass $row): array
    {
        // Parsed once. A null or malformed timestamp must never fail the state endpoint and lose the message: it
        // serializes as null and the row is simply not stale.
        $changedAt = $this->parsedTimestamp(value: $row->updated_at ?? null);

        return [
            'id'               => $row->id,
            'status'           => $row->status,
            'outcome'          => $row->outcome,
            'type'             => $row->type,
            'old_bytes'        => $row->old_bytes !== null ? (int) $row->old_bytes : null,
            'new_bytes'        => $row->new_bytes !== null ? (int) $row->new_bytes : null,
            'output_extension' => $row->output_extension,
            'created_at'       => $row->created_at,
            // When the row last changed (ISO 8601): for a failed or expired row, when it settled.
            'updated_at'       => $changedAt?->toIso8601String(),
            // A failed or expired row that settled more than a week ago, by server time: the UI stops showing it. Decided here so a wrong browser clock cannot hide a fresh failure.
            'stale'            => $changedAt !== null
                && in_array($row->status, ['failed', 'expired'], true)
                && $changedAt->lt(now()->subDays(self::STALE_AFTER_DAYS)),
            'message'          => $this->publicMessage(row: $row),
        ];
    }

    private function parsedTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function publicMessage(stdClass $row): ?string
    {
        return match ($row->status) {
            'failed' => match ((string) ($row->error ?? '')) {
                'provider_unreachable' => self::MESSAGE_PROVIDER_UNREACHABLE,
                'worker_failed'        => self::MESSAGE_WORKER_FAILED,
                'source_unavailable'   => self::MESSAGE_SOURCE_UNAVAILABLE,
                default                => self::MESSAGE_FAILED,
            },
            'expired' => self::MESSAGE_EXPIRED,
            // Still queued well past the point a running worker would have claimed it: say so, but leave
            // the row alone — the existing expiry sweep still settles it, and a long job ahead of it is
            // also possible, hence the hedged wording.
            'queued' => $this->stalledInQueue(row: $row) ? self::MESSAGE_STALLED : null,
            default  => null,
        };
    }

    private function stalledInQueue(stdClass $row): bool
    {
        $after = (int) config('optimization.queued_notice_after');
        // A null or malformed created_at means the wait cannot be measured: no notice, and never a failed state read.
        $queuedAt = $this->parsedTimestamp(value: $row->created_at ?? null);

        return $after > 0 && $queuedAt !== null && $queuedAt->lt(now()->subSeconds($after));
    }
}
