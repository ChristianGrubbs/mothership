<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\AssetRelationshipService;

class AssetRelationshipController extends Controller
{
    public function __construct(
        protected AssetRelationshipService $relationshipService,
    ) {}

    public function index(int $asset): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->relationshipService->relationships($asset),
        ]);
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'relationship' => $this->relationshipService->create($request, $asset),
            ],
        ], 201);
    }

    public function destroy(int $asset, int $relationship): JsonResponse
    {
        $this->relationshipService->delete($asset, $relationship);

        return response()->json([
            'success' => true,
            'data'    => ['deleted' => true],
        ]);
    }

    public function files(int $asset): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'files' => $this->relationshipService->files($asset),
            ],
        ]);
    }
}
