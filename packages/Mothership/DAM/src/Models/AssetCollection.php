<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\User\Models\Admin;

/**
 * A collection (P4 D2, ADR 0011): a curated, ordered delivery set for an audience, with an optional expiry and slug.
 * Not the Web Pages organizer (`WebCollection`, "Page groups" in the UI, ADR 0008).
 *
 * Its grant is bound to the container: a member is served to the audience only through the collection's own routes
 * (`AccessContext::collection()`), never through the Library, search, REST or any other route (roadmap §3.3).
 */
class AssetCollection extends Model
{
    public const AUDIENCE_INTERNAL = 'internal';

    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCES = [self::AUDIENCE_INTERNAL, self::AUDIENCE_PUBLIC];

    public const STATUS_ACTIVE = 'active';

    /** Kept, but served to nobody until it is made active again: the owner's off switch short of deleting it. */
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public const PRINCIPAL_USER = 'user';

    public const PRINCIPAL_GROUP = 'group';

    protected $table = 'dam_collections';

    protected $fillable = ['name', 'description', 'owner_id', 'audience', 'expires_at', 'slug', 'status'];

    protected $casts = [
        'owner_id'   => 'integer',
        'expires_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_id');
    }

    /** The member rows, in position order (ties by when they were added). */
    public function memberRows(): HasMany
    {
        return $this->hasMany(CollectionMember::class, 'collection_id')->orderBy('position')->orderBy('id');
    }

    public function isPublic(): bool
    {
        return $this->audience === self::AUDIENCE_PUBLIC;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Whether the collection serves anything at all: active and not expired. Otherwise every member read is 410. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }

    /** `active`, `archived` or `expired`, for badges and the API. */
    public function statusLabel(): string
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return (string) $this->status;
        }

        return $this->isExpired() ? 'expired' : self::STATUS_ACTIVE;
    }

    /** Live collections only, for a plain or Eloquent query over dam_collections. */
    public static function whereLive($query)
    {
        return $query->where('dam_collections.status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('dam_collections.expires_at')->orWhere('dam_collections.expires_at', '>', now()));
    }

    public function scopeLive(Builder $query): Builder
    {
        return self::whereLive($query);
    }
}
