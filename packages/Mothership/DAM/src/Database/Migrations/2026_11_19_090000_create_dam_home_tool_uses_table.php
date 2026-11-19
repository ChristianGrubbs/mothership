<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each person last opened each tool, so a tile on the Tools page can say "Used Fri 13 Nov", "Never used" or
 * "New". One row per person and tool; `last_used_at` moves each time the tool's own page is served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_home_tool_uses', function (Blueprint $table): void {
            $table->unsignedInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('admins')->cascadeOnDelete();
            $table->string('widget', 40);
            $table->timestamp('last_used_at');
            $table->timestamps();
            $table->primary(['admin_id', 'widget']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_home_tool_uses');
    }
};
