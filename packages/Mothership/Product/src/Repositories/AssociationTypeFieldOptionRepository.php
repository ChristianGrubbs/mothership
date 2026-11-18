<?php

namespace Mothership\Product\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\Product\Contracts\AssociationTypeFieldOption;

class AssociationTypeFieldOptionRepository extends Repository
{
    /**
     * This method returns the class name of the model associated with this repository.
     */
    public function model(): string
    {
        return AssociationTypeFieldOption::class;
    }
}
