<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

/** An asset linked to a calendar entry, or to one task or sub-item of it (task_id). */
class CalendarAsset extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dam_calendar_assets';

    protected $guarded = ['id'];

    protected $casts = [
        'event_id' => 'integer',
        'task_id'  => 'integer',
        'asset_id' => 'integer',
        'added_by' => 'integer',
    ];
}
