<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3b-2 (D5 suggestions): a stack suggestion one admin dismissed, by its kind (`key`, `stem` or `capture`) and the
 * value its members share. Per admin, so one person's Dismiss never hides a suggestion from anyone else. The only
 * foreign key is the admin's, so SQL Server sees a single cascade path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_stack_suggestion_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('admin_id');
            $table->string('kind', 16);
            $table->string('group_key', 191);
            $table->timestamps();
            $table->unique(['admin_id', 'kind', 'group_key'], 'dam_stack_dismissals_unique');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_stack_suggestion_dismissals');
    }
};
