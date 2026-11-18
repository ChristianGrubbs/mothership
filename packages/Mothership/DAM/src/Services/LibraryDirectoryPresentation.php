<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Directory;

/** Keeps implementation-only directory wrappers out of the user-facing tree. */
class LibraryDirectoryPresentation
{
    public function structuralIds(): array
    {
        // The container can resolve multiple service instances during one tree response.
        // Store identity on the request, never a process-static cache in long-lived workers.
        $attributes = request()->attributes;
        $key = self::class.'.structural_ids';
        if ($attributes->has($key)) {
            return $attributes->get($key);
        }
        $root = Directory::query()->whereNull('parent_id')->where('name', 'Root')->orderBy('id')->first(['id']);
        if (! $root) {
            $attributes->set($key, []);

            return [];
        }

        $importRoot = Directory::query()
            ->from('dam_directories as candidate')
            ->where('candidate.parent_id', $root->id)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('dam_directories as descendant')
                    ->join('dam_asset_directory as membership', 'membership.directory_id', '=', 'descendant.id')
                    ->join('dam_assets as asset', 'asset.id', '=', 'membership.asset_id')
                    ->whereColumn('descendant._lft', '>=', 'candidate._lft')
                    ->whereColumn('descendant._rgt', '<=', 'candidate._rgt')
                    ->whereNotNull('asset.legacy_ref');
            })
            ->orderBy('candidate.id')
            ->value('candidate.id');

        $ids = array_values(array_filter([(int) $root->id, $importRoot ? (int) $importRoot : null]));
        $attributes->set($key, $ids);

        return $ids;
    }
}
