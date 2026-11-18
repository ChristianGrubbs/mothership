<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Related\RelatedAssets;

/** REST twin of the asset page's Related section (P4 D14): the same ranking, rules and body. */
class RelatedAssetController extends Controller
{
    public function __construct(
        protected RelatedAssets $related,
    ) {}

    public function index(Request $request, int $asset): JsonResponse
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:'.(int) config('dam-related.max_limit', 24)]);

        // A hidden, trashed or unavailable seed answers like a missing one.
        $seed = app(AssetAccessDecision::class)->for(AccessContext::api(), $asset);
        abort_unless($seed->visible, 404);

        return response()->json([
            'success' => true,
            'data'    => ['related' => $this->related->for(seed: $seed->asset, via: AccessContext::VIA_API, limit: $request->integer('limit') ?: null)],
        ]);
    }
}
