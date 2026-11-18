<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\ImageEditorWorkingCopy;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function editorAsset(): Asset
{
    $file = UploadedFile::fake()->image('source.png', 200, 100);
    $path = 'assets/Root/'.uniqid().'.png';
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($file->getRealPath()));

    return Asset::factory()->create(['path'=>$path, 'file_name'=>basename($path), 'file_type'=>'image', 'extension'=>'png']);
}
function editorUrl(Asset $asset): string
{
    return '/'.config('app.admin_url').'/dam/assets/editor/'.$asset->id;
}

function editorLargeJpeg(int $width = 9000, int $height = 100): string
{
    $file = UploadedFile::fake()->image('wide.jpg', 16, 16);
    $bytes = file_get_contents($file->getRealPath());
    $offset = strpos($bytes, "\xff\xc0");
    if ($offset === false) {
        $offset = strpos($bytes, "\xff\xc2");
    }

    return substr_replace(substr_replace($bytes, pack('n', $height), $offset + 5, 2), pack('n', $width), $offset + 7, 2);
}

function editorFakeWorkingCopy(?Closure $during = null): void
{
    Process::fake(function ($process) use ($during) {
        $command = $process->command;
        if ($command[0] === 'vipsheader') {
            return Process::result();
        }
        expect($command[0])->toBe('vips');
        $image = imagecreatetruecolor(8192, 91);
        imagepng($image, $command[3]);
        imagedestroy($image);
        $during?->__invoke($command[3]);

        return Process::result();
    });
}

function editorLargeAsset(): Asset
{
    $path = 'assets/Root/'.uniqid().'.jpg';
    Storage::disk(Directory::getAssetDisk())->put($path, editorLargeJpeg());

    return Asset::factory()->create(['path'=>$path, 'file_name'=>basename($path), 'file_type'=>'image', 'extension'=>'jpg']);
}

it('opens an oversized original using a disclosed bounded resource and preserves original bytes', function () {
    $asset = editorLargeAsset();
    $original = app(ContentStore::class)->current($asset);
    $bytes = Storage::disk(Directory::getAssetDisk())->get($original->path);
    editorFakeWorkingCopy();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('source.original_width', 9000)
        ->assertJsonPath('source.working_width', 8192)->assertJsonPath('source.reduced', true)->json();
    $base = ImageEditorResource::findOrFail($opened['original_base']);
    expect($base->width)->toBe(8192)->and($base->height)->toBe(91)
        ->and(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe($bytes);
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $opened['revision']);
    Process::assertRanTimes(fn ($process) => $process->command[0] === 'vips', 1);
    $this->post(editorUrl($asset).'/save', ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'version', 'render'=>UploadedFile::fake()->image('result.jpg', 8192, 91)], ['Accept'=>'application/json'])->assertOk();
    $retained = AssetFile::where('dam_asset_id', $asset->id)->where('file_object_id', $original->id)->firstOrFail();
    expect($retained->is_current)->toBeFalse()
        ->and(Storage::disk(Directory::getAssetDisk())->get($retained->fileObject->path))->toBe($bytes);
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('source.original_width', 9000);
});

it('cleans failed working files without creating a document or changing the original', function () {
    $asset = editorLargeAsset();
    $bytes = Storage::disk(Directory::getAssetDisk())->get($asset->path);
    $output = null;
    Process::fake(function ($process) use (&$output) {
        if ($process->command[0] === 'vipsheader') {
            return Process::result();
        }
        $output = $process->command[3];
        file_put_contents($output, 'incomplete');

        return Process::result(exitCode: 1);
    });
    $this->getJson(editorUrl($asset))->assertUnprocessable();
    expect(is_file($output))->toBeFalse()->and(is_dir(dirname($output)))->toBeFalse()
        ->and(ImageEditorDocument::where('asset_id', $asset->id)->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe($bytes);
});

it('refuses to attach a working copy when its source changes during processing', function () {
    $asset = editorLargeAsset();
    editorFakeWorkingCopy(fn () => DB::table('dam_assets')->where('id', $asset->id)->update(['path' => 'assets/Root/changed.png']));
    $this->getJson(editorUrl($asset))->assertConflict();
    expect(ImageEditorDocument::where('asset_id', $asset->id)->count())->toBe(0);
});

it('rejects a changed original identity, version or missing original during processing', function (string $change) {
    $asset = editorLargeAsset();
    editorFakeWorkingCopy(function () use ($asset, $change) {
        $original = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
        if ($change === 'missing') {
            $original->update(['role' => AssetFile::ROLE_VERSION]);
        } elseif ($change === 'version') {
            AssetVersion::create(['dam_asset_id'=>$asset->id, 'number'=>1, 'primary_asset_file_id'=>$original->id]);
        } else {
            $file = UploadedFile::fake()->image('different.png', 20, 20);
            $object = app(ContentStore::class)->putLocal(disk: Directory::getAssetDisk(), localPath: $file->getRealPath());
            $original->update(['file_object_id' => $object->id]);
        }
    });
    $this->getJson(editorUrl($asset))->assertConflict();
    expect(ImageEditorDocument::where('asset_id', $asset->id)->count())->toBe(0);
})->with(['sha', 'version', 'missing']);

it('reuses the winning document when another opener attaches while processing', function () {
    $asset = editorLargeAsset();
    $revision = (string) Str::uuid();
    editorFakeWorkingCopy(function ($output) use ($asset, $revision) {
        $object = app(ContentStore::class)->putLocal(disk: Directory::getAssetDisk(), localPath: $output);
        $base = ImageEditorResource::create(['id'=>(string) Str::uuid(), 'asset_id'=>$asset->id, 'admin_id'=>auth()->guard('admin')->id(), 'kind'=>'base', 'status'=>'ready', 'file_object_id'=>$object->id, 'width'=>8192, 'height'=>91]);
        ImageEditorDocument::create(['asset_id'=>$asset->id, 'asset_version_id'=>null, 'revision'=>$revision, 'content_path'=>$asset->path, 'original_base'=>$base->id, 'document'=>app(ImageEditorSchema::class)->initial($base->id, 8192, 91)]);
    });
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $revision);
    expect(ImageEditorDocument::where('asset_id', $asset->id)->count())->toBe(1)
        ->and(ImageEditorResource::where('asset_id', $asset->id)->count())->toBe(1);
});

it('reduces oversized uploaded images through the same resource seam', function () {
    $asset = editorAsset();
    editorFakeWorkingCopy();
    $file = UploadedFile::fake()->createWithContent('wide.jpg', editorLargeJpeg());
    $this->post(editorUrl($asset).'/resources', ['image' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('source.original_width', 9000)->assertJsonPath('width', 8192);
});

it('bounds non-square pixel budgets and swaps EXIF oriented working axes', function (int $orientation, int $targetWidth, int $targetHeight) {
    $file = UploadedFile::fake()->createWithContent('large.jpg', editorLargeJpeg(12000, 8000));
    Process::fake(function ($process) use ($orientation, $targetWidth, $targetHeight) {
        if ($process->command[0] === 'vipsheader') {
            return Process::result(output: 'orientation: '.$orientation."\n");
        }
        expect($process->command[4])->toBe((string) $targetWidth)
            ->and($process->command[6])->toBe((string) $targetHeight)
            ->and($targetWidth * $targetHeight)->toBeLessThanOrEqual(32000000)
            ->and($process->command)->not->toContain('--no-rotate');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $process->command[3]);
        imagedestroy($image);

        return Process::result();
    });
    $working = app(ImageEditorWorkingCopy::class)->prepare(path: $file->getRealPath());
    expect($working['details']['original_width'])->toBe($orientation >= 5 ? 8000 : 12000)
        ->and($working['details']['original_height'])->toBe($orientation >= 5 ? 12000 : 8000);
    $working['temporary']->release();
})->with([[1, 6928, 4618], [6, 4618, 6928], [8, 4618, 6928]]);

it('rejects corrupt working input before invoking external processing', function () {
    Process::fake();
    $asset = editorAsset();
    $this->post(editorUrl($asset).'/resources', ['image' => UploadedFile::fake()->createWithContent('bad.png', 'corrupt')], ['Accept'=>'application/json'])->assertUnprocessable();
    Process::assertNothingRan();
});

it('rejects a zero dimension image header with 422', function () {
    $file = UploadedFile::fake()->createWithContent('zero.jpg', editorLargeJpeg(9000, 0));
    expect(fn () => app(ImageEditorWorkingCopy::class)->prepare(path: $file->getRealPath()))
        ->toThrow(HttpException::class, 'Image dimensions must be positive.');
});

it('decodes real large transparent PNG and WebP and normalizes in-bounds EXIF JPEGs', function (string $format) {
    try {
        if (! Process::timeout(10)->run(['vips', '--version'])->successful()) {
            $this->markTestSkipped('libvips is unavailable in this test lane.');
        }
    } catch (ProcessStartFailedException) {
        $this->markTestSkipped('libvips is unavailable in this test lane.');
    }
    $jpeg = str_starts_with($format, 'jpg');
    if ($jpeg && ! function_exists('exif_read_data')) {
        $this->markTestSkipped('In-bounds JPEG orientation requires the exif extension.');
    }
    $image = imagecreatetruecolor($jpeg ? 120 : 9000, 100);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 240, 20, 20, $format === 'png' ? 64 : 0));
    imagefilledrectangle($image, (int) (imagesx($image) / 2), 0, imagesx($image) - 1, 99, imagecolorallocatealpha($image, 20, 20, 240, $format === 'png' ? 64 : 0));
    $file = UploadedFile::fake()->create('real.'.$format);
    $path = $file->getRealPath();
    match ($format) {
        'png'   => imagepng($image, $path),
        'webp'  => imagewebp($image, $path, 95),
        default => imagejpeg($image, $path, 95),
    };
    imagedestroy($image);
    if ($jpeg) {
        $bytes = file_get_contents($path);
        $orientation = (int) substr($format, -1);
        $exif = "Exif\0\0II\x2a\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0".chr($orientation)."\0\0\0\0\0\0\0";
        file_put_contents($path, substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
    }
    $working = app(ImageEditorWorkingCopy::class)->prepare(path: $path);
    try {
        expect($working['info'][2])->toBe(IMAGETYPE_PNG)
            ->and($working['info'][0])->toBeLessThanOrEqual(8192)
            ->and($working['info'][0] * $working['info'][1])->toBeLessThanOrEqual(32000000);
        if ($jpeg) {
            $orientedWidth = $orientation >= 5 ? 100 : 120;
            $orientedHeight = $orientation >= 5 ? 120 : 100;
            expect([$working['info'][0], $working['info'][1]])->toBe([$orientedWidth, $orientedHeight])
                ->and($working['details']['original_width'])->toBe($orientedWidth)
                ->and($working['details']['original_height'])->toBe($orientedHeight)
                ->and($working['details']['reduced'])->toBeFalse();
            $output = imagecreatefrompng($working['path']);
            $first = imagecolorsforindex($output, imagecolorat($output, 25, 30));
            expect($first['red'] > $first['blue'])->toBe(in_array($format, ['jpg5', 'jpg6'], true));
            imagedestroy($output);
            $storedPath = 'assets/Root/oriented-'.uniqid().'.jpg';
            Storage::disk(Directory::getAssetDisk())->put($storedPath, file_get_contents($path));
            $asset = Asset::factory()->create(['path'=>$storedPath, 'file_name'=>basename($storedPath), 'file_type'=>'image', 'extension'=>'jpg']);
            $original = app(ContentStore::class)->current($asset);
            $opened = $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('document.sourceWidth', $orientedWidth)
                ->assertJsonPath('document.sourceHeight', $orientedHeight)->assertJsonPath('source.reduced', false)->json();
            expect(ImageEditorResource::findOrFail($opened['original_base'])->file_object_id)->not->toBe($original->id);
        }
        if ($format === 'png') {
            $output = imagecreatefrompng($working['path']);
            expect(imagecolorsforindex($output, imagecolorat($output, 10, 10))['alpha'])->toBeGreaterThan(0);
            imagedestroy($output);
        }
    } finally {
        $working['temporary']?->release();
    }
})->with(['png', 'webp', 'jpg3', 'jpg5', 'jpg6', 'jpg8']);

it('saves an editable revision with immutable source and rejects stale writers', function () {
    $asset = editorAsset();
    $original = Storage::disk(Directory::getAssetDisk())->get($asset->path);
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $doc = $opened['document'];
    $doc['annotations'][] = ['id'=>'text1', 'type'=>'text', 'x'=>1, 'y'=>2, 'width'=>90, 'height'=>20, 'text'=>'Editable', 'size'=>16, 'color'=>'#ff0000'];
    $save = ['revision'=>$opened['revision'], 'document'=>json_encode($doc), 'mode'=>'version', 'render'=>UploadedFile::fake()->image('result.png', 200, 100)];
    $this->post(editorUrl($asset).'/save', $save, ['Accept'=>'application/json'])->assertOk();
    $reopened = $this->getJson(editorUrl($asset))->assertOk()->json();
    expect($reopened['document']['annotations'][0]['text'])->toBe('Editable');
    expect($reopened['document']['base'])->toBe($doc['base']);
    expect(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe($original);
    $this->post(editorUrl($asset).'/save', $save, ['Accept'=>'application/json'])->assertConflict();
    $save['mode'] = 'new';
    $this->post(editorUrl($asset).'/save', $save, ['Accept'=>'application/json'])->assertOk();
    expect($asset->versions()->count())->toBeGreaterThan(0);
});
it('creates a separate asset in the source folder', function () {
    $asset = editorAsset();
    $folder = Directory::factory()->create();
    $asset->directories()->attach($folder->id);
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $saved = $this->post(editorUrl($asset).'/save', ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'new', 'render'=>UploadedFile::fake()->image('result.png', 200, 100)], ['Accept'=>'application/json'])->assertOk()->json();
    expect($saved['asset_id'])->not->toBe($asset->id);
    expect(Asset::find($saved['asset_id'])->directories()->pluck('dam_directories.id')->all())->toContain($folder->id);
    $this->getJson(editorUrl(Asset::find($saved['asset_id'])))->assertOk()->assertJsonPath('document.sourceWidth', 200);
});
it('gives a new-mode save a real folder-tree Library path, never assets/.editor/results', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $saved = $this->post(editorUrl($asset).'/save', ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'new', 'render'=>UploadedFile::fake()->image('result.png', 200, 100)], ['Accept'=>'application/json'])->assertOk()->json();

    $newAsset = Asset::find($saved['asset_id']);

    expect($newAsset->path)->toBe('assets/Root/'.$newAsset->file_name)
        ->and($newAsset->path)->not->toContain('.editor');
});

it('avoids a path collision when a version-mode save flips the extension onto an existing sibling name', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 200, 100);
    $stem = 'photo-'.uniqid();
    $path = 'assets/Root/'.$stem.'.jpg';
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($file->getRealPath()));
    $asset = Asset::factory()->create(['path'=>$path, 'file_name'=>$stem.'.jpg', 'file_type'=>'image', 'extension'=>'jpg']);
    // Already occupies the .png name the version-mode save (always PNG output) would otherwise pick.
    Asset::factory()->create(['path'=>'assets/Root/'.$stem.'.png', 'file_name'=>$stem.'.png', 'file_type'=>'image', 'extension'=>'png']);

    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $save = ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'version', 'render'=>UploadedFile::fake()->image('result.png', 200, 100)];

    $this->post(editorUrl($asset).'/save', $save, ['Accept'=>'application/json'])->assertOk();

    expect($asset->fresh()->path)->toBe('assets/Root/'.$stem.'(1).png');
});

it('rejects invalid document references and mismatched PNG dimensions', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $data = ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'version', 'render'=>UploadedFile::fake()->image('result.png', 99, 99)];
    $this->post(editorUrl($asset).'/save', $data, ['Accept'=>'application/json'])->assertUnprocessable();
    $opened['document']['base'] = '../../other.png';
    $data['document'] = json_encode($opened['document']);
    $this->post(editorUrl($asset).'/save', $data, ['Accept'=>'application/json'])->assertUnprocessable();
});
it('does not expose another assets image resource', function () {
    $one = editorAsset();
    $two = editorAsset();
    $opened = $this->getJson(editorUrl($one))->assertOk()->json();
    $this->get(editorUrl($two).'/resources/'.$opened['document']['base'])->assertNotFound();
});

it('reauthorizes folder access on editor document and save requests', function () {
    $asset = editorAsset();
    $folder = Directory::factory()->create();
    $asset->directories()->attach($folder->id);
    $service = Mockery::mock(DirectoryPermissionService::class);
    $service->shouldReceive('bypass')->andReturn(false);
    $service->shouldReceive('canAccess')->with($folder->id)->andReturn(false);
    app()->instance(DirectoryPermissionService::class, $service);
    $this->getJson(editorUrl($asset))->assertNotFound();
    $this->postJson(editorUrl($asset).'/save', [])->assertNotFound();
});

it('reuses the document and original resource on repeated reopen', function () {
    $asset = editorAsset();
    $first = $this->getJson(editorUrl($asset))->assertOk()->json();
    $second = $this->getJson(editorUrl($asset))->assertOk()->json();
    expect($second['revision'])->toBe($first['revision']);
    expect(ImageEditorDocument::where('asset_id', $asset->id)->count())->toBe(1);
    expect(ImageEditorResource::where('asset_id', $asset->id)->count())->toBe(1);
});

it('renders the actual asset detail page with the localized editor dictionary', function () {
    $asset = editorAsset();
    $this->get(route('admin.dam.assets.edit', $asset->id))
        ->assertOk()
        ->assertSee('loadDamImageEditor', false)
        ->assertSee('labels:', false)
        ->assertSee('Close editor', false);
});

it('purges only unreferenced AI drafts, their working files and the AI permission', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $disk = Storage::disk(Directory::getAssetDisk());
    $make = function (string $kind, string $status, array $details = []) use ($asset, $disk): ImageEditorResource {
        $id = (string) Str::uuid();
        $disk->copy($asset->path, 'assets/.editor/'.$asset->id.'/'.$id);

        return ImageEditorResource::create(['id'=>$id, 'asset_id'=>$asset->id, 'admin_id'=>auth()->guard('admin')->id(), 'kind'=>$kind, 'status'=>$status, 'path'=>'assets/.editor/'.$asset->id.'/'.$id, 'width'=>200, 'height'=>100, 'details'=>$details ?: null]);
    };
    $upload = $make('upload', 'ready');
    $dead = $make('draft', 'accepted');
    $disk->put($dead->path.'-mask', 'mask');
    // A details path outside the editor working area must never be deleted.
    $dead->update(['details'=>['mask'=>$dead->path.'-mask', 'input'=>$asset->path]]);
    $queued = $make('draft', 'queued');
    $referenced = $make('draft', 'accepted');
    ImageEditorDocument::where('revision', $opened['revision'])->update(['document'=>array_merge($opened['document'], ['base'=>$referenced->id])]);
    $role = Role::factory()->create(['permission_type'=>'custom', 'permissions'=>['dam.asset.editor', 'dam.asset.editor.gpt']]);

    (require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_09_21_000002_purge_image_editor_ai_drafts.php'))->up();

    expect(ImageEditorResource::find($dead->id))->toBeNull()
        ->and(ImageEditorResource::find($queued->id))->toBeNull()
        ->and($disk->exists($dead->path))->toBeFalse()
        ->and($disk->exists($dead->path.'-mask'))->toBeFalse()
        ->and($disk->exists($asset->path))->toBeTrue()
        ->and(ImageEditorResource::find($referenced->id)->only(['kind', 'status']))->toBe(['kind'=>'saved', 'status'=>'saved'])
        ->and(ImageEditorResource::where('kind', 'draft')->exists())->toBeFalse()
        ->and($disk->exists($referenced->path))->toBeTrue()
        ->and(ImageEditorResource::find($upload->id))->not->toBeNull()
        ->and($disk->exists($upload->path))->toBeTrue()
        ->and(ImageEditorResource::find($opened['original_base']))->not->toBeNull()
        ->and($role->refresh()->permissions)->toBe(['dam.asset.editor']);
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('document.base', $referenced->id);
});

it('keeps a JPEG source a JPEG when the editor sends a JPEG render', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 200, 100);
    $path = 'assets/Root/keep-'.uniqid().'.jpg';
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($file->getRealPath()));
    $asset = Asset::factory()->create(['path'=>$path, 'file_name'=>basename($path), 'file_type'=>'image', 'extension'=>'jpg']);

    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $save = ['revision'=>$opened['revision'], 'document'=>json_encode($opened['document']), 'mode'=>'version', 'render'=>UploadedFile::fake()->image('edited.jpg', 200, 100)];
    $this->post(editorUrl($asset).'/save', $save, ['Accept'=>'application/json'])->assertOk();

    expect($asset->fresh()->path)->toBe($path)
        ->and($asset->fresh()->extension)->toBe('jpg');
});

/** A PhotoEdit (schema v2) document over the asset's opened base. */
function photoeditDocument(array $opened, array $objects = [], array $changes = []): array
{
    $v1 = $opened['document'];

    return array_replace_recursive([
        'schemaVersion' => 2, 'base' => $v1['base'], 'sourceWidth' => $v1['sourceWidth'], 'sourceHeight' => $v1['sourceHeight'],
        'crop'          => ['x' => 0, 'y' => 0, 'width' => $v1['sourceWidth'], 'height' => $v1['sourceHeight']],
        'resize'        => ['width' => $v1['sourceWidth'], 'height' => $v1['sourceHeight']],
        'rotation'      => 0, 'flipX' => false, 'flipY' => false,
        'adjustments'   => ['brightness' => 0, 'contrast' => 0, 'sharpen' => 0, 'blur' => 0, 'grayscale' => false, 'invert' => false],
        'canvasColor'   => null, 'objectShadow' => true, 'counterStart' => 1, 'counterStyle' => 'numbers', 'objects' => $objects,
        'background'    => ['enabled' => false, 'type' => 'gradient', 'color' => '#ffffff', 'gradient' => ['#ff7aa8', '#7b5cff'], 'angle' => 135, 'image' => null, 'padding' => 64, 'inset' => 0, 'autoBalance' => false, 'shadow' => 30, 'corners' => 20, 'alignment' => 4, 'ratio' => null],
    ], $changes);
}

function photoeditImageObject(string $id, string $resource): array
{
    return ['id' => $id, 'type' => 'image', 'x' => 10, 'y' => 12, 'width' => 60, 'height' => 40, 'resource' => $resource];
}

function photoeditSave($test, Asset $asset, array $opened, array $document, string $mode, ?UploadedFile $render = null)
{
    return $test->post(editorUrl($asset).'/save', ['revision' => $opened['revision'], 'document' => json_encode($document), 'mode' => $mode, 'render' => $render ?? UploadedFile::fake()->image('result.png', 200, 100)], ['Accept' => 'application/json']);
}

function photoeditLayer($test, Asset $asset): string
{
    return $test->post(editorUrl($asset).'/resources', ['image' => UploadedFile::fake()->image('layer.png', 60, 40)], ['Accept' => 'application/json'])->assertOk()->json('id');
}

it('saves a schema v2 version with an image object and serves that image on reopen', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $layer = photoeditLayer($this, $asset);
    $document = photoeditDocument($opened, [photoeditImageObject('pic', $layer)]);

    $saved = photoeditSave($this, $asset, $opened, $document, 'version')->assertOk()->json();
    $reopened = $this->getJson(editorUrl($asset))->assertOk()->json();

    expect($saved['revision'])->not->toBe($opened['revision'])
        ->and($reopened['revision'])->toBe($saved['revision'])
        ->and($reopened['document']['schemaVersion'])->toBe(2)
        ->and($reopened['document']['objects'][0]['resource'])->toBe($layer)
        ->and($reopened['resources'])->toHaveKeys([$opened['document']['base'], $layer])
        ->and($reopened['original_base'])->toBe($opened['original_base']);
    $this->get($reopened['resources'][$layer])->assertOk();
    // The editor's own stale-writer rule applies to v2 unchanged.
    photoeditSave($this, $asset, $opened, $document, 'version')->assertConflict();
});

it('refuses a schema v2 save whose image object names a resource the asset does not own, or cannot use', function () {
    $asset = editorAsset();
    $other = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $foreign = photoeditLayer($this, $other);
    $unusable = photoeditLayer($this, $asset);
    ImageEditorResource::whereKey($unusable)->update(['status' => 'queued']);
    $unbacked = photoeditLayer($this, $asset);
    ImageEditorResource::whereKey($unbacked)->update(['file_object_id' => null]);

    foreach (['foreign' => $foreign, 'missing' => (string) Str::uuid(), 'unusable' => $unusable, 'without bytes' => $unbacked] as $why => $resource) {
        $document = photoeditDocument($opened, [photoeditImageObject('first', photoeditLayer($this, $asset)), photoeditImageObject('second', $resource)]);
        photoeditSave($this, $asset, $opened, $document, 'version')->assertUnprocessable();
        photoeditSave($this, $asset, $opened, $document, 'new')->assertUnprocessable();
    }
    // Nothing was written: the same revision still opens, and no copy exists.
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $opened['revision']);
    expect(Asset::where('file_name', 'like', '% - edited.%')->count())->toBe(0);
});

it('points every image object of a save-as-new copy at the copy\'s own resources, shared or not', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $layer = photoeditLayer($this, $asset);
    $backdrop = photoeditLayer($this, $asset);
    $document = photoeditDocument($opened, [photoeditImageObject('one', $layer), photoeditImageObject('two', $layer), photoeditImageObject('three', $backdrop)], ['background' => ['enabled' => true, 'type' => 'image', 'image' => $backdrop]]);
    $render = UploadedFile::fake()->image('result.png', 328, 228);

    $saved = photoeditSave($this, $asset, $opened, $document, 'new', $render)->assertOk()->json();
    $copy = Asset::findOrFail($saved['asset_id']);
    $reopened = $this->getJson(editorUrl($copy))->assertOk()->json();
    $own = ImageEditorResource::where('asset_id', $copy->id)->pluck('id')->all();
    $objects = collect($reopened['document']['objects'])->pluck('resource', 'id');

    expect($copy->id)->not->toBe($asset->id)
        ->and($objects['one'])->toBe($objects['two'])
        ->and($objects['one'])->not->toBeIn([$layer, $backdrop])
        ->and($objects['three'])->toBe($reopened['document']['background']['image'])
        ->and($objects['three'])->not->toBeIn([$layer, $backdrop, $objects['one']])
        ->and($reopened['document']['base'])->not->toBe($opened['document']['base'])
        ->and($own)->toContain($objects['one'], $objects['three'], $reopened['document']['base'], $reopened['original_base'])
        ->and(array_keys($reopened['resources']))->toEqualCanonicalizing(array_values(array_unique([$reopened['original_base'], $reopened['document']['base'], $objects['one'], $objects['three']])));
    foreach ($reopened['resources'] as $url) {
        $this->get($url)->assertOk();
    }
    // The source asset still names its own rows.
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $opened['revision']);
    expect(ImageEditorResource::where('asset_id', $asset->id)->whereIn('id', [$layer, $backdrop])->count())->toBe(2);
});

it('accepts a schema v2 render beyond the v1 size limits and bounds oversized uploads', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    // 8192x450 framed to 20:1 is 9000x450: wider than any v1 render may be.
    $wide = photoeditDocument($opened, [], ['resize' => ['width' => 8192, 'height' => 450], 'background' => ['enabled' => true, 'padding' => 0, 'ratio' => [20, 1]]]);
    photoeditSave($this, $asset, $opened, $wide, 'version', UploadedFile::fake()->image('result.png', 9000, 450))->assertOk();

    // 5000x5000 with 500px padding is 6000x6000: more than 32 megapixels, within the 64 the model allows.
    $reopened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $big = photoeditDocument($reopened, [], ['resize' => ['width' => 5000, 'height' => 5000], 'background' => ['enabled' => true, 'padding' => 500]]);
    photoeditSave($this, $asset, $reopened, $big, 'version', UploadedFile::fake()->image('result.png', 6000, 6000))->assertOk();

    // The render must still be the size the document says.
    $reopened = $this->getJson(editorUrl($asset))->assertOk()->json();
    photoeditSave($this, $asset, $reopened, $wide, 'version', UploadedFile::fake()->image('result.png', 8192, 450))->assertUnprocessable();
    editorFakeWorkingCopy();
    $this->post(editorUrl($asset).'/resources', ['image' => UploadedFile::fake()->image('layer.png', 8193, 100)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('source.reduced', true);
});

it('refuses a schema v2 document the editor could not have exported', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();
    $document = photoeditDocument($opened);
    unset($document['counterStyle']);

    photoeditSave($this, $asset, $opened, $document, 'version')->assertUnprocessable();
    photoeditSave($this, $asset, $opened, photoeditDocument($opened, [], ['background' => ['ratio' => [21, 1]]]), 'version')->assertUnprocessable();
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $opened['revision']);
});

it('refuses a schema v2 save naming a resource id the schema allows but no resource can have', function () {
    $asset = editorAsset();
    $opened = $this->getJson(editorUrl($asset))->assertOk()->json();

    foreach ([
        'a falsy id'       => photoeditDocument($opened, [photoeditImageObject('zero', '0')]),
        'an empty id'      => photoeditDocument($opened, [], ['background' => ['image' => '']]),
        'a non-uuid image' => photoeditDocument($opened, [photoeditImageObject('odd', 'layer-1')]),
    ] as $why => $document) {
        photoeditSave($this, $asset, $opened, $document, 'version')->assertUnprocessable();
    }
    $this->getJson(editorUrl($asset))->assertOk()->assertJsonPath('revision', $opened['revision']);
});
