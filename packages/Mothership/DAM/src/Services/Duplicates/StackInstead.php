<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Duplicates;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\DuplicateLog;
use Mothership\User\Models\Admin;

/**
 * P3b-2 (D16 exact) "Stack instead": an upload turned out to be bytes the Library already has, so instead of a second
 * entry the file joins the existing entry as an attachment, and the new entry goes to the Trash.
 *
 * Why after the fact: every upload path (one request, a folder, REST, and the queued chunk finalizer) has already
 * created the entry by the time its sha256 is known, so holding uploads back would change all four. Trash keeps it
 * reversible: Restore on the Trash page brings the entry back whole, and the attachment can be removed from the
 * Files tab (one revision, not the representative), so nothing is lost either way.
 *
 * Only a plain new upload can be folded: one file, no stack, no linked resources, and bytes the target really holds
 * as a current file. Both entries must be live, available to everyone and changeable by the actor, and the actor
 * must be allowed to delete the upload.
 *
 * Two passes over the same rules:
 *  - before the lock, on the rows as first read, each refusal says why (404 hidden or missing, 403, 422), so the
 *    common case gets a useful message;
 *  - inside the transaction, with both rows locked (in id order) and the rows, the actor and their role read again,
 *    every rule is asked once more of the current data. Anything that changed in between (access, availability,
 *    trash, the actor's keys, the upload's stack or files, the target's bytes) answers exactly like a missing entry
 *    and the transaction changes nothing.
 * EVENT_LOCKING fires between the two, so a test can change the data there.
 */
class StackInstead
{
    /** The REST key's scope for the stack-instead route, which the REST upload's notice checks before offering it. */
    public const API_SCOPE = 'api.dam.assets.stack_instead';

    /** The Library keys Stack instead needs: attach to one entry, change it, delete another. */
    public const LIBRARY_PERMISSIONS = ['dam.asset.files.store', 'dam.asset.edit', 'dam.asset.destroy'];

    /** The role the duplicate takes in the target unless the caller names one: neutral, editable in the Files tab. */
    public const DEFAULT_ROLE = AssetFile::ROLE_OTHER;

    /** Fired after the first checks and before the lock, with [uploadId, targetId]. */
    public const EVENT_LOCKING = 'dam.stack-instead.locking';

    public function __construct(
        private AttachmentLedger $ledger,
        private AssetTrashService $trash,
        private ContentStore $store,
        private RoleBuilder $roles,
        private AssetAccessDecision $access,
    ) {}

    /** Whether the session's role may attach to an entry and trash one, which is what Stack instead does. */
    public static function allowedInLibrary(): bool
    {
        foreach (self::LIBRARY_PERMISSIONS as $permission) {
            if (! bouncer()->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Folds entry `$uploadId` into entry `$targetId` for `$actor`, through surface `$via` (library or api).
     *
     * @return array{file: AssetFile, target: Asset, trashed_asset_id: int}
     */
    public function stack(int $uploadId, int $targetId, string $via, string $role, Admin $actor): array
    {
        abort_if($uploadId === $targetId, 422, trans('dam::app.admin.dam.duplicates.same-entry'));

        // First pass: the rows as first read, with an answer that says why.
        $edit = AccessContext::forAdmin($actor, AccessContext::PURPOSE_EDIT, $via);
        $upload = $this->access->for($edit, $uploadId)->allowedOrFail();
        $target = $this->access->for($edit, $targetId)->allowedOrFail();
        abort_unless($upload->unavailableBecause() === null && $target->unavailableBecause() === null, 404);
        abort_unless($this->roles->canUserDelete(model: $upload, admin: $actor), 403, trans('dam::app.admin.permissions.unauthorized'));
        abort_if($upload->resources()->exists(), 422, trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources'));
        abort_unless($this->plain($upload), 422, trans('dam::app.admin.dam.duplicates.not-plain'));
        abort_unless($this->holds(target: $target, object: $this->store->current($upload)), 422, trans('dam::app.admin.dam.duplicates.not-duplicate'));

        Event::dispatch(self::EVENT_LOCKING, [$uploadId, $targetId]);

        try {
            [$file, $target] = DB::transaction(function () use ($uploadId, $targetId, $via, $role, $actor): array {
                // Second pass: lock, read everything again, and ask every rule of the current data.
                $rows = Asset::withTrashed()->whereIn('id', [$uploadId, $targetId])->orderBy('id')->lockedForGraph()->get()->keyBy('id');
                $upload = $rows->get($uploadId);
                $target = $rows->get($targetId);
                $fresh = Admin::query()->with('role')->find($actor->id);
                $object = $upload ? $this->store->current($upload) : null;

                if ($upload === null || $target === null || $fresh === null || $object === null
                    || ! $this->stillAllowed(upload: $upload, target: $target, actor: $fresh, via: $via, object: $object)) {
                    DuplicateLog::write(event: 'stack_instead.changed', ctx: ['upload_id' => $uploadId, 'target_id' => $targetId]);
                    abort(404);
                }

                $file = $this->ledger->attachObject(asset: $target, object: $object, role: $role, label: (string) $upload->file_name);
                $this->trash->trash(asset: $upload, deletedBy: (int) $fresh->id);

                return [$file, $target];
            });
        } catch (AssetDeletionException) {
            abort(422, trans('dam::app.admin.dam.asset.delete-blocked'));
        }

        DuplicateLog::write(event: 'stack_instead.done', ctx: [
            'upload_id' => $uploadId,
            'target_id' => $targetId,
            'file_id'   => (int) $file->id,
        ]);

        return ['file' => $file, 'target' => $target, 'trashed_asset_id' => $uploadId];
    }

    /** Every rule of the first pass, asked again of locked, freshly read rows and a freshly read actor. */
    private function stillAllowed(Asset $upload, Asset $target, Admin $actor, string $via, FileObject $object): bool
    {
        if (! $this->actorStillMay(actor: $actor, via: $via)) {
            return false;
        }

        // A fresh context: its permission service reads the grants again rather than reuse the first pass's.
        $edit = AccessContext::forAdmin($actor, AccessContext::PURPOSE_EDIT, $via);

        foreach ([$upload, $target] as $asset) {
            if ($asset->trashed() || $asset->unavailableBecause() !== null || ! $this->access->for($edit, $asset)->allowed) {
                return false;
            }
        }

        return $this->roles->canUserDelete(model: $upload, admin: $actor)
            && ! $upload->resources()->exists()
            && $this->plain($upload)
            && $this->holds(target: $target, object: $object);
    }

    /** The actor is still enabled and still holds the keys of the surface: the role's in the Library, the API key's over REST. */
    private function actorStillMay(Admin $actor, string $via): bool
    {
        if (! (bool) $actor->status || $actor->role === null) {
            return false;
        }

        if ($via === AccessContext::VIA_API) {
            $key = $actor->apiKey;

            return $key !== null && ! $key->revoked && ($key->permission_type === 'all' || (bool) $key->hasPermission(self::API_SCOPE));
        }

        if ($actor->role->permission_type === 'all') {
            return true;
        }

        return (new Collection(self::LIBRARY_PERMISSIONS))->every(fn (string $permission) => (bool) $actor->hasPermission($permission));
    }

    /** One file besides derived previews, and no stack: nothing the Library would lose when the entry is trashed. */
    private function plain(Asset $upload): bool
    {
        return $upload->stack_basis === null
            && AssetFile::query()->where('dam_asset_id', $upload->id)
                ->whereNotIn('role', [AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL])->count() === 1;
    }

    /** Whether the target holds these bytes as a current file (any attachment, not history or a derived preview). */
    private function holds(Asset $target, ?FileObject $object): bool
    {
        return $object !== null && AssetFile::query()->where('dam_asset_id', $target->id)->where('is_current', true)
            ->whereNotIn('role', Asset::UNSTACKED_ROLES)
            ->whereHas('fileObject', fn ($query) => $query->where('sha256', $object->sha256))
            ->exists();
    }
}
