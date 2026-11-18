<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\ProcessingBatch;
use Mothership\DAM\Processing\BatchRequest;
use Mothership\DAM\Processing\BatchService;
use Mothership\DAM\Processing\RecipeRegistry;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\User\Models\Admin;

/**
 * The Library's Process dialog and the viewer's Export menu (P6a): list the recipes, start a batch, read its
 * progress, retry a failed item, cancel what has not started. The route ACL is dam.asset.process; each item is
 * decided by the access seam for the signed-in admin, and a batch is readable only by whoever started it.
 */
class ProcessingBatchController extends Controller
{
    public function __construct(
        protected BatchService $batches,
        protected RecipeRegistry $recipes,
    ) {}

    public function recipes(): JsonResponse
    {
        return response()->json(['recipes' => $this->recipes->describe(), 'max_items' => BatchService::maxItems()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(BatchService::rules($this->recipes));
        $batch = $this->batches->create(new BatchRequest(
            requester: $this->admin(),
            via: AccessContext::VIA_LIBRARY,
            recipe: $data['recipe'],
            params: $data['params'] ?? [],
            assetIds: $data['asset_ids'],
            saveAs: $data['save_as'] ?? ProcessingBatch::SAVE_ATTACH,
        ));

        return response()->json(['batch' => $this->batches->present(batch: $batch, viewer: $this->admin())], 201);
    }

    public function show(int $batch): JsonResponse
    {
        $model = $this->batches->ownedBy(batchId: $batch, viewer: $this->admin());

        return response()->json(['batch' => $this->batches->present(batch: $model, viewer: $this->admin())]);
    }

    public function retry(int $batch, int $item): JsonResponse
    {
        $model = $this->batches->retry(batch: $this->batches->ownedBy(batchId: $batch, viewer: $this->admin()), itemId: $item);

        return response()->json(['batch' => $this->batches->present(batch: $model, viewer: $this->admin())]);
    }

    public function cancel(int $batch): JsonResponse
    {
        $model = $this->batches->cancel(batch: $this->batches->ownedBy(batchId: $batch, viewer: $this->admin()));

        return response()->json(['batch' => $this->batches->present(batch: $model, viewer: $this->admin())]);
    }

    private function admin(): Admin
    {
        /** @var Admin */
        return auth()->guard('admin')->user();
    }
}
