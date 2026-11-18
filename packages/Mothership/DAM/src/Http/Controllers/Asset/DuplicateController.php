<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\User\Models\Admin;

/**
 * P3b-2 (D16 exact): the upload notice's "Stack instead" in the Library. The new entry `{asset}` joins `target_id`
 * as an attachment and goes to the Trash. Both must be entries the admin may change; either one hidden answers
 * 404 exactly like a missing id, and a visible one the admin may not change answers 403.
 */
class DuplicateController
{
    public function stackInstead(Request $request, int $asset, StackInstead $stacker): JsonResponse
    {
        abort_unless(StackInstead::allowedInLibrary(), 403, trans('dam::app.admin.permissions.unauthorized'));
        $data = $request->validate([
            // Not `exists`: a missing target must answer exactly like a hidden one.
            'target_id' => ['required', 'integer'],
            'role'      => ['nullable', 'string', Rule::in(AssetFile::ATTACHABLE_ROLES)],
        ]);

        $result = $stacker->stack(
            uploadId: $asset,
            targetId: (int) $data['target_id'],
            via: AccessContext::VIA_LIBRARY,
            role: $data['role'] ?? StackInstead::DEFAULT_ROLE,
            actor: self::actor('admin'),
        );

        return response()->json(self::payload(result: $result));
    }

    /** The signed-in admin of `$guard`. */
    public static function actor(string $guard): Admin
    {
        $actor = auth($guard)->user();
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }

    /** @param  array{file: AssetFile, target: Asset, trashed_asset_id: int}  $result */
    public static function payload(array $result): array
    {
        $target = $result['target'];

        return [
            'stacked'          => true,
            'target'           => ['id' => (int) $target->id, 'name' => (string) $target->file_name],
            'file'             => app(AssetRelationshipService::class)->formatFile($result['file']->load('attachment', 'fileObject'), $target),
            'trashed_asset_id' => $result['trashed_asset_id'],
            'message'          => trans('dam::app.admin.dam.duplicates.stacked', ['name' => $target->file_name]),
        ];
    }
}
