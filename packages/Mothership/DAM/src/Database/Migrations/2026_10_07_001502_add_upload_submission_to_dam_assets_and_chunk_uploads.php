<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P5a (D1): which guest visit a file came from.
 *
 * - dam_assets.upload_submission_id: set on an asset publishIntake() created, and never changed afterwards. It records
 *   that the uploader was a guest. While the asset is also `pending_review` it is guest intake awaiting a decision, and
 *   AssetAccessDecision keeps it to its owner (the request's creator) and full admins under either access model.
 * - dam_chunk_uploads.upload_submission_id: binds a guest chunk upload to its visit. The staff chunk routes refuse such
 *   a row, and the portal routes find only their own visit's rows.
 *
 * Plain indexed columns with no foreign key: a second cascade path into dam_assets is SQL Server error 1785, and an
 * asset keeps naming its submission after the request is deleted. Nothing to backfill: no existing row came from a
 * guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('upload_submission_id')->nullable()->index();
        });

        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->unsignedBigInteger('upload_submission_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->dropIndex(['upload_submission_id']);
            $table->dropColumn('upload_submission_id');
        });

        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['upload_submission_id']);
            $table->dropColumn('upload_submission_id');
        });
    }
};
