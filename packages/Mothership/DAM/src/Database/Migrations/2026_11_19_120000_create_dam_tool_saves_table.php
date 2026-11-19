<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tool made a file that was saved into the Library from a tool's page, and who saved it. One row per file,
 * written once when the file is saved. It goes when the file is deleted for good; it keeps the tool when the
 * person's account is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_tool_saves', function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_id')->primary();
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->string('widget', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_tool_saves');
    }
};
