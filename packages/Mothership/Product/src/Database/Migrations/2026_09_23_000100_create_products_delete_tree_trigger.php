<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQL Server only. It rejects a self-referencing cascade and a second cascade path into one table
 * (error 1785), so there products.parent_id, product_relations.child_id and
 * product_associations.related_product_id are NO ACTION, where every other driver cascades them.
 * This trigger restores those deletes: a product goes together with every variant under it, and
 * with the relation and association rows that point at any of them. The remaining cascades from
 * products run as usual on the final DELETE, which SQL Server does not route back through here.
 *
 * INSTEAD OF DELETE is allowed only while products holds no ON DELETE CASCADE foreign key of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        $prefix = DB::getTablePrefix();

        DB::unprepared(<<<SQL
            CREATE TRIGGER [{$prefix}products_delete_tree] ON [{$prefix}products] INSTEAD OF DELETE AS
            BEGIN
                SET NOCOUNT ON;

                DECLARE @tree TABLE ([id] int PRIMARY KEY);

                WITH [tree] AS (
                    SELECT [id] FROM deleted
                    UNION ALL
                    SELECT [p].[id] FROM [{$prefix}products] [p] JOIN [tree] [t] ON [p].[parent_id] = [t].[id]
                )
                INSERT INTO @tree ([id]) SELECT DISTINCT [id] FROM [tree];

                DELETE FROM [{$prefix}product_relations] WHERE [child_id] IN (SELECT [id] FROM @tree);
                DELETE FROM [{$prefix}product_associations] WHERE [related_product_id] IN (SELECT [id] FROM @tree);
                DELETE FROM [{$prefix}products] WHERE [id] IN (SELECT [id] FROM @tree);
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS ['.DB::getTablePrefix().'products_delete_tree]');
    }
};
