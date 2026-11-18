<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * P5b (D19): one evaluation of one rule version for one triggering event; unique on
 * (rule_id, rule_version, trigger_event_id), the loop guard's run key (roadmap §3.4).
 *
 * @property int $id
 * @property int $rule_id
 * @property int $rule_version
 * @property string $trigger_event_id
 * @property string $trigger_type
 * @property int|null $asset_id
 * @property int|null $directory_id
 * @property string $status
 * @property int $assets_total
 * @property int $matched
 * @property int $written
 * @property int $skipped
 * @property int $refused
 * @property int|null $requested_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class AutomationRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'dam_automation_runs';

    protected $fillable = ['rule_id', 'rule_version', 'trigger_event_id', 'trigger_type', 'asset_id', 'directory_id', 'status',
        'assets_total', 'matched', 'written', 'skipped', 'refused', 'requested_by', 'started_at', 'finished_at'];

    protected $casts = [
        'rule_id'      => 'integer',
        'rule_version' => 'integer',
        'asset_id'     => 'integer',
        'directory_id' => 'integer',
        'assets_total' => 'integer',
        'matched'      => 'integer',
        'written'      => 'integer',
        'skipped'      => 'integer',
        'refused'      => 'integer',
        'requested_by' => 'integer',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationRunAction::class, 'run_id');
    }
}
