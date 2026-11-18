<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Jobs\RunAutomationChunk;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Models\AutomationRun;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Processing\BatchService;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;
use Mothership\DAM\Services\Webhooks\DamWebhookEvents;
use Mothership\DAM\Support\AutomationLog;
use Mothership\User\Models\Admin;

/**
 * The automation rules engine (P5b, D19; roadmap §3.4).
 *
 * - A rule is a trigger, AND/OR conditions (RuleConditions) and actions: add tag, set field, add label, add to
 *   section, add to collection (CollectionTarget; refused until P4c is wired).
 * - Every action is add-if-absent or set-if-different and goes through ValueWriter with source `rule:<id>@v<n>`
 *   inside AutomationOrigin, so manual values and removals win and a rule's own write triggers nothing.
 * - A rule runs with its owner's rights: an action on an asset the owner cannot edit, or one whose permission the
 *   owner lacks, is refused `no_access` and logged.
 * - Each evaluation is keyed (rule, version, trigger event), unique in dam_automation_runs: a redelivered trigger
 *   finds its row and writes nothing. Runs over a folder or selection are queued in chunks; a redelivered chunk
 *   writes nothing new and logs nothing twice (dam_automation_run_actions is unique per run, asset and action).
 * - A dry run evaluates the same code path without writing.
 */
class AutomationEngine
{
    public const ACTION_TYPES = ['add_tag', 'set_field', 'add_label', 'add_to_section', 'add_to_collection'];

    /** The web permission the rule's owner needs for each action, as a person doing it by hand would. */
    public const ACTION_PERMISSIONS = [
        'add_tag'           => 'dam.asset.tag',
        'set_field'         => 'dam.asset.update',
        'add_label'         => 'dam.labels.assign',
        'add_to_section'    => 'dam.sections.manage',
        'add_to_collection' => 'dam.asset.share',
    ];

    public function __construct(
        private readonly ValueWriter $writer,
        private readonly RuleConditions $conditions,
        private readonly CollectionTarget $collections,
        private readonly AssetAccessDecision $access,
    ) {}

    /**
     * Validated attributes for a create or an update, or a 422.
     *
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name'             => ['required', 'string', 'max:191'],
            'enabled'          => ['sometimes', 'boolean'],
            'trigger_type'     => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'trigger_field_id' => ['nullable', 'integer'],
            'conditions'       => ['nullable', 'array'],
            'actions'          => ['required', 'array', 'min:1', 'max:20'],
        ])->validate();

        $data['trigger_field_id'] = $data['trigger_type'] === AutomationRule::TRIGGER_FIELD_CHANGED ? ($data['trigger_field_id'] ?? null) : null;

        if ($data['trigger_field_id'] !== null && ! MetadataField::query()->where('id', $data['trigger_field_id'])->exists()) {
            throw ValidationException::withMessages(['trigger_field_id' => [trans('dam::app.admin.automation.validation.field')]]);
        }

        $data['conditions'] = RuleConditions::validate($input['conditions'] ?? []);
        $data['actions'] = $this->validateActions($input['actions']);

        return $data;
    }

    public function create(array $input, int $ownerId): AutomationRule
    {
        $rule = AutomationRule::query()->create($this->validate($input) + ['owner_id' => $ownerId, 'updated_by' => $ownerId, 'version' => 1]);
        AutomationLog::write(event: 'rule.created', ctx: ['rule_id' => (int) $rule->id, 'trigger' => $rule->trigger_type, 'actor_id' => $ownerId]);

        return $rule;
    }

    /** Every saved edit, the enabled switch included, is a new version: the next trigger evaluates afresh. */
    public function update(AutomationRule $rule, array $input, ?int $actorId): AutomationRule
    {
        $rule->fill($this->validate($input + $rule->only(['name', 'trigger_type', 'trigger_field_id', 'conditions', 'actions'])));

        if ($rule->isDirty()) {
            // Turning it on or off is not a new version: what it does, and so its run key, is unchanged.
            if (array_diff(array_keys($rule->getDirty()), ['enabled']) !== []) {
                $rule->version = $rule->version + 1;
            }

            $rule->updated_by = $actorId;
            $rule->save();
            AutomationLog::write(event: 'rule.updated', ctx: ['rule_id' => (int) $rule->id, 'version' => $rule->version, 'actor_id' => (int) $actorId]);
        }

        return $rule;
    }

    /**
     * One rule for one triggering event on one asset. Returns the run, or null when this (rule, version, event)
     * already ran: a redelivered trigger evaluates once and writes nothing.
     */
    public function runForEvent(AutomationRule $rule, string $triggerType, string $eventId, int $assetId): ?AutomationRun
    {
        $run = DB::transaction(function () use ($rule, $triggerType, $eventId, $assetId): ?AutomationRun {
            $inserted = DB::table('dam_automation_runs')->insertOrIgnore([
                'rule_id'    => $rule->id, 'rule_version' => $rule->version, 'trigger_event_id' => $eventId, 'trigger_type' => $triggerType,
                'asset_id'   => $assetId, 'status' => AutomationRun::STATUS_RUNNING, 'assets_total' => 1,
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                AutomationLog::write(event: 'run.replayed', ctx: ['rule_id' => (int) $rule->id, 'version' => (int) $rule->version, 'asset_id' => $assetId, 'trigger' => $triggerType]);

                return null;
            }

            $run = AutomationRun::query()->where('rule_id', $rule->id)->where('rule_version', $rule->version)->where('trigger_event_id', $eventId)->firstOrFail();
            AutomationLog::within('automation-run:'.$run->id);
            $this->evaluate(rule: $rule, assetIds: [$assetId], dryRun: false, run: $run);

            return $this->finish($run);
        });

        AutomationLog::within(null);

        if ($run !== null) {
            app(DamWebhookEvents::class)->runCompleted($run);
        }

        return $run;
    }

    /**
     * "Run now" over a folder or a selection: a manual trigger event of its own, evaluated in queued chunks. The
     * caller passes only ids the requester may see.
     *
     * @param  list<int>  $assetIds
     */
    public function startManual(AutomationRule $rule, array $assetIds, ?int $directoryId, ?int $requesterId): AutomationRun
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $run = AutomationRun::query()->create([
            'rule_id'      => $rule->id, 'rule_version' => $rule->version, 'trigger_event_id' => 'manual:'.Str::uuid(),
            'trigger_type' => AutomationRule::TRIGGER_MANUAL, 'directory_id' => $directoryId, 'status' => AutomationRun::STATUS_QUEUED,
            'assets_total' => count($assetIds), 'requested_by' => $requesterId, 'started_at' => now(),
        ]);
        $runId = (int) $run->id;
        $jobs = array_map(fn (array $chunk) => new RunAutomationChunk(runId: $runId, assetIds: $chunk), array_chunk($assetIds, max(1, (int) config('dam.automation.chunk', 200))));

        AutomationLog::write(event: 'run.queued', ctx: ['rule_id' => (int) $rule->id, 'run_id' => $runId, 'assets' => count($assetIds), 'chunks' => count($jobs), 'actor_id' => (int) $requesterId]);

        if ($jobs === []) {
            $this->complete($runId);
        } else {
            Bus::batch($jobs)->allowFailures()->name('dam-automation-run:'.$runId)
                ->finally(fn () => app(AutomationEngine::class)->complete($runId))
                ->dispatch();
        }

        return $run->refresh();
    }

    /**
     * One queued chunk of a manual run. A rule edited since the run was queued does nothing: the run belonged to
     * the version that was asked for.
     *
     * @param  list<int>  $assetIds
     */
    public function runChunk(int $runId, array $assetIds): void
    {
        $run = AutomationRun::query()->find($runId);
        $rule = $run?->rule;

        if ($run === null || $rule === null || $rule->version !== $run->rule_version) {
            AutomationLog::write(event: 'run.chunk_stale', ctx: ['run_id' => $runId]);

            return;
        }

        AutomationRun::query()->where('id', $runId)->where('status', AutomationRun::STATUS_QUEUED)->update(['status' => AutomationRun::STATUS_RUNNING]);
        AutomationLog::within('automation-run:'.$runId);

        try {
            $this->evaluate(rule: $rule, assetIds: $assetIds, dryRun: false, run: $run);
        } finally {
            AutomationLog::within(null);
        }
    }

    /** Counts a run from its action rows (so a redelivered chunk counts nothing twice) and marks it completed. */
    public function complete(int $runId): void
    {
        $run = AutomationRun::query()->find($runId);

        if ($run === null || $run->status === AutomationRun::STATUS_COMPLETED) {
            return;
        }

        $this->finish($run);
        app(DamWebhookEvents::class)->runCompleted($run);
    }

    /**
     * The dry run over a selection: which assets match and what each action would write. Nothing is written and no
     * run is recorded. The caller passes only ids the requester may see.
     *
     * @param  list<int>  $assetIds
     * @return list<array{asset_id: int, name: string, actions: list<array<string, mixed>>}>
     */
    public function dryRun(AutomationRule $rule, array $assetIds): array
    {
        $assetIds = array_slice(array_values(array_unique(array_map('intval', $assetIds))), 0, max(1, (int) config('dam.automation.dry_run_limit', 500)));
        $result = $this->evaluate(rule: $rule, assetIds: $assetIds, dryRun: true, run: null);
        AutomationLog::write(event: 'rule.dry_run', ctx: ['rule_id' => (int) $rule->id, 'assets' => count($assetIds), 'matched' => count($result)]);

        return $result;
    }

    /**
     * Evaluates the rule over assets: the matching ones, each with every action's outcome. With a run, outcomes are
     * logged to it (once per run, asset and action).
     *
     * @param  list<int>  $assetIds
     * @return list<array{asset_id: int, name: string, actions: list<array<string, mixed>>}>
     */
    public function evaluate(AutomationRule $rule, array $assetIds, bool $dryRun, ?AutomationRun $run): array
    {
        $owner = $rule->owner_id === null ? null : Admin::query()->find($rule->owner_id);
        $source = ValueSource::rule(ruleId: (int) $rule->id, version: (int) ($run?->rule_version ?? $rule->version));
        $results = [];

        foreach (array_chunk($assetIds, max(1, (int) config('dam.automation.chunk', 200))) as $chunk) {
            $this->conditions->load($chunk);

            foreach (Asset::query()->whereIn('id', $chunk)->whereNull('merged_into_id')->orderBy('id')->get() as $asset) {
                $matched = $this->conditions->matches(asset: $asset, conditions: $rule->conditions);
                AutomationLog::write(event: 'rule.evaluated', ctx: ['rule_id' => (int) $rule->id, 'asset_id' => (int) $asset->id, 'matched' => $matched, 'dry_run' => $dryRun]);

                if (! $matched) {
                    continue;
                }

                $editable = $owner !== null && (bool) $owner->status
                    && $this->access->for(context: AccessContext::forAdmin($owner, AccessContext::PURPOSE_EDIT), asset: $asset)->allowed;
                $actions = [];

                foreach (array_values($rule->actions ?? []) as $index => $action) {
                    $outcome = $this->act(asset: $asset, action: $action, owner: $editable ? $owner : null, source: $source, dryRun: $dryRun);

                    if ($outcome->reason === WriteOutcome::REASON_NO_ACCESS) {
                        AutomationLog::write(event: 'action.refused', ctx: ['rule_id' => (int) $rule->id, 'asset_id' => (int) $asset->id, 'action' => (string) $action['type'], 'reason' => WriteOutcome::REASON_NO_ACCESS], level: 'notice');
                    }

                    $row = ['index' => $index, 'type' => (string) $action['type'], 'target' => $this->target($action)] + $outcome->toArray();
                    $actions[] = $row;

                    if ($run !== null) {
                        DB::table('dam_automation_run_actions')->insertOrIgnore([
                            'run_id'  => $run->id, 'asset_id' => $asset->id, 'action_index' => $index, 'action_type' => $row['type'],
                            'target'  => mb_substr((string) $row['target'], 0, 191), 'value_before' => $row['before'], 'value_after' => $row['after'],
                            'outcome' => $row['outcome'], 'reason' => $row['reason'], 'created_at' => now(),
                        ]);
                    }
                }

                $results[] = ['asset_id' => (int) $asset->id, 'name' => (string) $asset->file_name, 'actions' => $actions];
            }
        }

        return $results;
    }

    /**
     * The rule as its editor and the REST API show it, for `$viewer`.
     *
     * @return array<string, mixed>
     */
    public function present(AutomationRule $rule, AccessContext $viewer): array
    {
        $last = $rule->runs()->orderByDesc('id')->first();

        return [
            'id'               => (int) $rule->id,
            'name'             => $rule->name,
            'enabled'          => (bool) $rule->enabled,
            'version'          => (int) $rule->version,
            'trigger_type'     => $rule->trigger_type,
            'trigger_field_id' => $rule->trigger_field_id,
            'conditions'       => $rule->conditions ?? ['match' => 'all', 'conditions' => []],
            'actions'          => $rule->actions ?? [],
            'owner_id'         => $rule->owner_id,
            'owner_name'       => $rule->owner_id === null ? null : Admin::query()->where('id', $rule->owner_id)->value('name'),
            'last_run'         => $last === null ? null : $this->presentRun(run: $last, viewer: $viewer),
            'runs_count'       => $rule->runs()->count(),
            'updated_at'       => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One run, for `$viewer`: the file it was triggered for and the folder it ran over are named only when the viewer
     * may see them, so a run log never discloses a hidden file or folder (a hidden one reads like none).
     *
     * @return array<string, mixed>
     */
    public function presentRun(AutomationRun $run, AccessContext $viewer): array
    {
        $assetId = $run->asset_id === null || ! $this->access->for(context: $viewer, asset: (int) $run->asset_id)->visible ? null : (int) $run->asset_id;
        $folders = app(DirectoryPermissionService::class);
        $directoryId = $run->directory_id === null || ! ($folders->canView((int) $run->directory_id) || $folders->canAccess((int) $run->directory_id)) ? null : (int) $run->directory_id;

        return [
            'id'           => (int) $run->id,
            'rule_id'      => (int) $run->rule_id,
            'rule_version' => (int) $run->rule_version,
            'trigger_type' => $run->trigger_type,
            'asset_id'     => $assetId,
            'directory_id' => $directoryId,
            'status'       => $run->status,
            'assets_total' => (int) $run->assets_total,
            'matched'      => (int) $run->matched,
            'written'      => (int) $run->written,
            'skipped'      => (int) $run->skipped,
            'refused'      => (int) $run->refused,
            'started_at'   => $run->started_at?->toIso8601String(),
            'finished_at'  => $run->finished_at?->toIso8601String(),
        ];
    }

    private function act(Asset $asset, array $action, ?Admin $owner, string $source, bool $dryRun): WriteOutcome
    {
        $type = (string) $action['type'];

        if ($owner === null || ! BatchService::adminMay($owner, self::ACTION_PERMISSIONS[$type] ?? 'dam.asset.update')) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_NO_ACCESS);
        }

        return AutomationOrigin::run(fn () => match ($type) {
            'add_tag'           => $this->writer->addTag(asset: $asset, name: (string) $action['value'], source: $source, dryRun: $dryRun),
            'set_field'         => ($field = MetadataField::query()->find($action['field_id'])) === null
                ? WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING)
                : $this->writer->setField(asset: $asset, field: $field, raw: $action['value'], source: $source, mode: ValueWriter::MODE_REPLACE_MACHINE, dryRun: $dryRun),
            'add_label'         => $this->writer->addLabel(asset: $asset, label: Label::query()->find($action['label_id']), source: $source, dryRun: $dryRun),
            'add_to_section'    => $this->writer->pinSection(asset: $asset, section: SavedSearch::query()->find($action['section_id']), source: $source, dryRun: $dryRun),
            'add_to_collection' => $this->collections->add(asset: $asset, collectionId: (int) $action['collection_id'], owner: $owner, source: $source, dryRun: $dryRun),
            default             => WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING),
        });
    }

    private function target(array $action): string
    {
        return match ($action['type']) {
            'add_tag'           => 'tag:'.$action['value'],
            'set_field'         => 'field:'.(MetadataField::query()->where('id', $action['field_id'])->value('code') ?? $action['field_id']),
            'add_label'         => 'label:'.$action['label_id'],
            'add_to_section'    => 'section:'.$action['section_id'],
            'add_to_collection' => 'collection:'.$action['collection_id'],
            default             => (string) $action['type'],
        };
    }

    private function finish(AutomationRun $run): AutomationRun
    {
        $rows = DB::table('dam_automation_run_actions')->where('run_id', $run->id);
        $counts = (clone $rows)->selectRaw('outcome, COUNT(*) AS n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n);

        $run->forceFill([
            'status'      => AutomationRun::STATUS_COMPLETED,
            'matched'     => (int) (clone $rows)->distinct()->count('asset_id'),
            'written'     => $counts->get(WriteOutcome::WRITTEN, 0),
            'skipped'     => $counts->get(WriteOutcome::SKIPPED, 0),
            'refused'     => $counts->get(WriteOutcome::REFUSED, 0),
            'finished_at' => now(),
        ])->save();
        AutomationRule::query()->where('id', $run->rule_id)->update(['last_run_at' => now()]);
        AutomationLog::write(event: 'run.completed', ctx: ['run_id' => (int) $run->id, 'rule_id' => (int) $run->rule_id, 'matched' => $run->matched,
            'written'                                               => $run->written, 'skipped' => $run->skipped, 'refused' => $run->refused]);

        return $run;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function validateActions(mixed $actions): array
    {
        if (! is_array($actions) || ! array_is_list($actions)) {
            throw ValidationException::withMessages(['actions' => [trans('dam::app.admin.automation.validation.actions')]]);
        }

        $clean = [];

        foreach ($actions as $index => $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            $fail = fn () => ValidationException::withMessages(["actions.$index" => [trans('dam::app.admin.automation.validation.action')]]);
            $id = fn (string $key, string $table, array $where = []) => DB::table($table)->where('id', (int) ($action[$key] ?? 0))->where($where)->exists()
                ? (int) $action[$key] : throw $fail();

            $clean[] = match ($type) {
                'add_tag'           => is_string($action['value'] ?? null) && trim($action['value']) !== '' && mb_strlen($action['value']) <= 191
                    ? ['type' => 'add_tag', 'value' => trim($action['value'])] : throw $fail(),
                'set_field'         => $this->fieldAction(action: $action, fail: $fail),
                'add_label'         => ['type' => 'add_label', 'label_id' => $id('label_id', 'dam_labels', ['kind' => Label::KIND_SHARED])],
                'add_to_section'    => ['type' => 'add_to_section', 'section_id' => $id('section_id', 'dam_saved_searches', ['kind' => SavedSearch::KIND_SECTION])],
                'add_to_collection' => is_numeric($action['collection_id'] ?? null) ? ['type' => 'add_to_collection', 'collection_id' => (int) $action['collection_id']] : throw $fail(),
                default             => throw $fail(),
            };
        }

        return $clean;
    }

    /** A set-field action's value is checked against the field's type when the rule is saved, not first at run time. */
    private function fieldAction(array $action, \Closure $fail): array
    {
        $field = MetadataField::query()->active()->find((int) ($action['field_id'] ?? 0));

        if ($field === null || app(FieldValueValidator::class)->check(field: $field, raw: $action['value'] ?? null, value: $value) !== null || $value === null) {
            throw $fail();
        }

        return ['type' => 'set_field', 'field_id' => (int) $field->id, 'value' => $action['value']];
    }
}
