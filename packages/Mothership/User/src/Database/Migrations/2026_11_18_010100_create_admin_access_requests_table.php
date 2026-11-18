<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider sign-ins with no linked admin wait here until an operator grants or links them from the
     * command line. One row per provider account; rows expire and the open count is capped.
     */
    public function up(): void
    {
        Schema::create('admin_access_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('provider', 64);
            $table->string('subject');
            $table->string('email');
            $table->boolean('email_verified')->default(false);
            $table->string('name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->unsignedInteger('admin_id')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject'], 'admin_access_requests_provider_subject_unique');
            $table->index(['status', 'expires_at']);
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_access_requests');
    }
};
