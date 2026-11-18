<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\Asset\AssetFileController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * REST twin of the Files tab (P3a S3, S5): attach, remove, promote, edit, reorder, history, restore and the
 * stack basis/key. AttachmentLedger applies the same rules the admin UI does. Authorization is the REST
 * form of damCanEditAsset(), as on the other REST mutations (API AssetController update, destroy): the
 * api.scope middleware checks the key's permission per route in place of bouncer(), which reads only the
 * admin guard and so is always false here, and folder access is checked per asset. A hidden asset answers
 * 404, exactly like a missing one. Actions that answer with the files list check that list's rule before
 * writing (mutableAsset()).
 */
class AttachmentController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected AttachmentLedger $ledger,
        protected AssetRelationshipService $relationships,
    ) {}

    public function store(Request $request, int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $validated = $request->validate([
            'file'  => ['required', 'file', 'max:'.AssetHelper::getMaxUploadSizeKb()],
            'role'  => ['required', 'string', Rule::in(AssetFile::ATTACHABLE_ROLES)],
            'label' => ['nullable', 'string', 'max:191'],
        ]);
        $upload = $request->file('file');
        abort_if(AssetHelper::isForbiddenFile(
            extension: $upload->getClientOriginalExtension(),
            mimeType: $upload->getMimeType(),
            fileName: $upload->getClientOriginalName(),
            realPath: $upload->getRealPath(),
            inspectWebBundles: false,
        ), 422, trans('dam::app.admin.dam.asset.files.attach-blocked'));

        $file = $this->ledger->attach(asset: $model, upload: $upload, role: $validated['role'], label: $validated['label'] ?? null);

        return response()->json(['success' => true, 'data' => ['file' => $this->relationships->formatFile($file->load('attachment'), $model)]], 201);
    }

    public function destroy(int $asset, int $file): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $tombstoneIds = $this->ledger->remove(asset: $model, assetFileId: $file);

        try {
            app(AssetDeletionService::class)->processPendingFileDeletions($tombstoneIds);
        } catch (\Throwable) {
            // Already logged with its tombstone kept for retry.
        }

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    public function promote(int $asset, int $attachment): JsonResponse
    {
        $model = $this->mutableAsset($asset);
        $promoted = $this->ledger->promote(asset: $model, attachmentId: $attachment);

        return response()->json(['success' => true, 'data' => [
            'asset' => $promoted->only(['id', 'file_name', 'path', 'extension', 'file_type', 'mime_type', 'file_size']),
            'files' => $this->relationships->files($model->id),
        ]]);
    }

    public function update(Request $request, int $asset, int $attachment): JsonResponse
    {
        $model = $this->mutableAsset($asset);
        $this->ledger->update(asset: $model, attachmentId: $attachment, data: $request->validate(AssetFileController::updateRules()));

        return response()->json(['success' => true, 'data' => ['files' => $this->relationships->files($model->id)]]);
    }

    public function reorder(Request $request, int $asset): JsonResponse
    {
        $model = $this->mutableAsset($asset);
        $data = $request->validate(AssetFileController::orderRules());
        $this->ledger->reorder(asset: $model, attachmentIds: $data['order'], expectedFiles: $data['expected_files'] ?? null);

        return response()->json(['success' => true, 'data' => ['files' => $this->relationships->files($model->id)]]);
    }

    public function revisions(int $asset, int $attachment): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);

        return response()->json(['success' => true, 'data' => ['revisions' => $this->relationships->revisions($model->id, $attachment)]]);
    }

    public function restore(int $asset, int $attachment, int $revision): JsonResponse
    {
        $model = $this->mutableAsset($asset);
        $this->ledger->restore(asset: $model, attachmentId: $attachment, revisionId: $revision);

        return response()->json(['success' => true, 'data' => [
            'files'     => $this->relationships->files($model->id),
            'revisions' => $this->relationships->revisions($model->id, $attachment),
        ]]);
    }

    public function stack(Request $request, int $asset, AssetStackService $stacks): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $data = AssetStackService::validate($request);

        return response()->json(['success' => true, 'data' => ['stack' => $stacks->set(asset: $model, basis: $data['basis'], key: $data['key'] ?? null)]]);
    }

    /**
     * A hidden asset answers 404. Under `grants` the files list these actions answer with applies the
     * first-directory rule, so it is checked before the write rather than after it committed.
     */
    private function mutableAsset(int $asset): Asset
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $this->relationships->authorizedAsset($model->id);

        return $model;
    }
}
