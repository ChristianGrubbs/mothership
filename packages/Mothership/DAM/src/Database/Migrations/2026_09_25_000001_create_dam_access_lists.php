<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Support\FolderAccessLog;

/*
 * Owner-controlled access lists (ADR 0010, sharing slice 1): one list table per item kind and
 * principal kind, dam_groups.system_key for built-in groups, and the Everyone group. The lists start
 * empty. Reads stay on today's grants until `dam:access:switch lists` rebuilds them from the grants.
 */
return new class extends Migration
{
    private const SYSTEM_KEY_INDEX = 'dam_groups_system_key_unique';

    public function up(): void
    {
        // SQL Server rejects a second cascade path into one table (error 1785). Deleting an admin reaches a
        // user list directly, and through created_by (SET NULL) and then the item key. There admin_id is
        // NO ACTION, and DeleteAdminAccessRows removes the admin's rows before the admin row goes.
        $adminCascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_directory_user_access', function (Blueprint $table) use ($adminCascade) {
            $table->unsignedBigInteger('directory_id');
            $table->unsignedInteger('admin_id');
            $table->primary(['directory_id', 'admin_id']);
            $table->index(['admin_id', 'directory_id']);
            $table->foreign('directory_id')->references('id')->on('dam_directories')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete($adminCascade);
        });
        Schema::create('dam_directory_group_access', function (Blueprint $table) {
            $table->unsignedBigInteger('directory_id');
            $table->unsignedBigInteger('group_id');
            $table->primary(['directory_id', 'group_id']);
            $table->index(['group_id', 'directory_id']);
            $table->foreign('directory_id')->references('id')->on('dam_directories')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('dam_groups')->cascadeOnDelete();
        });
        Schema::create('dam_asset_user_access', function (Blueprint $table) use ($adminCascade) {
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('admin_id');
            $table->primary(['asset_id', 'admin_id']);
            $table->index(['admin_id', 'asset_id']);
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete($adminCascade);
        });
        Schema::create('dam_asset_group_access', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('group_id');
            $table->primary(['asset_id', 'group_id']);
            $table->index(['group_id', 'asset_id']);
            $table->foreign('asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('dam_groups')->cascadeOnDelete();
        });

        // `everyone` for the built-in group, `role:<role_id>` on a group the rebuild made from a role.
        Schema::table('dam_groups', function (Blueprint $table) {
            $table->string('system_key', 64)->nullable();
        });

        // A plain UNIQUE index on SQL Server admits one NULL only, and every operator group has none,
        // so there the index covers the keyed rows. The other drivers already leave NULLs out.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::SYSTEM_KEY_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_groups').' (system_key) WHERE system_key IS NOT NULL');
        } else {
            Schema::table('dam_groups', function (Blueprint $table) {
                $table->unique('system_key', self::SYSTEM_KEY_INDEX);
            });
        }

        // An operator's group is never renamed: when one is already called Everyone, the built-in group
        // takes the next free name.
        $name = 'Everyone';
        for ($attempt = 1; DB::table('dam_groups')->where('name', $name)->exists(); $attempt++) {
            $name = $attempt === 1 ? 'Everyone (all users)' : 'Everyone (all users) '.$attempt;
        }

        $everyoneId = DB::table('dam_groups')->insertGetId([
            'name'       => $name,
            'system_key' => 'everyone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FolderAccessLog::write(event: 'everyone_group_created', ctx: ['group_id' => (int) $everyoneId, 'name_taken' => $name !== 'Everyone']);

        // The owner rule filters on created_by. 2026_09_07_180000 indexed both columns; this covers only a
        // database where that index has gone missing.
        foreach (['dam_assets', 'dam_directories'] as $tableName) {
            if (! Schema::hasIndex($tableName, ['created_by'])) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index('created_by');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_group_access');
        Schema::dropIfExists('dam_asset_user_access');
        Schema::dropIfExists('dam_directory_group_access');
        Schema::dropIfExists('dam_directory_user_access');

        // Groups a rebuild made from roles stay behind as ordinary groups; their members are real.
        DB::table('dam_groups')->where('system_key', 'everyone')->delete();

        Schema::table('dam_groups', function (Blueprint $table) {
            $table->dropUnique(self::SYSTEM_KEY_INDEX);
        });
        Schema::table('dam_groups', function (Blueprint $table) {
            $table->dropColumn('system_key');
        });
    }
};
