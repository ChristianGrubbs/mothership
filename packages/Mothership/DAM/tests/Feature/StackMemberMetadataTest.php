<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\MetadataExtractionService;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'cover.dng', 'extension' => 'dng',
        'meta_data' => ['FileName' => 'cover.dng', 'FileType' => 'DNG'],
    ]));
    $this->asset->directories()->attach(Directory::factory()->create()->id);
    $this->member = function (string $name, string $bytes, ?Asset $asset = null) {
        $disk = Directory::getAssetDisk();
        Storage::disk($disk)->put($name, $bytes);
        $object = app(ContentStore::class)->putStored(disk: $disk, sourcePath: $name,
            mimeType: str_ends_with($name, '.dng') ? 'image/x-adobe-dng' : 'image/jpeg',
            extension: pathinfo($name, PATHINFO_EXTENSION));

        return AssetFile::create(['dam_asset_id' => ($asset ?? $this->asset)->id,
            'file_object_id'                     => $object->id, 'role' => 'alternate', 'label' => $name]);
    };
    $this->url = route('admin.dam.assets.metadata', ['id' => $this->asset->id]);
});

it('extracts the selected revision bytes and name without changing the representative cache', function () {
    $jpg = ($this->member)('selected.jpg', 'jpg-member-bytes');
    $dng = ($this->member)('selected.dng', 'dng-member-bytes');
    $paths = [];
    $extractor = Mockery::mock(MetadataExtractionService::class);
    $extractor->shouldReceive('extractMetadata')->twice()->andReturnUsing(function ($path, $disk, $localPath, $isPartial, $originalFileName, $extension) use (&$paths) {
        $paths[] = $localPath;
        expect(file_get_contents($localPath))->toBe($extension.'-member-bytes')
            ->and($originalFileName)->toBe('selected.'.$extension);

        return ['exif' => ['FileName' => $originalFileName, 'FileType' => strtoupper($extension)]];
    });
    app()->instance(MetadataExtractionService::class, $extractor);

    foreach ([[$jpg, 'JPG'], [$dng, 'DNG']] as [$member, $type]) {
        $this->getJson($this->url.'?file='.$member->id)->assertOk()
            ->assertJsonPath('data.FileName', $member->label)->assertJsonPath('data.FileType', $type)
            ->assertJsonPath('file.name', $member->label);
    }
    foreach ($paths as $path) {
        expect(file_exists($path))->toBeFalse();
    }
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.FileName', 'cover.dng');
    expect($this->asset->fresh()->meta_data)->toBe(['FileName' => 'cover.dng', 'FileType' => 'DNG']);
});

it('rejects foreign superseded removed and malformed selections without extraction', function () {
    $foreign = ($this->member)('foreign.jpg', 'foreign', Asset::withoutEvents(fn () => Asset::factory()->create()));
    $stale = ($this->member)('old.jpg', 'old');
    $stale->update(['is_current' => false]);
    $removed = ($this->member)('removed.jpg', 'removed');
    $id = $removed->id;
    $removed->delete();
    $this->mock(MetadataExtractionService::class)->shouldNotReceive('extractMetadata');
    foreach ([$foreign->id, $stale->id, $id, 'bogus', '0', '-1'] as $fileId) {
        $this->getJson($this->url.'?file='.$fileId)->assertNotFound();
    }
});

it('shows an unreadable member as an error without using cached representative metadata', function () {
    $file = ($this->member)('missing.jpg', 'missing');
    Storage::disk($file->fileObject->disk)->delete($file->fileObject->path);
    $this->mock(MetadataExtractionService::class)->shouldNotReceive('extractMetadata');
    $response = $this->getJson($this->url.'?file='.$file->id)->assertStatus(422)
        ->assertJsonPath('success', false)->assertJsonMissingPath('data')
        ->assertJsonPath('message', trans('dam::app.admin.dam.asset.edit.image-source-not-readable'));
    expect($response->json('message'))->not->toBe(trim(trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => ''])));
});

it('keeps an empty extraction bound to the selected name', function () {
    $file = ($this->member)('empty.jpg', 'empty');
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()->andReturn(['exif' => []]);
    $this->getJson($this->url.'?file='.$file->id)->assertOk()->assertJsonPath('data', [])->assertJsonPath('file.name', 'empty.jpg');
});

it('releases staged bytes after extraction throws and never falls back', function () {
    $file = ($this->member)('failed.jpg', 'failed');
    $path = null;
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->andReturnUsing(function ($name, $disk, $localPath) use (&$path) {
            $path = $localPath;
            throw new RuntimeException('extraction failed');
        });
    $this->getJson($this->url.'?file='.$file->id)->assertStatus(422)->assertJsonMissingPath('data');
    expect($path)->not->toBeNull()->and(file_exists($path))->toBeFalse();
});

it('rejects a revision replaced during extraction and releases its staged bytes', function () {
    $file = ($this->member)('replaced.jpg', 'replaced');
    $path = null;
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->andReturnUsing(function ($name, $disk, $localPath) use ($file, &$path) {
            $path = $localPath;
            $file->update(['is_current' => false]);

            return ['FileName' => $name];
        });
    $this->getJson($this->url.'?file='.$file->id)->assertNotFound()->assertJsonMissingPath('data');
    expect(file_exists($path))->toBeFalse();
});

it('names an unlabeled alternate distinctly from the cached cover', function () {
    $file = ($this->member)('unlabeled.jpg', 'own bytes');
    $file->update(['label' => null]);
    $name = 'File '.$file->id.'.jpg';
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->withArgs(fn ($path, $disk, $localPath, $partial, $originalName, $extension) => $path === $name && $originalName === $name && $extension === 'jpg')
        ->andReturn([]);
    $this->getJson($this->url.'?file='.$file->id)->assertOk()->assertJsonPath('file.name', $name)->assertJsonPath('data', []);
});

it('routes suffixless members by MIME and preserves an unknown empty extension', function ($mime, $extension, $name) {
    $file = ($this->member)('suffixless', 'suffixless bytes');
    $file->fileObject->update(['mime_type' => $mime]);
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->withArgs(fn ($path, $disk, $localPath, $partial, $originalName, $ext) => $path === $name && $originalName === $name && $ext === $extension && file_get_contents($localPath) === 'suffixless bytes')
        ->andReturn(['FileName' => $name]);
    $this->getJson($this->url.'?file='.$file->id)->assertOk()->assertJsonPath('file.name', $name)->assertJsonPath('data.FileName', $name);
})->with([
    ['image/jpeg', 'jpg', 'suffixless.jpg'],
    ['application/octet-stream', '', 'suffixless'],
]);

it('extracts an explicitly selected representative instead of using its cache', function () {
    $file = ($this->member)('original-source.dng', 'representative bytes');
    $file->update(['role' => 'original']);
    $file->attachment->update(['is_representative' => true, 'role' => 'original']);
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->withArgs(fn ($path, $disk, $localPath, $partial, $originalName, $extension) => $originalName === 'cover.dng' && $extension === 'dng' && file_get_contents($localPath) === 'representative bytes')
        ->andReturn(['FileName' => 'cover.dng', 'ByteSource' => 'selected representative']);
    $this->getJson($this->url.'?file='.$file->id)->assertOk()->assertJsonPath('data.ByteSource', 'selected representative');
    $this->getJson($this->url)->assertOk()->assertJsonMissingPath('data.ByteSource')->assertJsonPath('data.FileType', 'DNG');
});

it('reports malformed or unsuccessful extraction separately from unreadable bytes', function ($result) {
    $file = ($this->member)('failure.jpg', 'present bytes');
    $path = null;
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()
        ->andReturnUsing(function ($name, $disk, $localPath) use ($result, &$path) {
            $path = $localPath;

            return $result;
        });
    $this->getJson($this->url.'?file='.$file->id)->assertStatus(422)->assertJsonMissingPath('data')
        ->assertJsonPath('message', trim(trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => ''])));
    expect(file_exists($path))->toBeFalse();
})->with([[null], [['success' => false, 'message' => 'unusable']]]);
