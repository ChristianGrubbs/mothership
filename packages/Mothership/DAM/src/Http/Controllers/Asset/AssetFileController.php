<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\DAM\Traits\AssetAccessControl;

class AssetFileController
{
    use AssetAccessControl;

    public function store(Request $request, int $asset): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.files.store'), 403);
        $this->damAuthorizeAsset($asset);
        abort_unless($this->damCanEditAsset($asset), 403);
        $owner = Asset::findOrFail($asset);
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
        $file = app(AttachmentLedger::class)->attach(asset: $owner, upload: $upload, role: $validated['role'], label: $validated['label'] ?? null);

        return response()->json(app(AssetRelationshipService::class)->formatFile($file, $owner), 201);
    }

    /**
     * Start (or resume) a chunked attachment for a file too large for one request (PHP's post_max_size,
     * and Cloudflare's request-body cap in front of the Studio). The chunk, status, complete and cancel
     * calls then use the shared admin.dam.assets.chunk-upload.* routes by the returned uuid.
     */
    public function initializeChunked(Request $request, int $asset): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.files.store'), 403);
        $this->damAuthorizeAsset($asset);
        abort_unless($this->damCanEditAsset($asset), 403);
        $owner = Asset::findOrFail($asset);
        $metadata = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type'     => ['nullable', 'string', 'max:255'],
            'size'          => ['required', 'integer', 'min:1'],
            'last_modified' => ['required', 'integer', 'min:0'],
            'fingerprint'   => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
            'role'          => ['required', 'string', Rule::in(AssetFile::ATTACHABLE_ROLES)],
            'label'         => ['nullable', 'string', 'max:191'],
        ]);
        $service = app(ChunkUploadService::class);

        try {
            $upload = $service->initializeAttachment(metadata: $metadata, userId: (int) auth('admin')->id(), asset: $owner);
        } catch (ChunkUploadError $error) {
            return response()->json([
                'success'   => false, 'code' => $error->errorCode, 'message' => $error->getMessage(),
                'retryable' => $error->retryable, 'upload' => null,
            ], $error->status);
        }

        return response()->json([
            'success' => true, 'code' => null, 'message' => 'Upload initialized.', 'retryable' => false,
            'upload'  => $service->status($upload),
        ], $upload->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $asset, int $assetFile): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.files.destroy'), 403);
        $this->damAuthorizeAsset($asset);
        abort_unless($this->damCanEditAsset($asset), 403);

        $tombstoneIds = app(AttachmentLedger::class)->remove(asset: Asset::findOrFail($asset), assetFileId: $assetFile);

        // Database truth commits first. A failed unlink leaves a durable retry
        // record rather than rolling back an already-committed deletion.
        try {
            app(AssetDeletionService::class)->processPendingFileDeletions($tombstoneIds);
        } catch (\Throwable) {
            // Already logged with its tombstone kept for retry.
        }

        return response()->json(['deleted' => true]);
    }

    /** Makes another attachment the one that stands for the entry (P3a S3). */
    public function promote(int $asset, int $attachment): JsonResponse
    {
        $model = $this->editableAsset($asset);
        $promoted = app(AttachmentLedger::class)->promote(asset: $model, attachmentId: $attachment);

        return response()->json([
            'files' => app(AssetRelationshipService::class)->files($model->id),
            'asset' => $promoted->only(['id', 'file_name', 'path', 'extension', 'file_type', 'mime_type', 'file_size']),
        ]);
    }

    /** Makes an earlier revision current again by appending it (P3a S5). */
    public function restore(int $asset, int $attachment, int $revision): JsonResponse
    {
        $model = $this->editableAsset($asset);
        app(AttachmentLedger::class)->restore(asset: $model, attachmentId: $attachment, revisionId: $revision);
        $relationships = app(AssetRelationshipService::class);

        return response()->json([
            'files'     => $relationships->files($model->id),
            'revisions' => $relationships->revisions($model->id, $attachment),
        ]);
    }

    /** Edits an attachment's role, label or position. */
    public function update(Request $request, int $asset, int $attachment): JsonResponse
    {
        $model = $this->editableAsset($asset);
        $data = $request->validate(self::updateRules());
        app(AttachmentLedger::class)->update(asset: $model, attachmentId: $attachment, data: $data);

        return response()->json(['files' => app(AssetRelationshipService::class)->files($model->id)]);
    }

    /** Saves a full ordering of the entry's attachments. */
    public function reorder(Request $request, int $asset): JsonResponse
    {
        $model = $this->editableAsset($asset);
        $data = $request->validate(self::orderRules());
        app(AttachmentLedger::class)->reorder(asset: $model, attachmentIds: $data['order'], expectedFiles: $data['expected_files'] ?? null);

        return response()->json(['files' => app(AssetRelationshipService::class)->files($model->id), 'asset' => $model->fresh()->only(['id', 'file_name', 'path', 'extension', 'file_type', 'mime_type', 'file_size'])]);
    }

    /** @return array<string, array<int, mixed>> */
    public static function updateRules(): array
    {
        return [
            'role'     => ['sometimes', 'string', Rule::in(AssetFile::ATTACHABLE_ROLES)],
            'label'    => ['sometimes', 'nullable', 'string', 'max:191'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function orderRules(): array
    {
        return [
            'order'            => ['required', 'array', 'min:1'],
            'order.*'          => ['integer'],
            'expected_files'   => ['sometimes', 'array', 'min:1'],
            'expected_files.*' => ['integer'],
        ];
    }

    /**
     * Arranging an entry's files is editing the asset: a hidden asset answers 404, a read-only one 403. Under
     * `grants` the files list each action answers with uses the first-directory rule, stricter than the
     * any-directory one, so that rule is checked too, before the write, never after it committed.
     */
    private function editableAsset(int $asset): Asset
    {
        abort_unless(bouncer()->hasPermission('dam.asset.edit'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $model = $this->damVisibleAssetOrFail($asset);
        abort_unless($this->damCanEditAsset($model->id), 403, trans('dam::app.admin.permissions.unauthorized'));
        app(AssetRelationshipService::class)->authorizedAsset($model->id);

        return $model;
    }
}
