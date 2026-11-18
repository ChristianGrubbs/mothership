<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 S5b: per-link access options.
 * - `password_hash`: an optional password, stored only as Hash::make() output. Null means no password.
 * - `allow_download`: off makes the link preview-only. Defaults to on, today's behaviour for every existing link.
 * - `requires_creator_access`: on makes the link resolve only while its creator can still see the target. Off by
 *   default, so no existing link changes.
 *
 * Plain added columns with constant defaults: no index, no backfill, safe on every supported driver. Down drops them
 * (SQL Server's default constraints go with the column through the schema grammar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_shares', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('token_encrypted');
            $table->boolean('allow_download')->default(true)->after('password_hash');
            $table->boolean('requires_creator_access')->default(false)->after('allow_download');
        });
    }

    public function down(): void
    {
        Schema::table('dam_shares', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'allow_download', 'requires_creator_access']);
        });
    }
};
