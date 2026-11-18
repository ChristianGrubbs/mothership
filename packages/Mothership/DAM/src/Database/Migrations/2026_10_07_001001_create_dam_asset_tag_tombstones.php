<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 D8: a person's removal of a tag from an asset. While a row exists, no re-index, AI accept, rule, import or
 * bulk add puts that tag back on that asset; adding it again by hand on that one asset deletes the row.
 *
 * Both foreign keys cascade, from two different parents, exactly as dam_asset_tag's own do, so SQL Server sees no
 * second cascade path from one table. removed_by has no key: an admin who is later deleted leaves the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_asset_tag_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('dam_tags')->cascadeOnDelete();
            $table->unsignedInteger('removed_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unique(['asset_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_tag_tombstones');
    }
};
