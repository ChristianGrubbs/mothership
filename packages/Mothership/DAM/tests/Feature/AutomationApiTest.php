<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';
require_once __DIR__.'/../Support/AutomationFixtures.php';

use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Services\Automation\AutomationEngine;

/*
 * P5b (REST parity): metadata mappings and automation rules over /api/v1/rest, for the API key's admin. Dry runs,
 * runs and run logs see only the files that admin may list: a hidden file answers exactly like a missing one.
 */

beforeEach(function () {
    config(['dam-tags.denylist' => ['badword']]);
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->closed = Directory::factory()->create(['name' => 'Closed']);
    $this->role = organizeRole();
    $this->role->update(['permissions' => [...$this->role->permissions, 'dam.asset.tag', 'dam.asset.update', 'dam.automation', 'dam.automation.manage', 'dam.mapping', 'dam.mapping.manage']]);
    organizeGrant(role: $this->role, directory: $this->open);
    $this->asset = automationAsset($this->open, 'beach', ['IPTC:Keywords' => ['Sunset', 'badword']]);
    $this->hidden = automationAsset($this->closed, 'secret', ['IPTC:Keywords' => ['Sunset']]);
    $this->headers = organizeApiHeaders($this->role);
});

function automationRest(string $method, string $route, array $parameters = [], array $data = [])
{
    return test()->withHeaders(test()->headers)->json($method, route($route, $parameters), $data);
}

it('manages, dry-runs and runs a mapping over REST, seeing only files the caller may list', function () {
    $id = (int) automationRest('POST', 'admin.api.dam.metadata_mappings.store', data: ['name' => 'Keywords', 'source_key' => 'IPTC:Keywords', 'target_type' => 'tag'])
        ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
    automationRest('PUT', 'admin.api.dam.metadata_mappings.update', [$id], ['transforms' => [['type' => 'trim']]])->assertOk()->assertJsonPath('data.version', 2);
    automationRest('GET', 'admin.api.dam.metadata_mappings.show', [$id])->assertOk()->assertJsonPath('data.transforms', [['type' => 'trim']]);
    expect(array_column(automationRest('GET', 'admin.api.dam.metadata_mappings.index')->assertOk()->json('data'), 'id'))->toBe([$id]);

    $dry = automationRest('POST', 'admin.api.dam.metadata_mappings.dry_run', [$id], ['asset_ids' => [$this->asset->id, $this->hidden->id]])->assertOk()->json('data');
    expect(array_column($dry, 'asset_id'))->toBe([$this->asset->id])
        ->and(array_column($dry[0]['writes'], 'outcome'))->toBe(['written', 'refused'])
        ->and(automationTags($this->asset))->toBe([]);

    $hidden = automationRest('POST', 'admin.api.dam.metadata_mappings.dry_run', [$id], ['asset_ids' => [$this->hidden->id]])->assertUnprocessable();
    $missing = automationRest('POST', 'admin.api.dam.metadata_mappings.dry_run', [$id], ['asset_ids' => [999999]])->assertUnprocessable();
    expect($hidden->json())->toBe($missing->json());

    automationRest('POST', 'admin.api.dam.metadata_mappings.run', [$id], ['directory_id' => $this->open->id])->assertStatus(202)->assertJsonPath('data.queued', 1);
    expect(automationTags($this->asset))->toBe(['Sunset' => 'mapping:'.$id])
        ->and(automationTags($this->hidden))->toBe([]);

    automationRest('DELETE', 'admin.api.dam.metadata_mappings.destroy', [$id])->assertOk();
    expect(automationRest('GET', 'admin.api.dam.metadata_mappings.show', [$id])->assertNotFound()->json())->toBe(['success' => false, 'message' => trans('dam::app.admin.automation.mappings.not-found')])
        ->and(MetadataMapping::query()->count())->toBe(0);
});

it('manages, dry-runs and runs a rule over REST, with its run log', function () {
    $id = (int) automationRest('POST', 'admin.api.dam.automation_rules.store', data: ['name' => 'Tag beach', 'trigger_type' => 'manual',
        'conditions'                                                                         => ['match' => 'any', 'conditions' => [['type' => 'filename', 'value' => 'beach*'], ['type' => 'has_tag', 'value' => 'Nope']]],
        'actions'                                                                            => [['type' => 'add_tag', 'value' => 'Coast']]])->assertCreated()->json('data.id');
    automationRest('PUT', 'admin.api.dam.automation_rules.update', [$id], ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
    automationRest('PUT', 'admin.api.dam.automation_rules.update', [$id], ['enabled' => true])->assertOk();

    $dry = automationRest('POST', 'admin.api.dam.automation_rules.dry_run', [$id], ['asset_ids' => [$this->asset->id, $this->hidden->id]])->assertOk()->json('data');
    expect(array_column($dry, 'asset_id'))->toBe([$this->asset->id])->and(automationTags($this->asset))->toBe([]);

    $run = automationRest('POST', 'admin.api.dam.automation_rules.run', [$id], ['directory_id' => $this->open->id])->assertStatus(202)->json('data');
    expect($run)->toMatchArray(['status' => 'completed', 'matched' => 1, 'written' => 1])
        ->and(automationTags($this->asset))->toBe(['Coast' => 'rule:'.$id.'@v1']);

    expect(array_column(automationRest('GET', 'admin.api.dam.automation_rules.runs', [$id])->assertOk()->json('data'), 'id'))->toBe([$run['id']]);
    automationRest('GET', 'admin.api.dam.automation_rules.runs.show', [$id, $run['id']])->assertOk()
        ->assertJsonPath('data.actions', [['asset_id' => $this->asset->id, 'name' => $this->asset->file_name, 'index' => 0, 'type' => 'add_tag', 'target' => 'tag:Coast', 'before' => null, 'after' => 'Coast', 'outcome' => 'written', 'reason' => null]]);

    // A run that also touched a file the caller cannot see (a super admin's run-now) lists only the visible rows.
    $wide = app(AutomationEngine::class)->startManual(rule: AutomationRule::query()->find($id), assetIds: [$this->asset->id, $this->hidden->id], directoryId: null, requesterId: null);
    expect(array_column(automationRest('GET', 'admin.api.dam.automation_rules.runs.show', [$id, $wide->id])->assertOk()->json('data.actions'), 'asset_id'))->toBe([$this->asset->id]);

    // A run triggered on a file the caller cannot see lists without naming it.
    app(AutomationEngine::class)->runForEvent(rule: AutomationRule::query()->find($id), triggerType: 'field_changed', eventId: 'field:hidden-rest', assetId: (int) $this->hidden->id);
    expect(array_column(automationRest('GET', 'admin.api.dam.automation_rules.runs', [$id])->assertOk()->json('data'), 'asset_id'))->toBe([null, null, null])
        ->and(automationRest('GET', 'admin.api.dam.automation_rules.index')->assertOk()->json('data.0.last_run.asset_id'))->toBeNull();

    $missingRun = automationRest('GET', 'admin.api.dam.automation_rules.runs.show', [$id, 999999])->assertNotFound()->json();
    expect(automationRest('GET', 'admin.api.dam.automation_rules.show', [999999])->assertNotFound()->json('success'))->toBeFalse()
        ->and($missingRun['success'])->toBeFalse();

    automationRest('DELETE', 'admin.api.dam.automation_rules.destroy', [$id])->assertOk();
    expect(AutomationRule::query()->count())->toBe(0);
});

it('needs the API automation and mapping permissions', function () {
    $this->headers = organizeApiHeaders($this->role, permissions: ['api.dam.labels', 'api.dam.labels.get']);

    automationRest('GET', 'admin.api.dam.metadata_mappings.index')->assertForbidden();
    automationRest('POST', 'admin.api.dam.metadata_mappings.store', data: ['name' => 'M', 'source_key' => 'X', 'target_type' => 'tag'])->assertForbidden();
    automationRest('GET', 'admin.api.dam.automation_rules.index')->assertForbidden();
    automationRest('POST', 'admin.api.dam.automation_rules.store', data: ['name' => 'R', 'trigger_type' => 'manual', 'actions' => [['type' => 'add_tag', 'value' => 'x']]])->assertForbidden();
    expect(MetadataMapping::query()->count() + AutomationRule::query()->count())->toBe(0);
});
