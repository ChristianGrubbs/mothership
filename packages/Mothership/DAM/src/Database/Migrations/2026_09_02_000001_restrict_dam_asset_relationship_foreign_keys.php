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
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        Schema::table('dam_asset_relationships', function (Blueprint $table) {
            $table->dropForeign(['from_asset_id']);
            $table->dropForeign(['to_asset_id']);
        });

        Schema::table('dam_asset_relationships', function (Blueprint $table) use ($restrict) {
            $table->foreign('from_asset_id')
                ->references('id')
                ->on('dam_assets')
                ->onDelete($restrict);
            $table->foreign('to_asset_id')
                ->references('id')
                ->on('dam_assets')
                ->onDelete($restrict);
        });
    }

    public function down(): void
    {
        // SQL Server holds one cascade path per table pair (see 2026_09_01_000005).
        $cascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::table('dam_asset_relationships', function (Blueprint $table) {
            $table->dropForeign(['from_asset_id']);
            $table->dropForeign(['to_asset_id']);
        });

        Schema::table('dam_asset_relationships', function (Blueprint $table) use ($cascade) {
            $table->foreign('from_asset_id')
                ->references('id')
                ->on('dam_assets')
                ->cascadeOnDelete();
            $table->foreign('to_asset_id')
                ->references('id')
                ->on('dam_assets')
                ->onDelete($cascade);
        });
    }
};
