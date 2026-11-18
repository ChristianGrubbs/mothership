<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Mothership\DAM\Http\Controllers\Asset\AssetMergeController;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\User\Models\Admin;

/**
 * REST twin of the merge actions (P3b-1, D7): the same preview, merge, split, finalize and link revoke, through the
 * same MergeGate and AssetMergeService, so both surfaces apply one rule. As on the other REST mutations, the
 * api.scope middleware checks the key's permission per route in place of bouncer(), which reads only the admin
 * guard; folder access, sharing authority and nesting are checked per asset exactly as in the admin UI.
 */
class MergeController extends AssetMergeController
{
    protected function mayEdit(): bool
    {
        return true;
    }

    protected function actor(): Admin
    {
        return auth()->guard('api')->user();
    }

    protected function via(): string
    {
        return AccessContext::VIA_API;
    }

    /** @param  array<string, mixed>  $data */
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
