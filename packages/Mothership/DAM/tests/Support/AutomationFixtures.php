<?php

/**
 * P5b (D18, D19) fixtures, shared by the mapping, rule, webhook and REST spec files. Each requires this file itself
 * (Pest loads only the files it is given), as the P4 organization fixtures do.
 */

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

if (! function_exists('automationAsset')) {
    /** An asset filed in `$directory`, with optional raw ExifTool data. */
    function automationAsset(Directory $directory, string $name, array $exif = [], array $attributes = []): Asset
    {
        $fileName = $name.'-'.uniqid().'.jpg';

        // Created and filed in one transaction, as the upload publisher does, so the created event sees its folder.
        return DB::transaction(function () use ($directory, $fileName, $exif, $attributes): Asset {
            $asset = Asset::factory()->create([
                'file_name' => $fileName,
                'path'      => 'assets/'.$directory->name.'/'.$fileName,
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'meta_data' => $exif === [] ? [] : ['exif' => $exif],
                ...$attributes,
            ]);
            $directory->assets()->attach($asset->id);

            return $asset;
        });
    }
}

if (! function_exists('automationField')) {
    function automationField(string $code, string $type = 'text', array $attributes = []): MetadataField
    {
        return MetadataField::query()->create(['code' => $code, 'label' => ucfirst($code), 'type' => $type, ...$attributes]);
    }
}

if (! function_exists('automationRule')) {
    /** A rule owned by `$owner` (the signed-in admin by default), created through the engine as the UI does. */
    function automationRule(array $attributes, ?Admin $owner = null): AutomationRule
    {
        return app(AutomationEngine::class)->create(input: ['name' => 'Rule '.uniqid(), 'trigger_type' => 'manual', ...$attributes], ownerId: (int) ($owner ?? auth()->guard('admin')->user())->id);
    }
}

if (! function_exists('automationMember')) {
    /**
     * An admin on a custom role with `$permissions`, seeing only `$granted` folders (the grants access model). Not
     * signed in.
     */
    function automationMember(array $permissions, Directory ...$granted): Admin
    {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

        foreach ($granted as $directory) {
            DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
        app(AccessModel::class)->flush();
        app(DirectoryPermissionService::class)->flush();

        return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
    }
}

if (! function_exists('automationRowCounts')) {
    /** Row counts of every table a mapping or rule may write, to prove a dry run or a replay writes nothing. */
    function automationRowCounts(): array
    {
        $counts = [];

        foreach (['dam_asset_tag', 'dam_tags', 'dam_asset_properties', 'dam_asset_property_options', 'dam_label_assets', 'dam_section_pins',
            'dam_value_decisions', 'dam_asset_tag_tombstones', 'dam_collection_assets', 'dam_automation_runs', 'dam_automation_run_actions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}

if (! function_exists('automationTags')) {
    /** @return array<string, string> tag name => source */
    function automationTags(Asset $asset): array
    {
        return DB::table('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
            ->where('dam_asset_tag.asset_id', $asset->id)->orderBy('dam_tags.name')
            ->pluck('dam_asset_tag.source', 'dam_tags.name')->all();
    }
}
