<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P8′ (D25): an asset made from a template lists the template version it was made from as one of its lineage
 * inputs (role `template`), beside the image-slot revisions. No foreign key: dam_lineage_inputs already cascades
 * from dam_assets through dam_renditions, and a second path from dam_templates is SQL Server error 1785. A template
 * that has made an asset can only be archived, never deleted (TemplateService::delete), so the id stays good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_lineage_inputs', function (Blueprint $table): void {
            $table->unsignedBigInteger('template_version_id')->nullable()->after('sha256');
            $table->index('template_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('dam_lineage_inputs', function (Blueprint $table): void {
            $table->dropIndex(['template_version_id']);
            $table->dropColumn('template_version_id');
        });
    }
};
