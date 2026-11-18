<?php

beforeEach(function () {
    config(['mothership.pim_enabled' => false]);
    $this->loginAsAdmin();
});

it('defers thumbnails on the shared library cards', function () {
    $this->get(route('admin.dam.index'))
        ->assertOk()
        ->assertSee('v-dam-thumbnail="assetSrc"', false)
        ->assertSee("app.directive('dam-thumbnail'", false)
        ->assertDontSee(':src="assetSrc"', false);
});

it('renders an obvious and fully dismissible DAM asset preview', function () {
    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-dam-preview-backdrop', false)
        ->assertSee('@click.self="closePreviewWithInfoReset"', false)
        ->assertSee('data-dam-preview-back', false)
        ->assertSee('@click="closePreviewWithInfoReset"', false)
        ->assertSee('data-dam-preview-close', false)
        ->assertSee("e.key === 'Escape'", false)
        ->assertSee('videoStopOnClose();', false);
});

it('renders the Mothership lockup in the admin layout logo slot', function () {
    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-mothership-logo', false)
        ->assertSee('title="The Mothership"', false)
        ->assertSee('alt="The Mothership"', false)
        ->assertDontSee('alt="Mothership"', false);
});
