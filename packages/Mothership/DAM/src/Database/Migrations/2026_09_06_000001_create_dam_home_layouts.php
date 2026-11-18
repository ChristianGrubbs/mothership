<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_home_layouts', function (Blueprint $table) {
            $table->unsignedInteger('admin_id')->primary();
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->json('widgets');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('dam_home_widget_state', function (Blueprint $table) {
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->string('widget', 40);
            $table->json('state');
            $table->timestamps();
            $table->primary(['admin_id', 'widget']);
        });
        Schema::create('dam_home_tool_files', function (Blueprint $table) {
            $table->string('file_id', 36)->primary();
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_home_tool_files');
        Schema::dropIfExists('dam_home_widget_state');
        Schema::dropIfExists('dam_home_layouts');
    }
};
