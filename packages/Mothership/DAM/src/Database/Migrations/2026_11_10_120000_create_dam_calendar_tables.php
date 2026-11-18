<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Home calendar (docs/features/event-calendar.md): the events and campaigns people add by hand, the tasks on
 * them and the assets linked to them. `source` and `external_id` are kept for an importer; nothing ships that writes
 * them, so every row is `manual`.
 * dam_events is the analytics event log, so these tables are dam_calendar_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_calendar_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // manual, or an importer's own name
            $table->string('source', 16);
            // An importer's id for this occurrence; null for a manual entry.
            $table->string('external_id', 191)->nullable();
            // event | campaign
            $table->string('kind', 16)->default('event');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('url', 1024)->nullable();
            // Campus wall-clock time with no zone, as the feeds give it.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            // confirmed | cancelled | removed (no longer in its feed)
            $table->string('status', 16)->default('confirmed');
            // Who added a manual entry. No foreign key: the entry outlives its author.
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('dam_calendar_tasks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            // A sub-item points at its task. No foreign key (a second cascade path on SQL Server): the controller
            // deletes sub-items with their task.
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('title', 200);
            // Free text such as "Shooting" or "Designing".
            $table->string('role', 60)->nullable();
            // Optional. No foreign key: an account that is gone reads as unassigned.
            $table->unsignedInteger('assignee_id')->nullable();
            // open | in_progress | done
            $table->string('status', 16)->default('open');
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'parent_id', 'position']);
            $table->index(['assignee_id', 'status']);
            $table->foreign('event_id')->references('id')->on('dam_calendar_events')->cascadeOnDelete();
        });

        Schema::create('dam_calendar_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            // Null links the asset to the event itself; otherwise to that task or sub-item.
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('added_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'task_id']);
            $table->index('asset_id');
            $table->foreign('event_id')->references('id')->on('dam_calendar_events')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_calendar_assets');
        Schema::dropIfExists('dam_calendar_tasks');
        Schema::dropIfExists('dam_calendar_events');
    }
};
