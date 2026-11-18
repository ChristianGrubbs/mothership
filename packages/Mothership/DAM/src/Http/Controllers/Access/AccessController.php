<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\MovePreviewService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * The Share dialog's endpoints (ADR 0010, sharing slice 3). Every action is 404 while the access model is
 * anything but `lists`, so under `grants` — the default — this controller adds no surface at all.
 *
 * None of these route names appears in the ACL route map (`Config/acl.php`), so Bouncer's
 * checkIfAuthorized() enforces no capability key and every signed-in admin with a role reaches them: that
 * is what the spec asks for, because every user shares their own items and the picker is open to all. The
 * authorization that matters — only the owner or a full admin may set a list, and only someone who can
 * open the item may read it — lives in AccessListService, which is also where the REST-free callers
 * (sharing slice 2) go through.
 */
class AccessController extends Controller
{
    /** How many ids one move-preview request may ask about at once. */
    public const MAX_MOVE_PREVIEW_IDS = 500;

    public function __construct(protected AccessListService $access, protected AccessModel $model) {}

    public function show(string $type, int $id): JsonResponse
    {
        $this->abortUnlessLists();

        return response()->json($this->access->describe(type: $type, id: $id, actor: $this->actor()));
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->abortUnlessLists();

        // Shape only; AccessListService checks that every id is an existing group or active user, so a
        // direct caller gets the same answer as this route.
        $data = $request->validate([
            'mode'       => ['required', 'string', 'in:'.implode(',', AccessListService::MODES)],
            'users'      => 'present|array|max:'.AccessListService::MAX_PRINCIPALS,
            'users.*'    => 'required|integer|min:1|distinct',
            'groups'     => 'present|array|max:'.AccessListService::MAX_PRINCIPALS,
            'groups.*'   => 'required|integer|min:1|distinct',
        ]);

        $result = $this->access->set(
            actor: $this->actor(),
            type: $type,
            id: $id,
            mode: $data['mode'],
            userIds: array_map('intval', $data['users']),
            groupIds: array_map('intval', $data['groups']),
        );

        return response()->json(['saved' => true] + $result + ['message' => trans('dam::app.admin.dam.access.saved')]);
    }

    /** Revokes the item's own active public links (never an ancestor folder's) and answers the new counts. */
    public function revokePublicLinks(string $type, int $id): JsonResponse
    {
        $this->abortUnlessLists();

        return response()->json(['public_links' => $this->access->revokePublicLinks(actor: $this->actor(), type: $type, id: $id)]);
    }

    public function principals(Request $request): JsonResponse
    {
        $this->abortUnlessLists();

        $request->validate(['q' => 'nullable|string|max:255']);

        return response()->json($this->access->principals(query: $request->input('q')));
    }

    /**
     * The move UIs' confirmation (sharing slice 2b): how many folders and files would end up with a
     * different access list if $ids (assets or a directory's whole subtrees) were moved to `target` now.
     * Not in the ACL route map (like the rest of this controller): the actor's own move capability is
     * checked here, the same way MassMoveController checks it.
     *
     * Codex review of PR #75, finding 5: order matters, so a missing object and a hidden one are always
     * indistinguishable from outside. Checked in this order:
     *   1. the capability, before any id is even looked at — 403 whatever the ids are, without it;
     *   2. shape and the cap, which name no specific id, so a bad request 422s without confirming
     *      anything about the ids it named;
     *   3. every id and the target, through resolveOrAbort()/resolveTargetOrAbort(), which query
     *      existence and accessibility together and 404 identically whichever one fails — never
     *      Laravel's exists: rule, whose 422 would otherwise tell a caller with no capability at all,
     *      or one who cannot see the id, that it exists.
     * 422, with the guard's own message, when the move itself would be refused — only possible for
     * `type=directory`, since a single asset move has no subtree to refuse over.
     */
    public function movePreview(Request $request): JsonResponse
    {
        $this->abortUnlessLists();

        // A static shape check — no lookup, so it leaks nothing — decides which capability to require.
        $type = $request->validate(['type' => ['required', 'string', 'in:asset,directory']])['type'];

        abort_unless(
            bouncer()->hasPermission($type === 'directory' ? 'dam.directory.moved' : 'dam.asset.moved'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:'.self::MAX_MOVE_PREVIEW_IDS],
            'ids.*'  => ['required', 'integer', 'min:1', 'distinct'],
            'target' => ['required', 'integer', 'min:1'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $targetId = (int) $data['target'];

        $permissions = (new DirectoryPermissionService)->forAdmin($this->actor());

        $this->resolveTargetOrAbort(targetId: $targetId, permissions: $permissions);

        foreach ($ids as $id) {
            $this->resolveOrAbort(type: $type, id: $id, permissions: $permissions);
        }

        // Codex review of PR #75, finding 8: the real move refuses a protected root, a self-target and a
        // move into one's own descendant before it ever asks the access guard — this preview must refuse
        // the same way, with the same message, or it can promise a count for a move that never happens.
        if ($type === 'directory') {
            $this->assertMovableOrAbort(ids: $ids, targetId: $targetId);
        }

        if ($type === 'directory' && app(AccessMoveGuard::class)->firstBlocked(admin: $this->actor(), directoryIds: $ids) !== null) {
            abort(422, trans('dam::app.admin.dam.access.move-blocked'));
        }

        $preview = app(MovePreviewService::class);

        $counts = $type === 'directory'
            ? $preview->countChangedSubtrees(directoryIds: $ids, targetId: $targetId)
            : $preview->countChangedAssets(assetIds: $ids, targetId: $targetId);

        return response()->json($counts);
    }

    /**
     * 404 whether $id does not exist or the actor cannot open it — existence and accessibility resolved
     * together, in one path, so neither ever reaches a caller as a different outcome from the other.
     */
    protected function resolveOrAbort(string $type, int $id, DirectoryPermissionService $permissions): void
    {
        $exists = $type === 'directory'
            ? DB::table('dam_directories')->where('id', $id)->exists()
            : DB::table('dam_assets')->where('id', $id)->whereNull('deleted_at')->exists();

        abort_unless($exists, 404);

        // A file asks the seam for the actor, availability included (P2 S3 review): a draft or out-of-window file
        // the actor may not manage answers like a missing one. The seam lets a bypass role past the folder rule.
        $accessible = $type === 'directory'
            ? ($permissions->bypass() || $permissions->canAccess($id))
            : app(AssetAccessDecision::class)->for(AccessContext::forAdmin(admin: $this->actor()), $id)->visible;

        abort_unless($accessible, 404);
    }

    /** resolveOrAbort(), specialised for the move target, which is always a directory. */
    protected function resolveTargetOrAbort(int $targetId, DirectoryPermissionService $permissions): void
    {
        $this->resolveOrAbort(type: 'directory', id: $targetId, permissions: $permissions);
    }

    /**
     * The same structural eligibility DirectoryController::moved() and MassMoveController::move() check
     * before dispatching a real move: a protected root refuses with 403, moving into itself or one's own
     * descendant with 422 — both with the real move's own "cannot-move" message, so the preview never
     * promises a count for a move the real endpoint would refuse outright.
     *
     * @param  list<int>  $ids
     */
    protected function assertMovableOrAbort(array $ids, int $targetId): void
    {
        $target = Directory::find($targetId);

        abort_if($target === null, 404);

        foreach ($ids as $id) {
            $directory = Directory::find($id);

            abort_if($directory === null, 404);

            abort_unless($directory->isDeletable(), 403, trans('dam::app.admin.dam.index.directory.cannot-move'));

            abort_if(
                $id === $targetId || $directory->isAncestorOf($target),
                422,
                trans('dam::app.admin.dam.index.directory.cannot-move')
            );
        }
    }

    /** Under `grants` the lists are not the record, so none of this exists. */
    protected function abortUnlessLists(): void
    {
        abort_unless($this->model->lists(), 404);
    }

    protected function actor(): Admin
    {
        $actor = auth('admin')->user();

        abort_if($actor === null, 401);

        return $actor;
    }
}
