<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-user choice behind the SIGNAL announcement strip. NULL means "never chose": the account then follows
 * the default-plus-exceptions rule in `Mothership\DAM\Services\SignalAlerts`, so adding this column changes no existing
 * row and needs no backfill. Rolling back drops the column and every account follows the old behaviour (strip on
 * for any role that may use the RSS tool).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admins', 'signal_alerts')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table): void {
            $table->boolean('signal_alerts')->nullable()->after('use_gravatar');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admins', 'signal_alerts')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn('signal_alerts');
        });
    }
};
