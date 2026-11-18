<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\OptimizeAsset;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P3a S6: every derived output names the revision it was made from (acceptance line 8). Optimize, Vectorize,
 * image editor save-as-new and the video proxy write a rendition row on the source entry; the Files tab says
 * "Made by … from revision N", and an output made from several images lists each one.
 */

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->loginAsAdmin();
    $image = UploadedFile::fake()->image('source.png', 200, 100);
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/source.png', file_get_contents($image->getRealPath()));
    $this->source = Asset::factory()->create(['file_name' => 'source.png', 'path' => 'assets/Root/source.png', 'extension' => 'png', 'file_type' => 'image', 'title' => 'Spring banner']);
    $this->first = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    $this->filesUrl = fn (Asset $asset) => '/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files';
});

/** The Files tab row for one file of the entry. */
$row = function (Asset $asset, int $fileId): array {
    return collect(test()->getJson((test()->filesUrl)($asset))->assertOk()->json('files'))->firstWhere('id', $fileId);
};

it('names the current revision an optimized copy was made from, and keeps the record when the copy goes', function () use ($row) {
    config(['optimization.api' => 'http://clop.test']);
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $path = tempnam(sys_get_temp_dir(), 'lineage-optimize-');
        file_put_contents($path, 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $path, 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => 20, 'height' => 20];
    });
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();

    $output = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    $rendition = Rendition::where('output_asset_file_id', $output->id)->firstOrFail();
    expect($rendition->only(['dam_asset_id', 'recipe', 'source_asset_file_id', 'status']))->toBe([
        'dam_asset_id' => $this->source->id, 'recipe' => Rendition::RECIPE_OPTIMIZE, 'source_asset_file_id' => $this->first->id, 'status' => Rendition::STATUS_DONE,
    ])->and($rendition->parameters['optimization_id'])->toBe($id)
        ->and($rendition->output_file_object_id)->toBeNull();

    expect($row($this->source, $output->id)['derived_from'])->toMatchArray([
        'recipe' => 'optimize', 'summary' => 'Made by Optimize from revision 1', 'source_revision' => 1, 'source_file_id' => $this->first->id, 'source_url' => null,
    ])->and($row($this->source, $this->first->id)['derived_from'])->toBeNull();

    // Removing the copy keeps the record of what was made and from what; it no longer points at a file.
    $output->delete();
    expect($rendition->fresh()->output_asset_file_id)->toBeNull()->and($rendition->fresh()->source_asset_file_id)->toBe($this->first->id);
});

it('names the revision a vector was traced from even after a newer upload replaced it', function () use ($row) {
    $person = auth()->guard('admin')->user();
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => $person->id, 'vectorization.api_id' => 'x', 'vectorization.api_secret' => 'y']);
    $id = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertAccepted()->json('result.id');

    $newer = UploadedFile::fake()->image('newer.png', 40, 40);
    putAssetContent($this->source->fresh(), file_get_contents($newer->getRealPath()));
    $second = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    expect($second->revision_number)->toBe(2);

    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $id))->handle();

    $output = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    expect(Rendition::where('output_asset_file_id', $output->id)->value('source_asset_file_id'))->toBe($this->first->id)
        ->and($row($this->source, $output->id)['derived_from']['summary'])->toBe('Made by Vectorize from revision 1');
});

it('records an image editor copy and its base image on the source entry, and links the copy back', function () use ($row) {
    $editor = '/'.config('app.admin_url').'/dam/assets/editor/'.$this->source->id;
    $opened = $this->getJson($editor)->assertOk()->json();
    $copyId = $this->post($editor.'/save', [
        'revision' => $opened['revision'], 'document' => json_encode($opened['document']), 'mode' => 'new',
        'render'   => UploadedFile::fake()->image('result.png', 200, 100),
    ], ['Accept' => 'application/json'])->assertOk()->json('asset_id');
    $copy = Asset::findOrFail($copyId);

    $rendition = Rendition::where('output_asset_id', $copy->id)->firstOrFail();
    expect($rendition->only(['dam_asset_id', 'recipe', 'source_asset_file_id', 'output_asset_file_id']))->toBe([
        'dam_asset_id' => $this->source->id, 'recipe' => Rendition::RECIPE_EDITOR_COPY, 'source_asset_file_id' => $this->first->id, 'output_asset_file_id' => null,
    ]);
    $inputs = $rendition->inputs;
    expect($inputs)->toHaveCount(1)
        ->and($inputs[0]->role)->toBe(LineageInput::ROLE_BASE)
        ->and($inputs[0]->sha256)->toBe(FileObject::query()->whereKey(DB::table('dam_image_editor_resources')->where('id', $opened['document']['base'])->value('file_object_id'))->value('sha256'));

    $representative = AssetFile::where('dam_asset_id', $copy->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    expect($row($copy, $representative->id)['derived_from'])->toMatchArray([
        'recipe'          => 'editor-save-as-new', 'summary' => 'Made by Image editor from revision 1 of Spring banner',
        'source_asset_id' => $this->source->id, 'source_url' => route('admin.dam.assets.edit', $this->source->id),
    ]);

    // A later revision of the copy is no longer the editor's output.
    putAssetContent($copy->fresh(), 'retouched elsewhere');
    $current = AssetFile::where('dam_asset_id', $copy->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    expect($row($copy, $current->id)['derived_from'])->toBeNull();

    // Deleting the copy for good (past the trash, P2 S4) leaves the source's record of having made one, pointing at nothing.
    $copy->forceDelete();
    expect($rendition->fresh()->output_asset_id)->toBeNull()->and($rendition->fresh()->inputs)->toHaveCount(1);
});

it('does not name a source entry the viewer may not open', function () use ($row) {
    $editor = '/'.config('app.admin_url').'/dam/assets/editor/'.$this->source->id;
    $opened = $this->getJson($editor)->assertOk()->json();
    $copy = Asset::findOrFail($this->post($editor.'/save', [
        'revision' => $opened['revision'], 'document' => json_encode($opened['document']), 'mode' => 'new',
        'render'   => UploadedFile::fake()->image('result.png', 200, 100),
    ], ['Accept' => 'application/json'])->assertOk()->json('asset_id'));
    $representative = AssetFile::where('dam_asset_id', $copy->id)->where('role', AssetFile::ROLE_ORIGINAL)->value('id');

    $private = Directory::create(['name' => 'Private '.uniqid()]);
    $shared = Directory::create(['name' => 'Shared '.uniqid()]);
    $this->source->directories()->sync([$private->id]);
    $copy->directories()->sync([$shared->id]);
    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files', 'dam.asset.files.index']]);
    $other = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    DB::table('dam_directory_role')->insert([['directory_id' => $shared->id, 'role_id' => $viewer->id], ['directory_id' => $private->id, 'role_id' => $other->id]]);
    $this->actingAs(Admin::factory()->create(['role_id' => $viewer->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect($row($copy, $representative)['derived_from'])->toMatchArray([
        'summary' => 'Made by Image editor from an entry you can’t open', 'source_asset_id' => null, 'source_revision' => null, 'source_url' => null,
    ])->and(json_encode($row($copy, $representative)))->not->toContain('Spring banner');
});

it('names the revision that was current when the work started after the same bytes came back as a newer one', function () {
    $sha = FileObject::findOrFail($this->first->file_object_id)->sha256;
    AssetFile::whereKey($this->first->id)->update(['created_at' => now()->subHours(3)]);
    putAssetContent($this->source->fresh(), 'second bytes');
    $second = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    AssetFile::whereKey($second->id)->update(['created_at' => now()->subHours(2)]);
    // A, B, then A again: revision 3 shares revision 1's bytes.
    $third = app(AttachmentLedger::class)->restore(asset: $this->source, attachmentId: (int) $this->first->attachment_id, revisionId: $this->first->id);
    $lineage = app(Lineage::class);

    expect($lineage->revisionWithSha(assetId: $this->source->id, sha256: $sha, startedAt: now()->subMinutes(150)->toDateTimeString())->id)->toBe($this->first->id)
        ->and($lineage->revisionWithSha(assetId: $this->source->id, sha256: $sha, startedAt: now())->id)->toBe($third->id)
        ->and($lineage->revisionWithSha(assetId: $this->source->id, sha256: $sha)->id)->toBe($third->id);
});

it('keeps the revision a vector was traced from after another file was promoted before the work settled', function () {
    $person = auth()->guard('admin')->user();
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => $person->id, 'vectorization.api_id' => 'x', 'vectorization.api_secret' => 'y']);
    $id = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertAccepted()->json('result.id');

    $base = $this->filesUrl;
    $attached = $this->postJson($base($this->source), ['file' => UploadedFile::fake()->createWithContent('brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64)), 'role' => 'alternate'])
        ->assertCreated()->json();
    $this->postJson(dirname($base($this->source)).'/attachments/'.$attached['attachment_id'].'/promote')->assertOk();
    expect(DB::table('dam_attachments')->where('id', $this->first->attachment_id)->value('is_representative'))->toBeFalsy();

    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $id))->handle();

    $output = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    expect(Rendition::where('output_asset_file_id', $output->id)->value('source_asset_file_id'))->toBe($this->first->id);
});

it('does not give an unrelated file that starts at revision 1 the editor copy provenance after attach and promote', function () use ($row) {
    $editor = '/'.config('app.admin_url').'/dam/assets/editor/'.$this->source->id;
    $opened = $this->getJson($editor)->assertOk()->json();
    $copy = Asset::findOrFail($this->post($editor.'/save', [
        'revision' => $opened['revision'], 'document' => json_encode($opened['document']), 'mode' => 'new',
        'render'   => UploadedFile::fake()->image('result.png', 200, 100),
    ], ['Accept' => 'application/json'])->assertOk()->json('asset_id'));

    $copyUrl = ($this->filesUrl)($copy);
    $attached = $this->postJson($copyUrl, ['file' => UploadedFile::fake()->createWithContent('brochure.pdf', "%PDF-1.4\n%".str_repeat('y', 64)), 'role' => 'alternate'])
        ->assertCreated()->json();
    $this->postJson(dirname($copyUrl).'/attachments/'.$attached['attachment_id'].'/promote')->assertOk();

    $first = AssetFile::where('dam_asset_id', $copy->id)->where('id', '!=', $attached['id'])->where('revision_number', 1)->firstOrFail();
    expect($row($copy, $attached['id'])['derived_from'])->toBeNull()
        ->and($row($copy, $first->id)['derived_from']['recipe'])->toBe('editor-save-as-new');
});

it('does not give an unrelated revision-1 file the editor copy provenance after the copy\'s first revision was replaced', function () use ($row) {
    $editor = '/'.config('app.admin_url').'/dam/assets/editor/'.$this->source->id;
    $opened = $this->getJson($editor)->assertOk()->json();
    $copy = Asset::findOrFail($this->post($editor.'/save', [
        'revision' => $opened['revision'], 'document' => json_encode($opened['document']), 'mode' => 'new',
        'render'   => UploadedFile::fake()->image('result.png', 200, 100),
    ], ['Accept' => 'application/json'])->assertOk()->json('asset_id'));
    $initial = AssetFile::where('dam_asset_id', $copy->id)->where('revision_number', 1)->firstOrFail();

    // The copy's first revision is replaced, so it is now a superseded `version`.
    putAssetContent($copy->fresh(), 'retouched elsewhere');
    expect($initial->fresh()->role)->toBe(AssetFile::ROLE_VERSION);

    $copyUrl = ($this->filesUrl)($copy);
    $attached = $this->postJson($copyUrl, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'unrelated notes'), 'role' => 'alternate'])
        ->assertCreated()->json();

    expect($row($copy, $attached['id'])['derived_from'])->toBeNull()
        ->and(Rendition::where('output_asset_id', $copy->id)->value('source_asset_file_id'))->toBe($this->first->id);
});

it('keeps the revision an optimized copy was made from after an attachment with identical bytes was promoted', function () {
    config(['optimization.api' => 'http://clop.test']);
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    expect(DB::table('dam_optimizations')->where('id', $id)->value('source_asset_file_id'))->toBe($this->first->id);

    $bytes = Storage::disk(Directory::getAssetDisk())->get('assets/Root/source.png');
    $attached = $this->postJson(($this->filesUrl)($this->source), ['file' => UploadedFile::fake()->createWithContent('same.png', $bytes), 'role' => 'alternate'])
        ->assertCreated()->json();
    $this->postJson(dirname(($this->filesUrl)($this->source)).'/attachments/'.$attached['attachment_id'].'/promote')->assertOk();

    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $path = tempnam(sys_get_temp_dir(), 'lineage-optimize-');
        file_put_contents($path, 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $path, 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => 20, 'height' => 20];
    });
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();

    $output = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    expect(Rendition::where('output_asset_file_id', $output->id)->value('source_asset_file_id'))->toBe($this->first->id);
});

it('falls back to the sha lookup when the captured revision is missing, deleted, or belongs to another entry', function (string $case) {
    $person = auth()->guard('admin')->user();
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => $person->id, 'vectorization.api_id' => 'x', 'vectorization.api_secret' => 'y']);
    $id = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertAccepted()->json('result.id');
    $lineage = app(Lineage::class);

    $other = Asset::factory()->create(['file_name' => 'other.png', 'path' => 'assets/Root/other.png', 'extension' => 'png', 'file_type' => 'image']);
    $foreign = AssetFile::factory()->create(['dam_asset_id' => $other->id, 'role' => AssetFile::ROLE_ALTERNATE]);
    $captured = match ($case) {
        'legacy-null' => null,
        'moved'       => $foreign->id,
        'deleted'     => AssetFile::factory()->create(['dam_asset_id' => $this->source->id, 'role' => AssetFile::ROLE_ALTERNATE])->id,
    };
    DB::table('dam_vectorizations')->where('id', $id)->update(['source_asset_file_id' => $captured]);
    if ($case === 'deleted') {
        // Removed after capture: the foreign key clears the id, so settlement sees a legacy-style row.
        AssetFile::whereKey($captured)->delete();
        expect(DB::table('dam_vectorizations')->where('id', $id)->value('source_asset_file_id'))->toBeNull();
        $captured = null;
    }

    expect($lineage->capturedRevision(assetId: $this->source->id, revisionId: $captured))->toBeNull();

    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $id))->handle();

    $output = AssetFile::where('dam_asset_id', $this->source->id)->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    expect(Rendition::where('output_asset_file_id', $output->id)->value('source_asset_file_id'))->toBe($this->first->id);
})->with(['legacy-null', 'deleted', 'moved']);
