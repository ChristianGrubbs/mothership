<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files uploaded by a non-admin user are flagged so an admin can gather them into a folder and organise them
 * (the "New user uploads" page). Existing rows stay null: nothing already in the library is flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->timestamp('needs_sorting_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['needs_sorting_at']);
            $table->dropColumn('needs_sorting_at');
        });
    }
};
