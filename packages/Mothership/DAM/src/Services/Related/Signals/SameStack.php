<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\SignalMatch;

/** The same stack basis and key: other entries of the same campaign, product or use case (indexed columns). */
final class SameStack implements RelatedSignal
{
    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        if ($seed->stack_basis === null || trim((string) $seed->stack_key) === '') {
            return [];
        }

        $ids = DB::table('dam_assets')
            ->where('stack_basis', $seed->stack_basis)
            ->where('stack_key', $seed->stack_key)
            ->whereIn('id', $scope->visible())
            ->whereNotIn('id', $scope->exclude)
            ->orderByDesc('id')
            ->limit($scope->limit)
            ->pluck('id');

        $label = mb_strtolower(AssetStackService::label($seed->stack_basis));

        return $ids->mapWithKeys(fn ($id) => [(int) $id => new SignalMatch(score: $weight, label: $label)])->all();
    }

    public function reason(SignalMatch $match): string
    {
        return trans('dam::app.admin.dam.asset.related.why.stack', ['basis' => (string) $match->label]);
    }
}
