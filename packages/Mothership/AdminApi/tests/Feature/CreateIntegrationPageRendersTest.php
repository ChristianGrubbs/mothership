<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Mothership\AdminApi\Models\Apikey;
use Mothership\User\Tests\Concerns\UserAssertions;

uses(UserAssertions::class);

it('renders the integration create page without an admin_id dropdown', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.configuration.integrations.create'));

    $response->assertOk();
});

it('offers the permission tree in the list-page create modal for the Custom type', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.configuration.integrations.index'));

    $response->assertOk()
        ->assertSee('<v-integration-create-fields>', false)
        ->assertSee('selected_permission_type == \'custom\'', false)
        ->assertSee('<v-tree-view', false)
        ->assertSee('&quot;key&quot;:&quot;api.dam&quot;', false);
});

it('leaves the create modal and its permission tree out for admins who cannot create integrations', function () {
    $this->loginWithPermissions(permissions: ['configuration', 'configuration.integrations']);

    $response = $this->get(route('admin.configuration.integrations.index'));

    $response->assertOk()
        ->assertDontSee('<v-integration-create-fields>', false)
        ->assertDontSee('<v-tree-view', false);
});

it('persists the permissions chosen for a Custom integration and reopens them without widening', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);

    $this->loginAsAdmin();

    $name = 'ZZ-Test-'.uniqid();

    $this->post(route('admin.configuration.integrations.store'), [
        'name'            => $name,
        'permission_type' => 'custom',
        'permissions'     => ['api', 'api.dam', 'api.dam.assets', 'api.dam.assets.embeds'],
    ])->assertRedirect();

    $key = Apikey::where('name', $name)->firstOrFail();

    expect($key->permission_type)->toBe('custom')
        ->and($key->permissions)->toBe(['api', 'api.dam', 'api.dam.assets', 'api.dam.assets.embeds'])
        ->and($key->hasPermission('api.dam.assets.embeds'))->toBeTrue()
        ->and($key->hasPermission('api.dam.assets.delete'))->toBeFalse();

    $this->get(route('admin.configuration.integrations.edit', $key->id))
        ->assertOk()
        ->assertSee('&quot;api.dam.assets.embeds&quot;', false);
});
