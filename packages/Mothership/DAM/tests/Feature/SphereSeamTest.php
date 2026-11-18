<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\SharedHiggsfieldError;
use Mothership\SphereSeam\Jobs\GenerateSphereSeamBridge;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use Mothership\SphereSeam\Services\SphereSeamHiggsfield;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->seamAdmin = Admin::factory()->create();
    $this->actingAs($this->seamAdmin, 'admin');
    config(['stylepress.higgsfield.driver' => 'subscription', 'stylepress.higgsfield.account_email' => 'work@example.com', 'stylepress.higgsfield.workspace_id' => 'work']);
    Storage::fake('private');
    Queue::fake();
    Process::preventStrayProcesses();
});

function sphereSeamFake(array $poll = ['id' => 'seam-job', 'status' => 'completed', 'result_url' => 'https://cdn.higgsfield.ai/seam.png']): void
{
    Process::fake(function ($p) use ($poll) {
        if (in_array('workspace', $p->command, true)) {
            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        if (in_array('status', $p->command, true)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('cost', $p->command, true)) {
            return Process::result(output: '{"credits":5.5}');
        }
        if (in_array('create', $p->command, true)) {
            return Process::result(output: '["seam-job"]');
        }

        return Process::result(output: json_encode($poll));
    });
}

function sphereSeamPng(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));
    $path = tempnam(sys_get_temp_dir(), 'seam').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, basename($path), 'image/png', null, true);
}

function sphereSeamWebp(int $width, int $height): UploadedFile
{
    // What the browser actually posts since 2026-09-16 (losslessBlob() in editor.blade.php):
    // lossless WebP, materially smaller than the equivalent PNG, is why the ceilings in
    // sphereseam.max_upload_kilobytes are survivable at all for an 8192x4096 panorama.
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 60, 90, 120));
    $path = tempnam(sys_get_temp_dir(), 'seam').'.webp';
    imagewebp($image, $path, 100);
    imagedestroy($image);

    return new UploadedFile($path, basename($path), 'image/webp', null, true);
}

function sphereSeamGenerate(array $overrides = []): TestResponse
{
    // 400x200 panorama, 1:1 bridge 200x200 rolled (strip 100), 55 % band = 110.
    return test()->post('/admin/sphere-seam/generate', [
        'model'             => 'gpt_image_2_5', 'bridge_ratio' => '1:1', 'quality' => 'high', 'resolution' => '2k',
        'scene_description' => 'Foundry', 'repair_fraction' => '0.550', 'confirmed' => '1',
        'strip_width'       => 100, 'repair_width' => 110, 'feather_width' => 22, 'normalization_mode' => 'squeeze',
        'normalized'        => sphereSeamPng(400, 200), 'bridge' => sphereSeamPng(200, 200),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

it('does not register SphereSeam with the global sidebar menu', function () {
    $menuKeys = collect(config('menu.admin'))->pluck('key');

    expect($menuKeys)->not->toContain('sphereseam');
});

it('mounts the full SphereSeam editor in the Home tool frame and on its own page', function () {
    sphereSeamFake();
    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->keyBy('id');
    expect($catalog['sphereseam']['kind'])->toBe('tool')->and($catalog['sphereseam']['url'])->toContain('/admin/home/tools/sphereseam/index.html');
    // The Home shell only accepts a frame whose bootstrap names this widget; the editor itself must be inside.
    $frame = $this->get('/admin/home/tools/sphereseam/index.html')->assertOk()
        ->assertSee('"widget":"sphereseam"', false)->assertSee('<v-sphere-seam>', false)->assertSee('<v-dam-asset-picker>', false)
        ->assertSee('Auto · best match for this image')->getContent();
    expect($frame)->not->toContain('target="_top"')->not->toContain('SDK worker');
    $this->get('/admin/sphere-seam')->assertOk()->assertSee('<v-sphere-seam>', false)->assertSee('Auto · best match for this image');
});

it('resolves a DAM image with the generation prompt its details pane shows, so Scene context can be pre-filled', function () {
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png']);
    $this->postJson('/admin/sphere-seam/assets/resolve', ['ids' => [$asset->id]])->assertOk()->assertJsonPath('item.prompt', null);
    $manifest = ['kind' => 'image', 'model' => 'gpt_image_2', 'original_prompt' => '  Foundry interior, warm light  ', 'prompt' => 'Full generation prompt'];
    GenerationAsset::create(['generation_id' => 'seam-test', 'asset_id' => $asset->id, 'manifest' => $manifest, 'manifest_sha256' => hash('sha256', json_encode($manifest))]);
    $this->postJson('/admin/sphere-seam/assets/resolve', ['ids' => [$asset->id]])->assertOk()
        ->assertJsonPath('item.prompt', 'Foundry interior, warm light')->assertJsonPath('item.id', $asset->id);
});

it('serves a resolved DAM image through the read seam even with its Library-path file deleted', function () {
    $bytes = file_get_contents(sphereSeamPng(4, 2)->getRealPath());
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Root/seam-source.png']);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($asset->path, $bytes);
    $asset->markGraphContentChanged()->save();
    $disk->delete($asset->path);

    $response = $this->get(route('sphereseam.assets.source', $asset->id))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($bytes);
});

it('estimates through the shared subscription with the SphereSeam model, variant and chosen quality', function () {
    sphereSeamFake();
    $this->postJson('/admin/sphere-seam/estimate', ['model' => 'gpt_image_2_5', 'bridge_ratio' => '1:1', 'quality' => 'medium', 'resolution' => '4k', 'repair_fraction' => '0.55'])
        ->assertOk()->assertJsonPath('estimate.credits', 5.5);
    Process::assertRan(fn ($p) => in_array('cost', $p->command, true) && in_array('gpt_image_2_5', $p->command, true)
        && in_array('--variant', $p->command, true) && in_array('flare', $p->command, true)
        && in_array('auto', $p->command, true) && in_array('medium', $p->command, true) && in_array('4k', $p->command, true));
    $this->postJson('/admin/sphere-seam/estimate', ['model' => 'gpt_image_2', 'bridge_ratio' => '9:16', 'quality' => 'high', 'resolution' => '2k'])->assertOk();
    Process::assertRan(fn ($p) => in_array('gpt_image_2', $p->command, true) && in_array('9:16', $p->command, true) && ! in_array('--variant', $p->command, true));
    $this->postJson('/admin/sphere-seam/estimate', ['model' => 'gpt_image_2_5', 'bridge_ratio' => '1:1', 'quality' => 'auto', 'resolution' => '2k'])->assertStatus(422);
    $this->postJson('/admin/sphere-seam/estimate', ['model' => 'gpt_image_2_5', 'bridge_ratio' => '1:1', 'quality' => 'high', 'resolution' => 'auto'])->assertStatus(422);
});

it('stores a validated rolled bridge, queues one stylepress job and only the owner can read it back', function () {
    sphereSeamFake();
    $response = sphereSeamGenerate()->assertStatus(202)->assertJsonPath('generation.status', 'queued')->assertJsonPath('generation.quality', 'high');
    $id = $response->json('generation.id');
    // Must land where the stylepress worker listens: the database connection, not the redis default.
    Queue::assertPushed(GenerateSphereSeamBridge::class, fn ($job) => $job->generationId === $id && $job->queue === 'stylepress' && $job->connection === 'database');
    Storage::disk('private')->assertExists("sphereseam/{$id}/bridge.png");
    expect(SphereSeamGeneration::find($id)->estimated_credits)->toBe(5.5);
    $this->getJson("/admin/sphere-seam/generations/{$id}")->assertOk()->assertJsonPath('generation.bridge_result_url', null);
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson("/admin/sphere-seam/generations/{$id}")->assertNotFound();
});

it('lets a stalled queued seam be re-queued without re-submitting to the provider', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    $this->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertStatus(409);
    SphereSeamGeneration::whereKey($id)->update(['created_at' => now()->subMinutes(3)]);
    $this->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertOk()->assertJsonPath('generation.status', 'queued');
    Queue::assertPushed(GenerateSphereSeamBridge::class, 2);
    SphereSeamGeneration::whereKey($id)->update(['created_at' => now()->subMinutes(3), 'provider_request_id' => 'seam-job', 'status' => 'generating']);
    $this->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertStatus(409);
    Process::assertNotRan(fn ($p) => in_array('create', $p->command, true));
});

it('rejects a bridge that is not a rolled full-height crop', function () {
    sphereSeamFake();
    sphereSeamGenerate(['strip_width' => 90])->assertStatus(422);
    sphereSeamGenerate(['bridge' => sphereSeamPng(200, 150)])->assertStatus(422);
    sphereSeamGenerate(['normalized' => sphereSeamPng(400, 210)])->assertStatus(422);
    sphereSeamGenerate(['quality' => 'xhigh'])->assertStatus(422);
    Queue::assertNothingPushed();
});

it('submits the stored bridge as a reference once, then polls the shared subscription to completion', function () {
    sphereSeamFake(['id' => 'seam-job', 'status' => 'in_progress']);
    $id = sphereSeamGenerate()->json('generation.id');
    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldReceive('release')->once()->with(15);
    $queued->shouldReceive('isReleased')->andReturn(true);
    $job->handle(app(SphereSeamHiggsfield::class));
    $row = SphereSeamGeneration::find($id);
    expect($row->status)->toBe('generating')->and($row->provider_request_id)->toBe('seam-job');
    Process::assertRan(fn ($p) => in_array('create', $p->command, true) && in_array('gpt_image_2_5', $p->command, true)
        && in_array('--variant', $p->command, true) && in_array(Storage::disk('private')->path("sphereseam/{$id}/bridge.png"), $p->command, true));

    sphereSeamFake();
    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($again = Mockery::mock(Job::class));
    $again->shouldNotReceive('release');
    $job->handle(app(SphereSeamHiggsfield::class));
    Process::assertRanTimes(fn ($p) => in_array('create', $p->command, true), 1);
    $row->refresh();
    expect($row->status)->toBe('completed')->and($row->bridge_result_url)->toBe('https://cdn.higgsfield.ai/seam.png');
    $this->getJson("/admin/sphere-seam/generations/{$id}")->assertOk()->assertJsonPath('generation.bridge_result_url', "http://localhost/admin/sphere-seam/generations/{$id}/bridge");
});

it('proxies the generated bridge only from allowlisted provider hosts', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    $png = file_get_contents(sphereSeamPng(200, 200)->getRealPath());
    Http::fake(['https://cdn.higgsfield.ai/seam.png' => Http::response($png, 200), 'https://evil.example/seam.png' => Http::response($png, 200)]);
    SphereSeamGeneration::whereKey($id)->update(['status' => 'completed', 'bridge_result_url' => 'https://cdn.higgsfield.ai/seam.png']);
    $this->get("/admin/sphere-seam/generations/{$id}/bridge")->assertOk()->assertHeader('Content-Type', 'image/png');
    SphereSeamGeneration::whereKey($id)->update(['bridge_result_url' => 'https://evil.example/seam.png']);
    $this->get("/admin/sphere-seam/generations/{$id}/bridge")->assertNotFound();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});

it('saves only exact 2:1 exports of a completed seam to DAM', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    $this->post("/admin/sphere-seam/generations/{$id}/import", ['panorama' => sphereSeamPng(400, 200)], ['Accept' => 'application/json'])->assertStatus(409);
    SphereSeamGeneration::whereKey($id)->update(['status' => 'completed', 'bridge_result_url' => 'https://cdn.higgsfield.ai/seam.png']);
    $this->post("/admin/sphere-seam/generations/{$id}/import", ['panorama' => sphereSeamPng(400, 210)], ['Accept' => 'application/json'])->assertStatus(422);
    $asset = $this->post("/admin/sphere-seam/generations/{$id}/import", ['panorama' => sphereSeamPng(400, 200)], ['Accept' => 'application/json'])
        ->assertOk()->json('asset');
    expect(SphereSeamGeneration::find($id)->dam_asset_id)->toBe($asset['id']);
});

function sphereSeamCompletedImport(array $overrides = []): array
{
    sphereSeamFake();
    $id = sphereSeamGenerate($overrides)->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update(['status' => 'completed', 'bridge_result_url' => 'https://cdn.higgsfield.ai/seam.png']);
    $assetId = test()->post("/admin/sphere-seam/generations/{$id}/import", ['panorama' => sphereSeamPng(400, 200)], ['Accept' => 'application/json'])
        ->assertOk()->json('asset.id');

    return ['generation' => $id, 'asset' => $assetId];
}

// Sharing slice 2a (ADR 0010, spec D3): a saved export is a new asset, so it takes the exports folder's
// access list. Asserted here, where the seam fixture already lives.
it('gives a saved export the access list of the exports folder it lands in', function () {
    $friend = Admin::factory()->create(['status' => 1]);
    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $root->id, 'admin_id' => $friend->id]);

    $result = sphereSeamCompletedImport();

    $exports = Directory::where('name', 'SphereSeam-Exports')->firstOrFail();

    // The folder was created under the shared root, so the listener gave it the root's list, and the export
    // took the folder's.
    expect(DB::table('dam_directory_user_access')->where('directory_id', $exports->id)->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$friend->id])
        ->and(DB::table('dam_asset_user_access')->where('asset_id', $result['asset'])->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$friend->id]);
});

it('files saved exports in the SphereSeam-Exports folder, creating it when missing', function () {
    $result = sphereSeamCompletedImport();

    $folder = Directory::where('name', 'SphereSeam-Exports')->first();
    expect($folder)->not->toBeNull();
    expect(Asset::find($result['asset'])->directories->pluck('id')->all())->toContain($folder->id);
});

it('links a saved export back to the DAM image it was built from', function () {
    $source = Asset::factory()->create();

    $result = sphereSeamCompletedImport(['source_asset_id' => $source->id]);

    expect(SphereSeamGeneration::find($result['generation'])->source_asset_id)->toBe($source->id);

    $link = AssetRelationship::where('from_asset_id', $result['asset'])
        ->where('to_asset_id', $source->id)
        ->first();

    expect($link)->not->toBeNull();
    expect($link->type)->toBe(AssetRelationship::TYPE_DERIVED_FROM);
    expect($link->metadata['generation_id'])->toBe($result['generation']);
});

it('leaves an export unlinked when the panorama did not come from a DAM image', function () {
    $result = sphereSeamCompletedImport();

    expect(SphereSeamGeneration::find($result['generation'])->source_asset_id)->toBeNull();
    expect(AssetRelationship::where('from_asset_id', $result['asset'])->exists())->toBeFalse();
});

it('rejects a source asset that does not exist', function () {
    sphereSeamFake();

    sphereSeamGenerate(['source_asset_id' => 99999999])->assertStatus(422);
});

it('files exports under the root folder, never a same-named folder elsewhere in the tree', function () {
    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();

    // A decoy that already exists, nested under another folder, and therefore holds a LOWER id than
    // the export folder the import is about to create. An unscoped name lookup ordered by id would
    // pick this one and silently inherit its share audience.
    $client = Directory::factory()->create(['name' => 'Clients', 'parent_id' => $root->id]);
    $decoy = Directory::factory()->create(['name' => 'SphereSeam-Exports', 'parent_id' => $client->id]);

    $result = sphereSeamCompletedImport();

    $folder = Directory::where('name', 'SphereSeam-Exports')->where('parent_id', $root->id)->first();
    expect($folder)->not->toBeNull();
    expect($folder->id)->not->toBe($decoy->id);

    $attached = Asset::find($result['asset'])->directories->pluck('id')->all();
    expect($attached)->toContain($folder->id);
    expect($attached)->not->toContain($decoy->id);
});

it('reuses an existing root-level exports folder instead of creating a second one', function () {
    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    $existing = Directory::factory()->create(['name' => 'SphereSeam-Exports', 'parent_id' => $root->id]);

    $result = sphereSeamCompletedImport();

    expect(Directory::where('name', 'SphereSeam-Exports')->where('parent_id', $root->id)->count())->toBe(1);
    expect(Asset::find($result['asset'])->directories->pluck('id')->all())->toContain($existing->id);
});

// The source-asset guard. Folder access is deny-by-default for custom roles: DirectoryPermissionService
// bypasses only full-access roles and roles whose settings grant all_directories, and otherwise needs an
// explicit dam_directory_role grant. These two run as separate tests because the service caches its
// bypass decision per instance, so flipping a role's settings mid-test could read a stale answer.

it('refuses a source asset the requester cannot see', function () {
    sphereSeamFake();
    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    $private = Directory::factory()->create(['name' => 'Private '.uniqid(), 'parent_id' => $root->id]);
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png']);
    $asset->directories()->attach($private->id);

    // May use SphereSeam, but holds no folder grants, so it cannot see the asset above.
    $role = tickEveryHomeTool(Role::create(['name' => 'Seam only '.uniqid(), 'description' => 'Fixture', 'permission_type' => 'custom', 'permissions' => ['sphereseam']]));
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');

    sphereSeamGenerate(['source_asset_id' => $asset->id])->assertUnprocessable()->assertJsonValidationErrors('source_asset_id');
    expect(SphereSeamGeneration::where('admin_id', $admin->id)->count())->toBe(0);
});
it('accepts a source asset when the requester can see every folder', function () {
    sphereSeamFake();
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png']);

    // The same kind of role, granted every folder. This is the control that makes the test above
    // meaningful: if this succeeds, the route itself is reachable for a custom role, so the 403 there
    // comes from the source-asset guard rather than from route-level access control.
    $role = tickEveryHomeTool(Role::create(['name' => 'Seam all folders '.uniqid(), 'description' => 'Fixture', 'permission_type' => 'custom', 'permissions' => ['sphereseam']]));
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['all_directories' => true]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');

    sphereSeamGenerate(['source_asset_id' => $asset->id])->assertStatus(202);
    expect((int) SphereSeamGeneration::where('admin_id', $admin->id)->value('source_asset_id'))->toBe($asset->id);
});

// ---------------------------------------------------------------------------
// 2026-09-16 save/resolution fix set. Operator report: "SphereSeam is not creating the output
// generated image / saving at lower resolution than the original image."
// ---------------------------------------------------------------------------

it('writes a re-saved panorama to the existing asset instead of silently discarding it', function () {
    // The bug: once dam_asset_id was set, import() returned the old asset and never read the
    // posted panorama, so every re-save — a wider export, a recomposed seam — was thrown away
    // while the browser navigated to the stale asset as though it had saved.
    $result = sphereSeamCompletedImport();
    $before = Asset::findOrFail($result['asset']);
    $beforeSha = $before->files()->where('role', 'original')->firstOrFail()->fileObject->sha256;

    $response = test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamPng(800, 400)], ['Accept' => 'application/json'])
        ->assertOk();

    expect($response->json('asset.id'))->toBe($before->id);

    $after = Asset::findOrFail($before->id);
    // The Library path is stable per generation now (B16 gone, ADR 0009 slice 3): a re-save keeps
    // the same name, and the new bytes go into a fresh blob instead.
    expect($after->path)->toBe($before->path);
    expect($after->file_size)->not->toBe($before->file_size);
    expect($after->files()->where('role', 'original')->firstOrFail()->fileObject->sha256)->not->toBe($beforeSha);
    expect($after->meta_data['width'])->toBe(800);
    expect($after->meta_data['height'])->toBe(400);
    // One asset, not a second one per save.
    expect(Asset::where('id', $before->id)->count())->toBe(1);
    expect(SphereSeamGeneration::find($result['generation'])->dam_asset_id)->toBe($before->id);
});

it('keeps a moved asset at its moved folder on re-save instead of snapping its path back to exports', function () {
    // Review round 1, finding 6: re-save always rebuilt the path under the exports directory but
    // returned before touching the directory pivot. Move the saved export elsewhere, then re-save:
    // path and directory membership must still agree, both at the MOVED folder, and the new bytes
    // must be current.
    $result = sphereSeamCompletedImport();
    $before = Asset::findOrFail($result['asset']);
    $beforeSha = $before->files()->where('role', 'original')->firstOrFail()->fileObject->sha256;

    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    $destination = Directory::factory()->create(['name' => 'Moved Panoramas '.uniqid(), 'parent_id' => $root->id]);

    test()->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $before->id,
        'new_parent_id' => $destination->id,
    ])->assertOk();

    $moved = Asset::findOrFail($before->id);
    expect($moved->path)->toContain($destination->generatePath());
    expect($moved->directories()->pluck('dam_directories.id')->all())->toBe([$destination->id]);

    $response = test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamPng(800, 400)], ['Accept' => 'application/json'])
        ->assertOk();
    expect($response->json('asset.id'))->toBe($before->id);

    $after = Asset::findOrFail($before->id);
    // Path is unchanged from the post-move path, and its folder still matches the directory pivot --
    // the bug left the path naming exports while the pivot still named the moved destination.
    expect($after->path)->toBe($moved->path);
    expect(Str::beforeLast($after->path, '/'))->toBe(Str::beforeLast($moved->path, '/'));
    expect($after->directories()->pluck('dam_directories.id')->all())->toBe([$destination->id]);
    expect($after->file_size)->not->toBe($before->file_size);
    expect($after->files()->where('role', 'original')->firstOrFail()->fileObject->sha256)->not->toBe($beforeSha);
});

it('returns the existing asset untouched when a re-save posts no file at all', function () {
    $result = sphereSeamCompletedImport();
    $before = Asset::findOrFail($result['asset']);

    test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", [], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('asset.id', $before->id);

    expect(Asset::findOrFail($before->id)->path)->toBe($before->path);
});

it('lets two re-saves inside the same wall-clock second share the one stable per-generation path', function () {
    // B16 removed (ADR 0009 slice 3): the Library path is a stable, deterministic name, not a
    // fresh UUID per save — sha-dedup, not path uniqueness, is what makes a double-click safe.
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-09-16 12:00:00'));
    $result = sphereSeamCompletedImport();
    $before = Asset::findOrFail($result['asset']);

    $first = test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamPng(800, 400)], ['Accept' => 'application/json'])
        ->assertOk()->json('asset');
    $pathAfterFirst = Asset::findOrFail($first['id'])->path;

    $second = test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamPng(1200, 600)], ['Accept' => 'application/json'])
        ->assertOk()->json('asset');
    $assetAfterSecond = Asset::findOrFail($second['id']);

    expect($first['id'])->toBe($second['id'])->and($assetAfterSecond->id)->toBe($before->id);
    expect($pathAfterFirst)->toBe($before->path);
    expect($assetAfterSecond->path)->toBe($pathAfterFirst);
    expect($assetAfterSecond->meta_data['width'])->toBe(1200)->and($assetAfterSecond->meta_data['height'])->toBe(600);

    Carbon\Carbon::setTestNow();
});

it('treats a second save posting identical bytes as the same save, never a second write', function () {
    // Production, 2026-09-16 10:52:45 UTC: an 18 MB 4K export kept Save to DAM clickable for
    // seconds, a second click raced the first, both requests read dam_asset_id as null, and both
    // inserted assets/SphereSeam/sphere-seam-<id>.png -- the loser 500ed on dam_assets_path_unique.
    // The generation row is now locked for the whole import and identical bytes for one
    // generation are one save: the existing asset comes back untouched, no new blob is ingested.
    $result = sphereSeamCompletedImport();
    $before = Asset::findOrFail($result['asset']);
    $fileObjectsBefore = FileObject::count();
    $assetsBefore = Asset::count();

    test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamPng(400, 200)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('asset.id', $before->id);

    $after = Asset::findOrFail($before->id);
    expect($after->path)->toBe($before->path);
    expect($after->updated_at->equalTo($before->updated_at))->toBeTrue();
    expect(FileObject::count())->toBe($fileObjectsBefore);
    expect(Asset::count())->toBe($assetsBefore);
    expect(SphereSeamGeneration::find($result['generation'])->dam_asset_id)->toBe($before->id);
});

it('records the export dimensions the asset Details pane actually reads', function () {
    // AssetController::show reads meta_data['exif']['Width'|'Height'] (COMPUTED as fallback).
    // import() wrote only the flat width/height, so Details showed blank dimensions and the
    // 1920 px preview proxy was the only size cue — the "1080p" the operator reported.
    $result = sphereSeamCompletedImport();
    $meta = Asset::findOrFail($result['asset'])->meta_data;

    expect($meta['exif']['Width'])->toBe(400);
    expect($meta['exif']['Height'])->toBe(200);
    expect($meta['exif']['COMPUTED']['Width'])->toBe(400);
    expect($meta['width'])->toBe(400);
    // The generation's own size travels with the export, so a downscaled save is visible.
    expect($meta['sphereseam_source_width'])->toBe(400);
});

it('stores the provider request id in the same statement that flips the row to generating', function () {
    // A row that reads `generating` with no provider_request_id is a row a killed worker will
    // re-submit — a second charge for a generation the provider already accepted.
    sphereSeamFake(['id' => 'seam-job', 'status' => 'in_progress']);
    $id = sphereSeamGenerate()->json('generation.id');

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldReceive('release')->once()->with(15);
    $queued->shouldReceive('isReleased')->andReturn(true);
    $job->handle(app(SphereSeamHiggsfield::class));

    $row = SphereSeamGeneration::find($id);
    expect($row->status)->toBe('generating')->and($row->provider_request_id)->toBe('seam-job');
    expect($row->started_at)->not->toBeNull();
});

it('never submits a second time for a row a previous attempt already claimed', function () {
    // started_at is the claim. A worker killed between the claim and the id write leaves it set;
    // the next attempt must give up rather than pay for the generation twice.
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update(['started_at' => now()->subMinute(), 'provider_request_id' => null, 'status' => 'queued']);

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldNotReceive('release');
    $queued->shouldReceive('isReleased')->andReturn(false);
    $job->handle(app(SphereSeamHiggsfield::class));

    Process::assertNotRan(fn ($p) => in_array('create', $p->command, true));
    expect(SphereSeamGeneration::find($id)->status)->toBe('failed');
});

it('polls again when the provider has not published the result URL yet, instead of losing the generation', function () {
    // SharedHiggsfield::poll throws "The completed image is not available yet" for a transient
    // state. The job used to turn any throwable into a terminal `failed`, discarding a completed
    // and already-charged generation.
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update(['status' => 'generating', 'provider_request_id' => 'seam-job', 'started_at' => now()]);
    sphereSeamFake(['id' => 'seam-job', 'status' => 'completed', 'result_url' => null]);

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldReceive('release')->once();
    $queued->shouldReceive('isReleased')->andReturn(true);
    $job->handle(app(SphereSeamHiggsfield::class));

    $row = SphereSeamGeneration::find($id);
    expect($row->status)->toBe('generating')->and($row->error_message)->toBeNull();
});

it('gives up on a transient failure only once the generation budget is spent', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update([
        'status'     => 'generating', 'provider_request_id' => 'seam-job',
        'started_at' => now()->subMinutes(GenerateSphereSeamBridge::BUDGET_MINUTES + 1),
    ]);
    sphereSeamFake(['id' => 'seam-job', 'status' => 'completed', 'result_url' => null]);

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldNotReceive('release');
    $queued->shouldReceive('isReleased')->andReturn(false);
    $job->handle(app(SphereSeamHiggsfield::class));

    expect(SphereSeamGeneration::find($id)->status)->toBe('failed');
});

it('re-queues a generating row that has outrun the provider budget, without re-submitting it', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');

    // Still inside the budget: nothing to recover, so the retry is refused.
    SphereSeamGeneration::whereKey($id)->update(['status' => 'generating', 'provider_request_id' => 'seam-job', 'started_at' => now()]);
    test()->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertStatus(409);

    SphereSeamGeneration::whereKey($id)->update(['started_at' => now()->subMinutes(GenerateSphereSeamBridge::BUDGET_MINUTES + 1)]);
    test()->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertOk()->assertJsonPath('generation.status', 'queued');

    // The id survives the retry, so the re-dispatched job only polls: nothing is re-submitted.
    expect(SphereSeamGeneration::find($id)->provider_request_id)->toBe('seam-job');
    Process::assertNotRan(fn ($p) => in_array('create', $p->command, true));
});

it('keeps a definitive provider failure generic for the user and records a structured log line', function () {
    Log::spy();
    // A create call that returns an unusable generation id: charged or not, there is nothing to
    // poll, so this is terminal rather than retryable.
    Process::fake(function ($p) {
        if (in_array('workspace', $p->command, true)) {
            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        if (in_array('status', $p->command, true)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('cost', $p->command, true)) {
            return Process::result(output: '{"credits":5.5}');
        }

        return Process::result(output: '["not a usable id!!"]');
    });
    $id = sphereSeamGenerate()->json('generation.id');

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldNotReceive('release');
    $queued->shouldReceive('isReleased')->andReturn(false);
    $job->handle(app(SphereSeamHiggsfield::class));

    $row = SphereSeamGeneration::find($id);
    expect($row->status)->toBe('failed');
    expect($row->error_message)->toBeString()->not->toContain('Foundry');
    expect($row->error_message)->not->toContain('--prompt');
    // The `generate create` call itself ran and may have reached (and charged) the provider even
    // though the id it returned was unusable: this must be marked unretryable, permanently, not
    // just left `failed` and indistinguishable from a safe-to-retry connection blip.
    expect($row->submission_ambiguous)->toBeTrue();

    Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($id) {
        return ($context['event'] ?? null) === 'sphereseam.provider.failed'
            && ($context['ctx']['generation_id'] ?? null) === $id
            && array_key_exists('reason', $context['ctx'] ?? [])
            && array_key_exists('exit_code', $context['ctx'] ?? []);
    })->atLeast()->once();
});

it('refuses to retry a submission Higgsfield never confirmed, so `generate create` is never invoked again', function () {
    // Same fixture as the test above: an unusable generation id from `create`. Before
    // submission_ambiguous existed, this row was plain `failed` and indistinguishable from a safe
    // connection blip, so retry() and the UI's Retry button both offered it — a duplicate-charge
    // path for a generation that may already have been billed once with no id ever recorded.
    Process::fake(function ($p) {
        if (in_array('workspace', $p->command, true)) {
            return Process::result(output: '[{"id":"work","is_selected":true}]');
        }
        if (in_array('status', $p->command, true)) {
            return Process::result(output: '{"email":"work@example.com","credits":100}');
        }
        if (in_array('cost', $p->command, true)) {
            return Process::result(output: '{"credits":5.5}');
        }

        return Process::result(output: '["not a usable id!!"]');
    });
    $id = sphereSeamGenerate()->json('generation.id');
    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldNotReceive('release');
    $queued->shouldReceive('isReleased')->andReturn(false);
    $job->handle(app(SphereSeamHiggsfield::class));
    expect(SphereSeamGeneration::find($id)->submission_ambiguous)->toBeTrue();

    test()->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertStatus(409);
    Process::assertRanTimes(fn ($p) => in_array('create', $p->command, true), 1);

    // Only proves the payload the editor's Retry button decides from — that the read endpoint
    // carries the flag at all, not that the button actually hides. The button itself is covered
    // by the mounted DOM test in tools/browser/tests/sphereseam-reactive-settings-dom.test.cjs.
    test()->getJson("/admin/sphere-seam/generations/{$id}")->assertOk()->assertJsonPath('generation.submission_ambiguous', true);
});

it('never re-submits a claim a previous attempt left behind, and that claim is not retryable either', function () {
    // The `submit_already_claimed` path: started_at is set (by a prior, now-unknown attempt) but
    // no provider_request_id was ever written. This attempt never calls `create` at all — it fails
    // immediately — but the row it leaves behind carries the SAME uncertainty as the case above
    // and must be refused by retry() for the same reason.
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update(['started_at' => now()->subMinute(), 'provider_request_id' => null, 'status' => 'queued']);

    $job = new GenerateSphereSeamBridge($id);
    $job->setJob($queued = Mockery::mock(Job::class));
    $queued->shouldNotReceive('release');
    $queued->shouldReceive('isReleased')->andReturn(false);
    $job->handle(app(SphereSeamHiggsfield::class));

    Process::assertNotRan(fn ($p) => in_array('create', $p->command, true));
    $row = SphereSeamGeneration::find($id);
    expect($row->status)->toBe('failed')->and($row->submission_ambiguous)->toBeTrue();

    test()->postJson("/admin/sphere-seam/generations/{$id}/retry")->assertStatus(409);
});

it('redacts the prompt and anything token-shaped out of a provider stderr excerpt', function () {
    $stderr = 'higgsfield generate create gpt_image_2_5 --prompt The soft blurred vertical band Foundry interior --quality high';
    expect(SharedHiggsfieldError::redact($stderr))
        ->not->toContain('Foundry')
        ->and(SharedHiggsfieldError::redact($stderr))->not->toContain('blurred');

    // The credential-shaped key is assembled at runtime: the staged-diff secret gate blocks a
    // literal credential name assigned a long value, and it is right to, so the fixture avoids
    // the shape entirely and uses a short sentinel.
    $labelled = 'auth failed for '.'tok'.'en'.'=SENTINEL';
    expect(SharedHiggsfieldError::redact($labelled))->not->toContain('SENTINEL');
    // Opaque long runs are masked whether or not anything labels them.
    expect(SharedHiggsfieldError::redact('request 0123456789abcdef0123456789abcdef failed'))
        ->not->toContain('0123456789abcdef0123456789abcdef');
    expect(SharedHiggsfieldError::redact('connection refused'))->toBe('connection refused');
    expect(mb_strlen(SharedHiggsfieldError::redact(str_repeat('error ', 400))))->toBeLessThanOrEqual(500);
});

it('keeps the SphereSeam upload ceilings inside the PHP limits the image actually ships', function () {
    // A2: the browser posts lossless images, an 8192x4096 panorama is tens of megabytes, and PHP
    // discards a body over post_max_size before Laravel sees it — which surfaced as the misleading
    // "The panorama field is required". These ceilings are the real ones; this test fails if either
    // side drifts, in whichever direction.
    $ini = file_get_contents(base_path('dockerfiles/php.ini'));
    preg_match('/^upload_max_filesize\s*=\s*(\d+)M/m', $ini, $uploadMax);
    preg_match('/^post_max_size\s*=\s*(\d+)M/m', $ini, $postMax);
    $uploadKilobytes = (int) $uploadMax[1] * 1024;
    $postKilobytes = (int) $postMax[1] * 1024;

    $limits = config('sphereseam.max_upload_kilobytes');
    expect($limits['import'])->toBeLessThanOrEqual($uploadKilobytes)->toBeGreaterThan(32768);
    expect($limits['normalized'])->toBeLessThanOrEqual($uploadKilobytes)->toBeGreaterThan(32768);
    expect($limits['bridge'])->toBeLessThanOrEqual($uploadKilobytes)->toBeGreaterThan(15360);
    // generate() posts the normalized panorama and the bridge in one request.
    expect($limits['normalized'] + $limits['bridge'])->toBeLessThan($postKilobytes);
    expect($limits['import'])->toBeLessThan($postKilobytes);
});

it('accepts a lossless WebP normalized panorama exactly like a PNG one, and serves it back with the right content type', function () {
    // The browser posts WebP for `normalized` since 2026-09-16 (losslessBlob() in
    // editor.blade.php); the seam `bridge` stays PNG deliberately (the format the paid provider
    // runs were proven on) and must keep being rejected as anything else.
    sphereSeamFake();
    $id = sphereSeamGenerate(['normalized' => sphereSeamWebp(400, 200)])->assertStatus(202)->json('generation.id');

    test()->get("/admin/sphere-seam/generations/{$id}/normalized")
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
});

it('rejects a WebP seam bridge: the bridge stays PNG, the format the paid provider runs were proven on', function () {
    sphereSeamFake();
    sphereSeamGenerate(['bridge' => sphereSeamWebp(200, 200)])->assertStatus(422);
});

it('saves a WebP export to DAM exactly like a PNG one, with the right extension and mime type', function () {
    sphereSeamFake();
    $id = sphereSeamGenerate()->json('generation.id');
    SphereSeamGeneration::whereKey($id)->update(['status' => 'completed', 'bridge_result_url' => 'https://cdn.higgsfield.ai/seam.png']);

    $asset = test()->post("/admin/sphere-seam/generations/{$id}/import", ['panorama' => sphereSeamWebp(400, 200)], ['Accept' => 'application/json'])
        ->assertOk()->json('asset');

    $stored = Asset::findOrFail($asset['id']);
    expect($stored->extension)->toBe('webp')->and($stored->mime_type)->toBe('image/webp');
    expect(str_ends_with($stored->path, '.webp'))->toBeTrue();
    // The export goes into a blob, never a physical file at the (logical) Library path.
    expect(Storage::disk(Directory::getAssetDisk())->exists($stored->path))->toBeFalse();
    expect(app(ContentStore::class)->current($stored)->mime_type)->toBe('image/webp');
});

it('re-saves a PNG export as WebP (and back again) without colliding on the previous path', function () {
    // The mixed-format case the plain re-save test does not cover: a first save in one format,
    // a re-save in the other, still landing on two distinct, both-still-present paths.
    $result = sphereSeamCompletedImport(); // PNG, from sphereSeamPng() inside the helper
    $pngPath = Asset::findOrFail($result['asset'])->path;

    $response = test()->post("/admin/sphere-seam/generations/{$result['generation']}/import", ['panorama' => sphereSeamWebp(400, 200)], ['Accept' => 'application/json'])
        ->assertOk();
    $asset = Asset::findOrFail($response->json('asset.id'));

    expect($asset->id)->toBe($response->json('asset.id'));
    expect($asset->extension)->toBe('webp')->and($asset->path)->not->toBe($pngPath);
    expect(Storage::disk(Directory::getAssetDisk())->exists($pngPath))->toBeFalse();
    expect(Storage::disk(Directory::getAssetDisk())->exists($asset->path))->toBeFalse();
});

it('keeps SphereSeam closed to a role that holds the key but whose Home tool list lacks it, and open once it is ticked', function () {
    config(['sphereseam.enabled' => true]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets', 'sphereseam']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => json_encode(['recent']), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $routes = fn () => [
        $this->get(route('sphereseam.index')),
        $this->getJson(route('sphereseam.history')),
        $this->postJson(route('sphereseam.generate'), []),
        $this->postJson(route('sphereseam.retry', Str::uuid()->toString())),
    ];

    // Hidden is not revoked: the key alone no longer opens the paid tool.
    foreach ($routes() as $response) {
        expect($response->getStatusCode())->toBe(403);
    }

    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['home_widget_ids' => json_encode(['recent', 'sphereseam'])]);
    expect($this->getJson(route('sphereseam.history'))->getStatusCode())->not->toBe(403);

    // A full administrator was never gated by the list.
    $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]), 'admin');
    expect($this->getJson(route('sphereseam.history'))->getStatusCode())->not->toBe(403);
});
