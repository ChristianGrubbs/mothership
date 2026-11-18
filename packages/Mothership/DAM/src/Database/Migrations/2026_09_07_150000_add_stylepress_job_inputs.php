<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_home_generations', function (Blueprint $table) {
            $table->json('inputs')->nullable();
            $table->string('save_status')->default('pending');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->text('save_error')->nullable();
        });
        Schema::create('dam_stylepress_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('admin_id')->index();
            $table->string('path');
            $table->string('name');
            $table->string('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_stylepress_references');
        Schema::table('dam_home_generations', fn (Blueprint $table) => $table->dropColumn(['inputs', 'save_status', 'asset_id', 'save_error']));
    }
};
