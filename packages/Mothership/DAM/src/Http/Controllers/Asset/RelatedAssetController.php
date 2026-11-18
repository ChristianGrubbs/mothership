<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Related\RelatedAssets;

/**
 * The asset page's Related section (P4 D14), loaded after the page. A seed the viewer cannot open answers exactly as
 * a missing one does; the ranking itself only ever looks among what the viewer may list.
 */
class RelatedAssetController extends Controller
{
    public function __construct(
        protected RelatedAssets $related,
    ) {}

    public function index(Request $request, int $asset): JsonResponse
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:'.(int) config('dam-related.max_limit', 24)]);

        $seed = app(AssetAccessDecision::class)->for(AccessContext::library(), $asset);
        abort_unless($seed->visible, 404);

        return response()->json([
            'success' => true,
            'data'    => ['related' => $this->related->for(seed: $seed->asset, via: AccessContext::VIA_LIBRARY, limit: $request->integer('limit') ?: null)],
        ]);
    }
}
