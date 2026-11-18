<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_posthog_outbox', function (Blueprint $table): void {
            $table->id();
            $table->char('event_id', 36);
            $table->string('name', 64);
            $table->text('payload')->nullable();
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 32)->nullable();
            $table->unique(['event_id', 'name']);
            $table->index(['state', 'available_at']);
            // Local forget/prune removes pending export data along with its source; no second copy survives.
            $table->foreign('event_id')->references('event_id')->on('dam_events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_posthog_outbox');
    }
};
