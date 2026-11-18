<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'web' to dam_assets.file_type (ADR 0007). The column was created as enum('image','video',
 * 'document','audio'): a CHECK constraint on PostgreSQL and SQLite, a native ENUM on MySQL.
 */
return new class extends Migration
{
    private const TYPES = ['image', 'video', 'document', 'audio', 'web'];

    public function up(): void
    {
        $this->apply(self::TYPES);
    }

    public function down(): void
    {
        DB::table('dam_assets')->where('file_type', 'web')->update(['file_type' => 'document']);
        $this->apply(array_values(array_diff(self::TYPES, ['web'])));
    }

    private function apply(array $types): void
    {
        $list = implode(', ', array_map(fn (string $t) => "'{$t}'", $types));
        $table = DB::getTablePrefix().'dam_assets';

        match (DB::getDriverName()) {
            'pgsql' => DB::transaction(function () use ($table, $list): void {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_file_type_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_file_type_check CHECK (file_type::text = ANY (ARRAY[{$list}]::text[]))");
            }),
            // Laravel rebuilds the SQLite table on change(), so the enum CHECK is re-emitted from
            // the list supplied here rather than the old four-value one.
            'sqlite' => Schema::table('dam_assets', function (Blueprint $blueprint) use ($types): void {
                $blueprint->enum('file_type', $types)->change();
            }),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$table} MODIFY file_type ENUM({$list}) NOT NULL"),
            default            => null,
        };
    }
};
