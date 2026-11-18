<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Explorer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Jobs\CopyDirectory as CopyDirectoryJob;
use Mothership\DAM\Jobs\MassCopy as MassCopyJob;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\StackMemberState;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Traits\ActingAdminPermission;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\AssetAccessControl;

class CopyController extends Controller
{
    use ActingAdminPermission, ActionRequestTrait, AssetAccessControl;

    public function __construct(
        protected DirectoryPermissionService $permissionService
    ) {}

    public function copyAsset(Request $request): JsonResponse
    {
        abort_unless(
            $this->actingAdminHolds('dam.asset.upload'),
            403,
            trans('dam::app.admin.permissions.denied-copy-files')
        );

        $request->validate([
            // VisibleAsset, not `exists`: a hidden source fails exactly as a missing one does.
            'asset_id'            => ['required', 'integer', new VisibleAsset],
            'target_directory_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        $asset = Asset::findOrFail($request->integer('asset_id'));
        $targetId = $request->integer('target_directory_id');

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($targetId)) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        // Under `lists` a file has its own list, and its folder never widens it (ADR 0010), so the source
        // check is the asset's own accessibility rather than its folder's. Under `grants` the any-directory
        // rule still applies, unchanged.
        if (! $this->permissionService->bypass()) {
            $sourceAccessible = app(AccessModel::class)->lists()
                ? $this->permissionService->canAccessAsset($asset->id)
                : (($sourceDir = $asset->directories()->first()) && $this->permissionService->canAccess($sourceDir->id));

            if (! $sourceAccessible) {
                return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
            }
        }

        // A copy shares the source's current original FileObject (ADR 0009 slice 3): no bytes move,
        // so there is nothing to stage under Directory::withTreeLock and no makeDirectory call needed.

        // Codex review round 1, finding 2: read the destination and derive its path INSIDE the lock,
        // not before it — a destination rename that commits while this request waits for the lock
        // would otherwise leave the new asset linked to the right directory id but carrying the old
        // path prefix.
        // Codex review round 2, finding 1 (MassCopy ~:159 — same stale-source pattern): re-read the
        // source here too, instead of reusing the $asset fields captured before the lock — a delete
        // that commits while this request waits for the lock 404s the same way a request for an
        // already-gone asset would, rather than building a copy from stale fields and a since-reaped
        // blob locator.
        // Codex review round 3, finding 3: reading $sourceObject before the lock (as this used to)
        // left it stale the same way — an edit or re-upload committing while the request waited for
        // the lock could change the source's descriptors (filename/extension/mime/size/meta_data) via
        // its own Asset::lockedForGraph() write, but the pre-lock $sourceObject would still be
        // attached, producing a copy whose descriptors say "new format" while its bytes are the old
        // ones. Lock the source row inside the transaction (lock order: asset row before blob, ADR
        // 0009) and resolve its current FileObject from that same protected read, so descriptors and
        // bytes always come from one consistent snapshot; a concurrent writer's own lockedForGraph()
        // call blocks on this row until this transaction commits or rolls back.
        $refused = false;

        $newAsset = Directory::withTreeLock(function () use ($asset, $targetId, &$refused) {
            $source = Asset::query()->lockedForGraph()->findOrFail($asset->id);

            // Codex review of PR #75, finding 2: the check above ran against an unlocked snapshot, and
            // AccessListService::rewriteAsset() locks only this one file's row (never the tree lock), so
            // a revocation could have committed in the gap between that read and this lock being
            // granted. Re-checked now, from the row this transaction holds, which nothing else can
            // change until it ends. Grants parity: the any-directory rule races nothing, since every
            // folder-membership change already takes the tree lock this closure holds.
            //
            // Codex review round 2, finding 1: $this->permissionService is this request's one scoped
            // instance (DAMServiceProvider), the same object the pre-lock check above already called.
            // canAccessAsset() itself keeps no cache today, so nothing here is proven stale by a test —
            // unlike copyStructureTo()'s canAccess()/directlyGrantedIds(), which does. Kept for the same
            // reason that check is fresh: a reader gaining a cache later must not have to remember this
            // call site too.
            $this->permissionService->flush();

            if (app(AccessModel::class)->lists() && ! $this->permissionService->bypass() && ! $this->permissionService->canAccessAsset($source->id)) {
                $refused = true;

                return null;
            }

            // P2 S3 review: availability, from the locked row too. A source that stopped being out for the actor
            // while this request waited (a draft, a closed window) is not copied, as MassCopy's re-check leaves it.
            if (! app(AssetAccessDecision::class)->for(AccessContext::library(), $source)->visible) {
                $refused = true;

                return null;
            }

            $sourceObject = app(ContentStore::class)->current($source);

            $targetDirectory = Directory::findOrFail($targetId);
            $targetDirPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $targetDirectory->generatePath());

            $newName = LibraryPaths::uniqueFileName($targetDirPath, $source->file_name);

            $newAsset = new Asset([
                'file_name' => $newName,
                'file_type' => $source->file_type,
                'extension' => $source->extension,
                'file_size' => $source->file_size,
                'path'      => $targetDirPath.'/'.$newName,
                'mime_type' => $source->mime_type,
                'meta_data' => array_diff_key($source->meta_data ?? [], [StackMemberState::KEY => true]),
                // A copy is exactly as out for use as its source (P2 S3, as MassCopy and CopyDirectory): copying a
                // draft or a scheduled file never publishes it.
                ...Asset::availabilityOf($source),
            ]);
            $newAsset->withGraphContent($sourceObject)->save();

            $newAsset->directories()->attach($targetId);
            // The copy takes the destination folder's access list (ADR 0010, spec "Copy"). The tree lock
            // above already holds the fence's row.
            app(AccessListWriter::class)->inheritAssets([$newAsset->id]);

            return $newAsset;
        });

        if ($refused) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        return response()->json([
            'message' => trans('dam::app.admin.explorer.context.copy-done'),
            'asset'   => $newAsset,
        ], 201);
    }

    public function copyDirectory(Request $request): JsonResponse
    {
        // A folder copy creates folders and copies every file in them, so it needs the key each of those needs on
        // its own: dam.directory.store, and dam.asset.upload as copyAsset() does. The folder key alone let a role
        // that may not add files duplicate any number of them (UX eval 2026-10-10, G1-05 follow-up).
        abort_unless(
            $this->actingAdminHolds('dam.directory.store') && $this->actingAdminHolds('dam.asset.upload'),
            403,
            trans('dam::app.admin.permissions.denied-copy-folders')
        );

        $request->validate([
            'directory_id'        => ['required', 'integer', new VisibleDirectory],
            'target_directory_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        $sourceId = $request->integer('directory_id');
        $targetId = $request->integer('target_directory_id');

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($targetId)) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($sourceId)) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        $source = Directory::findOrFail($sourceId);

        $requestAction = $this->start(EventType::COPY_DIRECTORY->value);

        CopyDirectoryJob::dispatch($sourceId, $targetId, $requestAction->getUser()->id);

        return response()->json([
            'message' => trans('dam::app.admin.explorer.context.copy-progress'),
            'name'    => $source->name,
        ], 202);
    }

    public function copyStructureTo(Request $request): JsonResponse
    {
        // The classic Copy structure route is gated on dam.directory.copy_structure (acl.php); this one creates
        // folders too, so it needs both keys. dam.directory.store alone let a role without the copy-structure
        // capability copy structures from the Explorer (UX eval 2026-10-10, G1-05).
        abort_unless(
            bouncer()->hasPermission('dam.directory.copy_structure') && bouncer()->hasPermission('dam.directory.store'),
            403,
            trans('dam::app.admin.permissions.denied-copy-structure')
        );

        $request->validate([
            'source_id' => ['required', 'integer', new VisibleDirectory],
            'target_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($request->integer('target_id'))) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($request->integer('source_id'))) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        $sourceId = $request->integer('source_id');
        $targetId = $request->integer('target_id');

        // Same stale-source/stale-destination hazard fixed for MassCopy and copyAsset in c1535d6: this
        // used to run the self/descendant ancestry guard and the whole recursive directory copy with no
        // Directory::withTreeLock at all. Each Directory::create() inside deepCopyStructure only held the
        // lock for its own brief write, so a concurrent move could reparent the destination beneath the
        // source between the guard and the walk — isDescendantOf() would then compare the fresh
        // destination against the source's stale, pre-move interval, miss the prohibited relationship,
        // and deepCopyStructure would recurse into its own newly-created subtree with nothing to stop it.
        // A source deleted mid-walk produced a partial or empty copy. Reload both by id inside the lock,
        // run the guard against the reloaded models, and let the whole recursive copy commit or roll back
        // as one operation — withTreeLock is re-entrant per connection, so deepCopyStructure's own
        // Directory::create() calls nesting back into it below are free.
        $blocked = false;
        $refused = false;

        Directory::withTreeLock(function () use ($sourceId, $targetId, &$blocked, &$refused) {
            $source = Directory::findOrFail($sourceId);
            $target = Directory::findOrFail($targetId);

            if ($target->id === $source->id || $target->isDescendantOf($source)) {
                $blocked = true;

                return;
            }

            // Codex review of PR #75, finding 1: the two canAccess() checks above ran before this
            // request even acquired the tree lock, so either could be stale by now. Re-checked here,
            // fresh, before anything is created.
            //
            // Codex review round 2, finding 1: $this->permissionService is this request's one scoped
            // instance; its reader caches directlyGrantedIds() per admin, so without flush() the "fresh"
            // check below and deepCopyStructure()'s own per-child canAccess() would both replay the
            // cached, pre-lock answer instead of reading again.
            $this->permissionService->flush();

            if (app(AccessModel::class)->lists() && ! $this->permissionService->bypass()
                && (! $this->permissionService->canAccess($source->id) || ! $this->permissionService->canAccess($target->id))
            ) {
                $refused = true;

                return;
            }

            $newName = Directory::uniqueName($source->name, $target->id);

            $newRoot = Directory::create(['name' => $newName, 'parent_id' => $target->id]);
            $this->deepCopyStructure($source, $newRoot);
        });

        if ($blocked) {
            return response()->json([
                'message' => trans('dam::app.admin.dam.index.directory.cannot-copy'),
            ], 422);
        }

        if ($refused) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        return response()->json([
            'message' => trans('dam::app.admin.explorer.context.copy-done'),
        ], 202);
    }

    public function massCopy(Request $request): JsonResponse
    {
        $request->validate([
            'asset_ids'           => 'nullable|array',
            'asset_ids.*'         => 'integer',
            'directory_ids'       => 'nullable|array',
            'directory_ids.*'     => 'integer',
            'target_directory_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        if (! empty($request->input('asset_ids')) && ! bouncer()->hasPermission('dam.asset.upload')) {
            abort(403, trans('dam::app.admin.permissions.denied-copy-files'));
        }

        // Folders are copied with their files: both keys, as copyDirectory() requires.
        if (! empty($request->input('directory_ids')) && ! (bouncer()->hasPermission('dam.directory.store') && bouncer()->hasPermission('dam.asset.upload'))) {
            abort(403, trans('dam::app.admin.permissions.denied-copy-folders'));
        }

        $targetId = $request->integer('target_directory_id');

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($targetId)) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        $assetIds = array_filter(
            array_map('intval', $request->input('asset_ids', [])),
            function (int $id) {
                if (! $this->damCanAccessAsset($id)) {
                    return false;
                }

                $asset = Asset::find($id);

                // A copy shares the source's current original FileObject (ADR 0009 slice 3): the asset
                // needs a resolvable original, not a physical file at its Library path.
                if (! $asset || ! $asset->path) {
                    return false;
                }

                $hasOriginal = AssetFile::query()
                    ->where('dam_asset_id', $id)
                    ->where('role', AssetFile::ROLE_ORIGINAL)
                    ->exists();

                if (! $hasOriginal) {
                    return false;
                }

                // Under `lists` damCanAccessAsset() above already asked the right question — the file's
                // own list, not its folder's (ADR 0010) — so this folder check applies only under
                // `grants`, where the any-directory rule still governs.
                if (! $this->permissionService->bypass() && ! app(AccessModel::class)->lists()) {
                    $sourceDir = $asset->directories()->first();

                    if (! $sourceDir || ! $this->permissionService->canAccess($sourceDir->id)) {
                        return false;
                    }
                }

                return true;
            }
        );

        $dirIds = array_filter(
            array_map('intval', $request->input('directory_ids', [])),
            function (int $id) {
                if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($id)) {
                    return false;
                }

                $dir = Directory::find($id);

                return $dir && $dir->isCopyable();
            }
        );

        $requestAction = $this->start(EventType::MASS_COPY->value);

        MassCopyJob::dispatch(
            array_values($assetIds),
            array_values($dirIds),
            $targetId,
            $requestAction->getUser()->id
        );

        return response()->json(['queued' => true]);
    }

    private function deepCopyStructure(Directory $source, Directory $newParent): void
    {
        $source->loadMissing('children');

        foreach ($source->children as $child) {
            // Under `lists` a folder the actor cannot open is skipped together with its whole subtree,
            // path-only included (spec, "Copies"). Under `grants` there is no per-descendant check, as
            // today — gated explicitly, since canAccess() itself would still answer a real (grant) rule.
            if (app(AccessModel::class)->lists() && ! $this->permissionService->bypass() && ! $this->permissionService->canAccess($child->id)) {
                continue;
            }

            $newChild = Directory::create(['name' => $child->name, 'parent_id' => $newParent->id]);
            $this->deepCopyStructure($child, $newChild);
        }
    }
}
