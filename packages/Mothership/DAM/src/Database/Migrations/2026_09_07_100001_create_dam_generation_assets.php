<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_generation_assets', function (Blueprint $table) {
            $table->id();
            $table->string('generation_id', 255)->unique();
            $table->unsignedBigInteger('asset_id');
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->json('manifest');
            $table->string('manifest_sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_generation_assets');
    }
};
