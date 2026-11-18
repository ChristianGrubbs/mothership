<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P7 review comments. A comment names the revision it is about (`asset_file_id`, a dam_asset_files row of the same
 * asset), optionally a place on it (`anchor`: a point, a region or a time range), who it mentions (`mentions`, admin
 * ids from the structured payload, never parsed from the text) and whether it is resolved. Every existing comment
 * keeps working with all five null.
 *
 * asset_file_id and resolved_by carry no foreign key on purpose, as dam_asset_files.attachment_id and
 * dam_assets.deleted_by do: comments and files both cascade from the asset already, and a key from comments to files
 * (or a second one to admins, beside admin_id) is a second cascade path SQL Server refuses (error 1785) and a
 * mid-cascade check on InnoDB. RevisionOfAsset keeps a comment on its own asset's revisions, and AssetFile's deleted
 * hook clears the column when a revision goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->json('anchor')->nullable();
            $table->json('mentions')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();

            $table->index(['dam_asset_id', 'asset_file_id']);
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_comments', function (Blueprint $table): void {
            $table->dropIndex(['dam_asset_id', 'asset_file_id']);
        });

        Schema::table('dam_asset_comments', function (Blueprint $table): void {
            $table->dropColumn(['asset_file_id', 'anchor', 'mentions', 'resolved_at', 'resolved_by']);
        });
    }
};
