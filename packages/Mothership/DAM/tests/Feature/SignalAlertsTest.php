<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Services\SignalAlerts;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * The per-account SIGNAL announcement strip (My Account > AI announcements): off unless the account chose it or is
 * one of the configured exceptions, and the account's own choice always wins.
 */
beforeEach(function () {
    // The exceptions the product ships with, spelled out so a change to them has to touch this file too.
    config(['dam-home.signal_default_on' => [
        ['id' => 2, 'email' => 'first@example.test'],
        ['id' => 3, 'email' => 'second@example.test'],
        ['id' => 1, 'email' => 'admin@mothership.local'],
    ]]);

    $this->signalAlerts = app(SignalAlerts::class);

    // An unsaved identity is enough for the pure rule; the rendered cases below use saved accounts.
    $this->identity = fn (int $id, string $email, ?bool $saved = null): Admin => (new Admin)->forceFill(['id' => $id, 'email' => $email, 'signal_alerts' => $saved]);

    $this->strip = fn (string $html): array => [substr_count($html, 'data-signal-ticker'), substr_count($html, 'ticker.css'), substr_count($html, 'ticker.js')];

    $this->accountPayload = fn (Admin $admin, array $extra = []): array => [
        'name'         => $admin->name,
        'email'        => $admin->email,
        'timezone'     => 'UTC',
        'ui_locale_id' => $admin->ui_locale_id,
    ] + $extra;
});

it('keeps the column nullable with no default, so existing accounts are untouched', function () {
    $column = collect(Schema::getColumns('admins'))->firstWhere('name', 'signal_alerts');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and($column['default'])->toBeNull();

    expect(Admin::factory()->create()->fresh()->signal_alerts)->toBeNull();
});

it('is off for an ordinary account and on only for the three exact id-and-email exceptions', function (int $id, string $email, bool $expected) {
    expect($this->signalAlerts->enabledFor(($this->identity)($id, $email)))->toBe($expected);
})->with([
    'First account'                              => [2, 'first@example.test', true],
    'Second account'                             => [3, 'second@example.test', true],
    'Example demo account'                       => [1, 'admin@mothership.local', true],
    'email case and padding normalised'          => [2, '  First@Example.TEST ', true],
    'First id, second email'                     => [2, 'second@example.test', false],
    'First email, reused id'                     => [9, 'first@example.test', false],
    'Second email, reused id'                    => [4, 'second@example.test', false],
    'Example id, installer admin email'          => [1, 'admin@example.com', false],
    'Example email, reused id'                   => [5, 'admin@mothership.local', false],
    'ordinary user'                              => [7, 'someone@example.com', false],
    'an exception id with an empty mail'         => [2, '', false],
]);

it('lets an explicit choice override the fallback in both directions', function () {
    expect($this->signalAlerts->enabledFor(($this->identity)(7, 'someone@example.com', true)))->toBeTrue()
        ->and($this->signalAlerts->enabledFor(($this->identity)(2, 'first@example.test', false)))->toBeFalse()
        ->and($this->signalAlerts->enabledFor(($this->identity)(1, 'admin@mothership.local', false)))->toBeFalse()
        ->and($this->signalAlerts->enabledFor(null))->toBeFalse();
});

it('renders no SIGNAL strip, assets or bell for a new or existing ordinary account, and keeps other notifications', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    foreach (['/admin/home', route('admin.dam.assets.index')] as $page) {
        $html = $this->get($page)->assertOk()->getContent();

        expect(($this->strip)($html))->toBe([0, 0, 0])
            ->and($html)->not->toContain('Announcement alerts')
            ->and($html)->not->toContain('signal-label')
            // The operational notification bell is a different control and stays.
            ->and($html)->toContain('<v-notifications');
    }
});

it('renders the strip for an exception account until it opts out, and for an ordinary account once it opts in', function () {
    $exception = Admin::factory()->create();
    config(['dam-home.signal_default_on' => [['id' => $exception->id, 'email' => $exception->email]]]);

    $this->actingAs($exception, 'admin');
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([1, 1, 1]);

    // Opting out wins over the exception, and the account is not "reset" by a later request that omits the field.
    $exception->update(['signal_alerts' => false]);
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([0, 0, 0]);

    // The same email under another id never inherits the exception.
    $lookalike = Admin::factory()->create(['email' => 'LOOKALIKE@example.com']);
    config(['dam-home.signal_default_on' => [['id' => $exception->id, 'email' => 'lookalike@example.com']]]);
    $this->actingAs($lookalike, 'admin');
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([0, 0, 0]);

    $lookalike->update(['signal_alerts' => true]);
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([1, 1, 1]);
});

it('still withholds the strip from a role without the RSS tool even when the account opted in', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent","links"]']);

    $this->actingAs(Admin::factory()->create(['role_id' => $role->id, 'signal_alerts' => true]), 'admin');

    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([0, 0, 0]);
});

it('saves the choice on the signed-in account only, and a request that omits the field keeps it', function () {
    $me = Admin::factory()->create();
    $other = Admin::factory()->create(['signal_alerts' => false]);
    $this->actingAs($me, 'admin');
    $payload = ($this->accountPayload)($me);

    $this->put(route('admin.account.update'), $payload + ['signal_alerts' => 1])->assertOk();
    expect($me->fresh()->signal_alerts)->toBeTrue();
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([1, 1, 1]);

    // An older client (or a role that has no switch) sends no field: the saved choice is untouched.
    $this->put(route('admin.account.update'), $payload)->assertOk();
    expect($me->fresh()->signal_alerts)->toBeTrue();

    $this->put(route('admin.account.update'), $payload + ['signal_alerts' => 0])->assertOk();
    expect($me->fresh()->signal_alerts)->toBeFalse();
    $this->put(route('admin.account.update'), $payload)->assertOk();
    expect($me->fresh()->signal_alerts)->toBeFalse();
    expect(($this->strip)($this->get('/admin/home')->assertOk()->getContent()))->toBe([0, 0, 0]);

    // Naming another account in the body changes nothing about it: the target is always the session's own account.
    $this->put(route('admin.account.update'), $payload + ['signal_alerts' => 1, 'id' => $other->id, 'admin_id' => $other->id])->assertOk();
    expect($other->fresh()->signal_alerts)->toBeFalse()
        ->and($me->fresh()->signal_alerts)->toBeTrue();
});

it('rejects a value that is not a boolean and leaves the saved choice alone', function (mixed $value) {
    $me = Admin::factory()->create(['signal_alerts' => true]);
    $this->actingAs($me, 'admin');

    $this->putJson(route('admin.account.update'), ($this->accountPayload)($me, ['signal_alerts' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signal_alerts');

    expect($me->fresh()->signal_alerts)->toBeTrue();
})->with(['text' => 'maybe', 'null' => null, 'list' => [[1]]]);

it('does not let a signed-out visitor or an administrator editing another user set the choice', function () {
    $target = Admin::factory()->create(['signal_alerts' => false]);

    auth('admin')->logout();
    $this->putJson(route('admin.account.update'), ['name' => 'x', 'email' => $target->email, 'timezone' => 'UTC', 'ui_locale_id' => 1, 'signal_alerts' => 1])
        ->assertUnauthorized();
    expect($target->fresh()->signal_alerts)->toBeFalse();

    // The users screen has no such field: a full administrator sending it for someone else changes nothing.
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->putJson(route('admin.settings.users.update'), [
        'id'            => $target->id,
        'name'          => 'Target Person',
        'email'         => $target->email,
        'role_id'       => $target->role_id,
        'ui_locale_id'  => $target->ui_locale_id,
        'timezone'      => 'UTC',
        'status'        => 1,
        'password'      => '',
        'signal_alerts' => 1,
    ])->assertOk();
    expect($target->fresh()->signal_alerts)->toBeFalse();
});

it('shows the switch only on the own account page of a role that may use the RSS tool, reflecting the effective value', function () {
    $me = Admin::factory()->create();
    $this->actingAs($me, 'admin');

    $off = $this->get(route('admin.account.edit'))->assertOk()->assertSee('data-signal-alerts-switch', false)->assertSee('AI announcements');
    expect($off->getContent())->toMatch('/name="signal_alerts"[^>]*value="1"(?![^>]*checked)/s');

    $me->update(['signal_alerts' => true]);
    $on = $this->get(route('admin.account.edit'))->assertOk()->getContent();
    expect($on)->toContain('data-signal-alerts-switch');

    // Editing someone else from the users screen is not "my account": no switch there.
    $this->get(route('admin.settings.users.edit', ['id' => Admin::factory()->create()->id]))->assertOk()->assertDontSee('data-signal-alerts-switch', false);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent","links"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->get(route('admin.account.edit'))->assertOk()->assertDontSee('data-signal-alerts-switch', false);
});

it('offers the switch on My Account but not on the administrator\'s own record in the Users screen, whose save drops it', function () {
    $admin = Admin::factory()->create();
    $this->actingAs($admin, 'admin');

    $this->get(route('admin.settings.users.edit', ['id' => $admin->id]))->assertOk()->assertDontSee('data-signal-alerts-switch', false);
    $this->get(route('admin.account.edit'))->assertOk()->assertSee('data-signal-alerts-switch', false);

    $this->put(route('admin.account.update'), ($this->accountPayload)($admin, ['signal_alerts' => 1]))->assertOk();
    expect($admin->fresh()->signal_alerts)->toBeTrue();
});
