<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The AI description of one stacked file that is not the cover. The cover is the asset row, and dam_ai_index describes
 * it; a direct attachment has no asset row of its own, so its description lives here, keyed by the asset it sits in and
 * the file object (content) of the revision that was described. A row counts only while that object is still the
 * member's current revision AND content_sha256 still equals the object's sha256, so new bytes (a new revision) never
 * show an old caption. file_object_id has no foreign key on purpose, as dam_asset_files.attachment_id has none: the
 * asset cascade already clears the rows, and a second cascade path is an error on SQL Server.
 *
 * Additive: nothing existing changes. down() drops only this table (the descriptions are regenerable from the bytes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dam_ai_member_descriptions')) {
            return;
        }

        Schema::create('dam_ai_member_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('file_object_id');
            $table->char('content_sha256', 64);
            $table->text('caption');
            $table->json('keywords')->nullable();
            $table->text('visible_text')->nullable();
            $table->string('caption_model', 80)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'file_object_id'], 'dam_ai_member_desc_asset_object_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_ai_member_descriptions');
    }
};
