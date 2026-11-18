<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026_09_05_000001_create_capture_profiles and 2026_09_06_000003_create_dam_asset_favorites
 * first created their FKs to dam_assets.id (unsigned BIGINT) as unsigned INT. PR #105 corrected
 * both in place so MySQL fresh installs stop failing with 3780; databases that had already run
 * them keep the INT column. This widens those columns and is a no-op where they are already
 * BIGINT (fresh installs; SQLite, which reports "integer" for both).
 */
return new class extends Migration
{
    /** Column type names of the old INT column: pgsql int4, mysql/mariadb int. */
    private const NARROW_TYPES = ['int4', 'int'];

    public function up(): void
    {
        if ($this->isNarrow(table: 'dam_capture_groups', column: 'primary_asset_id')) {
            // Separate blueprints: a blueprint runs its column changes before its other commands.
            Schema::table('dam_capture_groups', function (Blueprint $t) {
                $t->dropForeign(['primary_asset_id']);
            });
            Schema::table('dam_capture_groups', function (Blueprint $t) {
                $t->unsignedBigInteger('primary_asset_id')->nullable()->change();
                $t->foreign('primary_asset_id')->references('id')->on('dam_assets')->nullOnDelete();
            });
        }

        if ($this->isNarrow(table: 'dam_asset_favorites', column: 'dam_asset_id')) {
            Schema::table('dam_asset_favorites', function (Blueprint $table) {
                $table->dropForeign(['dam_asset_id']);
            });
            Schema::table('dam_asset_favorites', function (Blueprint $table) {
                $table->unsignedBigInteger('dam_asset_id')->change();
                $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Forward-only: narrowing back to INT would restore the FK type mismatch.
    }

    private function isNarrow(string $table, string $column): bool
    {
        return in_array(Schema::getColumnType($table, $column), self::NARROW_TYPES, true);
    }
};
