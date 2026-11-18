<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Mothership\DAM\Database\Backfill\NameStemBackfill;

/*
 * Fills dam_assets.name_stem for every existing row. The backfill reconciles its counts and throws on a mismatch,
 * which rolls the run back. Rehearse first with `php artisan dam:name-stems --dry-run` on a restored copy of
 * production.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(NameStemBackfill::class)->run();
    }

    public function down(): void
    {
        // The column goes with 2026_10_07_000600.
    }
};
