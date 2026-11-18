<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2 S5a: share tokens at rest. A new link stores `token_hash` (SHA-256 hex of the URL token, the lookup key) and
 * `token_encrypted` (the token under APP_KEY, so its creator can still copy the URL), never the plaintext `token`.
 * Legacy rows keep their plaintext until first use or `dam:shares:hash-tokens` re-stores them, so `token` becomes
 * nullable.
 *
 * Both unique keys sit on nullable columns. A plain UNIQUE index on SQL Server admits one NULL only, so:
 * - `token` keeps a plain index (for the legacy lookup) instead of its unique one. New rows never write it, and the
 *   legacy values were already unique;
 * - `token_hash` is unique everywhere, through a filtered index (WHERE token_hash IS NOT NULL) on SQL Server, as
 *   dam_groups.system_key does. The other drivers already leave NULLs out of a unique index.
 *
 * Down restores the plaintext from `token_encrypted` first, and refuses before any schema change when a row cannot
 * be decrypted (APP_KEY changed), since `token` goes back to NOT NULL UNIQUE.
 */
return new class extends Migration
{
    private const HASH_INDEX = 'dam_shares_token_hash_unique';

    public function up(): void
    {
        Schema::table('dam_shares', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->after('token');
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::HASH_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_shares').' (token_hash) WHERE token_hash IS NOT NULL');
        } else {
            Schema::table('dam_shares', function (Blueprint $table) {
                $table->unique('token_hash', self::HASH_INDEX);
            });
        }

        // The unique index goes before the column changes: SQL Server refuses to alter a column an index depends on.
        Schema::table('dam_shares', function (Blueprint $table) {
            $table->dropUnique(['token']);
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->change();
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->index('token');
        });
    }

    public function down(): void
    {
        $this->restorePlaintext();

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->dropIndex(['token']);
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->dropUnique(self::HASH_INDEX);
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->dropColumn(['token_hash', 'token_encrypted']);
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->string('token', 64)->nullable(false)->change();
        });

        Schema::table('dam_shares', function (Blueprint $table) {
            $table->unique('token');
        });
    }

    /**
     * Every hashed row gets its plaintext back from the encrypted copy. All rows are decrypted before any is written,
     * so a row this APP_KEY cannot read leaves the table exactly as it was.
     */
    private function restorePlaintext(): void
    {
        $restore = [];

        DB::table('dam_shares')->whereNull('token')->orderBy('id')->select(['id', 'token_encrypted'])
            ->chunk(500, function ($rows) use (&$restore) {
                foreach ($rows as $row) {
                    try {
                        $restore[(int) $row->id] = Crypt::decryptString((string) $row->token_encrypted);
                    } catch (DecryptException) {
                        throw new RuntimeException("dam_shares row {$row->id}: its token cannot be decrypted with this APP_KEY; nothing was rolled back.");
                    }
                }
            });

        DB::transaction(function () use ($restore) {
            foreach ($restore as $id => $token) {
                DB::table('dam_shares')->where('id', $id)->update(['token' => $token]);
            }
        });
    }
};
