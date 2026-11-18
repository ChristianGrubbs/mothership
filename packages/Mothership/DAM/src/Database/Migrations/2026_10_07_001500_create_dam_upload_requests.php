<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P5a (D1): upload requests, the links a guest uses to send files into one intake folder.
 *
 * - The token is stored as the share tokens are (P2 S5a): a SHA-256 lookup hash plus an APP_KEY-encrypted copy, never
 *   the plaintext, so a database dump alone yields no working link.
 * - `directory_id` is the intake folder, chosen by staff; the guest never names a destination. Deleting the folder
 *   deletes its requests (one cascade path: dam_directories -> requests -> submissions).
 * - `allowed_types` is a JSON list of extensions (`pdf`) and MIME patterns (`image/*`); null accepts any type the
 *   Library accepts. `max_files` counts files across every visit; `max_bytes_per_file` bounds one file.
 * - `status` is `open` or `closed`. An expired request stays `open` in the row and reads as closed by its expiry.
 *
 * created_by carries no foreign key, as dam_shares.created_by carries none: a request outlives nothing it needs from
 * the admin row, and a deleted creator closes it at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_upload_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->text('instructions')->nullable();
            $table->unsignedBigInteger('directory_id');
            $table->foreign('directory_id')->references('id')->on('dam_directories')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('password_hash')->nullable();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_files')->nullable();
            $table->unsignedBigInteger('max_bytes_per_file')->nullable();
            $table->text('allowed_types')->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('directory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_upload_requests');
    }
};
