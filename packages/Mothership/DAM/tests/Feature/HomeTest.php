<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\DamConfiguration;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DamConfigurationFlags;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->homeAdmin = Admin::factory()->create();
    $this->actingAs($this->homeAdmin, 'admin');
    // Isolate from a host-installed (vault-synced) StylePress catalog pointer so the
    // tracked catalog is what these tests exercise.
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
});

it('serves Home with its native and creative widget catalog', function () {
    $this->get('/admin/home')->assertOk()->assertSee('data-mothership-home', false);
    $this->getJson('/admin/home/layout')->assertOk()
        ->assertJsonCount(17, 'catalog');
});

it('includes shared search and popup styles in complete page responses', function (string $path, bool $explorer) {
    config(['dam.explorer.enabled' => $explorer]);
    if (str_contains($path, '{asset}')) {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create());
        $path = str_replace('{asset}', (string) $asset->id, $path);
    }
    $html = $this->get($path)->assertOk()->getContent();
    preg_match('/<head\b[^>]*>(.*?)<\/head>/s', $html, $head);
    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $head[1] ?? '', $styles);
    $css = implode("\n", $styles[1]);
    expect($css)->toContain('.dam-iconvault-dialog {')
        ->toContain('height:94dvh;')
        ->toContain('.dam-sidebar-resize {');
    // The header search is the Bridge command field: the form is on every page, its styles ship in the admin bundle.
    $build = base_path('public/themes/admin/default/build');
    $manifest = json_decode(file_get_contents($build.'/manifest.json'), true);
    expect($html)->toContain('class="dam-header-search bridge-cmd"')
        ->and(file_get_contents($build.'/'.$manifest['src/Resources/assets/css/app.css']['file']))
        ->toContain('.bridge-cmd.dam-header-search{');
    // The partial's @push('scripts') still reaches the body stack exactly once when included from <head>.
    $body = substr($html, strpos($html, '</head>'));
    expect(substr_count($body, 'window.__mothershipHeaderSearch = true'))->toBe(1)
        ->and(substr_count($head[1] ?? '', 'window.__mothershipHeaderSearch = true'))->toBe(0);
})->with([
    'Home'                      => ['/admin/home', true],
    'Library explorer'          => ['/admin/dam/assets', true],
    'Library legacy'            => ['/admin/dam/assets', false],
    'Asset detail with history' => ['/admin/dam/assets/edit/{asset}', true],
]);

it('leaves the staff Library tools out of the signed-out shell', function () {
    // Share and upload-link visitors are never signed in, so the guest layout skips library-navigation (PR #438).
    auth('admin')->logout();
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('.dam-iconvault-dialog {')
        ->and($html)->not->toContain('window.__mothershipHeaderSearch = true');
});

it('keeps universal gadgets available without asset access or allowlist entries', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings', 'settings.users']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["rss"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->get('/admin/home')->assertOk();
    $catalog = $this->getJson('/admin/home/layout')->assertOk()->json('catalog');
    expect(array_column($catalog, 'id'))
        ->not->toContain('recent')
        ->toContain('loop-maker', 'sound-effects', 'plaque');
    $catalogById = collect($catalog)->keyBy('id');
    expect($catalogById['loop-maker']['always_available'] ?? false)->toBeTrue();
    $this->getJson('/admin/home/data/recent')->assertForbidden();
    $this->get('/admin/home/tools/loop-maker/index.html')->assertOk();
    $this->get('/admin/home/tools/sound-effects/index.html')->assertOk();
    $this->get('/admin/home/tools/plaque/index.html')->assertOk();
});

it('shows the rail storage meter only to a role that may read storage', function () {
    $meter = '<v-rail-storage url="'.route('admin.home.data', ['widget' => 'storage']).'"';
    expect($this->get('/admin/home')->assertOk()->getContent())->toContain($meter);

    // Without the Library the storage reading is refused, so the rail neither draws the meter nor requests it.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings', 'settings.users']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->getJson('/admin/home/data/storage')->assertForbidden();
    expect($this->get('/admin/home')->assertOk()->getContent())->not->toContain('<v-rail-storage');
});

it('requires authentication for Home layout and tools', function () {
    auth('admin')->logout();
    $this->get('/admin/home')->assertRedirect(route('admin.session.create'));
    $this->get('/admin/home/layout')->assertRedirect(route('admin.session.create'));
});

it('serves every tool frame and refuses source files outside the distribution', function () {
    // Each frame carries the bridge and the save helper for the installed iPhone app, byte for byte: a replacement
    // string would rewrite their `\\` and `$1` and the helper would not parse (seen in WebKit, 2026-11-11).
    $scripts = dirname(__DIR__, 2).'/src/Resources/assets/js/';
    foreach (['stylepress/index.html', 'looper/index.html', 'loop-maker/index.html', 'sound-effects/index.html', 'plaque/index.html'] as $tool) {
        $this->get('/admin/home/tools/'.$tool)->assertOk()->assertSee('__MOTHERSHIP_FRAME__', false)
            ->assertSee('<script>'.file_get_contents($scripts.'home-frame.js').'</script><script>'.file_get_contents($scripts.'device-save.js').'</script>', false);
    }
    // Signal has no frame of its own any more: only the Home card and the announcement ticker's files.
    $this->get('/admin/home/tools/rss/index.html')->assertNotFound();
    $this->get('/admin/home/tools/rss/README.md')->assertNotFound();
    $this->get('/admin/home/tools/rss/..%2F..%2F..%2FConfig/home.php')->assertNotFound();
});

it('keeps widget preferences separate for each account and widget', function () {
    $this->putJson('/admin/home/state/rss', ['state' => ['saved' => '[1,2]']])->assertOk();
    $this->getJson('/admin/home/state/rss')->assertJsonPath('state.saved', '[1,2]');
    $this->getJson('/admin/home/state/looper')->assertJsonCount(0, 'state');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson('/admin/home/state/rss')->assertJsonCount(0, 'state');
    $this->putJson('/admin/home/state/rss', ['state' => ['bad' => ['nested']]])->assertUnprocessable();
});

it('filters recent assets and totals by directly accessible folders', function () {
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(2)->create());
    $assets[0]->directories()->attach($allowed->id);
    $assets[1]->directories()->attach($private->id);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->getJson('/admin/home/data/recent')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[0]->id);
    $this->getJson('/admin/home/data/totals')->assertOk()->assertJsonPath('files', 1);
});

it('browses StylePress reference images by folder like the library and remembers the source asset', function () {
    Storage::fake('local');
    $directory = Directory::factory()->create(['name' => 'Campus']);
    $other = Directory::factory()->create(['name' => 'Elsewhere']);
    $inside = UploadedFile::fake()->image('inside.png', 6, 6);
    $outside = UploadedFile::fake()->image('outside.png', 6, 6);
    $video = UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4');
    $one = Asset::factory()->create(['file_name' => 'inside.png', 'file_type' => 'image', 'path' => 'campus/inside.png']);
    $two = Asset::factory()->create(['file_name' => 'outside.png', 'file_type' => 'image', 'path' => 'else/outside.png']);
    $three = Asset::factory()->create(['file_name' => 'clip.mp4', 'file_type' => 'video', 'path' => 'campus/clip.mp4']);
    $gif = Asset::factory()->create(['file_name' => 'anim.gif', 'file_type' => 'image', 'extension' => 'gif', 'mime_type' => 'image/gif', 'path' => 'campus/anim.gif']);
    $png = Asset::factory()->create(['file_name' => 'a.png', 'file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'else/a.png']);
    $jpeg = Asset::factory()->create(['file_name' => 'b.jpeg', 'file_type' => 'image', 'extension' => 'JPEG', 'mime_type' => 'image/jpeg', 'path' => 'else/b.jpeg']);
    $webp = Asset::factory()->create(['file_name' => 'c.webp', 'file_type' => 'image', 'extension' => 'webp', 'mime_type' => 'image/webp', 'path' => 'else/c.webp']);
    $bareMime = Asset::factory()->create(['file_name' => 'd', 'file_type' => 'image', 'extension' => null, 'mime_type' => 'image/png', 'path' => 'else/d']);
    $svg = Asset::factory()->create(['file_name' => 'e.svg', 'file_type' => 'image', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'path' => 'else/e.svg']);
    $bareExtension = Asset::factory()->create(['file_name' => 'f.webp', 'file_type' => 'image', 'extension' => 'webp', 'mime_type' => null, 'path' => 'else/f.webp']);
    $upperMime = Asset::factory()->create(['file_name' => 'g', 'file_type' => 'image', 'extension' => null, 'mime_type' => 'IMAGE/JPEG', 'path' => 'else/g']);
    $child = Directory::factory()->create(['name' => 'Nested', 'parent_id' => $directory->id]);
    $nested = Asset::factory()->create(['file_name' => 'nested.png', 'file_type' => 'image', 'path' => 'campus/nested/nested.png']);
    // Ingested as the asset's current original, as an upload's would be: reads go through it (ADR 0009).
    putAssetContent($one, file_get_contents($inside->getRealPath()));
    Storage::disk(Directory::getAssetDisk())->put('else/outside.png', file_get_contents($outside->getRealPath()));
    DB::table('dam_asset_directory')->insert([['asset_id' => $one->id, 'directory_id' => $directory->id], ['asset_id' => $three->id, 'directory_id' => $directory->id], ['asset_id' => $gif->id, 'directory_id' => $directory->id], ['asset_id' => $two->id, 'directory_id' => $other->id], ['asset_id' => $nested->id, 'directory_id' => $child->id]]);
    // Direct members only, and only the formats a reference may use: the video, the GIF and the nested child stay out.
    $folder = $this->getJson('/admin/home/stylepress/assets?scope=folder&directory='.$directory->id)->assertOk()->json();
    expect(collect($folder['assets'])->pluck('id')->all())->toBe([$one->id])
        ->and($folder['directory'])->toBe(['id' => $directory->id, 'name' => 'Campus'])
        ->and($folder['has_more'])->toBeFalse();
    expect(collect($this->getJson('/admin/home/stylepress/assets?scope=folder&directory='.$child->id)->assertOk()->json('assets'))->pluck('id')->all())->toBe([$nested->id]);
    $all = collect($this->getJson('/admin/home/stylepress/assets?scope=all')->assertOk()->json('assets'))->pluck('id')->all();
    expect($all)->toContain($png->id, $jpeg->id, $webp->id, $bareMime->id, $bareExtension->id, $upperMime->id, $one->id)->not->toContain($gif->id, $svg->id, $three->id);
    $this->getJson('/admin/home/stylepress/assets?scope=folder&directory=999999')->assertNotFound();
    // The folder tree rides StylePress access, not the directory ACL: a dam + dam.assets role sees its granted folders.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    // StylePress is a paid tool and opt-in for a custom role, so this role ticks it explicitly.
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => json_encode(['recent', 'stylepress']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_directory_role')->insert([['role_id' => $role->id, 'directory_id' => $directory->id], ['role_id' => $role->id, 'directory_id' => $child->id]]);
    $limited = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($limited, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $tree = $this->getJson('/admin/home/stylepress/folders')->assertOk()->json('data');
    expect(collect($tree)->pluck('id')->all())->toContain($directory->id)->not->toContain($other->id);
    $children = $this->getJson('/admin/home/stylepress/folders?parent='.$directory->id)->assertOk()->json();
    expect(collect($children['data'])->pluck('id')->all())->toBe([$child->id])->and($children['has_more'])->toBeFalse();
    $this->getJson('/admin/home/stylepress/folders?parent='.$other->id)->assertNotFound();
    $this->getJson('/admin/home/stylepress/assets?scope=folder&directory='.$other->id)->assertNotFound();
    expect(collect($this->getJson('/admin/home/stylepress/assets?scope=folder&directory='.$directory->id)->assertOk()->json('assets'))->pluck('id')->all())->toBe([$one->id]);
    $this->actingAs(Admin::factory()->create(), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $byName = $this->getJson('/admin/home/stylepress/assets?scope=all&sort=name')->assertOk()->json('assets');
    expect(collect($byName)->pluck('name')->map(fn ($n) => strtolower($n))->all())->toBe(collect($byName)->pluck('name')->map(fn ($n) => strtolower($n))->sort()->values()->all());
    $reference = $this->postJson('/admin/home/stylepress/references', ['asset_id' => $one->id])->assertCreated()->json('id');
    expect(DB::table('dam_stylepress_references')->where('id', $reference)->value('asset_id'))->toBe($one->id);
    // Add accepts real JPEG and WebP bytes from listed assets, and refuses a listed asset whose bytes are not an image.
    $jpegFile = UploadedFile::fake()->image('b.jpg', 6, 6); // keep the fake handles alive until the bytes are read
    $webpFile = UploadedFile::fake()->image('c.webp', 6, 6);
    putAssetContent($jpeg, file_get_contents($jpegFile->getRealPath()));
    putAssetContent($webp, file_get_contents($webpFile->getRealPath()));
    putAssetContent($png, 'not really an image');
    $this->postJson('/admin/home/stylepress/references', ['asset_id' => $jpeg->id])->assertCreated();
    $this->postJson('/admin/home/stylepress/references', ['asset_id' => $webp->id])->assertCreated();
    $this->postJson('/admin/home/stylepress/references', ['asset_id' => $png->id])->assertUnprocessable();
    $upload = UploadedFile::fake()->image('local.png', 6, 6);
    $local = $this->post('/admin/home/stylepress/references', ['image' => $upload])->assertCreated()->json('id');
    expect(DB::table('dam_stylepress_references')->where('id', $local)->value('asset_id'))->toBeNull();
});

it('reports a StylePress reference asset unavailable through the seam when its blob is gone', function () {
    Storage::fake('local');
    $asset = Asset::factory()->create(['file_name' => 'gone.png', 'file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'campus/gone.png']);
    $image = UploadedFile::fake()->image('gone.png', 6, 6);
    putAssetContent($asset, file_get_contents($image->getRealPath()));
    $object = $asset->files()->where('role', 'original')->firstOrFail()->fileObject;
    Storage::disk($object->disk)->delete($object->path);

    $this->postJson('/admin/home/stylepress/references', ['asset_id' => $asset->id])->assertUnprocessable();
    expect(DB::table('dam_stylepress_references')->where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('finds StylePress reference images by name title or tag within accessible folders and favorites', function () {
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(7)->create(['file_type' => 'image']));
    $assets[6]->update(['file_name' => 'quad_2%.jpg', 'title' => null]); // literal LIKE metacharacters in a file name
    $assets[0]->update(['file_name' => 'campus-morning.jpg', 'title' => 'Quad at sunrise']);
    $assets[1]->update(['file_name' => 'img_0042.jpg', 'title' => null]);
    $assets[2]->update(['file_name' => 'quad-hidden.jpg']);
    $assets[3]->update(['file_name' => 'img_0007.jpg', 'title' => null, 'meta_data' => ['Title' => 'Lecture hall 100% full!']]); // literal % and the escape character in a display title
    DB::table('dam_assets')->where('id', $assets[3]->id)->update(['created_at' => now()->subDays(60)]); // created_at is not mass-assignable
    $assets[4]->update(['file_name' => 'campus-tour.mp4', 'file_type' => 'video']);
    $assets[5]->update(['file_name' => 'img_0099.jpg', 'title' => 'Gymnasium banner']); // stored title only: no property, no metadata
    // Canonical display title precedence: an AssetProperty title outranks the stored title column.
    AssetProperty::factory()->create(['dam_asset_id' => $assets[0]->id, 'name' => 'Title', 'value' => 'Quad at sunrise (curated)']);
    $tag = DB::table('dam_tags')->insertGetId(['name' => 'Esports arena', 'created_at' => now(), 'updated_at' => now()]);
    $literalTag = DB::table('dam_tags')->insertGetId(['name' => 'wide_angle!', 'created_at' => now(), 'updated_at' => now()]); // literal _ and ! in a tag
    DB::table('dam_asset_tag')->insert([['asset_id' => $assets[1]->id, 'tag_id' => $tag], ['asset_id' => $assets[5]->id, 'tag_id' => $literalTag]]);
    foreach ([0, 1, 3, 4, 5, 6] as $index) {
        $assets[$index]->directories()->attach($allowed->id);
    }
    $assets[2]->directories()->attach($private->id);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    // StylePress is a paid tool and opt-in for a custom role, so this role ticks it explicitly.
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => json_encode(['recent', 'stylepress']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();
    // Title match (direct title), filename-only match, tag match, metadata title match; private folder and video stay hidden.
    $this->getJson('/admin/home/stylepress/assets?q=QUAD')->assertOk()->assertJsonCount(2, 'assets')->assertJsonPath('assets.0.id', $assets[6]->id)->assertJsonPath('assets.1.id', $assets[0]->id)->assertJsonPath('assets.1.title', 'Quad at sunrise (curated)');
    // % and _ are literal characters in the search, never wildcards.
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('quad_2%'))->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[6]->id);
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('%'))->assertOk()->assertJsonCount(2, 'assets'); // quad_2%.jpg and the "100% full!" title, nothing else
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('%').'&scope=recent')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[6]->id);
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('quad_'))->assertOk()->assertJsonCount(1, 'assets');
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('q_a'))->assertOk()->assertJsonCount(0, 'assets');
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('100% full!').'&scope=all')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[3]->id);
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('!'))->assertOk()->assertJsonCount(2, 'assets'); // tag "wide_angle!" and the "100% full!" title
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('!').'&scope=recent')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[5]->id); // tag "wide_angle!"
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('wide_angle'))->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[5]->id);
    $this->getJson('/admin/home/stylepress/assets?q='.urlencode('widexangle'))->assertOk()->assertJsonCount(0, 'assets');
    $this->getJson('/admin/home/stylepress/assets?q=curated')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[0]->id);
    $this->getJson('/admin/home/stylepress/assets?q=gymnasium')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[5]->id)->assertJsonPath('assets.0.title', 'Gymnasium banner');
    $assets[1]->update(['meta_data' => ['title' => 'lowercase metadata title']]);
    $this->getJson('/admin/home/stylepress/assets?q=lowercase')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[1]->id)->assertJsonPath('assets.0.title', 'lowercase metadata title');
    $assets[1]->update(['meta_data' => null]);
    $this->getJson('/admin/home/stylepress/assets?q=campus')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[0]->id);
    $this->getJson('/admin/home/stylepress/assets?q=arena')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[1]->id)->assertJsonPath('assets.0.title', 'img_0042.jpg');
    $this->getJson('/admin/home/stylepress/assets?q=lecture&scope=all')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[3]->id)->assertJsonPath('assets.0.title', 'Lecture hall 100% full!');
    // Recent is the 30-day window; All is the whole permitted library, paged.
    $this->getJson('/admin/home/stylepress/assets?scope=recent')->assertOk()->assertJsonCount(4, 'assets');
    $this->getJson('/admin/home/stylepress/assets?scope=all')->assertOk()->assertJsonCount(5, 'assets')->assertJsonPath('page', 1)->assertJsonPath('has_more', false);
    $this->getJson('/admin/home/stylepress/assets?scope=all&page=2')->assertOk()->assertJsonCount(0, 'assets')->assertJsonPath('page', 2)->assertJsonPath('has_more', false);
    $this->getJson('/admin/home/stylepress/assets?scope=bogus')->assertOk()->assertJsonPath('scope', 'all');
    // Favorites: empty, then only the starred permitted image, filtered by the query too.
    $this->getJson('/admin/home/stylepress/assets?scope=favorites')->assertOk()->assertJsonCount(0, 'assets');
    DB::table('dam_asset_favorites')->insert([['admin_id' => $admin->id, 'dam_asset_id' => $assets[1]->id, 'created_at' => now()], ['admin_id' => $admin->id, 'dam_asset_id' => $assets[2]->id, 'created_at' => now()]]);
    $this->getJson('/admin/home/stylepress/assets?scope=favorites')->assertOk()->assertJsonCount(1, 'assets')->assertJsonPath('assets.0.id', $assets[1]->id);
    $this->getJson('/admin/home/stylepress/assets?scope=favorites&q=arena')->assertOk()->assertJsonCount(1, 'assets');
    $this->getJson('/admin/home/stylepress/assets?scope=favorites&q=sunrise')->assertOk()->assertJsonCount(0, 'assets');
});

it('answers the feed services as unavailable when no adapter URL is set', function () {
    config(['dam-home.rss_api' => null, 'dam-home.trending_api' => null]);
    Http::fake();
    $this->getJson('/admin/home/service/rss/v1/stories')->assertStatus(503);
    $this->getJson('/admin/home/service/trending/v1/trending')->assertStatus(503);
    Http::assertNothingSent();
});

it('proxies only allowed RSS endpoints and reports service failure', function () {
    config(['dam-home.rss_api' => 'https://adapter.test/api']);
    Http::fake(['*' => Http::response(['stories' => []])]);
    $this->getJson('/admin/home/service/rss/v1/stories')->assertOk()->assertJsonCount(0, 'stories');
    $this->getJson('/admin/home/service/rss/admin')->assertNotFound();
    // Feed lists and full articles served only the removed Signal reader.
    $this->getJson('/admin/home/service/rss/v1/feeds')->assertNotFound();
    $this->getJson('/admin/home/service/rss/v1/stories/12')->assertNotFound();
    $this->postJson('/admin/home/service/rss/v1/stories')->assertNotFound();
    Http::assertSentCount(1);
});

it('proxies only GitHub Trending day endpoints to the adapter', function () {
    config(['dam-home.trending_api' => 'https://adapter.test/api', 'dam-home.rss_origin' => 'https://assets.example']);
    Http::fake(['*' => Http::response(['date' => '2026-09-11', 'repos' => []])]);
    $this->getJson('/admin/home/service/trending/v1/trending')->assertOk()->assertJsonPath('date', '2026-09-11');
    $this->getJson('/admin/home/service/trending/v1/trending/2026-09-10')->assertOk();
    foreach (['v1/trending/yesterday', 'v1/trending/2026-9-1', 'v1/trending/2026-09-10/x', 'v1/stories', 'health'] as $path) {
        $this->getJson('/admin/home/service/trending/'.$path)->assertNotFound();
    }
    $this->postJson('/admin/home/service/trending/v1/trending')->assertNotFound();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://adapter.test/api/v1/trending/2026-09-10'
        && $request->header('Origin') === ['https://assets.example']);
});

it('forwards a forced Trending refresh to the adapter and still drops unknown params', function () {
    config(['dam-home.trending_api' => 'https://adapter.test/api', 'dam-home.rss_origin' => 'https://assets.example']);
    Http::fake(['*' => Http::response(['date' => '2026-09-11', 'repos' => [], 'forced' => true, 'refreshedAt' => 1758000000000, 'throttledUntil' => 1758000600000])]);
    $this->getJson('/admin/home/service/trending/v1/trending?refresh=1&bogus=nope')->assertOk()->assertJsonPath('forced', true);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'refresh=1') && ! array_key_exists('bogus', $request->data()));
});

it('keeps GitHub Trending from roles whose Home allowlist excludes it', function () {
    Http::fake();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["rss"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    expect(array_column($this->getJson('/admin/home/layout')->json('catalog'), 'id'))->not->toContain('trending');
    $this->getJson('/admin/home/service/trending/v1/trending')->assertForbidden();
    Http::assertNothingSent();
});

it('refuses another users loop files before contacting the backend', function () {
    Http::fake();
    $fileId = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_tool_files')->insert(['file_id' => $fileId, 'admin_id' => Admin::factory()->create()->id, 'created_at' => now()]);
    $this->get('/admin/home/service/looper/static/'.$fileId.'.mp4')->assertForbidden();
    Http::assertNothingSent();
});

it('passes the actual loop matching query parameters through', function () {
    Http::fake(['*' => Http::response(['score' => 95])]);
    $fileId = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_tool_files')->insert(['file_id' => $fileId, 'admin_id' => $this->homeAdmin->id, 'created_at' => now()]);
    $this->getJson('/admin/home/service/looper/match-score/'.$fileId.'.mp4?ref_time=1&target_time=4')->assertOk();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'ref_time=1') && str_contains($request->url(), 'target_time=4'));
});

it('reports unavailable storage without inventing zero capacity', function () {
    $disk = Directory::getAssetDisk();
    config(["filesystems.disks.$disk.root" => '/nonexistent-home-storage-probe']);
    $this->getJson('/admin/home/data/storage')->assertOk()
        ->assertJsonPath('available', false)->assertJsonPath('free', null)->assertJsonPath('total', null);
});

it('streams owned file ranges without dropping response headers', function () {
    Http::fake(['*' => Http::response('part', 206, ['Content-Type' => 'video/mp4', 'Content-Range' => 'bytes 0-3/20', 'Accept-Ranges' => 'bytes'])]);
    $fileId = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_tool_files')->insert(['file_id' => $fileId, 'admin_id' => $this->homeAdmin->id, 'created_at' => now()]);
    $this->withHeader('Range', 'bytes=0-3')->get('/admin/home/service/looper/static/'.$fileId.'.mp4')
        ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/20')->assertStreamedContent('part');
    Http::assertSent(fn ($request) => $request->hasHeader('Range', 'bytes=0-3'));
});

it('keeps StylePress useful without an unconfigured generation connection', function () {
    config(['stylepress.higgsfield.api_key_id' => null, 'stylepress.higgsfield.api_key_secret' => null]);
    $this->get('/admin/home/tools/stylepress/index.html')->assertOk()->assertSee('data-connected="0"', false)
        ->assertSeeInOrder(['value="gpt_image_2_5:flare" selected', 'value="gpt_image_2_5:sunburst"', 'value="gpt_image_2"'], false);
    $this->get('/admin/home/tools/stylepress/index.html')->assertOk()
        ->assertSee('id="sp-output"', false)->assertSee('id="sp-lightbox"', false)->assertSee('sp-spinner', false)->assertSee('View full size')->assertSee('data-density="comfortable"', false)
        ->assertSee('/generation/output?download=1', false)->assertSee('/generation/view', false)
        ->assertSee('.sp-cards[data-density=list]>*{flex:0 0 auto}', false);
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->assertOk()->json();
    $tracked = json_decode(file_get_contents((string) config('stylepress.catalog_path')), true);
    expect(count($catalog['presets']))->toBe(count($tracked['presets']))->toBeGreaterThan(0);
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $catalog['presets'][0]['slug'], 'prompt' => 'A simple test', 'mode' => 'restyle'])->assertUnprocessable();
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $catalog['presets'][0]['slug'], 'prompt' => 'A simple test', 'model' => '/other'])->assertUnprocessable();
});

it('pages the full StylePress image library in ordered non-overlapping pages of forty', function () {
    $allowed = Directory::factory()->create();
    // 2,001 images: page 50 is a full page that must still report has_more, page 51 holds the last
    // image, page 52 is empty. A page clamp at 50 (the r2 defect) would repeat page 50 forever.
    $rows = [];
    $now = now();
    for ($i = 0; $i < 2001; $i++) {
        $rows[] = ['file_name' => sprintf('paged-%04d.jpg', $i), 'file_type' => 'image', 'file_size' => 1, 'path' => 'assets/paged-'.$i.'.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('dam_assets')->insert($chunk);
    }
    $ids = DB::table('dam_assets')->where('file_name', 'like', 'paged-%')->pluck('id');
    foreach ($ids->chunk(500) as $chunk) {
        DB::table('dam_asset_directory')->insert($chunk->map(fn ($id) => ['asset_id' => $id, 'directory_id' => $allowed->id])->all());
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    // StylePress is a paid tool and opt-in for a custom role, so this role ticks it explicitly.
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => json_encode(['recent', 'stylepress']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $seen = [];
    foreach ([1 => [40, true], 49 => [40, true], 50 => [40, true], 51 => [1, false], 52 => [0, false]] as $page => [$count, $more]) {
        $pageIds = $this->getJson('/admin/home/stylepress/assets?scope=all&page='.$page)->assertOk()->assertJsonCount($count, 'assets')->assertJsonPath('has_more', $more)->json('assets.*.id');
        expect(array_intersect($seen, $pageIds))->toBe([])->and($pageIds)->toBe(collect($pageIds)->sortDesc()->values()->all());
        $seen = [...$seen, ...$pageIds];
    }
    expect($seen)->toHaveCount(121); // 40 + 40 + 40 + 1 + 0 across the sampled pages
    // Exact 40-row boundary: with exactly 40 permitted images left, has_more is false and the next page is empty.
    DB::table('dam_assets')->whereIn('id', $ids->sortDesc()->values()->take(1961)->all())->delete();
    $this->getJson('/admin/home/stylepress/assets?scope=all&page=1')->assertOk()->assertJsonCount(40, 'assets')->assertJsonPath('has_more', false);
    $this->getJson('/admin/home/stylepress/assets?scope=all&page=2')->assertOk()->assertJsonCount(0, 'assets')->assertJsonPath('has_more', false);
    $this->getJson('/admin/home/stylepress/assets?scope=recent')->assertOk()->assertJsonCount(40, 'assets')->assertJsonPath('has_more', false);
});

it('keeps the HTTP Higgsfield driver on its default model and refuses GPT Image 2.5 there', function () {
    config(['stylepress.higgsfield.api_key_id' => 'a', 'stylepress.higgsfield.api_key_secret' => 'b']);
    Http::fake(['*' => Http::response(['request_id' => 'fixture', 'status_url' => 'https://api.higgsfield.ai/requests/fixture/status'])]);
    $slug = $this->getJson('/admin/home/stylepress/catalog')->json('presets.0.slug');
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $slug, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2_5', 'variant' => 'flare'])->assertUnprocessable()->assertJsonFragment(['message' => 'GPT Image 2.5 requires the shared subscription connection. Choose GPT Image 2 · Standard.']);
    $this->postJson('/admin/home/stylepress/estimate', ['style_slug' => $slug, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2_5', 'variant' => 'sunburst'])->assertUnprocessable()->assertJsonPath('ok', false);
    Http::assertNothingSent();
    $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $slug, 'prompt' => 'A cube', 'aspect_ratio' => '1:1', 'model' => 'gpt_image_2'])->assertAccepted();
    Http::assertSent(fn ($request) => ! array_key_exists('model', $request->data()) && ! array_key_exists('variant', $request->data()) && $request->data()['resolution'] === '2k');
});

it('polls StylePress jobs only for their owning account', function () {
    config(['stylepress.higgsfield.api_key_id' => 'a', 'stylepress.higgsfield.api_key_secret' => 'b']);
    Http::fake(['*' => Http::response(['status' => 'completed', 'images' => [['url' => 'https://example.com/output.png']]])]);
    $id = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->homeAdmin->id, 'request_id' => 'fixture', 'status_url' => 'https://api.higgsfield.ai/requests/fixture/status', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', 'completed')->assertJsonPath('generation.url', 'https://example.com/output.png');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertNotFound();
    Http::assertSentCount(1);
});

it('submits StylePress once and returns an owned resumable job without polling', function () {
    Queue::fake();
    config(['stylepress.higgsfield.api_key_id' => 'a', 'stylepress.higgsfield.api_key_secret' => 'b']);
    Http::fake(['*' => Http::sequence()->push(['credits' => 1])->push(['request_id' => 'fixture', 'status_url' => 'https://example.com/untrusted'])]);
    $catalog = $this->getJson('/admin/home/stylepress/catalog')->json();
    $response = $this->postJson('/admin/home/stylepress/generate', ['style_slug' => $catalog['presets'][0]['slug'], 'prompt' => 'A simple test']);
    $response->assertStatus(202)->assertJsonPath('generation.status', 'queued');
    $id = $response->json('generation.id');
    $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'admin_id' => $this->homeAdmin->id, 'status' => 'queued']);
    expect($response->json('generation.status_url'))->toBe(route('admin.home.stylepress.generation', ['id' => $id]));
    expect(DB::table('dam_home_generations')->where('id', $id)->value('status_url'))->not->toContain('example.com');
    Http::assertSentCount(2);
});

it('streams complete owned downloads with their length', function () {
    Http::fake(['*' => Http::response('complete', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '8'])]);
    $fileId = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_tool_files')->insert(['file_id' => $fileId, 'admin_id' => $this->homeAdmin->id, 'created_at' => now()]);
    $this->get('/admin/home/service/looper/download/'.$fileId.'.mp4')->assertOk()
        ->assertHeader('Content-Length', '8')->assertHeader('X-Accel-Buffering', 'no')->assertStreamedContent('complete');
});

it('keeps a generation resumable after an upstream status failure', function () {
    config(['stylepress.higgsfield.api_key_id' => 'a', 'stylepress.higgsfield.api_key_secret' => 'b']);
    Http::fake(['*' => Http::response([], 503)]);
    $id = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->homeAdmin->id, 'request_id' => 'fixture', 'status_url' => 'https://api.higgsfield.ai/requests/fixture/status', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertStatus(503);
    $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'status' => 'queued']);
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson('/admin/home/stylepress/generation/'.$id)->assertNotFound();
    Http::assertSentCount(1);
});

it('preserves generation states and polls only nonterminal jobs', function (string $status, bool $terminal) {
    config(['stylepress.higgsfield.api_key_id' => 'a', 'stylepress.higgsfield.api_key_secret' => 'b']);
    Http::fake(['*' => Http::response(['status' => $status, 'url' => 'https://example.com/ignored.png'])]);
    $id = '12ef9d01-b9df-4073-bd0e-83f080ab488a';
    DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => $this->homeAdmin->id, 'request_id' => 'fixture', 'status_url' => 'https://api.higgsfield.ai/requests/fixture/status', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
    for ($i = 0; $i < 2; $i++) {
        $this->getJson('/admin/home/stylepress/generation/'.$id)->assertOk()->assertJsonPath('generation.status', $status)->assertJsonPath('generation.url', null);
    }
    $this->assertDatabaseHas('dam_home_generations', ['id' => $id, 'status' => $status]);
    Http::assertSentCount($terminal ? 1 : 2);
})->with([['queued', false], ['in_progress', false], ['running', false], ['failed', true], ['nsfw', true], ['canceled', true], ['cancelled', true]]);

it('shows the global announcement strip only to a user who may use the RSS tool', function () {
    Http::fake();
    // The strip is also a per-account choice (SignalAlertsTest); every account here opts in, so this proves the role gate.
    $this->homeAdmin->update(['signal_alerts' => true]);
    $pages = ['/admin/home', route('admin.dam.assets.index')];
    $strip = fn (string $html): array => [substr_count($html, 'data-signal-ticker'), substr_count($html, 'ticker.css'), substr_count($html, 'ticker.js')];

    // Full access: the strip and both of its assets, once, and the assets are served.
    foreach ($pages as $page) {
        expect($strip($this->get($page)->assertOk()->getContent()))->toBe([1, 1, 1]);
    }
    expect($this->get('/admin/home/tools/rss/ticker.css')->assertOk()->headers->get('Content-Type'))->toContain('text/css');

    // A staff role whose Home allowlist leaves RSS out: no strip, no request for its denied assets or data.
    $staff = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.directory', 'dam.directory.index']]);
    DB::table('dam_role_settings')->insert(['role_id' => $staff->id, 'home_widget_ids' => '["recent","favorites","links"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $staff->id, 'signal_alerts' => true]), 'admin');
    foreach ($pages as $page) {
        expect($strip($this->get($page)->assertOk()->getContent()))->toBe([0, 0, 0]);
    }
    $sentBefore = Http::recorded()->count();
    $this->get('/admin/home/tools/rss/ticker.css')->assertForbidden();
    $this->get('/admin/home/tools/rss/ticker.js')->assertForbidden();
    $this->getJson('/admin/home/service/rss/v1/announcements')->assertForbidden();
    Http::assertSentCount($sentBefore);

    // The same role allowed RSS, and a custom role with no settings row (ungated), both see it.
    DB::table('dam_role_settings')->where('role_id', $staff->id)->update(['home_widget_ids' => '["recent","favorites","links","rss"]']);
    expect($strip($this->get('/admin/home')->assertOk()->getContent()))->toBe([1, 1, 1]);
    $ungated = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $ungated->id, 'signal_alerts' => true]), 'admin');
    expect($strip($this->get('/admin/home')->assertOk()->getContent()))->toBe([1, 1, 1]);
});

// The DAM middleware applies the stored DAM_* switches to config on every DAM request; the flags are cached, not queried.
function configurationQueryCount(callable $request): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        if (str_contains($query->sql, 'dam_configuration')) {
            $count++;
        }
    });
    $request();

    return $count;
}

it('applies the stored DAM switches without reading dam_configuration on every request', function () {
    DamConfiguration::updateOrCreate(['key' => 'DAM_EXPLORER_SHOW_TREE'], ['value' => '0']);
    DamConfiguration::updateOrCreate(['key' => 'DAM_TREE_SHOW_ASSETS'], ['value' => '1']);

    $first = configurationQueryCount(fn () => $this->get(route('admin.dam.configuration.index'))->assertOk());
    expect($first)->toBeGreaterThan(0)
        ->and(config('dam.explorer.show_tree'))->toBeFalse()
        ->and(config('dam.tree.show_assets'))->toBeTrue();

    config(['dam.explorer.show_tree' => true, 'dam.tree.show_assets' => false]);

    $second = configurationQueryCount(fn () => $this->get(route('admin.dam.configuration.index'))->assertOk());

    // Applied from the cache: the same values, and no query against dam_configuration or the schema.
    expect($second)->toBe(0)
        ->and(config('dam.explorer.show_tree'))->toBeFalse()
        ->and(config('dam.tree.show_assets'))->toBeTrue();
});

it('shows a saved DAM switch on the very next request, and a deleted one falls back to the config default', function () {
    DamConfiguration::updateOrCreate(['key' => 'DAM_EXPLORER_SHOW_TREE'], ['value' => '0']);
    $this->get(route('admin.dam.configuration.index'))->assertOk();
    expect(config('dam.explorer.show_tree'))->toBeFalse();

    // The settings page saves through Eloquent, which forgets the cached map.
    $this->post(route('admin.dam.configuration.update'), ['DAM_EXPLORER_SHOW_TREE' => '1'])->assertRedirect();
    $this->get(route('admin.dam.configuration.index'))->assertOk();
    expect(config('dam.explorer.show_tree'))->toBeTrue();

    DamConfiguration::query()->find('DAM_EXPLORER_SHOW_TREE')->delete();
    config(['dam.explorer.show_tree' => 'sentinel']);
    $this->get(route('admin.dam.configuration.index'))->assertOk();
    expect(config('dam.explorer.show_tree'))->toBe('sentinel');
});

it('reads the switches from the database when the cache store is unavailable', function () {
    DamConfiguration::updateOrCreate(['key' => 'DAM_EXPLORER_SHOW_TREE'], ['value' => '0']);
    Cache::shouldReceive('get', 'put', 'forget')->andThrow(new RuntimeException('cache store down'));

    expect(app(DamConfigurationFlags::class)->flags())->toBe(['dam.explorer.show_tree' => false]);
});
