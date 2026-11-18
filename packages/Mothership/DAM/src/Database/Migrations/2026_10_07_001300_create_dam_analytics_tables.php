<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product analytics (P1b, §3.6.1-§3.6.7): raw events, daily counts, the minimized search-term store and the
 * per-admin opt-out. Retention (operator decision Q1): raw events 90 days, daily counts 25 months, search terms
 * 30 days; `dam:analytics:prune` enforces all three.
 *
 * No foreign keys on purpose. An event outlives the asset, share or admin it names: deleting an asset nulls its
 * subject on the raw rows and leaves its counts as a deleted asset's (§3.6.7), and `dam:analytics:forget` removes
 * a person's rows itself. A cascade would also add a second path to dam_assets on SQL Server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_events', function (Blueprint $table): void {
            $table->id();
            // The dedupe key: a client reuses it on every resend, a server attempt or terminal event derives it.
            $table->char('event_id', 36)->unique();
            $table->string('name', 64);
            $table->unsignedSmallInteger('v');
            $table->timestamp('occurred_at', 3);
            $table->timestamp('received_at', 3);
            // A client-random session id, never Laravel's; seq orders a session, the clock only breaks ties.
            $table->char('session_id', 36)->nullable();
            $table->unsignedInteger('seq')->nullable();
            $table->string('actor_type', 24);
            // admin id, or HMAC(daily salt, link ‖ IP/24 ‖ browser family) for a link visitor. Never null here:
            // anything without a key goes to dam_event_counts_daily instead.
            $table->string('actor_key', 64);
            $table->unsignedInteger('actor_role_id')->nullable();
            $table->string('surface', 16)->nullable();
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedBigInteger('share_id')->nullable();
            $table->string('outcome', 10);
            $table->string('request_id', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('props')->nullable();
            $table->string('emitter', 8);
            $table->boolean('suspected_bot')->default(false);

            $table->index('occurred_at');
            $table->index(['name', 'occurred_at']);
            $table->index(['session_id', 'seq']);
            $table->index('actor_key');
            $table->index(['subject_type', 'subject_id']);
            $table->index('share_id');
        });

        // One row per day and slice. source `rollup` is recomputed from dam_events (dam:analytics:rollup);
        // source `aggregate` is the count-only path (DNT/GPC, opted-out staff) and is never recomputed. Empty
        // dimensions are '' or 0, not null, so the unique key holds on every database.
        Schema::create('dam_event_counts_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('name', 64);
            $table->string('outcome', 10);
            $table->string('actor_type', 24);
            $table->unsignedInteger('role_id')->default(0);
            $table->string('surface', 16)->default('');
            $table->string('subject_type', 16)->default('');
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->unsignedBigInteger('share_id')->default(0);
            // A short code (an error code, a drop reason, `zip`); never free text.
            $table->string('detail', 32)->default('');
            $table->string('source', 10);
            $table->unsignedInteger('events')->default(0);
            $table->unsignedInteger('counted')->default(0);
            $table->unsignedInteger('actors')->default(0);
            $table->unsignedInteger('nojs')->default(0);
            $table->unsignedInteger('bots')->default(0);

            $table->unique(
                ['day', 'name', 'outcome', 'actor_type', 'role_id', 'surface', 'subject_type', 'subject_id', 'share_id', 'detail', 'source'],
                'dam_event_counts_daily_slice_unique',
            );
            $table->index(['name', 'day']);
            $table->index(['subject_type', 'subject_id']);
        });

        // §3.6.7: search text lives only here, never in dam_events, for authenticated admins only, 30 days.
        Schema::create('dam_search_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('term_norm', 100);
            $table->string('actor_key', 64);
            $table->unsignedInteger('role_id')->nullable();
            $table->date('day');
            $table->unsignedInteger('result_count');
            $table->timestamp('created_at')->nullable();

            $table->index('day');
            $table->index('term_norm');
            $table->index('actor_key');
        });

        // Presence is the opt-out (`analytics_opt_out`, §3.6.7). Kept apart from `admins` so the DAM owns it.
        Schema::create('dam_analytics_opt_outs', function (Blueprint $table): void {
            $table->unsignedInteger('admin_id')->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_analytics_opt_outs');
        Schema::dropIfExists('dam_search_terms');
        Schema::dropIfExists('dam_event_counts_daily');
        Schema::dropIfExists('dam_events');
    }
};
