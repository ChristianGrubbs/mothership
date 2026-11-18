<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\DataGrids\Share\ShareDataGrid;
use Mothership\DAM\Http\Requests\StoreShareRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Services\Share\ShareSlugs;
use Mothership\DAM\Services\WebCollectionService;
use Mothership\DAM\Services\WebPageTelemetry;

class ShareController extends Controller
{
    public function __construct(
        protected ShareRepository $shareRepository,
        protected DirectoryPermissionService $permissionService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(ShareDataGrid::class)->toJson();
        }

        return view('dam::share.admin.index');
    }

    public function store(StoreShareRequest $request, WebCollectionService $collections): JsonResponse
    {
        $type = $request->input('share_type');
        $targetId = (int) $request->input('target_id');

        // Under lists, every signed-in user keeps the share-link feature (D7): only access to the target
        // decides. Under grants, parity keeps the ACL check exactly as before.
        $lists = app(AccessModel::class)->lists();

        // A target the actor cannot see answers exactly like one that does not exist.
        if ($type === Share::TYPE_ASSET) {
            $asset = Asset::find($targetId);
            if (! $asset || ! $this->canActOnAsset($asset)) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.share.target-not-found'),
                ], 404);
            }

            if (! $lists && ! bouncer()->hasPermission('dam.asset.share')) {
                return $this->unauthorized();
            }
        } else {
            $directory = VisibleDirectory::find($targetId);
            if (! $directory || ! $this->canAccessDirectory($targetId)) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.share.target-not-found'),
                ], 404);
            }

            if (! $lists && ! bouncer()->hasPermission('dam.directory.share')) {
                return $this->unauthorized();
            }
        }

        $expiresAt = $this->resolveExpiry($request);

        $userId = auth()->guard('admin')->id() ?? auth()->id();
        $name = $request->input('name');
        $name = is_string($name) ? trim($name) : null;
        $name = $name === '' ? null : $name;

        // P2 S5b: password, download-off and creator access, each optional (ShareAccessOptions).
        $access = ShareAccessOptions::columns($request);

        // Share pages in folder: the same ordinary link, with the page's folder and revision as the context the
        // confirmation was made in. Checked below, at creation, never taken on trust.
        $folderId = $request->filled('web_collection_id') ? (int) $request->input('web_collection_id') : null;

        // P4 D23: the optional custom slug is written with the link, so a refused slug creates no link at all.
        $outcome = DB::transaction(function () use ($type, $targetId, $expiresAt, $userId, $name, $access, $request, $folderId, $collections) {
            if ($folderId !== null) {
                // Locked, so two admins sharing the same folder at once meet one page at a time and the second reuses.
                $page = Asset::query()->whereKey($targetId)->lockForUpdate()->first();

                if (! $page || ! $collections->holdsFrozenDirectPage(page: $page, collectionId: $folderId, fingerprint: (string) $request->input('page_revision'))) {
                    return null;
                }

                if ($existing = ($this->reusableShares(collect([$page]))[$targetId] ?? null)) {
                    return ['share' => $existing, 'reused' => true];
                }
            }

            $share = $type === Share::TYPE_ASSET
                ? $this->shareRepository->createForAsset($targetId, $expiresAt, $userId, $name, $access)
                : $this->shareRepository->createForDirectory($targetId, $expiresAt, $userId, $name, $access);

            ShareSlugs::write(share: $share, slug: $request->input('slug'));

            return ['share' => $share, 'reused' => false];
        });

        if ($outcome === null) {
            WebPageTelemetry::record('web_pages.folder_share_rejected', ['collection_id' => $folderId, 'asset_id' => $targetId, 'reason' => 'page-changed']);

            return response()->json([
                'success' => false,
                'reason'  => 'page-changed',
                'message' => trans('dam::app.admin.dam.web-pages.browser.share-folder-changed'),
            ], 409);
        }

        if ($folderId !== null) {
            WebPageTelemetry::record('web_pages.folder_share_created', ['collection_id' => $folderId, 'asset_id' => $targetId, 'state' => $outcome['reused'] ? 'reused' : 'created']);
        }

        return response()->json([
            'success' => true,
            'share'   => $this->presentShare($outcome['share']->fresh()),
        ] + ($folderId !== null ? ['reused' => $outcome['reused']] : []));
    }

    /**
     * Share pages in folder, step one and observational: the direct pages of a Web Pages folder this actor could give
     * an ordinary link right now, for the confirmation. Same capability as creating a link; a page the actor cannot
     * see is neither listed nor counted. It creates nothing and reveals no URL, only whether a usable link already
     * exists (it would be kept, not duplicated).
     */
    public function webCollectionCandidates(int $id, WebCollectionService $collections): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);

        if (! app(AccessModel::class)->lists() && ! bouncer()->hasPermission('dam.asset.share')) {
            return $this->unauthorized();
        }

        $collection = WebCollection::query()->find($id);

        if (! $collection) {
            return $this->hiddenTarget();
        }

        $found = $collections->shareCandidates($collection);
        $reusable = $this->reusableShares($found['pages']);

        return response()->json([
            'success'    => true,
            'collection' => (int) $collection->id,
            'cap'        => $collections->shareCandidateCap(),
            'total'      => $found['total'],
            'omitted'    => $found['omitted'],
            'pages'      => $found['pages']->map(fn (Asset $page) => [
                'id'           => (int) $page->id,
                'display_name' => $page->display_name,
                'revision'     => $collections->pageFingerprint($page),
                // The link that would be kept, as the share dialog already shows it to this viewer: its own expiry and
                // options, never its URL, token or password.
                'reuse'        => isset($reusable[(int) $page->id]) ? [
                    'expires_at' => $reusable[(int) $page->id]->expires_at?->toIso8601String(),
                ] + ShareAccessOptions::present($reusable[(int) $page->id]) : null,
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The newest ACTIVE link per asset that opens today and whose URL this actor may use (its creator, the page owner
     * or a full admin): the one a folder share keeps instead of making another. A link the actor cannot use, or a
     * creator-access link its creator can no longer see through, is never reused or revealed.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<int, Share> asset id => share
     */
    protected function reusableShares(Collection $assets): array
    {
        if ($assets->isEmpty()) {
            return [];
        }

        $byId = $assets->keyBy(fn (Asset $asset) => (int) $asset->id);
        $found = [];

        Share::query()->active()->where('share_type', Share::TYPE_ASSET)->whereIn('target_id', $byId->keys()->all())->orderByDesc('id')->get()
            ->each(function (Share $share) use (&$found, $byId) {
                $assetId = (int) $share->target_id;

                if (isset($found[$assetId])) {
                    return;
                }

                $share->setRelation('asset', $byId[$assetId]);

                // Usable means the link opens today: its URL is the actor's to see, and a creator-access link whose creator
                // can no longer see the page is closed by the public gate, so keeping it would hand out a dead link.
                if ($this->isCreatorOwnerOrAdmin($share) && (! $share->requires_creator_access || app(ShareGate::class)->creatorCanSee($share))) {
                    $found[$assetId] = $share;
                }
            });

        return $found;
    }

    public function update(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.not-found'),
            ], 404);
        }

        if (! $this->canRevoke($share)) {
            return $this->unauthorized();
        }

        $data = request()->validate(ShareAccessOptions::rules() + ShareSlugs::rules() + [
            'name'                  => 'nullable|string|max:255',
            'no_expiry'             => 'sometimes|boolean',
            'expiry_days'           => 'sometimes|nullable|integer|min:1|max:365',
            'public_link_confirmed' => 'sometimes|boolean',
        ]);

        // Extending a link that no longer opens, or making one permanent, re-opens it to anyone with the URL:
        // the same opt-in as creating one.
        $changesExpiry = request()->boolean('no_expiry') || isset($data['expiry_days']);

        if ($changesExpiry && (! $share->isActive() || request()->boolean('no_expiry')) && ! request()->boolean('public_link_confirmed')) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.public-link-confirmation-required'),
                'errors'  => ['public_link_confirmed' => [trans('dam::app.admin.dam.share.public-link-confirmation-required')]],
            ], 422);
        }

        $payload = [
            'name' => $data['name'] ?? null,
        ];

        if (request()->boolean('no_expiry')) {
            $payload['expires_at'] = null;
        } elseif (isset($data['expiry_days'])) {
            $payload['expires_at'] = now()->addDays((int) $data['expiry_days']);
        }

        // P4 D23: the slug reaches the link as its token does, so only those the token is shown to may set it.
        if (request()->has('slug') && ! $this->isCreatorOwnerOrAdmin($share)) {
            return $this->unauthorized();
        }

        // P2 S5b: setting, changing or clearing the password, download-off and creator access need exactly the
        // authority editing the link does (canRevoke() above); an option the request leaves out stays as it is.
        DB::transaction(function () use ($share, $payload) {
            $share->fill($payload + ShareAccessOptions::columns(request()))->save();

            if (request()->has('slug')) {
                ShareSlugs::write(share: $share, slug: request()->input('slug'));
            }
        });

        return response()->json([
            'success' => true,
            'share'   => $this->presentShare($share->fresh()),
            'message' => trans('dam::app.admin.dam.share.updated'),
        ]);
    }

    public function reauthorize(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.not-found'),
            ], 404);
        }

        if (! $this->canRevoke($share)) {
            return $this->unauthorized();
        }

        request()->validate([
            'public_link_confirmed' => 'accepted',
            'expires_at'            => 'nullable|date|after:now',
            'expiry_days'           => 'nullable|integer|min:1|max:365',
            'no_expiry'             => 'nullable|boolean',
        ], [
            'public_link_confirmed.accepted' => trans('dam::app.admin.dam.share.public-link-confirmation-required'),
        ]);

        // Always one write: re-enabling sets a fresh expiry (30 days unless one is named, or none when asked
        // for outright) together with clearing the revocation, never the old expiry.
        $reauthorized = $this->shareRepository->reauthorize($id, renewExpiry: true, expiresAt: $this->resolveExpiry(request()));

        return response()->json([
            'success' => $reauthorized,
            'share'   => $reauthorized ? $this->presentShare($share->fresh()) : null,
            'message' => $reauthorized
                ? trans('dam::app.admin.dam.share.reauthorized')
                : trans('dam::app.admin.dam.share.not-revoked'),
        ]);
    }

    /**
     * Reissue (P2 S5a): a new URL for the same link; the old one stops working at once. Gated exactly like revoke
     * (a hidden target answers like a missing link, then canRevoke()), and only for an active link: an expired or
     * revoked one is extended or re-enabled first. The new URL goes back only to whom the #126 rule shows it.
     */
    public function reissue(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.not-found'),
            ], 404);
        }

        if (! $this->canRevoke($share)) {
            return $this->unauthorized();
        }

        $reissued = $share->isActive() ? $this->shareRepository->reissue($share) : null;

        if (! $reissued) {
            return response()->json([
                'success' => false,
                'message' => $this->notReissuableMessage($share->fresh() ?? $share),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'share'   => $this->presentShare($reissued),
            'message' => trans('dam::app.admin.dam.share.reissued'),
        ]);
    }

    public function revoke(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.not-found'),
            ], 404);
        }

        if (! $this->canRevoke($share)) {
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

    public function destroy(int $id): JsonResponse
    {
        $share = $this->visibleShare($id);

        if (! $share) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.share.not-found'),
            ], 404);
        }

        if (! $this->canDelete($share)) {
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

    public function activeForTarget(string $type, int $targetId): JsonResponse
    {
        if (! in_array($type, [Share::TYPE_ASSET, Share::TYPE_DIRECTORY], true)) {
            return response()->json(['success' => false], 422);
        }

        if ($type === Share::TYPE_ASSET) {
            $asset = Asset::find($targetId);
            if (! $asset || ! $this->canActOnAsset($asset)) {
                return $this->hiddenTarget();
            }
        } else {
            if (! Directory::find($targetId) || ! $this->canAccessDirectory($targetId)) {
                return $this->hiddenTarget();
            }
        }

        $shares = Share::query()
            ->where('share_type', $type)
            ->where('target_id', $targetId)
            ->orderByDesc('created_at')
            ->limit(1)
            ->get()
            ->map(fn (Share $s) => $this->presentShare($s));

        return response()->json([
            'success' => true,
            'shares'  => $shares,
        ]);
    }

    /**
     * P4 D23: whether a custom slug is free, for the dialog's live hint. Anyone who may create a link may ask; the
     * answer says only free, malformed, reserved or in use, never by what. `share_id` names the link being edited
     * (its own slugs stay free to it) and counts only for whom that link's token is shown.
     */
    public function slugCheck(Request $request): JsonResponse
    {
        $request->validate(['slug' => 'nullable|string|max:255', 'share_id' => 'nullable|integer|min:1']);

        if (! app(AccessModel::class)->lists() && ! bouncer()->hasPermission('dam.asset.share') && ! bouncer()->hasPermission('dam.directory.share')) {
            return $this->unauthorized();
        }

        $share = $request->filled('share_id') ? $this->visibleShare((int) $request->input('share_id')) : null;
        $shareId = $share !== null && $this->isCreatorOwnerOrAdmin($share) ? (int) $share->id : null;

        return response()->json(ShareSlugs::check(slug: $request->input('slug'), shareId: $shareId));
    }

    /**
     * The 30-day default whenever the request names no expiry; no expiry only when it is asked for outright.
     */
    protected function resolveExpiry(Request $request): ?Carbon
    {
        // In the app's zone: the datetime cast stores wall-clock time without converting, so a client's UTC offset
        // would otherwise be dropped and the link would close hours early or late (UX eval 2026-10-10, PERM-12).
        if ($request->filled('expires_at')) {
            return Carbon::parse($request->input('expires_at'))->setTimezone(config('app.timezone'));
        }

        if ($request->boolean('no_expiry')) {
            return null;
        }

        if ($request->filled('expiry_days')) {
            return now()->addDays(max(1, min(365, (int) $request->input('expiry_days'))));
        }

        return now()->addDays(30);
    }

    /**
     * The capability URL goes only to whom the #126 rule shows it (the link's creator, the target's owner or a
     * full admin). Anyone else who may see that a link exists, or may update, re-enable or reissue it, gets its
     * existence, status and expiry without the URL itself (P2 S5a review).
     */
    protected function presentShare(Share $share): array
    {
        $token = $this->isCreatorOwnerOrAdmin($share) ? $share->urlToken() : null;

        return [
            'id'              => $share->id,
            'token'           => $token,
            'name'            => $share->name,
            'share_type'      => $share->share_type,
            'target_id'       => $share->target_id,
            'public_url'      => $token !== null ? route('dam.share.show', ['token' => $token]) : null,
            // P4 D23: the custom slug, its URL and the URL to copy, beside the token and only where it is shown.
            ...ShareSlugs::present(share: $share, token: $token),
            'created_by'      => $share->created_by,
            'expires_at'      => $share->expires_at?->toIso8601String(),
            'revoked_at'      => $share->revoked_at?->toIso8601String(),
            'view_count'      => $share->view_count,
            'download_count'  => $share->download_count,
            'status'          => $share->statusLabel(),
            'created_at'      => $share->created_at?->toIso8601String(),
            'update_url'      => route('admin.dam.shared-links.update', $share->id),
            'reauthorize_url' => route('admin.dam.shared-links.reauthorize', $share->id),
            'reissue_url'     => route('admin.dam.shared-links.reissue', $share->id),
            // Whether this viewer may change, re-enable, reissue or revoke the link, and delete it: the dialog offers
            // only what the server would allow (UX eval 2026-10-10, R14).
            'can_manage'      => $this->canRevoke($share),
            'can_delete'      => $this->canDelete($share),
        ] + ShareAccessOptions::present($share);
    }

    /** Why a link cannot be reissued: it is revoked, or it has expired. */
    protected function notReissuableMessage(Share $share): string
    {
        return $share->isRevoked()
            ? trans('dam::app.admin.dam.share.reissue-revoked')
            : trans('dam::app.admin.dam.share.reissue-expired');
    }

    protected function canActOnAsset(Asset $asset): bool
    {
        // Any-directory rule shared with open/list (audit A1/A7): the first pivot row alone refused
        // to share a page the admin could open through its second directory.
        return app(AssetAccessDecision::class)->visible(AccessContext::library(AccessContext::PURPOSE_SHARE), $asset->id);
    }

    protected function canAccessDirectory(int $directoryId): bool
    {
        if ($this->permissionService->bypass()) {
            return true;
        }

        return $this->permissionService->canAccess($directoryId);
    }

    /**
     * Editing, re-enabling, reissuing or revoking a link. Callers reach this only through visibleShare(), so the
     * target is already one the actor may see.
     */
    protected function canRevoke(Share $share): bool
    {
        return $this->canManage(share: $share, aclKey: 'dam.shares.revoke');
    }

    protected function canDelete(Share $share): bool
    {
        return $this->canManage(share: $share, aclKey: 'dam.shares.delete');
    }

    /**
     * Changing a link belongs to its creator, the target's owner or a full admin, in both access models. Under
     * grants the ACL key is needed as well; under lists every custom role holds it (D7), so it decides nothing.
     * Grants once stopped at the key plus target access, which let any role holding the key revoke, reissue or
     * delete every link to a file it could see, including links handed out by others (UX eval 2026-10-10, R14).
     */
    protected function canManage(Share $share, string $aclKey): bool
    {
        if (! app(AccessModel::class)->lists() && ! bouncer()->hasPermission($aclKey)) {
            return false;
        }

        return $this->isCreatorOwnerOrAdmin($share);
    }

    /**
     * The link's creator, the target's owner or a full admin: who may see a link's URL (#126) and change the link.
     */
    protected function isCreatorOwnerOrAdmin(Share $share): bool
    {
        $adminId = auth()->guard('admin')->id();

        return $share->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: $this->permissionService->fullAdmin());
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

    /** Hidden answers exactly like missing. */
    protected function hiddenTarget(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.dam.share.target-not-found'),
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
