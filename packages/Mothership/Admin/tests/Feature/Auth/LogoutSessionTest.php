<?php

use Illuminate\Support\Facades\Hash;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->admin = Admin::factory()->create([
        'email'    => 'logout-session@example.com',
        'password' => Hash::make('password'),
        'status'   => 1,
    ]);

    $this->post(route('admin.session.store'), ['email' => 'logout-session@example.com', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->admin, 'admin');
});

it('gives the browser a new session id on logout and leaves the old id signed out', function () {
    $oldId = session()->getId();

    $this->withCookies([config('session.cookie') => $oldId])->delete(route('admin.session.destroy'))
        ->assertRedirect(route('admin.session.create'));

    expect(session()->getId())->not->toBe($oldId);

    // A signed-in admin is sent Home from the sign-in page; a guest is shown it.
    auth()->forgetGuards();
    $this->withCookies([config('session.cookie') => $oldId])->get(route('admin.session.create'))->assertOk();
    $this->assertGuest('admin');
});

it('keeps the browser signed out when a request in flight on the old id writes its session back after logout', function () {
    $oldId = session()->getId();
    $handler = app('session')->driver()->getHandler();
    $signedInPayload = $handler->read($oldId);

    $this->withCookies([config('session.cookie') => $oldId])->delete(route('admin.session.destroy'))->assertRedirect();
    $newId = session()->getId();

    // The file and database handlers do not lock: a thumbnail request that started before logout ends after it
    // and saves the signed-in payload it read under the old id.
    $handler->write($oldId, $signedInPayload);

    auth()->forgetGuards();
    $this->withCookies([config('session.cookie') => $newId])->get(route('admin.session.create'))->assertOk();
    $this->assertGuest('admin');
});

it('answers a signed-out json request with 401 and a message, and a signed-out page load with the sign-in page (FAULT-06)', function () {
    $this->delete(route('admin.session.destroy'))->assertRedirect();
    auth()->forgetGuards();

    $this->getJson(route('admin.session.token'))
        ->assertUnauthorized()
        ->assertJson(['message' => trans('admin::app.requests.session')]);

    $this->get(route('admin.dam.index'))->assertRedirect(route('admin.session.create'));
    expect(session('url.intended'))->toBe(route('admin.dam.index'));
});

it('hands a signed-in page its current csrf token for the session check', function () {
    $this->getJson(route('admin.session.token'))
        ->assertOk()
        ->assertJson(['token' => session()->token()]);
});

it('signs out an admin whose permissions were all revoked, answering the session check with 401 and a page with sign-in', function () {
    $this->admin->forceFill(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => []])->id])->save();
    auth()->forgetGuards();

    // The probe must not get a 302 its XHR would follow to a 200 sign-in page that reads as "restored".
    $this->getJson(route('admin.session.token'))
        ->assertUnauthorized()
        ->assertJson(['message' => trans('admin::app.requests.session')]);
    $this->assertGuest('admin');

    $this->post(route('admin.session.store'), ['email' => 'logout-session@example.com', 'password' => 'password']);
    $this->get(route('admin.dashboard.index'))->assertRedirect(route('admin.session.create'));
    $this->assertGuest('admin');
});

it('still lets an admin with permissions through the session check', function () {
    $this->admin->forceFill(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id])->save();
    auth()->forgetGuards();

    $this->getJson(route('admin.session.token'))->assertOk()->assertJson(['token' => session()->token()]);
    $this->assertAuthenticatedAs($this->admin, 'admin');
});
