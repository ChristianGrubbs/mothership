<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_chunk_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('upload_tracker_id')->nullable()->constrained('dam_upload_trackers')->nullOnDelete();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedBigInteger('directory_id')->index();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension', 32)->nullable();
            $table->text('relative_path')->nullable();
            $table->boolean('preserve_root')->default(false);
            $table->unsignedBigInteger('expected_bytes');
            $table->unsignedBigInteger('source_modified_ms');
            $table->char('source_fingerprint', 64);
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('chunk_count');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedBigInteger('finalization_bytes')->default(0);
            $table->string('state')->index();
            $table->string('staged_root');
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('failure_code')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_chunk_uploads');
    }
};
