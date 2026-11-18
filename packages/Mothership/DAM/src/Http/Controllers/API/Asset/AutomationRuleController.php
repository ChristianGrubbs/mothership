<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Models\AutomationRun;
use Mothership\DAM\Models\AutomationRunAction;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\Automation\AutomationSelection;
use Mothership\DAM\Services\Organization\VisibleAssets;

/**
 * REST twin of Settings → Automation rules (P5b, D19), under api.dam.automation.manage: CRUD, dry run, run-now and
 * the run log. A rule created here belongs to the key's admin and runs with their rights; a run's action log lists
 * only assets the key's admin may list.
 */
class AutomationRuleController extends Controller
{
    public function __construct(protected AutomationEngine $engine) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AutomationRule::query()->orderBy('id')->get()->map(fn (AutomationRule $rule) => $this->engine->present(rule: $rule, viewer: AccessContext::api(AccessContext::PURPOSE_LIST)))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        return $rule ? response()->json(['success' => true, 'data' => $this->engine->present(rule: $rule, viewer: AccessContext::api(AccessContext::PURPOSE_LIST))]) : $this->missing();
    }

    public function store(Request $request): JsonResponse
    {
        $rule = $this->engine->create(input: $request->all(), ownerId: (int) auth()->guard('api')->id());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.rules.saved'), 'data' => $this->engine->present(rule: $rule, viewer: AccessContext::api(AccessContext::PURPOSE_LIST))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        if (! $rule) {
            return $this->missing();
        }

        $rule = $this->engine->update(rule: $rule, input: $request->all(), actorId: auth()->guard('api')->id());

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.rules.saved'), 'data' => $this->engine->present(rule: $rule, viewer: AccessContext::api(AccessContext::PURPOSE_LIST))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        if (! $rule) {
            return $this->missing();
        }

        $rule->delete();

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.automation.rules.deleted')]);
    }

    public function dryRun(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        if (! $rule) {
            return $this->missing();
        }

        $selection = AutomationSelection::resolve($request, AccessContext::api(AccessContext::PURPOSE_LIST));

        return response()->json(['success' => true, 'data' => $this->engine->dryRun(rule: $rule, assetIds: $selection['asset_ids'])]);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        if (! $rule) {
            return $this->missing();
        }

        $selection = AutomationSelection::resolve($request, AccessContext::api(AccessContext::PURPOSE_LIST));
        $run = $this->engine->startManual(rule: $rule, assetIds: $selection['asset_ids'], directoryId: $selection['directory_id'], requesterId: auth()->guard('api')->id());

        return response()->json(['success' => true, 'data' => $this->engine->presentRun(run: $run->refresh(), viewer: AccessContext::api(AccessContext::PURPOSE_LIST))], 202);
    }

    public function runs(int $id): JsonResponse
    {
        $rule = AutomationRule::query()->find($id);

        if (! $rule) {
            return $this->missing();
        }

        return response()->json(['success' => true, 'data' => $rule->runs()->orderByDesc('id')->limit(50)->get()->map(fn (AutomationRun $run) => $this->engine->presentRun(run: $run, viewer: AccessContext::api(AccessContext::PURPOSE_LIST)))->values()]);
    }

    public function showRun(int $id, int $run): JsonResponse
    {
        $model = AutomationRun::query()->where('rule_id', $id)->where('id', $run)->first();

        if (! $model) {
            return $this->missing();
        }

        $rows = AutomationRunAction::query()->where('run_id', $model->id)->orderBy('asset_id')->orderBy('action_index')->limit(1000)->get();
        $visible = array_flip(VisibleAssets::filter($rows->pluck('asset_id')->unique()->values()->all(), AccessContext::api(AccessContext::PURPOSE_LIST)));
        $names = Asset::query()->whereIn('id', array_keys($visible) ?: [0])->pluck('file_name', 'id');

        return response()->json(['success' => true, 'data' => $this->engine->presentRun(run: $model, viewer: AccessContext::api(AccessContext::PURPOSE_LIST)) + [
            'actions' => $rows->filter(fn (AutomationRunAction $row) => isset($visible[$row->asset_id]))->map(fn (AutomationRunAction $row) => [
                'asset_id' => $row->asset_id, 'name' => $names[$row->asset_id] ?? null, 'index' => $row->action_index, 'type' => $row->action_type, 'target' => $row->target,
                'before'   => $row->value_before, 'after' => $row->value_after, 'outcome' => $row->outcome, 'reason' => $row->reason,
            ])->values()->all(),
        ]]);
    }

    private function missing(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => trans('dam::app.admin.automation.rules.not-found')], 404);
    }
}
