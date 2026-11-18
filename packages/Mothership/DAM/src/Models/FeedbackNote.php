<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\User\Models\Admin;

/** A triager's note on a report, or the record of a status change. The reporter sees only the visible ones. */
class FeedbackNote extends Model
{
    public const KIND_NOTE = 'note';

    public const KIND_STATUS = 'status_change';

    protected $table = 'dam_feedback_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'report_id'           => 'integer',
        'admin_id'            => 'integer',
        'visible_to_reporter' => 'boolean',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
