<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mothership\DAM\Contracts\Asset as AssetContract;
use Mothership\DAM\Database\Factories\AssetFactory;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\CaptureGroupingService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Services\Organization\AssetOrientation;
use Mothership\DAM\Services\PanoramaClassifier;
use Mothership\DAM\Support\BidiControls;
use Mothership\DAM\Support\FilenameStem;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\User\Models\Admin;
use Symfony\Component\Mime\MimeTypes;

class Asset extends Model implements AssetContract, HistoryAuditable
{
    use HasFactory;
    use HistoryTrait;
    use SoftDeletes;

    /**
     * Older API uploads encoded metadata before the array cast encoded it again.
     * Normalize reads without rewriting rows; SQL JSON-path readers still require stored objects.
     */
    public function getMetaDataAttribute(mixed $value): mixed
    {
        $metadata = $this->fromJson($value);

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $metadata;
    }

    public function getFileTypeAttribute(?string $value): ?string
    {
        // A .dng is an image unless its bytes were positively identified as another family (a WAV or PDF named raw.dng).
        $family = AssetHelper::confidentFamily($this->attributes['mime_type'] ?? null);
        $isDng = strtolower((string) ($this->attributes['extension'] ?? '')) === 'dng' && ($family === null || $family === 'image');

        return $isDng ? 'image' : $value;
    }

    const ASSET_ATTRIBUTE_TYPE = 'asset';

    public const PANORAMA_AUTO = 'auto';

    public const PANORAMA_FORCE_360 = 'force_360';

    public const PANORAMA_FORCE_FLAT = 'force_flat';

    public const PANORAMA_MODES = [self::PANORAMA_AUTO, self::PANORAMA_FORCE_360, self::PANORAMA_FORCE_FLAT];

    /** Why a stack's files belong together; the stack key names the product, campaign or use case. */
    public const STACK_BASES = ['product', 'campaign', 'use_case', 'other'];

    /** File roles that are history or generated previews, not files a person stacked. */
    public const UNSTACKED_ROLES = [AssetFile::ROLE_VERSION, AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL];

    /** P2 S3: whether the asset is out for use. Only `available` inside its window is seen by everyone it is shared with. */
    public const AVAILABILITY_AVAILABLE = 'available';

    public const AVAILABILITY_DRAFT = 'draft';

    public const AVAILABILITY_PENDING_REVIEW = 'pending_review';

    public const AVAILABILITY_ARCHIVED = 'archived';

    public const AVAILABILITY_STATES = [self::AVAILABILITY_AVAILABLE, self::AVAILABILITY_DRAFT, self::AVAILABILITY_PENDING_REVIEW, self::AVAILABILITY_ARCHIVED];

    /** An `available` asset whose window has not opened yet, or has closed: the two badges a window adds. */
    public const AVAILABILITY_SCHEDULED = 'scheduled';

    public const AVAILABILITY_EXPIRED = 'expired';

    /** P2 S4: how long a trashed asset can be restored before dam:trash:purge deletes it for good. */
    public const TRASH_RETENTION_DAYS = 30;

    /** P2 S4: the reserved Library prefix a trashed asset's path moves under (`assets/.trash/{id}/{name}`). */
    public const TRASH_DIRECTORY = Directory::ASSETS_DIRECTORY.'/.trash';

    protected $historyTags = ['asset'];

    protected $table = 'dam_assets';

    protected $fillable = [
        'file_name', 'file_type', 'file_size', 'path', 'mime_type', 'extension',
        'meta_data', 'legacy_ref', 'title', 'asset_kind', 'panorama_mode', 'created_by',
        'stack_basis', 'stack_key', 'availability', 'available_from', 'available_until',
    ];

    /** A database-generated helper column (see the migration that adds it), not part of an asset's API shape. */
    protected $hidden = ['meta_display_title'];

    protected $casts = [
        'meta_data'         => 'array',
        'needs_sorting_at'  => 'datetime',
        'available_from'    => 'datetime',
        'available_until'   => 'datetime',
        'deleted_at'        => 'datetime',
        'merged_into_id'    => 'integer',
        // P5a: the guest visit this file came from; set once by publishIntake() and never mass-assignable.
        'upload_submission_id' => 'integer',
    ];

    /** Who is trashing the asset, when not the signed-in admin (a queued folder delete); read by runSoftDelete(). */
    private ?int $trashingAdminId = null;

    /** One label for browsing, sorting and searching; never changes stored filenames. */
    public static function displayNameSql(): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $properties = $grammar->wrapTable('dam_asset_properties');
        $assetId = $grammar->wrap('dam_assets.id');
        $propertyTitle = "(SELECT value FROM {$properties} WHERE dam_asset_id = {$assetId} AND LOWER(name) = 'title' AND TRIM(value) <> '' ORDER BY id LIMIT 1)";
        $candidates = [$propertyTitle, $grammar->wrap('dam_assets.title')];

        // meta_display_title already is COALESCE(NULLIF(TRIM(meta_data->Title), ''), NULLIF(TRIM(meta_data->title), '')),
        // computed by the database over the json's own bytes. It is used bare, not re-wrapped in NULLIF/TRIM: on MySQL the
        // column has the table's collation, under which a title of only collation-ignorable characters would compare equal
        // to '' here although it did not in the json comparison the column was built from.
        return 'COALESCE('.implode(', ', array_map(fn ($value) => "NULLIF(TRIM({$value}), '')", $candidates)).', '.$grammar->wrap('dam_assets.meta_display_title').', '.$grammar->wrap('dam_assets.file_name').')';
    }

    public function scopeWithDisplayName($query)
    {
        if ($query->getQuery()->columns === null) {
            $query->select('dam_assets.*');
        }

        return $query->selectRaw(self::displayNameSql().' AS display_name');
    }

    public function getDisplayNameAttribute($value): string
    {
        $value ??= $this->attributes['display_name'] ?? null;

        if ($value !== null) {
            return BidiControls::strip((string) $value);
        }

        if ($this->exists) {
            // withTrashed: the Trash page names trashed assets too (P2 S4).
            return BidiControls::strip((string) self::withTrashed()->whereKey($this->getKey())
                ->selectRaw(self::displayNameSql().' AS resolved_name')->value('resolved_name'));
        }

        return BidiControls::strip(trim((string) $this->title) ?: (string) $this->file_name);
    }

    protected $auditExclude = [
        'id',
        // Derived from file_name on every save (P3b-2); the rename itself is the history entry.
        'name_stem',
        // Generated by the database from meta_data's Title/title; meta_data is the audited change.
        'meta_display_title',
    ];

    /**
     * P3b-2 (D16 exact): set by AssetUploadPublisher on the asset it just published, so the caller can say whether
     * these bytes are already in the Library. Never persisted.
     */
    public ?UploadReceipt $uploadReceipt = null;

    /**
     * Transient ingestion hints. Callers replacing bytes without changing the
     * persisted content descriptors can mark the save explicitly and may pass
     * a checksum they already computed while ingesting the file.
     */
    private bool $graphContentChangePending = false;

    private ?string $graphContentChecksum = null;

    /** Bytes a writer already ingested (ADR 0009 slice 3): the observer links this blob, never a Library file. */
    private ?FileObject $graphContentObject = null;

    /**
     * Who is replacing the content, when the writer knows it better than the session does: the queued chunk
     * finalizer has no session, so it names the uploader here and the graph observer records them as the new
     * revision's and version's author.
     */
    private ?int $graphContentActor = null;

    /**
     * Memoized panoramaClassification() result. classify() runs an
     * asset->files()->with('fileObject') query twice internally (dimensions()
     * and, for video, serverDirectCandidate()), and playbackSource()/
     * playbackFile()/panoramaKind() each call panoramaClassification()
     * independently — without this cache a single show()/edit() request
     * classifies the same asset three-plus times. Cleared by
     * forgetPanoramaClassification() whenever panorama_mode changes.
     */
    private ?array $panoramaClassificationCache = null;

    public function markGraphContentChanged(?string $sha256 = null): static
    {
        if ($sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            throw new InvalidArgumentException('Asset graph checksums must be 64 hexadecimal characters.');
        }

        $this->graphContentChangePending = true;
        $this->graphContentChecksum = $sha256 === null ? null : strtolower($sha256);

        return $this;
    }

    /**
     * Save this asset with bytes already in the blob store: create, re-upload and in-place edits hand
     * the observer the FileObject that ContentStore returned, so nothing is written at the Library path.
     */
    public function withGraphContent(FileObject $object, ?int $actorId = null): static
    {
        $this->markGraphContentChanged((string) $object->sha256);
        $this->graphContentObject = $object;
        $this->graphContentActor = $actorId;

        return $this;
    }

    public function hasPendingGraphContentChange(): bool
    {
        return $this->graphContentChangePending;
    }

    public function graphContentChecksum(): ?string
    {
        return $this->graphContentChecksum;
    }

    public function graphContentObject(): ?FileObject
    {
        return $this->graphContentObject;
    }

    /** The admin withGraphContent() named as the author of this content change, if any. */
    public function graphContentActor(): ?int
    {
        return $this->graphContentActor;
    }

    public function clearGraphContentChange(): void
    {
        $this->graphContentChangePending = false;
        $this->graphContentChecksum = null;
        $this->graphContentObject = null;
        $this->graphContentActor = null;
    }

    /**
     * Upload paths always submit file_size, even when replacement bytes happen
     * to have the same length. Treat presence of that ingestion descriptor as
     * an explicit content signal; ordinary metadata and rename updates do not
     * submit it and therefore avoid hashing.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if (array_key_exists('file_size', $attributes)) {
            $this->markGraphContentChanged($this->graphContentChecksum);
        }

        return parent::update($attributes, $options);
    }

    /**
     * Keep the asset row update and its observer-driven graph transition in the
     * same transaction. The UPDATE itself owns the row lock until the observer
     * completes, serializing competing replacements for a single asset.
     */
    public function save(array $options = []): bool
    {
        $needsGraphTransaction = ! $this->exists
            || $this->graphContentChangePending
            || $this->isDirty(['path', 'file_size']);

        if (! $needsGraphTransaction) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            // Bytes replaced at the same path with the same length, within the second of the last
            // update, dirty no column (touch() included), so Eloquent fires no `updated` event. An
            // explicit content change is still an update the graph must see.
            $contentOnly = $this->exists && $this->graphContentChangePending && ! $this->isDirty();
            $saved = parent::save($options);

            if ($saved && $contentOnly && $this->graphContentChangePending) {
                $this->fireModelEvent('updated', false);
            }

            return $saved;
        });
    }

    /**
     * The row lock for changing an asset's content graph. It serializes writers of this asset like FOR
     * UPDATE, but on PostgreSQL it is FOR NO KEY UPDATE (what a plain UPDATE takes), so another transaction
     * can still take the KEY SHARE lock a foreign key to this row needs. A writer that holds a blob lock and
     * then inserts a row referencing this asset (GenerationPackageImporter's derived-from edges) can then
     * never wait on a graph writer that is itself waiting for that blob (ContentStore::lockBlob).
     */
    public function scopeLockedForGraph($query)
    {
        return $query->getConnection()->getDriverName() === 'pgsql'
            ? $query->lock('for no key update')
            : $query->lockForUpdate();
    }

    /**
     * Assets out for use right now: `available`, and inside the window when one is set. $table names the assets
     * table's alias when the query reads it under another name.
     */
    public function scopeAvailableNow($query, string $table = 'dam_assets')
    {
        return self::whereAvailableNow($query, $table);
    }

    /** scopeAvailableNow() for a plain query builder too, e.g. a subquery over the assets table. */
    public static function whereAvailableNow($query, string $table = 'dam_assets')
    {
        $now = now();

        return $query->where("{$table}.availability", self::AVAILABILITY_AVAILABLE)
            ->where(fn ($q) => $q->whereNull("{$table}.available_from")->orWhere("{$table}.available_from", '<=', $now))
            ->where(fn ($q) => $q->whereNull("{$table}.available_until")->orWhere("{$table}.available_until", '>', $now));
    }

    /**
     * The availability columns as stored, for a row a copy inserts directly: a copy is exactly as out for use as
     * its source, never published by being copied.
     *
     * @return array{availability: string, available_from: mixed, available_until: mixed}
     */
    public static function availabilityOf(self $asset): array
    {
        return [
            'availability'    => $asset->getRawOriginal('availability') ?? self::AVAILABILITY_AVAILABLE,
            'available_from'  => $asset->getRawOriginal('available_from'),
            'available_until' => $asset->getRawOriginal('available_until'),
        ];
    }

    /**
     * Why the asset is not out for use right now: draft, pending_review or archived for its state, scheduled or
     * expired for its window; null when it is available. The state wins over the window.
     */
    public function unavailableBecause(): ?string
    {
        $state = $this->availability ?? self::AVAILABILITY_AVAILABLE;

        if ($state !== self::AVAILABILITY_AVAILABLE) {
            return $state;
        }

        $now = now();

        if ($this->available_from !== null && $this->available_from->gt($now)) {
            return self::AVAILABILITY_SCHEDULED;
        }

        if ($this->available_until !== null && $this->available_until->lte($now)) {
            return self::AVAILABILITY_EXPIRED;
        }

        return null;
    }

    /**
     * Listing scope only: file APIs and existing references still resolve companions. A companion is hidden only
     * behind a primary that is not in the trash (P2 S4): trashing the primary shows its companions again, as a
     * permanent delete of it always has.
     */
    public function scopeVisibleCaptures($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('dam_assets.capture_group_id')->orWhereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('dam_capture_groups')
                    ->whereColumn('dam_capture_groups.id', 'dam_assets.capture_group_id')
                    ->whereNotNull('dam_capture_groups.primary_asset_id')
                    ->whereColumn('dam_capture_groups.primary_asset_id', '!=', 'dam_assets.id')
                    ->whereExists(fn ($primary) => $primary->selectRaw('1')->from('dam_assets as capture_primary')
                        ->whereColumn('capture_primary.id', 'dam_capture_groups.primary_asset_id')
                        ->whereNull('capture_primary.deleted_at'));
            });
        });
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'dam_asset_tag');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** The page's collection placement (ADR 0008); at most one row per asset. */
    public function webCollection(): BelongsToMany
    {
        return $this->belongsToMany(WebCollection::class, 'dam_web_collection_assets', 'asset_id', 'collection_id');
    }

    public function directories(): BelongsToMany
    {
        // Ordered by pivot id so "the asset's first directory" (canActOnAsset(), FileController,
        // AssetNeighbours' fallback, every `->first()` consumer) means the directory attached first.
        // Without it PostgreSQL returns a multi-directory asset's rows in planner order, and which
        // directory governs access or navigation changed from one request to the next (2026-09-16).
        return $this->belongsToMany(Directory::class, 'dam_asset_directory')
            ->orderBy('dam_asset_directory.id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(AssetProperty::class, 'dam_asset_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AssetComments::class, 'dam_asset_id')
            ->whereNull('parent_id')
            ->with(['admin', 'children.admin']);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(AssetResourceMapping::class, 'dam_asset_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'target_id')
            ->where('share_type', Share::TYPE_ASSET);
    }

    public function getPathWithOutFileSystemRoot()
    {
        return str_replace(Directory::ASSETS_DIRECTORY.'/', '', $this->path);
    }

    protected static function newFactory(): Factory
    {
        return AssetFactory::new();
    }

    protected static function booted(): void
    {
        // The owner of every new item (ADR 0010). A REST request signs in on the `api` guard, not `admin`,
        // so it would otherwise leave created_by null and the item ownerless.
        static::creating(function (Asset $asset) {
            $asset->created_by ??= auth('admin')->id() ?? auth('api')->id();
        });
        static::updating(function (Asset $asset) {
            if ($asset->capture_group_id && $asset->isDirty(['file_name', 'path', 'extension'])) {
                app(CaptureGroupingService::class)->detachChangedMember(asset: $asset);
            }
        });
        static::saving(function (self $asset): void {
            if ($asset->panorama_mode !== null && ! in_array($asset->panorama_mode, self::PANORAMA_MODES, true)) {
                throw new InvalidArgumentException('Invalid panorama mode.');
            }

            // A database-generated column (meta_display_title) rejects any INSERT/UPDATE value, so a copy made by
            // replicate() or a forceFill() from loaded attributes must never carry it into the write.
            if (! $asset->exists || $asset->isDirty('meta_display_title')) {
                unset($asset->attributes['meta_display_title']);
            }

            // Stack suggestions group by this (P3b-2); dam:name-stems repairs rows a raw update renamed.
            if (! $asset->exists || $asset->isDirty('file_name')) {
                $asset->setAttribute('name_stem', FilenameStem::of($asset->file_name));
            }

            // P4 (D10): the Library's orientation filter reads what the stored width and height describe.
            AssetOrientation::refresh($asset);
        });
        // Only a permanent delete releases the asset's files: a trashed asset keeps its versions, renditions and
        // attachments so Restore brings it back whole (P2 S4).
        static::deleting(function (self $asset): void {
            if ($asset->isForceDeleting()) {
                self::releaseFileOwnership(assetIds: [$asset->getKey()]);
            }
        });
    }

    /** Names who trashes the asset when no admin is signed in (a queued folder delete). */
    public function trashedBy(?int $adminId): static
    {
        $this->trashingAdminId = $adminId;

        return $this;
    }

    /**
     * Moving to the trash (P2 S4) is SoftDeletes' delete plus the Library path: the old path is kept for Restore and
     * the path moves under the reserved `assets/.trash/{id}/` prefix, so a same-name upload makes a new asset and
     * never lands on the trashed one. `updated_at` is left alone, so Restore round-trips the row as it was.
     */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());
        $time = $this->freshTimestamp();
        $fromPath = (string) $this->getRawOriginal('path');

        $columns = [
            $this->getDeletedAtColumn() => $this->fromDateTime($time),
            'deleted_by'                => $this->trashingAdminId ?? auth('admin')->id() ?? auth('api')->id(),
            'trashed_from_path'         => $fromPath,
            'path'                      => self::trashPath(assetId: (int) $this->getKey(), fileName: (string) $this->file_name),
        ];

        // The base builder: Eloquent's update() would add updated_at itself.
        $query->toBase()->update($columns);

        foreach ($columns as $column => $value) {
            $this->{$column} = $column === $this->getDeletedAtColumn() ? $time : $value;
        }

        $this->syncOriginalAttributes(array_keys($columns));
        $this->trashingAdminId = null;

        $this->fireModelEvent('trashed', false);
    }

    /** A trashed asset's Library path: unique per id, under a dot-folder no person can create (ReservedDirectoryNames). */
    public static function trashPath(int $assetId, string $fileName): string
    {
        $prefix = self::TRASH_DIRECTORY.'/'.$assetId.'/';
        $name = $fileName !== '' ? $fileName : (string) $assetId;

        // The column holds 255 characters; a name too long for the prefix is cut, keeping its extension.
        if (mb_strlen($prefix.$name) > 255) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $extension !== '' && mb_strlen($extension) < 20 ? '.'.$extension : '';
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 255 - mb_strlen($prefix) - mb_strlen($suffix)).$suffix;
        }

        return $prefix.$name;
    }

    /**
     * P3b-1: whether this asset is a merge source. Its files live on as slots of the destination it names; the asset
     * itself is hidden from every read path for everyone (AssetAccessDecision) until a split brings it back.
     */
    /**
     * P5a: guest intake still awaiting a staff decision, a file publishIntake() created that is still `pending_review`.
     * AssetAccessDecision keeps it to its owner (the request's creator) and full admins under either access model.
     */
    public function isPendingGuestIntake(): bool
    {
        return $this->upload_submission_id !== null && ($this->availability ?? self::AVAILABILITY_AVAILABLE) === self::AVAILABILITY_PENDING_REVIEW;
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /** The admin who trashed the asset (P2 S4). */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'deleted_by');
    }

    /**
     * Versions and renditions point at their asset's files through composite owner keys with no
     * delete action (2026_09_02_000002). PostgreSQL checks those after the asset's cascades have
     * run; InnoDB checks them while cascading into dam_asset_files and refuses the delete (1451).
     * Both tables cascade from the asset anyway, so removing them first is the same end state.
     * A mass delete skips model events and must call this itself.
     *
     * On SQL Server an image editor document's asset_version_id is NO ACTION rather than SET NULL
     * (a second path from the asset, error 1785), so it is cleared before its version goes. An
     * optimization's asset_file_id is NO ACTION there for the same reason and is cleared too.
     */
    public static function releaseFileOwnership(iterable $assetIds): void
    {
        $assetIds = collect($assetIds)->all();
        $versions = AssetVersion::query()->whereIn('dam_asset_id', $assetIds);

        if ($versions->getConnection()->getDriverName() === 'sqlsrv') {
            ImageEditorDocument::query()
                ->whereIn('asset_version_id', (clone $versions)->select('id'))
                ->update(['asset_version_id' => null]);
            DB::table('dam_optimizations')
                ->whereIn('asset_id', $assetIds)
                ->whereNotNull('asset_file_id')
                ->update(['asset_file_id' => null]);

            foreach (['dam_vectorizations', 'dam_optimizations'] as $table) {
                DB::table($table)
                    ->whereIn('source_asset_file_id', AssetFile::query()->whereIn('dam_asset_id', $assetIds)->select('id'))
                    ->update(['source_asset_file_id' => null]);
            }
        }

        $versions->delete();
        Rendition::query()->whereIn('dam_asset_id', $assetIds)->delete();
        // A copy saved from another entry outlives that entry's lineage row the other way round.
        Rendition::query()->whereIn('output_asset_id', $assetIds)->update(['output_asset_id' => null]);
        LineageInput::query()->whereIn('asset_file_id', AssetFile::query()->whereIn('dam_asset_id', $assetIds)->select('id'))
            ->update(['asset_file_id' => null]);
    }

    /** Per-row count of the files a person stacked on the asset: its attachments minus history and previews. */
    public static function attachmentCountSubquery(): Builder
    {
        return DB::table('dam_asset_files')
            ->selectRaw('count(*)')
            ->whereColumn('dam_asset_files.dam_asset_id', 'dam_assets.id')
            ->where('dam_asset_files.is_current', true)
            ->whereNotIn('dam_asset_files.role', self::UNSTACKED_ROLES);
    }

    /** The bytes of every file a stack holds, counted like attachment_count, for the Library list's Size column (VISR-09). */
    public static function attachmentSizeSubquery(): Builder
    {
        return DB::table('dam_asset_files')
            ->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->selectRaw('coalesce(sum(dam_file_objects.size), 0)')
            ->whereColumn('dam_asset_files.dam_asset_id', 'dam_assets.id')
            ->where('dam_asset_files.is_current', true)
            ->whereNotIn('dam_asset_files.role', self::UNSTACKED_ROLES);
    }

    /**
     * Orders the Library by the size its list shows (VISR-09): a stack's files together, any other entry its
     * own file. One CASE over the two correlated subqueries, so every driver sorts and pages by the same value.
     */
    public static function orderByListSize($query, string $direction): void
    {
        $count = self::attachmentCountSubquery();
        $size = self::attachmentSizeSubquery();
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $query->orderByRaw(
            "CASE WHEN ({$count->toSql()}) > 1 AND ({$size->toSql()}) > 0 THEN ({$size->toSql()}) ELSE dam_assets.file_size END {$direction}",
            [...$count->getBindings(), ...$size->getBindings(), ...$size->getBindings()],
        );
    }

    /** How many files this entry holds as a stack, counted like the Library card's badge. */
    public function stackFileCount(): int
    {
        return $this->files()->where('is_current', true)->whereNotIn('role', self::UNSTACKED_ROLES)->count();
    }

    /**
     * Asset graph (ADR 0002): typed files attached to this asset.
     */
    public function captureFileCount(): int
    {
        // The Files tab's own rule (AssetRelationshipService::files()): a companion the viewer cannot open (hidden,
        // a draft, outside its window) is not listed there, so it is not counted here either (P2 S3 review).
        $ids = $this->capture_group_id
            ? self::where('capture_group_id', $this->capture_group_id)->pluck('id')
                ->filter(fn ($id) => (int) $id === (int) $this->id
                    || app(AssetAccessDecision::class)->visible(AccessContext::library(), (int) $id))
                ->values()
            : [$this->id];

        return AssetFile::whereIn('dam_asset_id', $ids)->where('is_current', true)->count();
    }

    public function files(): HasMany
    {
        return $this->hasMany(AssetFile::class, 'dam_asset_id');
    }

    /** The asset's file slots (P3a S2), representative first. */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'dam_asset_id')->orderBy('position')->orderBy('id');
    }

    public function fileObjects(): HasManyThrough
    {
        return $this->hasManyThrough(
            FileObject::class,
            AssetFile::class,
            'dam_asset_id',
            'id',
            'id',
            'file_object_id'
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class, 'dam_asset_id');
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(Rendition::class, 'dam_asset_id');
    }

    public function relationshipsFrom(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'from_asset_id');
    }

    public function relationshipsTo(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'to_asset_id');
    }

    /**
     * Both directions of the relationship graph, grouped by type.
     */
    public function related(): array
    {
        return [
            'outgoing' => $this->relationshipsFrom()->get()->groupBy('type'),
            'incoming' => $this->relationshipsTo()->get()->groupBy('type'),
        ];
    }

    /**
     * Return the browser-safe source used by DAM previews.
     *
     * @return array{url: string, mime: string, kind: string, label: string}|null
     */
    public function playbackSource(): ?array
    {
        if (strtolower((string) $this->extension) === 'dng') {
            return [
                'url'                     => $this->libraryPreviewUrl(),
                'mime'                    => 'image/jpeg',
                'kind'                    => 'image',
                'label'                   => 'DNG preview',
                'server_direct_candidate' => false,
                'original_url'            => null,
                'is_proxy'                => false,
            ];
        }

        $file = $this->playbackFile();

        // A HEIC/HEIF original plays through its WebP copy only when nothing else was chosen: a playable proxy
        // (a stitched 360 image or video, say) keeps the precedence playbackFile() gives it.
        if ($file === null) {
            return $this->heifPlaybackSource();
        }

        // The original plays from its Library path (a logical key the preview route resolves through the
        // read seam); attachments from their own route. Neither URL names a storage locator.
        $url = $file['asset_file_id'] === null
            ? $this->libraryPreviewUrl()
            : route('admin.dam.file.asset-file', [
                'asset'     => $this->getKey(),
                'assetFile' => $file['asset_file_id'],
            ]);

        $isPreviewProxy = $file['preview_proxy'] ?? false;

        return [
            'url'                     => $url,
            'mime'                    => $file['mime'],
            'kind'                    => $file['kind'],
            'label'                   => $file['label'],
            'server_direct_candidate' => $this->panoramaClassification()['direct_candidate'],
            'original_url'            => $isPreviewProxy ? $this->originalPlaybackUrl() : null,
            'is_proxy'                => $isPreviewProxy,
        ];
    }

    /**
     * A HEIC/HEIF original is not something a browser decodes, so it plays through the bounded WebP copy the preview
     * route makes (like a DNG's JPEG), and only while the current original's blob is intact. The stored MIME type of
     * the current original decides; playbackFile() and originalBrowserPlaybackType() still say the original itself is
     * not browser-playable, so nothing treats the HEIC bytes as decodable.
     *
     * @return array{url: string, mime: string, kind: string, label: string, server_direct_candidate: bool, original_url: null, is_proxy: bool}|null
     */
    private function heifPlaybackSource(): ?array
    {
        $original = $this->playbackAttachments()->firstWhere('role', AssetFile::ROLE_ORIGINAL);
        $mime = strtolower(trim(explode(';', (string) ($original?->fileObject?->mime_type ?: $this->mime_type))[0]));

        if (! in_array($mime, ['image/heic', 'image/heif'], true) || $original === null || ! $this->assetFileExists($original)) {
            return null;
        }

        return [
            'url'                     => route('admin.dam.file.preview', ['path' => urlencode((string) $this->path), 'size' => 1920]),
            'mime'                    => 'image/webp',
            'kind'                    => 'image',
            'label'                   => 'HEIC preview',
            'server_direct_candidate' => false,
            'original_url'            => null,
            'is_proxy'                => false,
        ];
    }

    /**
     * Panorama classification for this asset: whether it is a 360 panorama,
     * why, and whether the browser can play it directly or needs an
     * on-demand proxy.
     *
     * @return array{mode: string, is_panorama: bool, reason: string, media_type: ?string, direct_candidate: bool, requires_proxy: bool}
     */
    public function panoramaClassification(): array
    {
        return $this->panoramaClassificationCache ??= app(PanoramaClassifier::class)->classify($this);
    }

    /**
     * Invalidate the memoized classification. Call after changing
     * panorama_mode (or anything else classify() reads) on an instance that
     * stays alive and may be classified again in the same request.
     */
    public function forgetPanoramaClassification(): void
    {
        $this->panoramaClassificationCache = null;
    }

    /**
     * Resolve the stored file behind playback. The thumbnail worker uses the same
     * decision as the browser, without deriving a storage path from the public URL.
     *
     * @return array{asset_file_id: int|null, disk: string, path: string, extension: string, mime: string, kind: string, label: string, preview_proxy: bool}|null
     */
    public function playbackFile(): ?array
    {
        $attachments = $this->playbackAttachments();
        $facts = $this->originalPlaybackFacts($attachments);
        $original = $facts['original'];
        $originalExtension = $facts['extension'];
        $originalMime = $facts['mime'];
        $originalType = $facts['type'];

        $preview = $this->publishedPreviewProxy();

        if ($preview !== null) {
            return $this->assetFilePlaybackDescriptor($preview, 'video', previewProxy: true);
        }

        // The original is the current() FileObject (the `original` attachment, already loaded here) and
        // plays only while its blob is intact. The descriptor names that blob; its extension is separate.
        $originalObject = $original?->fileObject;

        if (
            $originalType !== null
            && $originalObject !== null
            && $this->assetFileExists($original)
        ) {
            return $this->playbackDescriptor(
                null,
                (string) $originalObject->disk,
                (string) $originalObject->path,
                $originalExtension,
                $originalMime,
                $this->panoramaKind($originalType),
                $original?->label ?: 'Original',
            );
        }

        // A preview proxy made from another file of the stack (a member's, or a former cover's) is never this
        // cover's fallback: it would play another file's video under the cover.
        $ownContent = Rendition::query()->where('dam_asset_id', $this->getKey())->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->fromCurrentContent((int) $this->getKey())->select('id');
        $otherContentOutputs = Rendition::query()->where('dam_asset_id', $this->getKey())
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->whereNotNull('output_file_object_id')
            ->whereNotIn('id', $ownContent)
            ->pluck('output_file_object_id');
        $proxies = $attachments
            ->where('role', AssetFile::ROLE_PROXY)
            ->reject(fn (AssetFile $file): bool => $otherContentOutputs->contains($file->file_object_id))
            ->filter(fn (AssetFile $file): bool => $this->assetFileExists($file))
            ->values();

        $generatedObjectId = $this->renditions()
            ->where('recipe', Rendition::RECIPE_PANORAMA_4K)
            ->where('status', Rendition::STATUS_DONE)
            ->whereNotNull('output_file_object_id')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->value('output_file_object_id');

        $generated = $proxies->firstWhere('file_object_id', $generatedObjectId);

        if ($generated && $this->panoramaClassification()['is_panorama']) {
            return $this->assetFilePlaybackDescriptor($generated, 'pano360-video');
        }

        $stitched = $proxies->first(function (AssetFile $file): bool {
            $label = strtolower((string) $file->label);

            return str_contains($label, 'stitched')
                && str_contains($label, '360')
                && $this->assetFilePlaybackType($file) !== null;
        });

        if ($stitched) {
            $type = $this->assetFilePlaybackType($stitched);

            return $this->assetFilePlaybackDescriptor(
                $stitched,
                $type === 'image' ? 'pano360-image' : 'pano360-video',
            );
        }

        $flatVideo = $proxies->first(
            fn (AssetFile $file): bool => $this->assetFilePlaybackType($file) === 'video'
        );

        if ($flatVideo) {
            return $this->assetFilePlaybackDescriptor($flatVideo, 'video');
        }

        if ($this->asset_kind === 'pano360') {
            $panoramaImage = $proxies->first(
                fn (AssetFile $file): bool => $this->assetFilePlaybackType($file) === 'image'
            );

            if ($panoramaImage) {
                return $this->assetFilePlaybackDescriptor($panoramaImage, 'pano360-image');
            }
        }

        return null;
    }

    /**
     * What plays one revision of this video or audio entry in the Comments tab (P7), as the file to stream through
     * the asset-file route; null when nothing a browser can play exists for it. The current representative revision
     * plays what playbackFile() chooses (its published proxy first). An older revision plays a preview proxy made
     * from its own content when one is intact, else its own file when a browser can decode it; a proxy-required
     * original with no proxy of its own (a ProRes .mov, say) has nothing to play.
     *
     * @return array{asset_file_id: int, mime: string, is_proxy: bool}|null
     */
    public function revisionPlayback(AssetFile $revision): ?array
    {
        if ((int) $revision->dam_asset_id !== (int) $this->getKey() || ! in_array($this->file_type, ['video', 'audio'], true)) {
            return null;
        }

        $currentId = AssetFile::query()
            ->where('dam_asset_id', $this->getKey())
            ->where('role', AssetFile::ROLE_ORIGINAL)
            ->where('is_current', true)
            ->value('id');

        if ((int) $revision->getKey() === (int) $currentId) {
            $file = $this->playbackFile();

            if ($file !== null && in_array($file['kind'], ['video', 'pano360-video'], true)) {
                return ['asset_file_id' => (int) ($file['asset_file_id'] ?? $revision->getKey()), 'mime' => (string) $file['mime'], 'is_proxy' => (bool) ($file['preview_proxy'] ?? false)];
            }
        } elseif ($this->file_type === 'video') {
            $proxy = $this->revisionPreviewProxy($revision);

            if ($proxy !== null) {
                return ['asset_file_id' => (int) $proxy->getKey(), 'mime' => (string) ($proxy->fileObject->mime_type ?: 'video/mp4'), 'is_proxy' => true];
            }
        }

        $revision->loadMissing('fileObject');

        if ($revision->fileObject === null || ! $this->assetFileExists($revision)) {
            return null;
        }

        $mime = strtolower(trim(explode(';', (string) $revision->fileObject->mime_type)[0]));
        $playable = $this->file_type === 'audio'
            ? in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/ogg', 'audio/webm', 'audio/flac', 'audio/x-flac'], true)
                || in_array(strtolower($revision->extension()), ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac'], true)
            : $this->assetFilePlaybackType($revision) === 'video';

        return $playable ? ['asset_file_id' => (int) $revision->getKey(), 'mime' => $mime ?: (string) $this->mime_type, 'is_proxy' => false] : null;
    }

    /**
     * What plays one video file of this stack other than its cover (feedback #6). The cover plays its own preview
     * proxy (playbackFile()); every other member plays the proxy made from that member's own bytes, else its own file
     * when a browser can decode it, else nothing yet. Another member's proxy is never a candidate: a proxy is looked
     * up through the renditions whose source is a revision of this member's content.
     *
     * `state` is `ready` (play `url`) or `processing` (no source yet; the viewer says the preview is being made).
     *
     * @return array{state: string, url: ?string, mime: ?string, is_proxy: bool}|null null when the file is not a video
     */
    public function stackMemberPlayback(AssetFile $file): ?array
    {
        $file->loadMissing('fileObject');
        $object = $file->fileObject;
        $mime = strtolower(trim(explode(';', (string) $object?->mime_type)[0]));

        if ($object === null || (! str_starts_with($mime, 'video/') && ! in_array(strtolower($file->extension()), ['mp4', 'm4v', 'webm', 'mov'], true))) {
            return null;
        }

        $route = fn (AssetFile $target): string => route('admin.dam.file.asset-file', ['asset' => $this->getKey(), 'assetFile' => $target->getKey()]);

        $sources = AssetFile::query()->select('id')->where('dam_asset_id', $this->getKey())->where('file_object_id', $file->file_object_id);
        $outputIds = $this->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_DONE)
            ->whereNotNull('output_file_object_id')
            ->whereIn('source_asset_file_id', $sources)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->pluck('output_file_object_id');
        $proxies = $this->playbackAttachments()->where('role', AssetFile::ROLE_PROXY);

        foreach ($outputIds as $outputId) {
            $proxy = $proxies->firstWhere('file_object_id', $outputId);

            if ($proxy !== null && $this->assetFileExists($proxy) && $this->assetFilePlaybackType($proxy) === 'video') {
                return ['state' => 'ready', 'url' => $route($proxy), 'mime' => (string) ($proxy->fileObject->mime_type ?: 'video/mp4'), 'is_proxy' => true];
            }
        }

        // What the classifier calls playable, or an HEVC stream (which it does not): a browser may still decode that one.
        if (($this->assetFilePlaybackType($file) === 'video' || $this->fileHasHevc($file)) && $this->assetFileExists($file)) {
            // A QuickTime or HEVC file is not one every browser decodes, so it waits for its own proxy; unless no proxy
            // will come soon (proxies are off, a merge can still split its slot out, or the transcode failed), when its own bytes are the
            // best there is and the viewer tries them without a type hint.
            if ($this->fileNeedsPreviewProxy($file) && $this->memberProxyIsComing($file, $sources)) {
                return ['state' => 'processing', 'url' => null, 'mime' => null, 'is_proxy' => false];
            }

            return ['state' => 'ready', 'url' => $route($file), 'mime' => $mime ?: null, 'is_proxy' => false];
        }

        return ['state' => 'processing', 'url' => null, 'mime' => null, 'is_proxy' => false];
    }

    /**
     * Whether a file of this stack is one browsers do not all decode as it is, so a 1080p H.264 preview proxy is worth
     * making for it: a QuickTime container (`video/quicktime`, `.mov`) whatever it holds, or an HEVC/H.265 stream
     * recorded in the stored facts or in $probedTechnical (what a probe of the file just read, merged over them).
     *
     * @param  array<string, mixed>  $probedTechnical
     */
    public function fileNeedsPreviewProxy(AssetFile $file, array $probedTechnical = []): bool
    {
        $file->loadMissing('fileObject');
        $object = $file->fileObject;

        return $object !== null && $this->requiresPreviewProxy(
            strtolower($file->extension()),
            (string) $object->mime_type,
            [...(is_array($object->technical) ? $object->technical : []), ...$probedTechnical],
        );
    }

    /** The same question for this entry's original (the cover), judged on the probe laid over the stored metadata. */
    public function originalNeedsPreviewProxy(array $probedTechnical = []): bool
    {
        $facts = $this->originalPlaybackFacts(probedTechnical: $probedTechnical);
        $technical = array_merge(
            is_array($this->meta_data) ? $this->meta_data : [],
            is_array($facts['original']?->fileObject?->technical) ? $facts['original']->fileObject->technical : [],
            $probedTechnical,
        );

        return $this->requiresPreviewProxy($facts['extension'], $facts['mime'], $technical);
    }

    /**
     * Whether a proxy for this member is expected to appear: proxy generation is on, its slot is not held by a merge that can still be split
     * (GenerateVideoPreviewProxy waits for that), and no transcode of its content has failed without another running.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<AssetFile>  $sources  this entry's files holding the member's content
     */
    private function memberProxyIsComing(AssetFile $file, $sources): bool
    {
        if (! config('mothership.video_preview.enabled')) {
            return false;
        }

        if ($file->attachment_id !== null && Attachment::query()->whereKey($file->attachment_id)->whereNotNull('merge_id')->exists()) {
            return false;
        }

        $attempts = fn (string $status) => $this->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', $status)
            ->whereIn('source_asset_file_id', $sources);

        return $attempts(Rendition::STATUS_FAILED)->doesntExist() || $attempts(Rendition::STATUS_RUNNING)->exists();
    }

    /** The newest intact, browser-playable 1080p preview proxy rendered from $revision's own content, if any. */
    private function revisionPreviewProxy(AssetFile $revision): ?AssetFile
    {
        $sources = AssetFile::query()->select('id')->where('dam_asset_id', $this->getKey())->where('file_object_id', $revision->file_object_id);
        $renditions = $this->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_DONE)
            ->whereNotNull('output_file_object_id')
            ->whereIn('source_asset_file_id', $sources)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get(['output_file_object_id', 'output_asset_file_id']);

        foreach ($renditions as $rendition) {
            $file = AssetFile::query()
                ->with('fileObject')
                ->where('dam_asset_id', $this->getKey())
                ->where('role', AssetFile::ROLE_PROXY)
                ->where('file_object_id', $rendition->output_file_object_id)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $rendition->output_asset_file_id])
                ->orderByDesc('id')
                ->first();

            if ($file !== null && $this->assetFileExists($file) && $this->assetFilePlaybackType($file) === 'video') {
                return $file;
            }
        }

        return null;
    }

    /**
     * The browser-playback type of this asset's original file, ignoring
     * whether that file currently exists on disk. Used by
     * VideoPreviewProfile to decide whether a 1080p-or-smaller master still
     * needs a preview proxy because the browser cannot play it directly
     * (e.g. a ProRes .mov).
     *
     * $probedTechnical carries facts just read from the file itself (an
     * ffprobe of the source) and is merged OVER the stored technical
     * metadata, so a caller that has probed an asset with empty or stale
     * metadata is judged on what the file actually contains. Omitted, the
     * stored metadata alone decides, exactly as before.
     *
     * @param  array<string, mixed>  $probedTechnical
     */
    public function originalBrowserPlaybackType(array $probedTechnical = []): ?string
    {
        return $this->originalPlaybackFacts(probedTechnical: $probedTechnical)['type'];
    }

    /**
     * Facts about this asset's original attachment needed to decide browser
     * playback: the AssetFile record (if any), its extension and mime, and
     * the resulting browser playback type. Computed once here so
     * playbackFile() and originalBrowserPlaybackType() never drift.
     *
     * @param  array<string, mixed>  $probedTechnical  Facts probed from the file, merged last (see originalBrowserPlaybackType()).
     * @return array{original: ?AssetFile, extension: string, mime: string, type: ?string}
     */
    private function originalPlaybackFacts(?Collection $attachments = null, array $probedTechnical = []): array
    {
        $attachments ??= $this->playbackAttachments();
        $original = $attachments->firstWhere('role', AssetFile::ROLE_ORIGINAL);
        $originalTechnical = array_merge(
            is_array($this->meta_data) ? $this->meta_data : [],
            is_array($original?->fileObject?->technical) ? $original->fileObject->technical : [],
            $probedTechnical,
        );
        $originalMime = (string) ($this->mime_type ?: $original?->fileObject?->mime_type ?: 'application/octet-stream');
        // The extension column, then the MIME type; never a path, since the blob is suffix-less and shared.
        $originalExtension = strtolower((string) ($this->extension ?: $this->extensionForMime($originalMime)));
        $originalType = $this->browserPlaybackType($originalExtension, $originalMime, $originalTechnical);

        return [
            'original'  => $original,
            'extension' => $originalExtension,
            'mime'      => $originalMime,
            'type'      => $originalType,
        ];
    }

    /**
     * @return array{url: string, mime: string, label: string}|null
     */
    public function captionSource(): ?array
    {
        $caption = $this->playbackAttachments()->first(function (AssetFile $file): bool {
            if (
                ! in_array($file->role, [AssetFile::ROLE_SIDECAR, AssetFile::ROLE_SUBTITLE], true)
                || ! $file->fileObject
                || ! $this->assetFileExists($file)
            ) {
                return false;
            }

            $extension = strtolower($file->extension());
            $mime = strtolower(trim(explode(';', (string) $file->fileObject->mime_type)[0]));

            return $extension === 'vtt' || $mime === 'text/vtt';
        });

        if (! $caption) {
            return null;
        }

        return [
            'url' => route('admin.dam.file.asset-file', [
                'asset'     => $this->getKey(),
                'assetFile' => $caption->getKey(),
            ]),
            'mime'  => (string) ($caption->fileObject->mime_type ?: 'text/vtt'),
            'label' => $caption->label ?: 'Captions',
        ];
    }

    private function playbackAttachments(): Collection
    {
        if ($this->relationLoaded('files')) {
            return $this->files->loadMissing('fileObject')
                ->sortBy(fn (AssetFile $file): array => [$file->sort, $file->id])
                ->values();
        }

        return $this->files()
            ->with('fileObject')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    private function assetFilePlaybackType(AssetFile $file, array $probedTechnical = []): ?string
    {
        $object = $file->fileObject;

        if (! $object) {
            return null;
        }

        return $this->browserPlaybackType(
            strtolower($file->extension()),
            (string) $object->mime_type,
            [...(is_array($object->technical) ? $object->technical : []), ...$probedTechnical],
        );
    }

    /**
     * The browser-playback type of one stacked file's own bytes, judged on the facts probed from the file (merged over
     * the stored ones), as originalBrowserPlaybackType() does for the cover.
     *
     * @param  array<string, mixed>  $probedTechnical
     */
    public function fileBrowserPlaybackType(AssetFile $file, array $probedTechnical = []): ?string
    {
        $file->loadMissing('fileObject');

        return $this->assetFilePlaybackType($file, $probedTechnical);
    }

    /** Whether an attachment's blob is intact (present at its own locator with its recorded size). */
    private function assetFileExists(AssetFile $file): bool
    {
        $object = $file->fileObject;

        if ($object === null) {
            return false;
        }

        try {
            return app(ContentStore::class)->holdsBlob($object);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The preview route for this asset's Library path: a logical key, resolved to bytes on the server. */
    private function libraryPreviewUrl(): string
    {
        return route('admin.dam.file.preview', ['path' => urlencode((string) $this->path)]);
    }

    private function extensionForMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if ($mime === '' || $mime === 'application/octet-stream') {
            return '';
        }

        return MimeTypes::getDefault()->getExtensions($mime)[0] ?? '';
    }

    /**
     * The published 1080p H.264 preview proxy, for the other places that serve it (P6 D26): an embed plays it and
     * the "Web MP4 (1080p)" download hands it out. Null when there is none to use, by the rule playback follows.
     */
    public function publishedWebProxy(): ?AssetFile
    {
        return $this->publishedPreviewProxy();
    }

    /**
     * The published 1080p preview proxy playback prefers over an oversized
     * master (ADR 0003), or null when there isn't one to use.
     *
     * Panoramas are excluded outright: their own proxy precedence (stitched,
     * then generated 4K) is a correctness rule about what the 360 viewer can
     * display, not a delivery optimization, and must not be second-guessed
     * here.
     *
     * Forced regeneration can leave more than one DONE rendition on an asset.
     * Every DONE rendition's output is considered newest-first (finished_at,
     * then id, both descending); a stale newest output — its file missing or
     * no longer browser-playable — falls through to the next older DONE
     * rendition rather than sinking playback straight to the master.
     */
    private function publishedPreviewProxy(): ?AssetFile
    {
        if ($this->panoramaClassification()['is_panorama']) {
            return null;
        }

        $objectIds = $this->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_DONE)
            ->whereNotNull('output_file_object_id')
            // A proxy of content this entry no longer stands for (a promote or restore since) is not its preview.
            ->fromCurrentContent($this->id)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->pluck('output_file_object_id');

        if ($objectIds->isEmpty()) {
            return null;
        }

        $proxyAttachments = $this->playbackAttachments()->where('role', AssetFile::ROLE_PROXY);

        foreach ($objectIds as $objectId) {
            $file = $proxyAttachments->firstWhere('file_object_id', $objectId);

            if ($file !== null && $this->assetFileExists($file) && $this->assetFilePlaybackType($file) === 'video') {
                return $file;
            }
        }

        return null;
    }

    /**
     * The master's own URL, for consumers that need full resolution while a
     * proxy is playing — frame capture, and the player's explicit Original
     * quality option. Null when the master is not something a browser can
     * decode, in which case no full-resolution capture is possible at all.
     */
    private function originalPlaybackUrl(): ?string
    {
        $original = $this->playbackAttachments()->firstWhere('role', AssetFile::ROLE_ORIGINAL);

        if ($this->originalBrowserPlaybackType() === null || $original === null || ! $this->assetFileExists($original)) {
            return null;
        }

        return $this->libraryPreviewUrl();
    }

    private function assetFilePlaybackDescriptor(AssetFile $file, string $kind, bool $previewProxy = false): array
    {
        $object = $file->fileObject;
        $extension = strtolower($file->extension());

        return $this->playbackDescriptor(
            (int) $file->getKey(),
            (string) $object->disk,
            (string) $object->path,
            $extension,
            (string) ($object->mime_type ?: $this->mimeForExtension($extension)),
            $kind,
            $file->label ?: ucfirst((string) $file->role),
            $previewProxy,
        );
    }

    private function playbackDescriptor(
        ?int $assetFileId,
        string $disk,
        string $path,
        string $extension,
        string $mime,
        string $kind,
        string $label,
        bool $previewProxy = false,
    ): array {
        return [
            'asset_file_id' => $assetFileId,
            'disk'          => $disk,
            'path'          => $path,
            'extension'     => $extension,
            'mime'          => $mime,
            'kind'          => $kind,
            'label'         => $label,
            'preview_proxy' => $previewProxy,
        ];
    }

    private function panoramaKind(string $type): string
    {
        return $this->panoramaClassification()['is_panorama'] ? 'pano360-'.$type : $type;
    }

    private function browserPlaybackType(string $extension, string $mime, array $technical): ?string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        // DJI OSV is a proprietary container even when an importer reports a
        // generic video MIME; it must never be sent to the browser as MP4.
        if ($extension === 'osv') {
            return null;
        }

        if (
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)
            || in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'], true)
        ) {
            return 'image';
        }

        if (
            ! in_array($extension, ['mp4', 'm4v', 'webm', 'mov'], true)
            && ! in_array($mime, ['video/mp4', 'video/x-m4v', 'video/webm', 'video/quicktime'], true)
        ) {
            return null;
        }

        $codecs = $this->technicalCodecs($technical);

        if ($codecs === []) {
            return 'video';
        }

        foreach ($codecs as $codec) {
            foreach (['h264', 'h.264', 'avc', 'vp8', 'vp08', 'vp9', 'vp09', 'av1', 'av01'] as $supported) {
                if (str_contains($codec, $supported)) {
                    return 'video';
                }
            }
        }

        return null;
    }

    /** @return list<string> every codec the technical facts name (any key containing "codec"), lower-cased */
    private function technicalCodecs(array $technical): array
    {
        $codecs = [];
        array_walk_recursive($technical, function ($value, $key) use (&$codecs): void {
            if (is_scalar($value) && str_contains(strtolower((string) $key), 'codec')) {
                $codecs[] = strtolower((string) $value);
            }
        });

        return $codecs;
    }

    private function requiresPreviewProxy(string $extension, string $mime, array $technical): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return $extension === 'mov' || in_array($mime, ['video/quicktime', 'video/x-quicktime'], true) || $this->hasHevcCodec($technical);
    }

    private function hasHevcCodec(array $technical): bool
    {
        foreach ($this->technicalCodecs($technical) as $codec) {
            foreach (['hevc', 'h265', 'h.265', 'hvc1', 'hev1'] as $hevc) {
                if (str_contains($codec, $hevc)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether the stored facts of a stacked file in a container a browser opens (never a proprietary one, DJI OSV say) name an HEVC stream. */
    private function fileHasHevc(AssetFile $file): bool
    {
        $technical = $file->fileObject?->technical;

        return in_array(strtolower($file->extension()), ['mp4', 'm4v', 'mov', 'webm'], true) && is_array($technical) && $this->hasHevcCodec($technical);
    }

    private function mimeForExtension(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            'webm'        => 'video/webm',
            'm4v'         => 'video/x-m4v',
            default       => 'video/mp4',
        };
    }
}
