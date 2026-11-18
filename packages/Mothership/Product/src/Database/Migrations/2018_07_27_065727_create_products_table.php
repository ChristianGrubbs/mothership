<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQL Server has no ON DELETE RESTRICT; NO ACTION blocks the same deletes there.
        $restrict = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'restrict';

        // SQL Server rejects a self-referencing cascade and a second cascade path into products
        // (error 1785). There the sqlsrv-only products_delete_tree trigger deletes the variants
        // and the product_relations child rows instead (2026_09_23_000100).
        $cascade = DB::getDriverName() === 'sqlsrv' ? 'no action' : 'cascade';

        Schema::create('products', function (Blueprint $table) use ($restrict): void {
            $table->increments('id');
            $table->string('sku')->unique();
            $table->string('type');
            $table->integer('parent_id')->unsigned()->nullable();
            $table->integer('attribute_family_id')->unsigned()->nullable();

            $table->json('values')->nullable();
            $table->json('additional')->nullable();

            $table->timestamps();

            $table->foreign('attribute_family_id')->references('id')->on('attribute_families')->onDelete($restrict);
        });

        Schema::table('products', function (Blueprint $table) use ($cascade): void {
            $table->foreign('parent_id')->references('id')->on('products')->onDelete($cascade);
            /** Indexes */
            $table->index('sku');
            $table->index('type');
            $table->index('attribute_family_id');
            $table->index('parent_id');
            $table->index(['attribute_family_id', 'parent_id'], 'attribute_family_parent_idx');
        });

        Schema::create('product_relations', function (Blueprint $table) use ($cascade): void {
            $table->integer('parent_id')->unsigned();
            $table->integer('child_id')->unsigned();

            $table->foreign('parent_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('child_id')->references('id')->on('products')->onDelete($cascade);

            $table->unique(['parent_id', 'child_id']);
        });

        Schema::create('product_super_attributes', function (Blueprint $table) use ($restrict): void {
            $table->integer('product_id')->unsigned();
            $table->integer('attribute_id')->unsigned();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('attribute_id')->references('id')->on('attributes')->onDelete($restrict);

            $table->unique(['product_id', 'attribute_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_super_attributes');

        Schema::dropIfExists('product_relations');

        Schema::dropIfExists('products');
    }
};
