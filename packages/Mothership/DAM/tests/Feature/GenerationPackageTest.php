<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\GenerationDirectory;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\DAM\Services\StylePressCatalog;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
    $this->packageRoot = sys_get_temp_dir().'/generation-package-'.Str::uuid();
    File::makeDirectory($this->packageRoot, 0700, true);
    $image = imagecreatetruecolor(3, 2);
    imagepng($image, $this->packageRoot.'/output.png');
    imagedestroy($image);
    File::put($this->packageRoot.'/reference.txt', 'Reference evidence');
    $this->manifest = [
        'schema_version'=> 1, 'generation_id'=>'device:example', 'title'=>'Reusable test image',
        'kind'          => 'image', 'provider'=>'higgsfield-generate', 'model'=>'gpt_image_2',
        'prompt'        => "Exact prompt\nwith a second line", 'original_prompt'=>'User request',
        'settings'      => ['quality'=>'low', 'aspect_ratio'=>'3:2', 'resolution'=>'1k'],
        'style'         => ['slug'=>'example', 'title'=>'Example Style', 'category'=>'04 Style', 'template'=>'Verbatim style', 'version'=>'one'],
        'source'        => ['note_path'=>'Images/example.md'],
        'output'        => ['path'=>'output.png', 'sha256'=>hash_file('sha256', $this->packageRoot.'/output.png'), 'mime'=>'image/png'],
        'references'    => [['path'=>'reference.txt', 'sha256'=>hash_file('sha256', $this->packageRoot.'/reference.txt'), 'name'=>'Reference evidence', 'role'=>'source']],
    ];
    $this->writePackage = function () {
        File::put($this->packageRoot.'/generation.json', json_encode($this->manifest));

        return $this->packageRoot.'/generation.json';
    };
});

afterEach(function () {
    File::deleteDirectory($this->packageRoot);
});

it('imports the output with full readable provenance and real reference attachments without duplicates', function () {
    $path = ($this->writePackage)();
    $importer = app(GenerationPackageImporter::class);
    $result = $importer->importManifest(manifestPath: $path);
    $asset = Asset::findOrFail($result['asset_id']);
    expect($result['action'])->toBe('created');
    expect($asset->title)->toBe('Reusable test image');
    expect($asset->files()->pluck('role')->all())->toContain('original', 'prompt', 'sidecar', 'source');
    $record = GenerationAsset::where('generation_id', 'device:example')->firstOrFail();
    expect($record->manifest['prompt'])->toBe($this->manifest['prompt']);
    expect($record->manifest['settings']['quality'])->toBe('low');
    expect($asset->fresh()->meta_data['AI Prompt'])->toBe($this->manifest['prompt']);
    expect($asset->directories()->first()->name)->toBe('Example Style');
    // A real folder-tree Library path (ADR 0009 slice 3), never assets/generated/<sha>.<ext>.
    expect($asset->path)->toBe('assets/Root/AI-Generations/Images/Pre-Built-Styles/04 Style/Example Style/output.png');
    $reference = $asset->files()->where('role', 'source')->with('fileObject')->firstOrFail();
    expect(Storage::disk($reference->fileObject->disk)->get($reference->fileObject->path))->toBe('Reference evidence');
    $again = $importer->importManifest(manifestPath: $path);
    expect($again['asset_id'])->toBe($asset->id)->and($again['action'])->toBe('unchanged');
    expect(Asset::count())->toBe(1)->and($asset->files()->count())->toBe(4);
});

it('enriches an existing migrated output by content instead of creating another asset', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('assets/migrated.png', File::get($this->packageRoot.'/output.png'));
    $existing = Asset::factory()->create(['path'=>'assets/migrated.png', 'file_type'=>'image', 'extension'=>'png']);
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    expect($result['asset_id'])->toBe($existing->id)->and($result['action'])->toBe('updated');
    expect(Asset::count())->toBe(1);
    expect($existing->fresh()->meta_data['AI Prompt'])->toBe($this->manifest['prompt']);
});

it('reconciles an old generated-media route while preserving unrelated directory memberships', function () {
    $importer = app(GenerationPackageImporter::class);
    $style = $this->manifest['style'];
    $this->manifest['style'] = null;
    $first = $importer->importManifest(manifestPath: ($this->writePackage)());
    $asset = Asset::findOrFail($first['asset_id']);
    $unrelated = app(GenerationDirectory::class)->ensure(segments: ['Editorial', 'Favorites']);
    $asset->directories()->attach($unrelated->id);

    $this->manifest['style'] = $style;
    $importer->importManifest(manifestPath: ($this->writePackage)());

    $paths = $asset->fresh()->directories->map->generatePath()->all();
    expect($paths)->toContain('Root/AI-Generations/Images/Pre-Built-Styles/04 Style/Example Style', 'Root/Editorial/Favorites')
        ->not->toContain('Root/AI-Generations/Images/Misc./higgsfield-generate');
    // M8: the logical path is recomputed with the new folder, not left pointing at the old one.
    expect($asset->fresh()->path)->toBe('assets/Root/AI-Generations/Images/Pre-Built-Styles/04 Style/Example Style/'.$asset->file_name);
});

it('updates file_name together with the allocated path when reconciling into an occupied destination', function () {
    // Finding 7 (review round 1, minor): reconcileDirectory allocated a collision suffix for the
    // move but wrote only `path`, leaving `file_name` stale -- an asset that landed on
    // output(1).png in `path` kept `file_name` = output.png, so the two columns disagreed.
    $importer = app(GenerationPackageImporter::class);
    $style = $this->manifest['style'];
    $this->manifest['style'] = null;
    $first = $importer->importManifest(manifestPath: ($this->writePackage)());
    $asset = Asset::findOrFail($first['asset_id']);

    // Pre-occupy the destination the reconcile is about to move into, under the same name the
    // asset already carries, so the allocator must pick a collision suffix.
    $this->manifest['style'] = $style;
    $destinationPath = 'assets/Root/AI-Generations/Images/Pre-Built-Styles/04 Style/Example Style';
    Asset::factory()->create(['path' => $destinationPath.'/'.$asset->file_name, 'file_type' => 'image', 'extension' => 'png']);

    $importer->importManifest(manifestPath: ($this->writePackage)());

    $reconciled = $asset->fresh();
    expect($reconciled->file_name)->not->toBe('output.png');
    expect($reconciled->path)->toBe($destinationPath.'/'.$reconciled->file_name);
});

it('keeps historical prompts readable without inventing a reusable style snapshot', function () {
    $this->manifest['style'] = null;
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    $id = $result['asset_id'];
    $this->getJson('/admin/dam/assets/'.$id.'/generation')->assertOk()
        ->assertJsonPath('reuse_url', null)->assertJsonPath('manifest.prompt', $this->manifest['prompt']);
    $this->get('/admin/dam/assets/'.$id.'/generation/view')->assertOk()->assertDontSee('Reuse in StylePress');
    $style = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $this->postJson('/admin/home/stylepress/generate', ['reuse_asset'=>$id, 'style_slug'=>$style, 'prompt'=>'A cube', 'aspect_ratio'=>'1:1'])->assertUnprocessable();
});

it('preserves archived vector and document outputs without rasterizing them', function (string $extension, string $bytes, string $type) {
    File::put($this->packageRoot.'/output.'.$extension, $bytes);
    $this->manifest['output'] = ['path'=>'output.'.$extension, 'sha256'=>hash('sha256', $bytes)];
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    $asset = Asset::findOrFail($result['asset_id']);
    expect($asset->file_type)->toBe($type);
    // The output goes into a blob, never a physical file at the asset's (logical) Library path.
    $store = app(ContentStore::class);
    $stream = $store->open($store->current($asset));
    expect(stream_get_contents($stream))->toBe($bytes);
    fclose($stream);
    expect(Storage::disk(Directory::getAssetDisk())->exists($asset->path))->toBeFalse();
})->with([
    ['svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><circle cx="5" cy="5" r="4"/></svg>', 'image'],
    ['pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF", 'document'],
]);

it('rejects invalid package paths and checksum mismatches before storing anything', function (string $failure) {
    if ($failure === 'traversal') {
        $this->manifest['output']['path'] = '../output.png';
    } elseif ($failure === 'checksum') {
        $this->manifest['output']['sha256'] = str_repeat('0', 64);
    } else {
        unlink($this->packageRoot.'/reference.txt');
    }
    $path = ($this->writePackage)();
    expect(fn () => app(GenerationPackageImporter::class)->importManifest(manifestPath: $path))->toThrow(RuntimeException::class);
    expect(Asset::count())->toBe(0)->and(Storage::disk(Directory::getAssetDisk())->allFiles())->toBe([]);
})->with(['traversal', 'checksum', 'missing-reference']);

it('validates a dry run without creating assets or directories', function () {
    $count = Directory::count();
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)(), dryRun: true);
    expect($result['ok'])->toBeTrue()->and(Asset::count())->toBe(0)->and(Directory::count())->toBe($count);
});

it('repairs a missing mirrored attachment without duplicating the asset', function () {
    $path = ($this->writePackage)();
    $importer = app(GenerationPackageImporter::class);
    $first = $importer->importManifest(manifestPath: $path);
    $reference = AssetFile::where('dam_asset_id', $first['asset_id'])->where('role', 'source')->with('fileObject')->firstOrFail();
    Storage::disk($reference->fileObject->disk)->delete($reference->fileObject->path);
    $again = $importer->importManifest(manifestPath: $path);
    expect($again['asset_id'])->toBe($first['asset_id']);
    expect(Storage::disk($reference->fileObject->disk)->get($reference->fileObject->path))->toBe('Reference evidence');
});

it('shows readable generation metadata and previewable references to an authorized viewer', function () {
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    $this->getJson('/admin/dam/assets/'.$result['asset_id'].'/generation')
        ->assertOk()->assertJsonPath('manifest.model', 'gpt_image_2')
        ->assertJsonPath('manifest.settings.quality', 'low')->assertJsonCount(1, 'reference_urls');
    $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/view')
        ->assertOk()->assertSee('Exact prompt')->assertSee('Reference evidence')->assertSee('Reuse in StylePress');
});

it('does not expose generation prompts or references outside an accessible directory', function () {
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    $role = Role::factory()->create(['permission_type'=>'custom', 'permissions'=>['dam.asset.files.index']]);
    $admin = Admin::factory()->create(['role_id'=>$role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->getJson('/admin/dam/assets/'.$result['asset_id'].'/generation')->assertNotFound();
    $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/view')->assertNotFound();
});

it('reconciles revised reproduction data without deleting unrelated sidecars', function () {
    $importer = app(GenerationPackageImporter::class);
    $first = $importer->importManifest(manifestPath: ($this->writePackage)());
    $asset = Asset::findOrFail($first['asset_id']);
    $original = $asset->files()->where('role', 'original')->firstOrFail();
    AssetFile::create(['dam_asset_id'=>$asset->id, 'file_object_id'=>$original->file_object_id, 'role'=>'sidecar', 'label'=>'Unrelated user attachment']);
    $this->manifest['prompt'] = 'Revised final prompt';
    $this->manifest['settings']['quality'] = 'medium';
    $second = $importer->importManifest(manifestPath: ($this->writePackage)());
    expect($second['asset_id'])->toBe($first['asset_id'])->and($second['action'])->toBe('updated');
    expect($asset->files()->where('role', 'prompt')->count())->toBe(1);
    expect($asset->files()->where('role', 'sidecar')->count())->toBe(2);
    $this->getJson('/admin/dam/assets/'.$asset->id.'/generation')->assertOk()
        ->assertJsonPath('manifest.prompt', 'Revised final prompt')->assertJsonCount(2, 'attachments');
});

it('lets a restricted generation owner read their saved details and references without granting folder access', function () {
    $role = Role::factory()->create(['permission_type'=>'custom', 'permissions'=>['dam.assets']]);
    DB::table('dam_role_settings')->insert(['role_id'=>$role->id, 'home_widget_ids'=>json_encode(['recent', 'stylepress']), 'created_at'=>now(), 'updated_at'=>now()]);
    $owner = Admin::factory()->create(['role_id'=>$role->id]);
    $id = (string) Str::uuid();
    DB::table('dam_home_generations')->insert(['id'=>$id, 'admin_id'=>$owner->id, 'request_id'=>'example', 'status_url'=>'higgsfield-cli:example', 'status'=>'completed', 'created_at'=>now(), 'updated_at'=>now()]);
    $this->manifest['generation_id'] = 'mothership-'.$id;
    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: ($this->writePackage)());
    $this->actingAs($owner, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $data = $this->getJson('/admin/dam/assets/'.$result['asset_id'].'/generation')->assertOk()->json();
    $this->get($data['reference_urls'][0]['url'])->assertOk();
    // The generated image and its download ride the same generation access, not folder access.
    $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/output')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $download = $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/output?download=1')->assertOk();
    expect((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment');
    $this->get('/admin/dam/assets/download/'.$result['asset_id'])->assertForbidden();
    $this->mock(SharedHiggsfield::class, function ($mock) {
        $mock->shouldReceive('enabled')->andReturn(true);
        $mock->shouldReceive('estimate')->andReturn(['credits'=>1]);
    });
    $this->postJson('/admin/home/stylepress/estimate', ['reuse_asset'=>$result['asset_id'], 'style_slug'=>'example', 'prompt'=>'New request', 'aspect_ratio'=>'1:1'])
        ->assertOk()->assertJsonPath('compiled_prompt', app(StylePressCatalog::class)->compile(style: $this->manifest['style'], prompt: 'New request', mode: 'create'));
    $other = Admin::factory()->create(['role_id'=>$role->id]);
    $this->actingAs($other, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->get($data['reference_urls'][0]['url'])->assertNotFound();
    $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/output')->assertNotFound();
    $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/output?download=1')->assertNotFound();
    $this->postJson('/admin/home/stylepress/estimate', ['reuse_asset'=>$result['asset_id'], 'style_slug'=>'example', 'prompt'=>'New request', 'aspect_ratio'=>'1:1'])->assertNotFound();
});

it('serves the generated image inline from the details page and shows it above the manifest', function () {
    $root = sys_get_temp_dir().'/gen-output-'.Str::uuid();
    File::makeDirectory($root, 0770, true, true);
    $image = UploadedFile::fake()->image('output.png', 12, 9);
    copy($image->getRealPath(), $root.'/output.png');
    $manifest = ['schema_version' => 1, 'generation_id' => 'mothership-'.Str::uuid(), 'title' => 'Inline Output Check', 'kind' => 'image', 'provider' => 'higgsfield-generate', 'model' => 'gpt_image_2', 'prompt' => 'A cube', 'style' => ['title' => 'Cubes', 'category' => '01 Marketing', 'slug' => 'cubes', 'template' => 'T'], 'settings' => [], 'output' => ['path' => 'output.png', 'sha256' => hash_file('sha256', $root.'/output.png'), 'mime' => 'image/png', 'width' => 12, 'height' => 9], 'references' => []];
    file_put_contents($root.'/generation.json', json_encode($manifest));
    try {
        $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: $root.'/generation.json');
        $outputUrl = '/admin/dam/assets/'.$result['asset_id'].'/generation/output';
        $this->get('/admin/dam/assets/'.$result['asset_id'].'/generation/view')->assertOk()
            ->assertSee($outputUrl, false)->assertSee('Generated output')->assertSee('Open full size')->assertSee($outputUrl.'?download=1', false);
        $response = $this->get($outputUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        expect(strlen($response->streamedContent()))->toBe(filesize($root.'/output.png'));
        $this->get('/admin/dam/assets/999999/generation/output')->assertNotFound();
    } finally {
        File::deleteDirectory($root);
    }
});

it('links the generated asset to the library assets it was derived from, once', function () {
    $source = Asset::factory()->create(['file_name' => 'source.png', 'file_type' => 'image', 'path' => 'sources/source.png']);
    $this->manifest['references'][0]['asset_id'] = $source->id;
    $this->manifest['references'][] = ['path' => $this->manifest['references'][0]['path'], 'name' => 'ghost.png', 'role' => 'source', 'sha256' => $this->manifest['references'][0]['sha256'], 'asset_id' => 987654];
    $importer = app(GenerationPackageImporter::class);
    $first = $importer->importManifest(manifestPath: ($this->writePackage)());
    $links = AssetRelationship::query()->where('from_asset_id', $first['asset_id'])->get();
    expect($links)->toHaveCount(1)
        ->and($links[0]->to_asset_id)->toBe($source->id)
        ->and($links[0]->type)->toBe(AssetRelationship::TYPE_DERIVED_FROM)
        ->and($links[0]->metadata['generation_ids'])->toBe([$this->manifest['generation_id']]);
    $importer->importManifest(manifestPath: ($this->writePackage)());
    expect(AssetRelationship::query()->where('from_asset_id', $first['asset_id'])->count())->toBe(1);
    // A revised manifest that points at a different source replaces the importer's edge; edges made by people or
    // other tools survive, even a derived-from edge whose metadata happens to carry a generation id.
    $manual = AssetRelationship::create(['from_asset_id' => $first['asset_id'], 'to_asset_id' => $source->id, 'type' => AssetRelationship::TYPE_VERSION_OF, 'metadata' => null, 'created_by' => null]);
    $foreign = Asset::factory()->create(['file_name' => 'foreign.png', 'file_type' => 'image', 'path' => 'sources/foreign.png']);
    $tool = AssetRelationship::create(['from_asset_id' => $first['asset_id'], 'to_asset_id' => $foreign->id, 'type' => AssetRelationship::TYPE_DERIVED_FROM, 'metadata' => ['generation_id' => 'sphereseam:123', 'generation_ids' => ['x']], 'created_by' => 1]);
    $replacement = Asset::factory()->create(['file_name' => 'replacement.png', 'file_type' => 'image', 'path' => 'sources/replacement.png']);
    $this->manifest['references'][0]['asset_id'] = $replacement->id;
    $this->manifest['prompt'] = 'Revised with a new source';
    $importer->importManifest(manifestPath: ($this->writePackage)());
    $derived = AssetRelationship::query()->where('from_asset_id', $first['asset_id'])->where('type', AssetRelationship::TYPE_DERIVED_FROM)->orderBy('id')->get();
    expect($derived->pluck('to_asset_id')->all())->toBe([$foreign->id, $replacement->id])
        ->and($derived[1]->metadata['owner'])->toBe(GenerationPackageImporter::LINK_OWNER)
        ->and(AssetRelationship::query()->whereKey($manual->id)->exists())->toBeTrue()
        ->and(AssetRelationship::query()->whereKey($tool->id)->firstOrFail()->metadata)->toBe(['generation_id' => 'sphereseam:123', 'generation_ids' => ['x']]);
    // Dropping every library reference removes the importer-owned edge as well.
    $this->manifest['references'] = [];
    $this->manifest['prompt'] = 'Revised without sources';
    $importer->importManifest(manifestPath: ($this->writePackage)());
    expect(AssetRelationship::query()->where('from_asset_id', $first['asset_id'])->where('type', AssetRelationship::TYPE_DERIVED_FROM)->pluck('id')->all())->toBe([$tool->id])
        ->and(AssetRelationship::query()->whereKey($manual->id)->exists())->toBeTrue();
    $this->manifest['references'] = [['path' => 'references/0.png', 'name' => 'ghost.png', 'role' => 'source', 'sha256' => str_repeat('a', 64), 'asset_id' => 'not-an-id']];
    expect(fn () => $importer->importManifest(manifestPath: ($this->writePackage)()))->toThrow(ValidationException::class);
});

it('keeps a source link alive while any generation sharing the same output still uses it', function () {
    $source = Asset::factory()->create(['file_name' => 'shared-source.png', 'file_type' => 'image', 'path' => 'sources/shared-source.png']);
    $importer = app(GenerationPackageImporter::class);
    $this->manifest['references'][0]['asset_id'] = $source->id;
    $this->manifest['generation_id'] = 'device:first';
    $first = $importer->importManifest(manifestPath: ($this->writePackage)());
    $this->manifest['generation_id'] = 'device:second'; // identical output bytes dedupe onto the same asset
    $second = $importer->importManifest(manifestPath: ($this->writePackage)());
    expect($second['asset_id'])->toBe($first['asset_id']);
    $edge = AssetRelationship::query()->where('from_asset_id', $first['asset_id'])->where('to_asset_id', $source->id)->where('type', AssetRelationship::TYPE_DERIVED_FROM)->firstOrFail();
    expect($edge->metadata['generation_ids'])->toBe(['device:first', 'device:second']);
    // Re-importing the first with a different name keeps its position: the metadata still comes from the earliest
    // generation's descriptor as now stored, and the ids stay oldest first.
    $this->manifest['generation_id'] = 'device:first';
    $this->manifest['references'][0]['name'] = 'Renamed evidence';
    $this->manifest['references'][0]['role'] = 'mask';
    $this->manifest['prompt'] = 'First revised with a renamed source';
    $importer->importManifest(manifestPath: ($this->writePackage)());
    $edge->refresh();
    expect($edge->metadata['generation_ids'])->toBe(['device:first', 'device:second'])
        ->and($edge->metadata['reference'])->toBe('Renamed evidence')->and($edge->metadata['role'])->toBe('mask');
    $this->manifest['references'][0]['name'] = 'Reference evidence';
    $this->manifest['references'][0]['role'] = 'source';
    // The first generation drops the source; the second still needs it, so the edge stays and records only the second.
    $this->manifest['generation_id'] = 'device:first';
    $this->manifest['references'] = [];
    $this->manifest['prompt'] = 'First revised without the source';
    $importer->importManifest(manifestPath: ($this->writePackage)());
    expect(AssetRelationship::query()->whereKey($edge->id)->firstOrFail()->metadata['generation_ids'])->toBe(['device:second']);
    // When the second drops it too, the edge goes.
    $this->manifest['generation_id'] = 'device:second';
    $this->manifest['prompt'] = 'Second revised without the source';
    $importer->importManifest(manifestPath: ($this->writePackage)());
    expect(AssetRelationship::query()->whereKey($edge->id)->exists())->toBeFalse();
});
