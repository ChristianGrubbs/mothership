<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2024_07_10_175059_update_id_to_ui_id_oauth_clients converts oauth_clients.id to a UUID on
 * MySQL/MariaDB and PostgreSQL and does nothing on SQLite, where the column stays
 * `integer primary key autoincrement`. That is a rowid alias, the one SQLite column that
 * rejects non-integer values, so Passport 13's UUID client ids fail every insert with
 * "datatype mismatch" and no API client can be created on a SQLite schema.
 *
 * A new migration rather than a `sqlite` case in the 2024 one, so SQLite files that have
 * already run it are repaired too. The client_id columns on the other oauth tables need no
 * change here: SQLite's integer affinity stores a UUID in them as text.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || Schema::getColumnType('oauth_clients', 'id') !== 'integer') {
            return;
        }

        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible: UUID ids written after up() cannot be cast back to a rowid.
    }
};
