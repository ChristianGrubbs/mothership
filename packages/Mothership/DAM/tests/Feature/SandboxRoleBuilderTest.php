<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('renders the same guided Sandbox controls on create and edit', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    foreach ([route('admin.settings.roles.create'), route('admin.settings.roles.edit', $role->id)] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('Sandbox')
            ->assertSee('Delete only files and folders they created')
            ->assertDontSee('Edit with GPT Image 2')
            ->assertSee('Repair panorama seams with SphereSeam')
            ->assertSee('Home widgets')
            ->assertSee('Advanced permissions');
    }
});

it('persists editable guided settings and expands Sandbox capabilities', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                     => 'Sandbox',
        'description'              => 'Editable coworker access',
        'permission_type'          => 'custom',
        'permissions'              => [],
        'dam_role_builder_managed' => '1',
        'dam_capabilities'         => ['library', 'metadata', 'image_editor', 'public_shares', 'delete_own', 'sphereseam'],
        'dam_home_widgets'         => ['recent', 'favorites', 'stylepress', 'sphereseam'],
    ])->assertRedirect();
    $role->refresh();

    expect($role->permissions)->toContain(
        'dam.assets',
        'dam.asset.property.update',
        'dam.asset.re_upload',
        'dam.asset.edit',
        'dam.asset.upload',
        'dam.asset.editor',
        'dam.asset.share',
        'dam.asset.destroy',
        'dam.directory.destroy',
        'sphereseam',
    );

    $settings = DB::table('dam_role_settings')->where('role_id', $role->id)->first();
    expect((bool) $settings->delete_own_only)->toBeTrue()
        ->and(json_decode($settings->home_widget_ids, true))->toBe(['recent', 'favorites', 'stylepress', 'sphereseam']);
});

it('grants every permission a selected tool widget declares once its capability is chosen', function () {
    // Each Home widget that declares a permission must be reachable through some Sandbox capability;
    // otherwise "Use Sandbox defaults" lists the widget but HomeWidgetRegistry never shows it.
    $declared = collect(config('dam-home.widgets'))
        ->reject(fn (array $widget) => (bool) ($widget['always_available'] ?? false))
        ->pluck('permission')->filter()->unique()->values();
    $granted = collect(RoleBuilder::CAPABILITIES)->flatten()->unique();

    expect($declared->diff($granted)->values()->all())->toBe([]);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                     => 'Sphereseam operator',
        'permission_type'          => 'custom',
        'permissions'              => [],
        'dam_role_builder_managed' => '1',
        'dam_capabilities'         => ['sphereseam'],
        'dam_home_widgets'         => ['sphereseam'],
    ])->assertRedirect();
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $catalog = $this->getJson('/admin/home/layout')->assertOk()->json('catalog');
    expect(array_column($catalog, 'id'))->toContain('sphereseam');
});

it('preserves partial advanced permissions during unrelated guided edits', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.edit'],
    ]);

    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                     => 'Specialist',
        'permission_type'          => 'custom',
        'permissions'              => ['dam.asset.edit'],
        'dam_role_builder_managed' => '1',
        'dam_capabilities'         => [],
        'dam_home_widgets'         => [],
    ])->assertRedirect();

    expect($role->fresh()->permissions)->toContain('dam.asset.edit');
});

it('autosaves tags as they are added without an assign confirmation', function () {
    $template = file_get_contents(__DIR__.'/../../src/Resources/views/components/tag/assign-modal.blade.php');

    expect($template)->toContain('Tags save as you add them.')
        ->and($template)->toContain('@select="assignSelectedTag"')
        ->and($template)->toContain('this.assign([value])')
        ->and($template)->not->toContain('@click="assign"');
});

it('clears managed restrictions when a role is promoted to all access', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    DB::table('dam_role_settings')->insert([
        'role_id'    => $role->id, 'delete_own_only' => true, 'home_widget_ids' => json_encode(['recent']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->put(route('admin.settings.roles.update', $role->id), [
        // Not "Administrator": role names are unique regardless of case (PERM-13), and the seeded admin role has it.
        'name' => 'Promoted to all access', 'permission_type' => 'all', 'permissions' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settings = DB::table('dam_role_settings')->where('role_id', $role->id)->first();
    expect((bool) $settings->delete_own_only)->toBeFalse()
        ->and($settings->home_widget_ids)->toBeNull();
});

it('ignores stale delete-own settings for all-access roles', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'delete_own_only' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $user = Admin::factory()->create(['role_id' => $role->id]);
    $foreignAsset = Asset::withoutEvents(fn () => Asset::factory()->create(['created_by' => Admin::factory()->create()->id]));
    $this->actingAs($user, 'admin');

    expect(app(RoleBuilder::class)->canDelete($foreignAsset))->toBeTrue();
});

it('filters the Home catalog to widgets selected for the role', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'home_widget_ids' => json_encode(['recent', 'links']),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $catalog = array_column($this->getJson('/admin/home/layout')->assertOk()->json('catalog'), 'id');
    $alwaysAvailable = array_keys(array_filter(config('dam-home.widgets'), fn (array $widget) => (bool) ($widget['always_available'] ?? false)));

    // Allowlisted widgets show, every other gated widget is dropped, and universal widgets ignore the list.
    expect($catalog)->toContain('recent', 'links')
        ->not->toContain('favorites', 'storage', 'stylepress')
        ->and(array_values(array_diff($catalog, ['recent', 'links'])))->toEqualCanonicalizing($alwaysAvailable);
});

it('blocks Sandbox deletion of files and directories created by someone else', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset', 'dam.asset.destroy', 'dam.directory', 'dam.directory.destroy'],
    ]);
    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'delete_own_only' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $owner = Admin::factory()->create();
    $sandboxUser = Admin::factory()->create(['role_id' => $role->id]);
    $directory = Directory::factory()->create(['created_by' => $owner->id]);
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['created_by' => $owner->id]));
    $asset->directories()->attach($directory->id);
    DB::table('dam_directory_role')->insert([
        'role_id' => $role->id, 'directory_id' => $directory->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($sandboxUser, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertForbidden();
    $this->deleteJson(route('admin.dam.directory.destroy', $directory->id))->assertForbidden();

    $ownDirectory = Directory::factory()->create(['created_by' => $sandboxUser->id]);
    $asset->directories()->attach($ownDirectory->id);
    expect(app(RoleBuilder::class)->canDeleteDirectoryTree($ownDirectory))->toBeFalse();

    $emptyOwnDirectory = Directory::factory()->create(['created_by' => $sandboxUser->id]);
    expect(app(RoleBuilder::class)->canDeleteDirectoryTree($emptyOwnDirectory))->toBeTrue();
});

it('names the permissions the capability coupling added or removed in the save response', function () {
    $base = ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view'];

    // Share keys ticked in the tree but the capability left off: the save drops them, and says so.
    $response = $this->postJson(route('admin.settings.roles.store'), [
        'name'                           => 'Coupled drop',
        'permission_type'                => 'custom',
        'permissions'                    => [...$base, 'dam.asset.share', 'dam.directory.share'],
        'dam_role_builder_managed'       => '1',
        'dam_capabilities'               => [],
        'dam_original_capabilities'      => ['public_shares'],
    ])->assertOk();

    expect($response->json('message'))->toContain('Removed: dam.asset.share, dam.directory.share')
        ->and(Role::query()->where('name', 'Coupled drop')->firstOrFail()->permissions)->not->toContain('dam.asset.share');

    // Capability ticked but the tree never submitted the keys: the save adds them, and says so.
    $response = $this->postJson(route('admin.settings.roles.store'), [
        'name'                      => 'Coupled add',
        'permission_type'           => 'custom',
        'permissions'               => $base,
        'dam_role_builder_managed'  => '1',
        'dam_capabilities'          => ['public_shares'],
        'dam_original_capabilities' => ['public_shares'],
    ])->assertOk();

    expect($response->json('message'))->toContain('Added: dam.asset.share, dam.directory.share');

    // Tree and capabilities already agree: the plain success message, no notice.
    $response = $this->postJson(route('admin.settings.roles.store'), [
        'name'                      => 'Coupled agree',
        'permission_type'           => 'custom',
        'permissions'               => [...$base, 'dam.asset.share', 'dam.directory.share'],
        'dam_role_builder_managed'  => '1',
        'dam_capabilities'          => ['public_shares'],
        'dam_original_capabilities' => ['public_shares'],
    ])->assertOk();

    expect($response->json('message'))->not->toContain('adjusted');
});

it('opens a new role with no capability ticked, so public links are opt-in, and keeps a child-only grant untouched on an unrelated save', function () {
    $this->get(route('admin.settings.roles.create'))->assertOk()
        ->assertDontSee('<input type="hidden" name="dam_original_capabilities[]"', false);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.duplicates.view']]);

    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                      => 'Child only',
        'description'               => 'edited',
        'permission_type'           => 'custom',
        'permissions'               => ['dam.duplicates.view'],
        'dam_role_builder_managed'  => '1',
        'dam_capabilities'          => [],
        'dam_original_capabilities' => [],
    ])->assertRedirect();

    expect($role->refresh()->permissions)->toBe(['dam.duplicates.view']);
});
