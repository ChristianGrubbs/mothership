<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Web Pages serving audit.
 * Only probes that caught a real defect, plus at most one "no defect" case per audit item.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
});

function auditAdmin(array $directoryIds, array $permissions = ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view']): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    foreach ($directoryIds as $id) {
        DB::table('dam_directory_role')->insert(['directory_id' => $id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return $admin;
}

function auditPage(string $title, array $directoryIds): Asset
{
    $asset = Asset::factory()->create(['file_type' => 'web', 'file_name' => strtolower($title).'.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/'.strtolower($title).'.html', 'title' => $title]);
    $asset->directories()->sync($directoryIds);

    return $asset;
}

/** Builds a real bundle from a zip and returns the absolute bundle root (null when the build failed). */
function auditBundle(Asset $asset, array $files = []): ?string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'wpa').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (['index.html' => '<title>t</title>'] + $files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $assetPath = 'assets/Root/site-'.$asset->id.'.zip';
    // Written before the row is updated so the graph observer ingests it as the original (ADR
    // 0009): its path/mime/extension change together, which is what triggers a re-sync on update.
    Storage::disk(Directory::getAssetDisk())->put($assetPath, file_get_contents($zipPath));
    $asset->forceFill(['file_name' => 'site.zip', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => $assetPath])->save();
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    $root = WebPageBundler::rootFor($asset->fresh());

    return $root === null ? null : Storage::disk(Directory::getAssetDisk())->path($root);
}

it('A1: a page in two directories with only the second granted is openable, as it is listed', function () {
    $granted = Directory::create(['name' => 'Granted', 'parent_id' => 1]);
    $other = Directory::create(['name' => 'Other', 'parent_id' => 1]);
    auditAdmin([$granted->id]);
    $page = auditPage('Twodirs', [$other->id, $granted->id]);

    $body = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();
    expect(collect($body['pages']['data'])->pluck('id')->filter(fn ($id) => $id === $page->id))->toHaveCount(1);
    $this->get(route('admin.dam.pages.show', ['id' => $page->id]))->assertOk();
});

it('A1: a page in an ancestor-only viewable directory is neither listed nor openable', function () {
    $parent = Directory::create(['name' => 'Parent', 'parent_id' => 1]);
    $child = Directory::create(['name' => 'Child', 'parent_id' => $parent->id]);
    auditAdmin([$child->id]);
    $page = auditPage('Ancestorpage', [$parent->id]);

    $this->get(route('admin.dam.pages.index'))->assertOk()->assertDontSee('Ancestorpage');
    $this->get(route('admin.dam.pages.show', ['id' => $page->id]))->assertNotFound();
    $this->getJson(route('admin.dam.pages.grant', ['id' => $page->id]))->assertNotFound();
});

it('A2: revoked directory grant closes the entry within the TTL', function () {
    $dir = Directory::create(['name' => 'Granted2', 'parent_id' => 1]);
    $admin = auditAdmin([$dir->id]);
    $page = auditPage('Grantpage', [$dir->id]);
    auditBundle($page);

    $url = $this->getJson(route('admin.dam.pages.grant', ['id' => $page->id]))->assertOk()->json('url');
    getRawUrl($url)->assertOk();

    DB::table('dam_directory_role')->where('role_id', $admin->role_id)->delete();
    app(DirectoryPermissionService::class)->flush();

    getRawUrl($url)->assertNotFound();
});

it('A3: rejects encoded traversal, backslash, NUL, symlinks and case-folded paths', function () {
    $this->loginAsAdmin();
    $page = auditPage('Resolver', [1]);
    $root = auditBundle($page, ['docs/app.js' => 'x']);
    symlink(dirname($root, 3), $root.'/link');
    symlink($root.'/docs', $root.'/inner');
    $url = rtrim($this->getJson(route('admin.dam.pages.grant', ['id' => $page->id]))->json('url'), '/');

    foreach (['%252e%252e/%252e%252e/index.html', 'docs%5C..%5C..%5Cindex.html', 'docs/app.js%00.html', 'link/index.html', 'inner/app.js', 'DOCS/app.js', 'docs/../index.html'] as $probe) {
        expect(getRawUrl($url.'/'.$probe)->getStatusCode())->toBe(404, $probe);
    }
    getRawUrl($url.'/docs/app.js')->assertOk();
});

it('A6: deleting the asset removes its bundle and a failed build leaves no partial tree', function () {
    $this->loginAsAdmin();
    $page = auditPage('Lifecycle', [1]);
    $root = auditBundle($page);
    expect(is_dir($root))->toBeTrue();

    app(AssetDeletionService::class)->delete($page->fresh());
    expect(is_dir(dirname($root)))->toBeFalse();

    $broken = auditPage('Broken', [1]);
    auditBundle($broken, ['x.php' => '<?php']);
    expect(Storage::disk(Directory::getAssetDisk())->allFiles("bundles/{$broken->id}"))->toBe([])
        ->and($broken->fresh()->meta_data['web_bundle']['error'] ?? null)->toBe('forbidden_entry:x.php');
});

it('A7: copy-share accepts a page reachable through its second directory and answers an ungranted one like a missing one', function () {
    $granted = Directory::create(['name' => 'Sharegranted', 'parent_id' => 1]);
    $other = Directory::create(['name' => 'Shareother', 'parent_id' => 1]);
    auditAdmin([$granted->id], ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view', 'dam.asset.share']);
    $twoDirs = auditPage('Sharetwo', [$other->id, $granted->id]);
    $ungranted = auditPage('Shareno', [$other->id]);

    $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => 'asset', 'target_id' => $twoDirs->id, 'public_link_confirmed' => true])->assertOk();
    $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => 'asset', 'target_id' => $ungranted->id, 'public_link_confirmed' => true])->assertNotFound();
});

it('A3: a bundle root replaced by a symlink to an outside directory is not served', function () {
    $this->loginAsAdmin();
    $page = auditPage('Rootlink', [1]);
    $root = auditBundle($page);
    $outside = Storage::disk(Directory::getAssetDisk())->path('outside-'.$page->id);
    rename($root, $outside);
    symlink($outside, $root);
    $url = $this->getJson(route('admin.dam.pages.grant', ['id' => $page->id]))->json('url');

    expect(getRawUrl($url)->getStatusCode())->toBe(404);
    unlink($root);
    (new Filesystem)->deleteDirectory($outside);
});

it('A2: a disabled admin loses the entry on the next request', function () {
    $dir = Directory::create(['name' => 'Disabledgrant', 'parent_id' => 1]);
    $admin = auditAdmin([$dir->id]);
    $page = auditPage('Disabledpage', [$dir->id]);
    auditBundle($page);
    $url = $this->getJson(route('admin.dam.pages.grant', ['id' => $page->id]))->assertOk()->json('url');
    getRawUrl($url)->assertOk();

    DB::table('admins')->where('id', $admin->id)->update(['status' => 0]);

    getRawUrl($url)->assertNotFound();
});
