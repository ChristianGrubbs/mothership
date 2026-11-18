<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Attachment slots (P3a S2). A slot is one file position inside an asset entry; its dam_asset_files rows
 * are that slot's revisions, exactly one of them current. The representative slot is the one whose
 * current revision is the asset's `original`.
 *
 * dam_asset_files.attachment_id has no foreign key on purpose: slots and files both cascade from the
 * asset already, and a third key would be a second cascade path (SQL Server error 1785) and a
 * mid-cascade check on InnoDB (error 1451, see Asset::releaseFileOwnership). AssetFile's saving guard
 * keeps a revision inside its own asset's slot instead, as AssetVersion does for its primary file.
 *
 * The (dam_asset_id, file_object_id, role) unique key goes: A -> B -> A stores blob A twice in one slot.
 * Its leftmost column served InnoDB's dam_asset_id foreign key, so an index takes its place first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_asset_id')->constrained('dam_assets')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('label')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_representative')->default(false);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['dam_asset_id', 'position'], $this->indexName('dam_attachments_asset_position_index'));
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedInteger('revision_number')->nullable();
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('created_by')->nullable();

            $table->index(['dam_asset_id', 'role'], $this->indexName('dam_asset_files_asset_role_index'));
            $table->unique(['attachment_id', 'revision_number'], $this->indexName('dam_asset_files_attachment_revision_unique'));
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->dropUnique(['dam_asset_id', 'file_object_id', 'role']);
        });

        $this->partialUnique(
            table: 'dam_attachments',
            column: 'dam_asset_id',
            flag: 'is_representative',
            index: 'dam_attachments_one_representative_per_asset',
            virtual: 'representative_asset_id',
        );
        $this->partialUnique(
            table: 'dam_asset_files',
            column: 'attachment_id',
            flag: 'is_current',
            index: 'dam_asset_files_one_current_per_attachment',
            virtual: 'current_attachment_id',
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ([
            ['dam_asset_files', 'dam_asset_files_one_current_per_attachment', 'current_attachment_id'],
            ['dam_attachments', 'dam_attachments_one_representative_per_asset', 'representative_asset_id'],
        ] as [$table, $index, $virtual]) {
            if (in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($this->indexName($index)));
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($virtual));
            }
        }

        // Fails while one slot holds the same blob twice (A -> B -> A); collapse those revisions first.
        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->unique(['dam_asset_id', 'file_object_id', 'role']);
        });

        Schema::table('dam_asset_files', function (Blueprint $table) {
            $table->dropUnique($this->indexName('dam_asset_files_attachment_revision_unique'));
            $table->dropIndex($this->indexName('dam_asset_files_asset_role_index'));
            $table->dropColumn(['attachment_id', 'revision_number', 'is_current', 'created_by']);
        });

        Schema::dropIfExists('dam_attachments');
    }

    /**
     * At most one row per $column with $flag set: a partial index on PostgreSQL and SQLite, a unique
     * index on a VIRTUAL generated column on MySQL and MariaDB (the one-original pattern of
     * 2026_09_02_000003), and the writer's transaction on SQL Server.
     */
    private function partialUnique(string $table, string $column, string $flag, string $index, string $virtual): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $name = $this->indexName($index);

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $grammar = $connection->getQueryGrammar();

            $connection->statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s = true',
                $grammar->wrap($name),
                $grammar->wrapTable($table),
                $grammar->wrap($column),
                $grammar->wrap($flag),
            ));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $flag, $name, $virtual) {
                $blueprint->unsignedBigInteger($virtual)
                    ->nullable()
                    ->virtualAs("CASE WHEN {$flag} = 1 THEN {$column} ELSE NULL END");

                $blueprint->unique($virtual, $name);
            });
        }
    }

    /** Index names carry the table prefix, as in 2026_09_02_000003. */
    private function indexName(string $name): string
    {
        return DB::connection()->getTablePrefix().$name;
    }
};
