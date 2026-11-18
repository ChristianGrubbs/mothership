<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->unique(['id', 'dam_asset_id'], 'dam_asset_files_id_asset_unique');
        });

        Schema::table('dam_asset_versions', function (Blueprint $table) {
            $table->dropForeign(['primary_asset_file_id']);
            $table->foreign(
                ['primary_asset_file_id', 'dam_asset_id'],
                'dam_asset_versions_primary_file_owner_foreign'
            )->references(['id', 'dam_asset_id'])->on('dam_asset_files');
        });

        Schema::table('dam_renditions', function (Blueprint $table) {
            $table->dropForeign(['source_asset_file_id']);
            $table->foreign(
                ['source_asset_file_id', 'dam_asset_id'],
                'dam_renditions_source_file_owner_foreign'
            )->references(['id', 'dam_asset_id'])->on('dam_asset_files');
        });
    }

    public function down(): void
    {
        // SQL Server cannot hold these as SET NULL (see 2026_09_01_000003 and 000004).
        $setNull = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::table('dam_asset_versions', function (Blueprint $table) use ($setNull) {
            $table->dropForeign('dam_asset_versions_primary_file_owner_foreign');
            $table->foreign('primary_asset_file_id')
                ->references('id')
                ->on('dam_asset_files')
                ->onDelete($setNull);
        });

        Schema::table('dam_renditions', function (Blueprint $table) use ($setNull) {
            $table->dropForeign('dam_renditions_source_file_owner_foreign');
            $table->foreign('source_asset_file_id')
                ->references('id')
                ->on('dam_asset_files')
                ->onDelete($setNull);
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->dropUnique('dam_asset_files_id_asset_unique');
        });
    }
};
