<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mothership\Admin\Http\Requests\MassDestroyRequest;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Exceptions\DirectoryNameReservedException;
use Mothership\DAM\Http\Controllers\Concerns\StreamsZipDownload;
use Mothership\DAM\Http\Requests\DirectoryRequest;
use Mothership\DAM\Http\Requests\DirectorySearchRequest;
use Mothership\DAM\Jobs\CopyDirectoryStructure as CopyDirectoryStructureJob;
use Mothership\DAM\Jobs\DeleteDirectory as DeleteDirectoryJob;
use Mothership\DAM\Jobs\MoveDirectoryStructure as MoveDirectoryStructureJob;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\FolderUploadPaths;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;

class DirectoryController
{
    use ActionRequestTrait;
    use StreamsZipDownload;

    public function __construct(
        protected DirectoryRepository $directoryRepository,
        protected DirectoryPermissionService $permissionService,
        protected DirectoryRolePermissionRepository $permissionRepository,
        protected RoleBuilder $roleBuilder,
        protected AccessMoveGuard $moveGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $directories = $request->boolean('with_assets')
            ? $this->directoryRepository->getDirectoryTree()
            : $this->directoryRepository->getDirectoryTreeOnly();

        return new JsonResponse([
            'data' => $directories,
        ]);
    }

    public function search(DirectorySearchRequest $request): JsonResponse
    {
        $q = $request->validated('q');
        $limit = 20;
        $offset = (int) ($request->validated('offset') ?? 0);

        $results = $this->directoryRepository->search($q, $limit, $offset);

        $returned = $results->count();
        $total = ($returned < $limit)
            ? $offset + $returned
            : $this->directoryRepository->searchCount($q);

        return new JsonResponse([
            'data' => $results->map(fn ($directory) => [
                'id'         => $directory->id,
                'name'       => $directory->name,
                'parent_id'  => $directory->parent_id,
                'path_names' => $directory->path_names,
                'path_ids'   => $directory->path_ids,
            ])->values(),
            'meta' => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    public function childrenDirectory(int $id, Request $request): JsonResponse
    {
        if (! VisibleDirectory::find($id)) {
            return $this->directoryNotFound();
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = (int) $request->query('limit', DirectoryRepository::DEFAULT_TREE_PAGE_SIZE);

        $page = $this->directoryRepository->getShallowChildren($id, null, $offset, $limit);

        return new JsonResponse([
            'data'     => $page['data']->values(),
            'has_more' => $page['has_more'],
        ]);
    }

    public function assetCounts(Request $request): JsonResponse
    {
        $counts = $this->directoryRepository->getVisibleSubtreeAssetCounts(
            ids: (array) $request->input('ids', []),
            service: $this->permissionService,
        );

        return new JsonResponse(['data' => (object) $counts]);
    }

    public function directoryPath(int $id): JsonResponse
    {
        if (! VisibleDirectory::find($id)) {
            return $this->directoryNotFound();
        }

        $path = $this->directoryRepository->getAncestorPath($id);

        return new JsonResponse([
            'data' => $path->values(),
        ]);
    }

    public function directoryAssets(int $id): JsonResponse
    {
        if (! config('dam.tree.show_assets')) {
            return new JsonResponse([
                'data' => [],
            ]);
        }

        if (! $this->permissionService->canAccess($id)) {
            return new JsonResponse([
                'data' => [],
            ]);
        }

        $directory = $this->directoryRepository->getDirectoryTree($id);

        if (! $directory) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.not-found'),
            ], 404);
        }

        $assets = $directory->assets;

        return new JsonResponse([
            'data' => $assets,
        ]);
    }

    public function store(DirectoryRequest $request)
    {
        $parentDirectoryId = $request->input('parent_id', 1);

        if (! $this->permissionService->canAccess((int) $parentDirectoryId)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.denied-create-folder'),
            ], 403);
        }

        try {
            $newDirectory = $this->directoryRepository->create([
                'name'      => $request->input('name'),
                'parent_id' => $parentDirectoryId,
            ]);

            $this->autoGrantToCreator($newDirectory->id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.created-success'),
                'data'    => $newDirectory,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'directory.store', 'creation-failed');
        }
    }

    /**
     * The exception goes to the log; the browser gets a generic translated message. A raw message can carry
     * SQL, bindings and database paths.
     */
    private function failed(\Throwable $e, string $operation, string $messageKey): JsonResponse
    {
        // The one exception whose message is written for people (a translated refusal), not a driver's.
        if ($e instanceof DirectoryNameReservedException) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        Log::error('dam.directory.failed', [
            'operation' => $operation,
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'loc'       => $e->getFile().':'.$e->getLine(),
        ]);

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.index.directory.'.$messageKey),
        ], 500);
    }

    private function autoGrantToCreator(int $directoryId): void
    {
        // Under `lists` the owner rule replaces this grant: the creator is the new folder's owner and
        // already opens it, and the folder has its parent's access list (ADR 0010). The legacy grant stays
        // under `grants`, which is still the record until the operator runs dam:access:switch lists.
        if (app(AccessModel::class)->lists()) {
            return;
        }

        $admin = auth()->guard('admin')->user();

        if (! $admin) {
            return;
        }

        $role = $admin->role;

        if (! $role || $role->permission_type !== 'custom') {
            return;
        }

        if (DB::table('dam_role_settings')->where('role_id', $role->id)->where('all_directories', true)->exists()) {
            return;
        }

        if (app(FolderAccessService::class)->grantToCreator(adminId: (int) $admin->id, directoryId: $directoryId)) {
            return;
        }

        $this->permissionRepository->addDirectoryToRole($role->id, $directoryId);
        $this->permissionService->flush();
    }

    public function update(DirectoryRequest $request): JsonResponse
    {
        $id = $request->input('id');
        $directory = VisibleDirectory::find($id);

        if (! $directory) {
            return $this->directoryNotFound();
        }

        if (! $this->permissionService->canAccess($directory->id)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.denied-rename-folder'),
            ], 403);
        }

        // A role limited to its own items renames only folders it created, and never the Library root.
        if ($refusal = $this->roleBuilder->refusal($directory)) {
            return new JsonResponse(['message' => $refusal], 403);
        }

        try {
            if ($directory->name !== $request->input('name')) {
                $directory = $this->directoryRepository->update([
                    'name' => $request->input('name'),
                ], $id);

                $this->start(EventType::RENAME_DIRECTORY->value);
                $this->completed(EventType::RENAME_DIRECTORY->value, $this->getUser()->id);
            }

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.updated-success'),
                'data'    => $directory,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'directory.update', 'update-failed');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $directory = VisibleDirectory::find($id);

        if (! $directory) {
            return $this->directoryNotFound();
        }

        if (! $this->permissionService->canAccess($id)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.denied-delete-folder'),
            ], 403);
        }

        if (! $directory->isDeletable()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.can-not-deleted'),
            ], 403);
        }

        if (! $this->roleBuilder->canDeleteDirectoryTree($directory)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.denied-delete-folder'),
            ], 403);
        }

        if ($this->moveGuard->isBlocked(admin: $this->getUser(), directoryId: $id)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.access.move-blocked'),
            ], 422);
        }

        try {
            // The tree redraws the parent from this: only the subfolders and files the viewer's tree lists, never a
            // hidden folder or a hidden, draft or out-of-window file (P2 S3 review).
            $parentDirectory = $directory->parent()->with([
                'children' => fn ($q) => $q->when(! $this->permissionService->bypass(), fn ($granted) => $granted->whereIn('id', $this->permissionService->viewableIds())),
                'assets'   => function ($q) {
                    $q->visibleCaptures();
                    app(AssetAccessDecision::class)->scopeListable($q, AccessContext::library(AccessContext::PURPOSE_LIST));
                },
            ])->first();

            $requestAction = $this->start(EventType::DELETE_DIRECTORY->value);

            DeleteDirectoryJob::dispatch($id, $requestAction->getUser()->id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.deleting-in-progress'),
                'data'    => $parentDirectory,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'directory.destroy', 'operation-failed');
        }
    }

    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.directory.destroy'),
            403,
            trans('dam::app.admin.permissions.denied-delete-folder')
        );

        $ids = $massDestroyRequest->input('indices');

        // Under `lists` the guard refuses the whole request rather than delete part of it: the existing
        // per-id checks below still skip silently, but a candidate whose subtree holds anything the actor
        // cannot see stops every candidate, not just its own.
        $candidates = [];
        $skippedByOwnRule = 0;

        foreach ($ids as $id) {
            if (! $this->permissionService->canAccess($id)) {
                continue;
            }

            $directory = $this->directoryRepository->find($id);

            if (! $directory || ! $directory->isDeletable()) {
                continue;
            }

            if (! $this->roleBuilder->canDeleteDirectoryTree($directory)) {
                $skippedByOwnRule++;

                continue;
            }

            $candidates[] = $id;
        }

        if ($candidates === [] && $skippedByOwnRule > 0) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.own-only-mass-none-folders', ['skipped' => $skippedByOwnRule]),
                'skipped' => $skippedByOwnRule,
            ], 403);
        }

        if ($this->moveGuard->firstBlocked(admin: $this->getUser(), directoryIds: $candidates) !== null) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.access.move-blocked'),
            ], 422);
        }

        $requestAction = $this->start(EventType::DELETE_DIRECTORY->value);

        foreach ($candidates as $id) {
            DeleteDirectoryJob::dispatch($id, $requestAction->getUser()->id);
        }

        return new JsonResponse([
            'message' => $skippedByOwnRule > 0
                ? trans('dam::app.admin.permissions.own-only-mass-delete-folders', ['deleted' => count($candidates), 'skipped' => $skippedByOwnRule])
                : trans('dam::app.admin.dam.index.directory.deleting-in-progress'),
            'skipped' => $skippedByOwnRule,
        ]);
    }

    public function copy(Request $request): JsonResponse
    {

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.index.directory.copy-success'),
            'data'    => null,
        ]);
    }

    public function copyStructure(Request $request): JsonResponse
    {
        $request->validate(
            ['id' => 'required|integer'],
        );

        $copyId = $request->input('id', 1);
        $directory = VisibleDirectory::find($copyId);

        if (! $directory) {
            return $this->directoryNotFound();
        }

        if (! $this->permissionService->canAccess((int) $copyId)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.denied-copy-structure'),
            ], 403);
        }

        if (! $directory->isCopyable()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.can-not-copy'),
            ], 403);
        }

        $requestAction = $this->start(EventType::COPY_DIRECTORY_STRUCTURE->value);

        try {
            CopyDirectoryStructureJob::dispatch($copyId, $requestAction->getUser()->id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.coping-in-progress'),
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'directory.copy', 'operation-failed');
        }
    }

    public function moved(Request $request): JsonResponse
    {
        $request->validate([
            'move_item_id'  => ['required', 'integer', new VisibleDirectory],
            'new_parent_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        $moveId = (int) $request->input('move_item_id');
        $newParentId = (int) $request->input('new_parent_id');
        $directory = $this->directoryRepository->find($moveId);
        $newParent = $this->directoryRepository->find($newParentId);

        if (! $this->permissionService->canAccess($moveId)
            || ! $this->permissionService->canAccess($newParentId)
        ) {
            // The generic key reads "You are not allowed to manage DAM directory permissions.",
            // which sends a user whose move was refused to the role editor instead of telling them
            // which two folders the check was about.
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.move-unauthorized'),
            ], 403);
        }

        if (! $directory->isDeletable()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.cannot-move'),
            ], 403);
        }

        if ($refusal = $this->roleBuilder->refusal($directory)) {
            return new JsonResponse(['message' => $refusal], 403);
        }

        // Items this person cannot see come first and are never counted for them: the plain sentence has no numbers, so it
        // cannot tell a person how much is hidden from them.
        if ($this->roleBuilder->restrictedToOwn() && $this->moveGuard->isBlocked(admin: $this->roleBuilder->actingAdmin(), directoryId: $moveId)) {
            return new JsonResponse(['message' => trans('dam::app.admin.permissions.own-only-subtree-hidden')], 403);
        }

        // Moving a folder moves everything in it and gives all of it the new parent's access list, so a role limited to
        // its own items may move only a folder that holds nothing but its own items (the job asks again under its locks).
        // Everything counted here is visible to them: the hidden case was answered above.
        $foreign = $this->moveGuard->foreignSubtreeCounts(admin: $this->roleBuilder->actingAdmin(), directoryId: $moveId);

        if ($foreign['folders'] + $foreign['files'] > 0) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.own-only-subtree', ['count' => $foreign['folders'] + $foreign['files']]),
                'foreign' => $foreign['folders'] + $foreign['files'],
            ], 403);
        }

        if ($moveId === $newParentId || $directory->isAncestorOf($newParent)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.cannot-move-inside'),
            ], 422);
        }

        if ($this->moveGuard->isBlocked(admin: $this->getUser(), directoryId: $moveId)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.access.move-blocked'),
            ], 422);
        }

        try {
            $requestAction = $this->start(EventType::MOVE_DIRECTORY_STRUCTURE->value);

            MoveDirectoryStructureJob::dispatch($moveId, $newParentId, $requestAction->getUser()->id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.moved-success'),
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'directory.move', 'operation-failed');
        }
    }

    public function downloadArchive(Request $request, int $id)
    {
        // A folder the viewer cannot see answers exactly like one that does not exist.
        $directory = VisibleDirectory::find($id) ?? abort(404);

        if (! $this->permissionService->canAccess($id)) {
            abort(403, trans('dam::app.admin.permissions.denied-download-folder'));
        }

        $folderBase = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
        $disk = Directory::getAssetDisk();

        $subtreeQuery = Asset::query()
            ->whereHas('directories', fn ($q) => $q->whereBetween('_lft', [$directory->_lft, $directory->_rgt]));

        // Under `lists` the zip holds only files the actor can see (spec, "Copies"); public share zips
        // (D6) are untouched, since they never reach this admin-only endpoint. Gated explicitly on the
        // access model (Codex review of PR #75, finding 3): applyAssetDirectoryGrantFilter() would
        // otherwise apply LegacyGrantReader's own any-directory rule under `grants` too, which is a new
        // filter this slice must not add there — before this slice, downloadArchive() trusted the single
        // canAccess() check on the folder itself and zipped its whole subtree unconditionally, so a
        // parent granted without inherit_children still downloaded every descendant's files.
        // Availability (P2 S3) applies under both models: a draft, archived or out-of-window file a member
        // cannot open never rides along in a folder zip.
        $context = AccessContext::library(AccessContext::PURPOSE_DOWNLOAD);

        if (app(AccessModel::class)->lists()) {
            app(AssetAccessDecision::class)->scopeListable($subtreeQuery, $context);
        } else {
            app(AssetAccessDecision::class)->scopeOutForUse($subtreeQuery, $context);
        }

        if (! $subtreeQuery->exists()) {
            // The Library asks first (X-Zip-Preflight) so an empty folder is a toast, not a page reload.
            if ($request->expectsJson()) {
                return new JsonResponse(['message' => trans('dam::app.admin.dam.index.directory.empty-directory')], 422);
            }

            return back()->with('error', trans('dam::app.admin.dam.index.directory.empty-directory'));
        }

        if ($request->headers->has('X-Zip-Preflight')) {
            return response()->noContent();
        }

        $zipName = sprintf('%s.zip', $directory->name);

        return $this->buildZipStreamFromAssets(
            $subtreeQuery->select(['path', 'file_name']),
            $folderBase,
            $disk,
            $zipName,
        );
    }

    /**
     * The one answer for a folder id that does not exist and for one the viewer cannot see, so an id
     * probe cannot tell them apart. A folder they can see but not open still gets its own 403.
     */
    private function directoryNotFound(): JsonResponse
    {
        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.index.directory.not-found'),
        ], 404);
    }

    public function ancestorPaths(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'present|array',
            'ids.*' => 'integer|min:1',
        ]);

        $ids = $request->input('ids');

        if (empty($ids)) {
            return new JsonResponse(['data' => []]);
        }

        $nodes = $this->directoryRepository->getAncestorPathsForIds($ids);

        $nodes = $nodes->filter(fn ($node) => $this->permissionService->canView($node->id));

        return new JsonResponse(['data' => $nodes->values()]);
    }

    public function descendants(int $id): JsonResponse
    {
        $node = $this->directoryRepository->find($id);

        if (! $node || ! $this->permissionService->canView($node->id)) {
            return new JsonResponse(['data' => []]);
        }

        $ids = $this->directoryRepository->getDescendantIds($id);

        $ids = array_values(array_filter($ids, fn ($did) => $this->permissionService->canView($did)));

        return new JsonResponse(['data' => $ids]);
    }

    public function createStructure(Request $request): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.directory.store'),
            403,
            trans('dam::app.admin.permissions.denied-create-structure')
        );

        $request->validate([
            'directory_id' => ['required', new VisibleDirectory],
            'paths'        => 'required|array|min:1',
            'paths.*'      => 'string|max:500',
        ]);

        $directoryId = (int) $request->input('directory_id');
        $paths = $request->input('paths');

        if (! $this->permissionService->canAccess($directoryId)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.denied-create-structure'),
            ], 403);
        }

        // FU: every name is sanitized, never refused, by the same rule the chunk upload resolves each file's folder
        // with (FolderUploadPaths); only too deep or too many is refused, before any folder is made.
        try {
            $folders = FolderUploadPaths::distinctFolders(paths: array_map('strval', $paths));
        } catch (\LengthException $limit) {
            return response()->json([
                'success' => false,
                'code'    => $limit->getMessage(),
                'message' => trans('dam::app.admin.folder-upload.'.str_replace('_', '-', $limit->getMessage()), [
                    'depth' => FolderUploadPaths::maxDepth(), 'count' => FolderUploadPaths::maxCount(),
                ]),
            ], 422);
        }

        $ids = [];
        $createdIds = [];

        // Parents first, so each folder's parent is already resolved. A folder that already exists is reused only
        // when the uploader can open it; new ones are made by the directory repository, so each takes its parent's
        // access list (ADR 0010, spec D3).
        foreach ($folders as $segments) {
            $name = end($segments);
            $parentKey = implode("\0", array_slice($segments, 0, -1));
            $parentId = $parentKey === '' ? $directoryId : $ids[$parentKey];
            $existing = Directory::where('parent_id', $parentId)->where('name', $name)->first();

            if ($existing && ! $this->permissionService->canAccess((int) $existing->id)) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.permissions.denied-create-structure'),
                ], 403);
            }

            if ($existing) {
                $ids[implode("\0", $segments)] = (int) $existing->id;

                continue;
            }

            $newId = (int) $this->directoryRepository->create(['name' => $name, 'parent_id' => $parentId])->id;
            $ids[implode("\0", $segments)] = $newId;
            $createdIds[] = $newId;
        }

        foreach ($createdIds as $id) {
            $this->autoGrantToCreator($id);
        }

        return response()->json([
            'success'               => true,
            'granted_directory_ids' => $createdIds,
        ]);
    }
}
