<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mothership\Installer\Database\Seeders\Demo\DemoProductSeeder;
use Mothership\Product\Models\Product;

/**
 * The publication tables were retired but stay in databases installed before that, and a publication
 * restricts deleting the product it points at, a version the publication. A forced demo re-seed has to
 * clear them first.
 *
 * Skipped where it cannot run cleanly: on a database that still has the retired tables, and on MySQL
 * and MariaDB, where creating a table commits the test's transaction.
 */
it('clears leftover publication rows before it deletes the products they point at', function () {
    Schema::create('publications', function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('product_id');
        $table->foreign('product_id')->references('id')->on('products');
    });

    Schema::create('publication_versions', function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('publication_id');
        $table->foreign('publication_id')->references('id')->on('publications');
    });

    try {
        $product = Product::factory()->create();

        $publicationId = DB::table('publications')->insertGetId(['product_id' => $product->id]);

        DB::table('publication_versions')->insert(['publication_id' => $publicationId]);

        (new ReflectionMethod(DemoProductSeeder::class, 'clearCatalog'))->invoke(resolve(DemoProductSeeder::class));

        expect(DB::table('publication_versions')->count())->toBe(0)
            ->and(DB::table('publications')->count())->toBe(0)
            ->and(DB::table('products')->count())->toBe(0);
    } finally {
        Schema::dropIfExists('publication_versions');
        Schema::dropIfExists('publications');
    }
})->skip(
    fn () => Schema::hasTable('publications')
        || Schema::hasTable('publication_versions')
        || in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
    'Needs a fresh schema and a database with transactional DDL (not MySQL or MariaDB).',
);
