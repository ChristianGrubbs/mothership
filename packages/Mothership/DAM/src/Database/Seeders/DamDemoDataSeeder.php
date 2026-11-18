<?php

declare(strict_types=1);

namespace Mothership\DAM\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mothership\Core\Helpers\Database\DatabaseSequenceHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;

class DamDemoDataSeeder extends Seeder
{
    private array $directoryTree = [
        'Accessories' => [
            'Bags'       => [],
            'Belts'      => [],
            'Hats'       => [],
            'Scarves'    => [],
            'Sunglasses' => [],
        ],
        'Audio and Video' => [
            'Headphones'   => [],
            'Loudspeakers' => [],
        ],
        'Clothes' => [
            'Accessories' => [
                'Caps'   => [],
                'Gloves' => [],
                'Ties'   => [],
            ],
        ],
        'Documents' => [],
    ];

    public function run(): void
    {
        $root = Directory::whereNull('parent_id')->where('name', 'Root')->first();

        if (! $root) {
            $this->command?->error('Root directory not found. Run DirectoryTableSeeder first.');

            return;
        }

        if ($root->children()->where('name', 'Accessories')->exists()) {
            return;
        }

        $sourceRoot = __DIR__.'/../Data/demo-assets/Root';

        if (! File::isDirectory($sourceRoot)) {
            $this->command?->error('Demo assets not found at: '.$sourceRoot);

            return;
        }

        $now = Carbon::now();

        $directoryMap = array_merge(
            ['' => $root],
            $this->createDirectoryTree($this->directoryTree, $root)
        );

        Directory::fixTree();

        $this->seedAssets($sourceRoot, $directoryMap, $now);
        $this->seedTags($directoryMap, $now);

        DatabaseSequenceHelper::fixSequences(['dam_directories', 'dam_assets', 'dam_tags']);
    }

    protected function createDirectoryTree(array $tree, Directory $parent, string $prefix = ''): array
    {
        $map = [];

        foreach ($tree as $name => $children) {
            $dir = Directory::create(['name' => $name, 'parent_id' => $parent->id]);
            $key = $prefix !== '' ? $prefix.'/'.$name : $name;
            $map[$key] = $dir;

            if (! empty($children)) {
                $map = array_merge($map, $this->createDirectoryTree($children, $dir, $key));
            }
        }

        return $map;
    }

    protected function seedAssets(string $sourceRoot, array $directoryMap, Carbon $now): void
    {
        $disk = Directory::getAssetDisk();
        $pivotRows = [];

        foreach (File::allFiles($sourceRoot) as $file) {
            $relDir = str_replace('\\', '/', $file->getRelativePath());
            $directory = $directoryMap[$relDir] ?? null;

            if (! $directory) {
                continue;
            }

            $fileName = $file->getFilename();
            $storagePath = Directory::ASSETS_DIRECTORY.'/Root/'.($relDir !== '' ? $relDir.'/' : '').$fileName;
            $mimeType = mime_content_type($file->getRealPath()) ?: 'application/octet-stream';
            $extension = strtolower($file->getExtension());

            // Ingest the bundled file into a blob before creating the model (ADR 0009 slice 3), so
            // withGraphContent hands CompoundAssetObserver the FileObject during the created event --
            // no on-disk Root/ tree, $storagePath is a logical Library path only.
            $object = app(ContentStore::class)->putLocal(
                disk: $disk,
                localPath: $file->getRealPath(),
                mimeType: $mimeType,
                extension: $extension,
            );

            $asset = new Asset([
                'file_name'  => $fileName,
                'file_type'  => $this->resolveFileType($mimeType),
                'file_size'  => $file->getSize(),
                'mime_type'  => $mimeType,
                'extension'  => $extension,
                'path'       => $storagePath,
                'meta_data'  => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $asset->withGraphContent($object);
            $asset->save();

            $pivotRows[] = [
                'asset_id'     => $asset->id,
                'directory_id' => $directory->id,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (! empty($pivotRows)) {
            // sharedly(), because this bulk attach has no transaction of its own: ADR 0010's fence wants
            // the DAM_ACCESS_MODEL row FOR SHARE first, and the seeded files take their folders' access
            // lists in the same transaction as the pivot insert (spec D3).
            app(AccessModel::class)->sharedly(function () use ($pivotRows): void {
                DB::table('dam_asset_directory')->insert($pivotRows);

                app(AccessListWriter::class)->inheritAssets(array_column($pivotRows, 'asset_id'));
            });
        }
    }

    protected function seedTags(array $directoryMap, Carbon $now): void
    {
        foreach (array_keys($this->directoryTree) as $tagName) {
            if (DB::table('dam_tags')->where('name', $tagName)->exists()) {
                continue;
            }

            $tagId = DB::table('dam_tags')->insertGetId([
                'name'       => $tagName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $dirIds = collect($directoryMap)
                ->filter(fn ($dir, $key) => $key === $tagName || str_starts_with($key, $tagName.'/'))
                ->map(fn ($dir) => $dir->id)
                ->values()
                ->toArray();

            if (empty($dirIds)) {
                continue;
            }

            $assetIds = DB::table('dam_asset_directory')
                ->whereIn('directory_id', $dirIds)
                ->pluck('asset_id')
                ->unique()
                ->values()
                ->toArray();

            $tagPivotRows = array_map(fn ($assetId) => [
                'asset_id'   => $assetId,
                'tag_id'     => $tagId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $assetIds);

            if (! empty($tagPivotRows)) {
                DB::table('dam_asset_tag')->insertOrIgnore($tagPivotRows);
            }
        }
    }

    protected function resolveFileType(string $mimeType): string
    {
        if (str_contains($mimeType, 'image')) {
            return 'image';
        }

        if (str_contains($mimeType, 'video')) {
            return 'video';
        }

        if (str_contains($mimeType, 'audio')) {
            return 'audio';
        }

        return 'document';
    }
}
