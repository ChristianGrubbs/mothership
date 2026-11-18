<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Services\StorageIntegrity;

/**
 * ADR 0009: give every layered image editor resource written before 2026_09_25_000500 a blob, through the same
 * ingest `dam:storage:repair --force` runs. A base usually shares its asset's original blob, so most rows only
 * gain a reference; the rest are copied in. The old `assets/.editor/` files stay for slice 4's reclaim. A row
 * whose file is missing is logged to storage/logs/storage-integrity.jsonl and left for `dam:storage:check`.
 *
 * Each row commits on its own, so an interrupted run resumes where it stopped.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('dam_image_editor_resources', 'file_object_id')) {
            return;
        }

        app(StorageIntegrity::class)->repairEditorResources();
    }

    /** Forward-only: a resource that gained a blob names the same bytes, and its old file is still there. */
    public function down(): void {}
};
