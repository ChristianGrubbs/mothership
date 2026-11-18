<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P3b-1 review: finalizing a merge copies the source's comment threads onto the destination before the source can be
 * purged. Each copy keeps its author, its timestamps and its place in the thread, and records where it came from:
 *
 * - merge_id: the finalized merge that copied it.
 * - origin_comment_id: the source comment it copies (gone once the source is purged, so no foreign key).
 * - merged_from_name: the source's Library name at finalize, shown as "From <name>" beside the comment.
 *
 * No foreign keys: the merge record and the source comment are kept or purged on their own schedules, and a second
 * cascade path into dam_asset_comments is SQL Server error 1785.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('merge_id')->nullable();
            $table->unsignedBigInteger('origin_comment_id')->nullable();
            $table->string('merged_from_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_comments', function (Blueprint $table): void {
            $table->dropColumn(['merge_id', 'origin_comment_id', 'merged_from_name']);
        });
    }
};
