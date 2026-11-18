<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_vectorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('input_sha', 64);
            $table->string('input_path');
            $table->boolean('charged');
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['admin_id', 'request_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_vectorizations');
    }
};
