<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * The per-admin analytics opt-out (§3.6.7), held in dam_analytics_opt_outs. Read once per admin per request:
 * every server emitter and the ingest endpoint ask it, so a request that emits several events asks once.
 */
final class OptOuts
{
    public const TABLE = 'dam_analytics_opt_outs';

    /** @var array<int, bool> */
    private array $known = [];

    public function has(int $adminId): bool
    {
        return $this->known[$adminId] ??= DB::table(self::TABLE)->where('admin_id', $adminId)->exists();
    }

    public function set(int $adminId, bool $optedOut): void
    {
        if ($optedOut) {
            DB::table(self::TABLE)->updateOrInsert(['admin_id' => $adminId], ['created_at' => now()]);
        } else {
            DB::table(self::TABLE)->where('admin_id', $adminId)->delete();
        }

        $this->known[$adminId] = $optedOut;
    }
}
