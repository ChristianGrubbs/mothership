<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P4 (D4, D13): a shared label (team-curated, nestable, no owner) or a board (a personal label, private to its
 * owner). Members live in dam_label_assets with a manual position; neither kind grants access (roadmap §3.2).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $kind
 * @property int|null $owner_id
 * @property int $position
 */
class Label extends Model
{
    public const KIND_SHARED = 'shared';

    public const KIND_BOARD = 'board';

    public const KINDS = [self::KIND_SHARED, self::KIND_BOARD];

    /** Deepest nesting the label manager offers (a root label is level 1). */
    public const MAX_DEPTH = 5;

    protected $table = 'dam_labels';

    protected $fillable = ['parent_id', 'name', 'kind', 'owner_id', 'position'];

    protected $casts = [
        'parent_id' => 'integer',
        'owner_id'  => 'integer',
        'position'  => 'integer',
    ];

    public function isBoard(): bool
    {
        return $this->kind === self::KIND_BOARD;
    }
}
