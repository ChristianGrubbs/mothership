<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\User\Models\Admin;

/**
 * A task on a calendar entry, or a sub-item of one (parent_id). The assignee is optional: a task can sit unassigned,
 * be given to someone or be claimed.
 */
class CalendarTask extends Model
{
    public const STATUSES = ['open', 'in_progress', 'done'];

    protected $table = 'dam_calendar_tasks';

    protected $guarded = ['id'];

    protected $casts = [
        'event_id'    => 'integer',
        'parent_id'   => 'integer',
        'assignee_id' => 'integer',
        'position'    => 'integer',
        'created_by'  => 'integer',
        'due_on'      => 'date:Y-m-d',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assignee_id');
    }
}
