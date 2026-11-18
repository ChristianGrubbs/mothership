<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New accounts no longer share their email hash with Gravatar unless they opt in: the column default becomes false, so
 * rows written with the query builder (the installer, `mothership:user:create`, SSO provisioning) get false too.
 *
 * Decision (2026-10-10): this is a default change only. Existing accounts keep the value already stored, because it is
 * user state; an operator who wants them off can reset it deliberately (`update admins set use_gravatar = 0`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admins', 'use_gravatar')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('use_gravatar')->default(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admins', 'use_gravatar')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('use_gravatar')->default(true)->change();
        });
    }
};
