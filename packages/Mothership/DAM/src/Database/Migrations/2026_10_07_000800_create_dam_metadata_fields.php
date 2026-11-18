<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4a (D9): admin-defined, typed metadata fields, and typed storage on the existing property rows.
 *
 * - dam_metadata_fields: one row per field. card_priority (1-5) is unique when set; SQL Server treats NULLs as equal
 *   in a plain unique index, so it gets a filtered one there.
 * - dam_asset_properties gains a nullable field_id and one typed column per scalar type. Text, URL and select keep
 *   their canonical string in the existing `value` column, which every legacy reader already uses.
 * - dam_asset_property_options: a multi-select value's chosen options, one row each, so a filter is a plain join on
 *   every driver (no JSON operators).
 *
 * Nothing here moves data: free-text names are adopted only by `dam:fields:adopt --apply`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_metadata_fields', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label', 191);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedTinyInteger('card_priority')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_filterable')->default(false);
            // Deleting a field that has values archives it: the values stay, and Restore brings the field back.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['archived_at', 'position'], 'dam_metadata_fields_active_position_idx');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX dam_metadata_fields_card_priority_unique ON '
                .DB::getTablePrefix().'dam_metadata_fields (card_priority) WHERE card_priority IS NOT NULL');
        } else {
            Schema::table('dam_metadata_fields', function (Blueprint $table) {
                $table->unique('card_priority', 'dam_metadata_fields_card_priority_unique');
            });
        }

        Schema::table('dam_asset_properties', function (Blueprint $table) {
            // No cascade: a field with values is archived, never deleted, so this path never fires.
            $table->foreignId('field_id')->nullable()->constrained('dam_metadata_fields');
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();

            $table->index(['field_id', 'dam_asset_id'], 'dam_asset_props_field_asset_idx');
        });

        Schema::create('dam_asset_property_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_property_id')->constrained('dam_asset_properties')->cascadeOnDelete();
            $table->string('value', 191);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['asset_property_id', 'value'], 'dam_asset_prop_options_unique');
            $table->index('value', 'dam_asset_prop_options_value_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_property_options');

        Schema::table('dam_asset_properties', function (Blueprint $table) {
            $table->dropIndex('dam_asset_props_field_asset_idx');
            $table->dropConstrainedForeignId('field_id');
            $table->dropColumn(['value_number', 'value_date', 'value_bool']);
        });

        Schema::dropIfExists('dam_metadata_fields');
    }
};
