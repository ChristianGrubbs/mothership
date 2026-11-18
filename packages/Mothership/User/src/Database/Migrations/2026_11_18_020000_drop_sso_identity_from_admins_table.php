<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider links live in admin_identities now (backfilled from these columns by
     * 2026_11_18_010000), so the single sso_provider/sso_identifier pair on admins goes.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('admins', 'sso_provider')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table): void {
            $table->dropUnique('admins_sso_identity_unique');
        });

        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn(['sso_provider', 'sso_identifier']);
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->string('sso_provider')->nullable()->after('type');
            $table->string('sso_identifier')->nullable()->after('sso_provider');

            $table->unique(['sso_provider', 'sso_identifier'], 'admins_sso_identity_unique');
        });
    }
};
