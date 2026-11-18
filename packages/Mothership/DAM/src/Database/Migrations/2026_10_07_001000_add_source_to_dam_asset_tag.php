<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 D8: who put a tag on an asset. `source` is manual, ai, rule or import (AssetTagWriter::SOURCES); every
 * existing row starts as manual, and 2026_10_07_001002 relabels the rows that came from an accepted AI suggestion.
 * The (tag_id, asset_id) index serves the tag-to-assets lookups a rename, merge or delete fans out over and the
 * related-assets tag signal; the existing unique key leads with asset_id and cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->string('source', 16)->default('manual');
            $table->index(['tag_id', 'asset_id'], 'dam_asset_tag_tag_asset_index');
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_tag', function (Blueprint $table): void {
            $table->dropIndex('dam_asset_tag_tag_asset_index');
            $table->dropColumn('source');
        });
    }
};
