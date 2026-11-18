<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Http\Requests\DirectoryRequest;
use Mothership\DAM\Jobs\DeleteDirectory as DeleteDirectoryJob;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;

class DirectoryController
{
    use ActionRequestTrait;

    public function __construct(
        protected DirectoryRepository $directoryRepository,
        protected DirectoryPermissionService $permissionService,
        protected DirectoryRolePermissionRepository $permissionRepository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $directories = $request->boolean('with_assets')
            ? $this->directoryRepository->getDirectoryTree()
            : $this->directoryRepository->getDirectoryTreeOnly();

        return new JsonResponse([
            'success' => true,
            'message' => trans('dam::app.admin.dam.index.directory.fetch-all-success'),
            'data'    => $directories,
        ]);
    }

    public function store(DirectoryRequest $request): JsonResponse
    {
        $parentDirectoryId = $request->input('parent_id', 1);

        if (! $this->permissionService->canAccess((int) $parentDirectoryId)) {
            return new JsonResponse([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.denied-create-folder'),
            ], 403);
        }

        try {
            $newDirectory = $this->directoryRepository->create([
                'name'      => $request->input('name'),
                'parent_id' => $parentDirectoryId,
            ]);

            $this->autoGrantToCreator($newDirectory->id);

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.index.directory.created-success'),
                'data'    => $newDirectory,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.creation-failed'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function autoGrantToCreator(int $directoryId): void
    {
        // Under `lists` the owner rule replaces this grant: the creator is the new folder's owner and
        // already opens it, and the folder has its parent's access list (ADR 0010). The legacy grant stays
        // under `grants`, which is still the record until the operator runs dam:access:switch lists.
        if (app(AccessModel::class)->lists()) {
            return;
        }

        $admin = auth()->guard('admin')->user() ?? auth()->guard('api')->user();

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

    public function getDirectory(int $id): JsonResponse
    {
        $directory = VisibleDirectory::find($id) ? $this->directoryRepository->getDirectoryTree($id) : null;

        if (! $directory) {
            return $this->directoryNotFound();
        }

        return response()->json([
            'success' => true,
            'data'    => $directory,
        ], 200);
    }

    public function update(DirectoryRequest $request, int $id): JsonResponse
    {
        $directory = VisibleDirectory::find($id);

        if (! $directory) {
            return $this->directoryNotFound();
        }

        if (! $this->permissionService->canAccess($id)) {
            return new JsonResponse([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.denied-rename-folder'),
            ], 403);
        }

        if ($refusal = app(RoleBuilder::class)->refusal($directory)) {
            return new JsonResponse(['success' => false, 'message' => $refusal], 403);
        }

        try {
            if ($directory->name !== $request->input('name')) {
                $directory = $this->directoryRepository->update([
                    'name' => $request->input('name'),
                ], $id);
                $this->start(EventType::RENAME_DIRECTORY->value);
                $this->completed(EventType::RENAME_DIRECTORY->value, $this->getUser()->id);
            }

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.index.directory.updated-success'),
                'data'    => $directory,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.update-failed'),
                'error'   => $e->getMessage(),
            ], 500);
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
                'success' => false,
                'message' => trans('dam::app.admin.permissions.denied-delete-folder'),
            ], 403);
        }

        if (! $directory->isDeletable()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.can-not-deleted'),
            ], 403);
        }

        // The same own-items gate as the Library's folder delete, including its folder-tree rule.
        if (! app(RoleBuilder::class)->canDeleteDirectoryTree($directory)) {
            return new JsonResponse([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.denied-delete-folder'),
            ], 403);
        }

        try {
            $parentDirectory = $directory->parent()->with(['children', 'assets'])->first();

            $requestAction = $this->start(EventType::DELETE_DIRECTORY->value);

            DeleteDirectoryJob::dispatch($id, $requestAction->getUser()->id);

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.index.directory.deleting-in-progress'),
                'data'    => $parentDirectory,
            ], 202);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * The one answer for a folder id that does not exist and for one the caller cannot see, so an id
     * probe cannot tell them apart. A folder they can see but not open still gets its own 403.
     */
    private function directoryNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.dam.index.directory.not-found'),
        ], 404);
    }
}
