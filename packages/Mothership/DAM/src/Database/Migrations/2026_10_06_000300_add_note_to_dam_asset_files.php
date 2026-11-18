<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A revision's note (P3a S5): what made it, such as "Restored revision 2". Shown in the file's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_asset_files', function (Blueprint $table): void {
            $table->string('note', 191)->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('dam_asset_files', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
