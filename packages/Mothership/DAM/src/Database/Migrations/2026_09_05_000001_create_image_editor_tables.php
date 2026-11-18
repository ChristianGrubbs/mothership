<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The asset already cascades into this table, and SQL Server rejects the second path
        // through its versions (error 1785); Asset::releaseFileOwnership clears it there.
        $setNull = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::create('dam_image_editor_documents', function (Blueprint $table) use ($setNull) {
            $table->id();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->foreignId('asset_version_id')->nullable()->constrained('dam_asset_versions')->onDelete($setNull);
            $table->uuid('revision')->unique();
            $table->string('content_path');
            $table->uuid('original_base');
            $table->json('document');
            $table->timestamps();
        });
        Schema::create('dam_image_editor_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('admin_id');
            $table->string('kind', 20);
            $table->string('status', 20)->default('ready');
            $table->string('path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_image_editor_documents');
        Schema::dropIfExists('dam_image_editor_resources');
    }
};
