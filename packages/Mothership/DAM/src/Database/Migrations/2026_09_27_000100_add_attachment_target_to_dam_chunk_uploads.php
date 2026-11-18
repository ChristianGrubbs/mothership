<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chunked upload can finish as an attachment (AssetFile) on an existing asset instead of a new asset,
 * so large "Related files" uploads use the same resumable 32 MB-chunk path as the Library uploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->unsignedBigInteger('attach_asset_id')->nullable()->index();
            $table->string('attach_role', 32)->nullable();
            $table->string('attach_label', 191)->nullable();
            $table->unsignedBigInteger('asset_file_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->dropIndex(['attach_asset_id']);
            $table->dropColumn(['attach_asset_id', 'attach_role', 'attach_label', 'asset_file_id']);
        });
    }
};
