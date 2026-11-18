<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQL Server rejects this SET NULL as a second path from dam_assets (through
        // dam_asset_files) into the table (error 1785). There it is NO ACTION, and
        // Asset::releaseFileOwnership() and AssetFile's deleting hook clear the column first.
        $setNull = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::create('dam_optimizations', function (Blueprint $table) use ($setNull) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('type', 8);
            $table->json('options');
            $table->char('source_sha256', 64);
            $table->string('status', 16)->default('queued');
            $table->string('outcome', 16)->nullable();
            $table->unsignedBigInteger('old_bytes')->nullable();
            $table->unsignedBigInteger('new_bytes')->nullable();
            $table->string('output_extension', 10)->nullable();
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->foreign('asset_file_id')->references('id')->on('dam_asset_files')->onDelete($setNull);
            $table->string('error', 40)->nullable();
            $table->timestamps();
            $table->unique(['admin_id', 'request_id']);
            $table->index(['asset_id', 'admin_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_optimizations');
    }
};
