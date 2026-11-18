<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_properties', function (Blueprint $table) {
            $table->index(['dam_asset_id', 'id'], 'dam_asset_properties_asset_title_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_properties', function (Blueprint $table) {
            $table->dropIndex('dam_asset_properties_asset_title_lookup');
        });
    }
};
