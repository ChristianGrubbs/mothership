<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Template-based asset creation (P8′, D25). A template is a locked image-editor layout with named slots that staff
 * fill to make a new asset. dam_templates holds what a template is called and where its outputs go by default;
 * dam_template_versions holds each version's document (TemplateSchema: the locked editor document plus its slots)
 * and the rendered locked layer. A published version is immutable: editing a published template starts a new
 * draft version, so an asset made from version 1 keeps naming exactly what version 1 was.
 *
 * dam_template_resources are the images a template's layout names (its base, a background, the rendered layer,
 * a choice option's image), each a FileObject blob like an editor resource (ADR 0009), so they stay referenced
 * while the template exists and survive the source asset's deletion.
 *
 * published_version_id carries no foreign key: dam_template_versions already points at dam_templates, and the
 * reverse key would make a cycle. owner_id, created_by and published_by carry none either: the record of who made
 * or published a version outlives the admin, and reads as unknown afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        Schema::create('dam_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->text('description')->nullable();
            // draft (never published) | published | archived (no longer offered to fillers; outputs keep their lineage)
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('owner_id')->nullable();
            // Where "New from template" saves by default; the filler may pick another folder they can upload to.
            $table->unsignedBigInteger('output_directory_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();

            $table->foreign('output_directory_id')->references('id')->on('dam_directories')->nullOnDelete();
            $table->index('status');
        });

        Schema::create('dam_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('version');
            $table->json('document');
            // The locked layer the server composes slots onto: the editor's own render of `document.locked`.
            $table->uuid('layer_resource_id')->nullable();
            // Changes on every layout save, so a stale editor tab is refused (409) like the asset editor's.
            $table->uuid('revision');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('published_by')->nullable();
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('dam_templates')->cascadeOnDelete();
            $table->unique(['template_id', 'version']);
        });

        Schema::create('dam_template_resources', function (Blueprint $table) use ($restrict): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('template_id');
            // base | background | layer | option
            $table->string('kind', 16);
            $table->unsignedBigInteger('file_object_id');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            // The Library revision these bytes were taken from, when they were: provenance only, no key, so the
            // template keeps its copy (the same blob) after that asset is deleted.
            $table->unsignedBigInteger('source_asset_id')->nullable();
            $table->unsignedBigInteger('source_asset_file_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('template_id')->references('id')->on('dam_templates')->cascadeOnDelete();
            $table->foreign('file_object_id')->references('id')->on('dam_file_objects')->onDelete($restrict);
            $table->index(['template_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_template_resources');
        Schema::dropIfExists('dam_template_versions');
        Schema::dropIfExists('dam_templates');
    }
};
