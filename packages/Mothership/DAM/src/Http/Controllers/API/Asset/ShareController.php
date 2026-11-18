<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\ApiDataSource\ShareDataSource;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Services\Share\ShareSlugs;

class ShareController extends Controller
{
    public function __construct(
        protected ShareRepository $shareRepository,
        protected DirectoryPermissionService $permissionService,
    ) {}

    public function index(): JsonResponse
    {
        return app(ShareDataSource::class)->toJson();
    }

    public function show(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->hasTargetAccess($share)) {
            return $this->unauthorized();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->presentShare($share),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(ShareAccessOptions::rules() + ShareSlugs::rules() + [
            'share_type'   => 'required|in:'.Share::TYPE_ASSET.','.Share::TYPE_DIRECTORY,
            'asset_id'     => 'required_if:share_type,'.Share::TYPE_ASSET.'|integer|min:1',
            'directory_id' => 'required_if:share_type,'.Share::TYPE_DIRECTORY.'|integer|min:1',
            'expiry_days'  => 'nullable|integer|min:1|max:365',
            'no_expiry'    => 'nullable|boolean',
            'expires_at'   => 'nullable|date|after:now',
            'name'         => 'nullable|string|max:255',
        ]);

        $type = $request->input('share_type');
        $targetId = (int) ($type === Share::TYPE_ASSET
            ? $request->input('asset_id')
            : $request->input('directory_id'));

        // A target the caller cannot see answers exactly like one that does not exist.
        if ($type === Share::TYPE_ASSET) {
            $asset = Asset::find($targetId);

            if (! $asset || ! $this->canActOnAsset($asset)) {
                return $this->notFound(trans('dam::app.admin.dam.share.target-not-found'));
            }
        } else {
            $directory = VisibleDirectory::find($targetId);

            if (! $directory || ! $this->canAccessDirectory($targetId)) {
                return $this->notFound(trans('dam::app.admin.dam.share.target-not-found'));
            }
        }

        // 30 days unless the caller names an expiry or asks for none outright.
        $expiresAt = null;

        if ($request->filled('expires_at')) {
            $expiresAt = Carbon::parse($request->input('expires_at'))->setTimezone(config('app.timezone'));
        } elseif (! $request->boolean('no_expiry')) {
            $days = (int) ($request->input('expiry_days') ?? 30);
            $days = max(1, min(365, $days));
            $expiresAt = now()->addDays($days);
        }

        // No `public_link_confirmed` requirement here, unlike the web controller: API callers are explicit
        // bearer integrations (operator decision), and the operator's automation client creates links through this route.
        $userId = auth()->guard('api')->id() ?? auth()->id();

        $name = $request->input('name');
        $name = is_string($name) ? trim($name) : null;
        $name = $name === '' ? null : $name;

        // P2 S5b: password, download-off and creator access, each optional (ShareAccessOptions).
        $access = ShareAccessOptions::columns($request);

        // P4 D23: the optional custom slug is written with the link, so a refused slug creates no link at all.
        $share = DB::transaction(function () use ($type, $targetId, $expiresAt, $userId, $name, $access, $request) {
            $share = $type === Share::TYPE_ASSET
                ? $this->shareRepository->createForAsset($targetId, $expiresAt, $userId, $name, $access)
                : $this->shareRepository->createForDirectory($targetId, $expiresAt, $userId, $name, $access);

            ShareSlugs::write(share: $share, slug: $request->input('slug'));

            return $share;
        });

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.share.modal.created'),
            'data'    => $this->presentShare($share->fresh()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->canMutateShare($share)) {
            return $this->unauthorized();
        }

        $data = $request->validate(ShareAccessOptions::rules() + ShareSlugs::rules() + [
            'name'        => 'sometimes|nullable|string|max:255',
            'no_expiry'   => 'sometimes|boolean',
            'expiry_days' => 'sometimes|nullable|integer|min:1|max:365',
        ]);

        $payload = [];

        if ($request->has('name')) {
            $name = is_string($data['name'] ?? null) ? trim($data['name']) : null;
            $payload['name'] = $name === '' ? null : $name;
        }

        if ($request->boolean('no_expiry')) {
            $payload['expires_at'] = null;
        } elseif (! empty($data['expiry_days'])) {
            $payload['expires_at'] = now()->addDays((int) $data['expiry_days']);
        }

        // P4 D23: the slug reaches the link as its token does, so only those the token is shown to may set it.
        if ($request->has('slug') && ! $this->isCreatorOwnerOrAdmin($share)) {
            return $this->unauthorized();
        }

        // P2 S5b: the access options, under the same gate as every other change to the link (canMutateShare()).
        $payload += ShareAccessOptions::columns($request);

        DB::transaction(function () use ($share, $payload, $request) {
            if (! empty($payload)) {
                $share->fill($payload)->save();
            }

            if ($request->has('slug')) {
                ShareSlugs::write(share: $share, slug: $request->input('slug'));
            }
        });

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.share.updated'),
            'data'    => $this->presentShare($share->fresh()),
        ]);
    }

    public function revoke(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->canMutateShare($share)) {
            return $this->unauthorized();
        }

        $revoked = $this->shareRepository->revoke($id);

        return response()->json([
            'success' => $revoked,
            'message' => $revoked
                ? trans('dam::app.admin.dam.share.revoked')
                : trans('dam::app.admin.dam.share.already-revoked'),
        ]);
    }

    /**
     * Reissue (P2 S5a): a new URL for the same link, in place; the old one stops working at once. Gated exactly like
     * revoke, and only for an active link (an expired or revoked one is extended or re-enabled first). The new URL
     * comes back only to the link's creator, the target's owner or a full admin.
     */
    public function reissue(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->canMutateShare($share)) {
            return $this->unauthorized();
        }

        $reissued = $share->isActive() ? $this->shareRepository->reissue($share) : null;

        if (! $reissued) {
            $current = $share->fresh() ?? $share;

            return response()->json([
                'success' => false,
                'message' => $current->isRevoked()
                    ? trans('dam::app.admin.dam.share.reissue-revoked')
                    : trans('dam::app.admin.dam.share.reissue-expired'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.share.reissued'),
            'data'    => $this->presentShare($reissued),
        ]);
    }

    public function reauthorize(Request $request, int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->canMutateShare($share)) {
            return $this->unauthorized();
        }

        $request->validate([
            'expires_at'  => 'nullable|date|after:now',
            'expiry_days' => 'nullable|integer|min:1|max:365',
            'no_expiry'   => 'nullable|boolean',
        ]);

        // One write: a fresh expiry (30 days unless one is named, none only when asked for) with the un-revoke.
        $expiresAt = null;

        if ($request->filled('expires_at')) {
            $expiresAt = Carbon::parse($request->input('expires_at'))->setTimezone(config('app.timezone'));
        } elseif (! $request->boolean('no_expiry')) {
            $expiresAt = now()->addDays(max(1, min(365, (int) ($request->input('expiry_days') ?? 30))));
        }

        $reauthorized = $this->shareRepository->reauthorize($id, renewExpiry: true, expiresAt: $expiresAt);

        return response()->json([
            'success' => $reauthorized,
            'message' => $reauthorized
                ? trans('dam::app.admin.dam.share.reauthorized')
                : trans('dam::app.admin.dam.share.not-revoked'),
            'data'    => $reauthorized ? $this->presentShare($share->fresh()) : null,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return $this->notFound(trans('dam::app.admin.dam.share.not-found'));
        }

        if (! $this->canMutateShare($share)) {
            return $this->unauthorized();
        }

        $deleted = $this->shareRepository->hardDelete($id);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted
                ? trans('dam::app.admin.dam.share.deleted')
                : trans('dam::app.admin.dam.share.delete-failed'),
        ]);
    }

    /**
     * P4 D23: whether a custom slug is free (the web dialog's check, over REST). The answer says only free,
     * malformed, reserved or in use, never by what; `share_id` counts only for whom that link's token is shown.
     */
    public function slugCheck(Request $request): JsonResponse
    {
        $request->validate(['slug' => 'nullable|string|max:255', 'share_id' => 'nullable|integer|min:1']);

        $share = $request->filled('share_id') ? $this->visibleShare((int) $request->input('share_id')) : null;
        $shareId = $share !== null && $this->isCreatorOwnerOrAdmin($share) ? (int) $share->id : null;

        return response()->json([
            'success' => true,
            'data'    => ShareSlugs::check(slug: $request->input('slug'), shareId: $shareId),
        ]);
    }

    protected function presentShare(Share $share): array
    {
        // The capability URL goes only to the link's creator, the target's owner or a full admin.
        $token = $this->isCreatorOwnerOrAdmin($share) ? $share->urlToken() : null;

        return [
            'id'             => $share->id,
            'token'          => $token,
            'name'           => $share->name,
            'share_type'     => $share->share_type,
            'target_id'      => $share->target_id,
            'public_url'     => $token !== null ? route('dam.share.show', ['token' => $token]) : null,
            // P4 D23: the custom slug, its URL and the URL to copy, beside the token and only where it is shown.
            ...ShareSlugs::present(share: $share, token: $token),
            'expires_at'     => $share->expires_at?->toIso8601String(),
            'revoked_at'     => $share->revoked_at?->toIso8601String(),
            'view_count'     => $share->view_count,
            'download_count' => $share->download_count,
            'status'         => $share->statusLabel(),
            'created_at'     => $share->created_at?->toIso8601String(),
        ] + ShareAccessOptions::present($share);
    }

    protected function canActOnAsset(Asset $asset): bool
    {
        // The one asset access rule (ADR 0010), the same the admin ShareController uses: the any-directory
        // rule under grants and the file's own list under lists. Applies in both models — this is the one
        // deliberate grants-side change in sharing slice 5, replacing the first-folder-only read that let
        // canActOnAsset() refuse a page open() would have allowed through a second directory.
        return app(AssetAccessDecision::class)->visible(AccessContext::api(AccessContext::PURPOSE_SHARE), $asset->id);
    }

    protected function canAccessDirectory(int $directoryId): bool
    {
        if ($this->permissionService->bypass()) {
            return true;
        }

        return $this->permissionService->canAccess($directoryId);
    }

    /**
     * The link, when it exists and its target is one the actor may see (P2 S3 re-review). A link to a hidden,
     * draft or out-of-window file, or to a folder the actor cannot open, answers exactly like a link that does not
     * exist, whoever made it: checked before any ownership or permission rule, so a 403 is only ever about a
     * target the actor can see. That includes a link's creator who has since lost sight of its target.
     */
    protected function visibleShare(int $id): ?Share
    {
        $share = $this->shareRepository->find($id);

        return $share !== null && $this->hasTargetAccess($share) ? $share : null;
    }

    protected function hasTargetAccess(Share $share): bool
    {
        // No folder-bypass shortcut here: an asset link asks the seam, which already lets a bypass role past the
        // folder rule but still applies availability (P2 S3 review); canAccessDirectory() keeps its own bypass.
        if ($share->share_type === Share::TYPE_ASSET) {
            $asset = $share->asset;

            return $asset !== null && $this->canActOnAsset($asset);
        }

        // P4 D2: a collection's link is seen and changed only by whoever manages the collection (its owner or a full
        // admin); to anyone else it answers like a link that does not exist. Never the folder rule below.
        if ($share->share_type === Share::TYPE_COLLECTION) {
            $collection = $share->collection;
            $grant = app(CollectionGrant::class);

            return $collection !== null && $grant->manages(collection: $collection, viewer: $grant->viewer(AccessContext::collection(collectionId: (int) $collection->id)));
        }

        return $this->canAccessDirectory($share->target_id);
    }

    /**
     * Revoking, reauthorizing, updating or deleting a link. Under lists, mere target access is not enough —
     * a custom-role user can be listed on someone else's asset without being its creator or owner, and
     * hasTargetAccess() alone would let that user mutate a link they did not create and do not own (Codex
     * review of PR #76, finding 2), mirroring Asset\ShareController::canManage(). Grants applies the same rule:
     * target access alone let any key holder change every link to a file it could see (UX eval 2026-10-10,
     * R14). The API key's own scope (api.dam.shares.*) is checked by the route middleware, and every caller has
     * already passed visibleShare().
     */
    protected function canMutateShare(Share $share): bool
    {
        return $this->isCreatorOwnerOrAdmin($share);
    }

    /** The API actor: the link's creator, the target's owner, or a full admin (Share::tokenVisibleTo). */
    protected function isCreatorOwnerOrAdmin(Share $share): bool
    {
        $adminId = auth()->guard('api')->id() ?? auth()->id();

        return $share->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: $this->permissionService->fullAdmin());
    }

    protected function notFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 404);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.permissions.unauthorized'),
        ], 403);
    }
}
