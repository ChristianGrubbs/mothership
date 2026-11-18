<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\User\Models\Admin;

class ChunkUploadController extends Controller
{
    public function __construct(
        protected ChunkUploadService $service,
    ) {}

    public function initialize(Request $request): JsonResponse
    {
        $metadata = $request->validate([
            'session_uuid'  => ['required', 'uuid'],
            'directory_id'  => ['required', 'integer', new VisibleDirectory],
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type'     => ['nullable', 'string', 'max:255'],
            'size'          => ['required', 'integer', 'min:1'],
            'last_modified' => ['required', 'integer', 'min:0'],
            'fingerprint'   => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
            'relative_path' => ['nullable', 'string', 'max:4096'],
            'preserve_root' => ['required', 'boolean'],
            'create_only'   => ['sometimes', 'boolean'],
        ]);

        try {
            $upload = $this->service->initialize($metadata, (int) auth('admin')->id());

            return $this->success(
                upload: $this->statusOf($upload),
                status: $upload->wasRecentlyCreated ? 201 : 200,
                message: $upload->wasRecentlyCreated ? 'Upload initialized.' : 'Upload resumed.',
            );
        } catch (ChunkUploadError $error) {
            return $this->error($error);
        }
    }

    public function status(string $uuid): JsonResponse
    {
        try {
            $upload = $this->service->findAuthorized($uuid, (int) auth('admin')->id());

            return $this->success($this->statusOf($upload), 200, 'Upload status retrieved.');
        } catch (ChunkUploadError $error) {
            return $this->error($error);
        }
    }

    public function store(Request $request, string $uuid, int $index): JsonResponse
    {
        try {
            $upload = $this->service->findAuthorized($uuid, (int) auth('admin')->id());
            $this->service->storePart(
                upload: $upload,
                index: $index,
                headers: [
                    'content_range' => $request->header('Content-Range'),
                    'sha256'        => $request->header('X-Chunk-SHA256'),
                ],
                stream: $request->getContent(true),
            );

            return $this->success($this->statusOf($upload->refresh()), 200, 'Chunk accepted.');
        } catch (ChunkUploadError $error) {
            return $this->error($error);
        }
    }

    public function complete(string $uuid): JsonResponse
    {
        try {
            $upload = $this->service->findAuthorized($uuid, (int) auth('admin')->id());
            $upload = $this->service->queueCompletion($upload);
            $status = $upload->state === ChunkUpload::STATE_COMPLETED ? 200 : 202;

            return $this->success($this->statusOf($upload), $status, 'Upload completion accepted.');
        } catch (ChunkUploadError $error) {
            return $this->error($error);
        }
    }

    public function cancel(string $uuid): JsonResponse
    {
        try {
            $upload = $this->service->findAuthorized($uuid, (int) auth('admin')->id());
            $this->service->cancel($upload);

            return $this->success($this->statusOf($upload->refresh()), 200, 'Upload cancelled.');
        } catch (ChunkUploadError $error) {
            return $this->error($error);
        }
    }

    /**
     * The upload's status plus, once a new file is published, its exact-duplicate notice (P3b-2): the same notice a
     * single-request upload answers with, worked out now for the signed-in admin. `duplicate` is always present and
     * null when nothing the admin can see holds these bytes. `similar` (P5c) is the near-duplicate hint, null likewise.
     */
    protected function statusOf(ChunkUpload $upload): array
    {
        $receipt = $this->service->receipt($upload);
        $notices = $receipt === null ? [] : app(ExactDuplicateNotices::class)->notices(
            receipts: [$receipt],
            context: AccessContext::library(AccessContext::PURPOSE_LIST),
            canStackInstead: StackInstead::allowedInLibrary(),
        );

        // P5c (D16): the near-duplicate hint, pairs the admin can see both sides of; null when there is none.
        $viewer = auth('admin')->user();
        $hints = $receipt === null || ! $viewer instanceof Admin ? [] : app(SimilarImages::class)->hints(
            receipts: [$receipt],
            viewer: $viewer,
            context: AccessContext::library(AccessContext::PURPOSE_LIST),
        );

        // A new version of a same-name file, or a new file under the next free name, is said in the tray's row.
        // `file_name` is the name the Library stored it under (null until published), which the Creative Hub workspace shows.
        $publishedName = $receipt === null ? null : (string) Asset::query()->whereKey($receipt->assetId)->value('file_name');
        $note = $receipt === null ? null : ExactDuplicateNotices::publishedNote(
            receipt: $receipt,
            originalName: (string) $upload->original_name,
            publishedName: (string) $publishedName,
        );

        return [...$this->service->status($upload), 'duplicate' => $notices[0] ?? null, 'similar' => $hints[0] ?? null, 'published_note' => $note, 'file_name' => $publishedName];
    }

    protected function success(array $upload, int $status, string $message): JsonResponse
    {
        return new JsonResponse([
            'success'   => true,
            'code'      => null,
            'message'   => $message,
            'retryable' => false,
            'upload'    => $upload,
        ], $status);
    }

    protected function error(ChunkUploadError $error): JsonResponse
    {
        return new JsonResponse([
            'success'   => false,
            'code'      => $error->errorCode,
            'message'   => $error->getMessage(),
            'retryable' => $error->retryable,
            'upload'    => null,
        ], $error->status);
    }
}
