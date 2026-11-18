<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\AutomationSelection;
use Mothership\DAM\Services\Automation\MetadataMappingService;

/**
 * REST twin of Settings → Metadata mappings (P5b, D18), under api.dam.mapping.manage: CRUD, dry run and run-now
 * through MetadataMappingService. A selection is cut to the assets the key's admin may list.
 */
class MetadataMappingController extends Controller
{
    public function __construct(protected MetadataMappingService $mappings) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => MetadataMapping::query()->orderBy('id')->get()->map(fn (MetadataMapping $mapping) => $this->mappings->present($mapping))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $mapping = MetadataMapping::query()->find($id);

        return $mapping ? response()->json(['success' => true, 'data' => $this->mappings->present($mapping)]) : $this->missing();
    }

    public function store(Request $request): JsonResponse
    {
        $mapping = $this->mappings->create(input: $request->all(), actorId: auth()->guard('api')->id());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.mappings.saved'), 'data' => $this->mappings->present($mapping)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $mapping = MetadataMapping::query()->find($id);

        if (! $mapping) {
            return $this->missing();
        }

        $mapping = $this->mappings->update(mapping: $mapping, input: $request->all(), actorId: auth()->guard('api')->id());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.mappings.saved'), 'data' => $this->mappings->present($mapping)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $mapping = MetadataMapping::query()->find($id);

        if (! $mapping) {
            return $this->missing();
        }

        $mapping->delete();

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.mappings.deleted')]);
    }

    public function dryRun(Request $request, int $id): JsonResponse
    {
        $mapping = MetadataMapping::query()->find($id);

        if (! $mapping) {
            return $this->missing();
        }

        $selection = AutomationSelection::resolve($request, AccessContext::api(AccessContext::PURPOSE_LIST));

        return response()->json(['success' => true, 'data' => $this->mappings->dryRun(mapping: $mapping, assetIds: $selection['asset_ids'])]);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        $mapping = MetadataMapping::query()->find($id);

        if (! $mapping) {
            return $this->missing();
        }

        $selection = AutomationSelection::resolve($request, AccessContext::api(AccessContext::PURPOSE_LIST));
        $queued = $this->mappings->run(mapping: $mapping, assetIds: $selection['asset_ids'], actorId: auth()->guard('api')->id());

        return response()->json(['success' => true, 'data' => ['queued' => $queued, 'mapping' => $this->mappings->present($mapping->refresh())]], 202);
    }

    private function missing(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => trans('dam::app.admin.automation.mappings.not-found')], 404);
    }
}
