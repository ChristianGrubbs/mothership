<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\User\Models\Admin;

/**
 * P5c (D16): the Similar images page. Lists near-duplicate pairs the admin may see both sides of, filtered to one
 * asset or one folder, and "Not duplicates" for one group or many. Stack opens the Create stack review (the P3b-1 merge
 * dialog) on the group's members, named as a `near_group` review so the server refuses (409) a group that changed;
 * the stack is made by the merge endpoint, with its own checks.
 *
 * A filter naming a hidden asset or folder answers 404 exactly like a missing one.
 */
class SimilarImageController
{
    public const VIEW_GROUPS = 'groups';

    public const VIEW_PAIRS = 'pairs';

    public function __construct(
        private SimilarImages $similar,
        private AssetAccessDecision $access,
        private DirectoryPermissionService $permissions,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize();
        $filters = self::filters(request: $request, access: $this->access, permissions: $this->permissions, context: AccessContext::library(AccessContext::PURPOSE_LIST));
        $page = self::list(
            similar: $this->similar,
            viewer: $this->viewer(),
            context: AccessContext::library(AccessContext::PURPOSE_LIST),
            filters: $filters,
            request: $request,
            // The page itself renders groups only; `view=pairs` is for JSON callers.
            view: $request->expectsJson() ? self::view(request: $request, default: self::VIEW_GROUPS) : self::VIEW_GROUPS,
        );

        if ($request->expectsJson()) {
            return response()->json($page);
        }

        return view('dam::similar.index', ['initial' => [
            ...$page,
            'filters'   => $filters,
            'can_stack' => bouncer()->hasPermission('dam.asset.edit'),
            'can_merge' => bouncer()->hasPermission('dam.asset.edit'),
        ]]);
    }

    /**
     * "Not duplicates" for pairs (legacy keys, as before) and for reviewed groups: each group names its members and is
     * dismissed only while it is still that set (otherwise it is counted as `stale` and left alone).
     */
    public function dismiss(Request $request): JsonResponse
    {
        $this->authorize();
        $data = $request->validate([
            'pairs'              => ['required_without:groups', 'array', 'min:1', 'max:200'],
            'pairs.*'            => ['string', 'max:48'],
            'groups'             => ['required_without:pairs', 'array', 'min:1', 'max:'.SimilarImages::MAX_PER_PAGE],
            'groups.*.key'       => ['required', 'string', 'max:64'],
            'groups.*.members'   => ['required', 'array', 'min:2', 'max:'.SimilarImages::GROUP_MAX_MEMBERS],
            'groups.*.members.*' => ['integer', 'min:1'],
        ]);
        $context = AccessContext::library(AccessContext::PURPOSE_LIST);
        $dismissed = isset($data['pairs']) ? $this->similar->dismiss(viewer: $this->viewer(), context: $context, pairKeys: $data['pairs']) : 0;

        if (! isset($data['groups'])) {
            return response()->json(['dismissed' => $dismissed, 'message' => trans_choice('dam::app.admin.dam.similar.dismissed', $dismissed, ['count' => $dismissed])]);
        }

        $groups = $this->similar->dismissGroups(
            viewer: $this->viewer(),
            context: $context,
            groups: array_map(fn (array $group) => ['key' => (string) $group['key'], 'members' => array_map('intval', $group['members'])], $data['groups']),
        );
        // Nothing was dismissed because every group changed: refuse, so the page reloads what is there now.
        if ($groups['dismissed'] === 0 && $dismissed === 0) {
            SimilarImages::changed();
        }

        return response()->json([
            'dismissed' => $dismissed + $groups['dismissed'],
            'groups'    => $groups['dismissed'],
            'stale'     => $groups['stale'],
            'message'   => trans_choice('dam::app.admin.dam.similar.dismissed-groups', $groups['dismissed'], ['count' => $groups['dismissed']])
                .($groups['stale'] > 0 ? ' '.trans_choice('dam::app.admin.dam.similar.groups-changed', $groups['stale'], ['count' => $groups['stale']]) : ''),
        ]);
    }

    /** `view=groups` (review groups) or `view=pairs` (one row per pair, the REST list's default); anything else is a 422 from filters(). */
    public static function view(Request $request, string $default): string
    {
        return in_array($request->query('view'), [self::VIEW_GROUPS, self::VIEW_PAIRS], true) ? (string) $request->query('view') : $default;
    }

    /**
     * @param  array{asset_id: ?int, folder_id: ?int, asset_name: ?string, folder_name: ?string}  $filters
     * @return array<string, mixed>
     */
    public static function list(SimilarImages $similar, Admin $viewer, AccessContext $context, array $filters, Request $request, string $view, ?int $assetId = null): array
    {
        $page = max(1, (int) $request->query('page', 1));

        return $view === self::VIEW_GROUPS
            ? $similar->groupPage(viewer: $viewer, context: $context, assetId: $assetId ?? $filters['asset_id'], folderId: $filters['folder_id'], page: $page, perPage: (int) $request->query('per_page', SimilarImages::GROUPS_PER_PAGE))
            : $similar->page(viewer: $viewer, context: $context, assetId: $assetId ?? $filters['asset_id'], folderId: $filters['folder_id'], page: $page, perPage: (int) $request->query('per_page', SimilarImages::PER_PAGE));
    }

    /**
     * The `asset` and `folder` filters, each checked through the seam; a hidden one is a 404 like a missing one.
     *
     * @return array{asset_id: ?int, folder_id: ?int, asset_name: ?string, folder_name: ?string}
     */
    public static function filters(Request $request, AssetAccessDecision $access, DirectoryPermissionService $permissions, AccessContext $context): array
    {
        $request->validate(['asset' => ['nullable', 'integer', 'min:1'], 'folder' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'view' => ['nullable', 'in:groups,pairs']]);
        $assetId = $request->filled('asset') ? (int) $request->query('asset') : null;
        $folderId = $request->filled('folder') ? (int) $request->query('folder') : null;
        $assetName = null;
        $folderName = null;

        if ($assetId !== null) {
            $decision = $access->for($context, $assetId);
            abort_unless($decision->visible, 404);
            $assetName = (string) $decision->asset?->file_name;
        }

        if ($folderId !== null) {
            $folder = Directory::query()->where('id', $folderId)->first();
            abort_unless($folder !== null && $permissions->canAccess($folderId), 404);
            $folderName = (string) $folder->name;
        }

        return ['asset_id' => $assetId, 'folder_id' => $folderId, 'asset_name' => $assetName, 'folder_name' => $folderName];
    }

    private function authorize(): void
    {
        abort_unless(bouncer()->hasPermission('dam.duplicates.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    private function viewer(): Admin
    {
        $viewer = auth('admin')->user();
        abort_unless($viewer instanceof Admin, 403);

        return $viewer;
    }
}
