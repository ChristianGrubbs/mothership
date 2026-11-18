<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\Concerns\ManagesEmbeds;
use Mothership\DAM\Services\Access\AccessContext;

/**
 * The Embed dialog on a video or audio asset (P6 D26): list, make and revoke tokenized embeds. A hidden asset answers
 * like a missing one; a visible one needs the `dam.asset.embed` permission. The REST twin is
 * API\Asset\AssetEmbedController, over the same rules.
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
        return AccessContext::library($purpose);
    }

    protected function mayManageEmbeds(): bool
    {
        return (bool) bouncer()->hasPermission('dam.asset.embed');
    }

    protected function mayEditAssets(): bool
    {
        return (bool) bouncer()->hasPermission('dam.asset.edit');
    }

    protected function actorId(): ?int
    {
        $id = auth()->guard('admin')->id();

        return $id === null ? null : (int) $id;
    }
}
