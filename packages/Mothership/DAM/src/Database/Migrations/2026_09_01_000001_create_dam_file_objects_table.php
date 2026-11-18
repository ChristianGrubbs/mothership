<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_file_objects', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->bigInteger('size');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->string('sniffed_type')->nullable();
            $table->json('technical')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_file_objects');
    }
};
