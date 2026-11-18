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
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        Schema::create('dam_asset_files', function (Blueprint $table) use ($restrict) {
            $table->id();
            $table->foreignId('dam_asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->foreignId('file_object_id')->constrained('dam_file_objects')->onDelete($restrict);
            $table->string('role')->index();
            $table->string('label')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['dam_asset_id', 'file_object_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_files');
    }
};
