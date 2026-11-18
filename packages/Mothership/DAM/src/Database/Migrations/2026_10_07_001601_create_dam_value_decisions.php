<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5b (roadmap §3.4): a person's removal of a field value, a label or a section pin from an asset. While a row
 * exists no mapping or rule puts that value back on that asset; setting it again by hand deletes the row. Tags keep
 * P4d's own table (dam_asset_tag_tombstones), which every tag reader already uses.
 *
 * `kind` is field, label or section and `target_id` that field's, label's or section's id. target_id has no foreign
 * key (it points at three tables); the label, section and field deletes clear their rows. decided_by has none
 * either: a deleted admin leaves the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_value_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('decision', 16)->default('removed');
            $table->unsignedInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->unique(['asset_id', 'kind', 'target_id'], 'dam_value_decisions_unique');
            $table->index(['kind', 'target_id'], 'dam_value_decisions_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_value_decisions');
    }
};
