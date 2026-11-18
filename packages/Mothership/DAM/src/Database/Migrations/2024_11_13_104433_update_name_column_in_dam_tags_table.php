<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->resize(100);
    }

    public function down(): void
    {
        $this->resize(255);
    }

    /**
     * SQL Server refuses ALTER COLUMN while an index covers the column, so the unique index on
     * name is dropped around the change there. Every other driver alters it in place.
     */
    private function resize(int $length): void
    {
        $sqlsrv = DB::getDriverName() === 'sqlsrv';

        Schema::table('dam_tags', function (Blueprint $table) use ($length, $sqlsrv) {
            if ($sqlsrv) {
                $table->dropUnique('dam_tags_name_unique');
            }

            $table->string('name', $length)->change();

            if ($sqlsrv) {
                $table->unique('name');
            }
        });
    }
};
