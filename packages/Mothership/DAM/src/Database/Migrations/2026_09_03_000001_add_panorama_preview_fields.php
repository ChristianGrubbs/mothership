<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table) {
            $table->string('panorama_mode')->default('auto')->index()->after('asset_kind');
        });

        Schema::table('dam_renditions', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->index()->after('recipe');
            $table->unsignedSmallInteger('progress')->default(0)->after('status');
            $table->timestamp('cancel_requested_at')->nullable()->after('progress');
            $table->timestamp('started_at')->nullable()->after('cancel_requested_at');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('dam_renditions', function (Blueprint $table) {
            $table->dropColumn(['fingerprint', 'progress', 'cancel_requested_at', 'started_at', 'finished_at']);
        });

        Schema::table('dam_assets', function (Blueprint $table) {
            $table->dropColumn('panorama_mode');
        });
    }
};
