<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * P3a S4: a stack's files download one at a time, as a selection or all together, from current revisions
 * only and under the same rights as a single file; and the Library can group stacks by basis and key.
 * Acceptance lines 2 (group-by on basis and key) and 6 (downloads only what the viewer may access).
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Browse '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'menu.png', 'path' => 'assets/Browse/menu.png']));
    $this->asset->directories()->attach($this->folder->id);
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id;
    $this->zip = '/'.config('app.admin_url').'/dam/file/asset/'.$this->asset->id.'/files-zip';
});

$attach = fn (object $test, string $name, string $content, string $role = 'sidecar', ?string $label = null) => $test->postJson($test->base.'/files', array_filter([
    'file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => $role, 'label' => $label,
]))->assertCreated()->json();

/** @return array<string, string> entry name => bytes */
$entries = function ($response): array {
    $path = tempnam(sys_get_temp_dir(), 'dam_test_zip_');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $out = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($path);

    return $out;
};

it('zips every current file in entry order with one unique name each, and only the selected ones on request', function () use ($attach, $entries) {
    $a = $attach($this, 'notes.txt', 'first notes');
    $b = $attach($this, 'notes.txt', 'second notes', 'transcript');
    $c = $attach($this, 'cover.txt', 'cover');
    // A superseded revision is history, never part of the download.
    AssetFile::create(['dam_asset_id' => $this->asset->id, 'file_object_id' => AssetFile::find($c['id'])->file_object_id, 'role' => 'version', 'attachment_id' => $c['attachment_id'], 'is_current' => false]);

    $all = $this->get($this->zip)->assertOk();
    // Streamed straight to the client: no temp archive is built first.
    expect($all->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($all->headers->get('Content-Disposition'))->toContain('attachment')->toContain('menu-files.zip')
        ->and($all->headers->get('Content-Type'))->toBe('application/zip')
        ->and($all->headers->get('Cache-Control'))->toContain('no-store');
    expect($entries($all))->toBe(['notes.txt' => 'first notes', 'notes (2).txt' => 'second notes', 'cover.txt' => 'cover']);

    $some = $this->get($this->zip.'?files[]='.$b['id'].'&files[]='.$c['id'])->assertOk();
    expect($entries($some))->toBe(['notes.txt' => 'second notes', 'cover.txt' => 'cover']);

    expect($a['id'])->toBeInt();
});

it('zips a stack of 150 files with every entry present, opening each blob only while it is written', function () use ($attach, $entries) {
    $expected = [];

    for ($i = 1; $i <= 150; $i++) {
        $expected[sprintf('note-%03d.txt', $i)] = 'contents '.$i;
        $attach($this, sprintf('note-%03d.txt', $i), 'contents '.$i);
    }

    $baseline = count(get_resources('stream'));
    $peak = 0;
    $real = app(ContentStore::class);
    $store = Mockery::mock(ContentStore::class)->makePartial();
    $store->shouldReceive('open')->andReturnUsing(function (FileObject $object) use ($real, $baseline, &$peak) {
        $stream = $real->open($object);
        $peak = max($peak, count(get_resources('stream')) - $baseline);

        return $stream;
    });
    app()->instance(ContentStore::class, $store);

    $response = $this->get($this->zip)->assertOk();

    expect($entries($response))->toBe($expected)
        ->and($peak)->toBeGreaterThan(0)
        // Descriptors held at once stay a handful (the zip's own handles), never one per file.
        ->and($peak)->toBeLessThan(20);
});

it('names the zip with an RFC 6266 filename* when the name is not plain ASCII', function () use ($attach) {
    $this->asset->forceFill(['file_name' => 'café 100%.png'])->saveQuietly();
    $attach($this, 'notes.txt', 'first notes');

    $disposition = (string) $this->get($this->zip)->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment')
        ->and($disposition)->toContain("filename*=utf-8''caf%C3%A9%20100%25-files.zip")
        ->and($disposition)->toContain('filename="cafe 100_-files.zip"');
});

it('answers 404 for a file that is not one of this entry\'s current files', function () use ($attach) {
    $mine = $attach($this, 'notes.txt', 'notes');
    $other = Asset::withoutEvents(fn () => Asset::factory()->create());
    $foreign = AssetFile::factory()->create(['dam_asset_id' => $other->id, 'role' => 'sidecar']);

    $this->get($this->zip.'?files[]='.$mine['id'].'&files[]='.$foreign->id)->assertNotFound();
});

it('hides another folder\'s entry as missing and refuses a role without view rights', function () use ($attach) {
    $attach($this, 'notes.txt', 'notes');
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download_compressed']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->get($this->zip)->assertNotFound();

    $blind = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $blind->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $blind->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->get($this->zip)->assertForbidden();
});

it('answers 404 and streams nothing when one selected file\'s bytes are missing', function () use ($attach) {
    $attach($this, 'notes.txt', 'notes');
    $gone = AssetFile::with('fileObject')->find($attach($this, 'cover.txt', 'cover')['id'])->fileObject;
    Storage::disk($gone->disk)->delete($gone->path);

    $response = $this->get($this->zip)->assertNotFound();

    expect($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->not->toBe('application/zip');
});

it('refuses the zip to a role that may view the entry but not download it compressed', function () use ($attach) {
    $attach($this, 'notes.txt', 'notes');
    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $viewer->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $viewer->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->get($this->zip)->assertForbidden();

    $zipper = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download_compressed']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $zipper->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $zipper->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->get($this->zip)->assertOk();
});

it('groups the Library by stack: bases in order, then keys, plain assets last', function () {
    $make = function (string $name, ?string $basis, ?string $key) {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => $name, 'path' => 'assets/Browse/'.$name, 'stack_basis' => $basis, 'stack_key' => $key]));
        $asset->directories()->attach($this->folder->id);

        return $asset->id;
    };
    $plain = $make('a-plain.png', null, null);
    $fall = $make('b-fall.png', 'campaign', 'Fall 2026');
    $zebra = $make('c-zebra.png', 'product', 'zebra menu');
    $bistro = $make('d-bistro.png', 'product', 'Bistro menu');
    $social = $make('e-social.png', 'use_case', 'Social');
    $url = '/'.config('app.admin_url').'/dam/explorer/data?directory_id='.$this->folder->id.'&sort_by=name&sort_order=asc';

    $grouped = $this->getJson($url.'&group_by=stack')->assertOk()->json('assets');
    expect(array_column($grouped, 'id'))->toBe([$bistro, $zebra, $fall, $social, $plain, $this->asset->id]);

    $ungrouped = $this->getJson($url)->assertOk()->json('assets');
    expect(array_column($ungrouped, 'id'))->toBe([$plain, $fall, $zebra, $bistro, $social, $this->asset->id]);

    $this->getJson($url.'&group_by=folder')->assertUnprocessable();
});

it('sorts the Library by the size its list shows: a stack by all its files together (VISR-09)', function () use ($attach) {
    // The stack's cover file is the smallest, but its files together are the largest.
    Asset::whereKey($this->asset->id)->update(['file_size' => 10]);
    $attach($this, 'one.txt', str_repeat('a', 3000));
    $attach($this, 'two.txt', str_repeat('b', 3000));
    $single = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'single.png', 'path' => 'assets/Browse/single.png', 'file_size' => 5000]));
    $single->directories()->attach($this->folder->id);
    $url = '/'.config('app.admin_url').'/dam/explorer/data?directory_id='.$this->folder->id.'&sort_by=size&sort_order=';

    $ascending = $this->getJson($url.'asc')->assertOk()->json('assets');
    $stack = collect($ascending)->firstWhere('id', $this->asset->id);

    expect(array_column($ascending, 'id'))->toBe([$single->id, $this->asset->id])
        ->and($stack['attachment_size'])->toBe(6000)
        ->and(array_column($this->getJson($url.'desc')->assertOk()->json('assets'), 'id'))->toBe([$this->asset->id, $single->id]);
});
