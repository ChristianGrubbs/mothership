<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\DataGrids\Asset\PickerDataGrid;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('should return the asset picker index page', function () {
    $response = $this->get(route('admin.dam.asset_picker.index'));
    $response->assertOk();
});

it('should fetch assets by ids', function () {
    $assets = Asset::factory()->createMany(3);
    $ids = $assets->pluck('id')->implode(',');

    $response = $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => $ids]));

    $response->assertOk();
    $response->assertJsonCount(3);
    $response->assertJsonStructure([
        '*' => ['id', 'url', 'value', 'file_name', 'file_type'],
    ]);
    // The internal storage path is not part of the picker contract.
    expect($response->json('0'))->not->toHaveKey('storage_file_path');
});

it('should return empty array when no asset ids provided', function () {
    $response = $this->getJson(route('admin.dam.asset_picker.get_assets'));

    $response->assertOk()
        ->assertJson([]);
});

it('should fetch a single asset by id', function () {
    $asset = Asset::factory()->create();

    $response = $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]));

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['id' => $asset->id]);
});

it('should return local thumbnail url when disk is private', function () {
    Config::set('filesystems.default', Directory::ASSETS_DISK_PRIVATE);

    $asset = Asset::factory()->create(['path' => 'assets/Root/sample.jpg']);

    $response = $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]));

    $response->assertOk();
    $url = $response->json('0.url');

    expect($url)->toContain('/file/thumbnail');
});

it('should return thumbnail route url when disk is aws', function () {
    Config::set('filesystems.default', Directory::ASSETS_DISK_AWS);
    Storage::fake(Directory::ASSETS_DISK_AWS);

    $path = 'assets/Root/sample-'.uniqid().'.jpg';
    Storage::disk(Directory::ASSETS_DISK_AWS)->put($path, 'dummy');

    $asset = Asset::factory()->create(['path' => $path]);

    $response = $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]));

    $response->assertOk();
    $url = $response->json('0.url');

    expect($url)->toContain('/admin/dam/file/thumbnail');
    expect($url)->toBe(route('admin.dam.file.thumbnail', ['path' => urlencode($path)]));
});

it('should return thumbnail route url for audio asset so cover art is served', function () {
    Config::set('filesystems.default', Directory::ASSETS_DISK_PRIVATE);

    $path = 'assets/Root/track-'.uniqid().'.mp3';
    $asset = Asset::factory()->create([
        'path'      => $path,
        'file_type' => 'audio',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'meta_data' => ['cover_art_path' => 'covers/'.uniqid().'.jpg'],
    ]);

    $response = $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]));

    $response->assertOk();
    $url = $response->json('0.url');

    expect($url)->toBe(route('admin.dam.file.thumbnail', ['path' => urlencode($path)]));
});

it('drops ids the viewer cannot open, exactly like missing ones (lists)', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $owner = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view', 'dam.asset_assign']])->id]);

    $visible = Asset::factory()->create(['created_by' => $owner->id]);
    $hidden = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $viewer->id]);

    $fetch = fn (Admin $actor) => collect($this->actingAs($actor, 'admin')
        ->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => $visible->id.','.$hidden->id.',999999']))
        ->assertOk()->json())->pluck('id')->all();

    expect($fetch($viewer))->toBe([$visible->id])
        ->and($fetch($owner))->toEqualCanonicalizing([$visible->id, $hidden->id]);
});

it('shortens a long multibyte file name on a character boundary so the lists still answer', function () {
    $name = str_repeat('صورة', 10).'.jpg';
    $asset = Asset::factory()->create(['file_name' => $name, 'path' => 'assets/Root/'.$name, 'file_type' => 'image', 'extension' => 'jpg']);

    expect(mb_check_encoding(AssetHelper::getDisplayFileName($name), 'UTF-8'))->toBeTrue();

    $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]))->assertOk()
        ->assertJsonPath('0.file_name', AssetHelper::getDisplayFileName($name));
    $this->getJson(route('admin.dam.asset_picker.index', ['limit' => 50]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->getJson(route('admin.dam.assets.index', ['limit' => 50]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
});

it('encodes a stored value that is not valid UTF-8 as U+FFFD at the datagrid JSON boundary, and still fails on other encoding errors', function () {
    $grid = fn (array $payload) => new class($payload) extends PickerDataGrid
    {
        public function __construct(private array $payload) {}

        public function prepare(): void
        {
            // The query is not what is under test: only how the rows leave as JSON.
        }

        public function formatData(): array
        {
            return $this->payload;
        }
    };

    $response = $grid(['records' => [['file_name' => "bad\xB1name.jpg"]]])->toJson();

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode($response->getContent(), true)['records'][0]['file_name'])->toBe("bad\u{FFFD}name.jpg");

    // Not a blanket mute: a value JSON cannot represent at all still errors rather than being dropped.
    expect(fn () => $grid(['records' => [['score' => INF]]])->toJson())->toThrow(InvalidArgumentException::class);
});
