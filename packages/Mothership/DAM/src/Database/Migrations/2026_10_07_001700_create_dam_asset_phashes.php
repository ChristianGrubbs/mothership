<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5c (D16 near-duplicates): a perceptual hash per asset and the near-duplicate pairs it finds.
 *
 * - dam_asset_phashes: one row per asset, for the bytes (`sha256`) and algorithm it was computed from, so a new
 *   version or a new algorithm recomputes it. `dhash` is the 64-bit difference hash as a signed BIGINT; band0-band3
 *   are its four 16-bit slices, each indexed, which is how a lookup finds every hash within the threshold without a
 *   scan (Services/Similarity/DHash). `status` is hashed, flat (no detail to compare, never paired) or skipped,
 *   with a short reason.
 * - dam_asset_similar_pairs: one row per pair within the threshold, the lower asset id first. `pair_key` is
 *   "low:high", the group key the Library's dismissals store, so a dismissal is one NOT EXISTS away.
 *
 * Only the lower id of a pair has a foreign key: a second cascade path from dam_assets is SQL Server error 1785.
 * Readers join both sides to dam_assets, so a pair naming a purged asset is never listed, and the next hash of the
 * survivor or `dam:phash:backfill --prune` removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_asset_phashes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->unique()->constrained('dam_assets')->cascadeOnDelete();
            $table->char('sha256', 64)->nullable();
            $table->string('algorithm', 16);
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->bigInteger('dhash')->nullable();
            $table->unsignedInteger('band0')->nullable()->index();
            $table->unsignedInteger('band1')->nullable()->index();
            $table->unsignedInteger('band2')->nullable()->index();
            $table->unsignedInteger('band3')->nullable()->index();
            $table->timestamp('computed_at')->nullable();
        });

        Schema::create('dam_asset_similar_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('other_asset_id')->index();
            $table->string('pair_key', 48)->unique();
            $table->unsignedTinyInteger('distance');
            $table->timestamp('created_at')->nullable();

            $table->index(['asset_id', 'distance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_similar_pairs');
        Schema::dropIfExists('dam_asset_phashes');
    }
};
