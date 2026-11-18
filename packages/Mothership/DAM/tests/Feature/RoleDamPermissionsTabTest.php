<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

/** Leaves the switch row at $model, as a finished `dam:access:switch` would. */
function roleDamPermissionsSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function dispatchRoleUpdateWith(array $payload, Role $role): void
{
    request()->replace($payload);

    Event::dispatch('user.role.update.after', [$role]);
}

it('syncs the submitted directory ids when the marker is present', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $dirOne = Directory::factory()->create();
    $dirTwo = Directory::factory()->create();

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$dirOne->id, $dirTwo->id],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($stored)->toEqualCanonicalizing([$dirOne->id, $dirTwo->id]);
});

it('leaves existing grants untouched when the marker is absent', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $existing = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $existing->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    dispatchRoleUpdateWith([
        'directories' => [],
    ], $role);

    expect(
        DB::table('dam_directory_role')->where('role_id', $role->id)->count()
    )->toBe(1);
});

it('falls back to the root directory when no selection is submitted', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first()
        ?? Directory::create(['name' => 'TestRoot', 'parent_id' => null]);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($stored)->toBe([$root->id]);
});

it('drops directory ids that no longer exist before insert', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $valid = Directory::factory()->create();
    $ghostId = $valid->id + 9_999;

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$valid->id, $ghostId],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($stored)->toBe([$valid->id]);
});

it('replaces prior grants on subsequent saves rather than appending', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $first = Directory::factory()->create();
    $second = Directory::factory()->create();

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$first->id],
    ], $role);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$second->id],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($stored)->toBe([$second->id]);
});

it('syncs grants on role create.after with the same listener semantics', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $dir = Directory::factory()->create();

    request()->replace([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$dir->id],
    ]);

    Event::dispatch('user.role.create.after', [$role]);

    expect(
        DB::table('dam_directory_role')->where('role_id', $role->id)->pluck('directory_id')->all()
    )->toEqualCanonicalizing([$dir->id]);
});

it('saves all_directories true in dam_role_settings when submitted', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_all_directories'          => '1',
        'directories'                  => [],
    ], $role);

    expect(
        DB::table('dam_role_settings')
            ->where('role_id', $role->id)
            ->where('all_directories', true)
            ->exists()
    )->toBeTrue();
});

it('clears all_directories in dam_role_settings when unchecked', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'all_directories' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_all_directories'          => '0',
        'directories'                  => [],
    ], $role);

    $setting = DB::table('dam_role_settings')->where('role_id', $role->id)->first();
    expect((bool) $setting?->all_directories)->toBeFalse();
});

it('saves inherit_children true in dam_role_settings when submitted', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_inherit_children'         => '1',
        'directories'                  => [],
    ], $role);

    expect(
        DB::table('dam_role_settings')
            ->where('role_id', $role->id)
            ->where('inherit_children', true)
            ->exists()
    )->toBeTrue();
});

it('clears inherit_children in dam_role_settings when unchecked', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);

    DB::table('dam_role_settings')->insert([
        'role_id'          => $role->id,
        'all_directories'  => false,
        'inherit_children' => true,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_inherit_children'         => '0',
        'directories'                  => [],
    ], $role);

    $setting = DB::table('dam_role_settings')->where('role_id', $role->id)->first();
    expect((bool) $setting?->inherit_children)->toBeFalse();
});

it('preserves explicit child grants when inherit_children strips expanded descendants', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $parent = Directory::create(['name' => 'PreserveParent', 'parent_id' => null]);
    $child = Directory::create(['name' => 'PreserveChild', 'parent_id' => $parent->id]);
    $grandchild = Directory::create(['name' => 'PreserveGrand', 'parent_id' => $child->id]);

    DB::table('dam_directory_role')->insert([
        ['directory_id' => $parent->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
        ['directory_id' => $child->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_inherit_children'         => '1',
        'directories'                  => [$parent->id, $child->id, $grandchild->id],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->sort()->values()->all();

    expect($stored)->toEqualCanonicalizing([$parent->id, $child->id]);
});

it('strips inherit-expanded descendants when no prior grants exist', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $root = Directory::create(['name' => 'StripFreshRoot', 'parent_id' => null]);
    $child = Directory::create(['name' => 'StripFreshChild', 'parent_id' => $root->id]);

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'dam_inherit_children'         => '1',
        'directories'                  => [$root->id, $child->id],
    ], $role);

    $stored = DB::table('dam_directory_role')
        ->where('role_id', $role->id)
        ->pluck('directory_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($stored)->toBe([$root->id]);
});

it('writes no dam_directory_role rows while the access model is lists', function () {
    roleDamPermissionsSetModel(AccessModel::LISTS);

    $role = Role::factory()->create(['permission_type' => 'custom']);
    $dir = Directory::factory()->create();

    dispatchRoleUpdateWith([
        'dam_directory_grants_managed' => '1',
        'directories'                  => [$dir->id],
    ], $role);

    expect(DB::table('dam_directory_role')->where('role_id', $role->id)->count())->toBe(0);

    roleDamPermissionsSetModel(AccessModel::GRANTS);
});

it('leaves dam_directory_role untouched on role create.after while the access model is lists', function () {
    roleDamPermissionsSetModel(AccessModel::LISTS);

    $role = Role::factory()->create(['permission_type' => 'custom']);
    $dir = Directory::factory()->create();

    request()->replace(['dam_directory_grants_managed' => '1', 'directories' => [$dir->id]]);
    Event::dispatch('user.role.create.after', [$role]);

    expect(DB::table('dam_directory_role')->where('role_id', $role->id)->count())->toBe(0);

    roleDamPermissionsSetModel(AccessModel::GRANTS);
});

it('renders no folder panel or tree script while the access model is lists, and keeps the Sandbox panel', function () {
    roleDamPermissionsSetModel(AccessModel::LISTS);

    $html = view('dam::admin.roles.dam-permissions-tab')->render();

    expect($html)->not->toContain('dam-directory-selection')
        ->not->toContain('dam-perm-tree-root')
        ->not->toContain('dam-all-directories-toggle')
        ->toContain('dam-capability-options')
        ->toContain('dam-home-widget-options');

    roleDamPermissionsSetModel(AccessModel::GRANTS);

    $html = view('dam::admin.roles.dam-permissions-tab')->render();

    expect($html)->toContain('dam-directory-selection')
        ->toContain('dam-perm-tree-root')
        ->toContain('dam-all-directories-toggle')
        ->toContain('dam-capability-options');
});

it('marks exactly the capabilities every human holds as always on, on role create and edit, from the enforcing list', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    $locked = e(json_encode(Admin::HUMAN_ALWAYS_ON_PERMISSIONS));

    foreach ([route('admin.settings.roles.create'), route('admin.settings.roles.edit', $role->id)] as $page) {
        $this->get($page)->assertOk()
            ->assertSee('locked-values="'.$locked.'"', false)
            ->assertSee('locked-label="Always on"', false)
            ->assertSee('data-always-on-note', false)
            ->assertSee('API integrations still need them granted explicitly');
    }

    // The integration (API key) screens do not lock anything: an API user is not granted these implicitly.
    $this->get(route('admin.configuration.integrations.create'))->assertOk()->assertDontSee('locked-values', false);
});

it('keeps the always-on list identical to what Admin::hasPermission grants a person and denies an API user', function () {
    $human = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => []])->id]);
    $api = Admin::factory()->create(['type' => 'api', 'role_id' => $human->role_id]);

    foreach (Admin::HUMAN_ALWAYS_ON_PERMISSIONS as $key) {
        expect($human->hasPermission($key))->toBeTrue("human lacks {$key}")
            ->and($api->hasPermission($key))->toBeFalse("API user holds {$key}");
    }

    // Triage of feedback is not always on: it stays a role grant.
    expect($human->hasPermission('dam.feedback.manage'))->toBeFalse()
        ->and(in_array('dam.feedback.manage', Admin::HUMAN_ALWAYS_ON_PERMISSIONS, true))->toBeFalse();
});
