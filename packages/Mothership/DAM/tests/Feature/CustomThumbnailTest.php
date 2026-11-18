<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\ImageCompleteness;
use Mothership\DAM\Support\ImageEditing;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    $this->asset = Asset::factory()->create(['path' => 'assets/model.stl', 'file_name' => 'model.stl',
        'extension'                                 => 'stl', 'file_type' => 'document', 'mime_type' => 'model/stl', 'file_size' => 8,
        'meta_data'                                 => ['description' => 'Keep this description', 'source_url' => 'https://example.com/model']]);
    Storage::disk(Directory::getAssetDisk())->put($this->asset->path, 'original');
    $this->url = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/custom-thumbnail';
});

function animatedThumbnailGif(): string
{
    // Two one-pixel frames with distinct palette indices and an infinite loop.
    return hex2bin('47494638396101000100800000000000ffffff21ff0b4e45545343415045322e300301000000'
        .'21f904000a0000002c0000000001000100000202440100'
        .'21f904000a0000002c00000000010001000002024c01003b');
}

it('serves every GIF frame unchanged while preserving the asset and its metadata', function () {
    $count = Asset::count();
    $versions = $this->asset->files()->count();
    $bytes = animatedThumbnailGif();
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('motion.gif', $bytes)])
        ->assertCreated()->json();
    $asset = $this->asset->fresh();
    expect($asset->path)->toBe('assets/model.stl');
    expect($asset->meta_data['description'])->toBe('Keep this description');
    expect($asset->meta_data['source_url'])->toBe('https://example.com/model');
    expect(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe('original');
    expect(Asset::count())->toBe($count);
    expect($asset->files()->count())->toBe($versions);
    $response = $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($bytes);
});

it('accepts JPEG and PNG and removes superseded thumbnails without changing the original', function () {
    $old = null;
    foreach (['jpg', 'png'] as $extension) {
        $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image('cover.'.$extension)])
            ->assertCreated()->json();
        $path = $this->asset->fresh()->meta_data['custom_thumbnail']['path'];
        Storage::disk(Directory::getAssetDisk())->assertExists($path);
        if ($old) {
            Storage::disk(Directory::getAssetDisk())->assertMissing($old);
        }
        $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()
            ->assertHeader('Content-Type', $extension === 'jpg' ? 'image/jpeg' : 'image/png');
        $old = $path;
    }
    app(AssetDeletionService::class)->delete($this->asset);
    Storage::disk(Directory::getAssetDisk())->assertMissing($old);
});

it('rejects invalid input and image targets without replacing a saved thumbnail', function () {
    $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image('cover.png')])->assertCreated();
    $before = $this->asset->fresh()->meta_data;
    $invalid = UploadedFile::fake()->createWithContent('fake.gif', 'not an image');
    $this->postJson($this->url, ['thumbnail' => new UploadedFile($invalid->getPathname(), 'fake.gif', test: true)])->assertUnprocessable();
    $this->postJson($this->url, [])->assertUnprocessable();
    expect($this->asset->fresh()->meta_data)->toBe($before);
    $this->asset->update(['file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png']);
    $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image('cover.png')])->assertUnprocessable();
});

// The video player's "Set this frame as thumbnail" posts a canvas JPEG as a named Blob (multipart part
// `thumbnail`, filename `<name>-frame-<t>s.jpg`, at most 1024 px on the longest side).
it('stores a captured video frame posted as a JPEG blob with a filename and serves it as the thumbnail', function () {
    $this->asset->update(['path' => 'assets/clip.mp4', 'file_name' => 'clip.mp4', 'extension' => 'mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4']);
    $frame = UploadedFile::fake()->image('clip-frame-12-480s.jpg', 1024, 576);
    $result = $this->post($this->url, ['thumbnail' => $frame], ['Accept' => 'application/json'])->assertCreated()->json();
    $saved = $this->asset->fresh()->meta_data['custom_thumbnail'];
    expect($saved['mime_type'])->toBe('image/jpeg');
    expect($saved['path'])->toMatch('#^custom-thumbnails/'.$this->asset->id.'/[a-f0-9-]{36}\.jpg$#');
    Storage::disk(Directory::getAssetDisk())->assertExists($saved['path']);
    expect($result['thumbnail_url'])->toContain('v='.$saved['revision']);
    $response = $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    // The grid route serves the 300 px derivative; the stored upload keeps its 1024x576 bytes.
    expect(getimagesizefromstring(customThumbnailBody($response)))->toMatchArray([0 => 300, 1 => 169]);
    expect(getimagesizefromstring(Storage::disk(Directory::getAssetDisk())->get($saved['path'])))->toMatchArray([0 => 1024, 1 => 576]);
});

it('rejects an oversized frame and a disallowed image type without saving anything', function () {
    $tooLarge = UploadedFile::fake()->image('huge-frame.jpg', 64, 64)->size(32 * 1024 + 1);
    $this->postJson($this->url, ['thumbnail' => $tooLarge])->assertUnprocessable()->assertJsonValidationErrorFor('thumbnail');
    $webp = UploadedFile::fake()->image('frame.webp', 64, 64);
    $this->postJson($this->url, ['thumbnail' => $webp])->assertUnprocessable()->assertJsonValidationErrorFor('thumbnail');
    expect($this->asset->fresh()->meta_data)->not->toHaveKey('custom_thumbnail');
    expect(Storage::disk(Directory::getAssetDisk())->allFiles('custom-thumbnails'))->toBe([]);
});

it('requires edit permission and access to the asset folder', function (bool $edit, bool $access) {
    $permissions = ['dam', 'dam.asset', 'dam.asset.view'];
    if ($edit) {
        $permissions[] = 'dam.asset.edit';
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $folder = Directory::factory()->create();
    $this->asset->directories()->attach($folder->id);
    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
    }
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image('cover.png')])->assertStatus($access ? 403 : 404);
    expect($this->asset->fresh()->meta_data)->not->toHaveKey('custom_thumbnail');
})->with([[false, true], [true, false]]);

it('returns the saved thumbnail after reopening a folder and changes its URL on replacement', function () {
    $folder = Directory::factory()->create();
    $this->asset->directories()->attach($folder->id);
    $browse = route('admin.dam.explorer.index', ['directory_id' => $folder->id]);
    $originalUrl = $this->getJson($browse)->assertOk()->json('assets.0.path');
    foreach (['first.png', 'second.png'] as $name) {
        $saved = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image($name)])->assertCreated()->json('thumbnail_url');
        $reopened = $this->getJson($browse)->assertOk()->assertJsonPath('assets.0.has_custom_thumbnail', true);
        expect($reopened->json('assets.0.path'))->toBe($saved)->not->toBe($originalUrl);
        $originalUrl = $saved;
    }
    $this->get('/'.config('app.admin_url').'/dam')->assertOk();
});

// G1-17: a truncated image passes the `image` rule and GD decodes it into a half-grey picture without a warning, so it
// was saved as the thumbnail. Each format is walked to its end, so a file cut short is refused and nothing changes,
// including a PNG missing only its last checksum and a GIF cut just after a data byte equal to the trailer (0x3B).
it('refuses a truncated image without replacing a saved thumbnail', function (string $name, Closure $cut) {
    $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->image('cover.png')])->assertCreated();
    $before = $this->asset->fresh()->meta_data;
    $bytes = $cut();
    $file = UploadedFile::fake()->createWithContent($name, $bytes);

    expect(getimagesizefromstring($bytes))->not->toBeFalse();
    $this->postJson($this->url, ['thumbnail' => new UploadedFile($file->getPathname(), $name, test: true)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.thumbnail.0', trans('dam::app.admin.explorer.custom-thumbnail.damaged'));
    expect($this->asset->fresh()->meta_data)->toBe($before);
})->with([
    'a JPEG cut in half' => ['photo.jpg', function () {
        $whole = UploadedFile::fake()->image('photo.jpg', 400, 300)->get();

        return substr($whole, 0, intdiv(strlen($whole), 2));
    }],
    'a PNG without its closing checksum' => ['photo.png', fn () => substr(UploadedFile::fake()->image('photo.png', 40, 30)->get(), 0, -4)],
    'a GIF cut after a 0x3B data byte'   => ['photo.gif', function () {
        $image = imagecreate(64, 64);
        for ($i = 0; $i < 256; $i++) {
            imagecolorallocate($image, $i, 255 - $i, ($i * 7) % 256);
        }
        mt_srand(17);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 255));
            }
        }
        ob_start();
        imagegif($image);
        $whole = (string) ob_get_clean();
        $at = strrpos(substr($whole, 0, -1), "\x3B");

        expect($at)->toBeGreaterThan(800);

        return substr($whole, 0, $at + 1);
    }],
]);

it('reads complete JPEG, PNG and animated GIF bytes as whole', function () {
    expect(ImageCompleteness::check(animatedThumbnailGif(), 'image/gif'))->toBeTrue();
    expect(ImageCompleteness::check(UploadedFile::fake()->image('a.png', 40, 30)->get(), 'image/png'))->toBeTrue();
    expect(ImageCompleteness::check(UploadedFile::fake()->image('a.jpg', 400, 300)->get(), 'image/jpeg'))->toBeTrue();
});

function customThumbnailPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    for ($i = 0; $i < 400; $i++) {
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagepng($image, null, 0);

    return (string) ob_get_clean();
}

function customThumbnailBody($response): string
{
    $base = $response->baseResponse;

    return $base instanceof BinaryFileResponse ? file_get_contents($base->getFile()->getPathname()) : $base->getContent();
}

it('serves a large PNG and JPEG custom thumbnail as a derivative of at most 300 px', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $png = customThumbnailPng(1600, 1000);
    $jpeg = (function () {
        $image = imagecreatetruecolor(900, 1800);
        imagefilledrectangle($image, 0, 0, 450, 900, 0xFF0000);
        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    })();

    foreach ([['cover.png', $png], ['cover.jpg', $jpeg]] as [$name, $bytes]) {
        $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent($name, $bytes)])->assertCreated()->json();
        $response = $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $body = customThumbnailBody($response);
        [$w, $h] = getimagesizefromstring($body);
        expect(max($w, $h))->toBeLessThanOrEqual(300)->and(strlen($body))->toBeLessThan(strlen($bytes));
        $custom = $this->asset->fresh()->meta_data['custom_thumbnail'];
        $disk->assertExists(CustomThumbnailService::derivativePath($custom));
    }
});

it('serves a stored derivative on a hit without decoding again, and a new revision gets its own', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $first = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $this->get($first['thumbnail_url'], ['Accept' => 'image/*'])->assertOk();
    $firstDerivative = CustomThumbnailService::derivativePath($this->asset->fresh()->meta_data['custom_thumbnail']);
    $disk->put($firstDerivative, 'sentinel');
    expect(customThumbnailBody($this->get($first['thumbnail_url'], ['Accept' => 'image/*'])))->toBe('sentinel');

    $second = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('b.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $secondDerivative = CustomThumbnailService::derivativePath($this->asset->fresh()->meta_data['custom_thumbnail']);
    expect($secondDerivative)->not->toBe($firstDerivative);
    $disk->assertMissing($firstDerivative);
    $body = customThumbnailBody($this->get($second['thumbnail_url'], ['Accept' => 'image/*'])->assertOk());
    expect($body)->not->toBe('sentinel')->and(max(...array_slice(getimagesizefromstring($body), 0, 2)))->toBeLessThanOrEqual(300);
});

it('keeps a GIF, a small image and an undecodable file as raw bytes', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $gif = animatedThumbnailGif();
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('m.gif', $gif)])->assertCreated()->json();
    expect(customThumbnailBody($this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()))->toBe($gif);

    $small = customThumbnailPng(120, 80);
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('s.png', $small)])->assertCreated()->json();
    expect(customThumbnailBody($this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()))->toBe($small);

    // A stored file the decoder cannot read (corrupted after upload) falls back to the raw bytes.
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('b.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $custom = $this->asset->fresh()->meta_data['custom_thumbnail'];
    $disk->put($custom['path'], 'not an image at all');
    expect(customThumbnailBody($this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()))->toBe('not an image at all');
    $disk->assertMissing(CustomThumbnailService::derivativePath($custom));
});

it('removes the derivative with the custom thumbnail when the asset is deleted', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk();
    $custom = $this->asset->fresh()->meta_data['custom_thumbnail'];
    $derivative = CustomThumbnailService::derivativePath($custom);
    $disk->assertExists($derivative);
    app(AssetDeletionService::class)->delete($this->asset);
    $disk->assertMissing($derivative);
    $disk->assertMissing($custom['path']);
});

function failCustomThumbnailWrites(): void
{
    $name = Directory::getAssetDisk();
    $mock = Mockery::mock(Storage::disk($name))->makePartial();
    $mock->shouldReceive('put')->andReturn(false);
    Storage::set($name, $mock);
}

function customThumbnailFiles(int $assetId): array
{
    return collect(Storage::disk(Directory::getAssetDisk())->allFiles('custom-thumbnails/'.$assetId))->sort()->values()->all();
}

it('names the representation in the URL: JPEG and PNG carry the w300 recipe, a GIF the bare revision', function () {
    $png = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $revision = $this->asset->fresh()->meta_data['custom_thumbnail']['revision'];
    expect($png['thumbnail_url'])->toContain('v='.$revision.'.w300');

    $gif = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('m.gif', animatedThumbnailGif())])->assertCreated()->json();
    $revision = $this->asset->fresh()->meta_data['custom_thumbnail']['revision'];
    expect($gif['thumbnail_url'])->toContain('v='.$revision)->not->toContain('.w300');
});

it('calls the derivative and a small raw source immutable only under their own version', function () {
    $big = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    expect($this->get($big['thumbnail_url'], ['Accept' => 'image/*'])->headers->get('Cache-Control'))->toContain('immutable');

    $revision = $this->asset->fresh()->meta_data['custom_thumbnail']['revision'];
    $path = explode('?', $big['thumbnail_url'])[0].'?'.explode('&v=', explode('?', $big['thumbnail_url'])[1])[0];
    foreach ([$path, $path.'&v=other'] as $url) {
        $response = $this->get($url, ['Accept' => 'image/*'])->assertOk();
        expect($response->headers->get('Cache-Control'))->not->toContain('immutable');
    }

    // The bare revision is the raw bytes' own version: immutable, and not the WebP.
    $bare = $this->get($path.'&v='.$revision, ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($bare->headers->get('Cache-Control'))->toContain('immutable');

    $small = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('s.png', customThumbnailPng(120, 80))])->assertCreated()->json();
    $response = $this->get($small['thumbnail_url'], ['Accept' => 'image/*'])->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('serves raw bytes and leaves no final or temp file when the derivative cannot be written', function () {
    $raw = customThumbnailPng(800, 800);
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', $raw)])->assertCreated()->json();
    $before = customThumbnailFiles($this->asset->id);
    failCustomThumbnailWrites();

    $response = $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(customThumbnailBody($response))->toBe($raw)
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable')
        ->and(customThumbnailFiles($this->asset->id))->toBe($before);
});

it('treats a zero-byte derivative as a miss and regenerates it', function () {
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', customThumbnailPng(800, 800))])->assertCreated()->json();
    $derivative = CustomThumbnailService::derivativePath($this->asset->fresh()->meta_data['custom_thumbnail']);
    Storage::disk(Directory::getAssetDisk())->put($derivative, '');

    $body = customThumbnailBody($this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/webp'));
    expect(max(...array_slice(getimagesizefromstring($body), 0, 2)))->toBeLessThanOrEqual(300)
        ->and(Storage::disk(Directory::getAssetDisk())->size($derivative))->toBeGreaterThan(0)
        ->and(customThumbnailFiles($this->asset->id))->toHaveCount(2);
});

it('never answers 304 across the raw fallback and the WebP representation', function () {
    $raw = customThumbnailPng(800, 800);
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', $raw)])->assertCreated()->json();
    $healthy = Storage::disk(Directory::getAssetDisk());
    failCustomThumbnailWrites();
    $fallback = $this->get($result['thumbnail_url'], ['Accept' => 'image/*'])->assertOk();
    $rawEtag = $fallback->headers->get('ETag');

    // The disk recovers: a client holding the fallback's validator gets the WebP, not a 304.
    Storage::set(Directory::getAssetDisk(), $healthy);
    $webp = $this->get($result['thumbnail_url'], ['Accept' => 'image/*', 'If-None-Match' => $rawEtag])->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect($webp->headers->get('ETag'))->not->toBe($rawEtag)->and($webp->headers->get('Cache-Control'))->toContain('immutable');
    $this->get($result['thumbnail_url'], ['Accept' => 'image/*', 'If-None-Match' => $webp->headers->get('ETag')])->assertStatus(304);

    // And back: a failure after the WebP validator was cached serves the raw bytes, not a 304.
    $healthy->delete(CustomThumbnailService::derivativePath($this->asset->fresh()->meta_data['custom_thumbnail']));
    failCustomThumbnailWrites();
    $again = $this->get($result['thumbnail_url'], ['Accept' => 'image/*', 'If-None-Match' => $webp->headers->get('ETag')])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($again->headers->get('ETag'))->toBe($rawEtag);
});

it('serves the editor URL (bare revision) the raw bytes, byte-identical and immutable, and the card URL a 300 px WebP', function () {
    $raw = customThumbnailPng(800, 800);
    $card = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', $raw)])->assertCreated()->json('thumbnail_url');
    $asset = $this->asset->fresh();
    $revision = $asset->meta_data['custom_thumbnail']['revision'];

    $edit = ImageEditing::thumbnailUrl($asset);
    expect($edit)->toContain('v='.$revision)->not->toContain('.w300');

    $rawResponse = $this->get($edit, ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(customThumbnailBody($rawResponse))->toBe($raw)
        ->and($rawResponse->headers->get('Cache-Control'))->toContain('immutable');

    $webp = $this->get($card, ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect(max(...array_slice(getimagesizefromstring(customThumbnailBody($webp)), 0, 2)))->toBeLessThanOrEqual(300)
        ->and($webp->headers->get('ETag'))->not->toBe($rawResponse->headers->get('ETag'));

    // Neither validator is honoured by the other representation.
    $this->get($edit, ['Accept' => 'image/*', 'If-None-Match' => $webp->headers->get('ETag')])->assertOk();
    $this->get($card, ['Accept' => 'image/*', 'If-None-Match' => $rawResponse->headers->get('ETag')])->assertOk();
    $this->get($edit, ['Accept' => 'image/*', 'If-None-Match' => $rawResponse->headers->get('ETag')])->assertStatus(304);
});

it('never answers an If-Modified-Since-only request with 304 on the raw stand-in', function () {
    $raw = customThumbnailPng(800, 800);
    $result = $this->postJson($this->url, ['thumbnail' => UploadedFile::fake()->createWithContent('a.png', $raw)])->assertCreated()->json();
    failCustomThumbnailWrites();

    // A date after every file's mtime: a Last-Modified check alone would call the stored WebP "the same".
    $response = $this->get($result['thumbnail_url'], ['Accept' => 'image/*', 'If-Modified-Since' => now()->addYear()->toRfc7231String()])
        ->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->headers->has('Last-Modified'))->toBeFalse()
        ->and(customThumbnailBody($response))->toBe($raw);
});
