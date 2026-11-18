<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Services\StorageIntegrity;

/**
 * ADR 0009: point every FileObject at its content-addressed blob and give raw-copied assets their original,
 * through the same repair `dam:storage:repair --force` runs. Almost every legacy row already has its blob
 * (the old observer copied each ingest there), so this repoints metadata; it copies only a blob that is
 * missing, hashes only those candidates and raw-copy backfills, and never re-hashes the library. A row it
 * cannot resolve is logged to storage/logs/storage-integrity.jsonl and left for `dam:storage:check`.
 *
 * Each row commits on its own, so an interrupted run resumes where it stopped.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('dam_file_objects') || ! Schema::hasTable('dam_asset_files')) {
            return;
        }

        app(StorageIntegrity::class)->repair();
    }

    /** Forward-only: a repointed locator names the same bytes, so there is nothing to undo. */
    public function down(): void {}
};
