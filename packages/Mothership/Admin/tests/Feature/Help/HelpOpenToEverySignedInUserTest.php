<?php

use Mothership\Admin\Helpers\SupportContact;

it('serves Help to a user whose role holds no help permission', function () {
    $this->loginWithPermissions(permissions: ['dam.asset']);

    $this->get(route('admin.help.index'))->assertOk();
    $this->get(route('admin.help.docs.show', ['slug' => 'getting-started']))->assertOk();
});

it('keeps Help behind sign-in and other gated routes behind their permission', function () {
    $this->get(route('admin.help.index'))->assertRedirect();

    $this->loginWithPermissions(permissions: ['dam.asset']);
    $this->get(route('admin.settings.roles.index'))->assertForbidden();
});

it('shows Help in the sidebar for a role holding no help permission', function () {
    $this->loginWithPermissions(permissions: ['dam.asset']);

    $this->get(route('admin.help.index'))->assertOk()->assertSee('href="'.route('admin.help.index').'"', false);
});

it('keeps the API reference and its downloads for administrators', function () {
    $this->loginWithPermissions(permissions: ['dam.asset']);

    $this->get(route('admin.help.index'))->assertOk()->assertDontSee(route('admin.help.api'), false);
    $this->get(route('admin.help.api'))->assertForbidden();
    $this->get(route('admin.help.api.download', ['file' => 'routes.json']))->assertForbidden();
});

it('serves the API reference and downloads to an administrator', function () {
    $this->loginAsAdmin();

    $this->get(route('admin.help.index'))->assertOk()->assertSee(route('admin.help.api'), false);
    $this->get(route('admin.help.api'))->assertOk();
    $this->get(route('admin.help.api.download', ['file' => 'routes.json']))->assertOk();
});

it('offers a contact address on the 403 page only when it is deliverable', function (?string $email, bool $deliverable) {
    expect(SupportContact::isDeliverable(email: $email))->toBe($deliverable);
})->with([
    'local'     => ['admin@mothership.local', false],
    'test'      => ['ops@example.test', false],
    'example'   => ['a@host.example', false],
    'invalid'   => ['a@host.invalid', false],
    'localhost' => ['a@host.localhost', false],
    'empty'     => ['', false],
    'null'      => [null, false],
    'real'      => ['help@studio.mothership.app', true],
]);
