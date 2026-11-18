<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_vectorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('source_asset_id')->nullable();
            $table->foreign('source_asset_id')->references('id')->on('dam_assets')->nullOnDelete();
            $table->string('output_format', 3)->nullable();
            $table->index(['source_asset_id', 'admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dam_vectorizations', function (Blueprint $table) {
            $table->dropForeign(['source_asset_id']);
            $table->dropIndex(['source_asset_id', 'admin_id', 'created_at']);
            $table->dropColumn(['source_asset_id', 'output_format']);
        });
    }
};
