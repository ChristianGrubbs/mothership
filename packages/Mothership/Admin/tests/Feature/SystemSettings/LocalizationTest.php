<?php

use Mothership\Core\Models\Locale;

// Only English UI translations ship (#106); a seeded locale without lang files falls back to en_US.
beforeEach(function () {
    $admin = $this->loginAsAdmin();
    $admin->update([
        'ui_locale_id' => Locale::query()->where('code', 'hi_IN')->value('id'),
    ]);
});

it('renders the appearance settings in en_US when the UI locale ships no translations', function () {
    $this->get(route('admin.settings.appearance.index'))
        ->assertOk()
        ->assertSee('lang="hi_IN"', false)
        ->assertSeeText('Appearance')
        ->assertSeeText('Customize how the admin panel looks by uploading your own logo and favicon.')
        ->assertSeeText('Recommended image resolution: 192px X 50px');
});

it('renders configuration fields in en_US when the UI locale ships no translations', function (string $path, array $english) {
    $response = $this->get(route('admin.settings.system.edit', $path))
        ->assertOk()
        ->assertSee('lang="hi_IN"', false);

    foreach ($english as $text) {
        $response->assertSee($text);
    }
})->with([
    'email' => [
        'system.email',
        ['Sender Name', 'Sender Email', 'Admin Name', 'SMTP Username'],
    ],
    'debug' => [
        'system.debug',
        ['Enable IP-based Debug', 'Allowed IP Addresses (comma-separated)'],
    ],
]);
