<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->isSqlite()) {
            $this->changeTypeOnSqlite(['product', 'category', 'image', 'translation']);

            return;
        }

        $table = DB::getTablePrefix().'magic_ai_prompts';

        if ($this->isSqlServer()) {
            $this->changeTypeOnSqlServer($table, ['product', 'category', 'image', 'translation']);

            return;
        }

        if ($this->isPostgres()) {
            // PostgreSQL: change column type with CHECK constraint
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS magic_ai_prompts_type_check");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN type TYPE VARCHAR(20)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT magic_ai_prompts_type_check CHECK (type IN ('product', 'category', 'image', 'translation'))");
        } else {
            // MySQL: MODIFY with ENUM
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN type ENUM('product', 'category', 'image', 'translation') NOT NULL DEFAULT 'product'");
        }
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            $this->changeTypeOnSqlite(['product', 'category']);

            return;
        }

        $table = DB::getTablePrefix().'magic_ai_prompts';

        if ($this->isSqlServer()) {
            $this->changeTypeOnSqlServer($table, ['product', 'category']);

            return;
        }

        if ($this->isPostgres()) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS magic_ai_prompts_type_check");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN type TYPE VARCHAR(20)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT magic_ai_prompts_type_check CHECK (type IN ('product', 'category'))");
        } else {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN type ENUM('product', 'category') NOT NULL DEFAULT 'product'");
        }
    }

    protected function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    protected function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    protected function isSqlServer(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlsrv';
    }

    /**
     * SQL Server stores the enum as nvarchar(255) under a CHECK constraint it names itself
     * (CK__...), so the constraint is found by column and swapped; the column stays as it is.
     */
    protected function changeTypeOnSqlServer(string $table, array $types): void
    {
        $checks = DB::select(
            'SELECT cc.name FROM sys.check_constraints cc JOIN sys.columns c ON c.object_id = cc.parent_object_id AND c.column_id = cc.parent_column_id WHERE cc.parent_object_id = OBJECT_ID(?) AND c.name = ?',
            [$table, 'type'],
        );

        foreach ($checks as $check) {
            DB::statement("ALTER TABLE [{$table}] DROP CONSTRAINT [{$check->name}]");
        }

        $allowed = implode(', ', array_map(fn (string $type): string => "N'{$type}'", $types));

        DB::statement("ALTER TABLE [{$table}] ADD CONSTRAINT magic_ai_prompts_type_check CHECK ([type] IN ({$allowed}))");
    }

    /**
     * SQLite stores the enum as a CHECK constraint, which change() swaps by rebuilding the table.
     */
    protected function changeTypeOnSqlite(array $types): void
    {
        Schema::table('magic_ai_prompts', function (Blueprint $table) use ($types): void {
            $table->enum('type', $types)->default('product')->change();
        });
    }
};
