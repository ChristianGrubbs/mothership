<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Organization\VisibleAssets;

/**
 * P4: what every Library organization endpoint shares, on the web and over REST. The web reads through the Library's
 * listing context and needs `dam.asset.view` (the route ACL adds dam.labels.* and dam.sections.* where they apply);
 * REST reads through the API listing context, gated by the route's API permission (api.scope) instead.
 */
abstract class OrganizationController extends Controller
{
    protected function context(): AccessContext
    {
        return AccessContext::library(AccessContext::PURPOSE_LIST);
    }

    protected function authorizeLibrary(): void
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    protected function viewerId(): ?int
    {
        return VisibleAssets::viewerId();
    }

    /** @return array<int, int> */
    protected function assetIds(Request $request, string $key = 'asset_ids'): array
    {
        return array_values(array_unique(array_map('intval', (array) $request->input($key, []))));
    }

    /** The asset-id list rule every add, remove and pin request uses. */
    protected static function assetIdsRules(int $max = 500): array
    {
        return ['asset_ids' => 'required|array|min:1|max:'.$max, 'asset_ids.*' => 'integer|min:1'];
    }
}
