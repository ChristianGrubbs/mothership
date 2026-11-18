<?php

namespace Mothership\DAM\Vectorization;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Support\RequestId;
use stdClass;

class Requests
{
    public function __construct(private Allowance $allowance) {}

    public function submit(int $adminId, string $requestId, string $inputPath, string $sha, ?int $assetId = null, ?string $format = null): stdClass
    {
        return DB::transaction(function () use ($adminId, $requestId, $inputPath, $sha, $assetId, $format) {
            $this->allowance->ensure(adminId: $adminId);
            $wallet = DB::table('dam_vectorization_allowances')
                ->where('admin_id', $adminId)
                ->lockForUpdate()
                ->first();
            $existing = DB::table('dam_vectorizations')
                ->where('admin_id', $adminId)
                ->where('request_id', $requestId)
                ->first();

            if ($existing) {
                abort_unless(hash_equals($existing->input_sha, $sha), 409, 'Request already belongs to a different image.');
                abort_unless(($existing->source_asset_id === null ? null : (int) $existing->source_asset_id) === $assetId
                    && $existing->output_format === $format, 409, 'Request already belongs to another asset or format.');

                return $existing;
            }

            $charged = ! $this->allowance->unlimited(adminId: $adminId);
            abort_if($charged && $wallet->remaining < 1, 403, 'No vectorizations remaining.');
            DB::table('dam_vectorization_allowances')->where('admin_id', $adminId)->update([
                'remaining' => (int) $wallet->remaining - ($charged ? 1 : 0),
                'reserved'  => (int) $wallet->reserved + 1,
                'revision'  => (int) $wallet->revision + 1,
            ]);
            $id = (string) Str::uuid();
            DB::table('dam_vectorizations')->insert([
                'id'              => $id,
                'admin_id'        => $adminId,
                'request_id'      => $requestId,
                'input_sha'       => $sha,
                'input_path'      => $inputPath,
                'source_asset_id' => $assetId,
                // The revision traced, fixed now: promoting another file before this settles must not lose it.
                'source_asset_file_id' => $assetId === null ? null : app(Lineage::class)->revisionWithSha(assetId: $assetId, sha256: $sha)?->getKey(),
                'output_format'        => $format,
                'charged'              => $charged,
                'status'               => 'queued',
                'http_request_id'      => RequestId::current(),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // This database queue uses the reservation transaction's default connection.
            VectorizeImage::dispatch(id: $id)
                ->onConnection('vectorization')
                ->onQueue('vectorization')
                ->beforeCommit();
            HomeTelemetry::record(event: 'vectorization.reserved', ctx: [
                'admin_id'      => $adminId,
                'generation_id' => $id,
            ]);

            return DB::table('dam_vectorizations')->where('id', $id)->first();
        });
    }

    public function finish(string $id, bool $success, string $code = ''): void
    {
        $initial = DB::table('dam_vectorizations')->where('id', $id)->first();

        if (! $initial) {
            return;
        }

        DB::transaction(function () use ($initial, $id, $success, $code) {
            $wallet = DB::table('dam_vectorization_allowances')
                ->where('admin_id', $initial->admin_id)
                ->lockForUpdate()
                ->first();
            $row = DB::table('dam_vectorizations')->where('id', $id)->lockForUpdate()->first();

            if (! $row || ! $wallet || in_array($row->status, ['completed', 'failed'], true)) {
                return;
            }

            if ($code === 'expired') {
                $stale = $row->status === 'queued'
                    ? Carbon::parse($row->created_at)->lt(now()->subMinutes(30))
                    : Carbon::parse($row->updated_at)->lt(now()->subMinutes(15));

                if (! $stale) {
                    return;
                }
            }

            if ($success && $row->status !== 'processing') {
                return;
            }

            if ($success) {
                app(AssetOutputs::class)->publish(row: $row);
            }

            DB::table('dam_vectorization_allowances')->where('admin_id', $row->admin_id)->update([
                'remaining' => (int) $wallet->remaining + (! $success && $row->charged ? 1 : 0),
                'reserved'  => max(0, (int) $wallet->reserved - 1),
                'revision'  => (int) $wallet->revision + 1,
            ]);
            DB::table('dam_vectorizations')->where('id', $id)->update([
                'status'     => $success ? 'completed' : 'failed',
                'error_code' => $success ? null : $code,
                'updated_at' => now(),
            ]);
            HomeTelemetry::record(event: 'vectorization.settled', ctx: [
                'admin_id'      => (int) $row->admin_id,
                'generation_id' => $id,
                'status'        => $success ? 'completed' : 'failed',
            ]);
            // P1b: the job's one terminal event, in the same transaction as the status it reports.
            DamEvents::emit(
                event: $success ? DamEvent::VectorizationSucceeded : DamEvent::VectorizationFailed,
                subjectId: $row->source_asset_id === null ? null : (int) $row->source_asset_id,
                props: ['job_id' => substr(str_replace('-', '', (string) $id), 0, 32)]
                    + ($success ? [] : ['error_code' => substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $code) ?: 'unknown', 0, 48)]),
                actor: app(ActorResolver::class)->forAdminId(adminId: (int) $row->admin_id),
                businessId: (string) $id,
            );
        });
    }

    public function publicResult(stdClass $row): array
    {
        $complete = $row->status === 'completed';

        return [
            'id'         => $row->id,
            'status'     => $row->status,
            'format'     => $row->output_format ?? null,
            'created_at' => $row->created_at,
            'message'    => $row->status === 'failed' ? 'Vectorization failed. Your allowance was returned.' : null,
            'svg_url'    => $complete ? route('admin.home.vectorization.file', ['id' => $row->id, 'format' => 'svg']) : null,
            'pdf_url'    => $complete ? route('admin.home.vectorization.file', ['id' => $row->id, 'format' => 'pdf']) : null,
        ];
    }
}
