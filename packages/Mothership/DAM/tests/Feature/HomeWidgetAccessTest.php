<?php

// The Home right-click widget-access menu writes role permissions, so the gate and the
// permission side effect are what these cover.

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

function widgetAccessRole(array $permissions = [], ?array $widgets = null): Role
{
    $role = Role::create([
        'name'            => 'Widget access '.uniqid(),
        'description'     => 'Fixture role',
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    if ($widgets !== null) {
        DB::table('dam_role_settings')->updateOrInsert(
            ['role_id' => $role->id],
            ['home_widget_ids' => json_encode($widgets), 'delete_own_only' => false, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

function widgetIdsFor(Role $role): array
{
    $stored = DB::table('dam_role_settings')->where('role_id', $role->id)->value('home_widget_ids');

    return json_decode((string) $stored, true) ?: [];
}

function rolePermissions(Role $role): array
{
    $value = $role->fresh()->permissions;

    return is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;
}

it('refuses a non-superuser on both endpoints', function () {
    $this->actingAs(Admin::factory()->create(['role_id' => widgetAccessRole(['dam.assets'])->id]), 'admin');

    $this->getJson('/admin/home/widget-access/rss')->assertForbidden();
    $this->putJson('/admin/home/widget-access/rss', ['role_id' => 1, 'enabled' => true])->assertForbidden();
});

it('lists custom roles with their current state for a widget', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $on = widgetAccessRole([], ['rss']);
    $off = widgetAccessRole([], []);

    $roles = collect($this->getJson('/admin/home/widget-access/rss')->assertOk()->json('roles'))->keyBy('id');

    expect($roles[$on->id]['enabled'])->toBeTrue();
    expect($roles[$off->id]['enabled'])->toBeFalse();
    // Full-access roles are never gated by the widget list, so a checkbox for them would be a no-op.
    expect($roles->has(1))->toBeFalse();
});

it('enables a widget for a role', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole([], []);

    $this->putJson('/admin/home/widget-access/trending', ['role_id' => $role->id, 'enabled' => true])->assertOk();

    expect(widgetIdsFor($role))->toContain('trending');
});

it('grants the permission a widget declares when enabling it', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole([], []);

    $this->putJson('/admin/home/widget-access/sphereseam', ['role_id' => $role->id, 'enabled' => true])->assertOk();

    expect(widgetIdsFor($role))->toContain('sphereseam');
    // Without this the widget is listed and still 403s inside HomeWidgetRegistry::allowed().
    expect(rolePermissions($role))->toContain('sphereseam');
});

it('removes the widget on disable but never revokes the permission', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole(['dam.assets'], ['recent', 'favorites']);

    $this->putJson('/admin/home/widget-access/recent', ['role_id' => $role->id, 'enabled' => false])->assertOk();

    expect(widgetIdsFor($role))->not->toContain('recent');
    expect(widgetIdsFor($role))->toContain('favorites');
    // Revoking dam.assets here would silently break that role's whole DAM surface.
    expect(rolePermissions($role))->toContain('dam.assets');
});

it('rejects a widget id that is not in the catalogue', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole([], []);

    $this->putJson('/admin/home/widget-access/not-a-widget', ['role_id' => $role->id, 'enabled' => true])->assertStatus(422);
    expect(widgetIdsFor($role))->toBe([]);
});

it('refuses role toggles for universally available widgets', function (string $widget) {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole([], ['rss']);

    $this->getJson('/admin/home/widget-access/'.$widget)->assertStatus(422);
    $this->putJson('/admin/home/widget-access/'.$widget, ['role_id' => $role->id, 'enabled' => false])->assertStatus(422);

    expect(widgetIdsFor($role))->toBe(['rss']);
})->with(['loop-maker', 'sound-effects', 'system-quickview', 'plaque']);

// A custom role with NO dam_role_settings row has the DEFAULT widget set (every tool except the paid ones):
// roleWidgetIds() falls back to HomeWidgetRegistry::defaultWidgetIds(). Writing a naive singleton on the
// first toggle would silently revoke the rest, which is the regression these two cover.

it('keeps every other widget when the first toggle lands on a role with no settings row', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole(['dam.assets']);
    expect(DB::table('dam_role_settings')->where('role_id', $role->id)->exists())->toBeFalse();

    $this->putJson('/admin/home/widget-access/trending', ['role_id' => $role->id, 'enabled' => true])->assertOk();

    // Exact membership and order, not just cardinality: a change that stored twelve *wrong* ids,
    // or swapped one for another, would still satisfy a count assertion.
    expect(widgetIdsFor($role))->toBe(HomeWidgetRegistry::defaultWidgetIds());
});

it('removes only the named widget when disabling against a role with no settings row', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole(['dam.assets']);

    $this->putJson('/admin/home/widget-access/recent', ['role_id' => $role->id, 'enabled' => false])->assertOk();

    expect(widgetIdsFor($role))->toBe(array_values(array_diff(HomeWidgetRegistry::defaultWidgetIds(), ['recent'])));
});

it('reports a role with no settings row as already having the widget', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole(['dam.assets']);

    $roles = collect($this->getJson('/admin/home/widget-access/rss')->assertOk()->json('roles'))->keyBy('id');

    expect($roles[$role->id]['enabled'])->toBeTrue();
});

it('refuses to toggle a full-access role, which the widget list never gates', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $all = Role::create(['name' => 'All access '.uniqid(), 'description' => 'Fixture', 'permission_type' => 'all', 'permissions' => null]);

    $this->putJson('/admin/home/widget-access/rss', ['role_id' => $all->id, 'enabled' => true])->assertStatus(422);
});

it('never lists a full-access role', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $all = Role::create(['name' => 'All access '.uniqid(), 'description' => 'Fixture', 'permission_type' => 'all', 'permissions' => null]);

    $ids = collect($this->getJson('/admin/home/widget-access/rss')->assertOk()->json('roles'))->pluck('id');

    expect($ids)->not->toContain($all->id);
});

it('refuses an unauthenticated request', function () {
    $response = $this->getJson('/admin/home/widget-access/rss');

    expect(in_array($response->status(), [401, 302], true))->toBeTrue();
});

it('reads a JSON null widget list as no widgets, exactly as the gate does', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $role = widgetAccessRole([], []);
    // A JSON `null` literal, not SQL NULL. HomeWidgetRegistry reads this as deny-all, so the editor
    // must agree: seeding the catalogue here would hand the role every widget on the next toggle.
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['home_widget_ids' => 'null']);

    $roles = collect($this->getJson('/admin/home/widget-access/rss')->assertOk()->json('roles'))->keyBy('id');
    expect($roles[$role->id]['enabled'])->toBeFalse();

    $this->putJson('/admin/home/widget-access/rss', ['role_id' => $role->id, 'enabled' => true])->assertOk();

    expect(widgetIdsFor($role))->toBe(['rss']);
});

it('makes the widget usable by that role, not merely listed', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    // An explicit empty list: this role can use nothing today, and lacks the sphereseam permission.
    $role = widgetAccessRole([], []);

    $this->putJson('/admin/home/widget-access/sphereseam', ['role_id' => $role->id, 'enabled' => true])->assertOk();

    // Act AS that role. This is what proves the gate opens: the stored id and the auto-granted
    // permission together have to satisfy HomeWidgetRegistry::catalog(), which HomeLayoutService::read
    // uses to build this response, applying both the widget-list and the permission check.
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->pluck('id');

    expect($catalog)->toContain('sphereseam');
});

// Paid tools are opt-in: a new custom role must not get StylePress, SphereSeam or Image Vectorization by default.

it('marks exactly the paid tools and leaves them out of the default widget set', function () {
    $paid = collect(config('dam-home.widgets'))->filter(fn (array $widget) => $widget['paid'] ?? false)->keys()->all();

    expect($paid)->toEqualCanonicalizing(['stylepress', 'sphereseam', 'vectorization'])
        ->and(HomeWidgetRegistry::defaultWidgetIds())->not->toContain(...$paid)
        ->and(HomeWidgetRegistry::defaultWidgetIds())->toContain('looper', 'rss', 'trending', 'loop-maker');
});

it('gives a custom role with no settings row the default tools and not the paid ones', function () {
    $role = widgetAccessRole(['dam.assets', 'sphereseam']);
    expect(DB::table('dam_role_settings')->where('role_id', $role->id)->exists())->toBeFalse();
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->pluck('id');

    expect($catalog)->toContain('looper', 'rss')
        ->and($catalog)->not->toContain('stylepress', 'sphereseam', 'vectorization');
    $this->get('/admin/home/tools/stylepress/index.html')->assertForbidden();
});

it('treats a stored SQL NULL list on a custom role the same as no row', function () {
    $role = widgetAccessRole(['dam.assets'], []);
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['home_widget_ids' => null]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->pluck('id');

    expect($catalog)->not->toContain('stylepress', 'vectorization');
});

it('leaves a full-access role ungated, paid tools included', function () {
    $all = Role::create(['name' => 'All access '.uniqid(), 'description' => 'Fixture', 'permission_type' => 'all', 'permissions' => null]);
    $this->actingAs(Admin::factory()->create(['role_id' => $all->id]), 'admin');

    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->pluck('id');

    expect($catalog)->toContain('vectorization', 'sphereseam');
});

it('still lets an admin tick a paid tool for a role explicitly', function () {
    $role = widgetAccessRole(['dam.assets'], ['recent', 'vectorization']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $catalog = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->pluck('id');

    expect($catalog)->toContain('vectorization');
});

it('pre-ticks only the default tools when the role editor opens for a role with no list', function () {
    $this->loginAsAdmin();
    $role = Role::factory()->create(['permission_type' => 'custom']);

    $html = $this->get(route('admin.settings.roles.edit', $role->id))->assertOk()->getContent();

    foreach (HomeWidgetRegistry::defaultWidgetIds() as $id) {
        expect($html)->toMatch('/name="dam_home_widgets\[\]"\s+value="'.preg_quote($id, '/').'"[^>]*checked/s');
    }
    foreach (['stylepress', 'sphereseam', 'vectorization'] as $id) {
        expect($html)->not->toMatch('/name="dam_home_widgets\[\]"\s+value="'.$id.'"[^>]*checked/s');
    }
});
