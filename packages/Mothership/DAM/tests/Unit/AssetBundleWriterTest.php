<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\Exporters\Concerns\CopiesDamMedia;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\AssetBundleWriter;

/*
 * Asset binaries must reach the disk the download archive is built from, and an asset
 * shared by many rows must be streamed once rather than once per reference. `$sourcePath`
 * is only the lookup key (ADR 0009): write() resolves the asset's current original through
 * ContentStore, never a raw read of the Library path.
 */

beforeEach(function (): void {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());

    $this->writer = new AssetBundleWriter;
});

/** An asset whose Library-path file has bytes ingested as its current original (ADR 0009). */
function bundleWriterAsset(string $path, string $bytes): Asset
{
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($path, $bytes);

    return Asset::factory()->create(['path' => $path, 'file_name' => basename($path)]);
}

it('streams an asset onto the export disk', function () {
    $asset = bundleWriterAsset('assets/Root/hero.jpg', 'binary');

    $wrote = $this->writer->write($asset->path, 'exports/7/mothership/assets/Root/hero.jpg');

    expect($wrote)->toBeTrue();

    Storage::disk('private')->assertExists('exports/7/mothership/assets/Root/hero.jpg');

    expect(Storage::disk('private')->get('exports/7/mothership/assets/Root/hero.jpg'))->toBe('binary');
});

it('streams the current blob even after the Library-path file is deleted', function () {
    $asset = bundleWriterAsset('assets/Root/hero.jpg', 'binary');
    Storage::disk(Directory::getAssetDisk())->delete($asset->path);

    expect($this->writer->write($asset->path, 'exports/7/mothership/assets/Root/hero.jpg'))->toBeTrue();
    expect(Storage::disk('private')->get('exports/7/mothership/assets/Root/hero.jpg'))->toBe('binary');
});

it('writes a shared asset only once within a batch', function () {
    $asset = bundleWriterAsset('assets/Root/hero.jpg', 'binary');

    $destination = 'exports/7/mothership/assets/Root/hero.jpg';

    expect($this->writer->write($asset->path, $destination))->toBeTrue()
        ->and($this->writer->write($asset->path, $destination))->toBeFalse()
        ->and($this->writer->write($asset->path, $destination))->toBeFalse();
});

it('skips an asset a sibling batch already copied', function () {
    $asset = bundleWriterAsset('assets/Root/hero.jpg', 'binary');
    Storage::disk('private')->put('exports/7/mothership/assets/Root/hero.jpg', 'written-earlier');

    expect((new AssetBundleWriter)->write($asset->path, 'exports/7/mothership/assets/Root/hero.jpg'))
        ->toBeFalse()
        ->and(Storage::disk('private')->get('exports/7/mothership/assets/Root/hero.jpg'))
        ->toBe('written-earlier');
});

it('leaves a visible manifest entry for a path no asset owns, never a silent gap', function () {
    $destination = 'exports/7/mothership/assets/Root/gone.jpg';

    expect($this->writer->write('assets/Root/gone.jpg', $destination))->toBeFalse();

    Storage::disk('private')->assertMissing($destination);
    Storage::disk('private')->assertExists($destination.'.MISSING.txt');
    expect(Storage::disk('private')->get($destination.'.MISSING.txt'))
        ->toContain('assets/Root/gone.jpg')->toContain('no_original');
});

it('leaves a visible manifest entry for an asset whose blob is missing, never a silent gap', function () {
    $asset = bundleWriterAsset('assets/Root/gone.jpg', 'binary');
    $object = $asset->files()->where('role', 'original')->firstOrFail()->fileObject;
    Storage::disk($object->disk)->delete($object->path);

    $destination = 'exports/7/mothership/assets/Root/gone.jpg';

    expect($this->writer->write($asset->path, $destination))->toBeFalse();

    Storage::disk('private')->assertMissing($destination);
    Storage::disk('private')->assertExists($destination.'.MISSING.txt');
    expect(Storage::disk('private')->get($destination.'.MISSING.txt'))
        ->toContain('blob_missing');
});

it('fails the export instead of marking a destination handled when the missing-content marker itself cannot be written', function () {
    // The export disk (AssetBundleWriter::EXPORT_DISK) runs with `throw => false`, so a real write
    // failure there reports as put() returning false, never an exception — this mock reproduces
    // exactly that contract rather than a disk that throws.
    $destination = 'exports/7/mothership/assets/Root/gone.jpg';
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->with($destination)->andReturn(false);
    $disk->shouldReceive('put')->once()->with($destination.'.MISSING.txt', Mockery::type('string'))->andReturn(false);
    Storage::set('private', $disk);

    expect(fn () => $this->writer->write('assets/Root/gone.jpg', $destination))
        ->toThrow(RuntimeException::class);
});

it('never re-attempts a destination it already recorded missing within the same batch', function () {
    $destination = 'exports/7/mothership/assets/Root/gone.jpg';

    expect($this->writer->write('assets/Root/gone.jpg', $destination))->toBeFalse()
        ->and($this->writer->write('assets/Root/gone.jpg', $destination))->toBeFalse();

    // Exactly one manifest entry: the second call short-circuits on the in-memory dedup set,
    // the same one a successful write uses, rather than probing ContentStore again.
    expect(Storage::disk('private')->get($destination.'.MISSING.txt'))->not->toBeEmpty();
});

it('targets the disk the export archive is assembled from', function () {
    expect(AssetBundleWriter::EXPORT_DISK)->toBe('private');
});

/*
 * Exporter-level fixture (the shape CopiesDamMedia::copyMedia() is actually called through, not
 * AssetBundleWriter::write() directly): a minimal stand-in for a core DataTransfer exporter, whose own
 * copyMedia() is what non-asset fields fall back to. copyMedia()'s return type is void, so nothing at
 * this call site can observe write()'s bool; the manifest entry is the only way a missing original
 * becomes visible in what an exporter job actually ships.
 */
class FakeCoreExporterForBundleWriterTest
{
    public function copyMedia(string $sourcePath, string $destinationPath): void {}
}

class FakeDamExporterForBundleWriterTest extends FakeCoreExporterForBundleWriterTest
{
    use CopiesDamMedia;
}

it('ships a visible manifest entry in the export output when copyMedia is called through the exporter trait', function () {
    $exporter = new FakeDamExporterForBundleWriterTest;
    $destination = 'exports/9/mothership/assets/Root/gone.jpg';

    $exporter->copyMedia('assets/Root/gone.jpg', $destination, true);

    // No final-archive assertion: the actual zip step is not in this package (grepped
    // packages/Mothership/DataTransfer/src for ZipArchive/zip-building code and found none — a `with_media`
    // export's file_path is left pointing at the temporary staging folder itself, per
    // DataTransfer\Helpers\Export::uploadFile()). The staging output checked here is what
    // copyMedia()'s caller actually produces and the only archive-shaped thing this repo can drive.
    Storage::disk('private')->assertMissing($destination);
    Storage::disk('private')->assertExists($destination.'.MISSING.txt');
});

it('propagates the export failure through copyMedia when the missing-content marker cannot be written', function () {
    $exporter = new FakeDamExporterForBundleWriterTest;
    $destination = 'exports/9/mothership/assets/Root/gone.jpg';
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->with($destination)->andReturn(false);
    $disk->shouldReceive('put')->once()->with($destination.'.MISSING.txt', Mockery::type('string'))->andReturn(false);
    Storage::set('private', $disk);

    expect(fn () => $exporter->copyMedia('assets/Root/gone.jpg', $destination, true))
        ->toThrow(RuntimeException::class);
});
