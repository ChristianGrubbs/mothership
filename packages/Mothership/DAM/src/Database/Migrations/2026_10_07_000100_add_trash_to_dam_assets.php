<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 S4: the 30-day trash. `deleted_at` (SoftDeletes) marks a trashed asset; `trashed_from_path` keeps the Library
 * path it had, for Restore, while `path` moves under the reserved `assets/.trash/{id}/` prefix; `deleted_by` is who
 * trashed it, for the Trash page. Every existing row stays untrashed (all three null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable()->index();
            $table->string('trashed_from_path')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'trashed_from_path', 'deleted_by']);
        });
    }
};
