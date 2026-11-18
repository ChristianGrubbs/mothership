<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\API\Asset\Concerns\ServesOrganizationOverRest;
use Mothership\DAM\Http\Controllers\Organization\OrganizationController;

/**
 * P4 (REST parity): the Library listing with its filters and sorts (search, media type, folder scope, the filter
 * drawer, filter_labels with filter_labels_mode, filter_favorites, filter_orientation, label_id or section_id, and the
 * position and popularity sorts), through the access seam for the key's admin.
 */
class LibraryController extends OrganizationController
{
    use ServesOrganizationOverRest;

    public function assets(Request $request): JsonResponse
    {
        $params = $request->query();
        $container = array_filter([
            'label_id'   => $params['label_id'] ?? null,
            'section_id' => $params['section_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        validator($container, ['label_id' => 'nullable|integer|min:1', 'section_id' => 'nullable|integer|min:1|prohibits:label_id'])->validate();

        return $this->listing(request: $request, container: array_map('intval', $container));
    }
}
