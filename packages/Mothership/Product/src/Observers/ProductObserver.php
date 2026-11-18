<?php

namespace Mothership\Product\Observers;

use Illuminate\Support\Facades\Storage;
use Mothership\Product\Contracts\Product;

class ProductObserver
{
    /**
     * Handle the Product "deleted" event.
     *
     * @param  Product  $product
     */
    public function deleted($product): void
    {
        Storage::deleteDirectory('product/'.$product->id);
    }
}
