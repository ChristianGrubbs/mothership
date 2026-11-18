<?php

namespace Mothership\Attribute\Repositories;

use Mothership\Attribute\Contracts\AttributeFamilyGroupMapping;
use Mothership\Core\Eloquent\Repository;

class AttributeFamilyGroupMappingRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model(): string
    {
        return AttributeFamilyGroupMapping::class;
    }
}
