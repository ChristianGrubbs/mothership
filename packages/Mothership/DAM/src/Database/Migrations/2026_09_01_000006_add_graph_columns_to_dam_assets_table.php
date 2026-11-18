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
            $table->string('legacy_ref')->nullable()->unique()->after('meta_data');
            $table->string('title')->nullable()->after('legacy_ref');
            $table->string('asset_kind')->nullable()->index()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table) {
            $table->dropColumn(['legacy_ref', 'title', 'asset_kind']);
        });
    }
};
