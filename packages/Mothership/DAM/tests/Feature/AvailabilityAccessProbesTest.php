<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P2 S3 access matrix: every `bytes` and `listing` route the S2 registry left to S3 (tests/Fixtures/route-probes.php),
 * probed under `grants` by a member who holds every ACL key but is no editor (no dam.asset.update). Each family
 * meets a folder-hidden asset or folder, and unavailable assets (a draft, an expired window) in a granted folder.
 * P2 S4 adds a trashed asset in the granted folder: missing everywhere, like the rest.
 * A bytes route answers each like a missing id, same status and body; a listing leaves each out, while a visible,
 * available asset stays in as the positive control.
 */

function s3ProbeAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** Every registered ACL key except the one that makes an editor, so no route ACL refuses before access is asked. */
function s3ProbeRole(): Role
{
    return tickEveryHomeTool(Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->push('sphereseam')->unique()
            ->reject(fn ($key) => $key === 'dam.asset.update')->values()->all(),
    ]));
}

function s3ProbeFullAdmin(): Admin
{
    return Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]);
}

/** A real PNG on the asset disk, so the editor and thumbnails can read it. */
function s3ProbeImage(string $name, array $attributes = []): Asset
{
    $path = 'assets/Root/'.$name.'-'.Str::lower(Str::random(6)).'.png';
    $image = UploadedFile::fake()->image('x.png', 8, 8);
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($image->getRealPath()));

    return Asset::factory()->create([
        'file_name' => basename($path), 'path' => $path, 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'file_size' => 100,
    ] + $attributes);
}

function s3ProbeLikeMissing(TestResponse $hidden, TestResponse $missing, string $label, int $status = 404): void
{
    expect($hidden->getStatusCode())->toBe($status, $label)
        ->and($missing->getStatusCode())->toBe($status, $label)
        ->and($hidden->getContent())->toBe($missing->getContent(), $label);
}

/** Every folder id anywhere in a tree payload (a list of nodes with `children`). */
function s3ProbeTreeIds(array $nodes): array
{
    return collect($nodes)->flatMap(fn ($node) => [(int) $node['id'], ...s3ProbeTreeIds((array) ($node['children'] ?? []))])->all();
}

/** Every asset id the tree's nodes carry in `assets`. */
function s3ProbeTreeAssetIds(array $nodes): array
{
    return collect($nodes)->flatMap(fn ($node) => [
        ...collect($node['assets'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ...s3ProbeTreeAssetIds((array) ($node['children'] ?? [])),
    ])->all();
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    s3ProbeAccessModel(AccessModel::GRANTS);

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->root = $root;
    $this->granted = Directory::factory()->create(['name' => 'Probe granted folder', 'parent_id' => $root->id]);
    $this->hiddenFolder = Directory::factory()->create(['name' => 'Probe hidden folder', 'parent_id' => $root->id]);
    $this->member = Admin::factory()->create(['status' => 1, 'role_id' => s3ProbeRole()->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->granted->id, 'role_id' => $this->member->role_id, 'created_at' => now(), 'updated_at' => now()]);

    // created_by stays null: the member owns none of them, so ownership never opens a draft.
    $this->visible = s3ProbeImage('probe-visible');
    $this->hidden = s3ProbeImage('probe-hidden');
    $this->draft = s3ProbeImage('probe-draft', ['availability' => Asset::AVAILABILITY_DRAFT]);
    $this->expired = s3ProbeImage('probe-expired', ['available_until' => now()->subMinute()]);
    $this->trashed = s3ProbeImage('probe-trashed');
    $this->granted->assets()->attach([$this->visible->id, $this->draft->id, $this->expired->id, $this->trashed->id]);
    $this->hiddenFolder->assets()->attach($this->hidden->id);
    $this->trashed->delete();

    $this->refused = ['hidden' => $this->hidden, 'draft' => $this->draft, 'expired' => $this->expired, 'trashed' => $this->trashed];
    $this->missingId = Asset::withTrashed()->max('id') + 1000;
    $this->missingFolderId = Directory::max('id') + 1000;
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);
});

it('answers a hidden or unavailable asset like a missing one on the editor resource, generation file and attachments zip', function () {
    // Editor resources, opened by a full admin so each image has a base resource with a blob behind it; the
    // trashed asset is out of the trash for this setup only.
    $this->trashed->restore();
    $this->actingAs(s3ProbeFullAdmin(), 'admin');
    $base = collect([...$this->refused, 'visible' => $this->visible])
        ->map(fn (Asset $asset) => $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json('document.base'));

    // A generated asset with a reference file, through the real importer.
    $package = storage_path('framework/testing/s3-probe-'.Str::uuid());
    File::makeDirectory($package, 0700, true);
    $output = UploadedFile::fake()->image('o.png', 3, 2);
    File::put($package.'/output.png', file_get_contents($output->getRealPath()));
    File::put($package.'/reference.txt', 'Reference evidence');
    File::put($package.'/generation.json', json_encode([
        'schema_version' => 1, 'generation_id' => 'device:s3-probe', 'title' => 'Probe generation', 'kind' => 'image',
        'provider'       => 'higgsfield-generate', 'model' => 'gpt_image_2', 'prompt' => 'Probe prompt', 'original_prompt' => 'Probe',
        'settings'       => ['quality' => 'low', 'aspect_ratio' => '3:2', 'resolution' => '1k'], 'style' => null,
        'output'         => ['path' => 'output.png', 'sha256' => hash_file('sha256', $package.'/output.png'), 'mime' => 'image/png'],
        'references'     => [['path' => 'reference.txt', 'sha256' => hash_file('sha256', $package.'/reference.txt'), 'name' => 'Reference evidence', 'role' => 'source']],
    ]));

    try {
        $generated = Asset::findOrFail(app(GenerationPackageImporter::class)->importManifest(manifestPath: $package.'/generation.json')['asset_id']);
    } finally {
        File::deleteDirectory($package);
    }

    $reference = (int) $this->getJson(route('admin.dam.assets.generation', $generated->id))->assertOk()->json('reference_urls.0.file_id');

    // Attachments for the zip.
    $attachment = collect([...$this->refused, 'visible' => $this->visible])
        ->map(fn (Asset $asset) => attachPreviewFile($asset, 'assets/Root/'.$asset->id.'-sheet.pdf', 'application/pdf', AssetFile::ROLE_SIDECAR)->id);
    $this->trashed->delete();

    $this->actingAs($this->member, 'admin');
    $missing = $this->missingId;

    foreach ($this->refused as $case => $asset) {
        s3ProbeLikeMissing(
            $this->get(route('admin.dam.assets.editor.resource', ['id' => $asset->id, 'resource' => $base[$case]])),
            $this->get(route('admin.dam.assets.editor.resource', ['id' => $missing, 'resource' => $base[$case]])),
            "editor.resource {$case}",
        );
        s3ProbeLikeMissing(
            $this->get(route('admin.dam.file.asset-files-zip', $asset->id)),
            $this->get(route('admin.dam.file.asset-files-zip', $missing)),
            "asset-files-zip {$case}",
        );
        s3ProbeLikeMissing(
            $this->get(route('admin.dam.file.asset-files-zip', ['asset' => $asset->id, 'files' => [$attachment[$case]]])),
            $this->get(route('admin.dam.file.asset-files-zip', ['asset' => $missing, 'files' => [$attachment[$case]]])),
            "asset-files-zip {$case} named file",
        );
    }

    // The generated asset: in its own folder only (hidden), then in a granted folder as a draft, as expired.
    $generationFile = fn (int $id) => $this->get(route('admin.dam.assets.generation.file', ['asset' => $id, 'file' => $reference]));
    s3ProbeLikeMissing($generationFile($generated->id), $generationFile($missing), 'generation.file hidden');
    $this->granted->assets()->attach($generated->id);
    foreach (['draft' => ['availability' => Asset::AVAILABILITY_DRAFT], 'expired' => ['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_until' => now()->subMinute()]] as $case => $state) {
        $generated->update($state);
        s3ProbeLikeMissing($generationFile($generated->id), $generationFile($missing), "generation.file {$case}");
    }

    // Positive control: the same reads open for a visible, available asset.
    $generated->update(['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_until' => null]);
    $this->get(route('admin.dam.assets.editor.resource', ['id' => $this->visible->id, 'resource' => $base['visible']]))->assertOk();
    $this->get(route('admin.dam.file.asset-files-zip', ['asset' => $this->visible->id, 'files' => [$attachment['visible']]]))->assertOk();
    $generationFile($generated->id)->assertOk();
});

it('answers another admin\'s StylePress, vectorization and tool files, and any asset id or path, like missing ones', function () {
    Http::fake();
    Storage::fake('local');
    Storage::fake('private');
    config(['stylepress.enabled' => true, 'stylepress.import_root' => storage_path('framework/testing/stylepress-none-'.Str::uuid())]);
    $other = Admin::factory()->create(['status' => 1, 'role_id' => $this->member->role_id]);
    $uuid = fn () => (string) Str::uuid();

    // Another admin's StylePress generations, saved references and vectorizations of each refused asset.
    $rows = collect($this->refused)->map(function (Asset $asset) use ($other, $uuid) {
        $generation = $uuid();
        DB::table('dam_home_generations')->insert(['id' => $generation, 'admin_id' => $other->id, 'request_id' => $generation, 'status_url' => 'higgsfield-cli:'.$generation, 'status' => 'completed', 'save_status' => 'saved', 'asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now()]);
        $reference = $uuid();
        Storage::disk('local')->put('stylepress/references/'.$reference.'.png', 'reference-bytes');
        DB::table('dam_stylepress_references')->insert(['id' => $reference, 'admin_id' => $other->id, 'path' => 'stylepress/references/'.$reference.'.png', 'name' => $asset->file_name, 'sha256' => str_repeat('a', 64), 'asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now()]);
        $vectorization = $uuid();
        DB::table('dam_vectorizations')->insert(['id' => $vectorization, 'admin_id' => $other->id, 'request_id' => $vectorization, 'input_sha' => str_repeat('b', 64), 'input_path' => 'vectorizations/input/'.$vectorization, 'charged' => 1, 'status' => 'completed', 'source_asset_id' => $asset->id, 'output_format' => 'svg', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('private')->put('vectorizations/results/'.$vectorization.'/result.svg', '<svg/>');
        $toolFile = $uuid();
        DB::table('dam_home_tool_files')->insert(['file_id' => $toolFile, 'admin_id' => $other->id, 'created_at' => now()]);

        return compact('generation', 'reference', 'vectorization', 'toolFile');
    });

    // The member's own reference and vectorization: the positive control.
    $ownReference = $uuid();
    Storage::disk('local')->put('stylepress/references/'.$ownReference.'.png', 'own-reference');
    DB::table('dam_stylepress_references')->insert(['id' => $ownReference, 'admin_id' => $this->member->id, 'path' => 'stylepress/references/'.$ownReference.'.png', 'name' => 'own.png', 'sha256' => str_repeat('c', 64), 'asset_id' => $this->visible->id, 'created_at' => now(), 'updated_at' => now()]);
    $ownVectorization = $uuid();
    DB::table('dam_vectorizations')->insert(['id' => $ownVectorization, 'admin_id' => $this->member->id, 'request_id' => $ownVectorization, 'input_sha' => str_repeat('d', 64), 'input_path' => 'vectorizations/input/'.$ownVectorization, 'charged' => 1, 'status' => 'completed', 'source_asset_id' => $this->visible->id, 'output_format' => 'svg', 'created_at' => now(), 'updated_at' => now()]);
    Storage::disk('private')->put('vectorizations/results/'.$ownVectorization.'/result.svg', '<svg/>');

    $this->actingAs($this->member, 'admin');
    $missing = $uuid();

    foreach ($this->refused as $case => $asset) {
        $row = $rows[$case];
        s3ProbeLikeMissing($this->getJson(route('admin.home.stylepress.generation', $row['generation'])), $this->getJson(route('admin.home.stylepress.generation', $missing)), "stylepress.generation {$case}");
        s3ProbeLikeMissing($this->get(route('admin.home.stylepress.output', $row['generation'])), $this->get(route('admin.home.stylepress.output', $missing)), "stylepress.output {$case}");
        s3ProbeLikeMissing($this->get(route('admin.home.stylepress.reference-file', $row['reference'])), $this->get(route('admin.home.stylepress.reference-file', $missing)), "stylepress.reference-file {$case}");
        s3ProbeLikeMissing($this->get(route('admin.home.vectorization.file', ['id' => $row['vectorization'], 'format' => 'svg'])), $this->get(route('admin.home.vectorization.file', ['id' => $missing, 'format' => 'svg'])), "vectorization.file {$case}");

        // A style or widget file is addressed by catalog slug or bundled path, never by an asset's id or path.
        s3ProbeLikeMissing($this->get(route('admin.home.stylepress.thumbnail', (string) $asset->id)), $this->get(route('admin.home.stylepress.thumbnail', 'no-such-style')), "stylepress.thumbnail {$case}");
        s3ProbeLikeMissing($this->get(route('admin.home.stylepress.source', ['style' => (string) $asset->id, 'attachment' => 0])), $this->get(route('admin.home.stylepress.source', ['style' => 'no-such-style', 'attachment' => 0])), "stylepress.source {$case}");
        s3ProbeLikeMissing($this->get(route('admin.home.tool', ['widget' => 'looper', 'path' => $asset->path])), $this->get(route('admin.home.tool', ['widget' => 'looper', 'path' => 'assets/Root/nothing-here.png'])), "tool {$case}");

        // The service proxy reaches only files the member's own tool sessions issued; nothing goes upstream.
        s3ProbeLikeMissing($this->get(route('admin.home.service', ['service' => 'looper', 'path' => 'download/'.$row['toolFile'].'.mp4'])), $this->get(route('admin.home.service', ['service' => 'looper', 'path' => 'download/'.$missing.'.mp4'])), "service {$case}", status: 403);
        s3ProbeLikeMissing($this->get(route('admin.home.service', ['service' => 'looper', 'path' => 'download/'.$asset->file_name])), $this->get(route('admin.home.service', ['service' => 'looper', 'path' => 'download/nothing-here.png'])), "service path {$case}", status: 403);
    }
    Http::assertNothingSent();

    $this->get(route('admin.home.stylepress.reference-file', $ownReference))->assertOk();
    $this->get(route('admin.home.vectorization.file', ['id' => $ownVectorization, 'format' => 'svg']))->assertOk();
    $this->get(route('admin.home.tool', ['widget' => 'looper', 'path' => 'vite.svg']))->assertOk();
});

it('leaves hidden folders and hidden or unavailable assets out of every folder-tree listing and count', function () {
    config(['dam.tree.show_assets' => true]);
    $this->actingAs($this->member, 'admin');
    $refusedIds = collect($this->refused)->map->id->values()->all();
    $leaked = fn (TestResponse $response) => collect([...array_values($this->refused), $this->hiddenFolder])
        ->filter(fn ($item) => str_contains($response->getContent(), $item instanceof Asset ? $item->file_name : $item->name))->keys()->all();

    // The tree, with and without its files. A subfolder of the granted folder is not granted (no inheritance), so
    // the granted folder shows no children rather than an expander that opens onto nothing.
    $hiddenChild = Directory::factory()->create(['name' => 'Probe hidden child', 'parent_id' => $this->granted->id]);
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->canView($hiddenChild->id))->toBeFalse();
    $tree = $this->getJson(route('admin.dam.directory.index'))->assertOk();
    $treeNode = collect($tree->json('data'))->flatMap(fn ($node) => [$node, ...($node['children'] ?? [])])->firstWhere('id', $this->granted->id);
    expect(s3ProbeTreeIds($tree->json('data')))->toContain($this->granted->id)->not->toContain($this->hiddenFolder->id)
        ->and($treeNode)->toMatchArray(['children_count' => 0, 'has_children' => false])
        ->and(str_contains($tree->getContent(), $hiddenChild->name))->toBeFalse()
        ->and($leaked($tree))->toBe([]);
    $withAssets = $this->getJson(route('admin.dam.directory.index', ['with_assets' => 1]))->assertOk();
    $grantedNode = collect($withAssets->json('data'))->flatMap(fn ($node) => [$node, ...($node['children'] ?? [])])->firstWhere('id', $this->granted->id);
    expect(s3ProbeTreeIds($withAssets->json('data')))->not->toContain($this->hiddenFolder->id)
        ->and(s3ProbeTreeAssetIds($withAssets->json('data')))->toBe([$this->visible->id])
        ->and($grantedNode['assets_total_count'])->toBe(1)
        ->and($leaked($withAssets))->toBe([]);

    // Folder counts count only what the member can open.
    expect($this->postJson(route('admin.dam.directory.asset_counts'), ['ids' => [$this->granted->id, $this->hiddenFolder->id]])->assertOk()->json('data'))
        ->toBe([(string) $this->granted->id => 1]);

    // A folder's files, its descendants and the paths: a hidden folder answers like a missing one.
    $assets = $this->getJson(route('admin.dam.directory.assets', $this->granted->id))->assertOk();
    expect(collect($assets->json('data'))->pluck('id')->all())->toBe([$this->visible->id])
        ->and(array_intersect(collect($assets->json('data'))->pluck('id')->all(), $refusedIds))->toBe([]);
    expect($this->getJson(route('admin.dam.directory.assets', $this->hiddenFolder->id))->getContent())
        ->toBe($this->getJson(route('admin.dam.directory.assets', $this->missingFolderId))->getContent());

    expect($this->getJson(route('admin.dam.directory.descendants', $this->root->id))->assertOk()->json('data'))
        ->toContain($this->granted->id)->not->toContain($this->hiddenFolder->id);
    expect($this->getJson(route('admin.dam.directory.descendants', $this->hiddenFolder->id))->getContent())
        ->toBe($this->getJson(route('admin.dam.directory.descendants', $this->missingFolderId))->getContent());

    $paths = $this->postJson(route('admin.dam.directory.paths'), ['ids' => [$this->granted->id, $this->hiddenFolder->id]])->assertOk();
    expect(collect($paths->json('data'))->pluck('id')->all())->toContain($this->granted->id)->not->toContain($this->hiddenFolder->id)
        ->and(collect($paths->json('data'))->firstWhere('id', $this->granted->id)['has_children'])->toBeFalse()
        ->and($leaked($paths))->toBe([]);

    $search = $this->getJson(route('admin.dam.directory.search', ['q' => 'Probe']))->assertOk();
    expect(collect($search->json('data'))->pluck('id')->all())->toBe([$this->granted->id])
        ->and($search->json('meta.total'))->toBe(1);
});

it('leaves hidden and unavailable assets out of the Library grid, selection count, filter options, picker and linked resources', function () {
    foreach ([...$this->refused, 'visible' => $this->visible] as $case => $asset) {
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => "probe-{$case}-property", 'is_filterable' => true]);
        DB::table('dam_asset_resource_mappings')->insert(['type' => 'category', 'related_field' => "probe-{$case}", 'dam_asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($this->member, 'admin');
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
    $allIds = [$this->visible->id, ...collect($this->refused)->map->id->values()->all()];

    $grid = $this->getJson(route('admin.dam.assets.index'), $ajax)->assertOk();
    expect(collect($grid->json('records'))->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$this->visible->id]);

    expect($this->postJson(route('admin.dam.explorer.count_items'), ['asset_ids' => $allIds])->assertOk()->json('file_count'))->toBe(1)
        ->and($this->postJson(route('admin.dam.explorer.count_items'), ['directory_ids' => [$this->granted->id, $this->hiddenFolder->id]])->assertOk()->json('file_count'))->toBe(1);

    expect($this->getJson(route('admin.dam.explorer.filter-options'))->assertOk()->json('properties'))->toBe(['probe-visible-property']);

    expect(collect($this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => implode(',', $allIds)]))->assertOk()->json())->pluck('id')->all())
        ->toBe([$this->visible->id]);

    // The linked-resources grid of a hidden or unavailable asset answers like a missing asset's.
    foreach ($this->refused as $case => $asset) {
        s3ProbeLikeMissing(
            $this->getJson(route('admin.dam.asset.linked_resources.index', ['dam_asset_id' => $asset->id]), $ajax),
            $this->getJson(route('admin.dam.asset.linked_resources.index', ['dam_asset_id' => $this->missingId]), $ajax),
            "linked_resources {$case}",
        );
    }
    // A visible asset passes the same check. Its grid rows are not read here: the grid's default sort is ambiguous
    // once products and categories are joined (500), which #135 on main fixes along with its own grid probe.
    $this->get(route('admin.dam.asset.linked_resources.index', ['dam_asset_id' => $this->visible->id]))->assertOk();
});

it('leaves links to hidden and unavailable assets and folders out of the Shared Links page and the share dialog', function () {
    $owner = s3ProbeFullAdmin();
    $shares = collect([...$this->refused, 'visible' => $this->visible])->map(fn (Asset $asset) => Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id, 'name' => null]));
    $folderShares = ['hidden' => Share::factory()->forDirectory($this->hiddenFolder->id)->create(['created_by' => $owner->id, 'name' => null]), 'granted' => Share::factory()->forDirectory($this->granted->id)->create(['created_by' => $owner->id, 'name' => null])];
    $this->actingAs($this->member, 'admin');

    $page = $this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    expect(collect($page->json('records'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$shares['visible']->id, $folderShares['granted']->id])->sort()->values()->all());
    foreach ($this->refused as $case => $asset) {
        expect(str_contains($page->getContent(), $asset->file_name))->toBeFalse("shared-links.index names {$case}")
            ->and(str_contains($page->getContent(), $shares[$case]->urlToken()))->toBeFalse("shared-links.index token {$case}");
    }
    expect(str_contains($page->getContent(), $this->hiddenFolder->name))->toBeFalse();

    $active = fn (string $type, int $id) => $this->getJson(route('admin.dam.shared-links.active_for_target', ['type' => $type, 'targetId' => $id]));
    foreach ($this->refused as $case => $asset) {
        s3ProbeLikeMissing($active(Share::TYPE_ASSET, $asset->id), $active(Share::TYPE_ASSET, $this->missingId), "active_for_target {$case}");
    }
    s3ProbeLikeMissing($active(Share::TYPE_DIRECTORY, $this->hiddenFolder->id), $active(Share::TYPE_DIRECTORY, $this->missingFolderId), 'active_for_target folder');
    $active(Share::TYPE_ASSET, $this->visible->id)->assertOk()->assertJsonPath('shares.0.id', $shares['visible']->id);
});

it('leaves hidden and unavailable assets out of Home recent, totals, favourites, the StylePress browser and the vectorization list', function () {
    config(['stylepress.enabled' => true, 'stylepress.import_root' => storage_path('framework/testing/stylepress-none-'.Str::uuid())]);
    $other = Admin::factory()->create(['status' => 1, 'role_id' => $this->member->role_id]);
    foreach ([...$this->refused, 'visible' => $this->visible] as $case => $asset) {
        DB::table('dam_asset_favorites')->insert(['admin_id' => $this->member->id, 'dam_asset_id' => $asset->id, 'created_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('dam_vectorizations')->insert(['id' => $id, 'admin_id' => $case === 'visible' ? $this->member->id : $other->id, 'request_id' => $id, 'input_sha' => str_repeat('e', 64), 'input_path' => 'vectorizations/input/'.$id, 'charged' => 1, 'status' => 'completed', 'source_asset_id' => $asset->id, 'output_format' => 'svg', 'created_at' => now(), 'updated_at' => now()]);
        $vectorizations[$case] = $id;
    }
    $this->actingAs($this->member, 'admin');
    $ids = fn (TestResponse $response, string $key = 'assets') => collect($response->assertOk()->json($key))->pluck('id')->map(fn ($id) => is_numeric($id) ? (int) $id : $id)->all();

    expect($ids($this->getJson(route('admin.home.data', ['widget' => 'recent', 'limit' => 24]))))->toBe([$this->visible->id]);
    expect($this->getJson(route('admin.home.data', 'totals'))->assertOk()->json())->toMatchArray(['files' => 1, 'bytes' => 100, 'folders' => 1]);
    expect($ids($this->getJson(route('admin.home.favorites.index'))))->toBe([$this->visible->id]);

    foreach (['all', 'recent', 'favorites'] as $scope) {
        expect($ids($this->getJson(route('admin.home.stylepress.assets', ['scope' => $scope]))))->toBe([$this->visible->id], $scope);
    }
    expect($ids($this->getJson(route('admin.home.stylepress.assets', ['scope' => 'folder', 'directory' => $this->granted->id]))))->toBe([$this->visible->id]);
    s3ProbeLikeMissing(
        $this->getJson(route('admin.home.stylepress.assets', ['scope' => 'folder', 'directory' => $this->hiddenFolder->id])),
        $this->getJson(route('admin.home.stylepress.assets', ['scope' => 'folder', 'directory' => $this->missingFolderId])),
        'stylepress.assets folder',
    );

    $folders = $this->getJson(route('admin.home.stylepress.folders'))->assertOk();
    expect(s3ProbeTreeIds($folders->json('data')))->toContain($this->granted->id)->not->toContain($this->hiddenFolder->id)
        ->and(str_contains($folders->getContent(), $this->hiddenFolder->name))->toBeFalse();
    s3ProbeLikeMissing(
        $this->getJson(route('admin.home.stylepress.folders', ['parent' => $this->hiddenFolder->id])),
        $this->getJson(route('admin.home.stylepress.folders', ['parent' => $this->missingFolderId])),
        'stylepress.folders parent',
    );

    expect(collect($this->getJson(route('admin.home.vectorization.state'))->assertOk()->json('results'))->pluck('id')->all())->toBe([$vectorizations['visible']]);
});

it('leaves hidden folders and hidden or unavailable assets and links out of the REST directory, share and tag listings', function () {
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);
    $owner = s3ProbeFullAdmin();
    $shares = collect([...$this->refused, 'visible' => $this->visible])->map(fn (Asset $asset) => Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id]));
    Share::factory()->forDirectory($this->hiddenFolder->id)->create(['created_by' => $owner->id]);
    $grantedShare = Share::factory()->forDirectory($this->granted->id)->create(['created_by' => $owner->id]);
    Tag::query()->create(['name' => 'probe-hidden-tag'])->assets()->attach($this->hidden->id);
    app(DirectoryPermissionService::class)->flush();
    $rest = fn (string $route, array $query = []) => $this->withHeaders($headers)->getJson(route($route, $query))->assertOk();

    $tree = $rest('admin.api.dam.directory.index');
    expect(s3ProbeTreeIds($tree->json('data')))->toContain($this->granted->id)->not->toContain($this->hiddenFolder->id)
        ->and(str_contains($tree->getContent(), $this->hiddenFolder->name))->toBeFalse();
    $withAssets = $rest('admin.api.dam.directory.index', ['with_assets' => 1]);
    expect(s3ProbeTreeAssetIds($withAssets->json('data')))->toBe([$this->visible->id]);

    $listed = $rest('admin.api.dam.shares.index', ['limit' => 100]);
    expect(collect($listed->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$shares['visible']->id, $grantedShare->id])->sort()->values()->all());

    // Tags are a shared vocabulary, not a read of any asset: each entry is an id and a name, with no asset id or count.
    $tags = $rest('admin.api.dam.tags.all', ['per_page' => 100]);
    expect(collect($tags->json('data'))->map(fn ($tag) => array_keys($tag))->unique()->values()->all())->toBe([['id', 'name']]);
});

it('cannot enumerate a hidden or unavailable asset through the tag vocabulary or the groups page', function (string $model) {
    s3ProbeAccessModel($model);
    Tag::query()->create(['name' => 'probe-hidden-tag'])->assets()->attach([$this->hidden->id, $this->draft->id]);
    $this->actingAs($this->member, 'admin');

    // Tags are a shared vocabulary managed on the Tags page: the list names no asset, count or id.
    $tags = $this->getJson(route('admin.dam.tags.list', ['per_page' => 100]))->assertOk();
    expect(collect($tags->json('data'))->map(fn ($tag) => array_keys($tag))->unique()->values()->all())->toBe([['id', 'name']]);
    foreach ($this->refused as $asset) {
        expect(str_contains($tags->getContent(), $asset->file_name))->toBeFalse();
    }

    // The groups page lists people and, under lists, folder roots; only a full admin may read it.
    $this->getJson(route('admin.settings.groups.list'))->assertForbidden();
})->with([AccessModel::GRANTS, AccessModel::LISTS]);
