<?php

namespace Mothership\User\Tests\Support;

use Mothership\User\Models\Admin;

class StatusCastAdmin extends Admin
{
    protected $table = 'admins';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'       => 'boolean',
            'use_gravatar' => 'boolean',
        ];
    }
}
