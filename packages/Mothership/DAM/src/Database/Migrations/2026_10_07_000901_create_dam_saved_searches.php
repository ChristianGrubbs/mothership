<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P4 (D12, D3): saved searches and sections share one table and one query definition (the Library filter JSON,
 * roadmap §3.2). A saved search (`kind` search) belongs to its owner, private unless shared. A section (`kind`
 * section) is an admin-defined, shared view with an asset-type rule, a default sort and manual pins; it has no owner
 * and survives the admin who made it (created_by keeps who that was, without a key).
 *
 * `definition` holds the query only, never result ids: re-running it applies the viewer's current access.
 *
 * dam_section_pins mirrors dam_label_assets (asset, container, position, added_by, created_at) so position sorting
 * has one shape. added_by has no foreign key for the same reason as there: admins already reach it through
 * dam_saved_searches.owner_id, and a second path is SQL Server error 1785.
 */
return new class extends Migration
{
    public function up(): void
    {
        // As the access lists (2026_09_25_000001): admins already reach these rows through dam_assets.created_by
        // (SET NULL) and the asset key, so on SQL Server owner_id is NO ACTION and DeleteAdminAccessRows removes an
        // admin's boards and saved searches before the admin row goes. Every other driver cascades.
        $adminCascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_saved_searches', function (Blueprint $table) use ($adminCascade): void {
            $table->id();
            $table->string('kind', 16)->default('search');
            $table->unsignedInteger('owner_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('name', 191);
            $table->text('definition');
            $table->boolean('pinned')->default(false);
            $table->string('visibility', 16)->default('private');
            $table->string('default_sort', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('admins')->onDelete($adminCascade);
            $table->index(['kind', 'owner_id', 'position']);
            $table->index(['kind', 'visibility']);
        });

        Schema::create('dam_section_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('dam_saved_searches')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('added_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['section_id', 'asset_id']);
            $table->index(['section_id', 'position']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_section_pins');
        Schema::dropIfExists('dam_saved_searches');
    }
};
