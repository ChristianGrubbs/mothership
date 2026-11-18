<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

it('renders the profile page from the shared user edit view', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.account.edit'));

    $response->assertStatus(200);
    $response->assertViewIs('admin::settings.users.edit');
    $response->assertViewHas('canManage', false);
    $response->assertViewHas('requiresCurrentPassword', true);
});

it('hides the role and status controls on the profile page', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.account.edit'));

    $response->assertDontSee('name="role_id"', false);
    $response->assertDontSee('name="status"', false);
    $response->assertSee('name="current_password"', false);
});

it('renders the user edit page from the same view with the role controls', function () {
    $this->loginAsAdmin();

    $target = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);

    $response = $this->get(route('admin.settings.users.edit', $target->id));

    $response->assertStatus(200);
    $response->assertViewIs('admin::settings.users.edit');
    $response->assertViewHas('canManage', true);
    $response->assertViewHas('requiresCurrentPassword', false);
    $response->assertSee('name="role_id"', false);
    $response->assertDontSee('name="current_password"', false);
});

it('changes another user password without the acting admin password', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->loginAsAdmin();

    $target = Admin::factory()->create([
        'name'    => 'Before Reset',
        'role_id' => Role::factory()->create(['permission_type' => 'all'])->id,
    ]);

    $this->put(route('admin.settings.users.update'), [
        'id'                    => $target->id,
        'name'                  => 'After Reset',
        'email'                 => $target->email,
        'role_id'               => $target->role_id,
        'ui_locale_id'          => $target->ui_locale_id,
        'timezone'              => 'Asia/Kolkata',
        'password'              => 'NewPassw0rd',
        'password_confirmation' => 'NewPassw0rd',
    ])->assertSessionDoesntHaveErrors();

    $target->refresh();

    expect($target->name)->toBe('After Reset')
        ->and(Hash::check('NewPassw0rd', $target->password))->toBeTrue();
});

it('still requires the current password when an admin changes their own password', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $admin = $this->loginAsAdmin();

    $this->put(route('admin.settings.users.update'), [
        'id'                    => $admin->id,
        'name'                  => $admin->name,
        'email'                 => $admin->email,
        'role_id'               => $admin->role_id,
        'ui_locale_id'          => $admin->ui_locale_id,
        'timezone'              => 'Asia/Kolkata',
        'password'              => 'NewPassw0rd',
        'password_confirmation' => 'NewPassw0rd',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('NewPassw0rd', $admin->fresh()->password))->toBeFalse();
});

it('updates another user without the acting admin password when no new password is set', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->loginAsAdmin();

    $target = Admin::factory()->create([
        'name'    => 'Before Rename',
        'role_id' => Role::factory()->create(['permission_type' => 'all'])->id,
    ]);

    $this->put(route('admin.settings.users.update'), [
        'id'           => $target->id,
        'name'         => 'After Rename',
        'email'        => $target->email,
        'role_id'      => $target->role_id,
        'ui_locale_id' => $target->ui_locale_id,
        'timezone'     => 'Asia/Kolkata',
        'password'     => '',
    ]);

    expect($target->fresh()->name)->toBe('After Rename');
});

it('accepts a user update when the acting admin password is right', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->loginAsAdmin();

    $target = Admin::factory()->create([
        'name'    => 'Before Sudo',
        'role_id' => Role::factory()->create(['permission_type' => 'all'])->id,
    ]);

    $this->put(route('admin.settings.users.update'), [
        'id'               => $target->id,
        'name'             => 'After Sudo',
        'email'            => $target->email,
        'role_id'          => $target->role_id,
        'ui_locale_id'     => $target->ui_locale_id,
        'timezone'         => 'Asia/Kolkata',
        'password'         => '',
        'current_password' => 'password',
    ]);

    expect($target->fresh()->name)->toBe('After Sudo');
});

it('keeps the status switch off the user edit page when editing yourself', function () {
    $admin = $this->loginAsAdmin();

    $response = $this->get(route('admin.settings.users.edit', $admin->id));

    $response->assertStatus(200);
    $response->assertViewHas('isSelf', true);
    $response->assertViewHas('requiresCurrentPassword', true);
    $response->assertSee('name="role_id"', false);
    $response->assertSee('name="current_password"', false);
});
