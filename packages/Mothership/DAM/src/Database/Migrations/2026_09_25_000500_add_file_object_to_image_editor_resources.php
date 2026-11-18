<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0009: a layered image editor resource names its bytes through a FileObject, like every other durable
 * byte, so it is content-addressed, reference-counted by AssetDeletionService and moved by MoveDamAssetsToS3.
 * `path` stays for rows written before this, naming their old `assets/.editor/` working file until slice 4's
 * reclaim removes it; rows written since carry none. 2026_09_25_000501 ingests those old files.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        Schema::table('dam_image_editor_resources', function (Blueprint $table) use ($restrict) {
            $table->foreignId('file_object_id')->nullable()->after('status')->constrained('dam_file_objects')->onDelete($restrict);
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dam_image_editor_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('file_object_id');
        });
    }
};
