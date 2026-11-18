<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
        Schema::create('dam_group_members', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id');
            $table->unsignedInteger('admin_id');
            $table->primary(['group_id', 'admin_id']);
            $table->index(['admin_id', 'group_id']);
            $table->foreign('group_id')->references('id')->on('dam_groups')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
        Schema::create('dam_directory_user', function (Blueprint $table) {
            $table->unsignedBigInteger('directory_id');
            $table->unsignedInteger('admin_id');
            $table->boolean('inherit_children')->default(false);
            $table->primary(['directory_id', 'admin_id']);
            $table->index(['admin_id', 'directory_id']);
            $table->foreign('directory_id')->references('id')->on('dam_directories')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
        });
        Schema::create('dam_directory_group', function (Blueprint $table) {
            $table->unsignedBigInteger('directory_id');
            $table->unsignedBigInteger('group_id');
            $table->boolean('inherit_children')->default(false);
            $table->primary(['directory_id', 'group_id']);
            $table->index(['group_id', 'directory_id']);
            $table->foreign('directory_id')->references('id')->on('dam_directories')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('dam_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_directory_group');
        Schema::dropIfExists('dam_directory_user');
        Schema::dropIfExists('dam_group_members');
        Schema::dropIfExists('dam_groups');
    }
};
