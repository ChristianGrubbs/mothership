<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\RoleBuilder;

class AssetRelationshipController extends Controller
{
    public function __construct(
        protected AssetRelationshipService $relationshipService,
    ) {}

    public function index(int $asset): JsonResponse
    {
        $this->authorizePermission('dam.asset.relationships.index');

        return response()->json($this->relationshipService->relationships($asset));
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        $this->authorizePermission('dam.asset.relationships.store');

        return response()->json([
            'relationship' => $this->relationshipService->create($request, $asset),
        ], 201);
    }

    public function destroy(int $asset, int $relationship): JsonResponse
    {
        $this->authorizePermission('dam.asset.relationships.destroy');

        $this->relationshipService->delete($asset, $relationship);

        return response()->json(['deleted' => true]);
    }

    public function files(int $asset): JsonResponse
    {
        $this->authorizePermission('dam.asset.files.index');

        return response()->json([
            'files' => $this->relationshipService->files($asset),
            ...$this->relationshipService->stack($asset),
            // Renaming, re-roling and re-ordering files on someone else's item is closed to a role limited to its own files.
            'can_edit_details' => app(RoleBuilder::class)->refusal(model: Asset::query()->findOrFail($asset), reason: 'own-only-label') === null,
        ]);
    }

    /** One file's revisions, newest first (P3a S5). Read under the same right as the file list. */
    public function revisions(int $asset, int $attachment): JsonResponse
    {
        $this->authorizePermission('dam.asset.files.index');

        return response()->json(['revisions' => $this->relationshipService->revisions($asset, $attachment)]);
    }

    protected function authorizePermission(string $permission): void
    {
        abort_unless(
            bouncer()->hasPermission($permission),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );
    }
}
