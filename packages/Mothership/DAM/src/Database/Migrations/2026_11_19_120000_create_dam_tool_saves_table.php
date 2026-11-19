<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which tool made a file that was saved into the Library from a tool's page, and who saved it. One row per file,
 * written once when the file is saved. It goes when the file is deleted for good; it keeps the tool when the
 * person's account is removed.
 *
 * As dam_collections.owner_id: admins already reach these rows through dam_assets.created_by (SET NULL) and the
 * asset key, and SQL Server refuses a second path from admins into one table, even SET NULL (error 1785). So there
 * admin_id is NO ACTION and DeleteAdminAccessRows sets it to null before the admin row goes. Every other driver
 * sets it to null with the delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminDelete = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::create('dam_tool_saves', function (Blueprint $table) use ($adminDelete): void {
            $table->unsignedBigInteger('asset_id')->primary();
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('admin_id')->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete($adminDelete);
            $table->string('widget', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_tool_saves');
    }
};
