<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\MetadataFields\FieldDefinitions;
use Mothership\DAM\Services\MetadataFields\FieldValues;

/**
 * The Library settings screen for custom metadata fields (P4a, D9), under its own ACL keys
 * (dam.metadata_fields, .create, .update, .delete). Every write goes through FieldDefinitions, as REST does.
 */
class MetadataFieldController extends Controller
{
    public function __construct(
        protected FieldDefinitions $definitions,
        protected FieldValues $values,
    ) {}

    public function index(): View|JsonResponse
    {
        $this->authorize('dam.metadata_fields');

        $fields = $this->fields();

        if (request()->expectsJson()) {
            return response()->json(['fields' => $fields]);
        }

        return view('dam::configuration.metadata-fields', ['initial' => [
            'fields'      => $fields,
            'types'       => MetadataField::TYPES,
            'can_create'  => bouncer()->hasPermission('dam.metadata_fields.create'),
            'can_update'  => bouncer()->hasPermission('dam.metadata_fields.update'),
            'can_delete'  => bouncer()->hasPermission('dam.metadata_fields.delete'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('dam.metadata_fields.create');

        $field = $this->definitions->create($request->all());

        return response()->json(['field' => $this->definition($field), 'message' => trans('dam::app.admin.metadata-fields.saved')], 201);
    }

    public function update(Request $request, int $field): JsonResponse
    {
        $this->authorize('dam.metadata_fields.update');

        $model = $this->definitions->update(MetadataField::query()->findOrFail($field), $request->all());

        return response()->json(['field' => $this->definition($model), 'message' => trans('dam::app.admin.metadata-fields.saved')]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('dam.metadata_fields.update');

        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $this->definitions->reorder($request->input('ids'));

        return response()->json(['fields' => $this->fields()]);
    }

    /**
     * A field with values needs `confirm: true`; without it the answer is 409 and the value count, and nothing changes.
     */
    public function destroy(Request $request, int $field): JsonResponse
    {
        $this->authorize('dam.metadata_fields.delete');

        $model = MetadataField::query()->findOrFail($field);
        $result = $this->definitions->delete(field: $model, confirmed: $request->boolean('confirm'));

        if (! $result['deleted'] && ! $result['archived']) {
            // The number shown is what this admin may see; hidden assets still count towards archiving.
            $visible = $this->counts()[$model->id] ?? 0;

            return response()->json([
                'requires_confirmation' => true,
                'values'                => $visible,
                'message'               => trans_choice('dam::app.admin.metadata-fields.delete.confirm', $visible, ['count' => $visible]),
            ], 409);
        }

        return response()->json($result + [
            'message' => trans($result['archived'] ? 'dam::app.admin.metadata-fields.delete.archived' : 'dam::app.admin.metadata-fields.delete.deleted'),
        ]);
    }

    public function restore(int $field): JsonResponse
    {
        $this->authorize('dam.metadata_fields.update');

        $model = $this->definitions->restore(MetadataField::query()->whereNotNull('archived_at')->findOrFail($field));

        return response()->json(['field' => $this->definition($model), 'message' => trans('dam::app.admin.metadata-fields.restored')]);
    }

    /** Active fields in order, then archived ones, each with how many assets the admin may see carry it. */
    private function fields(): array
    {
        $counts = $this->counts();

        return MetadataField::query()
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')->ordered()->get()
            ->map(fn (MetadataField $field) => $this->definition($field, $counts))->values()->all();
    }

    /** @param  array<int, int>|null  $counts */
    private function definition(MetadataField $field, ?array $counts = null): array
    {
        return $field->toDefinition() + ['values_count' => ($counts ?? $this->counts())[$field->id] ?? 0];
    }

    /** @return array<int, int> */
    private function counts(): array
    {
        return $this->values->visibleValueCounts(AccessContext::library(AccessContext::PURPOSE_LIST));
    }

    private function authorize(string $permission): void
    {
        abort_unless(bouncer()->hasPermission($permission), 403, trans('dam::app.admin.permissions.unauthorized'));
    }
}
