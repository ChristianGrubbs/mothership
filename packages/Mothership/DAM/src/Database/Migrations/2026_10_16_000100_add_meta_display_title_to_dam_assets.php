<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset::displayNameSql() ordered, searched and labelled every Library card through two `meta_data->>'Title'` /
 * `->>'title'` lookups. meta_data is `json` (not jsonb), so each lookup re-parses the whole text of every row: about
 * 60 us per row, 88 ms for a name-sorted page of a 1,440-asset Library, climbing linearly with it.
 *
 * `meta_display_title` is those two lookups, computed by the database itself whenever a row is written, so no
 * application writer (Eloquent, a bulk update, raw SQL) can leave it stale. It is a stored generated column on
 * PostgreSQL and MySQL/MariaDB (read as a plain column); SQLite cannot add a STORED generated column to an existing
 * table, so there it is VIRTUAL and computed on read exactly as the old expression was.
 *
 * It holds `COALESCE(NULLIF(TRIM(Title), ''), NULLIF(TRIM(title), ''))`, in the order the old chain tried them, so
 * displayNameSql() returns the same name for every row (AssetDisplayNameSortTest proves the ordering). It is not
 * indexed: the display name also depends on dam_asset_properties (a per-row subquery), which no index on dam_assets
 * can cover, and the cost this removes is the JSON parse, not the sort.
 */
return new class extends Migration
{
    private const COLUMN = 'meta_display_title';

    public function up(): void
    {
        if (Schema::hasColumn('dam_assets', self::COLUMN)) {
            return;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable('dam_assets');
        $column = $grammar->wrap(self::COLUMN);
        $expression = 'COALESCE(NULLIF(TRIM('.$grammar->wrap('meta_data->Title')."), ''), NULLIF(TRIM(".$grammar->wrap('meta_data->title')."), ''))";
        $storage = $connection->getDriverName() === 'sqlite' ? 'VIRTUAL' : 'STORED';
        $type = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'LONGTEXT' : 'TEXT';

        $connection->statement("ALTER TABLE {$table} ADD COLUMN {$column} {$type} GENERATED ALWAYS AS ({$expression}) {$storage}");
    }

    public function down(): void
    {
        if (Schema::hasColumn('dam_assets', self::COLUMN)) {
            Schema::table('dam_assets', fn ($table) => $table->dropColumn(self::COLUMN));
        }
    }
};
