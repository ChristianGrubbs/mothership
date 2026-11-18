<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Mothership\DAM\Contracts\Rendition as RenditionContract;
use Mothership\DAM\Database\Factories\RenditionFactory;
use Mothership\DAM\Traits\StampsHttpRequestId;

class Rendition extends Model implements RenditionContract
{
    use HasFactory;
    use StampsHttpRequestId;

    const STATUS_QUEUED = 'queued';

    const STATUS_RUNNING = 'running';

    const STATUS_DONE = 'done';

    const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RECIPE_PANORAMA_4K = 'pano360-h264-4k';

    public const RECIPE_PREVIEW_1080P = 'preview-h264-1080p';

    /** Derived outputs recorded for lineage (P3a S6); their bytes live in the output file or asset, not here. */
    public const RECIPE_OPTIMIZE = 'optimize';

    public const RECIPE_VECTORIZE = 'vectorize';

    public const RECIPE_EDITOR_COPY = 'editor-save-as-new';

    /** A file a Creative Hub app saved back (ADR 0012): a new asset from a source, or a new version of it. */
    public const RECIPE_CREATIVE_HUB = 'creative-hub-save';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'dam_renditions';

    protected $fillable = [
        'dam_asset_id',
        'source_asset_file_id',
        'recipe',
        'fingerprint',
        'parameters',
        'worker',
        'worker_version',
        'status',
        'progress',
        'cancel_requested_at',
        'started_at',
        'finished_at',
        'error',
        'output_file_object_id',
        'output_asset_file_id',
        'output_asset_id',
    ];

    protected $casts = [
        'parameters'          => 'array',
        'progress'            => 'integer',
        'cancel_requested_at' => 'datetime',
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rendition): void {
            if ($rendition->status !== null && ! in_array($rendition->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid rendition status.');
            }

            if ($rendition->source_asset_file_id === null) {
                return;
            }

            $ownsFile = AssetFile::query()
                ->whereKey($rendition->source_asset_file_id)
                ->where('dam_asset_id', $rendition->dam_asset_id)
                ->exists();

            if (! $ownsFile) {
                throw new InvalidArgumentException('A rendition source file must belong to the same asset.');
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function sourceAssetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'source_asset_file_id');
    }

    public function outputFileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'output_file_object_id');
    }

    /** The file of this asset the rendition produced (a proxy, an optimized or vectorized copy). */
    public function outputAssetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'output_asset_file_id');
    }

    /** The separate asset the rendition produced (an image editor save-as-new). */
    public function outputAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'output_asset_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(LineageInput::class, 'rendition_id')->orderBy('position');
    }

    /**
     * Renditions made from the asset's current content (P3a S6): their source revision holds the bytes the
     * representative's current revision holds, so a promote or restore leaves an older proxy behind while a
     * restore of the same bytes keeps it. Proxies made before sources were recorded were credited to their
     * entry's then-current original by the lineage migration; a row still without one (no original to credit)
     * stays eligible, since what it was made from is unknown.
     */
    public function scopeFromCurrentContent(Builder $query, int $assetId): Builder
    {
        $current = AssetFile::query()->where('dam_asset_id', $assetId)->where('role', AssetFile::ROLE_ORIGINAL)->value('file_object_id');

        return $query->where(function (Builder $query) use ($assetId, $current): void {
            $query->whereNull('source_asset_file_id');

            if ($current !== null) {
                $query->orWhereIn('source_asset_file_id', AssetFile::query()->select('id')->where('dam_asset_id', $assetId)->where('file_object_id', $current));
            }
        });
    }

    protected static function newFactory(): Factory
    {
        return RenditionFactory::new();
    }
}
