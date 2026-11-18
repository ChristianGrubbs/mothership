<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_chunk_upload_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chunk_upload_id')->constrained('dam_chunk_uploads')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedBigInteger('byte_offset');
            $table->unsignedInteger('byte_length');
            $table->char('sha256', 64);
            $table->string('staged_path');
            $table->timestamps();
            $table->unique(['chunk_upload_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_chunk_upload_parts');
    }
};
