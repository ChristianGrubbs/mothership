<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_creative_hub_saves', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('admin_id');
            $table->uuid('request_id');
            $table->char('request_sha256', 64);
            $table->unsignedBigInteger('asset_id');
            $table->timestamps();

            $table->unique(['admin_id', 'request_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_creative_hub_saves');
    }
};
