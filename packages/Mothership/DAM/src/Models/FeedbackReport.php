<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\User\Models\Admin;

/**
 * A bug, piece of feedback or idea a signed-in person sent from a page. Who may
 * read or change one is FeedbackAccess's decision, never this model's.
 */
class FeedbackReport extends Model
{
    public const TYPES = ['bug', 'feedback', 'idea'];

    public const SEVERITIES = ['minor', 'annoying', 'blocking'];

    public const STATUS_NEW = 'new';

    public const STATUSES = ['new', 'triaged', 'in_progress', 'fixed', 'wont_fix'];

    /** The statuses that close a report; reaching one stamps resolved_at. */
    public const RESOLVED_STATUSES = ['fixed', 'wont_fix'];

    protected $table = 'dam_feedback_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'admin_id'                  => 'integer',
        'screenshot_file_object_id' => 'integer',
        'context'                   => 'array',
        'resolved_at'               => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'screenshot_file_object_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FeedbackNote::class, 'report_id')->orderBy('id');
    }
}
