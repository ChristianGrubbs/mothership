<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P5c (D8, roadmap §3.4): an accepted AI suggestion records the model that proposed it, `ai:<model>`
 * (dam_ai_index.caption_model is up to 80 characters), so `source` grows from P5b's 64 to 100. down() folds every
 * `ai:<model>` back to `ai` and returns to 64, so P5b's `rule:<id>@v<n>` sources still fit and nothing is truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->string('source', 100)->default('manual')->change();
        });
    }

    public function down(): void
    {
        DB::table('dam_asset_tag')->where('source', 'like', 'ai:%')->update(['source' => 'ai']);

        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->string('source', 64)->default('manual')->change();
        });
    }
};
