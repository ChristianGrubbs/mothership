<?php

use Illuminate\Database\Migrations\Migration;
use Mothership\DAM\Database\Backfill\TagSourceBackfill;

/*
 * Relabels as `ai` every tag row that came from an accepted AI suggestion; the rest stay `manual`. The backfill
 * reconciles its counts and throws on a mismatch, which rolls it back. Rehearse first with
 * `php artisan dam:tags:backfill-source --dry-run` on a restored copy of production.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TagSourceBackfill::class)->run();
    }

    public function down(): void
    {
        // The column goes with 2026_10_07_001000.
    }
};
