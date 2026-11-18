<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `run_token`: the one queued run that owns the row. A new run takes a new token, so a job that was queued before it
 * (or that a worker lost and picked up again) finds the token is not its own and ends without touching the row.
 * Null on a pending row means no job is queued for it (a words-only refresh created it).
 *
 * (The manual-retry record is the per-content ledger of 2026_10_12_000200. An earlier draft of this migration also added a
 * `retry_sha` column that nothing reads; down() still drops it where a database has one.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dam_ai_index')) {
            return;
        }

        if (! Schema::hasColumn('dam_ai_index', 'run_token')) {
            Schema::table('dam_ai_index', fn (Blueprint $table) => $table->string('run_token', 36)->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dam_ai_index')) {
            return;
        }

        foreach (['run_token', 'retry_sha'] as $column) {
            if (Schema::hasColumn('dam_ai_index', $column)) {
                Schema::table('dam_ai_index', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
