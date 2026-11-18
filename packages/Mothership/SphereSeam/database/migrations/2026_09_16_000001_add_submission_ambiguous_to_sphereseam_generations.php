<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sphereseam_generations', function (Blueprint $table): void {
            // Set once, permanently, when a `generate create` call (or a claim left by one that
            // never wrote a provider_request_id) could not be confirmed either way: the provider
            // may have charged it even though no id was ever recorded to poll. retry() refuses a
            // row with this set, forever, rather than risk a second real charge on an unresolved
            // first one. See GenerateSphereSeamBridge::fail().
            $table->boolean('submission_ambiguous')->default(false)->after('provider_request_id')->index();
        });
    }

    public function down(): void
    {
        // Forward-only (CLAUDE.md): dropping this column on rollback would erase the permanent
        // duplicate-charge guard for every row that already earned it, with no way to recover
        // which rows those were. A mistaken `up()` here is fixed by a new forward migration, never
        // by rolling this one back.
    }
};
