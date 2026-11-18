<?php

declare(strict_types=1);

namespace Mothership\DAM\Helpers;

use Closure;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Database\Seeders\DamDemoDataSeeder;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Throwable;

class DamDemoDataInstaller
{
    public function seed(?Closure $reporter = null, bool $force = false): array
    {
        $report = $reporter ?? static fn (string $message) => null;

        if (! $force && $this->isAlreadySeeded()) {
            $report('DAM demo data already seeded. Use --force to re-seed.');

            return ['success' => true, 'skipped' => true];
        }

        try {
            if ($force) {
                $report('Clearing existing DAM demo data...');
                $this->clearDemoData();
            }

            $report('Seeding DAM demo directories and assets...');
            app(DamDemoDataSeeder::class)->run();

            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function isAlreadySeeded(): bool
    {
        try {
            $root = DB::table('dam_directories')->whereNull('parent_id')->first();

            if (! $root) {
                return false;
            }

            return DB::table('dam_directories')
                ->where('parent_id', $root->id)
                ->where('name', 'Accessories')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    protected function clearDemoData(): void
    {
        $prefix = Directory::ASSETS_DIRECTORY.'/Root/';

        // A demo asset in the trash (P2 S4) still counts: its Library path is the one it was trashed from.
        $assetIds = DB::table('dam_assets')
            ->where(fn ($query) => $query->where('path', 'like', $prefix.'%')->orWhere('trashed_from_path', 'like', $prefix.'%'))
            ->pluck('id');

        if ($assetIds->isNotEmpty()) {
            // Each demo asset is tombstoned by reference count through the same entry point a
            // single-asset delete uses (ADR 0009 slice 3), instead of a raw delete that leaves its
            // FileObject an orphan no tombstone was ever queued for.
            $assetDeletionService = app(AssetDeletionService::class);

            foreach (Asset::withTrashed()->whereIn('id', $assetIds)->get() as $asset) {
                $assetDeletionService->delete($asset, force: true);
            }
        }

        $root = DB::table('dam_directories')->whereNull('parent_id')->first();

        if ($root) {
            $childIds = DB::table('dam_directories')
                ->where('parent_id', $root->id)
                ->pluck('id');

            foreach ($childIds as $childId) {
                $this->deleteDirectoryRecursive((int) $childId);
            }
        }

        DB::table('dam_tags')
            ->whereIn('name', ['Accessories', 'Audio and Video', 'Clothes', 'Documents'])
            ->delete();

        Directory::fixTree();
    }

    protected function deleteDirectoryRecursive(int $id): void
    {
        $childIds = DB::table('dam_directories')->where('parent_id', $id)->pluck('id');

        foreach ($childIds as $childId) {
            $this->deleteDirectoryRecursive((int) $childId);
        }

        DB::table('dam_directories')->where('id', $id)->delete();
    }
}
