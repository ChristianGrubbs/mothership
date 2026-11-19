<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records when a person opens a tool, and reads it back for the Tools page. A use is the tool's own page being
 * served, so it counts from wherever the tool was opened.
 */
class HomeToolUses
{
    private const TABLE = 'dam_home_tool_uses';

    public function record(int $adminId, string $widget, CarbonImmutable $now): void
    {
        $stamp = $now->toDateTimeString();

        DB::table(self::TABLE)->upsert(
            [['admin_id' => $adminId, 'widget' => $widget, 'last_used_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]],
            ['admin_id', 'widget'],
            ['last_used_at', 'updated_at'],
        );
    }

    /**
     * When this person last opened each tool they have opened, keyed by tool id.
     *
     * @return array<string, CarbonImmutable>
     */
    public function lastUsed(int $adminId): array
    {
        return DB::table(self::TABLE)
            ->where('admin_id', $adminId)
            ->pluck('last_used_at', 'widget')
            ->map(fn (string $stamp): CarbonImmutable => CarbonImmutable::parse($stamp))
            ->all();
    }
}
