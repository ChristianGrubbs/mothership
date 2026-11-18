<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 (D11): each person's Library sort, kept on the server so it follows them between browsers. The Library reads
 * localStorage only as a fallback until this answers. A row exists only once someone picks a sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_library_preferences', function (Blueprint $table): void {
            $table->unsignedInteger('admin_id')->primary();
            $table->string('sort', 32)->nullable();
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_library_preferences');
    }
};
