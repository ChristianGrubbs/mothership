<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_role_settings', function (Blueprint $table) {
            $table->json('home_widget_ids')->nullable();
            $table->boolean('delete_own_only')->default(false);
        });

        foreach (['dam_assets', 'dam_directories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['dam_assets', 'dam_directories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            });
        }

        Schema::table('dam_role_settings', function (Blueprint $table) {
            $table->dropColumn(['home_widget_ids', 'delete_own_only']);
        });
    }
};
