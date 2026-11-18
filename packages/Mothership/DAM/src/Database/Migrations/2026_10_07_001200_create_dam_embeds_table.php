<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 D26: tokenized embeds of a video or audio asset (`/embed/{token}`). Only the SHA-256 of the URL token is
 * stored, unique, so a database read never yields a working embed URL; the URL is shown once, when it is made.
 *
 * created_by carries no foreign key: SQL Server refuses a second cascade path from admins into this table (error
 * 1785), one direct and one through dam_assets.created_by (SET NULL) and asset_id (CASCADE). Like deleted_by on
 * dam_assets, it is a plain record of who made the embed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_embeds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_embeds');
    }
};
