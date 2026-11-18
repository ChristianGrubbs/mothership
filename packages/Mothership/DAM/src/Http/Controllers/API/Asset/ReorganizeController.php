<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\Asset\AssetController as LibraryAssetController;
use Mothership\DAM\Http\Controllers\DirectoryController as LibraryDirectoryController;
use Mothership\DAM\Http\Controllers\Explorer\CopyController;
use Mothership\DAM\Http\Controllers\Explorer\MassMoveController;
use Mothership\DAM\Http\Controllers\TrashController;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Traits\ActingAdminPermission;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Move, copy and Trash over REST. Each method runs the Library's own action, so folder access, the own-items rule,
 * the locks and the jobs have one definition. api.scope has already asked for the key's permission; a role permission
 * the Library checks on its route is asked here, and one it checks inside the action is asked there.
 */
class ReorganizeController
{
    use ActingAdminPermission, AssetAccessControl;

    public function moveAsset(Request $request, int $id): JsonResponse
    {
        abort_unless($this->actingAdminHolds('dam.asset.moved'), 403, trans('dam::app.admin.permissions.denied-move-files'));

        $targetId = $this->targetDirectoryId($request);

        return $this->answer(app(LibraryAssetController::class)->moved($this->libraryRequest([
            'move_item_id'  => $id,
            'new_parent_id' => $targetId,
        ])));
    }

    public function moveAssets(Request $request): JsonResponse
    {
        $request->validate([
            'asset_ids'   => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer'],
        ]);

        $targetId = $this->targetDirectoryId($request);

        return $this->answer(app(MassMoveController::class)->move($this->libraryRequest([
            'asset_ids'           => $request->input('asset_ids'),
            'target_directory_id' => $targetId,
        ])), queued: true);
    }

    public function copyAsset(Request $request, int $id): JsonResponse
    {
        $this->damVisibleAssetOrFail($id);

        $targetId = $this->targetDirectoryId($request);

        return $this->answer(app(CopyController::class)->copyAsset($this->libraryRequest([
            'asset_id'            => $id,
            'target_directory_id' => $targetId,
        ])));
    }

    public function moveDirectory(Request $request, int $id): JsonResponse
    {
        abort_unless($this->actingAdminHolds('dam.directory.moved'), 403, trans('dam::app.admin.permissions.denied-move-folders'));
        abort_unless(VisibleDirectory::find($id) !== null, 404, trans('dam::app.admin.dam.index.directory.not-found'));

        $targetId = $this->targetDirectoryId($request);

        return $this->answer(app(LibraryDirectoryController::class)->moved($this->libraryRequest([
            'move_item_id'  => $id,
            'new_parent_id' => $targetId,
        ])), queued: true);
    }

    public function copyDirectory(Request $request, int $id): JsonResponse
    {
        abort_unless(VisibleDirectory::find($id) !== null, 404, trans('dam::app.admin.dam.index.directory.not-found'));

        $targetId = $this->targetDirectoryId($request);

        return $this->answer(app(CopyController::class)->copyDirectory($this->libraryRequest([
            'directory_id'        => $id,
            'target_directory_id' => $targetId,
        ])), queued: true);
    }

    public function trash(Request $request): JsonResponse
    {
        abort_unless($this->actingAdminHolds('dam.trash.index'), 403, trans('dam::app.admin.permissions.denied-view-trash'));

        return $this->answer(app(TrashController::class)->list($request));
    }

    public function restore(int $id): JsonResponse
    {
        abort_unless($this->actingAdminHolds('dam.trash.restore'), 403, trans('dam::app.admin.permissions.denied-restore-trash'));

        return $this->answer(app(TrashController::class)->restore($id));
    }

    public function purge(int $id): JsonResponse
    {
        return $this->answer(app(TrashController::class)->destroy($id));
    }

    /** Validated here so a refusal names the field this API documents, not the Library's own name for it. */
    private function targetDirectoryId(Request $request): int
    {
        $request->validate(['target_directory_id' => ['required', 'integer', new VisibleDirectory]]);

        return $request->integer('target_directory_id');
    }

    /** @param  array<string, mixed>  $input */
    private function libraryRequest(array $input): Request
    {
        return Request::create(uri: '/', method: 'POST', parameters: $input);
    }

    /** The Library's answer in this API's envelope; work handed to a queue answers 202 and says so. */
    private function answer(JsonResponse $response, bool $queued = false): JsonResponse
    {
        $status = $response->getStatusCode();
        $succeeded = $status < 400;
        $body = ['success' => $succeeded] + (array) $response->getData(true);

        if ($succeeded && $queued) {
            return new JsonResponse(['queued' => true] + $body, 202);
        }

        return new JsonResponse($body, $status);
    }
}
