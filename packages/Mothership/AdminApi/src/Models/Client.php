<?php

namespace Mothership\AdminApi\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client as PassportClient;
use Mothership\User\Models\AdminProxy;

class Client extends PassportClient
{
    /**
     * Get the admins.
     *
     * @return BelongsTo
     */
    public function admins()
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'user_id');
    }
}
