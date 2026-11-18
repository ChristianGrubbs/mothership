<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dam_capture_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('extensions');
            $t->json('priority');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        Schema::create('dam_capture_groups', function (Blueprint $t) use ($restrict) {
            $t->id();
            $t->foreignId('profile_id')->constrained('dam_capture_profiles')->onDelete($restrict);
            $t->unsignedBigInteger('primary_asset_id')->nullable();
            $t->foreign('primary_asset_id')->references('id')->on('dam_assets')->nullOnDelete();
            $t->boolean('manual_primary')->default(false);
            $t->timestamps();
        });
        Schema::table('dam_assets', function (Blueprint $t) {
            $t->foreignId('capture_group_id')->nullable()->constrained('dam_capture_groups')->nullOnDelete();
            $t->boolean('capture_grouping_disabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('capture_group_id');
            $t->dropColumn('capture_grouping_disabled');
        });
        Schema::dropIfExists('dam_capture_groups');
        Schema::dropIfExists('dam_capture_profiles');
    }
};
