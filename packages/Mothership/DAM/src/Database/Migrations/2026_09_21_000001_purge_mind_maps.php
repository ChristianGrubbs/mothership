<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;

/**
 * Mind Maps feature removal (operator directive 2026-09-21): purge without export.
 *
 * Deletes every hidden mind-map Library directory (each `mindmap_folders.directory_id` and its whole
 * nested-set subtree), the map assets (asset_kind `mindmap` or linked from `mindmap_maps.asset_id`),
 * assets that live only inside those directories, their stored files, the `assets/.mindmaps` tree
 * (map documents and attachments), and finally drops all eleven mindmap tables.
 *
 * Self-contained on purpose: the MindMap package and the DAM privacy classes are deleted in the same
 * change, so the protected-set logic is re-stated here in plain queries. An asset also filed in an
 * ordinary Library folder is only unlinked from the mind-map folders, never deleted.
 *
 * Irreversible. The rollback for data is the pre-deploy database dump.
 */
return new class extends Migration
{
    /** Child-first so each drop satisfies the remaining foreign keys. */
    private const TABLES = [
        'mindmap_attachments',
        'mindmap_shares',
        'mindmap_history',
        'mindmap_collaborators',
        'mindmap_preferences',
        'mindmap_map_groups',
        'mindmap_agent_tokens',
        'mindmap_folder_user',
        'mindmap_folder_group',
        'mindmap_maps',
        'mindmap_folders',
    ];

    private const OLD_MIGRATIONS = [
        '2026_09_11_000001_create_mindmap_tables',
        '2026_09_11_000002_create_mindmap_agent_tokens_table',
        '2026_09_12_000001_create_mindmap_folders',
        '2026_09_15_000001_create_mindmap_attachments',
    ];

    /**
     * Rows commit in one explicit transaction below; storage is only touched after it commits. Where DDL is
     * transactional (PostgreSQL, SQLite) the table drops commit with the rows; MySQL/MariaDB commit
     * implicitly on DROP TABLE, which would end the transaction early, so there they run after the commit
     * and after storage cleanup.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $files = [];
        $storageDirectories = [Directory::ASSETS_DIRECTORY.'/.mindmaps'];
        $counts = ['directories' => 0, 'assets' => 0, 'unlinked_assets' => 0, 'file_objects' => 0];
        $dropInTransaction = ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        DB::transaction(function () use (&$files, &$storageDirectories, &$counts, $dropInTransaction): void {
            if (Schema::hasTable('dam_directories') && Schema::hasTable('dam_assets')) {
                Directory::withTreeLock(function () use (&$files, &$storageDirectories, &$counts): void {
                    $this->purgeLibraryRows(files: $files, storageDirectories: $storageDirectories, counts: $counts);
                });
            }
            if ($dropInTransaction) {
                $this->dropTables();
            }
            if (Schema::hasTable('migrations')) {
                DB::table('migrations')->whereIn('migration', self::OLD_MIGRATIONS)->delete();
            }
        });

        // After commit: a rolled-back purge must never leave rows pointing at deleted bytes. A failed
        // unlink is logged, not thrown: the rows are already gone, and a throw would only block boot.
        $deletedFiles = 0;
        $failures = [];
        foreach ($files as [$disk, $path]) {
            // A copied asset can share its source's stored bytes; keep any path a surviving row names.
            if ($path === '' || $this->pathStillReferenced(path: $path, prefix: false)) {
                continue;
            }
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path) ? $deletedFiles++ : $failures[] = $disk.':'.$path;
                }
            } catch (Throwable $e) {
                $failures[] = $disk.':'.$path.' ('.$e->getMessage().')';
            }
        }
        $keptDirectories = [];
        foreach (array_unique($storageDirectories) as $directory) {
            // A dual-filed asset survives with its original path, which may sit under a purged folder.
            if ($this->pathStillReferenced(path: $directory.'/', prefix: true)) {
                $keptDirectories[] = $directory;

                continue;
            }
            try {
                $assetDisk = Storage::disk(Directory::getAssetDisk());
                if ($assetDisk->exists($directory) && ! $assetDisk->deleteDirectory($directory)) {
                    $failures[] = 'directory:'.$directory;
                }
            } catch (Throwable $e) {
                $failures[] = 'directory:'.$directory.' ('.$e->getMessage().')';
            }
        }

        $context = $counts + [
            'files_deleted'       => $deletedFiles,
            'storage_directories' => array_values(array_diff(array_unique($storageDirectories), $keptDirectories)),
            'kept_directories'    => $keptDirectories,
            'failures'            => $failures,
        ];
        if ($failures) {
            Log::error('mindmap.purge.storage_incomplete', $context);
        }

        // Last, after storage cleanup: the file list lives only in memory once the rows commit, so a failed
        // drop must not strand it. What a failed drop leaves is emptied tables; a rerun purges nothing and
        // retries the drop.
        if (! $dropInTransaction) {
            $this->dropTables();
        }

        if (! $failures) {
            Log::info('mindmap.purge.completed', $context);
        }
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Exact path, or (prefix) any surviving row stored beneath it; substr keeps the match literal on every driver (SQL Server spells it substring). */
    private function pathStillReferenced(string $path, bool $prefix): bool
    {
        foreach (['dam_assets', 'dam_file_objects'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table);
            $prefix
                ? $query->whereRaw((DB::getDriverName() === 'sqlsrv' ? 'substring' : 'substr').'(path, 1, ?) = ?', [mb_strlen($path), $path])
                : $query->where('path', $path);
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    private function purgeLibraryRows(array &$files, array &$storageDirectories, array &$counts): void
    {
        $protected = [];
        $topRoots = [];
        if (Schema::hasTable('mindmap_folders')) {
            $roots = DB::table('dam_directories')
                ->whereIn('id', DB::table('mindmap_folders')->select('directory_id'))
                ->get(['id', 'parent_id', '_lft', '_rgt']);
            foreach ($roots as $root) {
                $subtree = DB::table('dam_directories')->whereBetween('_lft', [$root->_lft, $root->_rgt])->pluck('id');
                foreach ($subtree as $id) {
                    $protected[(int) $id] = true;
                }
            }
            foreach ($roots as $root) {
                $nested = $roots->contains(fn ($other) => $other->id !== $root->id
                    && $other->_lft < $root->_lft && $other->_rgt > $root->_rgt);
                if (! $nested) {
                    $topRoots[] = (int) $root->id;
                }
            }
        }
        $protected = array_keys($protected);

        $assetIds = [];
        if (Schema::hasColumn('dam_assets', 'asset_kind')) {
            $assetIds = DB::table('dam_assets')->where('asset_kind', 'mindmap')->pluck('id')->all();
        }
        if (Schema::hasTable('mindmap_maps') && Schema::hasColumn('mindmap_maps', 'asset_id')) {
            $assetIds = array_merge($assetIds, DB::table('mindmap_maps')->whereNotNull('asset_id')->pluck('asset_id')->all());
        }
        if ($protected) {
            $inside = DB::table('dam_asset_directory')->whereIn('directory_id', $protected)->distinct()->pluck('asset_id')->all();
            $alsoOutside = DB::table('dam_asset_directory')->whereIn('asset_id', $inside)
                ->whereNotIn('directory_id', $protected)->distinct()->pluck('asset_id')->all();
            $counts['unlinked_assets'] = count(array_diff($alsoOutside, $assetIds));
            $assetIds = array_merge($assetIds, array_diff($inside, $alsoOutside));
        }
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        foreach (array_chunk($assetIds, 500) as $chunk) {
            $this->purgeAssets(assetIds: $chunk, files: $files, storageDirectories: $storageDirectories, counts: $counts);
        }

        // Mind-map rows first: mindmap_folders restricts directory deletion.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        foreach ($topRoots as $rootId) {
            $root = DB::table('dam_directories')->where('id', $rootId)->first(['id', 'parent_id', 'name', '_lft', '_rgt']);
            if (! $root) {
                continue;
            }
            $storagePath = Directory::ASSETS_DIRECTORY.'/'.$this->storagePath(directoryId: $rootId);
            $subtree = DB::table('dam_directories')->whereBetween('_lft', [$root->_lft, $root->_rgt])->pluck('id')->all();
            if (Schema::hasTable('dam_shares')) {
                DB::table('dam_shares')->where('share_type', 'directory')->whereIn('target_id', $subtree)->delete();
            }
            DB::table('dam_asset_directory')->whereIn('directory_id', $subtree)->delete();
            DB::table('dam_directories')->whereIn('id', $subtree)->update(['parent_id' => null]);
            DB::table('dam_directories')->whereIn('id', $subtree)->delete();
            $width = $root->_rgt - $root->_lft + 1;
            DB::table('dam_directories')->where('_rgt', '>', $root->_rgt)->decrement('_rgt', $width);
            DB::table('dam_directories')->where('_lft', '>', $root->_rgt)->decrement('_lft', $width);
            $counts['directories'] += count($subtree);
            // Per-admin roots are all named "Mind Maps"; never remove a surviving namesake's folder.
            if (! DB::table('dam_directories')->where('parent_id', $root->parent_id)->where('name', $root->name)->exists()) {
                $storageDirectories[] = $storagePath;
            }
        }
    }

    private function purgeAssets(array $assetIds, array &$files, array &$storageDirectories, array &$counts): void
    {
        $disk = Directory::getAssetDisk();
        foreach (DB::table('dam_assets')->whereIn('id', $assetIds)->pluck('path') as $path) {
            $files[] = [$disk, (string) $path];
        }
        // Same locations AssetDeletionService clears: custom thumbnails live under the asset's own id.
        foreach ($assetIds as $assetId) {
            $storageDirectories[] = 'custom-thumbnails/'.$assetId;
        }

        $fileObjectIds = [];
        if (Schema::hasTable('dam_asset_files')) {
            $fileObjectIds = DB::table('dam_asset_files')->whereIn('dam_asset_id', $assetIds)->pluck('file_object_id')->all();
        }
        if (Schema::hasTable('dam_renditions')) {
            $fileObjectIds = array_merge($fileObjectIds, DB::table('dam_renditions')->whereIn('dam_asset_id', $assetIds)
                ->whereNotNull('output_file_object_id')->pluck('output_file_object_id')->all());
        }

        if (Schema::hasTable('dam_shares')) {
            DB::table('dam_shares')->where('share_type', 'asset')->whereIn('target_id', $assetIds)->delete();
        }
        if (Schema::hasTable('dam_asset_relationships')) {
            DB::table('dam_asset_relationships')->where(fn ($q) => $q
                ->whereIn('from_asset_id', $assetIds)->orWhereIn('to_asset_id', $assetIds))->delete();
        }
        foreach (['dam_asset_resource_mappings', 'dam_asset_versions', 'dam_renditions'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('dam_asset_id', $assetIds)->delete();
            }
        }
        if (Schema::hasTable('mindmap_maps') && Schema::hasColumn('mindmap_maps', 'asset_id')) {
            DB::table('mindmap_maps')->whereIn('asset_id', $assetIds)->update(['asset_id' => null]);
        }
        $counts['assets'] += DB::table('dam_assets')->whereIn('id', $assetIds)->delete();

        // Content-addressed objects are shared; delete only the ones nothing references any more.
        $fileObjectIds = array_values(array_unique(array_map('intval', $fileObjectIds)));
        if ($fileObjectIds && Schema::hasTable('dam_file_objects')) {
            $stillUsed = array_merge(
                DB::table('dam_asset_files')->whereIn('file_object_id', $fileObjectIds)->pluck('file_object_id')->all(),
                Schema::hasTable('dam_renditions')
                    ? DB::table('dam_renditions')->whereIn('output_file_object_id', $fileObjectIds)->pluck('output_file_object_id')->all()
                    : [],
            );
            $orphans = array_values(array_diff($fileObjectIds, array_map('intval', $stillUsed)));
            foreach (DB::table('dam_file_objects')->whereIn('id', $orphans)->get(['disk', 'path', 'sha256']) as $object) {
                $files[] = [(string) $object->disk, (string) $object->path];
                $files[] = [(string) $object->disk, 'assets/.objects/'.substr((string) $object->sha256, 0, 2).'/'.$object->sha256];
            }
            $counts['file_objects'] += DB::table('dam_file_objects')->whereIn('id', $orphans)->delete();
        }
    }

    /** Same shape as Directory::generatePath(): ancestor names from the Library root down. */
    private function storagePath(int $directoryId): string
    {
        $node = DB::table('dam_directories')->where('id', $directoryId)->first(['_lft', '_rgt']);

        return DB::table('dam_directories')->where('_lft', '<=', $node->_lft)->where('_rgt', '>=', $node->_rgt)
            ->orderBy('_lft')->pluck('name')->implode('/');
    }

    public function down(): void
    {
        // Irreversible purge: restore the pre-deploy database dump and storage backup instead.
    }
};
