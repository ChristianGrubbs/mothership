<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creative Hub large saves: a create-only upload never replaces a same-name file. */
    public function up(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table) {
            $table->boolean('create_only')->default(false)->after('preserve_root');
        });
    }

    public function down(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table) {
            $table->dropColumn('create_only');
        });
    }
};
