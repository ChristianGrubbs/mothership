<?php

namespace Mothership\DAM\Vectorization;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\HomeTelemetry;

class Allowance
{
    public function unlimited(int $adminId): bool
    {
        return $adminId > 0 && $adminId === (int) config('vectorization.owner_admin_id', 0);
    }

    public function ensure(int $adminId): void
    {
        DB::table('dam_vectorization_allowances')->insertOrIgnore(['admin_id' => $adminId]);
    }

    public function read(int $adminId): array
    {
        $row = DB::table('dam_vectorization_allowances')->where('admin_id', $adminId)->first();
        $unlimited = $this->unlimited(adminId: $adminId);

        return [
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : (int) ($row?->remaining ?? 0),
            'reserved'  => (int) ($row?->reserved ?? 0),
            'revision'  => (int) ($row?->revision ?? 0),
        ];
    }

    public function set(int $adminId, int $remaining, int $revision): array
    {
        abort_if($remaining < 0 || $remaining > 1_000_000 || $revision < 0, 422);
        abort_if($this->unlimited(adminId: $adminId), 422, 'This account is unlimited.');

        return DB::transaction(function () use ($adminId, $remaining, $revision) {
            $this->ensure(adminId: $adminId);
            $changed = DB::table('dam_vectorization_allowances')
                ->where('admin_id', $adminId)
                ->where('revision', $revision)
                ->update(['remaining' => $remaining, 'revision' => $revision + 1]);

            abort_unless($changed === 1, 409, 'Balance changed. Reload before saving.');

            HomeTelemetry::record(event: 'vectorization.allowance_set', ctx: [
                'admin_id' => $adminId,
                'count'    => $remaining,
            ]);

            return $this->read(adminId: $adminId);
        });
    }
}
