<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Explorer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Http\Controllers\Access\AccessController;
use Mothership\DAM\Jobs\MassMove as MassMoveJob;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\ActingAdminPermission;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\AssetAccessControl;

class MassMoveController extends Controller
{
    use ActingAdminPermission, ActionRequestTrait, AssetAccessControl;

    public function __construct(
        protected DirectoryPermissionService $permissionService,
        protected AccessMoveGuard $moveGuard,
    ) {}

    public function move(Request $request): JsonResponse
    {
        $request->validate([
            'asset_ids'           => 'nullable|array',
            'asset_ids.*'         => 'integer',
            'directory_ids'       => 'nullable|array',
            'directory_ids.*'     => ['integer', new VisibleDirectory],
            'target_directory_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        if (! empty($request->input('asset_ids')) && ! $this->actingAdminHolds('dam.asset.moved')) {
            abort(403, trans('dam::app.admin.permissions.denied-move-files'));
        }

        if (! empty($request->input('directory_ids')) && ! $this->actingAdminHolds('dam.directory.moved')) {
            abort(403, trans('dam::app.admin.permissions.denied-move-folders'));
        }

        $targetId = $request->integer('target_directory_id');

        if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($targetId)) {
            return new JsonResponse(['message' => trans('dam::app.admin.permissions.move-unauthorized')], 403);
        }

        // Codex review of PR #75, finding 6: one cap for the preview and the move alike, under `lists`
        // — a selection the preview could never confirm must not still reach an uncapped, unconfirmed
        // move. Checked per id kind, matching the preview's own per-type cap.
        if (app(AccessModel::class)->lists()
            && (count($request->input('asset_ids', [])) > AccessController::MAX_MOVE_PREVIEW_IDS
                || count($request->input('directory_ids', [])) > AccessController::MAX_MOVE_PREVIEW_IDS)
        ) {
            return new JsonResponse(['message' => trans('dam::app.admin.dam.access.too-many-move-ids')], 422);
        }

        $assetIds = array_filter(
            array_map('intval', $request->input('asset_ids', [])),
            fn (int $id) => $this->damCanAccessAsset($id)
        );

        // A role limited to its own items moves only what it created; the rest is skipped and counted, never silently.
        $roles = app(RoleBuilder::class);
        $skippedForeign = 0;
        $foreignInside = 0;
        $hiddenInside = false;

        if ($roles->restrictedToOwn()) {
            $owned = Asset::query()->whereIn('id', $assetIds)->get()->filter(fn (Asset $asset) => $roles->mayChange($asset))->pluck('id')->map(fn ($id) => (int) $id)->all();
            $skippedForeign += count($assetIds) - count($owned);
            $assetIds = array_values(array_intersect($assetIds, $owned));
        }

        $targetDirectory = Directory::findOrFail($targetId);

        $dirIds = array_values(array_unique(array_map('intval', $request->input('directory_ids', []))));
        $directories = Directory::whereIn('id', $dirIds)->get()->keyBy('id');

        foreach ($dirIds as $id) {
            if (! $this->permissionService->bypass() && ! $this->permissionService->canAccess($id)) {
                return new JsonResponse(['message' => trans('dam::app.admin.permissions.move-unauthorized')], 403);
            }

            $directory = $directories->get($id);

            if (! $directory || ! $directory->isDeletable()) {
                return new JsonResponse(['message' => trans('dam::app.admin.dam.index.directory.cannot-move')], 403);
            }

            if ($roles->restrictedToOwn() && $roles->refusal($directory) !== null) {
                $skippedForeign++;
                $dirIds = array_values(array_diff($dirIds, [$id]));

                continue;
            }

            // A folder that holds anything this person cannot see stays put and is answered without any numbers.
            if ($roles->restrictedToOwn() && $this->moveGuard->isBlocked(admin: $roles->actingAdmin(), directoryId: $id)) {
                $skippedForeign++;
                $hiddenInside = true;
                $dirIds = array_values(array_diff($dirIds, [$id]));

                continue;
            }

            // A folder moves with everything in it, so one that holds anything of someone else's stays where it is.
            // Counted only after the hidden check above, so every item in the number is one they can see.
            $foreign = $this->moveGuard->foreignSubtreeCounts(admin: $roles->actingAdmin(), directoryId: $id);

            if ($foreign['folders'] + $foreign['files'] > 0) {
                $skippedForeign++;
                $foreignInside += $foreign['folders'] + $foreign['files'];
                $dirIds = array_values(array_diff($dirIds, [$id]));

                continue;
            }

            if ($id === $targetId || $directory->isAncestorOf($targetDirectory)) {
                return new JsonResponse(['message' => trans('dam::app.admin.dam.index.directory.cannot-move-inside')], 422);
            }
        }

        if ($skippedForeign > 0 && $assetIds === [] && $dirIds === []) {
            return new JsonResponse([
                'message' => $hiddenInside
                    ? trans('dam::app.admin.permissions.own-only-subtree-hidden')
                    : ($foreignInside > 0
                        ? trans('dam::app.admin.permissions.own-only-subtree-mass', ['count' => $foreignInside])
                        : trans('dam::app.admin.permissions.own-only-move-none', ['skipped' => $skippedForeign])),
                'skipped' => $skippedForeign,
            ], 403);
        }

        if ($this->moveGuard->firstBlocked(admin: $this->getUser(), directoryIds: $dirIds) !== null) {
            return new JsonResponse(['message' => trans('dam::app.admin.dam.access.move-blocked')], 422);
        }

        $requestAction = $this->start(EventType::MASS_MOVE->value);

        MassMoveJob::dispatch(
            array_values($assetIds),
            array_values($dirIds),
            $targetId,
            $requestAction->getUser()->id
        );

        return new JsonResponse(array_filter([
            'queued'  => true,
            'skipped' => $skippedForeign ?: null,
            'message' => $skippedForeign > 0
                ? trans('dam::app.admin.permissions.own-only-mass-move', ['moved' => count($assetIds) + count($dirIds), 'skipped' => $skippedForeign])
                : null,
        ], fn ($value) => $value !== null));
    }
}
