<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Organization\VisibleAssets;

/**
 * Which assets a dry run or a run-now covers (P5b): `asset_ids` (a selection) or `directory_id` (a folder and
 * everything under it), cut to the assets the requester may list. A hidden asset drops out exactly like a missing
 * one, so a dry run never shows the requester an asset they cannot see. Rule actions are then decided with the
 * rule owner's rights, asset by asset.
 */
final class AutomationSelection
{
    public const MAX_IDS = 500;

    public const MAX_FOLDERS = 2000;

    /**
     * The folders the signed-in admin may view, in tree order, each with its path, for the run-now and scope pickers
     * (the access model's own answer: under grants, a granted folder and, for navigation, its parents). A path names
     * only folders in that set.
     *
     * @return list<array{id: int, path: string}>
     */
    public static function folders(): array
    {
        $permissions = app(DirectoryPermissionService::class);
        $bypass = $permissions->bypass();
        $viewable = $bypass ? [] : array_flip($permissions->viewableIds());
        $stack = [];
        $folders = [];

        foreach (DB::table('dam_directories')->orderBy('_lft')->get(['id', 'name', '_lft', '_rgt']) as $row) {
            while ($stack !== [] && end($stack)['rgt'] < (int) $row->_lft) {
                array_pop($stack);
            }

            $visible = $bypass || isset($viewable[(int) $row->id]);
            $stack[] = ['rgt' => (int) $row->_rgt, 'name' => $visible ? (string) $row->name : null];

            if ($visible) {
                $folders[] = ['id' => (int) $row->id, 'path' => implode(' / ', array_filter(array_column($stack, 'name'), fn ($name) => $name !== null))];
            }

            if (count($folders) >= self::MAX_FOLDERS) {
                break;
            }
        }

        return $folders;
    }

    /** @return array{asset_ids: list<int>, directory_id: int|null} */
    public static function resolve(Request $request, AccessContext $context): array
    {
        $request->validate([
            'asset_ids'    => ['required_without:directory_id', 'array', 'max:'.self::MAX_IDS],
            'asset_ids.*'  => ['integer', 'min:1'],
            'directory_id' => ['required_without:asset_ids', 'nullable', 'integer', 'min:1'],
        ]);

        $directoryId = $request->filled('directory_id') ? (int) $request->input('directory_id') : null;
        $ids = $directoryId !== null
            ? app(FolderScope::class)->assetIds($directoryId)
            : array_map('intval', (array) $request->input('asset_ids', []));

        $visible = [];

        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            array_push($visible, ...VisibleAssets::filter($chunk, $context));
        }

        if ($directoryId === null && $visible === [] && $ids !== []) {
            throw ValidationException::withMessages(['asset_ids' => [trans('dam::app.admin.automation.validation.selection')]]);
        }

        return ['asset_ids' => $visible, 'directory_id' => $directoryId];
    }
}
