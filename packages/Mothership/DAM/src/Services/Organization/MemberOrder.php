<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Support\Facades\DB;

/**
 * P4 (D4, D11): the manual order inside a label, a board or a section's pins. Both tables share one shape
 * (container, asset, position), so position sorting has one implementation (roadmap §3.2).
 */
final class MemberOrder
{
    public function __construct(
        private readonly string $table,
        private readonly string $containerColumn,
        private readonly int $containerId,
    ) {}

    public static function label(int $labelId): self
    {
        return new self(table: 'dam_label_assets', containerColumn: 'label_id', containerId: $labelId);
    }

    public static function sectionPins(int $sectionId): self
    {
        return new self(table: 'dam_section_pins', containerColumn: 'section_id', containerId: $sectionId);
    }

    /** @return array<int, int> every member, hidden ones included, in manual order */
    public function ids(): array
    {
        return DB::table($this->table)->where($this->containerColumn, $this->containerId)
            ->orderBy('position')->orderBy('id')->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Appends the assets that are not members yet, in the order given; existing members keep their place and their
     * source. `$source` is who added them (P5b, roadmap §3.4): manual, or a rule's `rule:<id>@v<n>`.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, int> the ids added
     */
    public function append(array $assetIds, ?int $addedBy, string $source = 'manual'): array
    {
        return DB::transaction(function () use ($assetIds, $addedBy, $source): array {
            $existing = $this->ids();
            $next = $existing === [] ? 0 : (int) DB::table($this->table)->where($this->containerColumn, $this->containerId)->max('position') + 1;
            $added = array_values(array_diff($assetIds, $existing));
            $now = now();

            foreach ($added as $offset => $assetId) {
                DB::table($this->table)->insertOrIgnore([
                    $this->containerColumn => $this->containerId,
                    'asset_id'             => $assetId,
                    'position'             => $next + $offset,
                    'added_by'             => $addedBy,
                    'source'               => $source,
                    'created_at'           => $now,
                ]);
            }

            return $added;
        });
    }

    /** @param  array<int, int>  $assetIds */
    public function remove(array $assetIds): int
    {
        if ($assetIds === []) {
            return 0;
        }

        return DB::table($this->table)->where($this->containerColumn, $this->containerId)->whereIn('asset_id', $assetIds)->delete();
    }

    /**
     * Moves one member to a 0-based position among the members the viewer can see. Hidden members keep their place
     * relative to each other, so a reorder by one viewer never reveals or reshuffles what another sees alone.
     *
     * @param  array<int, int>  $visibleIds  the members the viewer may list, in manual order
     */
    public function move(int $assetId, int $position, array $visibleIds): void
    {
        DB::transaction(function () use ($assetId, $position, $visibleIds): void {
            $all = array_values(array_filter($this->ids(), fn (int $id) => $id !== $assetId));
            $visible = array_values(array_filter($visibleIds, fn (int $id) => $id !== $assetId));
            $position = max(0, min($position, count($visible)));

            if ($position >= count($visible)) {
                // After the last visible member.
                $anchor = $visible === [] ? count($all) : array_search($visible[count($visible) - 1], $all, true) + 1;
            } else {
                $anchor = array_search($visible[$position], $all, true);
            }

            array_splice($all, (int) $anchor, 0, [$assetId]);

            foreach ($all as $index => $id) {
                DB::table($this->table)->where($this->containerColumn, $this->containerId)->where('asset_id', $id)->update(['position' => $index]);
            }
        });
    }
}
