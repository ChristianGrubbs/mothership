<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\GlbPrimaryView;
use Mothership\DAM\Services\ModelThumbnailService;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function primaryGlb(): string
{
    $json = json_encode(['asset'=>['version'=>'2.0'], 'scene'=>0, 'scenes'=>[['nodes'=>[0]]], 'nodes'=>[['mesh'=>0]], 'meshes'=>[['primitives'=>[]]], 'extensions'=>(object) [], 'buffers'=>[['byteLength'=>4]]]);
    $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');

    return 'glTF'.pack('VVVV', 2, 28 + strlen($json) + 4, strlen($json), 0x4E4F534A).$json.pack('VV', 4, 0x004E4942).'ABCD';
}

function primaryModel(): Asset
{
    $path = 'assets/'.uniqid().'.glb';
    Storage::disk(Directory::getAssetDisk())->put($path, primaryGlb());

    return Asset::factory()->create(['path'=>$path, 'file_name'=>'model.glb', 'extension'=>'glb', 'file_type'=>'document', 'mime_type'=>'model/gltf-binary', 'file_size'=>strlen(primaryGlb())]);
}

function primaryUrl(Asset $asset): string
{
    return '/'.config('app.admin_url').'/dam/assets/primary-view/'.$asset->id;
}

function primaryPayload(string $revision): array
{
    return ['revision'=>$revision, 'rotation'=>[0, 1, 0, 0], 'translation'=>[0, 0, 0], 'view'=>['distance'=>4, 'aspect'=>1.5]];
}

/** The asset's current original through the read seam (ADR 0009), never the Library path. */
function primaryCurrentBytes(Asset $asset): string
{
    $store = app(ContentStore::class);
    $stream = $store->open($store->current($asset));

    try {
        return (string) stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
}

it('persists framing and a corrected default download while keeping original bytes and restoring them', function () {
    $asset = primaryModel();
    $originalPath = $asset->path;
    $originalFileObjectId = $asset->files()->where('role', 'original')->firstOrFail()->file_object_id;
    $opened = $this->getJson(primaryUrl($asset))->assertOk()->json();
    $saved = $this->postJson(primaryUrl($asset), primaryPayload($opened['revision']))->assertOk()->json();
    $asset->refresh();
    // The response names the new bytes the entry holds: the viewer that adopted this view captures against them.
    expect($saved['checksum'])->toBe(hash('sha256', primaryCurrentBytes($asset)))->and($saved['checksum'])->toBe(ModelThumbnailService::checksum($asset));
    // An in-place model correction never renames the asset (ADR 0009 slice 3): no new folder-tree
    // location, the corrected bytes go straight into a blob.
    expect($asset->path)->toBe($originalPath);
    $bytes = primaryCurrentBytes($asset);
    expect($bytes)->not->toBe(primaryGlb());
    expect(substr($bytes, -4))->toBe('ABCD');
    expect($this->get(route('admin.dam.assets.download', $asset->id))->assertOk()->streamedContent())->toBe($bytes);
    $doc = json_decode(substr($bytes, 20, unpack('V', substr($bytes, 12, 4))[1]));
    expect($doc->nodes[1]->rotation)->toBe([0, 1, 0, 0]);
    expect($doc->scenes[0]->nodes)->toBe([1]);
    expect($doc->nodes[1]->children)->toBe([0]);
    expect($doc->extensions)->toBeInstanceOf(stdClass::class);
    // The pre-correction bytes are demoted to a version, still content-addressed, at no Library path.
    $originalFileObject = FileObject::findOrFail($originalFileObjectId);
    expect($originalFileObject->sha256)->toBe(hash('sha256', primaryGlb()))
        ->and(app(ContentStore::class)->holdsBlob($originalFileObject))->toBeTrue()
        ->and($asset->files()->where('role', 'version')->where('file_object_id', $originalFileObjectId)->exists())->toBeTrue();
    expect($asset->files()->where('role', 'version')->count())->toBe(1);
    $reopened = $this->getJson(primaryUrl($asset))->assertOk()->assertJsonPath('view.distance', 4)->json();
    $this->postJson(primaryUrl($asset), primaryPayload($opened['revision']))->assertConflict();
    $again = primaryPayload($reopened['revision']);
    $again['rotation'] = [0, 0, 0, 1];
    $savedAgain = $this->postJson(primaryUrl($asset), $again)->assertOk()->json();
    $asset->refresh();
    $againBytes = primaryCurrentBytes($asset);
    $againDoc = json_decode(substr($againBytes, 20, unpack('V', substr($againBytes, 12, 4))[1]));
    expect($againDoc->nodes)->toHaveCount(2);
    expect($againDoc->nodes[1]->rotation)->toBe([0, 1, 0, 0]);
    $restored = $this->deleteJson(primaryUrl($asset), ['revision'=>$savedAgain['revision']])->assertOk()->json();
    $asset->refresh();
    expect(primaryCurrentBytes($asset))->toBe(primaryGlb());
    expect($restored['checksum'])->toBe(hash('sha256', primaryGlb()));
    expect($asset->path)->toBe($originalPath);
    $this->getJson(primaryUrl($asset))->assertOk()->assertJsonPath('view', null);
});

it('renders the actual detail page and rejects primary views on other formats', function () {
    $asset = primaryModel();
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk();
    $asset->update(['extension'=>'3mf']);
    $this->getJson(primaryUrl($asset))->assertUnprocessable();
    $this->postJson(primaryUrl($asset), [])->assertUnprocessable();
});

it('rejects malformed GLBs and invalid transforms without changing content', function () {
    $asset = primaryModel();
    $opened = $this->getJson(primaryUrl($asset))->assertOk()->json();
    $payload = primaryPayload($opened['revision']);
    $payload['rotation'] = [0, 0, 0, 0];
    $this->postJson(primaryUrl($asset), $payload)->assertUnprocessable();
    $payload = primaryPayload($opened['revision']);
    $payload['view']['distance'] = 0;
    $this->postJson(primaryUrl($asset), $payload)->assertUnprocessable();
    // The save reads the graph original (ADR 0009), so the malformed bytes go in its blob.
    Storage::disk(Directory::getAssetDisk())->put($asset->files()->where('role', 'original')->firstOrFail()->fileObject->path, 'broken');
    $this->postJson(primaryUrl($asset), primaryPayload($opened['revision']))->assertUnprocessable();
    expect($asset->fresh()->path)->toBe($asset->path);
});

it('reports the model unavailable through the seam when its blob is gone, without touching the Library file', function () {
    $asset = primaryModel();
    $opened = $this->getJson(primaryUrl($asset))->assertOk()->json();
    $object = $asset->files()->where('role', 'original')->firstOrFail()->fileObject;
    Storage::disk($object->disk)->delete($object->path);

    $this->postJson(primaryUrl($asset), primaryPayload($opened['revision']))
        ->assertUnprocessable()->assertJsonPath('message', 'The model file is unavailable.');
    expect($asset->fresh()->path)->toBe($asset->path);
});

it('reauthorizes read save and restore by folder access', function () {
    $asset = primaryModel();
    $folder = Directory::factory()->create();
    $asset->directories()->attach($folder->id);
    $service = Mockery::mock(DirectoryPermissionService::class);
    $service->shouldReceive('bypass')->andReturn(false);
    $service->shouldReceive('canAccess')->with($folder->id)->andReturn(false);
    app()->instance(DirectoryPermissionService::class, $service);
    $this->getJson(primaryUrl($asset))->assertNotFound();
    $this->postJson(primaryUrl($asset), [])->assertNotFound();
    $this->deleteJson(primaryUrl($asset), [])->assertNotFound();
});

it('preserves multiple scenes root lists and extension objects while correcting each scene', function () {
    $doc = (object) ['asset'=> (object) ['version'=>'2.0'], 'scene'=>1,
        'scenes'            => [(object) ['nodes'=>[0, 1]], (object) ['nodes'=>[1]]],
        'nodes'             => [(object) ['name'=>'One'], (object) ['name'=>'Two']],
        'extensions'        => (object) ['CUSTOM'=>(object) []]];
    $json = json_encode($doc);
    $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');
    $input = tmpfile();
    $output = tmpfile();
    fwrite($input, 'glTF'.pack('VVVV', 2, 20 + strlen($json), strlen($json), 0x4E4F534A).$json);
    rewind($input);
    app(GlbPrimaryView::class)->transform(input: $input, output: $output, rotation: [0, 0, 0, 1], translation: [1, 2, 3]);
    $bytes = stream_get_contents($output);
    $result = json_decode(substr($bytes, 20));
    expect($result->scene)->toBe(1);
    expect($result->scenes[0]->nodes)->toBe([2]);
    expect($result->nodes[2]->children)->toBe([0, 1]);
    expect($result->scenes[1]->nodes)->toBe([3]);
    expect($result->nodes[3]->children)->toBe([1]);
    expect($result->nodes[3]->translation)->toBe([1, 2, 3]);
    expect($result->extensions->CUSTOM)->toBeInstanceOf(stdClass::class);
    fclose($input);
    fclose($output);
});

it('streams a GLB larger than 256 MiB while preserving all binary bytes', function () {
    $binaryLength = 256 * 1024 * 1024 + 4;
    $json = json_encode(['asset'=>['version'=>'2.0'], 'scenes'=>[['nodes'=>[0]]], 'nodes'=>[[]], 'buffers'=>[['byteLength'=>$binaryLength]]]);
    $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');
    $input = tmpfile();
    $output = tmpfile();

    try {
        fwrite($input, 'glTF'.pack('VVVV', 2, 28 + strlen($json) + $binaryLength, strlen($json), 0x4E4F534A).$json.pack('VV', $binaryLength, 0x004E4942).'HEAD');
        // A sparse synthetic fixture exercises the real stream without allocating a giant string.
        fseek($input, 28 + strlen($json) + $binaryLength - 4);
        fwrite($input, 'TAIL');
        rewind($input);

        app(GlbPrimaryView::class)->transform(input: $input, output: $output, rotation: [0, 1, 0, 0], translation: [1, 2, 3]);

        $header = unpack('Vlength/VjsonLength', substr(fread($output, 20), 8, 8));
        $doc = json_decode(fread($output, $header['jsonLength']));
        expect($header['length'])->toBe(fstat($output)['size'])
            ->and($doc->scenes[0]->nodes)->toBe([1])
            ->and($doc->nodes[1]->rotation)->toBe([0, 1, 0, 0])
            ->and(fread($output, 8))->toBe(pack('VV', $binaryLength, 0x004E4942));
        fseek($input, 28 + strlen($json));
        $before = hash_init('sha256');
        $after = hash_init('sha256');
        expect(hash_update_stream($before, $input))->toBe($binaryLength)
            ->and(hash_update_stream($after, $output))->toBe($binaryLength)
            ->and(hash_final($after))->toBe(hash_final($before));
    } finally {
        fclose($input);
        fclose($output);
    }
});

it('rejects a corrected GLB that would overflow its uint32 length before writing output', function () {
    $bytes = primaryGlb();
    $input = tmpfile();
    $output = tmpfile();

    try {
        // The wrapper alone exceeds the format limit; no multi-gigabyte fixture is needed.
        fwrite($input, substr_replace($bytes, pack('V', 0xFFFFFFFC), 8, 4));
        rewind($input);
        expect(fn () => app(GlbPrimaryView::class)->transform(input: $input, output: $output, rotation: [0, 0, 0, 1], translation: [0, 0, 0]))
            ->toThrow(InvalidArgumentException::class, 'Corrected GLB exceeds the format length limit.');
        expect(fstat($output)['size'])->toBe(0);
    } finally {
        fclose($input);
        fclose($output);
    }
});

it('retains bounded JSON and binary chunk validation', function (string $malformation, string $message) {
    $bytes = primaryGlb();
    $jsonLength = unpack('V', substr($bytes, 12, 4))[1];
    $bytes = match ($malformation) {
        'oversized JSON'   => substr_replace($bytes, pack('VV', 20 + 16 * 1024 * 1024 + 4, 16 * 1024 * 1024 + 4), 8, 8),
        'unaligned chunk'  => substr_replace($bytes, pack('V', 3), 20 + $jsonLength, 4),
        'oversized chunk'  => substr_replace($bytes, pack('V', 8), 20 + $jsonLength, 4),
        'truncated binary' => substr($bytes, 0, -1),
        'trailing data'    => $bytes.'extra',
    };
    $input = tmpfile();
    $output = tmpfile();

    try {
        fwrite($input, $bytes);
        rewind($input);
        expect(fn () => app(GlbPrimaryView::class)->transform(input: $input, output: $output, rotation: [0, 0, 0, 1], translation: [0, 0, 0]))
            ->toThrow(InvalidArgumentException::class, $message);
    } finally {
        fclose($input);
        fclose($output);
    }
})->with([
    ['oversized JSON', 'Invalid or oversized GLB header.'],
    ['unaligned chunk', 'Invalid GLB chunk length.'],
    ['oversized chunk', 'Invalid GLB chunk length.'],
    ['truncated binary', 'Truncated GLB binary data.'],
    ['trailing data', 'Unexpected trailing GLB data.'],
]);

// Superseded PR #23 patched MoveDirectoryStructure/MassMove with raw-SQL REPLACE blocks on
// dam_file_objects.path. Under the locator invariant a FileObject's path never moves (it names its
// own blob under ContentStore::ROOT, independent of the Library path), so a folder rename and both
// move jobs must leave every FileObject byte-for-byte untouched, and a save that depends on reading
// it back (the 3D primary-view endpoint) must keep working after all that path churn.
it('keeps FileObjects content-addressed and unchanged through a folder rename, a directory move job and a mass move job', function () {
    $disk = Directory::getAssetDisk();

    $root = Directory::factory()->create(['name' => 'Pr23Root']);
    $folder = Directory::factory()->create(['name' => 'Pr23Folder', 'parent_id' => $root->id]);
    $moveTarget = Directory::factory()->create(['name' => 'Pr23MoveTarget', 'parent_id' => $root->id]);
    $massMoveTarget = Directory::factory()->create(['name' => 'Pr23MassMoveTarget', 'parent_id' => $root->id]);

    foreach ([$root, $folder, $moveTarget, $massMoveTarget] as $directory) {
        Storage::disk($disk)->makeDirectory(Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath());
    }

    $bytes = primaryGlb();
    $path = Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath().'/model.glb';
    Storage::disk($disk)->put($path, $bytes);
    $asset = Asset::factory()->create(['path'=>$path, 'file_name'=>'model.glb', 'extension'=>'glb', 'file_type'=>'document', 'mime_type'=>'model/gltf-binary', 'file_size'=>strlen($bytes)]);
    $folder->assets()->attach($asset->id);

    $originalAssetFile = $asset->files()->where('role', 'original')->firstOrFail();
    $fileObjectId = $originalAssetFile->file_object_id;
    $shaBefore = $originalAssetFile->fileObject->sha256;
    $pathBefore = $originalAssetFile->fileObject->path;
    $diskBefore = $originalAssetFile->fileObject->disk;

    expect(app(ContentStore::class)->holdsBlob($originalAssetFile->fileObject))->toBeTrue();

    // 1. A folder rename through the repository.
    app(DirectoryRepository::class)->update(['name' => 'Pr23FolderRenamed'], $folder->id);

    // 2. A MoveDirectoryStructure job reparents the (renamed) folder.
    (new MoveDirectoryStructure($folder->id, $moveTarget->id, auth()->id()))->handle();

    // 3. A MassMove job moves the asset itself to another folder.
    (new MassMove([$asset->id], [], $massMoveTarget->id, auth()->id()))->handle();

    $fileObject = FileObject::findOrFail($fileObjectId);

    expect($fileObject->sha256)->toBe($shaBefore)
        ->and($fileObject->path)->toBe($pathBefore)
        ->and($fileObject->disk)->toBe($diskBefore)
        ->and(app(ContentStore::class)->holdsBlob($fileObject))->toBeTrue();

    $asset->refresh();
    $opened = $this->getJson(primaryUrl($asset))->assertOk()->json();
    $this->postJson(primaryUrl($asset), primaryPayload($opened['revision']))->assertOk();
});

it('combines a new adjustment with the saved transform in world order', function () {
    $result = app(GlbPrimaryView::class)->compose(delta: ['rotation'=>[0, 1, 0, 0], 'translation'=>[0, 3, 0]],
        previous: ['rotation'=>[0, 1, 0, 0], 'translation'=>[2, 0, 0]]);
    expect($result['rotation'])->toEqual([0, 0, 0, -1]);
    expect($result['translation'])->toEqual([-2, 3, 0]);
});
