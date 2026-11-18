<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P4 (D4, D13): labels and boards on one tree and one membership table (roadmap §3.2). A label is a team-curated,
 * nestable grouping (`kind` shared, no owner); a board is a personal label (`kind` board, owned, private to its
 * owner). Neither grants access: members are always read through the access seam, so a member the viewer cannot
 * open is left out of every listing and count, and comes back when access returns.
 *
 * parent_id has no foreign key: a self-referencing cascade is a cycle on SQL Server (error 1785), so LabelService
 * deletes a subtree itself. dam_label_assets.added_by has none either: admins already reach the table through
 * dam_labels.owner_id, and a second path from admins (even SET NULL) is again error 1785 on SQL Server.
 */
return new class extends Migration
{
    public function up(): void
    {
        // As the access lists (2026_09_25_000001): admins already reach these rows through dam_assets.created_by
        // (SET NULL) and the asset key, so on SQL Server owner_id is NO ACTION and DeleteAdminAccessRows removes an
        // admin's boards and saved searches before the admin row goes. Every other driver cascades.
        $adminCascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_labels', function (Blueprint $table) use ($adminCascade): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 191);
            $table->string('kind', 16)->default('shared');
            $table->unsignedInteger('owner_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('admins')->onDelete($adminCascade);
            $table->index(['kind', 'owner_id', 'parent_id', 'position']);
        });

        Schema::create('dam_label_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('label_id')->constrained('dam_labels')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('added_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['label_id', 'asset_id']);
            $table->index(['label_id', 'position']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_label_assets');
        Schema::dropIfExists('dam_labels');
    }
};
