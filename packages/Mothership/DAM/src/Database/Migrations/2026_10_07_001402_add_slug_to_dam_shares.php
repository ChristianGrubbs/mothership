<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P4 D23 for ordinary share links: an optional `slug` on `dam_shares`, opened at `/s/{slug}`.
 *
 * - `dam_shares.slug`: nullable and unique, through a filtered index on SQL Server (which otherwise admits one NULL).
 * - `dam_slug_redirects` becomes the one register of every slug a collection or a share has claimed, current ones
 *   included (AddressSlugs). Its unique `slug` index is what keeps a slug unique across both tables, even when two
 *   writers race. Each row now belongs to a collection OR a share, so `collection_id` turns nullable and `share_id`
 *   joins it; deleting the owner deletes its rows.
 *
 * SQL Server: dam_shares.created_by already sets null on an admin delete, and dam_collections.owner_id is NO ACTION
 * there (2026_10_07_001400), so share_id's cascade adds no second cascade path into this table. No backfill: no
 * deployed database holds a collection slug yet (the collections migration ships in the same release).
 *
 * Down drops the share rows first, so collection_id can be NOT NULL again.
 */
return new class extends Migration
{
    private const SLUG_INDEX = 'dam_shares_slug_unique';

    public function up(): void
    {
        Schema::table('dam_shares', function (Blueprint $table): void {
            $table->string('slug', 64)->nullable()->after('name');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::SLUG_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_shares').' (slug) WHERE slug IS NOT NULL');
        } else {
            Schema::table('dam_shares', function (Blueprint $table): void {
                $table->unique('slug', self::SLUG_INDEX);
            });
        }

        $this->collectionColumn(nullable: true);

        Schema::table('dam_slug_redirects', function (Blueprint $table): void {
            $table->unsignedBigInteger('share_id')->nullable()->after('collection_id');
            $table->index('share_id');
            $table->foreign('share_id')->references('id')->on('dam_shares')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('dam_slug_redirects')->whereNotNull('share_id')->delete();
        DB::table('dam_slug_redirects')->whereNull('collection_id')->delete();

        Schema::table('dam_slug_redirects', function (Blueprint $table): void {
            $table->dropForeign(['share_id']);
            $table->dropIndex(['share_id']);
        });
        Schema::table('dam_slug_redirects', function (Blueprint $table): void {
            $table->dropColumn('share_id');
        });

        $this->collectionColumn(nullable: false);

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::SLUG_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_shares'));
        } else {
            Schema::table('dam_shares', function (Blueprint $table): void {
                $table->dropUnique(self::SLUG_INDEX);
            });
        }

        Schema::table('dam_shares', function (Blueprint $table): void {
            $table->dropColumn('slug');
        });
    }

    /**
     * Changes dam_slug_redirects.collection_id's nullability. MySQL and SQL Server refuse to alter a column a foreign
     * key (and, on SQL Server, an index) depends on, so both are dropped around the change and put back the same.
     */
    private function collectionColumn(bool $nullable): void
    {
        Schema::table('dam_slug_redirects', function (Blueprint $table): void {
            $table->dropForeign(['collection_id']);
            $table->dropIndex(['collection_id']);
        });

        Schema::table('dam_slug_redirects', function (Blueprint $table) use ($nullable): void {
            $table->unsignedBigInteger('collection_id')->nullable($nullable)->change();
        });

        Schema::table('dam_slug_redirects', function (Blueprint $table): void {
            $table->index('collection_id');
            $table->foreign('collection_id')->references('id')->on('dam_collections')->cascadeOnDelete();
        });
    }
};
