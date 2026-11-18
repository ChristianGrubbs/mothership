<?php

use Illuminate\Support\Facades\Hash;
use Mothership\Core\Models\CoreConfig;
use Mothership\DAM\Models\Asset;
use Mothership\User\Models\Admin;

function setAgenticEnabled(string $value): void
{
    CoreConfig::query()->updateOrCreate(
        [
            'code'         => 'general.magic_ai.agentic_pim.enabled',
            'channel_code' => null,
            'locale_code'  => null,
        ],
        ['value' => $value],
    );
}

function loginAdmin(): Admin
{
    return Admin::factory()->create([
        'email'    => 'agentic-toggle@example.com',
        'password' => Hash::make('password-123'),
        'status'   => 1,
    ]);
}

it('does NOT render the agentic pim panel when the setting is disabled', function () {
    config(['mothership.pim_enabled' => true]);

    $admin = loginAdmin();

    setAgenticEnabled('0');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.dashboard.index'));

    $response->assertOk();
    $response->assertDontSee('v-agenting-pim', false);
});

it('does not render the agentic pim panel or its scripts in DAM when the PIM surface is disabled', function () {
    config(['mothership.pim_enabled' => false]);

    $admin = loginAdmin();
    $asset = Asset::factory()->create();

    setAgenticEnabled('1');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.dam.assets.edit', $asset->id));

    $response->assertOk();
    $response->assertDontSee('id="agenting-pim-panel"', false);
    $response->assertDontSee('id="v-agenting-pim-template"', false);
    $response->assertDontSee("app.component('v-agenting-pim'", false);
    // The DAM image editor is DAM-owned and mounts regardless of the PIM flag.
    $response->assertSee('imageEditorHost', false);
    $response->assertSee('loadDamImageEditor', false);
});

it('renders the agentic pim panel and its scripts in DAM when both PIM and the panel are enabled', function () {
    config(['mothership.pim_enabled' => true]);

    $admin = loginAdmin();
    $asset = Asset::factory()->create();

    setAgenticEnabled('1');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.dam.assets.edit', $asset->id));

    $response->assertOk();
    $response->assertSee('id="agenting-pim-panel"', false);
    $response->assertSee('id="v-agenting-pim-template"', false);
    $response->assertSee("app.component('v-agenting-pim'", false);
    $response->assertSee('imageEditorHost', false);
    $response->assertSee('loadDamImageEditor', false);
});
