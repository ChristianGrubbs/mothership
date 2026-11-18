<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each person last opened Home in a browser, so "new since your last visit" is real. `last_visit_at` moves on
 * every open. `previous_visit_at` is the line Home measures from: it moves only when a new visit begins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_home_visits', function (Blueprint $table): void {
            $table->unsignedInteger('admin_id')->primary();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->timestamp('previous_visit_at')->nullable();
            $table->timestamp('last_visit_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_home_visits');
    }
};
