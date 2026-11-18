<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 D14: the related-assets property signal looks up other assets by property name and value. `value` is text
 * (no portable index), so the lookup narrows by name through this index and compares values only within it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_properties', function (Blueprint $table): void {
            $table->index('name', 'dam_asset_properties_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_properties', function (Blueprint $table): void {
            $table->dropIndex('dam_asset_properties_name_index');
        });
    }
};
