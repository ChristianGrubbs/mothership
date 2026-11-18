<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\MetadataFields\FieldDefinitions;
use Mothership\DAM\Services\MetadataFields\FieldValues;

/**
 * REST field definitions (P4a, D9). Reading needs only the asset-listing scope; create, update and delete have their
 * own scopes and go through FieldDefinitions, as the Library settings screen does.
 */
class MetadataFieldController extends Controller
{
    public function __construct(protected FieldDefinitions $definitions) {}

    /** Active fields in order; `include_archived=1` adds archived ones after them. */
    public function index(Request $request): JsonResponse
    {
        $fields = MetadataField::query()
            ->when(! $request->boolean('include_archived'), fn ($query) => $query->active())
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')->ordered()->get();

        return response()->json(['success' => true, 'data' => $fields->map->toDefinition()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $field = $this->definitions->create($request->all());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.metadata-fields.saved'), 'data' => $field->toDefinition()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $field = MetadataField::query()->find($id);

        if (! $field) {
            return $this->missing();
        }

        $field = $this->definitions->update($field, $request->all());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.metadata-fields.saved'), 'data' => $field->toDefinition()]);
    }

    /** A field with values answers 409 unless `confirm=true`, which archives it and keeps the values. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $field = MetadataField::query()->find($id);

        if (! $field) {
            return $this->missing();
        }

        $result = $this->definitions->delete(field: $field, confirmed: $request->boolean('confirm'));

        if (! $result['deleted'] && ! $result['archived']) {
            // The number shown is what this key may list; hidden assets still count towards archiving.
            $visible = app(FieldValues::class)->visibleValueCounts(AccessContext::api(AccessContext::PURPOSE_LIST))[$field->id] ?? 0;

            return response()->json([
                'success'               => false,
                'requires_confirmation' => true,
                'values'                => $visible,
                'message'               => trans_choice('dam::app.admin.metadata-fields.delete.confirm', $visible, ['count' => $visible]),
            ], 409);
        }

        return response()->json(['success' => true] + $result + [
            'message' => trans($result['archived'] ? 'dam::app.admin.metadata-fields.delete.archived' : 'dam::app.admin.metadata-fields.delete.deleted'),
        ]);
    }

    private function missing(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => trans('dam::app.admin.metadata-fields.not-found')], 404);
    }
}
