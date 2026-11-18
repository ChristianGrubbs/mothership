<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * P5b (D19): what one action of one run did to one asset: before, after, and written, skipped or refused with a
 * reason. Unique per (run, asset, action index), so a redelivered chunk logs nothing twice.
 *
 * @property int $id
 * @property int $run_id
 * @property int $asset_id
 * @property int $action_index
 * @property string $action_type
 * @property string|null $target
 * @property string|null $value_before
 * @property string|null $value_after
 * @property string $outcome
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class AutomationRunAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dam_automation_run_actions';

    protected $fillable = ['run_id', 'asset_id', 'action_index', 'action_type', 'target', 'value_before', 'value_after', 'outcome', 'reason'];

    protected $casts = [
        'run_id'       => 'integer',
        'asset_id'     => 'integer',
        'action_index' => 'integer',
    ];
}
