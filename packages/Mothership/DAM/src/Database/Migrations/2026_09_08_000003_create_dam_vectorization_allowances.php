<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_vectorization_allowances', function (Blueprint $table) {
            $table->unsignedInteger('admin_id')->primary();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->unsignedInteger('remaining')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_vectorization_allowances');
    }
};
