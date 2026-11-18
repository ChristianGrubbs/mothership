<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admins')->whereNotNull('api_token')->update(['api_token' => null]);
    }

    public function down(): void {}
};
