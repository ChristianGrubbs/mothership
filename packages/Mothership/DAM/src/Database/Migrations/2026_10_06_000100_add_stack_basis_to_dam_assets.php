<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an asset's files are stacked together (product, campaign, use case, other) and what binds them (a product
 * name or SKU, a campaign, a use case). Both null is a plain asset; existing rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->string('stack_basis', 16)->nullable()->index();
            $table->string('stack_key', 191)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['stack_basis']);
            $table->dropIndex(['stack_key']);
            $table->dropColumn(['stack_basis', 'stack_key']);
        });
    }
};
