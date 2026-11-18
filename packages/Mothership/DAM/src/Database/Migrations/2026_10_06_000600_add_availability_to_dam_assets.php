<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 S3: whether an asset is out for use (available, draft, pending_review, archived) and the window it is out for.
 * Every existing row is available with no window, so nothing changes for anyone until an editor sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->string('availability', 16)->default('available')->index();
            $table->timestamp('available_from')->nullable()->index();
            $table->timestamp('available_until')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('dam_assets', function (Blueprint $table): void {
            $table->dropIndex(['availability']);
            $table->dropIndex(['available_from']);
            $table->dropIndex(['available_until']);
            $table->dropColumn(['availability', 'available_from', 'available_until']);
        });
    }
};
