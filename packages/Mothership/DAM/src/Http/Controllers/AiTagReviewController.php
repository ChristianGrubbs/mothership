<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Tags\AiTagReview;

/**
 * P5c (D8): the AI tag review page. Open AI suggestions across every asset the reviewer may list, grouped by term or
 * by asset, accepted or dismissed one at a time or in bulk through AiTagReview, the asset page's code path. Needs
 * dam.asset.tag, the permission the asset page's accept and dismiss need.
 */
class AiTagReviewController
{
    public function __construct(
        private AiTagReview $review,
        private DirectoryPermissionService $permissions,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize();
        $filters = self::filters(request: $request, permissions: $this->permissions);
        $queue = $this->review->queue(
            context: AccessContext::library(AccessContext::PURPOSE_LIST),
            group: $filters['group'],
            term: $filters['term'],
            folderId: $filters['folder_id'],
            page: max(1, (int) $request->query('page', 1)),
        );

        if ($request->expectsJson()) {
            return response()->json($queue);
        }

        return view('dam::ai-review.index', ['initial' => [...$queue, 'filters' => $filters, 'bulk_max' => AiTagReview::BULK_MAX]]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->authorize();
        $data = self::validateBulk($request);
        $result = $this->review->bulk(action: $data['action'], items: $data['items'], context: AccessContext::library(AccessContext::PURPOSE_LIST), actorId: auth('admin')->id());

        $message = trans_choice('dam::app.admin.ai-review.done.'.$data['action'], $result['done'], ['count' => $result['done']]);

        if ($result['not_yours'] > 0) {
            $message = $result['done'] > 0
                ? trans('dam::app.admin.permissions.own-only-mass-details', ['updated' => $result['done'], 'skipped' => $result['not_yours']])
                : trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => $result['not_yours']]);
        }

        return response()->json([...$result, 'message' => $message]);
    }

    /** @return array{action: string, items: list<array{asset_id: int, term: string}>} */
    public static function validateBulk(Request $request): array
    {
        $data = $request->validate([
            'action'           => ['required', 'string', Rule::in(['accept', 'dismiss'])],
            'items'            => ['required', 'array', 'min:1', 'max:'.AiTagReview::BULK_MAX],
            'items.*.asset_id' => ['required', 'integer', 'min:1'],
            'items.*.term'     => ['required', 'string', 'max:64'],
        ]);

        return ['action' => $data['action'], 'items' => array_map(fn (array $item) => ['asset_id' => (int) $item['asset_id'], 'term' => (string) $item['term']], $data['items'])];
    }

    /**
     * The group, term and folder filters. A hidden folder answers 404 like a missing one; an unknown or disabled term
     * just lists nothing.
     *
     * @return array{group: string, term: ?string, folder_id: ?int, folder_name: ?string}
     */
    public static function filters(Request $request, DirectoryPermissionService $permissions): array
    {
        $request->validate([
            'group'  => ['nullable', 'string', Rule::in([AiTagReview::GROUP_TERM, AiTagReview::GROUP_ASSET])],
            'term'   => ['nullable', 'string', 'max:64'],
            'folder' => ['nullable', 'integer', 'min:1'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ]);
        $folderId = $request->filled('folder') ? (int) $request->query('folder') : null;
        $folderName = null;

        if ($folderId !== null) {
            $folder = Directory::query()->where('id', $folderId)->first();
            abort_unless($folder !== null && $permissions->canAccess($folderId), 404);
            $folderName = (string) $folder->name;
        }

        return [
            'group'       => (string) $request->query('group', AiTagReview::GROUP_TERM),
            'term'        => $request->filled('term') ? (string) $request->query('term') : null,
            'folder_id'   => $folderId,
            'folder_name' => $folderName,
        ];
    }

    private function authorize(): void
    {
        abort_unless(bouncer()->hasPermission('dam.asset.tag'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }
}
