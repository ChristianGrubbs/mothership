<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\UploadRequests\IntakeReview;
use Mothership\DAM\Services\UploadRequests\UploadRequestService;
use Mothership\DAM\Services\UploadRequests\UploadSubmissions;
use Mothership\User\Models\Admin;

/**
 * P5a (D1): guest upload requests over REST, for staff integrations only: the same rules as the Library's pages
 * (UploadRequestController), the same answers. A request the key's admin may not see is 404 like a missing id;
 * editing, closing and Reissue are the creator's and full admins' (403). `data.url` is the guest link only for the
 * people the #126 rule names. The guest's own portal routes are never part of this API.
 */
class UploadRequestController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        protected UploadRequestService $requests,
        protected IntakeReview $review,
        protected UploadSubmissions $submissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $page = $this->requests->visibleQuery($actor)
            ->when($request->filled('directory_id'), fn ($query) => $query->where('directory_id', $request->integer('directory_id')))
            ->paginate(perPage: min(100, max(1, $request->integer('limit', self::PER_PAGE))), page: $request->integer('page', 1));

        return response()->json([
            'success'      => true,
            'data'         => $page->getCollection()->map(fn (UploadRequest $uploadRequest) => $this->requests->present(request: $uploadRequest, actor: $actor))->values(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $uploadRequest = $this->requests->visible(actor: $this->actor(), id: $id);

        return $uploadRequest === null ? $this->notFound() : $this->data($this->requests->present(request: $uploadRequest, actor: $this->actor()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(UploadRequestService::rules(creating: true));
        $actor = $this->actor();
        $directory = Directory::query()->where('id', (int) $data['directory_id'])->first();

        if ($directory === null || ! $this->requests->canUseAsIntake(actor: $actor, directory: $directory)) {
            return $this->notFound(trans('dam::app.admin.upload-requests.folder-missing'));
        }

        [$uploadRequest, $token] = $this->requests->create(actor: $actor, directory: $directory, data: $data);
        $presented = $this->requests->present(request: $uploadRequest, actor: $actor, freshToken: $token);

        return $this->data($presented, 201, trans('dam::app.admin.upload-requests.created'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->manageable($id);

        if ($uploadRequest instanceof JsonResponse) {
            return $uploadRequest;
        }

        $updated = $this->requests->update(actor: $this->actor(), request: $uploadRequest, data: $request->validate(UploadRequestService::rules(creating: false)));

        return $this->data($this->requests->present(request: $updated, actor: $this->actor()), 200, trans('dam::app.admin.upload-requests.updated'));
    }

    public function close(int $id): JsonResponse
    {
        $uploadRequest = $this->manageable($id);

        if ($uploadRequest instanceof JsonResponse) {
            return $uploadRequest;
        }

        $closed = $this->requests->close(actor: $this->actor(), request: $uploadRequest);

        return $this->data($this->requests->present(request: $closed, actor: $this->actor()), 200, trans('dam::app.admin.upload-requests.closed'));
    }

    public function reissue(int $id): JsonResponse
    {
        $uploadRequest = $this->manageable($id);

        if ($uploadRequest instanceof JsonResponse) {
            return $uploadRequest;
        }

        $reissued = $this->requests->reissue(actor: $this->actor(), request: $uploadRequest);

        if ($reissued === null) {
            return response()->json(['success' => false, 'message' => trans('dam::app.admin.upload-requests.reissue-closed')], 409);
        }

        return $this->data($this->requests->present(request: $reissued[0], actor: $this->actor(), freshToken: $reissued[1]), 200, trans('dam::app.admin.upload-requests.reissued'));
    }

    public function submissions(int $id): JsonResponse
    {
        $uploadRequest = $this->requests->visible(actor: $this->actor(), id: $id);

        if ($uploadRequest === null) {
            return $this->notFound();
        }

        return $this->data([
            'can_decide' => $this->review->canDecide(actor: $this->actor(), request: $uploadRequest),
            ...$this->submissions->forStaff(request: $uploadRequest, context: AccessContext::api(AccessContext::PURPOSE_LIST)),
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->requests->visible(actor: $this->actor(), id: $id);

        if ($uploadRequest === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'asset_ids'      => ['required', 'array', 'min:1', 'max:500'],
            'asset_ids.*'    => ['integer'],
            'destination_id' => ['nullable', 'integer'],
        ]);
        $destination = null;

        if (! empty($data['destination_id'])) {
            $destination = Directory::query()->where('id', (int) $data['destination_id'])->first();

            if ($destination === null || ! $this->requests->canUseAsIntake(actor: $this->actor(), directory: $destination)) {
                return $this->notFound(trans('dam::app.admin.upload-requests.folder-missing'));
            }
        }

        return $this->decided($this->review->accept(actor: $this->actor(), request: $uploadRequest, assetIds: $data['asset_ids'], destination: $destination));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->requests->visible(actor: $this->actor(), id: $id);

        if ($uploadRequest === null) {
            return $this->notFound();
        }

        $data = $request->validate(['asset_ids' => ['required', 'array', 'min:1', 'max:500'], 'asset_ids.*' => ['integer']]);

        return $this->decided($this->review->reject(actor: $this->actor(), request: $uploadRequest, assetIds: $data['asset_ids']));
    }

    /** @param  array{outcomes: array<int, string>, status: int}  $result */
    protected function decided(array $result): JsonResponse
    {
        $payload = ['success' => $result['status'] === 200, 'data' => ['outcomes' => (object) $result['outcomes']]];

        if (in_array(IntakeReview::OWN_ONLY, $result['outcomes'], true)) {
            $payload['message'] = trans('dam::app.admin.upload-requests.review.reject-own-only');
        }

        return response()->json($payload, $result['status']);
    }

    protected function manageable(int $id): UploadRequest|JsonResponse
    {
        $uploadRequest = $this->requests->visible(actor: $this->actor(), id: $id);

        if ($uploadRequest === null) {
            return $this->notFound();
        }

        if (! $this->requests->canManage(actor: $this->actor(), request: $uploadRequest)) {
            return response()->json(['success' => false, 'message' => trans('dam::app.admin.upload-requests.manage-forbidden')], 403);
        }

        return $uploadRequest;
    }

    /** @param  array<string, mixed>  $data */
    protected function data(array $data, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['success' => true, 'message' => $message, 'data' => $data], fn ($value) => $value !== null), $status);
    }

    protected function notFound(?string $message = null): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message ?? trans('dam::app.admin.upload-requests.not-found')], 404);
    }

    protected function actor(): Admin
    {
        $actor = auth('api')->user();
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }
}
