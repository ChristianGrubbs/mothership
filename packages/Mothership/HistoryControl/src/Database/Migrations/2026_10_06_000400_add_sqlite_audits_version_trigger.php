<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite twin of the audit_before_insert trigger (2024_08_16_173100), which numbers audit versions on
 * mysql, mariadb and pgsql only; without it every History row on SQLite showed a blank Version and no
 * View action (visual-review finding F4). SQLite cannot assign NEW in a BEFORE trigger, so this numbers
 * the row just after insert with the same rule: reuse the version of a row with the same tags, history
 * id and created_at, otherwise one more than the highest so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $audits = DB::getTablePrefix().'audits';

        DB::unprepared('DROP TRIGGER IF EXISTS audit_after_insert_version');
        DB::unprepared("
            CREATE TRIGGER audit_after_insert_version
            AFTER INSERT ON {$audits}
            FOR EACH ROW WHEN NEW.version_id IS NULL
            BEGIN
                UPDATE {$audits} SET version_id = COALESCE(
                    (SELECT version_id FROM {$audits}
                        WHERE tags = NEW.tags AND history_id = NEW.history_id AND created_at = NEW.created_at
                        AND id <> NEW.id AND version_id IS NOT NULL LIMIT 1),
                    (SELECT MAX(version_id) FROM {$audits}
                        WHERE tags = NEW.tags AND history_id = NEW.history_id AND id <> NEW.id) + 1,
                    1
                ) WHERE id = NEW.id;
            END
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_after_insert_version');
        }
    }
};
