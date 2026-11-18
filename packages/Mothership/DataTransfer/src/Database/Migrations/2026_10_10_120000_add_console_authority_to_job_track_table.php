<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a job was started from the console with no admin attributed (`data-transfer` without a user
 * email), separately from `user_id`. `user_id` is ON DELETE SET NULL, so a null there cannot tell a shell
 * operator's job from one whose admin has since been deleted; the user and role importers rank-check every
 * job that is not explicitly a console job (UX eval 2026-10-10, PERM-08 review). Existing rows stay null and
 * read as not console: fail closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_track', function (Blueprint $table): void {
            $table->boolean('console_authority')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_track', function (Blueprint $table): void {
            $table->dropColumn('console_authority');
        });
    }
};
