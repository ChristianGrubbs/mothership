<?php

require_once __DIR__.'/../Support/AutomationFixtures.php';

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\RunAutomationTrigger;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AutomationRun;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\Automation\AutomationSelection;
use Mothership\DAM\Services\Automation\AutomationTriggers;
use Mothership\DAM\Services\Automation\MetadataMappingService;
use Mothership\DAM\Services\Automation\ValueSource;
use Mothership\DAM\Services\Automation\ValueWriter;
use Mothership\DAM\Services\Automation\WriteOutcome;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Services\Tags\AssetTagWriter;

/*
 * P5b (D19, roadmap §3.4): the rules engine. Precedence manual > rule/mapping > AI, tombstones, the loop guard
 * (automation's own writes trigger nothing; each evaluation is keyed to its triggering event), dry runs that write
 * nothing and match the real run, and rules that run with their owner's rights.
 */

beforeEach(function () {
    config(['dam-tags.denylist' => ['badword'], 'dam.automation_logging' => false]);
    $this->admin = $this->loginAsAdmin();
    $this->folder = Directory::factory()->create(['name' => 'Shoots']);
    $this->asset = automationAsset($this->folder, 'beach');
    $this->status = automationField('status');
    $this->stage = automationField('stage');
    $this->fields = app(FieldValues::class);
});

/** A person writing one field on the test asset, as the Library's Details editor does. */
function personSets(object $test, string $code, ?string $value): void
{
    $field = $test->{$code};
    $test->fields->write(asset: $test->asset, values: [$code => ['field' => $field, 'value' => $value]], source: 'web');
}

function fieldValue(object $test, string $code): ?array
{
    return app(FieldValues::class)->current(assetId: (int) $test->asset->id, field: $test->{$code});
}

it('evaluates two different field changes on unchanged bytes, once each', function () {
    $rule = automationRule(['trigger_type' => 'field_changed', 'trigger_field_id' => $this->status->id, 'actions' => [['type' => 'add_tag', 'value' => 'Reviewed']]]);

    personSets($this, 'status', 'draft');
    personSets($this, 'status', 'final');

    expect(AutomationRun::query()->where('rule_id', $rule->id)->count())->toBe(2)
        ->and(automationTags($this->asset))->toBe(['Reviewed' => ValueSource::rule($rule->id, 1)]);
});

it('writes nothing when one trigger is replayed', function () {
    automationRule(['trigger_type' => 'field_changed', 'trigger_field_id' => $this->status->id, 'actions' => [
        ['type' => 'add_tag', 'value' => 'Reviewed'], ['type' => 'set_field', 'field_id' => $this->stage->id, 'value' => 'triage'],
    ]]);
    $job = new RunAutomationTrigger(trigger: 'field_changed', eventId: 'field:replayed-event', assetId: (int) $this->asset->id, fieldIds: [$this->status->id]);

    $job->handle(app(AutomationEngine::class));
    $after = automationRowCounts();
    $job->handle(app(AutomationEngine::class));

    expect(automationRowCounts())->toBe($after)
        ->and($after['dam_automation_runs'])->toBe(1)
        ->and(fieldValue($this, 'stage')['text'])->toBe('triage');
});

it('runs a rule once when its own action changes the field its trigger watches', function () {
    // Any field change triggers it, and it sets a field: its own write is a field change it must ignore.
    $rule = automationRule(['trigger_type' => 'field_changed', 'actions' => [['type' => 'set_field', 'field_id' => $this->stage->id, 'value' => 'triage']]]);

    personSets($this, 'status', 'draft');

    expect(AutomationRun::query()->where('rule_id', $rule->id)->count())->toBe(1)
        ->and(fieldValue($this, 'stage'))->toMatchArray(['text' => 'triage', 'source' => ValueSource::rule($rule->id, 1)]);
});

it('does not fire tag rules from a rule that writes a tag', function () {
    $writer = automationRule(['trigger_type' => 'field_changed', 'actions' => [['type' => 'add_tag', 'value' => 'Auto']]]);
    $listener = automationRule(['trigger_type' => 'tag_changed', 'actions' => [['type' => 'add_tag', 'value' => 'Echo']]]);

    personSets($this, 'status', 'draft');

    expect(AutomationRun::query()->where('rule_id', $writer->id)->count())->toBe(1)
        ->and(AutomationRun::query()->where('rule_id', $listener->id)->count())->toBe(0)
        ->and(array_keys(automationTags($this->asset)))->toBe(['Auto']);
});

it('never overwrites a manual field value or re-adds a value a person removed', function () {
    $rule = automationRule(['actions' => [
        ['type' => 'set_field', 'field_id' => $this->status->id, 'value' => 'machine'],
        ['type' => 'add_tag', 'value' => 'Sunset'],
        ['type' => 'add_label', 'label_id' => ($label = Label::query()->create(['name' => 'Hero', 'kind' => 'shared']))->id],
        ['type' => 'set_field', 'field_id' => $this->stage->id, 'value' => 'machine'],
    ]]);
    $tags = app(AssetTagWriter::class);
    $sunset = $tags->resolve('Sunset')->first();
    $labels = app(LabelService::class);
    $context = AccessContext::library(AccessContext::PURPOSE_LIST);

    personSets($this, 'status', 'by hand');
    $tags->addManual(asset: $this->asset, tag: $sunset, actorId: $this->admin->id);
    $tags->remove(asset: $this->asset, tag: $sunset, actorId: $this->admin->id);
    $labels->addAssets(label: $label, assetIds: [$this->asset->id], context: $context, viewerId: $this->admin->id);
    $labels->removeAssets(label: $label, assetIds: [$this->asset->id], context: $context, viewerId: $this->admin->id);
    personSets($this, 'stage', 'gone');
    personSets($this, 'stage', null);

    $run = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);

    expect(fieldValue($this, 'status'))->toMatchArray(['text' => 'by hand', 'source' => 'manual'])
        ->and(automationTags($this->asset))->toBe([])
        ->and(DB::table('dam_label_assets')->where('asset_id', $this->asset->id)->exists())->toBeFalse()
        ->and(fieldValue($this, 'stage'))->toBeNull()
        ->and($run->refresh()->only(['status', 'written', 'refused']))->toBe(['status' => 'completed', 'written' => 0, 'refused' => 4])
        ->and(DB::table('dam_automation_run_actions')->where('run_id', $run->id)->orderBy('action_index')->pluck('reason')->all())
        ->toBe(['manual', 'removed', 'removed', 'removed']);

    // A mapping is held to the same rules.
    $mapping = app(MetadataMappingService::class)->create(input: ['name' => 'Keywords', 'source_key' => 'Keywords', 'target_type' => 'tag'], actorId: $this->admin->id);
    $this->asset->update(['meta_data' => ['exif' => ['Keywords' => ['Sunset']]]]);
    expect(app(MetadataMappingService::class)->evaluate(mapping: $mapping, asset: $this->asset->fresh(), dryRun: false)[0])
        ->toMatchArray(['outcome' => 'refused', 'reason' => 'removed'])
        ->and(automationTags($this->asset))->toBe([]);

    // Setting it again by hand lifts the removal.
    personSets($this, 'stage', 'back');
    expect(fieldValue($this, 'stage')['source'])->toBe('manual')
        ->and(DB::table('dam_value_decisions')->where('asset_id', $this->asset->id)->where('kind', 'field')->where('target_id', $this->stage->id)->exists())->toBeFalse();
});

it('lets a rule replace an AI value, never lets AI replace a rule or mapping value, and leaves machine values to fill-empty mappings', function () {
    $writer = app(ValueWriter::class);
    $asset = $this->asset;

    expect($writer->setField(asset: $asset, field: $this->status, raw: 'from ai', source: 'ai:caption-v1', mode: ValueWriter::MODE_FILL_EMPTY, dryRun: false)->outcome)->toBe('written');

    $rule = automationRule(['actions' => [['type' => 'set_field', 'field_id' => $this->status->id, 'value' => 'from rule']]]);
    app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$asset->id], directoryId: null, requesterId: $this->admin->id);
    expect(fieldValue($this, 'status'))->toMatchArray(['text' => 'from rule', 'source' => ValueSource::rule($rule->id, 1)]);

    $ai = $writer->setField(asset: $asset, field: $this->status, raw: 'ai again', source: 'ai:caption-v1', mode: ValueWriter::MODE_REPLACE_MACHINE, dryRun: false);
    expect($ai->toArray())->toMatchArray(['outcome' => 'refused', 'reason' => WriteOutcome::REASON_PRECEDENCE])
        ->and(fieldValue($this, 'status')['text'])->toBe('from rule');

    $mapping = app(MetadataMappingService::class)->create(input: ['name' => 'Status', 'source_key' => 'XMP:Label', 'target_type' => 'field', 'target_id' => $this->status->id], actorId: $this->admin->id);
    $asset->update(['meta_data' => ['exif' => ['XMP:Label' => 'from mapping']]]);
    expect(app(MetadataMappingService::class)->evaluate(mapping: $mapping, asset: $asset->fresh(), dryRun: false)[0])->toMatchArray(['outcome' => 'skipped', 'reason' => 'not_empty'])
        ->and(fieldValue($this, 'status')['text'])->toBe('from rule');

    // In replace-machine mode the mapping (same precedence as a rule) replaces the rule's value; AI still cannot.
    $mapping = app(MetadataMappingService::class)->update(mapping: $mapping, input: ['write_mode' => 'replace_machine'], actorId: $this->admin->id);
    app(MetadataMappingService::class)->evaluate(mapping: $mapping, asset: $asset->fresh(), dryRun: false);
    expect(fieldValue($this, 'status'))->toMatchArray(['text' => 'from mapping', 'source' => 'mapping:'.$mapping->id])
        ->and($writer->setField(asset: $asset, field: $this->status, raw: 'ai', source: 'ai:caption-v1', mode: ValueWriter::MODE_REPLACE_MACHINE, dryRun: false)->reason)->toBe(WriteOutcome::REASON_PRECEDENCE);
});

it('writes nothing in a dry run, and the real run writes exactly what the dry run showed', function () {
    $label = Label::query()->create(['name' => 'Hero', 'kind' => 'shared']);
    $collection = app(CollectionService::class)->create(actor: $this->admin, data: ['name' => 'Canon picks']);
    $other = automationAsset($this->folder, 'portrait', ['Make' => 'Canon']);
    $psd = automationAsset($this->folder, 'layout', [], ['extension' => 'psd', 'mime_type' => 'image/vnd.adobe.photoshop']);
    $this->asset->update(['meta_data' => ['exif' => ['Make' => 'Canon']]]);
    personSets($this, 'status', 'by hand');

    $rule = automationRule(['conditions' => ['match' => 'all', 'conditions' => [
        ['type' => 'folder', 'directory_id' => $this->folder->id],
        ['type' => 'extension', 'values' => ['jpg']],
        ['type' => 'exif', 'key' => 'EXIF:Make', 'op' => 'eq', 'value' => 'canon'],
    ]], 'actions' => [
        ['type' => 'add_tag', 'value' => 'Canon'],
        ['type' => 'set_field', 'field_id' => $this->status->id, 'value' => 'shot'],
        ['type' => 'add_label', 'label_id' => $label->id],
        ['type' => 'add_to_collection', 'collection_id' => $collection->id],
    ]]);
    $ids = [$this->asset->id, $other->id, $psd->id];
    $before = automationRowCounts();

    $dry = app(AutomationEngine::class)->dryRun(rule: $rule, assetIds: $ids);

    expect(automationRowCounts())->toBe($before)
        ->and(array_column($dry, 'asset_id'))->toBe([$this->asset->id, $other->id]);

    $run = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: $ids, directoryId: null, requesterId: $this->admin->id);
    $logged = DB::table('dam_automation_run_actions')->where('run_id', $run->id)->orderBy('asset_id')->orderBy('action_index')
        ->get(['asset_id', 'action_index', 'outcome', 'reason'])->map(fn ($row) => [(int) $row->asset_id, (int) $row->action_index, $row->outcome, $row->reason])->all();
    $planned = collect($dry)->flatMap(fn ($asset) => array_map(fn ($action) => [$asset['asset_id'], $action['index'], $action['outcome'], $action['reason']], $asset['actions']))->all();

    expect($logged)->toBe($planned)
        ->and($run->refresh()->only(['matched', 'written', 'refused']))->toBe(['matched' => 2, 'written' => 7, 'refused' => 1])
        ->and(DB::table('dam_collection_assets')->where('collection_id', $collection->id)->orderBy('asset_id')->pluck('source', 'asset_id')->all())
        ->toBe([$this->asset->id => ValueSource::rule($rule->id, 1), $other->id => ValueSource::rule($rule->id, 1)])
        ->and(automationTags($other))->toBe(['Canon' => ValueSource::rule($rule->id, 1)]);
});

it('refuses and logs every action on a file the rule owner cannot edit', function () {
    $closed = Directory::factory()->create(['name' => 'Closed']);
    $hidden = automationAsset($closed, 'secret');
    $owner = automationMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update', 'dam.asset.tag', 'dam.automation', 'dam.automation.manage'], $this->folder);
    $rule = automationRule(['actions' => [['type' => 'add_tag', 'value' => 'Owned'], ['type' => 'set_field', 'field_id' => $this->status->id, 'value' => 'x']]], owner: $owner);

    $run = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id, $hidden->id], directoryId: null, requesterId: $this->admin->id);

    expect(automationTags($hidden))->toBe([])
        ->and(automationTags($this->asset))->toBe(['Owned' => ValueSource::rule($rule->id, 1)])
        ->and(DB::table('dam_automation_run_actions')->where('run_id', $run->id)->where('asset_id', $hidden->id)->pluck('reason')->all())->toBe(['no_access', 'no_access'])
        ->and($run->refresh()->refused)->toBe(2);

    // An action whose permission the owner lacks is refused too: this owner may not assign labels.
    $label = Label::query()->create(['name' => 'Hero', 'kind' => 'shared']);
    $labelRule = automationRule(['actions' => [['type' => 'add_label', 'label_id' => $label->id]]], owner: $owner);
    $labelRun = app(AutomationEngine::class)->startManual(rule: $labelRule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);
    expect(DB::table('dam_automation_run_actions')->where('run_id', $labelRun->id)->value('reason'))->toBe('no_access');
});

it('adds to a collection once, only with the rights a person would need, and never re-adds a member a person removed', function () {
    $collections = app(CollectionService::class);
    $collection = $collections->create(actor: $this->admin, data: ['name' => 'Campaign']);
    $rule = automationRule(['actions' => [['type' => 'add_to_collection', 'collection_id' => $collection->id]]]);
    $members = fn () => DB::table('dam_collection_assets')->where('collection_id', $collection->id)->pluck('source', 'asset_id')->all();
    $reasons = fn ($run) => DB::table('dam_automation_run_actions')->where('run_id', $run->id)->pluck('reason')->all();

    $first = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);
    $again = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);

    expect($members())->toBe([$this->asset->id => ValueSource::rule($rule->id, 1)])
        ->and($first->refresh()->written)->toBe(1)
        ->and($reasons($again))->toBe([WriteOutcome::REASON_PRESENT]);

    // A person removes it: the rule leaves it out from then on; adding it back by hand lifts that.
    $collections->removeMember(collection: $collection, actor: $this->admin, assetId: $this->asset->id);
    $removed = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);
    expect($members())->toBe([])->and($reasons($removed))->toBe([WriteOutcome::REASON_REMOVED]);

    $collections->addMembers(collection: $collection, actor: $this->admin, assetIds: [$this->asset->id]);
    $collections->removeMember(collection: $collection, actor: $this->admin, assetId: $this->asset->id);
    expect(DB::table('dam_value_decisions')->where('kind', 'collection')->count())->toBe(1);

    // An owner who may share the file but does not manage the collection is refused, and nothing is written.
    $sharer = automationMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.share', 'dam.automation', 'dam.automation.manage'], $this->folder);
    $other = automationAsset($this->folder, 'other');
    $foreign = automationRule(['actions' => [['type' => 'add_to_collection', 'collection_id' => $collection->id]]], owner: $sharer);
    $refused = app(AutomationEngine::class)->startManual(rule: $foreign, assetIds: [$other->id], directoryId: null, requesterId: $this->admin->id);
    expect($reasons($refused))->toBe([WriteOutcome::REASON_NO_ACCESS])->and($members())->toBe([]);

    // A collection that no longer exists is reported, not guessed at.
    $collections->delete(collection: $collection, actor: $this->admin);
    $gone = app(AutomationEngine::class)->startManual(rule: $rule, assetIds: [$this->asset->id], directoryId: null, requesterId: $this->admin->id);
    expect($reasons($gone))->toBe([WriteOutcome::REASON_TARGET_MISSING])
        ->and(DB::table('dam_value_decisions')->where('kind', 'collection')->count())->toBe(0);
});

it('runs ingest rules once per content and AI rules once per indexed result', function () {
    $ingest = automationRule(['trigger_type' => 'ingest', 'actions' => [['type' => 'add_tag', 'value' => 'New']]]);
    $ai = automationRule(['trigger_type' => 'ai_indexed', 'actions' => [['type' => 'add_tag', 'value' => 'Indexed']]]);
    $triggers = app(AutomationTriggers::class);

    $triggers->ingest($this->asset);
    $triggers->ingest($this->asset);
    $triggers->aiIndexed(assetId: (int) $this->asset->id, sha256: str_repeat('a', 64));
    $triggers->aiIndexed(assetId: (int) $this->asset->id, sha256: str_repeat('a', 64));

    expect(AutomationRun::query()->where('rule_id', $ingest->id)->count())->toBe(1)
        ->and(AutomationRun::query()->where('rule_id', $ai->id)->count())->toBe(1)
        ->and(array_keys(automationTags($this->asset)))->toBe(['Indexed', 'New']);
});

it('bumps the version on every edit, and a new version evaluates the same trigger again', function () {
    $rule = automationRule(['trigger_type' => 'field_changed', 'actions' => [['type' => 'add_tag', 'value' => 'One']]]);
    $engine = app(AutomationEngine::class);
    $job = new RunAutomationTrigger(trigger: 'field_changed', eventId: 'field:same', assetId: (int) $this->asset->id);

    $job->handle($engine);
    $rule = $engine->update(rule: $rule, input: ['actions' => [['type' => 'add_tag', 'value' => 'Two']]], actorId: $this->admin->id);
    $job->handle($engine);

    expect($rule->version)->toBe(2)
        ->and(AutomationRun::query()->where('rule_id', $rule->id)->pluck('rule_version')->all())->toBe([1, 2])
        ->and(automationTags($this->asset))->toBe(['One' => 'rule:'.$rule->id.'@v1', 'Two' => 'rule:'.$rule->id.'@v2']);
});

it('refuses an invalid rule with a 422 naming the bad part', function () {
    $this->postJson(route('admin.dam.configuration.rules.store'), ['name' => 'Bad', 'trigger_type' => 'manual', 'actions' => [['type' => 'set_field', 'field_id' => $this->status->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
    $this->postJson(route('admin.dam.configuration.rules.store'), ['name' => 'Bad', 'trigger_type' => 'never', 'actions' => [['type' => 'add_tag', 'value' => 'x']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['trigger_type']);
    expect(AssetProperty::query()->count())->toBe(0);
});

it('renders the rules and mappings pages, linked from the Library settings for those who may manage them', function () {
    automationRule(['name' => 'Tag new uploads', 'trigger_type' => 'ingest', 'actions' => [['type' => 'add_tag', 'value' => 'New']]]);

    $this->get(route('admin.dam.configuration.index'))->assertOk()
        ->assertSee(route('admin.dam.configuration.rules.index'), false)->assertSee(route('admin.dam.configuration.mappings.index'), false);
    $this->get(route('admin.dam.configuration.rules.index'))->assertOk()->assertSee('v-dam-automation-rules', false)->assertSee('Tag new uploads');
    $this->get(route('admin.dam.configuration.mappings.index'))->assertOk()->assertSee('v-dam-metadata-mappings', false);

    $this->actingAs(automationMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.configuration'], $this->folder), 'admin');
    $this->get(route('admin.dam.configuration.rules.index'))->assertForbidden();
});

it('leaves hidden files and folders out of every automation and mapping listing', function () {
    $closed = Directory::factory()->create(['name' => 'Closed vault']);
    $hidden = automationAsset($closed, 'secret', ['IPTC:Keywords' => ['Sunset']]);
    $rule = automationRule(['actions' => [['type' => 'add_tag', 'value' => 'Seen']]]);
    $engine = app(AutomationEngine::class);
    $folderRun = $engine->startManual(rule: $rule, assetIds: [$this->asset->id, $hidden->id], directoryId: $closed->id, requesterId: $this->admin->id);
    $engine->runForEvent(rule: $rule, triggerType: 'field_changed', eventId: 'field:hidden', assetId: (int) $hidden->id);
    $mapping = app(MetadataMappingService::class)->create(input: ['name' => 'Keywords', 'source_key' => 'IPTC:Keywords', 'target_type' => 'tag'], actorId: $this->admin->id);
    $this->actingAs(automationMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.automation', 'dam.automation.manage', 'dam.mapping', 'dam.mapping.manage'], $this->folder), 'admin');

    foreach (['admin.dam.configuration.rules.index', 'admin.dam.configuration.mappings.index'] as $page) {
        $this->get(route($page))->assertOk()->assertSee('Shoots')->assertDontSee('Closed vault');
    }

    expect($this->getJson(route('admin.dam.configuration.rules.index'))->assertOk()->json('rules.0.last_run.asset_id'))->toBeNull();
    $runs = collect($this->getJson(route('admin.dam.configuration.rules.runs', $rule->id))->assertOk()->json('runs'))->keyBy('id');
    expect($runs[$folderRun->id]['directory_id'])->toBeNull()
        ->and($runs->pluck('asset_id')->filter()->all())->toBe([]);
    expect(array_column($this->getJson(route('admin.dam.configuration.rules.runs.show', [$rule->id, $folderRun->id]))->assertOk()->json('actions'), 'asset_id'))->toBe([$this->asset->id]);

    $rulePreview = fn (array $ids) => $this->postJson(route('admin.dam.configuration.rules.dry_run'), ['id' => $rule->id, 'asset_ids' => $ids]);
    $mappingPreview = fn (array $ids) => $this->postJson(route('admin.dam.configuration.mappings.dry_run'), ['id' => $mapping->id, 'asset_ids' => $ids]);
    expect(array_column($rulePreview([$this->asset->id, $hidden->id])->assertOk()->json('assets'), 'asset_id'))->toBe([$this->asset->id])
        ->and(array_column($mappingPreview([$this->asset->id, $hidden->id])->assertOk()->json('assets'), 'asset_id'))->toBe([$this->asset->id])
        ->and($rulePreview([$hidden->id])->assertUnprocessable()->json())->toBe($rulePreview([999999])->assertUnprocessable()->json())
        ->and($mappingPreview([$hidden->id])->assertUnprocessable()->json())->toBe($mappingPreview([999999])->assertUnprocessable()->json());

    $hiddenKeys = $this->getJson(route('admin.dam.configuration.mappings.keys', ['asset_id' => $hidden->id]))->assertNotFound();
    // Compared without the debug trace, which only a local debug build adds.
    expect($hiddenKeys->json('message'))->toBe($this->getJson(route('admin.dam.configuration.mappings.keys', ['asset_id' => 999999]))->assertNotFound()->json('message'));
});

it('offers only the folders the admin may view in the run and scope pickers', function () {
    $parent = Directory::factory()->create(['name' => 'Parent of granted']);
    $child = Directory::factory()->create(['name' => 'Granted child', 'parent_id' => $parent->id]);
    Directory::factory()->create(['name' => 'Closed']);
    $this->actingAs(automationMember(['dam', 'dam.automation', 'dam.automation.manage'], $this->folder, $child), 'admin');

    $paths = collect(AutomationSelection::folders())->pluck('path')->all();

    expect($paths)->toContain('Shoots')->toContain('Parent of granted / Granted child')
        ->and(implode('|', $paths))->not->toContain('Closed');
});
