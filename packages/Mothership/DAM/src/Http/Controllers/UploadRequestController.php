<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\UploadRequests\IntakeReview;
use Mothership\DAM\Services\UploadRequests\UploadRequestService;
use Mothership\DAM\Services\UploadRequests\UploadSubmissions;
use Mothership\User\Models\Admin;

/**
 * P5a (D1): the staff side of guest upload requests, in the Library. Create one from a folder's context menu, list and
 * edit them on the "Upload requests" page, review what guests sent and Accept or Reject it.
 *
 * A request the actor may not see (UploadRequestService::canSee) answers 404 on every route, exactly like a missing
 * id. Editing, closing and Reissue are the creator's and full admins' (403 for anyone else who can see it). A file is
 * decided through IntakeReview, which runs the access seam on each one.
 */
class UploadRequestController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        protected UploadRequestService $requests,
        protected IntakeReview $review,
        protected UploadSubmissions $submissions,
    ) {}

    public function index()
    {
        return view('dam::upload-requests.index');
    }

    public function list(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $page = $this->requests->visibleQuery($actor)
            ->when($request->filled('directory_id'), fn ($query) => $query->where('directory_id', $request->integer('directory_id')))
            ->paginate(perPage: self::PER_PAGE, page: $request->integer('page', 1));

        return response()->json([
            'requests' => $page->getCollection()->map(fn (UploadRequest $uploadRequest) => $this->requests->present(request: $uploadRequest, actor: $actor))->values(),
            'meta'     => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()],
        ]);
    }

    public function show(int $id)
    {
        $uploadRequest = $this->visibleOrFail($id);

        return view('dam::upload-requests.show', [
            'uploadRequest' => $this->requests->present(request: $uploadRequest, actor: $this->actor()),
            'canDecide'     => $this->review->canDecide(actor: $this->actor(), request: $uploadRequest),
        ]);
    }

    public function submissions(int $id): JsonResponse
    {
        $uploadRequest = $this->visibleOrFail($id);
        $actor = $this->actor();

        return response()->json([
            'request'     => $this->requests->present(request: $uploadRequest, actor: $actor),
            'can_decide'  => $this->review->canDecide(actor: $actor, request: $uploadRequest),
            ...$this->submissions->forStaff(request: $uploadRequest, context: AccessContext::library(AccessContext::PURPOSE_LIST)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(UploadRequestService::rules(creating: true));
        $actor = $this->actor();
        $directory = Directory::query()->where('id', (int) $data['directory_id'])->first();

        if ($directory === null || ! $this->requests->canUseAsIntake(actor: $actor, directory: $directory)) {
            return response()->json(['message' => trans('dam::app.admin.upload-requests.folder-missing')], 404);
        }

        [$uploadRequest, $token] = $this->requests->create(actor: $actor, directory: $directory, data: $data);

        return response()->json([
            'message' => trans('dam::app.admin.upload-requests.created'),
            'request' => $this->requests->present(request: $uploadRequest, actor: $actor, freshToken: $token),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->manageableOrFail($id);
        $data = $request->validate(UploadRequestService::rules(creating: false));
        $updated = $this->requests->update(actor: $this->actor(), request: $uploadRequest, data: $data);

        return response()->json([
            'message' => trans('dam::app.admin.upload-requests.updated'),
            'request' => $this->requests->present(request: $updated, actor: $this->actor()),
        ]);
    }

    public function close(int $id): JsonResponse
    {
        $closed = $this->requests->close(actor: $this->actor(), request: $this->manageableOrFail($id));

        return response()->json([
            'message' => trans('dam::app.admin.upload-requests.closed'),
            'request' => $this->requests->present(request: $closed, actor: $this->actor()),
        ]);
    }

    public function reissue(int $id): JsonResponse
    {
        $reissued = $this->requests->reissue(actor: $this->actor(), request: $this->manageableOrFail($id));

        if ($reissued === null) {
            return response()->json(['message' => trans('dam::app.admin.upload-requests.reissue-closed')], 409);
        }

        [$uploadRequest, $token] = $reissued;

        return response()->json([
            'message' => trans('dam::app.admin.upload-requests.reissued'),
            'request' => $this->requests->present(request: $uploadRequest, actor: $this->actor(), freshToken: $token),
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->visibleOrFail($id);
        $data = $request->validate([
            'asset_ids'      => ['required', 'array', 'min:1', 'max:500'],
            'asset_ids.*'    => ['integer'],
            'destination_id' => ['nullable', 'integer'],
        ]);
        $destination = null;

        if (! empty($data['destination_id'])) {
            $destination = Directory::query()->where('id', (int) $data['destination_id'])->first();

            if ($destination === null || ! $this->requests->canUseAsIntake(actor: $this->actor(), directory: $destination)) {
                return response()->json(['message' => trans('dam::app.admin.upload-requests.folder-missing')], 404);
            }
        }

        $result = $this->review->accept(actor: $this->actor(), request: $uploadRequest, assetIds: $data['asset_ids'], destination: $destination);

        return $this->decided(result: $result, decision: IntakeReview::ACCEPTED);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $uploadRequest = $this->visibleOrFail($id);
        $data = $request->validate([
            'asset_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'asset_ids.*' => ['integer'],
        ]);

        $result = $this->review->reject(actor: $this->actor(), request: $uploadRequest, assetIds: $data['asset_ids']);

        return $this->decided(result: $result, decision: IntakeReview::REJECTED);
    }

    /** @param  array{outcomes: array<int, string>, status: int}  $result */
    protected function decided(array $result, string $decision): JsonResponse
    {
        $done = count(array_filter($result['outcomes'], fn (string $outcome) => $outcome === $decision));
        $message = match ($result['status']) {
            200     => trans_choice('dam::app.admin.upload-requests.review.'.$decision, $done, ['count' => $done]),
            403     => trans('dam::app.admin.upload-requests.review.'.(in_array(IntakeReview::OWN_ONLY, $result['outcomes'], true) ? 'reject-own-only' : 'forbidden')),
            404     => trans('dam::app.admin.upload-requests.review.missing'),
            default => trans('dam::app.admin.upload-requests.review.not-pending'),
        };

        return response()->json(['message' => $message, 'outcomes' => (object) $result['outcomes']], $result['status']);
    }

    protected function visibleOrFail(int $id): UploadRequest
    {
        return $this->requests->visible(actor: $this->actor(), id: $id) ?? abort(404);
    }

    protected function manageableOrFail(int $id): UploadRequest
    {
        $uploadRequest = $this->visibleOrFail($id);

        abort_unless($this->requests->canManage(actor: $this->actor(), request: $uploadRequest), 403, trans('dam::app.admin.upload-requests.manage-forbidden'));

        return $uploadRequest;
    }

    protected function actor(): Admin
    {
        /** @var Admin */
        return auth('admin')->user();
    }
}
