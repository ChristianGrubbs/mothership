<?php

use Illuminate\Support\Facades\Hash;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

it('redirects a GET to the logout URL without logging the admin out', function () {
    $admin = Admin::factory()->create([
        'email'    => 'logout-get@example.com',
        'password' => Hash::make('password'),
        'status'   => 1,
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.session.destroy'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/logout');
    $this->assertAuthenticatedAs($admin, 'admin');

});

it('logs the admin out on a DELETE to the logout route', function () {
    $admin = Admin::factory()->create([
        'email'    => 'logout-delete@example.com',
        'password' => Hash::make('password'),
        'status'   => 1,
    ]);

    $response = $this->actingAs($admin, 'admin')->delete(route('admin.session.destroy'));

    $response->assertRedirect(route('admin.session.create'));
    $this->assertGuest('admin');
});

it('keeps an admin with no reachable landing page signed in on a GET to the logout URL', function () {
    // A stale key: non-empty (so Bouncer admits the admin) but granting no reachable route.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['retired.feature']]);
    $admin = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);

    // Nothing is reachable, so firstAllowedUrl() would take its log-out fallback.
    config(['menu.admin' => [], 'acl' => []]);

    $this->actingAs($admin, 'admin')->get(route('admin.session.logout.show'))->assertRedirect(route('admin.home.index'));

    $this->assertAuthenticatedAs($admin, 'admin');
});

it('sends a signed-out GET to the logout URL to the sign-in page', function () {
    $this->get(route('admin.session.logout.show'))->assertRedirect(route('admin.session.create'));
});
