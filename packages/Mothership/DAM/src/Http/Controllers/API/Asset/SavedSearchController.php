<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\API\Asset\Concerns\ServesOrganizationOverRest;
use Mothership\DAM\Http\Controllers\Organization\LibrarySavedSearchController;

/** P4 (D12, REST): saved searches (the key's admin's own and shared ones) and re-running one under current access. */
class SavedSearchController extends LibrarySavedSearchController
{
    use ServesOrganizationOverRest;

    public function index(): JsonResponse
    {
        return new JsonResponse(['success' => true, 'data' => $this->searches->list($this->viewerId())]);
    }

    /** Re-runs the stored definition for the key's admin now; page and per_page are the only request parameters. */
    public function assets(Request $request, int $search): JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:250']);
        $model = $this->searches->findVisible(id: $search, viewerId: $this->viewerId());

        return $this->run(params: is_array($model->definition) ? $model->definition : [], request: $request, stored: true);
    }
}
