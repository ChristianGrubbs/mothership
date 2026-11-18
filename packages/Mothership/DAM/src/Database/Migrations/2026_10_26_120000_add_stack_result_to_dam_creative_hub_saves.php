<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Craft stack save (craft_save_asset with stack_asset_id) records the stack member it created and the receipt it
 * answered, so a retry with the same request_id returns that recorded result instead of attaching again. Both
 * columns are nullable and additive: rows of other saves leave them empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_creative_hub_saves', function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->json('receipt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_creative_hub_saves', function (Blueprint $table): void {
            $table->dropColumn(['asset_file_id', 'receipt']);
        });
    }
};
