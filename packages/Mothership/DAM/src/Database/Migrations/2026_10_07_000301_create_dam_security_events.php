<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only authentication and access-change events (P1a, §3.6.1). Kept 1 year, the full IP for 90 days
 * (operator decision Q1); `dam:security-events:prune` enforces both.
 *
 * actor_admin_id has no foreign key on purpose: a security record has to outlive the account it names, and a
 * cascade would delete exactly the rows an investigation of a removed account needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_security_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('event', 64);
            $table->string('guard', 32)->nullable();
            $table->unsignedInteger('actor_admin_id')->nullable();
            // HMAC-SHA256 of the lowercased submitted login, keyed with APP_KEY; never the login itself.
            $table->char('identifier_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('ip_truncated', 45)->nullable();
            $table->string('user_agent_family', 32)->nullable();
            $table->string('request_id', 64)->nullable();
            // sha256 of the session id; never the id itself.
            $table->char('session_hash', 64)->nullable();
            $table->json('ctx')->nullable();

            $table->index('occurred_at');
            $table->index(['event', 'occurred_at']);
            $table->index('actor_admin_id');
            $table->index('identifier_hash');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_security_events');
    }
};
