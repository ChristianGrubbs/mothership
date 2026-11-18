<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\DB;

/**
 * A person's removal of a field value, a label, a section pin or a collection membership from an asset (roadmap §3.4 tombstones), in
 * dam_value_decisions. While one is recorded no mapping or rule writes that value back; a person setting it again
 * clears it. Tags keep P4d's dam_asset_tag_tombstones, which AssetTagWriter reads and writes.
 */
class ValueDecisions
{
    public const KIND_FIELD = 'field';

    public const KIND_LABEL = 'label';

    public const KIND_SECTION = 'section';

    public const KIND_COLLECTION = 'collection';

    public const DECISION_REMOVED = 'removed';

    /** @param  list<int>|int  $assetIds */
    public function remove(array|int $assetIds, string $kind, int $targetId, ?int $actorId): void
    {
        $rows = array_map(fn (int $assetId) => [
            'asset_id' => $assetId, 'kind' => $kind, 'target_id' => $targetId,
            'decision' => self::DECISION_REMOVED, 'decided_by' => $actorId, 'decided_at' => now(),
        ], array_values(array_unique(array_map('intval', (array) $assetIds))));

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('dam_value_decisions')->upsert($chunk, ['asset_id', 'kind', 'target_id'], ['decision', 'decided_by', 'decided_at']);
        }
    }

    /** A person put the value back: the removal no longer holds. */
    public function clear(array|int $assetIds, string $kind, int $targetId): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', (array) $assetIds))), 500) as $chunk) {
            DB::table('dam_value_decisions')->whereIn('asset_id', $chunk)->where('kind', $kind)->where('target_id', $targetId)->delete();
        }
    }

    public function isRemoved(int $assetId, string $kind, int $targetId): bool
    {
        return DB::table('dam_value_decisions')->where('asset_id', $assetId)->where('kind', $kind)->where('target_id', $targetId)->exists();
    }

    /** The field, label or section itself is gone: its decisions mean nothing any more. */
    public function forgetTarget(string $kind, array|int $targetIds): void
    {
        DB::table('dam_value_decisions')->where('kind', $kind)->whereIn('target_id', array_map('intval', (array) $targetIds))->delete();
    }
}
