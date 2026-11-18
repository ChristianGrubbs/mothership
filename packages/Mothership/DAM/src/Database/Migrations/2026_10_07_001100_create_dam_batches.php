<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Batch processing (P6a, D17/D27/D28). One dam_batches row per request to run a recipe (convert, resize, gif,
 * clip, watermark …) over a set of assets, and one dam_batch_items row per asset, so every file's progress,
 * failure reason and output can be shown and retried on its own.
 *
 * An item names the asset and the exact revision it was made from (source_asset_file_id), and what it produced:
 * a file of the same entry (output_asset_file_id) or a new entry (output_asset_id), with the lineage rendition
 * (rendition_id). None of those carry a foreign key, like dam_renditions' output columns: dam_assets and
 * dam_asset_files already cascade from the asset, and a second path to them is SQL Server error 1785. An item
 * keeps the record of what was asked after its asset or files are gone; it then reads as missing.
 *
 * requested_by follows dam_optimizations.admin_id: deleting the admin deletes their batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('recipe', 32);
            $table->json('params')->nullable();
            // `attach` adds the output to the source entry as a new file; `new_asset` files it as its own entry.
            $table->string('save_as', 16)->default('attach');
            $table->unsignedInteger('requested_by');
            // library (the admin UI) or api (an integration key), for the logs and the run-time re-check.
            $table->string('via', 16)->default('library');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            // Laravel's job_batches id, for the queue's own view of the same work.
            $table->string('bus_batch_id', 36)->nullable();
            $table->uuid('correlation_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by')->references('id')->on('admins')->cascadeOnDelete();
            $table->index(['requested_by', 'created_at']);
            $table->index('status');
        });

        Schema::create('dam_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('source_asset_file_id')->nullable();
            // queued | running | done | failed | skipped | cancelled
            $table->string('status', 16)->default('queued');
            // A short, user-safe code (dam::app.admin.batch.errors.*); never a path, a command or tool output.
            $table->string('error', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('output_asset_file_id')->nullable();
            $table->unsignedBigInteger('output_asset_id')->nullable();
            $table->unsignedBigInteger('rendition_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('dam_batches')->cascadeOnDelete();
            $table->unique(['batch_id', 'position']);
            $table->index(['batch_id', 'status']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_batch_items');
        Schema::dropIfExists('dam_batches');
    }
};
