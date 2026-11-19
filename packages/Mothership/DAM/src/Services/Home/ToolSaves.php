<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records which tool a file was saved into the Library from, and who saved it. The Library's own upload stores the
 * file; this keeps the one fact that upload does not: the tool that made it.
 */
class ToolSaves
{
    private const TABLE = 'dam_tool_saves';

    /**
     * @param  array<int, int>  $assetIds
     */
    public function record(int $adminId, string $widget, array $assetIds, CarbonImmutable $now): void
    {
        $stamp = $now->toDateTimeString();
        $rows = array_map(
            fn (int $assetId): array => ['asset_id' => $assetId, 'admin_id' => $adminId, 'widget' => $widget, 'created_at' => $stamp],
            array_values(array_unique($assetIds)),
        );

        // A file is made once. If the Library answered with a file it already held, its first maker stands.
        if ($rows !== []) {
            DB::table(self::TABLE)->insertOrIgnore($rows);
        }
    }
}
