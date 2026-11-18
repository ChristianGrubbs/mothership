<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\API\Asset\Concerns\ServesOrganizationOverRest;
use Mothership\DAM\Http\Controllers\Organization\LibrarySectionController;

/** P4 (D3, REST): sections, what each holds for the key's admin, and their pins. */
class SectionController extends LibrarySectionController
{
    use ServesOrganizationOverRest;

    public function index(): JsonResponse
    {
        return new JsonResponse(['success' => true, 'data' => $this->sections->list(context: $this->context())]);
    }

    public function assets(Request $request, int $section): JsonResponse
    {
        $this->sections->find($section);

        return $this->listing(request: $request, container: ['section_id' => $section]);
    }
}
