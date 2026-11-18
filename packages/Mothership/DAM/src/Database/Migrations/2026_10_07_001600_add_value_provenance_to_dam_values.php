<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P5b (roadmap §3.4): every machine-writable value carries who wrote it. `source` is `manual`, `ai` / `ai:<model>`,
 * `mapping:<id>`, `rule:<id>@v<n>`, or a P4d channel (`rule`, `import`). Every existing row is manual.
 *
 * - dam_asset_tag.source (P4d, 16 chars) widens to 64 so `rule:<id>@v<n>` fits.
 * - dam_asset_properties, dam_label_assets and dam_section_pins gain the same column.
 *
 * down() folds the long forms back into P4d's short ones before narrowing, so no value is too long on PostgreSQL.
 */
return new class extends Migration
{
    private const TABLES = ['dam_asset_properties', 'dam_label_assets', 'dam_section_pins'];

    public function up(): void
    {
        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->string('source', 64)->default('manual')->change();
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('source', 64)->default('manual');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }

        DB::table('dam_asset_tag')->where('source', 'like', 'rule:%')->update(['source' => 'rule']);
        DB::table('dam_asset_tag')->where('source', 'like', 'mapping:%')->update(['source' => 'import']);
        DB::table('dam_asset_tag')->where('source', 'like', 'ai:%')->update(['source' => 'ai']);

        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->string('source', 16)->default('manual')->change();
        });
    }
};
