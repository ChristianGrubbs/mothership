<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\DAM\Contracts\AssetRelationship as AssetRelationshipContract;
use Mothership\DAM\Database\Factories\AssetRelationshipFactory;

class AssetRelationship extends Model implements AssetRelationshipContract
{
    use HasFactory;

    const TYPE_CONTAINS = 'contains';

    const TYPE_USES_TEXTURE = 'uses-texture';

    const TYPE_SIDECAR_OF = 'sidecar-of';

    const TYPE_VERSION_OF = 'version-of';

    const TYPE_RENDITION_OF = 'rendition-of';

    const TYPE_SUBTITLE_FOR = 'subtitle-for';

    const TYPE_TRANSCRIPT_OF = 'transcript-of';

    const TYPE_DERIVED_FROM = 'derived-from';

    const TYPE_GENERATED_FROM = 'generated-from';

    const TYPE_ALTERNATE_OF = 'alternate-of';

    const TYPE_SOURCE_FOR = 'source-for';

    const TYPE_RELATED_TO = 'related-to';

    /**
     * Suggested vocabulary per ADR 0002. Not DB-enforced — `type` accepts any string
     * ("or a custom string" per the ADR), so this is a hint for UI/import code, not a CHECK constraint.
     */
    const TYPES = [
        self::TYPE_CONTAINS,
        self::TYPE_USES_TEXTURE,
        self::TYPE_SIDECAR_OF,
        self::TYPE_VERSION_OF,
        self::TYPE_RENDITION_OF,
        self::TYPE_SUBTITLE_FOR,
        self::TYPE_TRANSCRIPT_OF,
        self::TYPE_DERIVED_FROM,
        self::TYPE_GENERATED_FROM,
        self::TYPE_ALTERNATE_OF,
        self::TYPE_SOURCE_FOR,
        self::TYPE_RELATED_TO,
    ];

    protected $table = 'dam_asset_relationships';

    protected $fillable = [
        'from_asset_id',
        'to_asset_id',
        'type',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function fromAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'from_asset_id');
    }

    public function toAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'to_asset_id');
    }

    protected static function newFactory(): Factory
    {
        return AssetRelationshipFactory::new();
    }
}
