<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kalnoy\Nestedset\NodeTrait;
use Mothership\DAM\Contracts\Directory as DirectoryContract;
use Mothership\DAM\Database\Eloquent\Builder;
use Mothership\DAM\Database\Factories\DirectoryFactory;
use Mothership\DAM\Services\DirectoryTreeLock;

class Directory extends Model implements DirectoryContract
{
    use HasFactory;
    use NodeTrait;

    const ASSETS_DIRECTORY = 'assets';

    const ASSETS_DISK_PRIVATE = 'private';

    const ASSETS_DISK_AWS = 's3';

    const NON_DELETABLE_DRECTORIES = [1];

    protected $table = 'dam_directories';

    protected $fillable = ['name', 'parent_id', 'created_by'];

    protected static function booted(): void
    {
        // The owner of every new item (ADR 0010). A REST request signs in on the `api` guard, not `admin`,
        // so it would otherwise leave created_by null and the item ownerless.
        static::creating(function (Directory $directory) {
            $directory->created_by ??= auth('admin')->id() ?? auth('api')->id();
        });
    }

    /**
     * Run a tree mutation under the one directory-tree lock (ADR 0004). Writers that read the tree before
     * changing it wrap the whole operation so those reads happen under the lock too.
     */
    public static function withTreeLock(callable $callback): mixed
    {
        return app(DirectoryTreeLock::class)->run($callback);
    }

    /**
     * Every nested-set shift kalnoy performs happens inside save() (the `saving` hook) or delete() (the
     * `deleted` hook), so locking these two entry points covers every creator and mover in the codebase,
     * including the ones that never go through DirectoryRepository.
     */
    public function save(array $options = [])
    {
        return static::withTreeLock(fn () => parent::save($options));
    }

    public function delete()
    {
        return static::withTreeLock(fn () => parent::delete());
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'dam_asset_directory')->withDisplayName();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Directory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Directory::class, 'parent_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'target_id')
            ->where('share_type', Share::TYPE_DIRECTORY);
    }

    public function isDeletable()
    {
        return ! in_array($this->id, self::NON_DELETABLE_DRECTORIES);
    }

    public function isCopyable()
    {
        return ! in_array($this->id, self::NON_DELETABLE_DRECTORIES);
    }

    protected static function newFactory(): Factory
    {
        return DirectoryFactory::new();
    }

    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    public function generatePath(): string
    {
        $path = [];

        foreach ($this->ancestorsAndSelfAndDefaultOrder($this->id) as $directory) {
            $path[] = $directory->name;
        }

        return implode('/', $path);
    }

    /** `private` or `s3`: `dam.asset_disk` (MOTHERSHIP_ASSET_DISK) when set, else `s3` only when FILESYSTEM_DISK is `s3`. */
    public static function getAssetDisk(): string
    {
        $disk = config('dam.asset_disk') ?: config('filesystems.default');

        if ($disk === self::ASSETS_DISK_AWS) {
            return self::ASSETS_DISK_AWS;
        }

        return self::ASSETS_DISK_PRIVATE;
    }

    public static function uniqueName(string $name, int $parentId): string
    {
        if (! static::where('name', $name)->where('parent_id', $parentId)->exists()) {
            return $name;
        }

        $candidate = $name.' (copy)';
        if (! static::where('name', $candidate)->where('parent_id', $parentId)->exists()) {
            return $candidate;
        }

        $i = 1;
        do {
            $candidate = $name.' (copy) ('.$i.')';
            $i++;
        } while (static::where('name', $candidate)->where('parent_id', $parentId)->exists());

        return $candidate;
    }
}
