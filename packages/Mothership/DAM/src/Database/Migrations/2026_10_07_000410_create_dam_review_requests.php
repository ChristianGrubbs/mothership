<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P7 feedback requests: someone asks people for feedback on an asset's revision, with an optional message and due
 * date. A request is opened and closed, never decided (operator decision 2026-10-06: no approvals). Its recipients
 * are admin ids, groups expanded when the request is made.
 *
 * asset_file_id, requested_by and closed_by carry no foreign key, for the reasons the comment columns beside them
 * do (2026_10_07_000400): the request already cascades from the asset, and the recipients pivot already has the one
 * key to admins this table's children need.
 */
return new class extends Migration
{
    public function up(): void
    {
        // With the `wk_` prefix the generated name of the recipients' reverse index runs to 65 characters, past
        // MySQL's 64 (error 1059). It is named there only, so every driver this already ran on keeps its name.
        $reverseIndex = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'dam_review_recipients_admin_index' : null;

        Schema::create('dam_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dam_asset_id');
            $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->unsignedInteger('requested_by')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('closed_by')->nullable();
            $table->timestamps();

            $table->index(['dam_asset_id', 'closed_at']);
        });

        Schema::create('dam_review_request_recipients', function (Blueprint $table) use ($reverseIndex): void {
            $table->unsignedBigInteger('review_request_id');
            $table->unsignedInteger('admin_id');
            $table->primary(['review_request_id', 'admin_id']);
            $table->index(['admin_id', 'review_request_id'], $reverseIndex);
            $table->foreign('review_request_id')->references('id')->on('dam_review_requests')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_review_request_recipients');
        Schema::dropIfExists('dam_review_requests');
    }
};
