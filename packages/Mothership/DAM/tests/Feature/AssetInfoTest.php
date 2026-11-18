<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function (): void {
    $this->loginAsAdmin();
});

it('returns technical provenance tags and properties for an asset', function (): void {
    $root = Directory::factory()->create(['name' => 'Library', 'parent_id' => null]);
    $directory = Directory::factory()->create(['name' => 'Aerial', 'parent_id' => $root->id]);
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.mp4',
        'title'      => 'Survey flight',
        'file_type'  => 'video',
        'asset_kind' => 'video',
        'file_size'  => 1_048_576,
        'mime_type'  => 'video/mp4',
        'extension'  => 'mp4',
        'meta_data'  => [
            'legacy' => [
                'created_by'    => ['username' => 'morgan'],
                'creation_date' => '2021-04-03 12:30:00',
            ],
        ],
    ]);
    $asset->directories()->attach($directory);

    $tag = Tag::create(['name' => 'drone']);
    $asset->tags()->attach($tag);

    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'tech_resolution',
        'value'        => '1920x1080',
    ]);
    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => 'drone_model',
        'value'        => 'Mavic 3',
    ]);

    $fileObject = FileObject::factory()->create([
        'sha256'    => str_repeat('a', 64),
        'size'      => 2_097_152,
        'mime_type' => 'video/mp4',
        'technical' => [
            'width'    => 1920,
            'height'   => 1080,
            'duration' => 125.5,
            'codec'    => 'h264',
        ],
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $fileObject->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $response = $this->getJson(route('admin.dam.assets.info', $asset->id));

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('file_name', 'flight.mp4')
        ->assertJsonPath('title', 'Survey flight')
        ->assertJsonPath('file_type', 'video')
        ->assertJsonPath('asset_kind', 'video')
        ->assertJsonPath('mime_type', 'video/mp4')
        ->assertJsonPath('extension', 'mp4')
        ->assertJsonPath('file_size', 2_097_152)
        ->assertJsonPath('file_size_human', '2.00 MB')
        ->assertJsonPath('dimensions.width', 1920)
        ->assertJsonPath('dimensions.height', 1080)
        ->assertJsonPath('duration.seconds', 125.5)
        ->assertJsonPath('codec', 'h264')
        ->assertJsonPath('created_by', 'morgan')
        ->assertJsonPath('legacy_creation_date', '2021-04-03 12:30:00')
        ->assertJsonPath('directory_breadcrumb.0.name', 'Library')
        ->assertJsonPath('directory_breadcrumb.1.name', 'Aerial')
        ->assertJsonPath('tags.0.name', 'drone')
        ->assertJsonPath('properties.0.name', 'drone_model')
        ->assertJsonPath('properties.0.value', 'Mavic 3')
        ->assertJsonPath('properties.1.name', 'tech_resolution')
        ->assertJsonPath('properties.1.value', '1920x1080')
        ->assertJsonPath('original.sha256', str_repeat('a', 64))
        ->assertJsonStructure([
            'created_at',
            'updated_at',
        ]);

    expect($response->json())->not->toHaveKey('technical');
    expect($response->json())->not->toHaveKey('created_in_'.'resource'.'space_on');
});

it('parses and displays a unicode multiplication-sign resolution', function (): void {
    $asset = Asset::factory()->create();
    $fileObject = FileObject::factory()->create([
        'technical' => ['resolution' => '1920 × 1080'],
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $fileObject->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $this->getJson(route('admin.dam.assets.info', $asset->id))
        ->assertOk()
        ->assertJsonPath('dimensions.width', 1920)
        ->assertJsonPath('dimensions.height', 1080)
        ->assertJsonPath('dimensions.display', '1920 × 1080 px');
});

it('omits large raw technical metadata while retaining normalized fields', function (): void {
    $asset = Asset::factory()->create();
    $fileObject = FileObject::factory()->create([
        'technical' => [
            'width'      => 3840,
            'height'     => 2160,
            'codec_name' => 'h264',
            'probe_blob' => str_repeat('large-probe-payload-', 20_000),
        ],
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $fileObject->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $response = $this->getJson(route('admin.dam.assets.info', $asset->id));

    $response
        ->assertOk()
        ->assertJsonPath('dimensions.display', '3840 × 2160 px')
        ->assertJsonPath('codec', 'h264');
    expect($response->json())->not->toHaveKey('technical')
        ->and($response->getContent())->not->toContain('large-probe-payload-');
});

it('json-encodes script-like metadata values without emitting executable tags', function (): void {
    $asset = Asset::factory()->create([
        'title' => '<script>alert("title")</script>',
    ]);
    AssetProperty::factory()->create([
        'dam_asset_id' => $asset->id,
        'name'         => '<img src=x onerror=alert(1)>',
        'value'        => '</script><script>alert("property")</script>',
    ]);

    $response = $this->getJson(route('admin.dam.assets.info', $asset->id));

    $response
        ->assertOk()
        ->assertJsonPath('title', '<script>alert("title")</script>')
        ->assertJsonPath('properties.0.name', '<img src=x onerror=alert(1)>')
        ->assertJsonPath('properties.0.value', '</script><script>alert("property")</script>');
    expect($response->getContent())
        ->not->toContain('<script>')
        ->not->toContain('<img');
});

it('requires authentication for asset info', function (): void {
    $asset = Asset::factory()->create();

    auth('admin')->logout();

    $this->getJson(route('admin.dam.assets.info', $asset->id))
        ->assertUnauthorized();

    $this->get(route('admin.dam.assets.info', $asset->id))
        ->assertRedirect(route('admin.session.create'));
});

it('uses the asset view permission and directory access for asset info', function (): void {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $allowedAsset = Asset::factory()->create();
    $deniedAsset = Asset::factory()->create();
    $allowedAsset->directories()->attach($allowedDirectory);
    $deniedAsset->directories()->attach($deniedDirectory);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $allowedDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.info', $allowedAsset->id))->assertOk();
    // A hidden asset answers like a missing one, so an id probe cannot learn which assets exist.
    $this->getJson(route('admin.dam.assets.info', $deniedAsset->id))->assertNotFound();
});

it('answers a file-private asset exactly like a missing one under the lists access model', function (): void {
    // Debug pages embed the caller's line number; compare what production would send.
    config(['app.debug' => false]);
    $directory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($directory);
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    // The folder is open to the member; only the file's own list leaves them out (ADR 0010).
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    $hidden = $this->getJson(route('admin.dam.assets.info', $asset->id));
    $missing = $this->getJson(route('admin.dam.assets.info', Asset::max('id') + 1000));

    expect($hidden->status())->toBe(404)
        ->and($missing->status())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->getContent());
});

it('forbids asset info without the asset view permission', function (): void {
    $directory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($directory);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.assets.index'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $directory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertForbidden();
});

it('renders the technical provenance card on the asset edit page', function (): void {
    $asset = Asset::factory()->create([
        'meta_data' => [
            'legacy' => [
                'created_by' => ['username' => 'legacy-import-user'],
            ],
        ],
    ]);

    $this->get(route('admin.dam.assets.edit', $asset->id).'?properties')
        ->assertOk()
        ->assertSee('data-dam-technical-provenance', false)
        ->assertSeeText('Technical & provenance')
        ->assertSeeText('legacy-import-user');
});

it('renders info and open details controls in asset previews', function (): void {
    $asset = Asset::factory()->create();

    $this->get(route('admin.dam.assets.edit', $asset->id))
        ->assertOk()
        ->assertSee('data-dam-info-toggle', false)
        ->assertSee('data-dam-open-details', false)
        ->assertSee('_infoRequestGeneration', false)
        ->assertSee('isCurrentInfoRequest(requestGeneration, assetId)', false)
        ->assertSee('closePreviewWithInfoReset', false)
        ->assertSeeText('Info')
        ->assertSeeText('Open details');

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-dam-info-toggle', false)
        ->assertSee('data-dam-open-details', false)
        ->assertSee('_previewRequestGeneration', false)
        ->assertSee('this.$emitter.off(\'dam-open-preview\', this._onPreviewRequested);', false)
        ->assertSee('requestGeneration !== this._previewRequestGeneration', false)
        // The lightbox back button names where closing returns to (UX QA F24).
        ->assertSee('previewBackLabel()', false)
        ->assertSee('Back to results', false);
});

it('renders the shared preview header\'s "Download original" link, bound to a populated downloadUrl, on both the details page and the Library lightbox', function (): void {
    $asset = Asset::factory()->create();

    // preview-modal.blade.php (the details page's own preview overlay). Its previewData is
    // baked in via Blade's @json directive, which escapes forward slashes by default (json_encode
    // without JSON_UNESCAPED_SLASHES) — assert the escaped form the page actually contains, not
    // the raw route() string.
    $downloadRouteEscaped = str_replace('/', '\\/', route('admin.dam.assets.download', $asset->id));

    $this->get(route('admin.dam.assets.edit', $asset->id))
        ->assertOk()
        ->assertSee('data-dam-preview-download-original', false)
        ->assertSee('v-if="previewData.downloadUrl"', false)
        ->assertSee($downloadRouteEscaped, false);

    // grid-preview-modal.blade.php (the Library's lightbox), which shares the same partial.
    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-dam-preview-download-original', false)
        ->assertSee('v-if="previewData.downloadUrl"', false)
        ->assertSee('downloadUrl:', false);
});

it('renders immediate detail navigation and deferred thumbnails on explorer cards', function (): void {
    config()->set('dam.explorer.enabled', true);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-dam-asset-open', false)
        // The card click goes through openAsset(), which emits at once for every asset. These two
        // strings only prove the wiring is rendered; openAsset() itself is exercised in
        // tests/js/explorer-asset-card-open.test.mjs and, through a real click, in
        // tools/browser/tests/dam-explorer-asset-card-open.test.cjs.
        // A stack card opens its main file like every other card; its count button fans its files out (stack fan-out)
        // when the role may list them (R2).
        // A user who can view lands in the preview (PROD-01); Edit is the fallback for an edit-only user, who is
        // covered by tools/browser/tests/dam-explorer-asset-card-open.test.cjs.
        ->assertSee('@click="openAsset(\'preview\')"', false)
        ->assertDontSee('@click="openAsset(\'edit\')"', false)
        ->assertDontSee('opensFan', false)
        ->assertSee('@click.stop="openStack"', false)
        ->assertSee('this.$emit(mode, this.asset.id);', false)
        ->assertSee('@click.stop="edit(asset.id)"', false)
        // Bound to the computed source since custom thumbnails (#179), no longer to asset.path.
        ->assertSee('v-dam-thumbnail="thumbnailSrc"', false)
        ->assertSee('v-dam-thumbnail="assetThumbnail(asset)"', false)
        ->assertSee("app.directive('dam-thumbnail'", false)
        ->assertDontSee('@click="queuePreview"', false);
});

it('measures a stored PNG without recorded dimensions once and persists them', function (): void {
    Storage::fake('private');
    // PNG signature + IHDR (1200 × 600): getimagesize only needs the header.
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 1200, 600)."\x08\x02\x00\x00\x00".pack('N', 0);
    $blob = storeFixtureBlob(hash('sha256', $png), $png, 'png', 'private');
    $asset = Asset::factory()->create(['file_name' => 'output.png', 'file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png']);
    // A read checks the blob holds exactly the recorded size (ContentStore::holdsBlob()).
    $fileObject = FileObject::factory()->create(['sha256' => hash('sha256', $png), 'size' => strlen($png), 'disk' => 'private', 'path' => $blob, 'mime_type' => 'image/png', 'technical' => null]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $fileObject->id, 'role' => AssetFile::ROLE_ORIGINAL]);

    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('dimensions.display', '1200 × 600 px')
        ->assertJsonPath('aspect_ratio.ratio', '2:1');
    expect($fileObject->fresh()->technical)->toBe(['width' => 1200, 'height' => 600]);

    Storage::disk('private')->delete($blob);
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('dimensions.width', 1200);
});

it('leaves technical data alone for non-raster or unreadable originals', function (): void {
    Storage::fake('private');
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'svg', 'mime_type' => 'image/svg+xml']);
    $fileObject = FileObject::factory()->create(['sha256' => hash('sha256', 'missing.svg'), 'disk' => 'private', 'path' => app(ContentStore::class)->objectPath(hash('sha256', 'missing.svg'), 'svg'), 'mime_type' => 'image/svg+xml', 'technical' => ['codec' => 'none']]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $fileObject->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('dimensions', null);
    expect($fileObject->fresh()->technical)->toBe(['codec' => 'none']);
});

function rasterHeaderFixture(string $kind, int $width, int $height): string
{
    if ($kind === 'webp') {
        // RIFF container with a VP8L header: 14-bit (width-1) then 14-bit (height-1).
        $bits = ($width - 1) | (($height - 1) << 14);
        // PHP reads a fixed 30-byte header, so pad the tiny bitstream out with zero bytes.
        $payload = "\x2f".pack('V', $bits).str_repeat("\x00", 24);
        $chunk = 'VP8L'.pack('V', strlen($payload)).$payload;

        return 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;
    }

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);
}

it('measures rasters with missing or generic MIME types, on any disk, and keeps existing technical keys', function (string $disk, ?string $mime, string $kind, string $extension): void {
    Storage::fake($disk);
    $bytes = rasterHeaderFixture($kind, 640, 480);
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => $extension]);
    $fileObject = FileObject::factory()->create([
        // A read checks the blob holds exactly the recorded size (ContentStore::holdsBlob()).
        'sha256'    => hash('sha256', $bytes), 'size' => strlen($bytes), 'disk' => $disk, 'path' => storeFixtureBlob(hash('sha256', $bytes), $bytes, $extension, $disk), 'mime_type' => $mime,
        'technical' => ['codec' => 'none', 'width' => 0, 'height' => -1],
    ]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $fileObject->id, 'role' => AssetFile::ROLE_ORIGINAL]);

    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('dimensions.display', '640 × 480 px')
        ->assertJsonPath('aspect_ratio.ratio', '4:3');
    expect($fileObject->fresh()->technical)->toBe(['codec' => 'none', 'width' => 640, 'height' => 480]);
})->with([
    'null mime png'        => ['private', null, 'png', 'png'],
    'octet-stream webp'    => ['private', 'application/octet-stream', 'webp', 'webp'],
    'remote-named disk'    => ['s3', 'image/png', 'png', 'png'],
]);

it('does not measure a file whose MIME and extension are not raster images', function (): void {
    Storage::fake('private');
    $bytes = rasterHeaderFixture('png', 10, 10);
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'bin']);
    $fileObject = FileObject::factory()->create(['sha256' => hash('sha256', $bytes), 'disk' => 'private', 'path' => storeFixtureBlob(hash('sha256', $bytes), $bytes, 'bin', 'private'), 'mime_type' => null, 'technical' => null]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $fileObject->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('dimensions', null);
    expect($fileObject->fresh()->technical)->toBeNull();
});

it('never falls back to the Library path when the blob is unavailable, even with different bytes there', function (): void {
    Storage::fake('private');
    // The Library file holds a DIFFERENT image than the blob. If StoredImageDimensions fell back to
    // reading it, it would persist these (wrong) dimensions onto the shared FileObject (ADR 0009).
    Storage::disk('private')->put('assets/generated/current.png', rasterHeaderFixture('png', 2048, 1024));
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/generated/current.png']);
    $original = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    $fileObject = FileObject::findOrFail($original->file_object_id);
    // The blob itself is gone; the row still correctly names a content-addressed locator
    // (slice 1's migration repointed every legacy row), so this is unavailable content, not a
    // stale locator to fall back past.
    Storage::disk('private')->delete($fileObject->path);

    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('dimensions', null);
    expect($fileObject->fresh()->technical)->toBeNull();
});
