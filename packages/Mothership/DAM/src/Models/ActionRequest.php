<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Mothership\DAM\Contracts\ActionRequest as ActionRequestContract;

class ActionRequest extends Model implements ActionRequestContract
{
    protected $table = 'dam_action_request';

    protected $fillable = [
        'event_type',
        'status',
        'error_message',
        'admin_id',
    ];

    public static function findOneWhere($column)
    {
        return static::where($column)->first();
    }
}
