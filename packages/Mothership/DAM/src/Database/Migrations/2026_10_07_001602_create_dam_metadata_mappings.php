<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5b (D18): embedded-metadata mappings. Each maps one ExifTool key under meta_data.exif, through an ordered list of
 * transforms, onto a tag, a typed field or a shared label, and writes with source `mapping:<id>`.
 *
 * target_id is the field for a field target and null otherwise; scope_directory_id is the folder whose subtree the
 * mapping covers, null for every folder. Neither has a foreign key: a deleted field or folder leaves the mapping,
 * which then refuses its writes (`target_missing`) or matches nothing, and says so in its dry run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_metadata_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->boolean('enabled')->default(true);
            $table->string('source_key', 191);
            $table->json('transforms')->nullable();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('write_mode', 16)->default('fill_empty');
            $table->unsignedBigInteger('scope_directory_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_counts')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_metadata_mappings');
    }
};
