<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Requests;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->person = Admin::factory()->create();
    $this->actingAs($this->person, 'admin');
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => $this->person->id,
        'vectorization.api_id'      => 'x', 'vectorization.api_secret' => 'y']);
    $image = UploadedFile::fake()->image('source.png', 20, 20);
    Storage::disk(Directory::getAssetDisk())->put('assets/source.png', file_get_contents($image->getRealPath()));
    $this->source = Asset::factory()->create(['file_name' => 'source.png', 'path' => 'assets/source.png', 'extension' => 'png', 'file_type' => 'image']);
});

it('attaches only the chosen format exactly once after a worker completes', function (string $format) {
    $response = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => $format,
    ])->assertAccepted();
    $id = $response->json('result.id');
    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $id))->handle();
    (new VectorizeImage(id: $id))->handle();
    $this->getJson(route('admin.dam.assets.vectorization.state', $this->source->id))->assertOk()
        ->assertJsonPath('result.status', 'completed')->assertJsonPath('result.format', $format);
    $files = $this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->with('fileObject')->get();
    expect($files)->toHaveCount(1)
        ->and($files->first()->fileObject->mime_type)->toBe($format === 'svg' ? 'image/svg+xml' : 'application/pdf')
        ->and($this->source->fresh()->path)->toBe('assets/source.png');
    Storage::disk($files->first()->fileObject->disk)->assertExists($files->first()->fileObject->path);
    $download = $this->get(route('admin.dam.file.asset-file', [
        'asset' => $this->source->id, 'assetFile' => $files->first()->id, 'download' => 1,
    ]))->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('source-vectorized.'.$format);
})->with(['svg', 'pdf']);

it('binds a request to the asset and selected format', function () {
    $request = ['request_id' => (string) Str::uuid(), 'format' => 'svg'];
    $url = route('admin.dam.assets.vectorization.store', $this->source->id);
    $one = $this->postJson($url, $request)->assertAccepted()->json('result.id');
    $this->postJson($url, $request)->assertAccepted()->assertJsonPath('result.id', $one);
    $this->postJson($url, array_replace($request, ['format' => 'pdf']))->assertConflict();
    Queue::assertPushed(VectorizeImage::class, 1);
});

it('rejects unsupported and falsely named images without reserving work', function () {
    $this->source->update(['extension' => 'svg']);
    $url = route('admin.dam.assets.vectorization.store', $this->source->id);
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'format' => 'pdf'])->assertUnprocessable();
    $this->source->update(['extension' => 'png']);
    // The conversion reads the graph original (ADR 0009), so the unreadable bytes go in its blob —
    // and the FileObject's own recorded size must match, or ContentStore::holdsBlob() (an exact-size
    // check) reports the blob missing before the bytes are ever decoded.
    $original = $this->source->files()->where('role', 'original')->firstOrFail()->fileObject;
    $garbage = 'not an image';
    Storage::disk(Directory::getAssetDisk())->put($original->path, $garbage);
    $original->forceFill(['size' => strlen($garbage)])->save();
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'format' => 'svg'])->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('refunds failed conversion without an attached output', function () {
    config(['vectorization.owner_admin_id' => 999999]);
    app(Allowance::class)->set(adminId: $this->person->id, remaining: 1, revision: 0);
    $id = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('convert')->once()->andThrow(new RuntimeException('unavailable'));
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $id))->handle();
    expect(DB::table('dam_vectorizations')->where('id', $id)->value('status'))->toBe('failed')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0)
        ->and(app(Allowance::class)->read(adminId: $this->person->id)['remaining'])->toBe(1);
});

it('denies an inaccessible source before reserving conversion', function () {
    $permissions = Mockery::mock(DirectoryPermissionService::class);
    $permissions->shouldReceive('bypass')->andReturn(false);
    $permissions->shouldReceive('canAccess')->andReturn(false);
    app()->instance(DirectoryPermissionService::class, $permissions);
    $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertNotFound();
    Queue::assertNothingPushed();
});

it('does not attach late output from an expired conversion', function () {
    $id = $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertAccepted()->json('result.id');
    DB::table('dam_vectorizations')->where('id', $id)->update(['status' => 'processing']);
    Storage::disk('private')->put('vectorizations/results/'.$id.'/result.svg', '<svg/>');
    app(Requests::class)->finish(id: $id, success: false, code: 'worker_failed');
    app(Requests::class)->finish(id: $id, success: true);
    expect($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('removes staged input when no conversion allowance remains', function () {
    config(['vectorization.owner_admin_id' => 999999]);
    app(Allowance::class)->set(adminId: $this->person->id, remaining: 0, revision: 0);
    $this->postJson(route('admin.dam.assets.vectorization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'format' => 'svg',
    ])->assertForbidden();
    expect(Storage::disk('private')->files('vectorizations/input'))->toBe([]);
    Queue::assertNothingPushed();
});
