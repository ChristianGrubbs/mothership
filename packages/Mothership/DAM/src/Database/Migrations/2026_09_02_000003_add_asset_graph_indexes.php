<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_relationships', function (Blueprint $table) {
            $table->index('to_asset_id', $this->indexName('dam_asset_relationships_to_asset_id_index'));
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->index('file_object_id', $this->indexName('dam_asset_files_file_object_id_index'));
        });

        Schema::table('dam_renditions', function (Blueprint $table) {
            $table->index('dam_asset_id', $this->indexName('dam_renditions_dam_asset_id_index'));
            $table->index('output_file_object_id', $this->indexName('dam_renditions_output_file_object_id_index'));
        });

        Schema::table('dam_file_objects', function (Blueprint $table) {
            $table->index(['disk', 'path'], $this->indexName('dam_file_objects_disk_path_index'));
        });

        Schema::create('dam_file_deletion_tombstones', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path'], $this->indexName('dam_file_deletion_tombstones_disk_path_unique'));
        });

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $index = $this->indexName('dam_asset_files_one_original_per_asset');

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $grammar = $connection->getQueryGrammar();

            // Blueprint has no partial index, so this one stays raw SQL; the
            // grammar applies the connection's table prefix to the table.
            $connection->statement(sprintf(
                "CREATE UNIQUE INDEX %s ON %s (dam_asset_id) WHERE role = 'original'",
                $grammar->wrap($index),
                $grammar->wrapTable('dam_asset_files'),
            ));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            // VIRTUAL, not STORED: dam_asset_id cascades on delete, and MySQL
            // and MariaDB refuse a stored generated column over a base column
            // a foreign key action can change (error 1215). A unique index on
            // a virtual column enforces the same one-original rule.
            Schema::table('dam_asset_files', function (Blueprint $table) use ($index) {
                $table->unsignedBigInteger('current_original_asset_id')
                    ->nullable()
                    ->virtualAs("CASE WHEN role = 'original' THEN dam_asset_id ELSE NULL END");

                $table->unique('current_original_asset_id', $index);
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $index = $this->indexName('dam_asset_files_one_original_per_asset');

        if (in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
            Schema::table('dam_asset_files', function (Blueprint $table) use ($index) {
                $table->dropIndex($index);
            });
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('dam_asset_files', function (Blueprint $table) {
                $table->dropColumn('current_original_asset_id');
            });
        }

        Schema::dropIfExists('dam_file_deletion_tombstones');

        Schema::table('dam_file_objects', function (Blueprint $table) {
            $table->dropIndex($this->indexName('dam_file_objects_disk_path_index'));
        });

        Schema::table('dam_renditions', function (Blueprint $table) {
            $table->dropIndex($this->indexName('dam_renditions_dam_asset_id_index'));
            $table->dropIndex($this->indexName('dam_renditions_output_file_object_id_index'));
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->dropIndex($this->indexName('dam_asset_files_file_object_id_index'));
        });

        Schema::table('dam_asset_relationships', function (Blueprint $table) {
            $table->dropIndex($this->indexName('dam_asset_relationships_to_asset_id_index'));
        });
    }

    /**
     * Index names share one namespace per schema on PostgreSQL and SQLite, and
     * Laravel uses an explicit name verbatim, so each one carries the table
     * prefix the way Laravel's generated names do. An empty prefix
     * (production) keeps the names these indexes already have.
     */
    private function indexName(string $name): string
    {
        return DB::connection()->getTablePrefix().$name;
    }
};
