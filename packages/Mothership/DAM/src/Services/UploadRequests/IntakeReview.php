<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Support\GuestIntakeLog;
use Mothership\User\Models\Admin;

/**
 * P5a (D1): a staff member's decision on guest intake. This is not an approval workflow (operator decision Q3):
 * accepting is a plain staff action that lets a `pending_review` file into the library, rejecting sends it to the
 * trash.
 *
 * Each file is decided on its own, so the same call takes one id or many:
 * - `missing`: not a file of this request, or hidden from the actor by the access seam (AssetAccessDecision, purpose
 *   edit). A pending guest file is visible only to its owner, the request's creator, and full admins.
 * - `forbidden`: visible, but the actor has no edit rights on the intake folder (`dam.asset.update` and access to
 *   the folder).
 * - `not_pending`: already accepted, or in the trash.
 * - `accepted` / `rejected`: done.
 *
 * Accept makes the file `available` and gives it the intake folder's access list (recomputed from the folder, so it
 * reads exactly as any file uploaded there does), then flags it for the "New user uploads" queue. With a destination
 * it is moved there instead, through the Library's own move, so it takes that folder's list and is not flagged.
 */
class IntakeReview
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const MISSING = 'missing';

    public const FORBIDDEN = 'forbidden';

    public const NOT_PENDING = 'not_pending';

    public const BLOCKED = 'blocked';

    /** A role limited to its own items may reject only files sent through a link it made (or any, for a full admin). */
    public const OWN_ONLY = 'own_only';

    public function __construct(
        protected AssetAccessDecision $access,
        protected AccessModel $accessModel,
        protected AccessListWriter $lists,
        protected AssetTrashService $trash,
    ) {}

    /**
     * @param  list<int>  $assetIds
     * @return array{outcomes: array<int, string>, status: int}
     */
    public function accept(Admin $actor, UploadRequest $request, array $assetIds, ?Directory $destination = null): array
    {
        return $this->acceptTriaged(
            actor: $actor,
            request: $request,
            triaged: $this->triage(actor: $actor, request: $request, assetIds: $assetIds),
            destination: $destination,
        );
    }

    /**
     * Accepts the ids triage left ready, in place or into `$destination`.
     *
     * @param  array{0: array<int, string>, 1: list<int>}  $triaged
     * @return array{outcomes: array<int, string>, status: int}
     */
    protected function acceptTriaged(Admin $actor, UploadRequest $request, array $triaged, ?Directory $destination): array
    {
        [$outcomes, $ready] = $triaged;

        if ($ready !== []) {
            $accepted = $destination === null
                ? $this->acceptInPlace(assetIds: $ready)
                : $this->acceptInto(actor: $actor, assetIds: $ready, destination: $destination);

            foreach ($ready as $id) {
                $outcomes[$id] = in_array($id, $accepted, true) ? self::ACCEPTED : self::BLOCKED;
            }

            $this->recorded(actor: $actor, request: $request, assetIds: $accepted, decision: self::ACCEPTED);
        }

        return ['outcomes' => $outcomes, 'status' => self::status($outcomes)];
    }

    /**
     * @param  list<int>  $assetIds
     * @return array{outcomes: array<int, string>, status: int}
     */
    public function reject(Admin $actor, UploadRequest $request, array $assetIds): array
    {
        [$outcomes, $ready] = $this->triage(actor: $actor, request: $request, assetIds: $assetIds);
        $rejected = [];

        // Rejecting sends someone else's upload to the trash: only the link's creator (or a full admin, who is never
        // restricted) may, for a role limited to its own items. Accepting stays with anyone who can decide.
        if ($ready !== [] && app(RoleBuilder::class)->restrictedToOwn($actor) && (int) $request->created_by !== (int) $actor->id) {
            foreach ($ready as $id) {
                $outcomes[$id] = self::OWN_ONLY;
            }

            return ['outcomes' => $outcomes, 'status' => self::status($outcomes)];
        }

        foreach ($ready as $id) {
            try {
                $this->trash->trash(asset: Asset::query()->findOrFail($id), deletedBy: (int) $actor->id);
                $rejected[] = $id;
                $outcomes[$id] = self::REJECTED;
            } catch (AssetDeletionException) {
                $outcomes[$id] = self::BLOCKED;
            }
        }

        $this->recorded(actor: $actor, request: $request, assetIds: $rejected, decision: self::REJECTED);

        return ['outcomes' => $outcomes, 'status' => self::status($outcomes)];
    }

    /**
     * Whether `$actor` holds edit rights on the request's intake folder: the editor right and access to the folder.
     * Without them they may still see the request, but not decide its files.
     */
    public function canDecide(Admin $actor, UploadRequest $request): bool
    {
        return $this->intakeRefusal(actor: $actor, request: $request) === null;
    }

    /** Why `$actor` cannot decide on the request's intake folder (`missing_edit_permission` or `no_folder_access`), or null. */
    private function intakeRefusal(Admin $actor, UploadRequest $request): ?string
    {
        $role = $actor->role;
        $fullAdmin = $role === null || $role->permission_type === 'all';

        return match (true) {
            ! $fullAdmin && ! in_array('dam.asset.update', (array) $role->permissions, true)                    => 'missing_edit_permission',
            ! (new DirectoryPermissionService)->forAdmin($actor)->canAccess((int) $request->directory_id)       => 'no_folder_access',
            default                                                                                             => null,
        };
    }

    /**
     * The HTTP status a decision answers with: 200 once any file was decided; otherwise 404 when every id was missing
     * or hidden, 403 when the actor may see some but decide none, 409 when the rest were already decided.
     *
     * @param  array<int, string>  $outcomes
     */
    public static function status(array $outcomes): int
    {
        $values = array_values($outcomes);

        return match (true) {
            array_intersect($values, [self::ACCEPTED, self::REJECTED]) !== [] => 200,
            $values === [] || array_diff($values, [self::MISSING]) === []     => 404,
            in_array(self::FORBIDDEN, $values, true)                          => 403,
            in_array(self::OWN_ONLY, $values, true)                           => 403,
            default                                                           => 409,
        };
    }

    /**
     * Sorts the ids into those this actor may decide now and the outcome of every other one.
     *
     * @param  list<int>  $assetIds
     * @return array{0: array<int, string>, 1: list<int>}
     */
    protected function triage(Admin $actor, UploadRequest $request, array $assetIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));
        $ofRequest = Asset::withTrashed()
            ->whereIn('id', $ids)
            ->whereIn('upload_submission_id', UploadSubmission::query()->select('id')->where('upload_request_id', $request->id))
            ->get()
            ->keyBy('id');
        $mayDecide = $this->canDecide(actor: $actor, request: $request);
        $context = AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_EDIT);
        $outcomes = [];
        $ready = [];

        foreach ($ids as $id) {
            $asset = $ofRequest->get($id);

            if ($asset === null) {
                $outcomes[$id] = self::MISSING;

                continue;
            }

            if ($asset->trashed()) {
                $outcomes[$id] = self::NOT_PENDING;

                continue;
            }

            $decision = $this->access->for($context, $asset);

            if (! $decision->visible) {
                $outcomes[$id] = self::MISSING;
            } elseif (! $mayDecide || ! $decision->allowed) {
                $outcomes[$id] = self::FORBIDDEN;
            } elseif (! $asset->isPendingGuestIntake()) {
                $outcomes[$id] = self::NOT_PENDING;
            } else {
                $ready[] = $id;
            }
        }

        return [$outcomes, $ready];
    }

    /**
     * Available, with the intake folder's list, flagged for the "New user uploads" queue: one fenced transaction, the
     * file rows locked, so a file is never available with its private list or private with the folder's.
     *
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    protected function acceptInPlace(array $assetIds): array
    {
        return $this->accessModel->sharedly(function () use ($assetIds): array {
            $pending = Asset::query()->whereIn('id', $assetIds)->lockForUpdate()->get()
                ->filter(fn (Asset $asset) => $asset->isPendingGuestIntake());
            $ids = $pending->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            if ($ids === []) {
                return [];
            }

            $this->lists->recomputeAssets($ids);

            foreach ($pending as $asset) {
                // Through the model, so the asset's History shows the change.
                $asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
            }

            Asset::query()->whereIn('id', $ids)->update(['needs_sorting_at' => now()]);

            return $ids;
        });
    }

    /**
     * Moved into `$destination` by the Library's move (it takes that folder's list), then made available: only what
     * really landed there is accepted, so a failed move leaves the file pending and private.
     *
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    protected function acceptInto(Admin $actor, array $assetIds, Directory $destination): array
    {
        $this->accessModel->sharedly(fn () => Directory::withTreeLock(fn () => (new MassMove($assetIds, [], (int) $destination->id, (int) $actor->id))->handle()));

        $moved = DB::table('dam_asset_directory')->whereIn('asset_id', $assetIds)->where('directory_id', $destination->id)
            ->pluck('asset_id')->map(fn ($id) => (int) $id)->all();

        foreach (Asset::query()->whereIn('id', $moved)->get() as $asset) {
            $asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
        }

        return array_values(array_intersect($assetIds, $moved));
    }

    /** @param  list<int>  $assetIds */
    protected function recorded(Admin $actor, UploadRequest $request, array $assetIds, string $decision): void
    {
        if ($assetIds === []) {
            return;
        }

        $submissions = Asset::withTrashed()->whereIn('id', $assetIds)->pluck('upload_submission_id', 'id');

        app(SecurityEventRecorder::class)->record(new SecurityEvent(
            name: $decision === self::ACCEPTED ? SecurityEvent::GUEST_SUBMISSION_ACCEPTED : SecurityEvent::GUEST_SUBMISSION_REJECTED,
            guard: 'admin',
            actorAdminId: (int) $actor->id,
            ctx: ['upload_request_id' => (int) $request->id, 'files' => count($assetIds)],
        ));

        // The security event above is a row and rolls back with a caller's transaction; these JSONL lines cannot, so
        // they wait for the commit (at once when no transaction is open).
        DB::afterCommit(function () use ($actor, $request, $assetIds, $decision, $submissions): void {
            foreach ($assetIds as $id) {
                $ids = ['upload_request_id' => (int) $request->id, 'submission_id' => (int) ($submissions[$id] ?? 0), 'asset_id' => $id, 'actor_admin_id' => (int) $actor->id];
                GuestIntakeFunnel::emit($decision === self::ACCEPTED ? GuestIntakeFunnel::SUBMISSION_ACCEPTED : GuestIntakeFunnel::SUBMISSION_REJECTED, $ids);
            }

            GuestIntakeLog::write(event: 'intake.'.$decision, ctx: ['upload_request_id' => (int) $request->id, 'files' => count($assetIds), 'actor_admin_id' => (int) $actor->id]);
        });
    }
}
