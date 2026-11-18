<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry on the Home calendar (docs/features/event-calendar.md): an event or campaign someone added. Times are
 * wall-clock time with no zone. `source`, `external_id` and `synced_at` are kept for an importer: nothing ships that
 * writes them, so every entry is `manual`.
 */
class CalendarEvent extends Model
{
    public const SOURCE_MANUAL = 'manual';

    /** The kinds a person may type in. */
    public const KINDS = ['event', 'campaign'];

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'dam_calendar_events';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'all_day'    => 'boolean',
        'created_by' => 'integer',
        'synced_at'  => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(CalendarTask::class, 'event_id');
    }

    public function assetLinks(): HasMany
    {
        return $this->hasMany(CalendarAsset::class, 'event_id');
    }
}
