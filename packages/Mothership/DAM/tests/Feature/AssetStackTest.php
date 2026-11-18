<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Stacks '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'menu-front.png', 'file_type' => 'image', 'extension' => 'png']));
    $this->asset->directories()->attach($this->folder->id);
    $this->stackUrl = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/stack';
    $this->explorer = '/'.config('app.admin_url').'/dam/explorer/data?directory_id='.$this->folder->id;
});

$member = function (object $test, array $extra, bool $access) {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index', ...$extra]]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $test->folder->id, 'role_id' => $role->id]);
    }

    $test->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();
};

it('sets a stack basis with a trimmed key, changes it, and clears both', function () {
    $this->putJson($this->stackUrl, ['basis' => 'campaign', 'key' => '  Fall 2026 enrollment  '])
        ->assertOk()->assertJson(['stack' => ['basis' => 'campaign', 'key' => 'Fall 2026 enrollment']]);
    expect($this->asset->fresh()->only(['stack_basis', 'stack_key']))->toBe(['stack_basis' => 'campaign', 'stack_key' => 'Fall 2026 enrollment']);

    // The change lands in the asset's History trail.
    $audited = DB::table('audits')->where('auditable_type', Asset::class)->where('auditable_id', $this->asset->id)
        ->pluck('new_values')->map(fn ($values) => json_decode((string) $values, true) ?? []);
    expect($audited->contains(fn ($values) => ($values['stack_key'] ?? null) === 'Fall 2026 enrollment'))->toBeTrue();

    $this->putJson($this->stackUrl, ['basis' => 'product', 'key' => 'SKU 1042'])->assertOk()->assertJson(['stack' => ['basis' => 'product', 'key' => 'SKU 1042']]);

    // Clearing the basis clears the key even when a key is sent.
    $this->putJson($this->stackUrl, ['basis' => null, 'key' => 'ignored'])->assertOk()->assertJson(['stack' => ['basis' => null, 'key' => null]]);
    expect($this->asset->fresh()->only(['stack_basis', 'stack_key']))->toBe(['stack_basis' => null, 'stack_key' => null]);
});

it('refuses an unknown basis, a basis without a key, and an over-long key', function (array $body) {
    $this->putJson($this->stackUrl, $body)->assertUnprocessable();
    expect($this->asset->fresh()->stack_basis)->toBeNull();
})->with([
    'unknown basis'   => [['basis' => 'season', 'key' => 'Fall']],
    'missing key'     => [['basis' => 'campaign']],
    'blank key'       => [['basis' => 'campaign', 'key' => '   ']],
    'over-long key'   => [['basis' => 'product', 'key' => str_repeat('k', 192)]],
    'basis not sent'  => [['key' => 'Fall']],
]);

it('answers a hidden asset like a missing one, and refuses a visible asset without edit rights', function () use ($member) {
    $member($this, ['dam.asset.edit'], access: false);
    $this->putJson($this->stackUrl, ['basis' => 'campaign', 'key' => 'Fall'])->assertNotFound();
    $this->putJson('/'.config('app.admin_url').'/dam/assets/999999999/stack', ['basis' => 'campaign', 'key' => 'Fall'])->assertNotFound();

    $member($this, [], access: true);
    $this->putJson($this->stackUrl, ['basis' => 'campaign', 'key' => 'Fall'])->assertForbidden();
    expect($this->asset->fresh()->stack_basis)->toBeNull();
});

it('lists a stack once with its basis, key and stacked-file count, leaving out versions and previews', function () {
    $this->asset->forceFill(['stack_basis' => 'product', 'stack_key' => 'Bistro menu'])->save();

    foreach (['original', 'alternate', 'source', 'version', 'proxy', 'thumbnail'] as $role) {
        AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => $role]);
    }

    $assets = $this->getJson($this->explorer)->assertOk()->json('assets');

    expect($assets)->toHaveCount(1)
        ->and($assets[0])->toMatchArray(['stack_basis' => 'product', 'stack_key' => 'Bistro menu', 'attachment_count' => 3]);
});

it('sends the stacked-file count with the preview payload and the details page, counted like the Library badge', function () {
    foreach (['original', 'alternate', 'source', 'version', 'proxy', 'thumbnail'] as $role) {
        AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => $role]);
    }
    $admin = '/'.config('app.admin_url').'/dam/assets';

    // The Library lightbox and the details page's prev/next read this; without it the strip cannot tell a stack from a file.
    $this->getJson($admin.'/show/'.$this->asset->id)->assertOk()->assertJsonPath('attachmentCount', 3);
    $this->get(route('admin.dam.assets.edit', $this->asset->id))->assertOk()->assertSee('"attachment_count":3', false);
});

it('counts a stack\'s files once per request, on the details page and in the preview payload', function () {
    $counts = 0;
    DB::listen(function ($query) use (&$counts) {
        if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'dam_asset_files') && str_contains($query->sql, 'is_current') && str_contains($query->sql, 'not in')) {
            $counts++;
        }
    });

    $this->get(route('admin.dam.assets.edit', $this->asset->id))->assertOk();
    expect($counts)->toBe(1);

    $counts = 0;
    $this->getJson('/'.config('app.admin_url').'/dam/assets/show/'.$this->asset->id)->assertOk()->assertJsonStructure(['stackFileCount', 'attachmentCount']);
    expect($counts)->toBe(1);
});

it('opens the Files tab for ?files, with or without the file the preview was showing riding along', function () {
    $edit = route('admin.dam.assets.edit', $this->asset->id);

    foreach (['?files', '?files&file=2', '?files&nav=abc&file=2'] as $query) {
        $this->get($edit.$query)->assertOk()->assertSee('<v-dam-asset-files', false);
    }

    $this->get($edit)->assertOk()->assertDontSee('<v-dam-asset-files', false);
});

it('filters on any stack, one basis, and a case-insensitive part of the key', function () {
    $this->asset->forceFill(['stack_basis' => 'campaign', 'stack_key' => 'Fall 2026 Enrollment'])->save();
    $other = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'poster.png', 'file_type' => 'image', 'extension' => 'png', 'stack_basis' => 'use_case', 'stack_key' => 'Social media']));
    $plain = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'plain.png', 'file_type' => 'image', 'extension' => 'png']));
    $other->directories()->attach($this->folder->id);
    $plain->directories()->attach($this->folder->id);

    $ids = fn (string $query) => collect($this->getJson($this->explorer.$query)->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

    expect($ids('&filter_stack=any'))->toBe(collect([$this->asset->id, $other->id])->sort()->values()->all())
        ->and($ids('&filter_stack=use_case'))->toBe([$other->id])
        ->and($ids('&filter_stack_key=enroll'))->toBe([$this->asset->id])
        ->and($ids('&filter_stack=use_case&filter_stack_key=enroll'))->toBe([])
        ->and($ids(''))->toHaveCount(3);

    $this->getJson($this->explorer.'&filter_stack=season')->assertUnprocessable();
});

it('finds a stack by its key in the Library word search', function () {
    $this->asset->forceFill(['stack_basis' => 'product', 'stack_key' => 'Bistro menu'])->save();
    config(['ai-index.search_enabled' => false]);

    expect(collect($this->getJson($this->explorer.'&search=bistro')->assertOk()->json('assets'))->pluck('id')->all())->toBe([$this->asset->id]);
});

it('gives the Files tab the stack and whether this viewer may change it', function () use ($member) {
    $this->asset->forceFill(['stack_basis' => 'use_case', 'stack_key' => 'Signage'])->save();
    $filesUrl = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files';

    $this->getJson($filesUrl)->assertOk()->assertJson(['stack' => ['basis' => 'use_case', 'key' => 'Signage'], 'can_edit' => true]);

    $member($this, [], access: true);
    $this->getJson($filesUrl)->assertOk()->assertJson(['stack' => ['basis' => 'use_case', 'key' => 'Signage'], 'can_edit' => false]);
});

it('keeps history files: removing a version, or a file a version or output points at, answers 422', function () {
    $version = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'version']);
    $referenced = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'alternate']);
    AssetVersion::factory()->create(['dam_asset_id' => $this->asset->id, 'primary_asset_file_id' => $referenced->id]);
    $source = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'source']);
    Rendition::create(['dam_asset_id' => $this->asset->id, 'source_asset_file_id' => $source->id, 'recipe' => 'test', 'parameters' => [], 'worker' => 'test', 'worker_version' => '1', 'status' => Rendition::STATUS_DONE]);
    $filesUrl = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files/';

    foreach ([$version, $referenced, $source] as $file) {
        $this->deleteJson($filesUrl.$file->id)->assertUnprocessable()->assertJsonPath('message', trans('dam::app.admin.dam.asset.files.history-protected'));
        expect(AssetFile::whereKey($file->id)->exists())->toBeTrue();
    }
});
