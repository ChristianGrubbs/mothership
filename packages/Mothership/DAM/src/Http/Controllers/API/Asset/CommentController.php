<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Repositories\AssetCommentsRepository;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Services\Review\ReviewComments;
use Mothership\DAM\Traits\ApiKeyPermission;
use Mothership\DAM\Traits\AssetAccessControl;

class CommentController extends Controller
{
    use ApiKeyPermission;
    use AssetAccessControl;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetCommentsRepository $assetCommentRepository,
        protected ReviewComments $reviewComments,
    ) {}

    public function comments(int $id): JsonResponse
    {

        $comment = $this->assetCommentRepository->find($id);

        // A row on an asset the caller cannot open answers like a missing row (hidden equals missing).
        if (! $comment || ! $this->damCanAccessAsset((int) $comment->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.not-found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $comment,
        ], 200);
    }

    public function update(int $id)
    {
        $comment = $this->assetCommentRepository->find($id);

        if (! $comment || ! $this->damCanAccessAsset((int) $comment->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.not-found'),
            ], 404);
        }

        if ($comment->admin_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.update-failed'),
            ], 403);
        }

        try {
            $comment = $this->assetCommentRepository->update(request()->only([
                'comments',
            ]), $id);

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.asset.comments.updated-success'),
                'data'    => $comment,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.update-failed'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function delete(int $id)
    {
        $comment = $this->assetCommentRepository->find($id);

        if (! $comment || ! $this->damCanAccessAsset((int) $comment->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.not-found'),
            ], 404);
        }

        if ($comment->admin_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.delete-failed'),
            ], 403);
        }

        try {
            $comment = $this->assetCommentRepository->delete($id);

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.asset.comments.delete-success'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.delete-failed'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Creates a comment or a reply with the review fields the Comments tab writes (P7): asset_file_id, anchor and
     * mentions, through the same writer and rules.
     */
    public function createComment(Request $request)
    {
        if ($request->input('parent_id') === 'null') {
            $request->merge(['parent_id' => null]);
        }

        $this->validate($request, [
            'comments'     => 'required|min:2|max:1000',
            // VisibleAsset, not `exists`: a hidden asset fails exactly as a missing one does.
            'dam_asset_id' => ['required', 'integer', new VisibleAsset],
        ], [
            'comments.required'     => trans('dam::app.admin.validation.comment.required'),
            'dam_asset_id.required' => trans('dam::app.admin.validation.asset.required'),
        ]);

        $this->damAuthorizeAsset((int) $request->input('dam_asset_id'));
        $asset = Asset::query()->findOrFail((int) $request->input('dam_asset_id'));
        $data = $this->validate($request, ReviewComments::rules($asset), ReviewComments::messages());

        try {
            $comment = $this->reviewComments->post(asset: $asset, author: Auth::user(), data: $data);

            return response()->json([
                'message' => trans('dam::app.admin.dam.asset.comments.create.create-success'),
                'comment' => $comment->fresh(),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => trans('dam::app.admin.dam.asset.comments.create.create-failure'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolves or reopens a top-level comment (P7). Its author may, and so may a key that may update assets; a
     * comment on an asset the key cannot open answers 404, like a missing one.
     */
    public function resolution(Request $request, int $id): JsonResponse
    {
        $comment = $this->assetCommentRepository->find($id);

        if (! $comment || ! $this->damCanAccessAsset((int) $comment->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.comments.not-found'),
            ], 404);
        }

        $resolved = (bool) $request->validate(['resolved' => ['required', 'boolean']])['resolved'];
        $comment = $this->reviewComments->resolve(
            comment: $comment,
            actor: Auth::user(),
            resolved: $resolved,
            actorMayEdit: $this->apiKeyAllows('api.dam.assets.update'),
        );

        return response()->json([
            'success' => true,
            'message' => trans($resolved ? 'dam::app.admin.dam.asset.comments.review.resolved-success' : 'dam::app.admin.dam.asset.comments.review.reopened'),
            'data'    => $comment->fresh(),
        ]);
    }
}
