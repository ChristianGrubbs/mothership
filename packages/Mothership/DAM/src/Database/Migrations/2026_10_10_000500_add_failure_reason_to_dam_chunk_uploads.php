<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Why finalization refused a chunked upload, as a structured reason ({code, key, params}) rather than text: the
 * status endpoint renders it in the requester's language, and no exception text ever reaches a browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table) {
            $table->json('failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dam_chunk_uploads', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });
    }
};
