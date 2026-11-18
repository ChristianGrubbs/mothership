<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Requests\MassDestroyRequest;
use Mothership\Core\Filesystem\FileStorer;
use Mothership\DAM\DataGrids\Asset\AssetPropertyDataGrid;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Repositories\AssetPropertyRepository;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Rules\UniquePropertyName;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\AssetAccessControl;

class PropertyController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetPropertyRepository $assetPropertyRepository,
        protected FileStorer $fileStorer
    ) {}

    public function properties(int $id)
    {
        $this->damVisibleAssetOrFail($id);

        if (request()->ajax()) {
            return app(AssetPropertyDataGrid::class)->toJson();
        }

        return view('dam::asset.properties.index', compact('id'));
    }

    public function propertiesCreate(int $id)
    {
        $this->damVisibleAssetOrFail($id);

        // A role limited to its own items adds and edits details only on assets it created.
        if ($refusal = $this->ownItemsRefusal($id, 'own-only-details')) {
            return new JsonResponse(['message' => $refusal], 403);
        }

        $messages = [
            'name.required'          => trans('dam::app.admin.validation.property.name.required'),
            'name.unique'            => trans('dam::app.admin.validation.property.name.unique'),
            'sort_order.required_if' => trans('dam::app.admin.validation.property.sort-order.required'),
        ];

        // Validate and write under the asset's row lock, so a concurrent case variant cannot slip past the check.
        return UniquePropertyName::serialized($id, function () use ($id, $messages) {
            $this->validate(request(), [
                'type'          => 'required',
                'language'      => 'required',
                'value'         => 'required|max:1000',
                'is_filterable' => 'sometimes|boolean',
                'sort_order'    => 'required_if:is_filterable,1|integer|min:0|max:9999',
                'name'          => [
                    'required',
                    'min:3',
                    'max:100',
                    // P4a: a field's value row is not a free-text property, so it does not take the name.
                    new UniquePropertyName(assetId: $id, language: (string) request()->get('language')),
                ],
            ], $messages);

            // P4a: a property named after a field is that field's value, typed and checked like any other write.
            if (($typed = app(FieldValues::class)->writeThroughProperty(asset: Asset::query()->findOrFail($id), row: null, name: (string) request('name'), raw: request('value'), source: 'web-property')) !== false) {
                return new JsonResponse([
                    'message' => trans('dam::app.admin.dam.asset.properties.index.create-success'),
                ]);
            }

            $payload = array_merge(request()->only([
                'name',
                'type',
                'language',
                'value',
            ]), [
                'dam_asset_id'  => $id,
                'is_filterable' => request()->boolean('is_filterable'),
                'sort_order'    => (int) request()->input('sort_order', 0),
            ]);

            $this->assetPropertyRepository->create($payload);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.properties.index.create-success'),
            ]);
        });
    }

    public function propertiesEdit(int $id)
    {
        $property = $this->damVisibleChildOrFail($this->assetPropertyRepository->find($id));

        return new JsonResponse($property);
    }

    public function propertiesUpdate()
    {
        $id = request('id');
        $property = $this->damVisibleChildOrFail($this->assetPropertyRepository->find($id));

        if ($refusal = $this->ownItemsRefusal((int) $property->dam_asset_id, 'own-only-details')) {
            return new JsonResponse(['message' => $refusal], 403);
        }

        $messages = [
            'name.unique'            => trans('dam::app.admin.validation.property.name.unique'),
            'sort_order.required_if' => trans('dam::app.admin.validation.property.sort-order.required'),
        ];

        // Validate and write under the asset's row lock, so a concurrent case variant cannot slip past the check.
        return UniquePropertyName::serialized((int) $property->dam_asset_id, function () use ($id, $property, $messages) {
            // The row as it is now, under the lock: its name decides what counts as unchanged.
            $property->refresh();

            $this->validate(request(), [
                'name' => [
                    'required',
                    'min:3',
                    'max:100',
                    new UniquePropertyName(assetId: (int) $property->dam_asset_id, language: (string) $property->language, ignoreId: (int) $id, currentName: (string) $property->name),
                ],
                // The same limit as create (UX eval G2-04).
                'value'         => 'required|max:1000',
                'is_filterable' => 'sometimes|boolean',
                'sort_order'    => 'required_if:is_filterable,1|integer|min:0|max:9999',
            ], $messages);

            if (($typed = app(FieldValues::class)->writeThroughProperty(asset: Asset::query()->findOrFail($property->dam_asset_id), row: $property, name: (string) request('name'), raw: request('value'), source: 'web-property')) !== false) {
                return new JsonResponse([
                    'message' => trans('dam::app.admin.dam.asset.properties.index.update-success'),
                ]);
            }

            $payload = array_merge(
                request()->only(['name', 'value']),
                [
                    'is_filterable' => request()->boolean('is_filterable'),
                    'sort_order'    => (int) request()->input('sort_order', 0),
                ]
            );

            $this->assetPropertyRepository->update($payload, $id);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.properties.index.update-success'),
            ]);
        });
    }

    public function propertiesDestroy()
    {
        $id = request('id');
        $property = $this->damVisibleChildOrFail($this->assetPropertyRepository->find($id));

        // A role limited to its own items clears details only on assets it created.
        if ($refusal = $this->ownItemsRefusal((int) $property->dam_asset_id)) {
            return new JsonResponse(['message' => $refusal], 403);
        }

        try {
            // P5b: deleting a field's value row is a person clearing that field (the delete and the removal are one step).
            app(FieldValues::class)->deleteByPerson(row: $property, actorId: auth()->guard('admin')->id(), delete: fn () => $this->assetPropertyRepository->delete($id));

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.properties.index.delete-success'),
            ], 200);
        } catch (\Exception $e) {
            report($e);
        }

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.asset.properties.index.delete-failed'),
        ], 500);
    }

    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.asset.property.delete'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $assetPropertyIds = $massDestroyRequest->input('indices');

        $skippedForeign = 0;
        $deleted = 0;

        try {
            foreach ($assetPropertyIds as $assetPropertyId) {
                $property = $this->assetPropertyRepository->find($assetPropertyId);

                if (! $property || ! $this->damCanAccessAsset((int) $property->dam_asset_id)) {
                    continue;
                }

                if ($this->ownItemsRefusal((int) $property->dam_asset_id) !== null) {
                    $skippedForeign++;

                    continue;
                }

                Event::dispatch('dam.asset.property.delete.before', $assetPropertyId);

                app(FieldValues::class)->deleteByPerson(row: $property, actorId: auth()->guard('admin')->id(), delete: fn () => $this->assetPropertyRepository->delete($assetPropertyId));

                Event::dispatch('dam.asset.property.delete.after', $assetPropertyId);
                $deleted++;
            }

            if ($skippedForeign > 0) {
                return new JsonResponse([
                    'message' => $deleted > 0
                        ? trans('dam::app.admin.permissions.own-only-mass-delete', ['deleted' => $deleted, 'skipped' => $skippedForeign])
                        : trans('dam::app.admin.permissions.own-only-mass-none', ['skipped' => $skippedForeign]),
                    'skipped' => $skippedForeign,
                ], $deleted > 0 ? 200 : 403);
            }

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.datagrid.mass-delete-success'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /** The plain-language refusal when the signed-in admin may not clear details on `$assetId` under the own-items rule. */
    private function ownItemsRefusal(int $assetId, string $reason = 'own-only-delete'): ?string
    {
        $asset = Asset::query()->find($assetId);

        return $asset === null ? null : app(RoleBuilder::class)->refusal($asset, $reason);
    }
}
