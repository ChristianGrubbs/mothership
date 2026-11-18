<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\User\Models\Admin;

/**
 * A font or brush someone uploaded for the Creative Hub apps (ADR 0015). Its bytes are a FileObject blob (ADR 0009);
 * HubResourceStore is the only writer. Who may read it is decided by visibleTo() / isVisibleTo(), the one rule:
 * an active admin reads it when they are a full admin, own it, it is shared with Everyone, or it is shared with
 * groups and they belong to one (the built-in Everyone group counts for every active admin).
 *
 * @property int $id
 * @property string $uuid
 * @property int $owner_id
 * @property string $kind
 * @property string $format
 * @property string $name
 * @property int $file_object_id
 * @property int $size
 * @property string $visibility
 */
class HubResource extends Model
{
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_EVERYONE = 'everyone';

    public const VISIBILITY_GROUPS = 'groups';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_EVERYONE, self::VISIBILITY_GROUPS];

    protected $table = 'dam_hub_resources';

    protected $guarded = ['id'];

    protected $casts = [
        'owner_id'       => 'integer',
        'file_object_id' => 'integer',
        'size'           => 'integer',
        'weight'         => 'integer',
        'meta'           => 'array',
        'attested_at'    => 'datetime',
        'attested_by'    => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $resource): void {
            $resource->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_id');
    }

    /** @return list<int> the dam_groups this resource is shared with (meaningful under VISIBILITY_GROUPS) */
    public function groupIds(): array
    {
        return DB::table('dam_hub_resource_group_access')->where('resource_id', $this->getKey())->orderBy('group_id')
            ->pluck('group_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The resources $admin may read. A disabled admin reads none, not even their own; a full admin (or an admin
     * with no role, ADR 0010's parity case) reads all.
     *
     * @param  Builder<HubResource>  $query
     */
    public function scopeVisibleTo(Builder $query, Admin $admin): void
    {
        if (! (bool) $admin->status) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (self::readsEverything($admin)) {
            return;
        }

        $groupIds = self::groupIdsOf($admin);

        $query->where(function (Builder $query) use ($admin, $groupIds): void {
            $query->where('owner_id', $admin->getKey())
                ->orWhere('visibility', self::VISIBILITY_EVERYONE)
                ->orWhere(function (Builder $query) use ($groupIds): void {
                    $query->where('visibility', self::VISIBILITY_GROUPS)
                        ->whereIn('id', function (QueryBuilder $listed) use ($groupIds): void {
                            $listed->select('resource_id')->from('dam_hub_resource_group_access')->whereIn('group_id', $groupIds);
                        });
                });
        });
    }

    public function isVisibleTo(Admin $admin): bool
    {
        return self::query()->whereKey($this->getKey())->visibleTo($admin)->exists();
    }

    /** Whether $admin is a full admin for resource reads: permission_type `all`, or no role at all. Disabled never is. */
    public static function readsEverything(Admin $admin): bool
    {
        if (! (bool) $admin->status) {
            return false;
        }

        $role = $admin->role;

        return ! $role || $role->permission_type === 'all';
    }

    /** @return list<int> the groups $admin belongs to, plus the built-in Everyone group */
    private static function groupIdsOf(Admin $admin): array
    {
        return DB::table('dam_groups')
            ->whereIn('id', fn (QueryBuilder $members) => $members->select('group_id')->from('dam_group_members')->where('admin_id', $admin->getKey()))
            ->orWhere('system_key', AccessListReader::EVERYONE)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
