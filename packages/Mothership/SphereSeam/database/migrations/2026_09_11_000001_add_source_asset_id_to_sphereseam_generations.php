<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sphereseam_generations', function (Blueprint $table): void {
            // The DAM image a generation was built from, when one was chosen as the base panorama.
            // Persisted so the import can link the exported panorama back to its source asset.
            $table->unsignedBigInteger('source_asset_id')->nullable()->after('admin_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sphereseam_generations', function (Blueprint $table): void {
            $table->dropColumn('source_asset_id');
        });
    }
};
