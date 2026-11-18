<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one row DirectoryTreeLock locks `FOR UPDATE` on MySQL/MariaDB (ADR 0004, note 2026-09-23). InnoDB frees a
 * row lock only when the outermost transaction commits or rolls back — the lifetime pg_advisory_xact_lock has on
 * PostgreSQL — so no waiting writer can read the tree before the holder's rows commit, and there is no release
 * step to leak. Created on every driver so the schema is identical; only MySQL/MariaDB take the lock through it.
 *
 * Dated just before 2026_09_21_000001_purge_mind_maps, the first migration that takes the tree lock; it first
 * shipped as 2026_09_23_000003, which a fresh MySQL install reached only after the purge had failed on the
 * missing table. Idempotent so a database that already ran the old name only drops that stale record.
 */
return new class extends Migration
{
    private const OLD_MIGRATION = '2026_09_23_000003_create_dam_directory_tree_lock_table';

    public function up(): void
    {
        if (! Schema::hasTable('dam_directory_tree_lock')) {
            Schema::create('dam_directory_tree_lock', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
            });
        }

        // Not insertOrIgnore(): SQL Server's grammar has no compiler for it.
        if (! DB::table('dam_directory_tree_lock')->where('id', 1)->exists()) {
            DB::table('dam_directory_tree_lock')->insert(['id' => 1]);
        }
        DB::table('migrations')->where('migration', self::OLD_MIGRATION)->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_directory_tree_lock');
    }
};
