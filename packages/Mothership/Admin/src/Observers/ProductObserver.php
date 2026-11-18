<?php

namespace Mothership\Admin\Observers;

use Mothership\Admin\Helpers\Dashboard;
use Mothership\Product\Contracts\Product;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        Dashboard::invalidateProductCache();
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        Dashboard::invalidateProductCache();
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        Dashboard::invalidateProductCache();
    }
}
