<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fonts and brushes people upload for the Creative Hub apps (ADR 0015). The bytes are a content-addressed FileObject
 * (ADR 0009), shared by every row and asset that holds the same bytes; one owner holds a given blob once. Visibility is
 * the owner's choice (private, everyone or groups, ADR 0010's principals); sharing records a license attestation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server allows no RESTRICT, and rejects a second cascade path into one table (error 1785), as ADR 0010
        // records for the access lists. There owner_id is NO ACTION, and HubResourceStore::releaseOwnedBy() removes an
        // admin's resources before the admin row goes. Every other driver cascades; releaseOwnedBy() runs first
        // there too, so the blobs are tombstoned rather than left behind by the cascade.
        $sqlsrv = DB::getDriverName() === 'sqlsrv';
        $restrict = $sqlsrv ? 'no action' : 'restrict';
        $adminCascade = $sqlsrv ? 'no action' : 'cascade';

        Schema::create('dam_hub_resources', function (Blueprint $table) use ($restrict, $adminCascade): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('owner_id');
            $table->string('kind', 16);
            $table->string('format', 16);
            $table->string('name', 255);
            $table->string('family', 255)->nullable();
            $table->string('subfamily', 255)->nullable();
            $table->unsignedSmallInteger('weight')->nullable();
            $table->string('style', 16)->nullable();
            $table->string('postscript_name', 255)->nullable();
            $table->unsignedBigInteger('file_object_id');
            $table->unsignedBigInteger('size');
            $table->json('meta')->nullable();
            $table->string('visibility', 16)->default('private');
            $table->text('license_note')->nullable();
            $table->timestamp('attested_at')->nullable();
            // An audit record, so no foreign key: it outlives the admin who attested, and a second key into admins
            // would be another cascade path on SQL Server.
            $table->unsignedInteger('attested_by')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'file_object_id']);
            $table->index(['owner_id', 'kind']);
            $table->index(['visibility', 'kind']);
            $table->index('file_object_id');
            $table->foreign('owner_id')->references('id')->on('admins')->onDelete($adminCascade);
            $table->foreign('file_object_id')->references('id')->on('dam_file_objects')->onDelete($restrict);
        });

        Schema::create('dam_hub_resource_group_access', function (Blueprint $table): void {
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('group_id');
            $table->primary(['resource_id', 'group_id']);
            $table->index(['group_id', 'resource_id']);
            $table->foreign('resource_id')->references('id')->on('dam_hub_resources')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('dam_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_hub_resource_group_access');
        Schema::dropIfExists('dam_hub_resources');
    }
};
