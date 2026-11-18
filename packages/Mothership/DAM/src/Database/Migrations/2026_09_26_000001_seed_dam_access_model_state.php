<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;

/**
 * Gives the `DAM_ACCESS_MODEL` row a durable existence, as `grants` (ADR 0010, sharing slice 2a).
 *
 * Reads are unchanged: an absent row and `grants` are the same answer to AccessModel::modelFor(). What
 * changes is the fence. Every writer that maintains the access lists holds this row FOR SHARE, and there is
 * nothing to hold FOR SHARE until the row exists — PostgreSQL locks no row that is not there. Before this
 * migration the row appeared only when a rebuild or a switch first created it, so on a database that had
 * never run one, every list writer was unfenced while the first switch rebuilt and activated concurrently
 * (Codex review of PR #74, finding 1).
 *
 * Idempotent and driver-portable: one existence check, one insert, no upsert grammar and no value
 * overwritten. Migrations run one at a time, so the check cannot race another migration; a writer that
 * creates the row first through AccessModel::transactionally() simply leaves nothing to do here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('dam_configuration')->where('key', AccessModel::KEY)->exists()) {
            return;
        }

        DB::table('dam_configuration')->insert(['key' => AccessModel::KEY, 'value' => AccessModel::GRANTS]);
    }

    /**
     * Only the row this migration would have written. A rollback must never take away a `lists` or
     * `pending:<run>` state, because deleting it silently moves every read back to the grants.
     */
    public function down(): void
    {
        DB::table('dam_configuration')->where('key', AccessModel::KEY)->where('value', AccessModel::GRANTS)->delete();
    }
};
