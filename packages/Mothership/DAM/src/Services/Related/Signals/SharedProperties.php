<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\SignalMatch;

/**
 * Shared property values: the same name and the same value. Narrowed by the name index, then the value; values over
 * 191 characters are prose, not a shared value, and are skipped.
 */
final class SharedProperties implements RelatedSignal
{
    private const MAX_SEED_PAIRS = 20;

    private const MAX_VALUE_LENGTH = 191;

    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        $pairs = DB::table('dam_asset_properties')->where('dam_asset_id', $seed->id)->orderBy('id')->get(['name', 'value'])
            ->filter(fn ($row) => trim((string) $row->value) !== '' && mb_strlen((string) $row->value) <= self::MAX_VALUE_LENGTH)
            ->unique(fn ($row) => $row->name."\0".$row->value)
            ->take(self::MAX_SEED_PAIRS)
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $matching = fn ($query) => $query->where(function ($where) use ($pairs) {
            foreach ($pairs as $pair) {
                $where->orWhere(fn ($one) => $one->where('name', $pair->name)->where('value', $pair->value));
            }
        })->whereIn('dam_asset_id', $scope->visible())->whereNotIn('dam_asset_id', $scope->exclude);

        $rows = $matching(DB::table('dam_asset_properties'))
            ->groupBy('dam_asset_id')
            ->select('dam_asset_id', DB::raw('COUNT(*) as shared'))
            ->orderByDesc('shared')
            ->orderByDesc('dam_asset_id')
            ->limit($scope->limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // The property name, for a card that shares exactly one ("same campaign").
        $names = $matching(DB::table('dam_asset_properties'))->whereIn('dam_asset_id', $rows->pluck('dam_asset_id'))
            ->get(['dam_asset_id', 'name'])->groupBy('dam_asset_id');

        $matches = [];

        foreach ($rows as $row) {
            $id = (int) $row->dam_asset_id;
            $count = (int) $row->shared;
            $matches[$id] = new SignalMatch(
                score: $weight * $count,
                count: $count,
                label: $count === 1 ? mb_strtolower((string) $names->get($row->dam_asset_id)?->first()?->name) : null,
            );
        }

        return $matches;
    }

    public function reason(SignalMatch $match): string
    {
        return $match->count === 1 && $match->label !== null && $match->label !== ''
            ? trans('dam::app.admin.dam.asset.related.why.property', ['name' => $match->label])
            : trans('dam::app.admin.dam.asset.related.why.properties', ['count' => $match->count]);
    }
}
