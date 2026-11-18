<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['admin_password_resets', 'admin_invites'];

    /**
     * Wrong tries spent against the stored one-time code (CodeTokenRepository::MAX_ATTEMPTS ends the row's use).
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'attempts')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedTinyInteger('attempts')->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'attempts')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('attempts');
                });
            }
        }
    }
};
