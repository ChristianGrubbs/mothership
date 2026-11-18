<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave-2 integration of P5b and P4c (roadmap §3.4): a collection membership carries who wrote it, like label and
 * section memberships (2026_10_07_001600), so a rule's "add to collection" writes `rule:<id>@v<n>`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_collection_assets', function (Blueprint $table): void {
            $table->string('source', 64)->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('dam_collection_assets', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
