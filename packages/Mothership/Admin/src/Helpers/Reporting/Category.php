<?php

namespace Mothership\Admin\Helpers\Reporting;

use Mothership\Category\Repositories\CategoryRepository;

class Category extends AbstractReporting
{
    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryRepository $categoryRepository,
    ) {}

    /**
     * This method calculates and returns the total number of categories in the system.
     *
     * @return int The total number of categories.
     */
    public function getTotalCategories(): int
    {
        return $this->categoryRepository
            ->resetModel()
            ->count();
    }
}
