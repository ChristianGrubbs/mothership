<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Web page collections (ADR 0008): a shared, nestable organizer for `file_type = 'web'` assets with at
 * most one placement per page. Collections grant nothing; SQLite and PostgreSQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server rejects a self-referencing cascade (error 1785). WebCollectionService::delete
        // reparents the children before a collection goes, so NO ACTION there loses nothing.
        $cascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_web_collections', function (Blueprint $table) use ($cascade) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('dam_web_collections')->onDelete($cascade);
            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique(['parent_id', 'name']);
        });

        Schema::create('dam_web_collection_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->primary();
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained('dam_web_collections')->cascadeOnDelete();
            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_web_collection_assets');
        Schema::dropIfExists('dam_web_collections');
    }
};
