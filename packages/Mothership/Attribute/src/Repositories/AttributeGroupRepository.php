<?php

namespace Mothership\Attribute\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Mothership\Attribute\Contracts\AttributeGroup;
use Mothership\Core\Eloquent\Repository;

class AttributeGroupRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model(): string
    {
        return AttributeGroup::class;
    }

    /**
     * This function returns a query builder instance for the AttributeGroup model.
     * It eager loads the 'translations' relationship for the attribute groups.
     *
     * @return Builder
     */
    public function queryBuilder()
    {
        return $this->with(['translations']);
    }
}
