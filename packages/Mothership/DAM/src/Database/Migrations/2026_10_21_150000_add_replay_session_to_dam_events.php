<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dam_events', 'replay_session_id')) {
            return;
        }
        Schema::table('dam_events', function (Blueprint $table): void {
            $table->char('replay_session_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        // Roll back the application/configuration, not recorded linkage data. The nullable additive column is
        // intentionally retained so older code and a later reactivation can coexist without losing evidence.
    }
};
