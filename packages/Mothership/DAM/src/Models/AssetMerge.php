<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\User\Models\Admin;

/**
 * One source merged into a destination (P3b-1, D7). Active until it is split (the source comes back) or finalized
 * (irreversible: split ends, and the normal trash and purge rules apply again). The snapshot records what the merge
 * linked and added, for display and for split; the source's own rows are what make it whole again.
 *
 * @property array<string, mixed>|null $snapshot
 */
class AssetMerge extends Model
{
    protected $table = 'dam_asset_merges';

    protected $fillable = [
        'dest_id',
        'source_id',
        'snapshot',
        'merged_by',
        'merged_at',
        'split_at',
        'split_by',
        'finalized_at',
        'finalized_by',
    ];

    protected $casts = [
        'dest_id'      => 'integer',
        'source_id'    => 'integer',
        'snapshot'     => 'array',
        'merged_at'    => 'datetime',
        'split_at'     => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->split_at === null && $this->finalized_at === null;
    }

    /** Neither split nor finalized. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dam_asset_merges.split_at')->whereNull('dam_asset_merges.finalized_at');
    }

    /** The source, trashed or not: a finalized source goes to the trash (AssetMergeService::finalize()). */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_id')->withTrashed();
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dest_id')->withTrashed();
    }

    public function merger(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'merged_by');
    }
}
