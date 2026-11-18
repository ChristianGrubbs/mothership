<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\PublicUpload;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Http\Middleware\EnforceUploadPortal;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\UploadRequests\AllowedTypes;
use Mothership\DAM\Services\UploadRequests\GuestIntakeFunnel;
use Mothership\DAM\Services\UploadRequests\GuestIntakeService;
use Mothership\DAM\Services\UploadRequests\PortalGate;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\DAM\Support\GuestIntakeLog;
use Symfony\Component\HttpFoundation\Response;

/**
 * P5a (D1): the guest upload portal at `/u/{token}`. Write-only: it lists, searches, previews and downloads nothing.
 * The page shows the request's title, instructions and limits, a drop zone and the names this visit sent; the JSON
 * calls are the staff chunk protocol (ChunkUploadService), bound to the visit and placed by the server.
 */
class UploadPortalController extends Controller
{
    public function __construct(
        protected PortalGate $gate,
        protected GuestIntakeService $intake,
        protected ChunkUploadService $chunks,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $uploadRequest = $this->gate->accepting($token);

        if ($uploadRequest === null) {
            return $this->unavailable();
        }

        $nonce = $this->gate->visit(request: $request, uploadRequest: $uploadRequest);

        if ($nonce === null && $uploadRequest->hasPassword()) {
            return EnforceUploadPortal::private(response()->view('dam::upload-portal.password', ['token' => $token], 401));
        }

        $response = EnforceUploadPortal::private(response()->view('dam::upload-portal.show', [
            'uploadRequest' => $uploadRequest,
            'token'         => $token,
            'visitFiles'    => $nonce === null ? [] : $this->intake->visitFiles(request: $uploadRequest, nonce: $nonce),
            'accept'        => AllowedTypes::acceptAttribute($uploadRequest->allowed_types),
            'chunkBytes'    => (int) config('dam.explorer.upload.chunk_bytes'),
        ]));

        if ($nonce === null) {
            $response->headers->setCookie($this->gate->visitCookie(request: $request, uploadRequest: $uploadRequest, token: $token));
        }

        // Counted per page shown, never from the visit cookie (§3.6.7: guest counting is cookieless).
        GuestIntakeFunnel::emit(GuestIntakeFunnel::PORTAL_OPENED, ['upload_request_id' => (int) $uploadRequest->id]);

        return $response;
    }

    /** The password prompt's answer: the right password starts a visit; a wrong one shows the prompt again. */
    public function unlock(Request $request, string $token): Response
    {
        $uploadRequest = $this->gate->accepting($token);

        if ($uploadRequest === null) {
            return $this->unavailable();
        }

        if (! $uploadRequest->hasPassword()) {
            return redirect()->route('dam.upload_portal.show', ['token' => $token]);
        }

        $password = $request->input('password');
        $result = $this->gate->attempt(request: $request, uploadRequest: $uploadRequest, password: is_string($password) ? $password : '');

        if ($result['outcome'] === 'ok') {
            return EnforceUploadPortal::private(redirect()->route('dam.upload_portal.show', ['token' => $token])
                ->withCookie($this->gate->visitCookie(request: $request, uploadRequest: $uploadRequest, token: $token)));
        }

        return EnforceUploadPortal::private(response()->view('dam::upload-portal.password', [
            'token'      => $token,
            'error'      => $result['outcome'],
            'retryAfter' => $result['retry_after'],
        ], $result['outcome'] === 'locked' ? 429 : 401));
    }

    public function initialize(Request $request, string $token): JsonResponse
    {
        ['request' => $uploadRequest, 'nonce' => $nonce] = $request->attributes->get(EnforceUploadPortal::ATTRIBUTE);

        // Server-placed: a guest never names a folder, a path or an asset to overwrite. Refused, never followed.
        $named = array_values(array_filter(GuestIntakeService::PLACEMENT_FIELDS, fn (string $field) => $request->has($field)
            && ! in_array($request->input($field), [null, '', false], true)));

        if ($named !== []) {
            $this->gate->misuse(uploadRequest: $uploadRequest, reason: 'client_placement');

            return EnforceUploadPortal::json(status: 422, code: 'placement_not_allowed', message: trans('dam::app.upload-portal.errors.placement'));
        }

        $metadata = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type'     => ['nullable', 'string', 'max:255'],
            'size'          => ['required', 'integer', 'min:1'],
            'last_modified' => ['required', 'integer', 'min:0'],
            'fingerprint'   => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
            'guest_name'    => ['nullable', 'string', 'max:191'],
            'guest_email'   => ['nullable', 'string', 'email', 'max:191'],
        ]);

        try {
            $upload = $this->intake->initialize(request: $uploadRequest, nonce: $nonce, metadata: $metadata);

            return $this->success(upload: $upload, status: $upload->wasRecentlyCreated ? 201 : 200);
        } catch (ChunkUploadError $error) {
            return $this->error($error, $uploadRequest);
        }
    }

    public function status(Request $request, string $token, string $uuid): JsonResponse
    {
        return $this->withUpload($request, $uuid, fn (ChunkUpload $upload) => $this->success($upload, 200));
    }

    public function store(Request $request, string $token, string $uuid, int $index): JsonResponse
    {
        return $this->withUpload($request, $uuid, function (ChunkUpload $upload) use ($request, $index): JsonResponse {
            $this->chunks->storePart(
                upload: $upload,
                index: $index,
                headers: ['content_range' => $request->header('Content-Range'), 'sha256' => $request->header('X-Chunk-SHA256')],
                stream: $request->getContent(true),
            );

            return $this->success($upload->refresh(), 200);
        });
    }

    public function complete(Request $request, string $token, string $uuid): JsonResponse
    {
        return $this->withUpload($request, $uuid, function (ChunkUpload $upload): JsonResponse {
            $upload = $this->chunks->queueCompletion($upload);

            return $this->success($upload, $upload->state === ChunkUpload::STATE_COMPLETED ? 200 : 202);
        });
    }

    public function cancel(Request $request, string $token, string $uuid): JsonResponse
    {
        return $this->withUpload($request, $uuid, function (ChunkUpload $upload): JsonResponse {
            $this->chunks->cancel($upload);

            return $this->success($upload->refresh(), 200);
        });
    }

    /** @param  callable(ChunkUpload): JsonResponse  $action */
    protected function withUpload(Request $request, string $uuid, callable $action): JsonResponse
    {
        ['request' => $uploadRequest, 'nonce' => $nonce] = $request->attributes->get(EnforceUploadPortal::ATTRIBUTE);

        try {
            return $action($this->intake->find(request: $uploadRequest, nonce: $nonce, uuid: $uuid));
        } catch (ChunkUploadError $error) {
            return $this->error($error, $uploadRequest);
        }
    }

    protected function success(ChunkUpload $upload, int $status): JsonResponse
    {
        return EnforceUploadPortal::private(new JsonResponse([
            'success'   => true,
            'code'      => null,
            'message'   => null,
            'retryable' => false,
            'upload'    => $this->intake->status($upload),
        ], $status));
    }

    protected function error(ChunkUploadError $error, UploadRequest $uploadRequest): JsonResponse
    {
        GuestIntakeLog::write(event: 'portal.upload.refused', ctx: ['upload_request_id' => (int) $uploadRequest->id, 'code' => $error->errorCode, 'status' => $error->status], level: 'notice');

        return EnforceUploadPortal::json(status: $error->status, code: $error->errorCode, message: $error->getMessage(), retryable: $error->retryable);
    }

    /** Unknown, closed or expired: one page, one status, whichever it is. */
    protected function unavailable(): Response
    {
        return EnforceUploadPortal::private(response()->view('dam::upload-portal.unavailable', [], 410));
    }
}
