<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Craft publish (craft_save_asset) records its evidence manifest (ADR 0016): the kind, the approved Style frames
 * and Motion proofs with their server-read sha256, the verified Floor measurement, the Bar verdict or the user's
 * quoted override, the notes and the problems found. The column is nullable and additive: rows of other saves, and
 * publishes made before this column, leave it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_creative_hub_saves', function (Blueprint $table): void {
            $table->json('evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_creative_hub_saves', function (Blueprint $table): void {
            $table->dropColumn('evidence');
        });
    }
};
