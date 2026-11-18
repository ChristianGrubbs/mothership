<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P5a (D1): one row per guest visit that sent at least one file to an upload request.
 *
 * - `guest_key` is a random pseudonym, the guest's only identity (§3.6.2: actor_key = request id ‖ submission id);
 *   the DAM never asserts who an anonymous visitor is.
 * - `visit_hash` is the SHA-256 of the visit cookie's nonce, binding every chunk call to the visit that started it.
 * - `guest_name` and `guest_email` are optional and self-reported. dam:upload-requests:prune clears them after the
 *   retention period (§3.6.7) and stamps `identity_pruned_at`; the row and its counts stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_upload_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('upload_request_id');
            $table->foreign('upload_request_id')->references('id')->on('dam_upload_requests')->cascadeOnDelete();
            $table->char('guest_key', 32)->unique();
            $table->char('visit_hash', 64)->unique();
            $table->string('guest_name', 191)->nullable();
            $table->string('guest_email', 191)->nullable();
            $table->timestamp('identity_pruned_at')->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->timestamps();

            $table->index(['upload_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_upload_submissions');
    }
};
