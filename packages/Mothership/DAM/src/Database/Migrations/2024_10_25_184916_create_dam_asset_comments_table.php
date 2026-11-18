<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQL Server rejects a self-referencing cascade (error 1785); there AssetComments deletes
        // the replies itself before the parent row goes.
        $cascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('dam_asset_comments', function (Blueprint $table) use ($cascade) {
            $table->id();
            $table->integer('admin_id')->unsigned()->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('SET NULL');
            $table->foreignId('parent_id')->nullable()->constrained('dam_asset_comments')->onDelete($cascade);
            $table->longText('comments');
            $table->unsignedBigInteger('dam_asset_id');
            $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dam_asset_comments');
    }
};
