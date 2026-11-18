<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Exceptions\MergeActiveException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\ActingAdminPermission;

/**
 * The Trash page (P2 S4). It lists the trashed assets the viewer could see before they were deleted, with who
 * deleted each and when. Restore needs the right to change the asset; Delete permanently needs the
 * `dam.trash.delete` key (full admins hold every key) and the role's own delete scope. Everything else about a
 * trashed asset answers like a missing one, so an asset the viewer could not see is a 404 here too.
 */
class TrashController extends Controller
{
    use ActingAdminPermission;

    private const PER_PAGE = 50;

    public function __construct(
        protected AssetTrashService $trash,
        protected AssetAccessDecision $access,
        protected RoleBuilder $roleBuilder,
    ) {}

    public function index()
    {
        return view('dam::trash.index');
    }

    public function list(Request $request): JsonResponse
    {
        $query = Asset::onlyTrashed()->withDisplayName()->with('deleter:id,name');
        $this->access->scopeTrashListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        $page = $query->orderByDesc('dam_assets.deleted_at')->orderByDesc('dam_assets.id')
            ->paginate(perPage: self::PER_PAGE, page: $request->integer('page', 1));
        $canDeletePermanently = $this->actingAdminHolds('dam.trash.delete');
        $canRestore = $this->actingAdminHolds('dam.trash.restore');

        return response()->json([
            'assets' => $page->getCollection()->map(fn (Asset $asset) => [
                'id'          => $asset->id,
                'name'        => $asset->display_name,
                'file_name'   => $asset->file_name,
                'extension'   => strtolower((string) $asset->extension),
                'location'    => $this->location((string) $asset->trashed_from_path),
                'deleted_by'  => $asset->deleter?->name,
                'deleted_at'  => $asset->deleted_at?->toIso8601String(),
                'purge_after' => $asset->deleted_at?->copy()->addDays(Asset::TRASH_RETENTION_DAYS)->toIso8601String(),
                'can_delete'  => $canDeletePermanently && $this->roleBuilder->canDelete($asset),
                'can_restore' => $canRestore && $this->roleBuilder->mayChange($asset),
            ])->values(),
            'meta'   => [
                'total'          => $page->total(),
                'last_page'      => $page->lastPage(),
                'current_page'   => $page->currentPage(),
                'retention_days' => Asset::TRASH_RETENTION_DAYS,
            ],
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $asset = $this->access->forTrashed(AccessContext::library(AccessContext::PURPOSE_EDIT), $id)->allowedOrFail();
        $trashedName = (string) $asset->file_name;

        // A role limited to its own items restores only files it created.
        if ($refusal = $this->roleBuilder->refusal($asset, 'own-only-restore')) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        if ($this->trash->folderDeleteInProgress()) {
            return response()->json(['success' => false, 'message' => trans('dam::app.admin.dam.trash.folder-delete-running')], 409);
        }

        $restored = $this->trash->restore($asset);

        // Restore takes the next free name when another file took this one meanwhile; the message says so, with
        // both names, instead of renaming silently (UX eval 2026-10-10, PERM-12).
        $message = (string) $restored->file_name === $trashedName
            ? trans('dam::app.admin.dam.trash.restored')
            : trans('dam::app.admin.dam.trash.restored-renamed', ['old' => $trashedName, 'name' => (string) $restored->file_name]);

        return response()->json([
            'success'  => true,
            'message'  => $message,
            'id'       => $restored->id,
            'location' => $this->location((string) $restored->path),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $asset = $this->access->forTrashed(AccessContext::library(AccessContext::PURPOSE_EDIT), $id)->visibleOrFail();

        // The route already asks for the key; the role's own delete scope (own items only) still applies.
        abort_unless($this->actingAdminHolds('dam.trash.delete') && $this->roleBuilder->canDelete($asset), 403, trans('dam::app.admin.permissions.denied-delete-trash'));

        try {
            $this->trash->purge($asset);
        } catch (ModelNotFoundException) {
            // Restored (or deleted) since the check above: it answers as an id not in the trash does.
            abort(404);
        } catch (MergeActiveException) {
            // P3b-1: a destination still holding merged files waits for its merges to be finalized or split. The reason
            // is the body, as the handler gives a 409 abort only generic copy.
            return response()->json(['success' => false, 'message' => trans('dam::app.admin.dam.trash.merge-active')], 409);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.trash.deleted'),
        ]);
    }

    /** The folder a Library path names, as the Library shows it (no `assets/` prefix). */
    private function location(string $path): string
    {
        $folder = dirname($path);
        $prefix = Directory::ASSETS_DIRECTORY.'/';

        return str_starts_with($folder, $prefix) ? substr($folder, strlen($prefix)) : $folder;
    }
}
