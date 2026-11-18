<?php

namespace Mothership\Category\Repositories;

use Mothership\Category\Contracts\CategoryFieldOption;
use Mothership\Core\Eloquent\Repository;

class CategoryFieldOptionRepository extends Repository
{
    /**
     * This method returns the class name of the model associated with this repository.
     */
    public function model(): string
    {
        return CategoryFieldOption::class;
    }
}
