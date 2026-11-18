<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * P5b (D19): an if/then automation rule. `version` goes up on every edit and is part of each run's key and of the
 * source its writes carry (`rule:<id>@v<n>`). Actions run with `owner_id`'s rights.
 *
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property int $version
 * @property string $trigger_type
 * @property int|null $trigger_field_id
 * @property array{match?: string, conditions?: list<array<string, mixed>>}|null $conditions
 * @property list<array<string, mixed>> $actions
 * @property int|null $owner_id
 * @property int|null $updated_by
 * @property Carbon|null $last_run_at
 */
class AutomationRule extends Model
{
    public const TRIGGER_INGEST = 'ingest';

    public const TRIGGER_AI_INDEXED = 'ai_indexed';

    public const TRIGGER_FIELD_CHANGED = 'field_changed';

    public const TRIGGER_TAG_CHANGED = 'tag_changed';

    public const TRIGGER_LABEL_CHANGED = 'label_changed';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGERS = [self::TRIGGER_INGEST, self::TRIGGER_AI_INDEXED, self::TRIGGER_FIELD_CHANGED,
        self::TRIGGER_TAG_CHANGED, self::TRIGGER_LABEL_CHANGED, self::TRIGGER_MANUAL];

    protected $table = 'dam_automation_rules';

    protected $fillable = ['name', 'enabled', 'version', 'trigger_type', 'trigger_field_id', 'conditions', 'actions',
        'owner_id', 'updated_by', 'last_run_at'];

    protected $casts = [
        'enabled'          => 'boolean',
        'version'          => 'integer',
        'trigger_field_id' => 'integer',
        'conditions'       => 'array',
        'actions'          => 'array',
        'owner_id'         => 'integer',
        'updated_by'       => 'integer',
        'last_run_at'      => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
    }

    public function source(): string
    {
        return 'rule:'.$this->id.'@v'.$this->version;
    }
}
