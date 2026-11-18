<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5b (D19): the admin a webhook belongs to. DAM asset events are filtered through the asset access seam for this
 * admin, so a payload never names an asset its owner cannot see; a webhook with no owner (every one created before
 * this) receives no DAM asset event until an admin saves it and becomes its owner. No foreign key: a deleted owner
 * leaves a webhook that receives no DAM asset event, the same fail-closed answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table): void {
            $table->unsignedInteger('owner_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table): void {
            $table->dropColumn('owner_id');
        });
    }
};
