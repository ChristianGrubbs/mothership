<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\StackFileThumbnail;

/*
 * A stack member's small picture is named by a version (perf pass LB-06): immutable only when the URL's `v` names the
 * bytes served, revalidating for a wrong or absent one, so a member whose bytes change gets a new URL.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Versioned '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'menu.png', 'path' => 'assets/Versioned/menu.png']));
    $this->asset->directories()->attach($this->folder->id);
});

function stackMemberPng(int $shade): string
{
    $image = imagecreatetruecolor(640, 480);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, 120, 200));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function stackMemberAttach(object $test, string $name, string $bytes): array
{
    return $test->postJson('/'.config('app.admin_url').'/dam/assets/'.$test->asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent($name, $bytes), 'role' => 'sidecar', 'label' => $name,
    ])->assertCreated()->json();
}

function stackMemberUrl(object $test, int $fileId): ?string
{
    return collect($test->getJson(route('admin.dam.assets.files.index', $test->asset->id))->assertOk()->json('files'))->keyBy('id')[$fileId]['thumbnail_url'];
}

it('names the member picture with the sha of its bytes, and serves it immutably only under that version', function () {
    $file = stackMemberAttach($this, 'a.png', stackMemberPng(20));
    $sha = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->sha256;
    $url = stackMemberUrl($this, $file['id']);

    expect($url)->toContain('thumb=1')->toContain('v='.$sha);

    $good = $this->get($url)->assertOk();
    expect($good->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=31536000')->toContain('immutable')->not->toContain('public');

    $base = route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]);
    foreach ([$base, $base.'&v=not-these-bytes', $base.'&v='.str_repeat('0', 64)] as $other) {
        $response = $this->get($other)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable')->not->toContain('max-age=31536000');
    }
});

it('answers a conditional request for the versioned member picture 304 without a body', function () {
    $file = stackMemberAttach($this, 'b.png', stackMemberPng(40));
    $url = stackMemberUrl($this, $file['id']);
    $etag = $this->get($url)->assertOk()->headers->get('ETag');

    $again = $this->get($url, ['If-None-Match' => $etag]);

    expect($again->getStatusCode())->toBe(304)->and($again->getContent())->toBeIn(['', false])
        ->and($again->headers->get('Cache-Control'))->toContain('immutable');
});

it('gives a member with different bytes a different URL', function () {
    $one = stackMemberAttach($this, 'c.png', stackMemberPng(60));
    $two = stackMemberAttach($this, 'd.png', stackMemberPng(90));

    expect(stackMemberUrl($this, $one['id']))->not->toBe(stackMemberUrl($this, $two['id']));
});

it('never calls a poster or original immutable under a wrong version, and the original stays revalidating', function () {
    $file = stackMemberAttach($this, 'e.png', stackMemberPng(70));
    $sha = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->sha256;
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($sha), 'poster');

    $original = $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'v' => $sha]))->assertOk();

    expect($original->headers->get('Cache-Control'))->not->toContain('immutable');
});

it('does not version cover art or a model capture, which are rewritten in place', function () {
    expect(StackFileThumbnail::version(['kind' => 'art', 'path' => 'covers/12.jpg', 'disk' => 'local', 'mime' => null], str_repeat('a', 64)))->toBeNull()
        ->and(StackFileThumbnail::version(['kind' => 'model', 'path' => DerivativeImages::modelCapture(str_repeat('a', 64), 5), 'disk' => 'local', 'mime' => null], str_repeat('a', 64)))->toBeNull()
        ->and(StackFileThumbnail::version(['kind' => 'poster', 'path' => DerivativeImages::poster(str_repeat('a', 64)), 'disk' => 'local', 'mime' => null], str_repeat('a', 64)))->not->toBeNull()
        ->and(StackFileThumbnail::version(['kind' => 'raster', 'path' => null, 'disk' => null, 'mime' => null], 'short'))->toBeNull();
});

/** A stacked video: its picture is the poster of its bytes, found only once the poster exists. Returns [file, sha]. */
function stackMemberVideo(object $test, string $name): array
{
    $file = stackMemberAttach($test, $name, 'bytes of '.$name);
    $object = FileObject::query()->whereKey(AssetFile::query()->whereKey($file['id'])->value('file_object_id'))->firstOrFail();
    $object->update(['mime_type' => 'video/mp4']);

    return [$file, $object->sha256];
}

function stackMemberCache(string $header): array
{
    return ['immutable' => str_contains($header, 'immutable'), 'year' => str_contains($header, 'max-age=31536000')];
}

it('never calls a poster immutable under a wrong version', function () {
    [$file, $sha] = stackMemberVideo($this, 'f.mp4');
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($sha), 'poster-bytes');
    $base = route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]);

    foreach ([$base, $base.'&v=wrong', $base.'&v='.$sha] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable');
    }
});

it('serves a path-hashed poster, a custom picture and a generated derivative immutably under their matching version', function (string $kind) {
    $file = $kind === 'poster' ? stackMemberVideo($this, 'g.mp4')[0] : stackMemberAttach($this, 'g.png', stackMemberPng(72));
    $sha = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->sha256;

    if ($kind === 'poster') {
        Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($sha), 'poster-bytes');
    } elseif ($kind === 'custom') {
        $custom = ['path' => 'custom-thumbnails/'.$this->asset->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
        Storage::disk(Directory::getAssetDisk())->put($custom['path'], 'custom-bytes');
        $this->asset->update(['meta_data' => ['custom_thumbnail' => $custom]]);
        AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => AssetFile::ROLE_ORIGINAL]);
        $file = ['id' => AssetFile::query()->where('dam_asset_id', $this->asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->latest('id')->value('id')];
    }

    $url = stackMemberUrl($this, $file['id']);
    expect($url)->toContain('v=');

    $good = $this->get($url)->assertOk();
    expect(stackMemberCache($good->headers->get('Cache-Control')))->toBe(['immutable' => true, 'year' => true]);

    $again = $this->get($url, ['If-None-Match' => $good->headers->get('ETag')]);
    expect($again->getStatusCode())->toBe(304)->and($again->headers->get('Cache-Control'))->toContain('immutable');
})->with(['generated derivative' => ['derivative'], 'poster' => ['poster'], 'custom picture' => ['custom']]);

it('keeps ?preview=1 and ?download=1 non-immutable even when a v is present', function (string $flag) {
    $file = stackMemberAttach($this, 'h.png', stackMemberPng(73));
    $sha = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->sha256;
    $url = route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], $flag => 1, 'v' => $sha]);

    $response = $this->get($url);

    expect($response->getStatusCode())->toBeIn([200, 404]);
    expect((string) $response->headers->get('Cache-Control'))->not->toContain('immutable')->not->toContain('max-age=31536000');
})->with(['preview', 'download']);

it('keeps a mutable cover revalidating after its picture is replaced', function () {
    $this->asset->update(['file_type' => 'audio', 'meta_data' => ['cover_art_path' => 'covers/'.$this->asset->id.'.jpg']]);
    Storage::disk(Directory::getAssetDisk())->put('covers/'.$this->asset->id.'.jpg', 'cover-one');
    $member = stackMemberAttach($this, 'cover.png', stackMemberPng(78));
    $cover = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => AssetFile::ROLE_ORIGINAL, 'file_object_id' => AssetFile::query()->findOrFail($member['id'])->file_object_id]);
    $url = stackMemberUrl($this, $cover->id);
    expect($url)->not->toBeNull()->not->toContain('v=');

    $first = $this->get($url)->assertOk();
    expect($first->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable');
    $etag = $first->headers->get('ETag');

    Storage::disk(Directory::getAssetDisk())->put('covers/'.$this->asset->id.'.jpg', 'cover-two-replaced');
    $second = $this->get($url, ['If-None-Match' => $etag])->assertOk();

    expect($second->headers->get('ETag'))->not->toBe($etag)
        ->and($second->baseResponse->getFile()->getContent())->toBe('cover-two-replaced');
});

it('gives no 304 once the derivative and the original are gone, exactly like an unconditional request', function (string $damage) {
    $file = stackMemberAttach($this, 'i.png', stackMemberPng(74));
    $row = AssetFile::query()->with('fileObject')->findOrFail($file['id']);
    $object = $row->fileObject;
    $url = stackMemberUrl($this, $file['id']);
    $etag = $this->get($url)->assertOk()->headers->get('ETag');
    $disk = Storage::disk(Directory::getAssetDisk());

    $disk->delete(DerivativeImages::thumbnail($object->sha256, 'png'));
    $damage === 'missing' ? $disk->delete($object->path) : $disk->put($object->path, substr($disk->get($object->path), 0, 16));

    $unconditional = $this->get($url);
    $conditional = $this->get($url, ['If-None-Match' => $etag]);

    expect($unconditional->getStatusCode())->toBe(404)
        ->and($conditional->getStatusCode())->toBe(404)
        ->and((string) $conditional->headers->get('Cache-Control'))->not->toContain('immutable');
})->with(['missing', 'truncated']);

it('gives no 304 for a poster that disappeared, and forgets that it existed', function () {
    [$file, $sha] = stackMemberVideo($this, 'j.mp4');
    $poster = DerivativeImages::poster($sha);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($poster, 'poster-bytes');
    $url = stackMemberUrl($this, $file['id']);
    $etag = $this->get($url)->assertOk()->headers->get('ETag');
    $key = 'dam-stack-thumb-exists:'.Directory::getAssetDisk().':'.md5($poster);
    expect(Cache::get($key))->toBeTrue();

    $disk->delete($poster);
    $conditional = $this->get($url, ['If-None-Match' => $etag]);

    expect($conditional->getStatusCode())->not->toBe(304)
        ->and((string) $conditional->headers->get('Cache-Control'))->not->toContain('immutable')
        ->and(Cache::has($key))->toBeFalse();
});

it('answers 404, never 200 or an immutable 304, when only the original is gone and the derivative is kept', function (string $kind, string $damage) {
    $disk = Storage::disk(Directory::getAssetDisk());

    if ($kind === 'raster') {
        $file = stackMemberAttach($this, 'k.png', stackMemberPng(76));
        $sha = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->sha256;
    } else {
        [$file, $sha] = stackMemberVideo($this, $kind === 'dng' ? 'k.dng' : 'k.mp4');

        if ($kind === 'dng') {
            FileObject::query()->where('sha256', $sha)->update(['mime_type' => 'image/x-adobe-dng']);
            $disk->put(DerivativeImages::dngThumbnail($sha), 'dng-thumb-bytes');
        } else {
            $disk->put(DerivativeImages::poster($sha), 'poster-bytes');
        }
    }

    $url = stackMemberUrl($this, $file['id']);
    $warm = $this->get($url)->assertOk();
    $etag = $warm->headers->get('ETag');
    $object = AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject;

    // The derivative or poster stays; only the original goes.
    $damage === 'missing' ? $disk->delete($object->path) : $disk->put($object->path, 'x');

    foreach ([['If-None-Match' => $etag], []] as $headers) {
        $response = $this->get($url, $headers);
        expect($response->getStatusCode())->toBe(404)
            ->and((string) $response->headers->get('Cache-Control'))->not->toContain('immutable');
    }
})->with(['raster', 'poster', 'dng'])->with(['missing', 'truncated']);

it('keeps serving a custom picture, and answers it 304, when the original is gone', function () {
    $file = stackMemberAttach($this, 'l.png', stackMemberPng(77));
    $custom = ['path' => 'custom-thumbnails/'.$this->asset->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($custom['path'], 'custom-bytes');
    $this->asset->update(['meta_data' => ['custom_thumbnail' => $custom]]);
    $cover = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $url = stackMemberUrl($this, $cover->id);
    $etag = $this->get($url)->assertOk()->headers->get('ETag');
    $disk->delete(AssetFile::query()->with('fileObject')->findOrFail($file['id'])->fileObject->path);

    expect($this->get($url, ['If-None-Match' => $etag])->getStatusCode())->toBe(304);
});
