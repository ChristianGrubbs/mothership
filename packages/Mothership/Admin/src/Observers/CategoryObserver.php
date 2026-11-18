<?php

namespace Mothership\Admin\Observers;

use Mothership\Admin\Helpers\Dashboard;
use Mothership\Category\Contracts\Category;

class CategoryObserver
{
    /**
     * Handle the Category "created" event.
     */
    public function created(Category $category): void
    {
        Dashboard::invalidateCatalogCache();
    }

    /**
     * Handle the Category "updated" event.
     */
    public function updated(Category $category): void
    {
        Dashboard::invalidateCatalogCache();
    }

    /**
     * Handle the Category "deleted" event.
     */
    public function deleted(Category $category): void
    {
        Dashboard::invalidateCatalogCache();
    }
}
