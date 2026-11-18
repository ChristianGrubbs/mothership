<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4 (D2): a public collection's link is an ordinary share (`share_type = collection`, `target_id` =
 * dam_collections.id), so the S5 password, download-off, creator access, hashed token, Reissue and expiry apply to it
 * unchanged. The column was created as enum('asset', 'directory'): a CHECK constraint on PostgreSQL, SQLite and SQL
 * Server, a native ENUM on MySQL, widened here as 2026_09_22_000001 widened dam_assets.file_type.
 *
 * Down deletes the collection links first, so the narrower constraint holds.
 */
return new class extends Migration
{
    private const TYPES = ['asset', 'directory', 'collection'];

    public function up(): void
    {
        $this->apply(self::TYPES);
    }

    public function down(): void
    {
        DB::table('dam_shares')->where('share_type', 'collection')->delete();
        $this->apply(array_values(array_diff(self::TYPES, ['collection'])));
    }

    private function apply(array $types): void
    {
        $list = implode(', ', array_map(fn (string $t) => "'{$t}'", $types));
        $table = DB::getTablePrefix().'dam_shares';

        match (DB::getDriverName()) {
            'pgsql' => DB::transaction(function () use ($table, $list): void {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_share_type_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_share_type_check CHECK (share_type::text = ANY (ARRAY[{$list}]::text[]))");
            }),
            // Laravel rebuilds the SQLite table on change(), so the CHECK is re-emitted from the list supplied here.
            'sqlite' => Schema::table('dam_shares', function (Blueprint $blueprint) use ($types): void {
                $blueprint->enum('share_type', $types)->change();
            }),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$table} MODIFY share_type ENUM({$list}) NOT NULL"),
            'sqlsrv'           => $this->applySqlServer(table: $table, list: $list),
            default            => null,
        };
    }

    /** SQL Server names the enum's inline CHECK itself: find it by the column it guards, then replace it by name. */
    private function applySqlServer(string $table, string $list): void
    {
        $existing = DB::select(
            'SELECT cc.name FROM sys.check_constraints cc JOIN sys.columns c ON c.object_id = cc.parent_object_id AND c.column_id = cc.parent_column_id WHERE cc.parent_object_id = OBJECT_ID(?) AND c.name = ?',
            [$table, 'share_type'],
        );

        foreach ($existing as $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT [{$constraint->name}]");
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_share_type_check CHECK (share_type IN ({$list}))");
    }
};
