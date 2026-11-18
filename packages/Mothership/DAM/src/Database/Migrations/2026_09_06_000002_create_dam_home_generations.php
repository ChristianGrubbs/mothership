<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_home_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->string('request_id');
            $table->text('status_url');
            $table->string('status')->default('queued');
            $table->text('result_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_home_generations');
    }
};
