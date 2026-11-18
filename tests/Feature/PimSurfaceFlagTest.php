<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mothership\Admin\Sso\SsoManager;
use Mothership\Admin\Tests\Support\FakeSsoProvider;
use Mothership\Core\Models\CoreConfig;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminIdentity;

function getAdminMenuItems(): array
{
    app()->forgetScopedInstances();

    return app('mothership.admin.menu')['tree']->items ?? [];
}

function pimSystemSettingKeys(): array
{
    return [
        'system.measurement',
    ];
}

function dashboardStatisticTypes(): array
{
    return [
        'total-catalogs',
        'total-configurations',
        'product-stats',
        'recent-activity',
        'data-transfer-status',
        'needs-attention',
        'channel-readiness',
    ];
}

describe('when pim_enabled is false (default)', function () {
    beforeEach(function () {
        config(['mothership.pim_enabled' => false]);
    });

    it('returns 403 for product routes when logged in as admin', function () {
        $this->loginAsAdmin();

        $this->get(route('admin.catalog.products.index'))->assertForbidden();
    });

    it('returns 403 for other PIM routes when logged in as admin', function () {
        $this->loginAsAdmin();

        $this->get(route('admin.catalog.categories.index'))->assertForbidden();
        $this->get(route('admin.catalog.attributes.index'))->assertForbidden();
        $this->get(route('admin.catalog.families.index'))->assertForbidden();
        $this->get(route('admin.settings.data_transfer.tracker.index'))->assertForbidden();
        $this->get(route('admin.measurement.families.index'))->assertForbidden();
        $this->get(route('ai-agent.agents.index'))->assertForbidden();
        $this->get(route('admin.magic_ai.platform.index'))->assertForbidden();
    });

    it('returns 403 for PIM write routes when logged in as admin', function () {
        $this->loginAsAdmin();

        $this->post(route('admin.catalog.categories.store'))->assertForbidden();
        $this->post(route('admin.catalog.attributes.store'))->assertForbidden();
        $this->post(route('admin.catalog.families.store'))->assertForbidden();
        $this->post(route('admin.settings.data_transfer.imports.store'))->assertForbidden();
    });

    it('rejects the legacy Magic AI configuration POST URL', function () {
        $this->loginAsAdmin();

        $this->post(url(config('app.admin_url').'/configuration/general/magic_ai'), [
            'general' => ['magic_ai' => ['image_generation' => ['enabled' => '1']]],
        ])->assertForbidden();
    });

    it('rejects all AI-backed DAM background POST routes but keeps local routes reachable', function () {
        $this->loginAsAdmin();

        foreach ([
            'admin.dam.assets.image_edit.bg_color',
            'admin.dam.assets.image_edit.bg_upload',
            'admin.dam.assets.image_edit.bg_ai',
        ] as $routeName) {
            $this->post(route($routeName, ['id' => 999999]))->assertForbidden();
        }

        $this->post(route('admin.dam.assets.image_edit.bg_preview', ['id' => 999999]))
            ->assertNotFound();
        $this->post(route('admin.dam.assets.image_edit.bg_color_normal', ['id' => 999999]))
            ->assertNotFound();
    });

    it('forbids every supported dashboard statistics query', function () {
        $this->loginAsAdmin();

        foreach (dashboardStatisticTypes() as $type) {
            $this->getJson(route('admin.dashboard.stats', ['type' => $type]))->assertForbidden();
        }
    });

    it('omits PIM-owned settings and denies their direct GET and PUT routes', function () {
        $this->loginAsAdmin();

        $hub = $this->get(route('admin.settings.system.index'))->assertOk();

        foreach (pimSystemSettingKeys() as $key) {
            $hub->assertDontSee(route('admin.settings.system.edit', ['key' => $key]), false);
            $this->get(route('admin.settings.system.edit', ['key' => $key]))->assertForbidden();
            $this->put(route('admin.settings.system.update', ['key' => $key]))->assertForbidden();
        }
    });

    it('mounts the image editor and permits its document in DAM-only mode', function () {
        $this->loginAsAdmin();
        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $file = UploadedFile::fake()->image('editor.png', 100, 100);
        $path = 'assets/editor.png';
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
        $asset = Asset::factory()->create(['extension' => 'png', 'file_type' => 'image', 'path' => $path]);
        $this->get(route('admin.dam.assets.edit', ['id' => $asset->id]))
            ->assertOk()
            ->assertSee('imageEditorHost', false)
            ->assertSee('loadDamImageEditor', false);
        $this->getJson(route('admin.dam.assets.editor.document', $asset->id))
            ->assertOk()->assertJsonPath('document.schemaVersion', 1);
    });

    it('hides shared media AI controls from the non-PIM Appearance page', function () {
        CoreConfig::query()->updateOrCreate(
            ['code' => 'general.magic_ai.image_generation.enabled', 'channel_code' => null, 'locale_code' => null],
            ['value' => '1'],
        );

        $this->loginAsAdmin();

        $this->get(route('admin.settings.appearance.index'))
            ->assertOk()
            ->assertSee('data-media-ai-enabled="false"', false);
    });

    it('discards an intended PIM URL after classic login', function () {
        $admin = Admin::factory()->create([
            'email'    => 'classic-pim-intended@example.com',
            'password' => Hash::make('password'),
            'status'   => 1,
        ]);

        $this->withSession(['url.intended' => route('admin.catalog.products.index')])
            ->post(route('admin.session.store'), [
                'email'    => $admin->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.home.index'));
    });

    it('discards an intended PIM URL after JSON login', function () {
        $admin = Admin::factory()->create([
            'email'    => 'json-pim-intended@example.com',
            'password' => Hash::make('password'),
            'status'   => 1,
        ]);

        $this->withSession(['url.intended' => route('admin.catalog.products.index')])
            ->postJson(route('admin.session.store'), [
                'email'    => $admin->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('redirect_url', route('admin.home.index'));
    });

    it('discards a bookmarked PIM URL before completing SSO login', function () {
        FakeSsoProvider::$enabled = true;
        FakeSsoProvider::$email = 'sso-pim-intended@example.com';
        config()->set('sso.providers.fake', FakeSsoProvider::class);
        app()->forgetInstance(SsoManager::class);

        $admin = Admin::factory()->create([
            'email'  => FakeSsoProvider::$email,
            'status' => 1,
        ]);

        AdminIdentity::query()->create([
            'admin_id' => $admin->id,
            'provider' => 'fake',
            'subject'  => 'fake-subject-'.FakeSsoProvider::$email,
        ]);

        $this->withHeader('Referer', route('admin.catalog.products.index'))
            ->get(route('admin.session.create'))
            ->assertOk();

        expect(session('url.intended'))->toBeNull();

        $this->get(route('admin.session.sso.redirect', ['provider' => 'fake']));
        $state = session('sso_handshake.fake.state');

        $this->get(route('admin.session.sso.callback', [
            'provider' => 'fake',
            'state'    => $state,
            'code'     => 'auth-code',
        ]))->assertRedirect(route('admin.home.index'));
    });

    it('filters out PIM keys from the admin menu payload and keeps DAM', function () {
        $this->loginAsAdmin();

        $menu = getAdminMenuItems();

        expect($menu)->not->toHaveKey('catalog')
            ->and($menu)->not->toHaveKey('data_transfer')
            ->and($menu)->not->toHaveKey('ai-agent')
            ->and($menu)->toHaveKey('dam');
    });

    it('redirects GET /admin to Home for logged in admin', function () {
        $this->loginAsAdmin();

        $this->get(url(config('app.admin_url')))->assertRedirect(route('admin.home.index'));
    });

    it('redirects GET /admin/dashboard to Home', function () {
        $this->loginAsAdmin();

        $this->get(route('admin.dashboard.index'))->assertRedirect(route('admin.home.index'));
    });
});

describe('when pim_enabled is true', function () {
    beforeEach(function () {
        config(['mothership.pim_enabled' => true]);
    });

    it('returns 200 for product routes when logged in as admin', function () {
        $this->loginAsAdmin();

        $this->get(route('admin.catalog.products.index'))->assertOk();
    });

    it('includes catalog and products in the admin menu payload', function () {
        $this->loginAsAdmin();

        $menu = getAdminMenuItems();

        expect($menu)->toHaveKey('catalog');
        expect($menu['catalog']['children'] ?? [])->toHaveKey('products');
    });

    it('renders the dashboard when pim_enabled is true', function () {
        $this->loginAsAdmin();

        $this->get(route('admin.dashboard.index'))->assertOk();
    });

    it('allows every supported dashboard statistics query', function () {
        $this->loginAsAdmin();

        foreach (dashboardStatisticTypes() as $type) {
            $this->getJson(route('admin.dashboard.stats', ['type' => $type]))->assertOk();
        }
    });

    it('allows the legacy Magic AI configuration POST URL', function () {
        $this->loginAsAdmin();

        $this->from(route('admin.magic_ai.settings.index'))
            ->post(url(config('app.admin_url').'/configuration/general/magic_ai'))
            ->assertRedirect(route('admin.magic_ai.settings.index'));
    });

    it('lets AI-backed DAM background routes reach their request validation', function () {
        $this->loginAsAdmin();

        foreach ([
            'admin.dam.assets.image_edit.bg_color',
            'admin.dam.assets.image_edit.bg_upload',
            'admin.dam.assets.image_edit.bg_ai',
        ] as $routeName) {
            $this->post(route($routeName, ['id' => 999999]))->assertNotFound();
        }
    });

    it('shows PIM-owned settings and allows their direct GET and PUT routes', function () {
        $this->loginAsAdmin();

        $hub = $this->get(route('admin.settings.system.index'))->assertOk();

        foreach (pimSystemSettingKeys() as $key) {
            $hub->assertSee(route('admin.settings.system.edit', ['key' => $key]), false);
            $this->get(route('admin.settings.system.edit', ['key' => $key]))->assertOk();

            expect($this->put(route('admin.settings.system.update', ['key' => $key]))->status())
                ->not->toBe(403);
        }
    });

    it('shows shared media AI controls on Appearance when both gates allow them', function () {
        CoreConfig::query()->updateOrCreate(
            ['code' => 'general.magic_ai.image_generation.enabled', 'channel_code' => null, 'locale_code' => null],
            ['value' => '1'],
        );

        $this->loginAsAdmin();

        $this->get(route('admin.settings.appearance.index'))
            ->assertOk()
            ->assertSee('data-media-ai-enabled="true"', false);
    });

    it('preserves an intended PIM URL after login', function () {
        $admin = Admin::factory()->create([
            'email'    => 'enabled-pim-intended@example.com',
            'password' => Hash::make('password'),
            'status'   => 1,
        ]);

        $this->withSession(['url.intended' => route('admin.catalog.products.index')])
            ->post(route('admin.session.store'), [
                'email'    => $admin->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.catalog.products.index'));
    });
});
