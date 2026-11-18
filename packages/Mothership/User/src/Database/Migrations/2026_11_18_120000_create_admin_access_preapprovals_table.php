<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email addresses an administrator has let in ahead of their first provider sign-in. A row is deleted
     * when it is used or removed, and goes with its role, so the person falls back to the waiting list.
     */
    public function up(): void
    {
        Schema::create('admin_access_preapprovals', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_access_preapprovals');
    }
};
