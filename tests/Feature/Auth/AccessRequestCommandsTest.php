<?php

use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminAccessRequest;
use Mothership\User\Models\AdminIdentity;
use Mothership\User\Models\Role;

function waitingRequest(array $overrides = []): AdminAccessRequest
{
    return AdminAccessRequest::query()->create(array_merge([
        'provider'       => 'google',
        'subject'        => 'g-'.uniqid(),
        'email'          => 'newcomer@example.test',
        'email_verified' => true,
        'name'           => 'New Comer',
        'status'         => AdminAccessRequest::STATUS_PENDING,
        'expires_at'     => now()->addHours(24),
    ], $overrides));
}

beforeEach(function () {
    config()->set('admin.auth.access_log', false);
});

it('lists open requests and leaves out expired and granted ones', function () {
    $open = waitingRequest(['email' => 'open@example.test']);
    waitingRequest(['email' => 'old@example.test', 'expires_at' => now()->subMinute()]);
    waitingRequest(['email' => 'done@example.test', 'status' => AdminAccessRequest::STATUS_GRANTED]);

    $this->artisan('mothership:access:list', ['--json' => true])
        ->expectsOutputToContain('open@example.test')
        ->doesntExpectOutputToContain('old@example.test')
        ->doesntExpectOutputToContain('done@example.test')
        ->assertSuccessful();

    expect($open->id)->toBeInt();
});

it('grants a request as a full admin linked to the provider account', function () {
    $request = waitingRequest();

    $this->artisan('mothership:access:grant', ['request' => $request->id, '--admin' => true])->assertSuccessful();

    $admin = Admin::query()->where('email', 'newcomer@example.test')->sole();

    expect($admin->role->permission_type)->toBe('all')
        ->and((int) $admin->status)->toBe(1)
        ->and($admin->password)->toBeNull()
        ->and(AdminIdentity::query()->where('admin_id', $admin->id)->sole()->subject)->toBe($request->subject)
        ->and($request->fresh()->status)->toBe(AdminAccessRequest::STATUS_GRANTED)
        ->and($request->fresh()->admin_id)->toBe($admin->id);
});

it('grants a request with a named role', function () {
    $role = Role::factory()->create(['name' => 'Viewer', 'permission_type' => 'custom', 'permissions' => ['dashboard']]);

    $request = waitingRequest();

    $this->artisan('mothership:access:grant', ['request' => $request->id, '--role' => 'Viewer'])->assertSuccessful();

    expect(Admin::query()->where('email', 'newcomer@example.test')->sole()->role_id)->toBe($role->id);
});

it('needs exactly one of --admin and --role', function () {
    $request = waitingRequest();

    $this->artisan('mothership:access:grant', ['request' => $request->id])->assertFailed();
    $this->artisan('mothership:access:grant', ['request' => $request->id, '--admin' => true, '--role' => 'Viewer'])->assertFailed();

    expect(Admin::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse();
});

it('refuses an unverified, expired or already granted request', function () {
    $unverified = waitingRequest(['email_verified' => false]);
    $expired = waitingRequest(['expires_at' => now()->subMinute()]);
    $granted = waitingRequest(['status' => AdminAccessRequest::STATUS_GRANTED]);

    foreach ([$unverified, $expired, $granted] as $request) {
        $this->artisan('mothership:access:grant', ['request' => $request->id, '--admin' => true])->assertFailed();
    }

    expect(Admin::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse();
});

it('refuses to create a second admin for an address that already has one, and links it instead', function () {
    $existing = Admin::factory()->create(['email' => 'newcomer@example.test']);

    $request = waitingRequest();

    $this->artisan('mothership:access:grant', ['request' => $request->id, '--admin' => true])
        ->expectsOutputToContain('mothership:access:link')
        ->assertFailed();

    $this->artisan('mothership:access:link', ['admin' => 'newcomer@example.test', 'request' => $request->id])->assertSuccessful();

    expect(AdminIdentity::query()->sole()->admin_id)->toBe($existing->id)
        ->and(Admin::query()->where('email', 'newcomer@example.test')->count())->toBe(1);
});

it('revokes provider links and deactivates on request', function () {
    $admin = Admin::factory()->create();

    AdminIdentity::query()->create(['admin_id' => $admin->id, 'provider' => 'google', 'subject' => 'g-revoke']);

    $this->artisan('mothership:access:revoke', ['admin' => (string) $admin->id, '--deactivate' => true])->assertSuccessful();

    expect(AdminIdentity::query()->count())->toBe(0)
        ->and((int) $admin->fresh()->status)->toBe(0);
});

it('will not deactivate the last active full admin without --force', function () {
    $fullRole = Role::query()->where('permission_type', 'all')->orderBy('id')->firstOrFail();

    Admin::query()->update(['status' => 0]);

    $last = Admin::factory()->create(['role_id' => $fullRole->id, 'status' => 1]);

    $this->artisan('mothership:access:revoke', ['admin' => (string) $last->id, '--deactivate' => true])->assertFailed();

    expect((int) $last->fresh()->status)->toBe(1);

    $this->artisan('mothership:access:revoke', ['admin' => (string) $last->id, '--deactivate' => true, '--force' => true])->assertSuccessful();

    expect((int) $last->fresh()->status)->toBe(0);
});
