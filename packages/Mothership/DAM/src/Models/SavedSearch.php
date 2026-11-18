<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P4 (D12, D3): a saved search (its owner's, private unless shared) or a section (an admin-defined shared view with
 * an asset-type rule, manual pins and a default sort). Both store a query definition, never result ids, so running
 * one always applies the viewer's current access (SearchDefinition).
 *
 * @property int $id
 * @property string $kind
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property string $name
 * @property array $definition
 * @property bool $pinned
 * @property string $visibility
 * @property string|null $default_sort
 * @property int $position
 */
class SavedSearch extends Model
{
    public const KIND_SEARCH = 'search';

    public const KIND_SECTION = 'section';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_SHARED];

    protected $table = 'dam_saved_searches';

    protected $fillable = ['kind', 'owner_id', 'created_by', 'name', 'definition', 'pinned', 'visibility', 'default_sort', 'position'];

    protected $casts = [
        'owner_id'   => 'integer',
        'created_by' => 'integer',
        'definition' => 'array',
        'pinned'     => 'boolean',
        'position'   => 'integer',
    ];

    protected $attributes = [
        'kind'       => self::KIND_SEARCH,
        'visibility' => self::VISIBILITY_PRIVATE,
        'pinned'     => false,
    ];

    public function isSection(): bool
    {
        return $this->kind === self::KIND_SECTION;
    }
}
