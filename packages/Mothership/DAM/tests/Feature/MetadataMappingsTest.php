<?php

require_once __DIR__.'/../Support/AutomationFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Services\Automation\MetadataMappingService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Support\AutomationLog;

/*
 * P5b (D18): embedded-metadata mappings. Raw ExifTool keys become tags, typed fields or shared labels at ingest and
 * on demand, through the same ValueWriter (and so the same precedence and TagNameRule) as rules, with
 * source=mapping:<id>; a dry run shows before→after and writes nothing. Campus GPS is left as it was and coexists.
 */

beforeEach(function () {
    Storage::fake('private');
    config(['dam-tags.denylist' => ['badword']]);
    $this->admin = $this->loginAsAdmin();
    $this->folder = Directory::factory()->create(['name' => 'Shoots']);
    $this->mappings = app(MetadataMappingService::class);
});

/** An upload whose bytes are stored before the row exists, so the graph ingests them as its original (ADR 0009). */
function uploadedAsset(Directory $directory, string $name, string $extension = 'jpg', array $attributes = []): Asset
{
    $path = 'uploads/'.$name.'-'.uniqid().'.'.$extension;
    Storage::disk('private')->put($path, 'fixture '.$name);

    return automationAsset($directory, $name, [], ['path' => $path, 'extension' => $extension, ...$attributes]);
}

function ingestWith(int $assetId, array $metadata): void
{
    $extractor = Mockery::mock(MetadataExtractionService::class);
    $extractor->shouldReceive('extractMetadata')->andReturn($metadata);
    (new ProcessAssetUpload($assetId))->handle($extractor);
}

it('turns IPTC keywords into mapping-sourced tags at ingest, logging the names TagNameRule refuses', function () {
    Queue::fake();
    config(['dam.automation_logging' => true]);
    $log = storage_path(AutomationLog::PATH);
    $offset = is_file($log) ? filesize($log) : 0;
    $mapping = $this->mappings->create(input: ['name' => 'Keywords', 'source_key' => 'IPTC:Keywords', 'target_type' => 'tag', 'transforms' => [['type' => 'trim']]], actorId: $this->admin->id);
    $asset = uploadedAsset($this->folder, 'upload');

    ingestWith($asset->id, ['exif' => ['SourceFile' => 'x.jpg', 'Keywords' => ['Sunset ', 'badword', 'Beach']]]);

    $refusals = collect(explode(PHP_EOL, trim((string) file_get_contents($log, offset: $offset))))->map(fn ($line) => json_decode($line, true))
        ->where('event', 'write.refused')->where('ctx.asset_id', $asset->id);

    expect(automationTags($asset))->toBe(['Beach' => 'mapping:'.$mapping->id, 'Sunset' => 'mapping:'.$mapping->id])
        ->and(DB::table('dam_tags')->whereRaw('LOWER(name) = ?', ['badword'])->exists())->toBeFalse()
        ->and($refusals->pluck('ctx.reason')->all())->toBe(['tag_name_refused'])
        ->and($refusals->first()['ctx']['source'])->toBe('mapping:'.$mapping->id);
});

it('maps a transformed key into a typed field and an existing shared label, within its folder scope only', function () {
    $field = automationField('camera');
    $label = Label::query()->create(['name' => 'Canon kit', 'kind' => 'shared']);
    $outside = Directory::factory()->create(['name' => 'Elsewhere']);
    $child = Directory::factory()->create(['name' => 'Day one', 'parent_id' => $this->folder->id]);
    $exif = ['EXIF:Make' => 'Canon EOS R5'];
    $in = automationAsset($child, 'in', $exif);
    $out = automationAsset($outside, 'out', $exif);
    $transforms = [['type' => 'regex', 'pattern' => '/^(\w+)/', 'group' => 1], ['type' => 'map', 'values' => ['canon' => 'Canon kit']]];
    $toField = $this->mappings->create(input: ['name' => 'Make', 'source_key' => 'EXIF:Make', 'target_type' => 'field', 'target_id' => $field->id, 'scope_directory_id' => $this->folder->id, 'transforms' => $transforms], actorId: $this->admin->id);
    $toLabel = $this->mappings->create(input: ['name' => 'Kit', 'source_key' => 'Make', 'target_type' => 'label', 'scope_directory_id' => $this->folder->id, 'transforms' => $transforms], actorId: $this->admin->id);

    $this->mappings->run(mapping: $toField, assetIds: [$in->id, $out->id], actorId: $this->admin->id);
    $this->mappings->run(mapping: $toLabel, assetIds: [$in->id, $out->id], actorId: $this->admin->id);

    expect(app(FieldValues::class)->current(assetId: (int) $in->id, field: $field))->toMatchArray(['text' => 'Canon kit', 'source' => 'mapping:'.$toField->id])
        ->and(app(FieldValues::class)->current(assetId: (int) $out->id, field: $field))->toBeNull()
        ->and(DB::table('dam_label_assets')->where('label_id', $label->id)->pluck('source', 'asset_id')->all())->toBe([$in->id => 'mapping:'.$toLabel->id])
        ->and($toField->refresh()->last_run_counts)->toMatchArray(['assets' => 1, 'written' => 1]);
});

it('shows before and after in a dry run, writes nothing, and the run then writes what it showed', function () {
    $field = automationField('caption');
    $asset = automationAsset($this->folder, 'one', ['XMP:Description' => 'New caption']);
    $machine = automationAsset($this->folder, 'two', ['XMP:Description' => 'Other caption']);
    $this->mappings->create(input: ['name' => 'Earlier', 'source_key' => 'XMP:Title', 'target_type' => 'field', 'target_id' => $field->id], actorId: $this->admin->id);
    app(FieldValues::class)->writeMachine(asset: $machine, field: $field, value: 'from ai', provenance: 'ai:caption-v1');
    $draft = ['name' => 'Captions', 'source_key' => 'XMP:Description', 'target_type' => 'field', 'target_id' => $field->id, 'write_mode' => 'replace_machine'];
    $before = automationRowCounts();

    $dry = $this->postJson(route('admin.dam.configuration.mappings.dry_run'), ['mapping' => $draft, 'asset_ids' => [$asset->id, $machine->id]])->assertOk()->json('assets');

    expect(automationRowCounts())->toBe($before)
        ->and(collect($dry)->map(fn ($row) => [$row['asset_id'], $row['writes'][0]['outcome'], $row['writes'][0]['before'], $row['writes'][0]['after']])->all())
        ->toBe([[$asset->id, 'written', null, 'New caption'], [$machine->id, 'written', 'from ai', 'Other caption']]);

    $id = $this->postJson(route('admin.dam.configuration.mappings.store'), $draft)->assertCreated()->json('mapping.id');
    $this->postJson(route('admin.dam.configuration.mappings.run', $id), ['asset_ids' => [$asset->id, $machine->id]])->assertStatus(202)->assertJsonPath('queued', 2);

    expect(app(FieldValues::class)->current(assetId: (int) $asset->id, field: $field)['text'])->toBe('New caption')
        ->and(app(FieldValues::class)->current(assetId: (int) $machine->id, field: $field))->toMatchArray(['text' => 'Other caption', 'source' => 'mapping:'.$id]);
});

it('samples the raw exif keys of an asset the admin may see, and answers a hidden one as missing', function () {
    $asset = automationAsset($this->folder, 'keys', ['SourceFile' => 'x', 'IPTC:Keywords' => ['a', 'b'], 'EXIF:Make' => 'Canon']);

    $this->getJson(route('admin.dam.configuration.mappings.keys', ['asset_id' => $asset->id]))->assertOk()
        ->assertJsonPath('asset_id', $asset->id)
        ->assertJsonPath('keys', [['key' => 'EXIF:Make', 'sample' => 'Canon'], ['key' => 'IPTC:Keywords', 'sample' => 'a, b']]);
    $this->getJson(route('admin.dam.configuration.mappings.keys', ['asset_id' => 999999]))->assertNotFound();
});

it('validates transforms and targets, naming the bad part', function () {
    $this->postJson(route('admin.dam.configuration.mappings.store'), ['name' => 'Bad', 'source_key' => 'X', 'target_type' => 'tag', 'transforms' => [['type' => 'regex', 'pattern' => '/(/']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['transforms.0.pattern']);
    $this->postJson(route('admin.dam.configuration.mappings.store'), ['name' => 'Bad', 'source_key' => 'X', 'target_type' => 'field', 'target_id' => 999999])
        ->assertUnprocessable()->assertJsonValidationErrors(['target_id']);
    expect(MetadataMapping::query()->count())->toBe(0);
});

it('needs dam.mapping.manage for every mapping route', function () {
    $member = automationMember(['dam', 'dam.asset', 'dam.asset.view'], $this->folder);
    $this->actingAs($member, 'admin');
    $mapping = MetadataMapping::query()->create(['name' => 'M', 'source_key' => 'X', 'target_type' => 'tag', 'transforms' => []]);

    $this->getJson(route('admin.dam.configuration.mappings.index'))->assertForbidden();
    $this->postJson(route('admin.dam.configuration.mappings.store'), ['name' => 'M', 'source_key' => 'X', 'target_type' => 'tag'])->assertForbidden();
    $this->postJson(route('admin.dam.configuration.mappings.run', $mapping->id), ['directory_id' => $this->folder->id])->assertForbidden();
    $this->deleteJson(route('admin.dam.configuration.mappings.destroy', $mapping->id))->assertForbidden();
    expect(MetadataMapping::query()->count())->toBe(1);
});
