<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dam_events', 'internal_actor')) {
            return;
        }
        Schema::table('dam_events', function (Blueprint $table): void {
            $table->boolean('internal_actor')->default(false);
        });
    }

    public function down(): void
    {
        // Additive and defaulted: older code ignores it, so it is retained rather than dropping recorded data.
    }
};
