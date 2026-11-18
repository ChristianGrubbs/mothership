<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade path for the Mothership namespace rename.
 *
 * Polymorphic and agent columns store fully qualified class names. Rows written
 * before the rename still hold the old vendor prefix, which no longer autoloads.
 * This rewrites that prefix to "Mothership\" in every column known to hold a class
 * name. Fresh installs have nothing to rewrite, so the migration is a no-op there.
 */
return new class extends Migration
{
    private const OLD_PREFIX = 'Webkul\\';

    private const NEW_PREFIX = 'Mothership\\';

    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'personal_access_tokens'      => ['tokenable_type'],
        'audits'                      => ['auditable_type', 'user_type'],
        'oauth_clients'               => ['owner_type'],
        'agent_conversation_messages' => ['agent'],
    ];

    public function up(): void
    {
        $this->rewrite(from: self::OLD_PREFIX, to: self::NEW_PREFIX);
    }

    public function down(): void
    {
        $this->rewrite(from: self::NEW_PREFIX, to: self::OLD_PREFIX);
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $values = DB::table($table)->whereNotNull($column)->distinct()->pluck($column);

                foreach ($values as $value) {
                    if (! str_starts_with((string) $value, $from)) {
                        continue;
                    }

                    DB::table($table)
                        ->where($column, $value)
                        ->update([$column => $to.substr((string) $value, strlen($from))]);
                }
            }
        }
    }
};
