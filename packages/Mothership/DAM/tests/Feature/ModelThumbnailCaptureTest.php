<?php

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

/** The fixed byte content every model fixture in this file is made of. */
function modelThumbBytes(): string
{
    return str_repeat('M', 64);
}

function modelThumbAsset(string $extension = 'glb', array $meta = [], bool $withOriginal = true, ?string $path = null): Asset
{
    $disk = Directory::getAssetDisk();
    $path ??= 'assets/'.Str::uuid().'.'.$extension;
    $bytes = modelThumbBytes();

    // CompoundAssetObserver::created() (ADR 0002) already snapshots whatever
    // exists at $asset->path into the asset's own "original" AssetFile/
    // FileObject the moment the row is inserted -- the same path
    // ModelPrimaryViewTest::primaryModel() drives, by putting the file on
    // disk before the Asset row exists. Manually creating a second original
    // here (an earlier version of this helper did, unconditionally) doubled
    // up on every test that used the default $withOriginal=true, tripping
    // dam_asset_files_one_original_per_asset. FileObject.sha256 is globally
    // unique but content-addressed (FileObject::firstOrCreate by sha256), so
    // every fixture in this file safely sharing identical bytes just dedupes
    // to one FileObject row with one AssetFile(original) per asset -- never
    // a collision.
    if ($withOriginal) {
        Storage::disk($disk)->put($path, $bytes);
    }

    $asset = Asset::factory()->create([
        'path'      => $path,
        'file_name' => 'model.'.$extension,
        'extension' => $extension,
        'file_type' => 'document',
        'mime_type' => match ($extension) {
            'glb'   => 'model/gltf-binary',
            'stl'   => 'model/stl',
            default => 'application/octet-stream',
        },
        'file_size' => 64,
        'meta_data' => $meta,
    ]);

    if (! $withOriginal) {
        Storage::disk($disk)->put($path, $bytes);
    }

    return $asset;
}

function modelThumbChecksum(Asset $asset): string
{
    return hash('sha256', modelThumbBytes());
}

function modelThumbUrl(Asset $asset): string
{
    return '/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/model-thumbnail';
}

function modelThumbMissingUrl(array $query = []): string
{
    return '/'.config('app.admin_url').'/dam/model-thumbnails/missing'
        .($query === [] ? '' : '?'.http_build_query($query));
}

function modelThumbCapture(int $width = 512, int $height = 512): UploadedFile
{
    return UploadedFile::fake()->image('capture.png', $width, $height);
}

/** A genuine PNG above the 2 MB ceiling but inside the 1024x1024 ceiling. */
function modelThumbHugeCapture(): UploadedFile
{
    $image = imagecreatetruecolor(1024, 1024);

    for ($y = 0; $y < 1024; $y++) {
        for ($x = 0; $x < 1024; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }

    ob_start();
    imagepng($image, null, 1);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent('huge.png', $bytes);
}

function modelThumbCustomMeta(): array
{
    return ['custom_thumbnail' => [
        'path'      => 'custom-thumbnails/1/'.Str::uuid().'.png',
        'mime_type' => 'image/png',
        'revision'  => (string) Str::uuid(),
    ]];
}

it('stores a re-encoded JPEG thumbnail, skips an existing one and replaces on request', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset(meta: ['stack_cover' => ['path' => 'borrowed-picture.jpg']]);
    $checksum = modelThumbChecksum($asset);

    $stored = $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(1024, 768), 'checksum' => $checksum,
    ])->assertOk()->assertJsonPath('stored', true)->assertJsonPath('skipped', null)->json();

    $expectedPath = 'thumbnails/models/'.$asset->id.'-'.substr($checksum, 0, 12).'.jpg';
    expect($asset->fresh()->meta_data['thumbnail_path'])->toBe($expectedPath);
    expect($asset->fresh()->meta_data['stack_cover'] ?? null)->toBeNull();
    expect($stored['thumbnail_path'])->toBe($expectedPath);
    expect($stored['thumbnail_url'])->toContain('v=');
    Storage::disk($disk)->assertExists($expectedPath);

    $info = getimagesizefromstring(Storage::disk($disk)->get($expectedPath));
    expect($info[2])->toBe(IMAGETYPE_JPEG);
    expect([$info[0], $info[1]])->toBe([512, 384]);

    $first = Storage::disk($disk)->get($expectedPath);
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $checksum])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'exists');
    expect(Storage::disk($disk)->get($expectedPath))->toBe($first);

    $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(200, 200), 'checksum' => $checksum, 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', true);
    expect(Storage::disk($disk)->get($expectedPath))->not->toBe($first);
    expect($asset->fresh()->meta_data['thumbnail_path'])->toBe($expectedPath);
});

it('rejects a capture bound to a checksum that is not the current original', function () {
    $asset = modelThumbAsset();

    $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(), 'checksum' => str_repeat('a', 64),
    ])->assertStatus(409);

    expect($asset->fresh()->meta_data ?? [])->not->toHaveKey('thumbnail_path');
    Storage::disk(Directory::getAssetDisk())->assertMissing(
        'thumbnails/models/'.$asset->id.'-'.substr(str_repeat('a', 64), 0, 12).'.jpg'
    );
});

it('rejects non-3D assets, oversize captures, non-images and a missing checksum', function () {
    $checksum = fn (Asset $asset) => modelThumbChecksum($asset);

    $pdf = modelThumbAsset('pdf');
    $this->postJson(modelThumbUrl($pdf), ['image' => modelThumbCapture(), 'checksum' => $checksum($pdf)])
        ->assertUnprocessable();

    $asset = modelThumbAsset('3mf');
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbHugeCapture(), 'checksum' => $checksum($asset)])
        ->assertUnprocessable();
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(2048, 2048), 'checksum' => $checksum($asset)])
        ->assertUnprocessable();
    $this->postJson(modelThumbUrl($asset), [
        'image' => UploadedFile::fake()->createWithContent('notes.png', 'not an image'), 'checksum' => $checksum($asset),
    ])->assertUnprocessable();
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture()])->assertUnprocessable();
    $this->postJson(modelThumbUrl($asset), ['checksum' => $checksum($asset)])->assertUnprocessable();

    expect($asset->fresh()->meta_data ?? [])->not->toHaveKey('thumbnail_path');
});

it('never overwrites a user-set custom thumbnail', function () {
    $asset = modelThumbAsset('glb', modelThumbCustomMeta());

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'custom');

    $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset), 'replace' => 1,
    ])->assertOk()->assertJsonPath('skipped', 'custom');

    expect($asset->fresh()->meta_data)->not->toHaveKey('thumbnail_path');
});

it('requires edit permission and access to the asset folder', function (bool $edit, bool $access) {
    $asset = modelThumbAsset();
    $folder = Directory::factory()->create();
    $asset->directories()->attach($folder->id);

    $permissions = ['dam', 'dam.asset', 'dam.asset.view'];
    if ($edit) {
        $permissions[] = 'dam.asset.edit';
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
    }
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertStatus($access ? 403 : 404);

    expect($asset->fresh()->meta_data ?? [])->not->toHaveKey('thumbnail_path');
})->with([[false, true], [true, false]]);

it('reports the binding checksum and whether a thumbnail already exists', function () {
    $asset = modelThumbAsset();
    $this->getJson(modelThumbUrl($asset))->assertOk()
        ->assertJsonPath('checksum', modelThumbChecksum($asset))
        ->assertJsonPath('has_thumbnail', false)
        ->assertJsonPath('can_edit', true);

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertOk();

    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', true);

    $this->getJson(modelThumbUrl(modelThumbAsset('png')))->assertUnprocessable();
});

it('captures an STL like any other model format', function () {
    $asset = modelThumbAsset('stl');

    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', false);
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertOk()->assertJsonPath('stored', true);

    expect($asset->fresh()->meta_data['thumbnail_path'])->toBe('thumbnails/models/'.$asset->id.'-'.substr(modelThumbChecksum($asset), 0, 12).'.jpg');
    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', true);
});

it('lists only accessible 3D assets that still need a thumbnail and pages with a cursor', function () {
    $folder = Directory::factory()->create();
    $inFolder = function (Asset $asset) use ($folder) {
        $asset->directories()->attach($folder->id);

        return $asset;
    };

    $glb = $inFolder(modelThumbAsset('glb'));
    $threeMf = $inFolder(modelThumbAsset('3mf'));
    $stl = $inFolder(modelThumbAsset('stl'));
    $inFolder(modelThumbAsset('glb', ['thumbnail_path' => 'thumbnails/models/9-abc.jpg']));
    $inFolder(modelThumbAsset('glb', modelThumbCustomMeta()));
    $inFolder(modelThumbAsset('png'));

    $listed = $this->getJson(modelThumbMissingUrl(['directory_id' => $folder->id]))->assertOk()->json();

    expect(collect($listed['items'])->pluck('id')->all())->toEqualCanonicalizing([$glb->id, $threeMf->id, $stl->id]);
    expect($listed['next_cursor'])->toBeNull();
    expect(array_keys($listed['items'][0]))->toEqualCanonicalizing(['id', 'extension', 'checksum', 'size', 'url']);
    expect(collect($listed['items'])->firstWhere('id', $glb->id)['checksum'])->toBe(modelThumbChecksum($glb));

    $page = $this->getJson(modelThumbMissingUrl(['directory_id' => $folder->id, 'limit' => 1]))->assertOk()->json();
    expect($page['items'])->toHaveCount(1);
    expect($page['next_cursor'])->not->toBeNull();

    $next = $this->getJson(modelThumbMissingUrl([
        'directory_id' => $folder->id, 'limit' => 1, 'cursor' => $page['next_cursor'],
    ]))->assertOk()->json();
    expect($next['items'])->toHaveCount(1);
    expect($next['items'][0]['id'])->not->toBe($page['items'][0]['id']);
    // A full page (count === limit) always hands back a cursor -- the endpoint
    // has no lookahead, so it cannot yet know this was the last matching row.
    // The client's own paging loop (tab.blade.php fetchMissingModels) already
    // relies on exactly this: it keeps requesting until a page comes back
    // short, so the real "no more pages" signal is the following, genuinely
    // empty page, not this one.
    expect($next['next_cursor'])->not->toBeNull();

    $third = $this->getJson(modelThumbMissingUrl([
        'directory_id' => $folder->id, 'limit' => 1, 'cursor' => $next['next_cursor'],
    ]))->assertOk()->json();
    expect($third['items'])->toHaveCount(1);
    expect($third['next_cursor'])->not->toBeNull();

    $exhausted = $this->getJson(modelThumbMissingUrl([
        'directory_id' => $folder->id, 'limit' => 1, 'cursor' => $third['next_cursor'],
    ]))->assertOk()->json();
    expect($exhausted['items'])->toBe([]);
    expect($exhausted['next_cursor'])->toBeNull();

    $byIds = $this->getJson(modelThumbMissingUrl(['ids' => [$glb->id]]))->assertOk()->json();
    expect(collect($byIds['items'])->pluck('id')->all())->toBe([$glb->id]);
});

it('hides assets in folders the signed-in role cannot reach', function () {
    $granted = Directory::factory()->create();
    $denied = Directory::factory()->create();
    $visible = modelThumbAsset('glb');
    $visible->directories()->attach($granted->id);
    $hidden = modelThumbAsset('glb');
    $hidden->directories()->attach($denied->id);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit'],
    ]);
    DB::table('dam_directory_role')->insert(['directory_id' => $granted->id, 'role_id' => $role->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $listed = $this->getJson(modelThumbMissingUrl())->assertOk()->json();

    expect(collect($listed['items'])->pluck('id')->all())->toBe([$visible->id]);
    // A folder the role cannot see answers like one that does not exist.
    $this->getJson(modelThumbMissingUrl(['directory_id' => $denied->id]))->assertUnprocessable()->assertJsonValidationErrors('directory_id');
});

it('serves the generated thumbnail and advertises it on the explorer card payload', function () {
    $folder = Directory::factory()->create();
    $asset = modelThumbAsset();
    $asset->directories()->attach($folder->id);
    $checksum = modelThumbChecksum($asset);

    $browse = route('admin.dam.explorer.index', ['directory_id' => $folder->id]);
    $this->getJson($browse)->assertOk()
        ->assertJsonPath('assets.0.has_thumbnail', false)
        ->assertJsonPath('assets.0.checksum', $checksum);

    $url = $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $checksum])
        ->assertOk()->json('thumbnail_url');

    $this->getJson($browse)->assertOk()->assertJsonPath('assets.0.has_thumbnail', true);

    $response = $this->get($url, ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    // A capture overwrites its file before it records the new revision, so its `v` does not prove the bytes:
    // this URL stays revalidating even though it carries a revision.
    expect($url)->toContain('v=')
        ->and($response->headers->get('Cache-Control'))->not->toContain('immutable');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))
        ->toBe(Storage::disk(Directory::getAssetDisk())->get($asset->fresh()->meta_data['thumbnail_path']));

    // A forced re-capture overwrites the same file, so the grid card's `v` has
    // to name the new bytes or the browser keeps the old image for a day.
    $cardRevision = function (string $browse): ?string {
        parse_str((string) parse_url($this->getJson($browse)->assertOk()->json('assets.0.path'), PHP_URL_QUERY), $query);

        return $query['v'] ?? null;
    };
    $first = $cardRevision($browse);
    $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(200, 200), 'checksum' => $checksum, 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', true);
    expect($cardRevision($browse))->not->toBe($first)->toBe($asset->fresh()->meta_data['thumbnail_revision']);

    // A poster job rewrites only thumbnail_path, so a revision a capture left
    // behind must not outrank the poster's own blob sha.
    $sha = hash('sha256', 'poster-source');
    $posterFolder = Directory::factory()->create();
    modelThumbAsset('mp4', ['thumbnail_path' => DerivativeImages::poster($sha), 'thumbnail_revision' => 'fedcba654321'])
        ->directories()->attach($posterFolder->id);
    expect($cardRevision(route('admin.dam.explorer.index', ['directory_id' => $posterFolder->id])))->toBe($sha);
});

it('gives the details-page 3D card a revisioned thumbnail URL on first load, and none before a capture', function () {
    // `+` and `%` survive only the server's urlencode()-inside-route() form:
    // FileController::thumbnail() urldecodes a path the request already decoded.
    $asset = modelThumbAsset(path: 'assets/'.Str::uuid().' 50%+draft.glb');
    $edit = route('admin.dam.assets.edit', $asset->id);
    $show = route('admin.dam.assets.show', $asset->id);

    // Uncaptured: the thumbnail route would answer 200 with the placeholder
    // (so the card's error hide never fires) and cache it for a day.
    expect(ModelThumbnailService::cardUrl($asset))->toBeNull();
    $this->get($edit)->assertOk()->assertSee('"modelThumbnailUrl":null', false);
    $this->getJson($show)->assertOk()->assertJsonPath('modelThumbnailUrl', null);

    $disk = Storage::disk(Directory::getAssetDisk());
    // What a reload asks for: the card URL, named by the stored bytes, and those bytes behind it.
    $reload = function () use ($asset, $edit, $show, $disk): string {
        $asset->refresh();
        $url = ModelThumbnailService::cardUrl($asset);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query['v'])->toBe(substr(hash('sha256', $disk->get($asset->meta_data['thumbnail_path'])), 0, 12));

        // @json escapes slashes and hex-encodes `&`; match the form the page carries.
        $baked = json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->get($edit)->assertOk()->assertSee('"modelThumbnailUrl":'.$baked, false);
        $this->getJson($show)->assertOk()->assertJsonPath('modelThumbnailUrl', $url);

        $response = $this->get($url, ['Accept' => 'image/*'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))
            ->toBe($disk->get($asset->meta_data['thumbnail_path']));

        return $url;
    };

    $captured = $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertOk()->assertJsonPath('stored', true)->json('thumbnail_url');
    $first = $reload();
    expect($first)->toBe($captured, 'the in-session swap and the next load name the same revision');

    // A forced re-capture (after a primary-view save) overwrites the same file,
    // so only a content revision keeps the next load off the cached old image.
    $this->postJson(modelThumbUrl($asset), [
        'image' => modelThumbCapture(200, 200), 'checksum' => modelThumbChecksum($asset), 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', true);
    expect($reload())->not->toBe($first);

    // A custom thumbnail outranks an earlier capture; a capture stored before
    // thumbnail_revision existed is still named by its file, never a bare ?path=.
    $custom = modelThumbAsset('glb', modelThumbCustomMeta() + ['thumbnail_path' => 'thumbnails/models/7-abcdef123456.jpg', 'thumbnail_revision' => 'fedcba654321']);
    expect(ModelThumbnailService::cardUrl($custom))->toContain('v='.$custom->meta_data['custom_thumbnail']['revision']);
    expect(ModelThumbnailService::cardUrl(modelThumbAsset('glb', ['thumbnail_path' => 'thumbnails/models/7-abcdef123456.jpg'])))
        ->toContain('v=7-abcdef123456');
});

it('keeps the capture the browser posted before the upload job ran, instead of replacing meta_data wholesale (F-06)', function () {
    $asset = modelThumbAsset();
    $checksum = modelThumbChecksum($asset);

    // The worker is stopped: the browser's capture lands first.
    $stored = $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $checksum])->assertOk()->json();
    expect($stored['stored'])->toBeTrue();

    // Then the worker runs, and the extraction describes the file as before.
    $extraction = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $extraction->shouldReceive('extractMetadata')->andReturn(['Title' => 'Part', 'Make' => 'Printer']);
    (new ProcessAssetUpload($asset->id))->handle($extraction);

    $meta = $asset->fresh()->meta_data;
    expect($meta)->toMatchArray(['Title' => 'Part', 'thumbnail_path' => $stored['thumbnail_path']])
        ->and(Storage::disk(Directory::getAssetDisk())->exists($meta['thumbnail_path']))->toBeTrue()
        ->and(ModelThumbnailService::hasThumbnail($asset->fresh()))->toBeTrue();
});

// --- A custom or stale card thumbnail never stands in for the picture the AI describes ---

it('keeps a custom thumbnail as the card and still stores a capture of the current bytes the AI can use', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset('glb', modelThumbCustomMeta());
    $sha = modelThumbChecksum($asset);
    $custom = $asset->meta_data['custom_thumbnail'];

    // The card has a picture, but the AI has none: the viewer must still capture.
    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', false);
    expect(AiEligibility::source($asset, $sha))->toMatchArray(['state' => AiEligibility::AWAITING_PREVIEW, 'reason' => 'model_capture']);

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(1024, 768), 'checksum' => $sha])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'custom')->assertJsonPath('captured', true);

    $own = DerivativeImages::modelCapture($sha, $asset->id);
    Storage::disk($disk)->assertExists($own);
    expect(getimagesizefromstring(Storage::disk($disk)->get($own))[2])->toBe(IMAGETYPE_JPEG);
    // The chosen picture is untouched: no generated card pointer, same revision.
    expect($asset->fresh()->meta_data)->toBe(['custom_thumbnail' => $custom]);
    expect(AiEligibility::source($asset->fresh(), $sha))->toMatchArray(['state' => AiEligibility::ELIGIBLE, 'reason' => 'preview', 'path' => $own]);
    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', true);

    // An unchanged capture is idempotent; an explicit replace re-makes the capture and still leaves the custom card alone.
    $first = Storage::disk($disk)->get($own);
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(200, 200), 'checksum' => $sha])
        ->assertOk()->assertJsonPath('captured', false);
    expect(Storage::disk($disk)->get($own))->toBe($first);
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(200, 200), 'checksum' => $sha, 'replace' => 1])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('captured', true);
    expect(Storage::disk($disk)->get($own))->not->toBe($first);
    expect($asset->fresh()->meta_data)->toBe(['custom_thumbnail' => $custom]);
});

it('never stores a capture beside a custom thumbnail for bytes that are not the current original', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset('glb', modelThumbCustomMeta());
    $stale = hash('sha256', 'an older revision');

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $stale])->assertStatus(409);

    Storage::disk($disk)->assertMissing(DerivativeImages::modelCapture($stale, $asset->id));
    Storage::disk($disk)->assertMissing(DerivativeImages::modelCapture(modelThumbChecksum($asset), $asset->id));
});

it('replaces a stale card thumbnail with a capture bound to the current bytes', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset('glb', ['thumbnail_path' => 'thumbnails/models/1-aaaaaaaaaaaa.jpg', 'thumbnail_revision' => 'aaaaaaaaaaaa']);
    Storage::disk($disk)->put('thumbnails/models/1-aaaaaaaaaaaa.jpg', 'a picture of an older revision');
    $sha = modelThumbChecksum($asset);
    $bound = 'thumbnails/models/'.$asset->id.'-'.substr($sha, 0, 12).'.jpg';

    // A card picture exists, but it is not a capture of these bytes: the viewer captures.
    $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', false);

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])
        ->assertOk()->assertJsonPath('stored', true)->assertJsonPath('captured', true);

    expect($asset->fresh()->meta_data['thumbnail_path'])->toBe($bound);
    expect(AiEligibility::source($asset->fresh(), $sha))->toMatchArray(['state' => AiEligibility::ELIGIBLE, 'path' => $bound]);
});

it('re-queues a run that found no image exactly once when the capture lands beside a custom thumbnail', function () {
    $asset = modelThumbAsset('glb', modelThumbCustomMeta());
    $sha = modelThumbChecksum($asset);
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'done', 'error' => AiEligibility::NO_IMAGE, 'content_sha256' => $sha, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])->assertOk()->assertJsonPath('captured', true);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->first())->status->toBe('pending')->attempts->toBe(0);

    // A second open of the viewer finds the capture and posts nothing new: nothing more is queued.
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])->assertOk()->assertJsonPath('captured', false);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
});

it('repairs a corrupt capture at the current generated path on a normal capture and re-queues indexing once', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset('glb');
    $sha = modelThumbChecksum($asset);
    $bound = 'thumbnails/models/'.$asset->id.'-'.substr($sha, 0, 12).'.jpg';
    $asset->forceFill(['meta_data' => ['thumbnail_path' => $bound, 'thumbnail_revision' => substr($sha, 0, 12)]])->saveQuietly();
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'done', 'error' => AiEligibility::NO_IMAGE, 'content_sha256' => $sha, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();

    foreach (['', 'a truncated picture'] as $remains) {
        Storage::disk($disk)->put($bound, $remains);

        // The card has a path, but the file is not a picture the AI can read: the viewer must capture, and be able to.
        $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', false);
        $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])
            ->assertOk()->assertJsonPath('stored', true)->assertJsonPath('skipped', null);

        expect(getimagesizefromstring(Storage::disk($disk)->get($bound))[2])->toBe(IMAGETYPE_JPEG);
        expect(AiEligibility::source($asset->fresh(), $sha))->toMatchArray(['state' => AiEligibility::ELIGIBLE, 'path' => $bound]);
        $this->getJson(modelThumbUrl($asset))->assertOk()->assertJsonPath('has_thumbnail', true);
    }

    // The first repair re-queued the no_image row; the second found a row already waiting and queued nothing more.
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
});

it('keeps a custom thumbnail chosen while a capture is being written and still gives the AI a current-byte capture', function () {
    $disk = Directory::getAssetDisk();
    $asset = modelThumbAsset('glb');
    $sha = modelThumbChecksum($asset);
    $bound = 'thumbnails/models/'.$asset->id.'-'.substr($sha, 0, 12).'.jpg';
    $custom = modelThumbCustomMeta()['custom_thumbnail'];
    Storage::disk($disk)->put($custom['path'], 'the person\'s chosen picture');
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'done', 'error' => AiEligibility::NO_IMAGE, 'content_sha256' => $sha, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();

    // The person's choice lands after the generated capture is written but before the metadata transaction reads the locked row.
    $chosen = false;
    Event::listen(TransactionBeginning::class, function () use (&$chosen, $disk, $bound, $asset, $custom): void {
        if ($chosen || ! Storage::disk($disk)->exists($bound)) {
            return;
        }
        $chosen = true;
        DB::table('dam_assets')->where('id', $asset->id)->update(['meta_data' => json_encode(['custom_thumbnail' => $custom])]);
    });

    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'custom')->assertJsonPath('captured', true);

    expect($chosen)->toBeTrue();
    // The chosen card is untouched: same meta, same bytes, no generated pointer written over it.
    expect($asset->fresh()->meta_data)->toBe(['custom_thumbnail' => $custom]);
    expect(Storage::disk($disk)->get($custom['path']))->toBe('the person\'s chosen picture');
    $own = DerivativeImages::modelCapture($sha, $asset->id);
    expect(AiEligibility::source($asset->fresh(), $sha))->toMatchArray(['state' => AiEligibility::ELIGIBLE, 'reason' => 'preview', 'path' => $own]);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);

    // A repeat finds the capture and queues nothing more.
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => $sha])->assertOk()->assertJsonPath('captured', false);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
});

it('serves a download only while the entry still holds the bytes the caller names', function () {
    $asset = modelThumbAsset();
    $sha = modelThumbChecksum($asset);
    $download = route('admin.dam.assets.download', ['id' => $asset->id]);

    $this->get($download.'?sha256='.$sha)->assertOk();
    $this->get($download.'?sha256='.strtoupper($sha))->assertOk();
    // Bytes the caller did not name (the entry was replaced since it read its identity) are refused, not served.
    $this->get($download.'?sha256='.str_repeat('b', 64))->assertStatus(409);
    // Without a named identity the download is unchanged.
    $this->get($download)->assertOk();
});

it('lists each missing model with a download pinned to the checksum it lists', function () {
    $folder = Directory::factory()->create();
    $asset = modelThumbAsset();
    $asset->directories()->attach($folder->id);

    $item = $this->getJson(modelThumbMissingUrl(['directory_id' => $folder->id]))->assertOk()->json('items.0');

    expect($item['checksum'])->toBe(modelThumbChecksum($asset))
        ->and($item['url'])->toBe(route('admin.dam.assets.download', ['id' => $asset->id, 'sha256' => $item['checksum']]));
});

it('keeps a reordered member custom picture while storing a new model capture beside it', function () {
    $cover = ['path' => 'custom-thumbnails/999/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png', 'mime' => 'image/png'];
    $asset = modelThumbAsset(meta: ['stack_cover' => $cover]);
    expect(ModelThumbnailService::hasThumbnail($asset))->toBeTrue()
        ->and(ModelThumbnailService::cardUrl($asset))->not->toBeNull();
    $this->postJson(modelThumbUrl($asset), ['image' => modelThumbCapture(), 'checksum' => modelThumbChecksum($asset)])
        ->assertOk()->assertJsonPath('skipped', 'custom')->assertJsonPath('captured', true);
    expect($asset->fresh()->meta_data['stack_cover'])->toBe($cover);
});
