<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5b (D19): automation rules, their runs and one log row per action an evaluation decided.
 *
 * - dam_automation_rules: `version` goes up on every edit; owner_id is the admin whose rights every action runs
 *   with. owner_id has no foreign key: a deleted owner leaves a rule whose every action is refused.
 * - dam_automation_runs: one evaluation of one rule version for one triggering event. The unique key is the loop
 *   guard's run key (§3.4): a redelivered trigger finds its row and writes nothing.
 * - dam_automation_run_actions: per asset and action, before, after and outcome (written, skipped or refused with
 *   a reason). Unique per run, asset and action, so a redelivered chunk logs nothing twice. asset_id has no foreign
 *   key: the log outlives the asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->string('trigger_type', 32);
            $table->unsignedBigInteger('trigger_field_id')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->unsignedInteger('owner_id')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'trigger_type'], 'dam_automation_rules_trigger_idx');
        });

        Schema::create('dam_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('dam_automation_rules')->cascadeOnDelete();
            $table->unsignedInteger('rule_version');
            $table->string('trigger_event_id', 128);
            $table->string('trigger_type', 32);
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('directory_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('assets_total')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('written')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'rule_version', 'trigger_event_id'], 'dam_automation_runs_key');
            $table->index(['rule_id', 'id'], 'dam_automation_runs_rule_idx');
        });

        Schema::create('dam_automation_run_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('dam_automation_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedSmallInteger('action_index');
            $table->string('action_type', 32);
            $table->string('target', 191)->nullable();
            $table->text('value_before')->nullable();
            $table->text('value_after')->nullable();
            $table->string('outcome', 16);
            $table->string('reason', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'asset_id', 'action_index'], 'dam_automation_run_actions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_automation_run_actions');
        Schema::dropIfExists('dam_automation_runs');
        Schema::dropIfExists('dam_automation_rules');
    }
};
