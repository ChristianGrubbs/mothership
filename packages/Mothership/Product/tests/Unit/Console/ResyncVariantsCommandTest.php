<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mothership\Completeness\Jobs\ProductCompletenessJob;
use Mothership\Product\Models\Product;

uses(DatabaseTransactions::class);

it('queues a completeness resync for the whole variant subtree', function () {
    Queue::fake();

    $parent = Product::factory()->configurable()->create([
        'values' => ['common' => ['brand' => 'Nike']],
    ]);

    Product::factory()->count(2)->create(['parent_id' => $parent->id]);

    $this->artisan('mothership:variants:resync', ['--all' => true])->assertSuccessful();

    Queue::assertPushed(ProductCompletenessJob::class);
});

it('fails when neither --product nor --all is given', function () {
    $this->artisan('mothership:variants:resync')->assertFailed();
});
