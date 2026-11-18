<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\API\Asset\Concerns\ServesOrganizationOverRest;
use Mothership\DAM\Http\Controllers\Organization\LibraryLabelController;

/** P4 (D4, REST): shared labels, their members and their manual order. */
class LabelController extends LibraryLabelController
{
    use ServesOrganizationOverRest;

    public function index(Request $request): JsonResponse
    {
        $tree = $this->labels->tree(viewerId: $this->viewerId(), kind: $this->kind($request), context: $this->context());

        return new JsonResponse(['success' => true, 'data' => $tree]);
    }

    public function assets(Request $request, int $label): JsonResponse
    {
        $this->find(request: $request, id: $label);

        return $this->listing(request: $request, container: ['label_id' => $label]);
    }
}
