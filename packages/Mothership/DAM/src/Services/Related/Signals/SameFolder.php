<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\SignalMatch;

/**
 * The same folder, the weakest signal. Only folders the viewer may open count, so "same folder" never tells a viewer
 * who sees a file through its own list that two files share a folder they cannot open.
 */
final class SameFolder implements RelatedSignal
{
    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        $permissions = app(DirectoryPermissionService::class);
        $directoryIds = DB::table('dam_asset_directory')->where('asset_id', $seed->id)->pluck('directory_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $permissions->bypass() || $permissions->canAccess($id))
            ->values()->all();

        if ($directoryIds === []) {
            return [];
        }

        $ids = DB::table('dam_asset_directory')
            ->whereIn('directory_id', $directoryIds)
            ->whereIn('asset_id', $scope->visible())
            ->whereNotIn('asset_id', $scope->exclude)
            ->select('asset_id')->distinct()
            ->orderByDesc('asset_id')
            ->limit($scope->limit)
            ->pluck('asset_id');

        return $ids->mapWithKeys(fn ($id) => [(int) $id => new SignalMatch(score: $weight)])->all();
    }

    public function reason(SignalMatch $match): string
    {
        return trans('dam::app.admin.dam.asset.related.why.folder');
    }
}
