<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 (D10): an indexed orientation (landscape, portrait, square) for the Library filter, set at ingest from the
 * stored width and height (AssetOrientation). Existing rows stay null until `php artisan dam:orientation-backfill
 * --dry-run`, then `--apply`, fills them with reconciled counts; null means "unknown" and matches no orientation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->string('orientation', 16)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['orientation']);
            $table->dropColumn('orientation');
        });
    }
};
