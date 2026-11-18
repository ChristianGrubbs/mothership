<?php

namespace Mothership\DAM\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Mothership\AdminApi\Tests\Traits\ApiHelperTrait;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Tests\Concerns\UserAssertions;
use Tests\TestCase;

class DamTestCase extends TestCase
{
    use ApiHelperTrait, UserAssertions {
        UserAssertions::getFullTableName insteadof ApiHelperTrait;
    }

    protected const TEST_ROOT_URL = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::TEST_ROOT_URL]);

        URL::forceRootUrl(self::TEST_ROOT_URL);

        if (Schema::hasTable('dam_configuration')) {
            DB::table('dam_configuration')->delete();

            // Put the access-model state back as migration 2026_09_26_000001 leaves it. A missing row is no
            // fence at all (ADR 0010, sharing slice 2a): there is nothing to hold FOR SHARE, so every list
            // writer would run through AccessListWriter's unfenced path and throw. A case that wants the row
            // absent deletes it itself.
            DB::table('dam_configuration')->insert(['key' => AccessModel::KEY, 'value' => AccessModel::GRANTS]);
            app(AccessModel::class)->flush();
        }
    }

    protected function damResetAssetTables(): void
    {
        $tables = [
            'dam_asset_comments',
            'dam_asset_directory',
            'dam_asset_properties',
            'dam_asset_resource_mappings',
            'dam_asset_tag',
            'dam_assets',
            'dam_tags',
            'dam_directory_role',
            'dam_explorer_bookmarks',
            'dam_directories',
        ];

        Schema::disableForeignKeyConstraints();

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}
