<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sphereseam_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('model', 80);
            $table->string('bridge_ratio', 12);
            $table->string('resolution', 8)->default('2k');
            $table->string('quality', 8)->default('high');
            $table->string('normalized_path', 1024);
            $table->string('bridge_path', 1024);
            $table->string('storage_disk', 80)->default('private');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('strip_width');   // panorama columns copied per side of the rolled bridge
            $table->unsignedInteger('repair_width');  // central band the model is asked to repair
            $table->unsignedInteger('feather_width');
            $table->string('normalization_mode', 12)->default('squeeze');
            $table->text('prompt');
            $table->decimal('estimated_credits', 12, 2)->nullable();
            $table->string('provider_request_id', 180)->nullable()->index();
            $table->string('status', 24)->default('queued')->index();
            $table->text('bridge_result_url')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('dam_asset_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sphereseam_generations');
    }
};
