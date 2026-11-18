<?php

use Mothership\Core\Models\Locale;

it('renders the system information page with runtime details', function () {
    $this->loginAsAdmin();

    $this->get(route('admin.configuration.system.information'))
        ->assertOk()
        ->assertSee(trans('admin::app.help.system-info.title'))
        ->assertSee(PHP_VERSION)
        ->assertSee(app()->version())
        ->assertSee(core()->version())
        ->assertSee('framework');
});

// Only English UI translations ship (#106); a seeded locale without lang files falls back to en_US.
it('renders the system information page in en_US when the UI locale ships no translations', function () {
    $admin = $this->loginAsAdmin();
    $admin->update([
        'ui_locale_id' => Locale::query()->where('code', 'hi_IN')->value('id'),
    ]);

    $this->get(route('admin.configuration.system.information'))
        ->assertOk()
        ->assertSee('lang="hi_IN"', false)
        ->assertSeeText('System Information')
        ->assertSeeText('Application')
        ->assertSeeText('Operating System');
});

it('denies the system information page without permission', function () {
    $this->loginWithPermissions(permissions: ['dashboard']);

    $this->get(route('admin.configuration.system.information'))->assertForbidden();
});
