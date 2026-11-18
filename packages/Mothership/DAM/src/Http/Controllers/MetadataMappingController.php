<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Automation\AutomationSelection;
use Mothership\DAM\Services\Automation\MappingTransforms;
use Mothership\DAM\Services\Automation\MetadataMappingService;
use Mothership\DAM\Services\Automation\ValueWriter;

/**
 * Settings → Library → Metadata mappings (P5b, D18), under dam.mapping.manage. Every write goes through
 * MetadataMappingService, as REST does. A dry run takes the editor's unsaved draft, so the effect shows before
 * anything is saved; a run-now queues the saved mapping over a folder or a selection.
 */
class MetadataMappingController extends Controller
{
    public function __construct(protected MetadataMappingService $mappings) {}

    public function index(): View|JsonResponse
    {
        $this->authorize();
        $list = MetadataMapping::query()->orderBy('name')->orderBy('id')->get()->map(fn (MetadataMapping $mapping) => $this->mappings->present($mapping))->values()->all();

        if (request()->expectsJson()) {
            return response()->json(['mappings' => $list]);
        }

        return view('dam::configuration.metadata-mappings', ['initial' => [
            'mappings'   => $list,
            'targets'    => MetadataMapping::TARGETS,
            'modes'      => ValueWriter::MODES,
            'transforms' => MappingTransforms::TYPES,
            'fields'     => MetadataField::query()->active()->ordered()->get(['id', 'code', 'label', 'type'])->toArray(),
            'labels'     => Label::query()->where('kind', Label::KIND_SHARED)->orderBy('name')->get(['id', 'name'])->toArray(),
            'folders'    => AutomationSelection::folders(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize();
        $mapping = $this->mappings->create(input: $request->all(), actorId: auth()->guard('admin')->id());

        return response()->json(['mapping' => $this->mappings->present($mapping), 'message' => trans('dam::app.admin.automation.mappings.saved')], 201);
    }

    public function update(Request $request, int $mapping): JsonResponse
    {
        $this->authorize();
        $model = $this->mappings->update(mapping: MetadataMapping::query()->findOrFail($mapping), input: $request->all(), actorId: auth()->guard('admin')->id());

        return response()->json(['mapping' => $this->mappings->present($model), 'message' => trans('dam::app.admin.automation.mappings.saved')]);
    }

    public function destroy(int $mapping): JsonResponse
    {
        $this->authorize();
        MetadataMapping::query()->findOrFail($mapping)->delete();

        return response()->json(['message' => trans('dam::app.admin.automation.mappings.deleted')]);
    }

    /** The key picker: the raw ExifTool keys of one asset the admin may see, each with a sample value. */
    public function keys(Request $request): JsonResponse
    {
        $this->authorize();
        $request->validate(['asset_id' => ['required', 'integer', 'min:1']]);
        $asset = app(AssetAccessDecision::class)->for(context: AccessContext::library(), asset: (int) $request->input('asset_id'))->visibleOrFail();

        return response()->json(['asset_id' => (int) $asset->id, 'name' => (string) $asset->file_name, 'keys' => $this->mappings->keys($asset)]);
    }

    /** The dry run of the editor's draft (or of the saved mapping, by id) over a selection. Writes nothing. */
    public function dryRun(Request $request): JsonResponse
    {
        $this->authorize();
        $saved = $request->filled('id') ? MetadataMapping::query()->findOrFail((int) $request->input('id')) : null;
        $draft = new MetadataMapping($this->mappings->validate($request->input('mapping', $saved?->toArray() ?? [])));
        $draft->id = $saved?->id ?? 0;
        $selection = AutomationSelection::resolve($request, AccessContext::library(AccessContext::PURPOSE_LIST));

        return response()->json(['assets' => $this->mappings->dryRun(mapping: $draft, assetIds: $selection['asset_ids'])]);
    }

    public function run(Request $request, int $mapping): JsonResponse
    {
        $this->authorize();
        $model = MetadataMapping::query()->findOrFail($mapping);
        $selection = AutomationSelection::resolve($request, AccessContext::library(AccessContext::PURPOSE_LIST));
        $queued = $this->mappings->run(mapping: $model, assetIds: $selection['asset_ids'], actorId: auth()->guard('admin')->id());

        return response()->json([
            'queued'  => $queued,
            'mapping' => $this->mappings->present($model->refresh()),
            'message' => trans_choice('dam::app.admin.automation.mappings.run-queued', $queued, ['count' => $queued]),
        ], 202);
    }

    private function authorize(): void
    {
        abort_unless(bouncer()->hasPermission('dam.mapping.manage'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }
}
