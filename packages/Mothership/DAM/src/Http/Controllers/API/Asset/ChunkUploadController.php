<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mothership\DAM\Http\Controllers\Asset\ChunkUploadController as LibraryChunkUploadController;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * REST twin of the Library's chunked upload (docs/api/chunked-upload.md): the same service, parts, limits and
 * finalize job, as the API key's admin, behind the upload scope (Config/api-acl.php). A file over the single-request
 * limit (about 100 MB through the site's proxy) goes up in parts of `chunk_size` bytes, and the completed upload is
 * the asset. Unlike the Library, the REST client does not open an upload tracker first: initialize opens one for the
 * folder, or reuses the caller's own `session_uuid`, which is also what lets a client resume the same upload later.
 */
class ChunkUploadController extends LibraryChunkUploadController
{
    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => ['sometimes', 'uuid'],
            'directory_id' => ['required', 'integer', new VisibleDirectory],
        ]);
        $directoryId = (int) $data['directory_id'];
        if (! app(DirectoryPermissionService::class)->canAccess($directoryId)) {
            return $this->refuse(status: 403, code: 'directory_forbidden', message: 'The upload destination is not accessible.');
        }
        $tracker = UploadTracker::query()->firstOrCreate(['uuid' => $data['session_uuid'] ?? (string) Str::uuid()], [
            'user_id'      => $this->actorId(),
            'directory_id' => $directoryId,
            'state'        => UploadTracker::STATE_PROCESSING,
            'started_at'   => now(),
        ]);
        // Another account's session, or this one's for another folder, is not this upload's.
        if ((int) $tracker->user_id !== $this->actorId() || (int) $tracker->directory_id !== $directoryId) {
            return $this->refuse(status: 409, code: 'destination_mismatch', message: 'The session_uuid belongs to another upload destination.');
        }
        // preserve_root only matters for a dropped folder; a REST client sending one file may leave it out.
        $request->merge(['session_uuid' => $tracker->uuid, 'preserve_root' => $request->input('preserve_root', false)]);

        return parent::initialize($request);
    }

    protected function actor(): ?Admin
    {
        $admin = auth()->guard('api')->user();

        return $admin instanceof Admin ? $admin : null;
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message, 'retryable' => false, 'upload' => null], $status);
    }
}
