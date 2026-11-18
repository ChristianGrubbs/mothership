<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P4 (D2, D23): collections, curated delivery sets with an audience, an expiry and an optional slug (ADR 0011, which
 * amends ADR 0008: the Web Pages organizer keeps `dam_web_collections` and is called "Page groups" in the UI).
 *
 * - `dam_collections`: one row per collection. `audience` is `internal` (listed people and groups, signed in) or
 *   `public` (a share link, `dam_shares.share_type = collection`). `status` is `active` or `archived`. A collection
 *   is deleted outright: it holds no bytes, and its members, audience, redirects and links go with it.
 * - `dam_collection_assets`: the ordered members, the same shape as `dam_label_assets` (P4 labels), so position
 *   sorting has one implementation (roadmap §3.2).
 * - `dam_collection_audience`: the internal audience, one row per principal (`user` = admins.id, `group` =
 *   dam_groups.id). Polymorphic and without a principal foreign key: an admin or group that no longer exists
 *   simply matches nobody.
 * - `dam_slug_redirects`: a collection's previous slugs, each answering 301 to its current one (D23).
 *
 * As the access lists (2026_09_25_000001) and labels: admins already reach dam_collection_assets through
 * dam_assets.created_by (SET NULL) and the asset key, so on SQL Server owner_id is NO ACTION and
 * DeleteAdminAccessRows clears an admin's ownership before the admin row goes (error 1785). added_by has no foreign
 * key for the same reason. Every other driver sets owner_id to null with the delete: full admins then manage it.
 * Both nullable unique slugs use a filtered index on SQL Server, which otherwise admits one NULL only.
 */
return new class extends Migration
{
    private const SLUG_INDEX = 'dam_collections_slug_unique';

    public function up(): void
    {
        $ownerDelete = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'set null';

        Schema::create('dam_collections', function (Blueprint $table) use ($ownerDelete): void {
            $table->id();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('audience', 16)->default('internal');
            $table->timestamp('expires_at')->nullable();
            $table->string('slug', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('admins')->onDelete($ownerDelete);
            $table->index(['owner_id', 'status']);
            $table->index(['audience', 'status', 'expires_at']);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::SLUG_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_collections').' (slug) WHERE slug IS NOT NULL');
        } else {
            Schema::table('dam_collections', function (Blueprint $table): void {
                $table->unique('slug', self::SLUG_INDEX);
            });
        }

        Schema::create('dam_collection_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('dam_collections')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('added_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['collection_id', 'asset_id']);
            $table->index(['collection_id', 'position']);
            $table->index('asset_id');
        });

        Schema::create('dam_collection_audience', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('dam_collections')->cascadeOnDelete();
            $table->string('principal_type', 8);
            $table->unsignedBigInteger('principal_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['collection_id', 'principal_type', 'principal_id'], 'dam_collection_audience_unique');
            $table->index(['principal_type', 'principal_id']);
        });

        Schema::create('dam_slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->foreignId('collection_id')->constrained('dam_collections')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_slug_redirects');
        Schema::dropIfExists('dam_collection_audience');
        Schema::dropIfExists('dam_collection_assets');

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::SLUG_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_collections'));
        }

        Schema::dropIfExists('dam_collections');
    }
};
