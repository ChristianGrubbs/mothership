<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A StylePress reference taken from the library remembers which asset it came from, so a generated
 * image can be linked back to its sources when it is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_stylepress_references', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable()->index()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('dam_stylepress_references', function (Blueprint $table) {
            $table->dropColumn('asset_id');
        });
    }
};
