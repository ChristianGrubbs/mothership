<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Models\AutomationRun;
use Mothership\DAM\Models\AutomationRunAction;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\Automation\AutomationSelection;
use Mothership\DAM\Services\Automation\RuleConditions;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\Organization\VisibleAssets;

/**
 * Settings → Library → Automation rules (P5b, D19), under dam.automation.manage. A new rule belongs to the admin who
 * creates it and runs with their rights. A dry run takes the editor's unsaved draft; run-now queues the saved rule
 * over a folder or a selection; the run log lists each run and, per run, every action's outcome on the assets the
 * viewer may see.
 */
class AutomationRuleController extends Controller
{
    public function __construct(protected AutomationEngine $engine) {}

    public function index(): View|JsonResponse
    {
        $this->authorize();
        $rules = AutomationRule::query()->orderBy('name')->orderBy('id')->get()->map(fn (AutomationRule $rule) => $this->engine->present(rule: $rule, viewer: AccessContext::library(AccessContext::PURPOSE_LIST)))->values()->all();

        if (request()->expectsJson()) {
            return response()->json(['rules' => $rules]);
        }

        return view('dam::configuration.automation-rules', ['initial' => [
            'rules'           => $rules,
            'triggers'        => AutomationRule::TRIGGERS,
            'condition_types' => RuleConditions::TYPES,
            'field_ops'       => RuleConditions::FIELD_OPS,
            'exif_ops'        => RuleConditions::EXIF_OPS,
            'action_types'    => AutomationEngine::ACTION_TYPES,
            'fields'          => MetadataField::query()->active()->ordered()->get(['id', 'code', 'label', 'type'])->toArray(),
            'labels'          => Label::query()->where('kind', Label::KIND_SHARED)->orderBy('name')->get(['id', 'name'])->toArray(),
            'sections'        => SavedSearch::query()->where('kind', SavedSearch::KIND_SECTION)->orderBy('position')->get(['id', 'name'])->toArray(),
            'folders'         => AutomationSelection::folders(),
            // Only collections the editor manages: a rule runs with its owner's rights, so others would be refused.
            'collections'     => app(CollectionService::class)->managedBy(auth()->guard('admin')->user())->map(fn ($collection) => ['id' => (int) $collection->id, 'name' => $collection->name])->values()->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize();
        $rule = $this->engine->create(input: $request->all(), ownerId: (int) auth()->guard('admin')->id());

        return response()->json(['rule' => $this->engine->present(rule: $rule, viewer: AccessContext::library(AccessContext::PURPOSE_LIST)), 'message' => trans('dam::app.admin.automation.rules.saved')], 201);
    }

    public function update(Request $request, int $rule): JsonResponse
    {
        $this->authorize();
        $model = $this->engine->update(rule: AutomationRule::query()->findOrFail($rule), input: $request->all(), actorId: auth()->guard('admin')->id());

        return response()->json(['rule' => $this->engine->present(rule: $model, viewer: AccessContext::library(AccessContext::PURPOSE_LIST)), 'message' => trans('dam::app.admin.automation.rules.saved')]);
    }

    public function destroy(int $rule): JsonResponse
    {
        $this->authorize();
        AutomationRule::query()->findOrFail($rule)->delete();

        return response()->json(['message' => trans('dam::app.admin.automation.rules.deleted')]);
    }

    /** The dry run of the editor's draft (or the saved rule, by id) over a selection, with the owner's rights. */
    public function dryRun(Request $request): JsonResponse
    {
        $this->authorize();
        $saved = $request->filled('id') ? AutomationRule::query()->findOrFail((int) $request->input('id')) : null;
        $draft = new AutomationRule($this->engine->validate($request->input('rule', $saved?->only(['name', 'trigger_type', 'trigger_field_id', 'conditions', 'actions']) ?? [])));
        $draft->id = $saved?->id ?? 0;
        $draft->version = $saved?->version ?? 1;
        $draft->owner_id = $saved?->owner_id ?? (int) auth()->guard('admin')->id();
        $selection = AutomationSelection::resolve($request, AccessContext::library(AccessContext::PURPOSE_LIST));

        return response()->json(['assets' => $this->engine->dryRun(rule: $draft, assetIds: $selection['asset_ids'])]);
    }

    public function run(Request $request, int $rule): JsonResponse
    {
        $this->authorize();
        $model = AutomationRule::query()->findOrFail($rule);
        $selection = AutomationSelection::resolve($request, AccessContext::library(AccessContext::PURPOSE_LIST));
        $run = $this->engine->startManual(rule: $model, assetIds: $selection['asset_ids'], directoryId: $selection['directory_id'], requesterId: auth()->guard('admin')->id());

        return response()->json(['run' => $this->engine->presentRun(run: $run->refresh(), viewer: AccessContext::library(AccessContext::PURPOSE_LIST)), 'message' => trans('dam::app.admin.automation.rules.run-queued')], 202);
    }

    public function runs(int $rule): JsonResponse
    {
        $this->authorize();
        $model = AutomationRule::query()->findOrFail($rule);
        $runs = $model->runs()->orderByDesc('id')->limit(50)->get()->map(fn (AutomationRun $run) => $this->engine->presentRun(run: $run, viewer: AccessContext::library(AccessContext::PURPOSE_LIST)))->values()->all();

        return response()->json(['runs' => $runs]);
    }

    /** One run's action log: only rows for assets the viewer may list, so the log names no hidden asset. */
    public function showRun(int $rule, int $run): JsonResponse
    {
        $this->authorize();
        $model = AutomationRun::query()->where('rule_id', $rule)->where('id', $run)->firstOrFail();
        $rows = AutomationRunAction::query()->where('run_id', $model->id)->orderBy('asset_id')->orderBy('action_index')->limit(1000)->get();
        $visible = array_flip(VisibleAssets::filter($rows->pluck('asset_id')->unique()->values()->all(), AccessContext::library(AccessContext::PURPOSE_LIST)));
        $names = Asset::query()->whereIn('id', array_keys($visible) ?: [0])->pluck('file_name', 'id');

        return response()->json([
            'run'     => $this->engine->presentRun(run: $model, viewer: AccessContext::library(AccessContext::PURPOSE_LIST)),
            'actions' => $rows->filter(fn (AutomationRunAction $row) => isset($visible[$row->asset_id]))->map(fn (AutomationRunAction $row) => [
                'asset_id' => $row->asset_id, 'name' => $names[$row->asset_id] ?? null, 'index' => $row->action_index, 'type' => $row->action_type, 'target' => $row->target,
                'before'   => $row->value_before, 'after' => $row->value_after, 'outcome' => $row->outcome, 'reason' => $row->reason,
            ])->values()->all(),
        ]);
    }

    private function authorize(): void
    {
        abort_unless(bouncer()->hasPermission('dam.automation.manage'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }
}
