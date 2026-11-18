<?php

/*
 * Storage slice 2 acceptance (ADR 0009): "delete every Library-path file in a test fixture; every route,
 * viewer and job must still work". Each fixture is ingested the way an upload is, then every file under a
 * Library path is deleted, leaving only the content-addressed blobs. A blob that is itself missing must
 * surface as a 404, a recorded job failure or a ZIP MISSING entry, never as a silent success.
 *
 * Runs after the CI media-tools step: the PDF poster uses the real pdftoppm. ffmpeg is faked the way
 * PlaybackPreviewTest does it.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null]);
    DB::table('dam_shares')->delete();
});

function acceptancePng(): string
{
    $image = imagecreatetruecolor(640, 320);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150));
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function acceptanceZip(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'acceptance-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    @unlink($path);

    return $bytes;
}

/** The body of a local response, whatever response class carried it. */
function acceptanceBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
        $base instanceof StreamedResponse   => (string) $response->streamedContent(),
        default                             => (string) $base->getContent(),
    };
}

/** @return list<string> */
function acceptanceZipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'acceptance-read-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($path);
    sort($names);

    return $names;
}

/** The last record ContentStore wrote to storage/logs/content-store.jsonl. */
function acceptanceLastUnavailable(): array
{
    $lines = file(storage_path(ContentStore::LOG), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return json_decode((string) end($lines), true) ?? [];
}

/**
 * Six uploads in one folder (image, video with a VTT caption, PDF, GLB model, web-page zip), ingested as
 * their originals, then every Library-path file deleted.
 *
 * @return array{directory: Directory, bytes: array<string, string>, assets: array<string, Asset>, caption: AssetFile}
 */
function acceptanceLibrary(): array
{
    $disk = Storage::disk(Directory::getAssetDisk());
    $directory = Directory::factory()->create(['name' => 'Acceptance']);
    $base = 'assets/Acceptance/';
    $bytes = [
        'image' => acceptancePng(),
        'video' => 'mp4-original-bytes',
        'pdf'   => file_get_contents(base_path('tests/e2e-pw/assets/sample.pdf')),
        'model' => "glTF\x02\x00\x00\x00acceptance-model",
        'web'   => acceptanceZip(['index.html' => '<h1>accepted</h1>', 'css/site.css' => 'h1{}']),
    ];
    $rows = [
        'image' => ['file_name' => 'harbor.png', 'file_type' => 'image', 'mime_type' => 'image/png', 'extension' => 'png'],
        'video' => ['file_name' => 'clip.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4'],
        'pdf'   => ['file_name' => 'brief.pdf', 'file_type' => 'document', 'mime_type' => 'application/pdf', 'extension' => 'pdf'],
        'model' => ['file_name' => 'bracket.glb', 'file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'extension' => 'glb'],
        'web'   => ['file_name' => 'site.zip', 'file_type' => 'web', 'mime_type' => 'application/zip', 'extension' => 'zip'],
    ];

    $assets = [];
    foreach ($rows as $key => $row) {
        $path = $base.$row['file_name'];
        $disk->put($path, $bytes[$key]);
        $assets[$key] = Asset::factory()->create($row + ['path' => $path]);
        $directory->assets()->attach($assets[$key]->id);
    }

    $caption = attachPreviewFile($assets['video'], 'assets/Acceptance/clip.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR, 'English');

    foreach ($assets as $asset) {
        $disk->delete($asset->path);
    }

    // Only content-addressed blobs are left under assets/.
    expect(collect($disk->allFiles('assets'))->reject(fn (string $file) => str_starts_with($file, ContentStore::ROOT.'/'))->values()->all())
        ->toBe([]);

    return ['directory' => $directory, 'bytes' => $bytes, 'assets' => $assets, 'caption' => $caption];
}

it('serves every admin route from blobs alone', function () {
    $this->loginAsAdmin();
    ['bytes' => $bytes, 'assets' => $assets] = acceptanceLibrary();
    $image = $assets['image'];
    $sha = hash('sha256', $bytes['image']);

    // Fetch, thumbnail and preview by Library path.
    foreach (['image', 'video', 'pdf', 'model', 'web'] as $key) {
        $fetch = $this->get(route('admin.dam.file.fetch', ['path' => $assets[$key]->path]))->assertOk();
        expect(acceptanceBody($fetch))->toBe($bytes[$key]);
    }
    $this->get(route('admin.dam.file.thumbnail', ['path' => $image->path]), ['Accept' => 'image/*'])
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::thumbnail($sha, 'png'));
    $this->get(route('admin.dam.file.preview', ['path' => $image->path, 'size' => 300]))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(route('admin.dam.file.preview', ['path' => $assets['video']->path]))
        ->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->get(route('admin.dam.file.preview', ['path' => $assets['pdf']->path]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // A GLB has no server-made thumbnail (ADR 0006): the card keeps the model placeholder.
    $this->get(route('admin.dam.file.thumbnail', ['path' => $assets['model']->path]), ['Accept' => 'image/*'])
        ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->getJson(route('admin.dam.assets.primary_view.show', $assets['model']->id))->assertOk();

    // Single, compressed and custom downloads, named after the asset.
    foreach (['image', 'pdf', 'model', 'web'] as $key) {
        $download = $this->get(route('admin.dam.assets.download', $assets[$key]->id))->assertOk();
        expect(acceptanceBody($download))->toBe($bytes[$key])
            ->and($download->headers->get('Content-Disposition'))->toContain('attachment')->toContain($assets[$key]->file_name);
    }
    $compressed = $this->get(route('admin.dam.assets.download_compressed', $image->id))->assertOk();
    expect(acceptanceZipEntries(acceptanceBody($compressed)))->toBe(['harbor.png']);
    $same = $this->get(route('admin.dam.assets.custom_download', ['id' => $image->id, 'format' => 'png']))->assertOk();
    expect(acceptanceBody($same))->toBe($bytes['image']);
    $resized = $this->get(route('admin.dam.assets.custom_download', ['id' => $image->id, 'format' => 'jpg', 'width' => 64]))->assertOk();
    expect(getimagesizefromstring(acceptanceBody($resized))[0])->toBe(64);

    // A ZIP of the whole folder.
    $zip = $this->get(route('admin.dam.directory.zip_download', ['id' => $assets['image']->directories()->value('dam_directories.id')]))->assertOk();
    expect(acceptanceZipEntries(acceptanceBody($zip)))->toBe(['Acceptance/bracket.glb', 'Acceptance/brief.pdf', 'Acceptance/clip.mp4', 'Acceptance/harbor.png', 'Acceptance/site.zip']);

    // Generation details stream the generated output.
    GenerationAsset::create(['generation_id' => 'device:acceptance', 'asset_id' => $image->id, 'manifest' => ['prompt' => 'harbor'], 'manifest_sha256' => hash('sha256', 'manifest')]);
    $this->getJson(route('admin.dam.assets.generation', $image->id))->assertOk()->assertJsonPath('asset_id', $image->id);
    expect(acceptanceBody($this->get(route('admin.dam.assets.generation.output', $image->id))->assertOk()))->toBe($bytes['image']);

    // Metadata extraction, on demand and in the upload job.
    $this->getJson(route('admin.dam.assets.metadata', $image->id))->assertOk()->assertJsonPath('success', true);
    Queue::fake();
    (new ProcessAssetUpload($image->id))->handle(app(MetadataExtractionService::class));
    expect($image->fresh()->meta_data)->toBeArray()->not->toBeEmpty();
});

it('plays the video and its caption and renders every viewer URL from blobs alone', function () {
    $this->loginAsAdmin();
    ['bytes' => $bytes, 'assets' => $assets, 'caption' => $caption] = acceptanceLibrary();
    $video = $assets['video']->fresh();

    $playback = $video->playbackSource();
    expect($playback)->toMatchArray(['kind' => 'video', 'mime' => 'video/mp4', 'is_proxy' => false]);
    expect(acceptanceBody($this->get($playback['url'])->assertOk()->assertHeader('Content-Type', 'video/mp4')))->toBe($bytes['video']);

    $captions = $video->captionSource();
    expect($captions['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $video->id, 'assetFile' => $caption->id]));
    $vtt = $this->get($captions['url'])->assertOk();
    expect($vtt->headers->get('Content-Type'))->toStartWith('text/vtt')
        ->and(acceptanceBody($vtt))->toBe('preview-fixture');

    $image = $assets['image']->fresh();
    expect($image->playbackSource()['kind'])->toBe('image');
    expect(acceptanceBody($this->get($image->playbackSource()['url'])->assertOk()))->toBe($bytes['image']);

    $this->get(route('admin.dam.assets.edit', $video->id))->assertOk();
});

it('serves share links, share downloads and share ZIPs from blobs alone', function () {
    ['bytes' => $bytes, 'assets' => $assets, 'directory' => $directory] = acceptanceLibrary();
    $image = $assets['image'];
    $assetShare = Share::factory()->forAsset($image->id)->create();

    $this->get(route('dam.share.show', $assetShare->urlToken()))->assertOk()->assertSeeText('harbor.png');
    $download = $this->get(route('dam.share.download', $assetShare->urlToken()))->assertOk();
    expect(acceptanceBody($download))->toBe($bytes['image'])
        ->and($download->headers->get('Content-Disposition'))->toContain('harbor.png');
    $this->get(route('dam.share.thumbnail', ['token' => $assetShare->urlToken(), 'assetId' => $image->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');

    $folderShare = Share::factory()->forDirectory($directory->id)->create();
    $this->get(route('dam.share.asset_view', ['token' => $folderShare->urlToken(), 'assetId' => $assets['pdf']->id]))->assertOk();
    $pdf = $this->get(route('dam.share.asset_download', ['token' => $folderShare->urlToken(), 'assetId' => $assets['pdf']->id]))->assertOk();
    expect(acceptanceBody($pdf))->toBe($bytes['pdf']);
    $zip = $this->get(route('dam.share.download_zip', $folderShare->urlToken()))->assertOk();
    $entries = acceptanceZipEntries(acceptanceBody($zip));
    expect($entries)->toHaveCount(5)
        ->and(array_filter($entries, fn (string $name) => str_ends_with($name, '.MISSING.txt')))->toBe([]);
});

it('serves the API download link and its signed URL from blobs alone', function () {
    ['bytes' => $bytes, 'assets' => $assets] = acceptanceLibrary();
    $headers = $this->getAuthenticationHeaders();
    $pdf = $assets['pdf'];

    $url = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', $pdf->id))
        ->assertOk()->assertJsonPath('success', true)->json('data.download_url');
    $signed = $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk();

    expect(acceptanceBody($signed))->toBe($bytes['pdf'])
        ->and($signed->headers->get('Content-Disposition'))->toContain('brief.pdf');
});

it('runs the poster, PDF thumbnail and web-page jobs from blobs alone', function () {
    $this->loginAsAdmin();
    ['bytes' => $bytes, 'assets' => $assets] = acceptanceLibrary();

    $videoJob = new class($assets['video']->id) extends GenerateVideoThumbnail
    {
        public ?string $input = null;

        public ?string $inputBytes = null;

        protected function runFfmpeg(array $command): array
        {
            $this->input = $command[array_search('-i', $command, true) + 1];
            $this->inputBytes = file_get_contents($this->input);
            file_put_contents($command[array_key_last($command)], 'jpeg-poster');

            return [true, ''];
        }
    };
    $videoJob->handle();
    $videoPoster = DerivativeImages::poster(hash('sha256', $bytes['video']));
    expect($videoJob->inputBytes)->toBe($bytes['video'])
        ->and(basename($videoJob->input))->toBe('source.mp4')
        ->and(file_exists($videoJob->input))->toBeFalse()
        ->and($assets['video']->fresh()->meta_data['thumbnail_path'])->toBe($videoPoster);
    Storage::disk(Directory::getAssetDisk())->assertExists($videoPoster);

    (new GeneratePdfThumbnail($assets['pdf']->id))->handle();
    $pdfPoster = DerivativeImages::poster(hash('sha256', $bytes['pdf']));
    Storage::disk(Directory::getAssetDisk())->assertExists($pdfPoster);
    $this->get(route('admin.dam.file.thumbnail', ['path' => $assets['pdf']->path]), ['Accept' => 'image/*'])->assertOk();

    $web = $assets['web'];
    $manifest = app(WebPageBundler::class)->build($web, WebPageBundler::versionNumber($web));
    expect($manifest)->toMatchArray(['entry' => 'index.html', 'file_count' => 2]);
    expect(Storage::disk(Directory::getAssetDisk())->get($manifest['root'].'/index.html'))->toBe('<h1>accepted</h1>');
})->skip(fn () => trim((string) shell_exec('command -v pdftoppm')) === '', 'pdftoppm is not installed');

it('surfaces a missing blob as a 404, a recorded job failure or a ZIP MISSING entry', function () {
    $this->loginAsAdmin();
    ['assets' => $assets, 'directory' => $directory] = acceptanceLibrary();
    $store = app(ContentStore::class);
    $objects = [];
    foreach (['image', 'video', 'pdf'] as $key) {
        $objects[$key] = $store->current($assets[$key]);
        Storage::disk($objects[$key]->disk)->delete($objects[$key]->path);
    }
    $image = $assets['image'];

    $this->getJson(route('admin.dam.file.fetch', ['path' => $image->path]))->assertNotFound();
    $this->get(route('admin.dam.assets.download', $image->id))->assertNotFound();
    $this->get(route('admin.dam.assets.download_compressed', $image->id))->assertNotFound();
    $this->getJson(route('admin.dam.assets.custom_download', ['id' => $image->id, 'format' => 'png']))->assertNotFound();
    $signed = URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(5), ['id' => $image->id]);
    $this->get(parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY))->assertNotFound();

    $share = Share::factory()->forAsset($image->id)->create();
    $this->get(route('dam.share.download', $share->urlToken()))->assertNotFound();

    $zip = $this->get(route('admin.dam.directory.zip_download', ['id' => $directory->id]))->assertOk();
    expect(acceptanceZipEntries(acceptanceBody($zip)))
        ->toContain('Acceptance/harbor.png.MISSING.txt', 'Acceptance/clip.mp4.MISSING.txt', 'Acceptance/brief.pdf.MISSING.txt', 'Acceptance/site.zip');

    (new GeneratePdfThumbnail($assets['pdf']->id))->handle();
    expect(acceptanceLastUnavailable()['ctx'])->toMatchArray(['reason' => 'blob_missing', 'file_object_id' => $objects['pdf']->id]);
    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::poster($objects['pdf']->sha256));

    (new GenerateVideoThumbnail($assets['video']->id))->handle();
    expect(acceptanceLastUnavailable()['ctx'])->toMatchArray(['reason' => 'blob_missing', 'file_object_id' => $objects['video']->id]);
    expect(FileObject::query()->whereKey($objects['video']->id)->exists())->toBeTrue();
});
