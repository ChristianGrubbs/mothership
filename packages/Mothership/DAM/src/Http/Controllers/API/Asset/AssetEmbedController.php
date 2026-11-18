<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\Concerns\ManagesEmbeds;
use Mothership\DAM\Services\Access\AccessContext;

/**
 * REST twin of the Library's Embed dialog (P6 D26): the same rules through ManagesEmbeds, asked as the bearer. The
 * api.scope middleware has already checked the route's api.dam.assets.embeds* permission.
 */
class AssetEmbedController extends Controller
{
    use ManagesEmbeds;

    public function index(int $asset): JsonResponse
    {
        return $this->listEmbeds(assetId: $asset);
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        return $this->createEmbed(request: $request, assetId: $asset);
    }

    public function revoke(int $asset, int $embed): JsonResponse
    {
        return $this->revokeEmbed(assetId: $asset, embedId: $embed);
    }

    protected function embedContext(string $purpose): AccessContext
    {
        return AccessContext::api($purpose);
    }

    protected function mayManageEmbeds(): bool
    {
        return true;
    }

    protected function mayEditAssets(): bool
    {
        return true;
    }

    protected function actorId(): ?int
    {
        $id = auth()->guard('api')->id();

        return $id === null ? null : (int) $id;
    }
}
