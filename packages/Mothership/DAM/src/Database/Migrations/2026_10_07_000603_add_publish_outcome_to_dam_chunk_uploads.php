<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3b-2 (D16 exact): what a finished chunked upload did, so its status can carry the exact-duplicate notice a
 * single-request upload answers with. `published_sha256` is the checksum of the bytes it published; `publish_outcome`
 * is `created` (a new entry), `versioned` (a same-name upload that became a new version) or `unchanged` (a same-name
 * upload with identical bytes, which changed nothing). Both stay null for an attachment upload and for rows written
 * before this migration, whose status simply carries no notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->char('published_sha256', 64)->nullable();
            $table->string('publish_outcome', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table): void {
            $table->dropColumn(['published_sha256', 'publish_outcome']);
        });
    }
};
