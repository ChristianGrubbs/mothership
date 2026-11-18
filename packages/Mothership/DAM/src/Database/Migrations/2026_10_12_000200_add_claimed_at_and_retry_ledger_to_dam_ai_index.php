<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `claimed_at`: when the row's current run was queued, started or last retried. updated_at is moved by anything that
 * touches the row (a words refresh), so it cannot say how long a job has waited.
 *
 * `dam_ai_index_retries`: the manual retries a person has used, one per (file, content). The unique key is what makes
 * "one retry per content" hold under two requests at once and when a file goes back to bytes it had before (A, B, A).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dam_ai_index') && ! Schema::hasColumn('dam_ai_index', 'claimed_at')) {
            Schema::table('dam_ai_index', fn (Blueprint $table) => $table->timestamp('claimed_at')->nullable());
        }

        if (! Schema::hasTable('dam_ai_index_retries')) {
            Schema::create('dam_ai_index_retries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('asset_id');
                $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
                $table->char('content_sha256', 64);
                $table->timestamp('created_at')->nullable();
                $table->unique(['asset_id', 'content_sha256']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_ai_index_retries');

        if (Schema::hasTable('dam_ai_index') && Schema::hasColumn('dam_ai_index', 'claimed_at')) {
            Schema::table('dam_ai_index', fn (Blueprint $table) => $table->dropColumn('claimed_at'));
        }
    }
};
