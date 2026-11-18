<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\User\Models\Admin;
use RuntimeException;

/**
 * The one writer of Hub resources (ADR 0015): fonts and brushes people upload for the Creative Hub apps.
 *
 * - Bytes go to the content store (ADR 0009) as a FileObject; the same bytes held by an asset or another person are
 *   one blob. One owner holds a blob once: storing bytes they already have returns their existing resource.
 * - The per-person byte quota (`dam.hub.resources.per_user_max_bytes`; no file-count limit) is decided under a lock on
 *   the owner's admin row, so two uploads by one person cannot both take the last free bytes.
 * - Deleting a resource releases its blob the way removing an attachment does: when nothing else references the
 *   FileObject, the row goes and its locator is tombstoned for AssetDeletionService to unlink after the commit (and
 *   for `dam:storage:reap` to retry).
 * - Sharing (Everyone or groups) needs the owner's license attestation and a license note, both kept on the row.
 */
final class HubResourceStore
{
    private const LICENSE_NOTE_MAX = 2000;

    /** @var (Closure(FileObject, int): void)|null */
    private ?Closure $beforeAttach = null;

    public function __construct(
        private HubResourceInspector $inspector,
        private ContentStore $contentStore,
        private AssetDeletionService $deletion,
    ) {}

    /**
     * Inspects and stores the file at $path for $owner as a private resource. `created` is false when $owner already
     * held these bytes: their existing resource is returned unchanged.
     *
     * @return array{resource: HubResource, created: bool}
     *
     * @throws HubResourceRefused
     */
    public function store(string $path, string $originalName, Admin $owner): array
    {
        $inspected = $this->inspector->inspect(path: $path, originalName: $originalName);
        $sha256 = @hash_file('sha256', $path);

        if ($sha256 === false) {
            throw new RuntimeException('The uploaded resource is unreadable.');
        }

        $ownerId = (int) $owner->getKey();

        if (($existing = $this->ownedWithSha(ownerId: $ownerId, sha256: $sha256)) !== null) {
            CreativeHubLog::write(event: 'resource_replayed', ctx: ['mode' => $existing->kind, 'ext' => $existing->format]);

            return ['resource' => $existing, 'created' => false];
        }

        // Checked before any byte is written, and again under the owner lock below.
        $this->assertQuota(ownerId: $ownerId, size: $inspected['size']);

        $object = $this->contentStore->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $path,
            sha256: $sha256,
            mimeType: $inspected['mime_type'],
            extension: $inspected['format'],
        );

        // The ids the bytes are known by: the ingested row, and the row retain() returned if it had to recreate it.
        $objectIds = [(int) $object->getKey()];

        try {
            if ($this->beforeAttach !== null && app()->runningUnitTests()) {
                ($this->beforeAttach)($object, $ownerId);
            }

            $stored = DB::transaction(function () use ($object, $ownerId, $sha256, $inspected, &$objectIds): array {
                if (DB::table('admins')->where('id', $ownerId)->lockForUpdate()->first() === null) {
                    throw new RuntimeException('The owner of this upload no longer exists.');
                }

                $live = $this->contentStore->retain($object);
                $objectIds[] = (int) $live->getKey();

                if (($existing = $this->ownedWithSha(ownerId: $ownerId, sha256: $sha256)) !== null) {
                    return ['resource' => $existing, 'created' => false];
                }

                $this->assertQuota(ownerId: $ownerId, size: $inspected['size']);

                return ['resource' => HubResource::query()->create([
                    'owner_id'        => $ownerId,
                    'kind'            => $inspected['kind'],
                    'format'          => $inspected['format'],
                    'name'            => $inspected['name'],
                    'family'          => $inspected['family'],
                    'subfamily'       => $inspected['subfamily'],
                    'weight'          => $inspected['weight'],
                    'style'           => $inspected['style'],
                    'postscript_name' => $inspected['postscript_name'],
                    'file_object_id'  => (int) $live->getKey(),
                    'size'            => $inspected['size'],
                    'meta'            => $inspected['meta'],
                    'visibility'      => HubResource::VISIBILITY_PRIVATE,
                ]), 'created' => true];
            });
        } catch (\Throwable $failure) {
            // A concurrent upload of the same bytes by the same owner won the unique (owner_id, file_object_id): its
            // row holds the blob, so there is nothing to release.
            $existing = $failure instanceof UniqueConstraintViolationException ? $this->ownedWithSha(ownerId: $ownerId, sha256: $sha256) : null;
            if ($existing !== null) {
                $stored = ['resource' => $existing, 'created' => false];
            } else {
                // The bytes were written for a resource that will not exist (refused, owner gone, attach failed):
                // release them unless something else holds them, then report the original failure.
                $this->releaseAfterFailedAttach(objectIds: $objectIds, failure: $failure);

                throw $failure;
            }
        }

        CreativeHubLog::write(
            event: $stored['created'] ? 'resource_stored' : 'resource_replayed',
            ctx: ['mode' => $stored['resource']->kind, 'ext' => $stored['resource']->format, 'size' => (int) $stored['resource']->size],
        );

        return $stored;
    }

    /**
     * Sets who may read $resource. Only its owner shares it; a full admin may also make it private (a takedown).
     * Sharing with Everyone or with groups needs $licenseAttested and a non-empty $licenseNote, recorded with the
     * attesting admin and time. Making it private keeps the last attestation as a record.
     *
     * @param  list<int>  $groupIds  required, and only read, for VISIBILITY_GROUPS
     *
     * @throws HubResourceRefused
     */
    public function setVisibility(HubResource $resource, Admin $actor, string $visibility, array $groupIds = [], ?string $licenseNote = null, bool $licenseAttested = false): HubResource
    {
        if (! in_array($visibility, HubResource::VISIBILITIES, true)) {
            throw new HubResourceRefused(reason: HubResourceRefused::BAD_VISIBILITY, message: 'Visibility must be private, everyone or groups.');
        }

        $updated = DB::transaction(function () use ($resource, $actor, $visibility, $groupIds, $licenseNote, $licenseAttested): HubResource {
            $locked = HubResource::query()->whereKey($resource->getKey())->lockForUpdate()->firstOrFail();
            $isOwner = (int) $locked->owner_id === (int) $actor->getKey() && (bool) $actor->status;
            $takedown = $visibility === HubResource::VISIBILITY_PRIVATE && HubResource::readsEverything($actor);

            if (! $isOwner && ! $takedown) {
                throw new HubResourceRefused(reason: HubResourceRefused::NOT_OWNER, message: 'Only the owner can change who sees this resource.');
            }

            if ($visibility === HubResource::VISIBILITY_PRIVATE) {
                DB::table('dam_hub_resource_group_access')->where('resource_id', $locked->getKey())->delete();
                $locked->update(['visibility' => $visibility]);

                return $locked;
            }

            $note = trim((string) $licenseNote);
            if (! $licenseAttested || $note === '') {
                throw new HubResourceRefused(reason: HubResourceRefused::LICENSE_REQUIRED, message: 'Sharing needs a license note and your confirmation that the license allows sharing.');
            }

            $groups = [];
            if ($visibility === HubResource::VISIBILITY_GROUPS) {
                $requested = array_values(array_unique(array_map('intval', $groupIds)));
                $groups = $requested === [] ? [] : DB::table('dam_groups')->whereIn('id', $requested)->pluck('id')->map(fn ($id): int => (int) $id)->all();

                if ($groups === [] || count($groups) !== count($requested)) {
                    throw new HubResourceRefused(reason: HubResourceRefused::BAD_VISIBILITY, message: 'Choose at least one existing group to share with.');
                }
            }

            DB::table('dam_hub_resource_group_access')->where('resource_id', $locked->getKey())->delete();
            if ($groups !== []) {
                DB::table('dam_hub_resource_group_access')->insert(array_map(
                    fn (int $groupId): array => ['resource_id' => (int) $locked->getKey(), 'group_id' => $groupId],
                    $groups,
                ));
            }

            $locked->update([
                'visibility'   => $visibility,
                'license_note' => mb_substr($note, 0, self::LICENSE_NOTE_MAX),
                'attested_at'  => now(),
                'attested_by'  => (int) $actor->getKey(),
            ]);

            return $locked;
        });

        CreativeHubLog::write(event: 'resource_visibility', ctx: ['mode' => $updated->kind, 'status' => $visibility, 'count' => count($groupIds)]);

        return $updated;
    }

    /**
     * Deletes $resource for its owner, or for a full admin, and releases its blob when nothing else references it.
     *
     * @throws HubResourceRefused
     */
    public function delete(HubResource $resource, Admin $actor): void
    {
        $isOwner = (int) $resource->owner_id === (int) $actor->getKey() && (bool) $actor->status;

        if (! $isOwner && ! HubResource::readsEverything($actor)) {
            throw new HubResourceRefused(reason: HubResourceRefused::NOT_OWNER, message: 'Only the owner can delete this resource.');
        }

        $this->remove(resourceId: (int) $resource->getKey());
    }

    /**
     * Removes every resource $ownerId owns, releasing each blob as delete() does. For the Admin model's `deleting`
     * event (wired with the HTTP layer): without it the owner foreign key's cascade drops the rows but leaves their
     * FileObjects unreferenced, and on SQL Server (NO ACTION) the admin delete is refused. Returns how many went.
     */
    public function releaseOwnedBy(int $ownerId): int
    {
        $removed = 0;

        foreach (HubResource::query()->where('owner_id', $ownerId)->orderBy('id')->pluck('id') as $id) {
            $removed += $this->remove(resourceId: (int) $id) ? 1 : 0;
        }

        return $removed;
    }

    /** Whether the row was still there to remove. */
    private function remove(int $resourceId): bool
    {
        $result = DB::transaction(function () use ($resourceId): ?array {
            $locked = HubResource::query()->whereKey($resourceId)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            // Lock order as AttachmentLedger::remove(): the referencing row, then the FileObject row.
            $object = FileObject::query()->whereKey($locked->file_object_id)->lockForUpdate()->first();
            DB::table('dam_hub_resource_group_access')->where('resource_id', $locked->getKey())->delete();
            $locked->delete();

            return ['kind' => $locked->kind, 'ext' => $locked->format, 'tombstones' => $object === null ? [] : $this->releaseLocked(object: $object)];
        });

        if ($result === null) {
            return false;
        }

        $this->reap(tombstoneIds: $result['tombstones']);
        CreativeHubLog::write(event: 'resource_deleted', ctx: ['mode' => $result['kind'], 'ext' => $result['ext'], 'count' => count($result['tombstones'])]);

        return true;
    }

    /**
     * Test seam: runs after the bytes are ingested and just before the attach transaction (the owner lock and the
     * locked dedupe and quota checks), so a test can commit a competing row, remove the owner or fail the attach
     * between the preliminary checks and the locked ones. Setting it outside unit tests throws, and a hook already set
     * never runs outside them.
     *
     * @param  (Closure(FileObject, int): void)|null  $hook  receives the ingested object and the owner id
     */
    public function beforeAttach(?Closure $hook): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('HubResourceStore::beforeAttach() is a test seam and cannot be set outside unit tests.');
        }

        $this->beforeAttach = $hook;
    }

    /** @param  list<int>  $objectIds */
    private function releaseAfterFailedAttach(array $objectIds, \Throwable $failure): void
    {
        foreach (array_unique($objectIds) as $objectId) {
            try {
                $this->releaseIfUnreferenced(objectId: $objectId);
            } catch (\Throwable $e) {
                // The original failure is what the caller needs; this one is reported and its row stays for repair.
                report($e);
                CreativeHubLog::write(event: 'resource_release_failed', ctx: ['error_class' => class_basename($e), 'reason' => class_basename($failure)], level: 'error');
            }
        }
    }

    private function releaseIfUnreferenced(int $objectId): void
    {
        $tombstones = DB::transaction(function () use ($objectId): array {
            $object = FileObject::query()->whereKey($objectId)->lockForUpdate()->first();

            return $object === null ? [] : $this->releaseLocked(object: $object);
        });

        $this->reap(tombstoneIds: $tombstones);
    }

    /**
     * Under the caller's transaction and its lock on $object: deletes the row when nothing references it and
     * tombstones its locator (plus the suffix-less twin, as AttachmentLedger::remove() does).
     *
     * @return array<int, int> tombstone ids
     */
    private function releaseLocked(FileObject $object): array
    {
        if ($object->isReferenced()) {
            return [];
        }

        $object->delete();

        $locations = collect([
            ['disk' => (string) $object->disk, 'path' => (string) $object->path],
            ['disk' => (string) $object->disk, 'path' => $this->contentStore->objectPath((string) $object->sha256)],
        ])->unique(fn (array $location): string => $location['disk']."\0".$location['path'])->values()->all();

        return $this->deletion->queueFileDeletions($locations);
    }

    /**
     * Unlinks the tombstoned blobs once the outermost transaction commits (at once outside one). A failure stays on
     * its tombstone for `dam:storage:reap`; the delete itself has already committed.
     *
     * @param  array<int, int>  $tombstoneIds
     */
    private function reap(array $tombstoneIds): void
    {
        if ($tombstoneIds === []) {
            return;
        }

        DB::afterCommit(function () use ($tombstoneIds): void {
            try {
                $this->deletion->processPendingFileDeletions($tombstoneIds);
            } catch (\Throwable $e) {
                report($e);
                CreativeHubLog::write(event: 'resource_reap_deferred', ctx: ['count' => count($tombstoneIds), 'error_class' => class_basename($e)], level: 'error');
            }
        });
    }

    private function ownedWithSha(int $ownerId, string $sha256): ?HubResource
    {
        return HubResource::query()
            ->where('owner_id', $ownerId)
            ->whereIn('file_object_id', FileObject::query()->select('id')->where('sha256', strtolower($sha256)))
            ->first();
    }

    /** @throws HubResourceRefused */
    private function assertQuota(int $ownerId, int $size): void
    {
        $maxBytes = (int) config('dam.hub.resources.per_user_max_bytes');
        $bytes = (int) HubResource::query()->where('owner_id', $ownerId)->sum('size');

        if ($bytes + $size > $maxBytes) {
            throw new HubResourceRefused(
                reason: HubResourceRefused::QUOTA_BYTES,
                message: sprintf('This upload would take your fonts and brushes past %s GiB. Delete some to upload more.', round($maxBytes / 1073741824, 2)),
                context: ['bytes' => $bytes, 'size' => $size, 'limit' => $maxBytes],
            );
        }
    }
}
