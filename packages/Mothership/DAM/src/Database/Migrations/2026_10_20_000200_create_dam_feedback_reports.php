<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Feedback & bug reports. A report keeps its screenshot as a content-addressed
 * FileObject (ADR 0009); FileObject::scopeReferenced() counts it, so deleting an asset with the same bytes keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server allows no RESTRICT and refuses a second cascade path into one table (error 1785), as the hub
        // resources migration records. There admin_id is NO ACTION, and NullAdminFeedbackReports clears it before the
        // admin row goes; that listener runs on every driver, so the outcome is the same everywhere.
        $sqlsrv = DB::getDriverName() === 'sqlsrv';

        Schema::create('dam_feedback_reports', function (Blueprint $table) use ($sqlsrv): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('admin_id')->nullable();
            $table->string('type', 16);
            $table->string('severity', 16)->nullable();
            $table->string('title', 160);
            $table->text('description');
            $table->string('status', 16)->default('new');
            $table->string('page_url', 2048)->nullable();
            $table->string('route_name', 191)->nullable();
            $table->string('hub_app', 64)->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('screenshot_file_object_id')->nullable();
            $table->string('github_issue_url', 512)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['admin_id', 'created_at']);
            $table->index('screenshot_file_object_id');
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete($sqlsrv ? 'no action' : 'set null');
            $table->foreign('screenshot_file_object_id')->references('id')->on('dam_file_objects')->onDelete($sqlsrv ? 'no action' : 'restrict');
        });

        Schema::create('dam_feedback_notes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id');
            // An audit record, so no foreign key: it outlives its author, and a second key into admins would be another
            // cascade path on SQL Server.
            $table->unsignedInteger('admin_id')->nullable();
            $table->string('kind', 16)->default('note');
            $table->text('body')->nullable();
            $table->boolean('visible_to_reporter')->default(false);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->foreign('report_id')->references('id')->on('dam_feedback_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_feedback_notes');
        Schema::dropIfExists('dam_feedback_reports');
    }
};
