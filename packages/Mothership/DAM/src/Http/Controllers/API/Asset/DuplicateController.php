<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\Asset\DuplicateController as LibraryDuplicateController;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\DAM\Services\Duplicates\StackSuggestions;
use Mothership\User\Models\Admin;

/**
 * P3b-2 REST twins: "Stack instead" for the notice a REST upload answers with, and the stack suggestions list. The
 * api.scope middleware checks each route's scope; access to each entry goes through the seam as in the Library,
 * so a hidden entry answers 404 exactly like a missing one.
 */
class DuplicateController extends Controller
{
    public function stackInstead(Request $request, int $asset, StackInstead $stacker): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
            'role'      => ['nullable', 'string', Rule::in(AssetFile::ATTACHABLE_ROLES)],
        ]);

        $result = $stacker->stack(
            uploadId: $asset,
            targetId: (int) $data['target_id'],
            via: AccessContext::VIA_API,
            role: $data['role'] ?? StackInstead::DEFAULT_ROLE,
            actor: $this->viewer(),
        );

        return response()->json(['success' => true, 'data' => LibraryDuplicateController::payload(result: $result)]);
    }

    public function suggestions(StackSuggestions $suggestions): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'suggestions' => $suggestions->for(viewer: $this->viewer(), context: AccessContext::api(AccessContext::PURPOSE_LIST)),
        ]]);
    }

    private function viewer(): Admin
    {
        $viewer = auth('api')->user();
        abort_unless($viewer instanceof Admin, 403);

        return $viewer;
    }
}
