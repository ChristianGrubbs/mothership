<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY_INDEX = 'dam_upload_batches_finalization_key_unique';

    public function up(): void
    {
        $this->trackerColumn(nullable: true);

        Schema::table('dam_upload_batches', function (Blueprint $table): void {
            $table->string('finalization_key', 64)->nullable();
            $table->unsignedInteger('claim_attempts')->default(0);
            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->timestamp('last_recovered_at')->nullable();
            $table->index(['state', 'updated_at'], 'dam_upload_batches_recovery_scan');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX '.self::KEY_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_upload_batches').' (finalization_key) WHERE finalization_key IS NOT NULL');
        } else {
            Schema::table('dam_upload_batches', function (Blueprint $table): void {
                $table->unique('finalization_key', self::KEY_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (DB::table('dam_upload_batches')->whereNull('upload_tracker_id')->exists()) {
            throw new RuntimeException('Cannot remove finalization keys while trackerless batches exist.');
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::KEY_INDEX.' ON '.DB::getQueryGrammar()->wrapTable('dam_upload_batches'));
        } else {
            Schema::table('dam_upload_batches', function (Blueprint $table): void {
                $table->dropUnique(self::KEY_INDEX);
            });
        }

        Schema::table('dam_upload_batches', function (Blueprint $table): void {
            $table->dropIndex('dam_upload_batches_recovery_scan');
            $table->dropColumn(['finalization_key', 'claim_attempts', 'recovery_attempts', 'last_recovered_at']);
        });

        $this->trackerColumn(nullable: false);
    }

    private function trackerColumn(bool $nullable): void
    {
        Schema::table('dam_upload_batches', function (Blueprint $table): void {
            $table->dropForeign(['upload_tracker_id']);
        });

        Schema::table('dam_upload_batches', function (Blueprint $table) use ($nullable): void {
            $table->unsignedBigInteger('upload_tracker_id')->nullable($nullable)->change();
        });

        Schema::table('dam_upload_batches', function (Blueprint $table): void {
            $table->foreign('upload_tracker_id')->references('id')->on('dam_upload_trackers')->cascadeOnDelete();
        });
    }
};
