<?php

namespace Mothership\User\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\User\Contracts\Role;

class RoleRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Role::class;
    }
}
