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
        // SQL Server rejects this SET NULL as a second path from dam_assets (through
        // dam_asset_files) into the table (error 1785). 2026_09_02_000002 replaces it on every
        // driver with a NO ACTION owner key, so there it starts as NO ACTION instead.
        $setNull = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::create('dam_asset_versions', function (Blueprint $table) use ($setNull) {
            $table->id();
            $table->foreignId('dam_asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->integer('number');
            $table->unsignedInteger('created_by')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('primary_asset_file_id')->nullable()->constrained('dam_asset_files')->onDelete($setNull);
            $table->timestamp('created_at')->nullable();

            $table->unique(['dam_asset_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_versions');
    }
};
