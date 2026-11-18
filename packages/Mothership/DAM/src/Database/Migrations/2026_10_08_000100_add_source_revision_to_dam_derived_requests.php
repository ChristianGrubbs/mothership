<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The revision a vectorize/optimize request was made from, captured when it was requested, so the lineage
 * row still names it after another file is promoted to representative before the work settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server rejects this SET NULL as a second path from dam_assets (through dam_asset_files) into
        // both tables (error 1785), as for dam_optimizations.asset_file_id. There it is NO ACTION, and
        // Asset::releaseFileOwnership() and AssetFile's deleting hook clear the column first.
        $setNull = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        foreach (['dam_vectorizations', 'dam_optimizations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($setNull) {
                $table->unsignedBigInteger('source_asset_file_id')->nullable();
                $table->foreign('source_asset_file_id')->references('id')->on('dam_asset_files')->onDelete($setNull);
            });
        }
    }

    public function down(): void
    {
        foreach (['dam_vectorizations', 'dam_optimizations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['source_asset_file_id']);
                $table->dropColumn('source_asset_file_id');
            });
        }
    }
};
