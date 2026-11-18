<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P3b-1, merge assets into a stack (D7, audit §3.1 "Merge: link, don't move"). A merge links: the destination gains new
 * slots whose revisions name the same FileObjects as the source's, and the source's own rows are left as they are.
 *
 * - dam_asset_merges: one row per source merged into a destination. Active while split_at and finalized_at are both
 *   null. The snapshot is for display and reconciliation (what was linked, which tags and properties the merge added);
 *   durability comes from the untouched source rows.
 * - dam_assets.merged_into_id: the destination a source is merged into. Set, the source is hidden from every read path
 *   for everyone, like a trashed asset (AssetAccessDecision).
 * - dam_attachments.merge_id / origin_attachment_id: which merge a destination slot came from, and the source slot it
 *   links. A split removes the slots carrying its merge id.
 *
 * Only dest_id has a foreign key. source_id, merged_into_id and the two slot columns carry none: a second cascade path
 * from dam_assets is SQL Server error 1785 (as for dam_asset_files.attachment_id), and a finalized merge record keeps
 * naming its source after that source is purged. The service clears what it set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_asset_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dest_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->longText('snapshot')->nullable();
            $table->unsignedInteger('merged_by')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('split_at')->nullable();
            $table->unsignedInteger('split_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedInteger('finalized_by')->nullable();
            $table->timestamps();

            $table->index('source_id');
        });

        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('merged_into_id')->nullable()->index();
        });

        Schema::table('dam_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('merge_id')->nullable()->index();
            $table->unsignedBigInteger('origin_attachment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_attachments', function (Blueprint $table): void {
            $table->dropIndex(['merge_id']);
            $table->dropColumn(['merge_id', 'origin_attachment_id']);
        });

        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });

        Schema::dropIfExists('dam_asset_merges');
    }
};
