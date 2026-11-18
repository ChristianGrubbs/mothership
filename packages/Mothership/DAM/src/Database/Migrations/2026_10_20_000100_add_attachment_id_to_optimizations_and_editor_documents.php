<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimize and the image editor work on one stacked file, not only on the stack's main file: the row names that
 * file's slot (dam_attachments.id), null for the main file. No foreign key, as for the other slot references the
 * graph cleans itself: a slot that is gone simply stops being a member (StackMembers), so a queued optimization
 * settles as `source_unavailable` and an editor document for it is never found again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_optimizations', function (Blueprint $table) {
            $table->unsignedBigInteger('attachment_id')->nullable()->after('asset_id');
            $table->index(['asset_id', 'attachment_id', 'admin_id'], 'dam_opt_asset_slot_admin_idx');
        });

        Schema::table('dam_image_editor_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('attachment_id')->nullable()->after('asset_id');
            // The member revision the document was opened on; the main file's documents key on content_path and version.
            $table->unsignedBigInteger('source_asset_file_id')->nullable()->after('attachment_id');
            $table->index(['asset_id', 'attachment_id', 'source_asset_file_id'], 'dam_ied_asset_slot_rev_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dam_image_editor_documents', function (Blueprint $table) {
            $table->dropIndex('dam_ied_asset_slot_rev_idx');
            $table->dropColumn(['attachment_id', 'source_asset_file_id']);
        });

        Schema::table('dam_optimizations', function (Blueprint $table) {
            $table->dropIndex('dam_opt_asset_slot_admin_idx');
            $table->dropColumn('attachment_id');
        });
    }
};
