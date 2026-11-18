<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3b-2 (D5 suggestions): each asset's normalized filename stem (Support\FilenameStem), kept by the model on every
 * save, so stack suggestions group names like `hero-v2.jpg` and `hero@2x.png` with one indexed GROUP BY instead of
 * reading every name. Null until 2026_10_07_000601 fills it, and for a name with no usable stem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->string('name_stem', 191)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['name_stem']);
            $table->dropColumn('name_stem');
        });
    }
};
