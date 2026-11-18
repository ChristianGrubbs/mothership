<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQL Server rejects a second cascade path from dam_assets into one table (error 1785).
        // 2026_09_02_000001 makes both keys NO ACTION there anyway, so to_asset_id starts that way.
        $cascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_asset_relationships', function (Blueprint $table) use ($cascade) {
            $table->id();
            $table->foreignId('from_asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->foreignId('to_asset_id')->constrained('dam_assets')->onDelete($cascade);
            $table->string('type')->index();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['from_asset_id', 'to_asset_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_relationships');
    }
};
