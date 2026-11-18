<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P7 watchers. The asset's creator and everyone who has commented on it watch it without a row here; a row is an
 * explicit choice that overrides that: `watching` true for Watch, false for Unwatch. AssetWatchers derives the set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_asset_watchers', function (Blueprint $table): void {
            $table->unsignedBigInteger('dam_asset_id');
            $table->unsignedInteger('admin_id');
            $table->boolean('watching');
            $table->timestamps();
            $table->primary(['dam_asset_id', 'admin_id']);
            $table->index(['admin_id', 'dam_asset_id']);
            $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_watchers');
    }
};
