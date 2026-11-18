<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Repositories\AssetCommentsRepository;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Services\Review\ReviewComments;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\User\Repositories\AdminRepository;

class CommentController extends Controller
{
    use AssetAccessControl;

    /** Upper bound on how long one comment post holds its double-submit lock. */
    private const DUPLICATE_POST_WINDOW_SECONDS = 10;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetCommentsRepository $assetCommentRepository,
        protected AdminRepository $adminRepository,
        protected ReviewComments $reviewComments,
    ) {}

    /** One comment by its own id; its asset decides who may read it (P2 S1: this used to check asset $id). */
    public function comments($id)
    {
        return new JsonResponse($this->damVisibleChildOrFail($this->assetCommentRepository->find((int) $id)));
    }

    public function getUserInfo($id): JsonResponse
    {
        $user = $this->adminRepository->find($id);

        if (! $user) {
            return new JsonResponse([
                'user' => [
                    'id'        => $id,
                    'name'      => trans('dam::app.admin.dam.asset.comments.deleted-user'),
                    'image'     => null,
                    'image_url' => null,
                    'status'    => false,
                ],
                'timezone' => null,
            ]);
        }

        return new JsonResponse([
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'image'     => $user->image,
                'image_url' => $user->image_url,
                'status'    => (bool) $user->status,
            ],
            'timezone' => ['id' => $user?->timezone, 'label' => $user?->timezone],
        ]);
    }

    /**
     * Posts a comment or a reply (P7: with its revision, anchor and mentions). A top-level comment without a revision
     * is about the representative's current one.
     */
    public function commentCreate($id)
    {
        $this->damAuthorizeAsset((int) $id);
        $asset = Asset::query()->findOrFail((int) $id);

        $data = $this->validate(request(), ReviewComments::rules($asset), ReviewComments::messages());
        $author = Auth::guard('admin')->user();

        // A double submit (two identical posts in flight at once) records one comment: whichever request
        // holds the lock posts; an identical one arriving meanwhile is told it already landed. Posting the
        // same words again later is a new comment.
        $fingerprint = sha1(json_encode([$data['comments'] ?? '', $data['parent_id'] ?? null, $data['anchor'] ?? null, $data['asset_file_id'] ?? null, $data['mentions'] ?? []]));
        $lock = Cache::lock('dam:comment-post:'.$author->id.':'.$asset->id.':'.$fingerprint, self::DUPLICATE_POST_WINDOW_SECONDS);

        if (! $lock->get()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.create.duplicate'),
            ], JsonResponse::HTTP_CONFLICT);
        }

        try {
            $comment = $this->reviewComments->post(asset: $asset, author: $author, data: $data);
        } finally {
            $lock->release();
        }

        $comment->load('admin');

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.asset.comments.create.create-success'),
            'comment' => $comment,
        ]);
    }

    /**
     * Resolves or reopens a top-level comment (P7): its author may, and so may anyone who may change the asset. A
     * comment on an asset the caller cannot open, or on another asset than the path names, answers 404.
     */
    public function resolution(Request $request, int $id, int $comment): JsonResponse
    {
        $this->damAuthorizeAsset($id);
        $row = $this->damVisibleChildOrFail($this->assetCommentRepository->find($comment));
        abort_unless((int) $row->dam_asset_id === $id, 404);

        $resolved = $request->validate(['resolved' => ['required', 'boolean']])['resolved'];

        $row = $this->reviewComments->resolve(
            comment: $row,
            actor: Auth::guard('admin')->user(),
            resolved: (bool) $resolved,
            actorMayEdit: bouncer()->hasPermission('dam.asset.update') && $this->damCanEditAsset($id),
        );

        return new JsonResponse([
            'message' => trans($row->resolved_at === null ? 'dam::app.admin.dam.asset.comments.review.reopened' : 'dam::app.admin.dam.asset.comments.review.resolved-success'),
            'comment' => $row->only(['id', 'resolved_at', 'resolved_by']),
        ]);
    }

    public function commentUpdate(): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.asset.comment.update')) {
            return new JsonResponse(['message' => trans('dam::app.admin.errors.401')], 401);
        }

        $this->validate(request(), [
            'id'       => 'required|integer',
            'comments' => 'required|min:2|max:1000',
        ]);

        $id = request('id');
        $comment = $this->damVisibleChildOrFail($this->assetCommentRepository->find((int) $id));

        if ($comment->admin_id !== Auth::id()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.update-failed'),
            ], 403);
        }

        $this->assetCommentRepository->update(['comments' => request('comments')], $id);
        $comment->refresh()->load('admin');

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.asset.comments.updated-success'),
            'comment' => $comment,
        ]);
    }

    public function commentDelete(): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.asset.comment.delete')) {
            return new JsonResponse(['message' => trans('dam::app.admin.errors.401')], 401);
        }

        $id = request('id');
        $comment = $this->assetCommentRepository->find($id);

        // A comment on an asset the caller cannot open answers like a missing one.
        if (! $comment || ! $this->damCanAccessAsset((int) $comment->dam_asset_id)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.delete-failed'),
            ], 404);
        }

        if ($comment->admin_id !== Auth::id()) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.delete-failed'),
            ], 403);
        }

        try {
            $this->assetCommentRepository->delete($id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.delete-success'),
            ]);
        } catch (\Exception $e) {
            report($e);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.comments.delete-failed'),
            ], 500);
        }
    }
}
