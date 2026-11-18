<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Mothership\DAM\Database\Backfill\AttachmentSlotBackfill;

/*
 * Every existing dam_asset_files row gets its slot and revision number. The backfill reconciles its
 * counts and throws on any mismatch, which rolls the run back. Rehearse first with
 * `php artisan dam:attachments-migrate --dry-run` on a restored copy of production.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AttachmentSlotBackfill::class)->run();
    }

    public function down(): void
    {
        // The slot columns go with 2026_10_06_000200; rows the backfill split stay as history rows.
    }
};
