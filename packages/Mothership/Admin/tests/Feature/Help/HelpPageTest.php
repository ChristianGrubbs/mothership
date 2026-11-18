<?php

use Illuminate\Support\Facades\Route;

it('help route is registered and named', function () {
    expect(Route::has('admin.help.index'))->toBeTrue();
});

it('help page requires authentication', function () {
    $this->get(route('admin.help.index'))->assertRedirect();
});

it('renders the fork documentation heading and search', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.help.index'));

    $response->assertStatus(200);
    $response->assertSeeText(config('documentation.name').' help');
    $response->assertSee('id="documentation-search"', false);
    $response->assertSeeText('Search');
});
