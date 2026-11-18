<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_asset_favorites', function (Blueprint $table) {
            $table->unsignedInteger('admin_id');
            $table->unsignedBigInteger('dam_asset_id');
            $table->timestamp('created_at');
            $table->primary(['admin_id', 'dam_asset_id']);
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_favorites');
    }
};
