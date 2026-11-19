<?php

use Illuminate\Support\Facades\DB;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Home is one fixed page. Nothing is saved per person; a layout stored while
 * Home was customizable is kept and only its Quick links are read.
 */

beforeEach(function () {
    $this->homeAdmin = Admin::factory()->create(['name' => 'Marcus Staff']);
    $this->actingAs($this->homeAdmin, 'admin');
});

it('serves the fixed sections with nothing to customize', function () {
    $this->get('/admin/home')->assertOk()
        ->assertSee('Arrivals board')
        ->assertSee('Cargo')
        ->assertSee('Tools')
        ->assertSee('Calendar')
        ->assertSee('iPhone app')
        ->assertSee('Add to Home Screen')
        ->assertSee('data-calendar-dialog', false)
        ->assertDontSee('Customize Home')
        ->assertDontSee('data-customize', false);
});

it('gives every account the widgets its role may use and no layout to save', function () {
    $layout = $this->getJson('/admin/home/layout')->assertOk()->assertJsonMissingPath('revision')->json();

    expect(array_column($layout['widgets'], 'id'))->toEqual(array_column($layout['catalog'], 'id'))
        ->and($layout['links'])->toBe([])
        ->and(collect($layout['widgets'])->firstWhere('id', 'recent')['settings'])->toBe(['limit' => 12]);

    $this->putJson('/admin/home/layout', ['revision' => 0, 'widgets' => []])->assertStatus(405);
    $this->deleteJson('/admin/home/layout', ['revision' => 0])->assertStatus(405);
    expect(DB::table('dam_home_layouts')->count())->toBe(0);
});

it('keeps saved Quick links readable and never rewrites the stored layout', function () {
    $stored = json_encode([
        ['id' => 'rss', 'size' => 'wide', 'settings' => ['feed_id' => 12]],
        ['id' => 'links', 'size' => 'small', 'settings' => ['links' => [
            ['title' => 'Print shop order form', 'url' => 'https://example.edu/print'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)'],
            ['title' => '', 'url' => 'https://example.edu/untitled'],
            'not a link',
        ]]],
    ]);
    DB::table('dam_home_layouts')->insert(['admin_id' => $this->homeAdmin->id, 'widgets' => $stored, 'revision' => 3, 'created_at' => now(), 'updated_at' => now()]);

    $layout = $this->getJson('/admin/home/layout')->assertOk()->json();

    expect($layout['links'])->toBe([['title' => 'Print shop order form', 'url' => 'https://example.edu/print']])
        // The stored arrangement no longer decides what is on the page.
        ->and(array_column($layout['widgets'], 'id'))->toContain('calendar', 'recent')
        ->and(DB::table('dam_home_layouts')->where('admin_id', $this->homeAdmin->id)->value('widgets'))->toBe($stored);

    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson('/admin/home/layout')->assertOk()->assertJsonPath('links', []);
});

it('survives a stored layout that is not valid JSON', function () {
    if (DB::getDriverName() === 'pgsql') {
        // PostgreSQL refuses invalid JSON in a json column (SQLSTATE 22P02), so this state cannot be stored there.
        $this->markTestSkipped('PostgreSQL cannot store invalid JSON in dam_home_layouts.widgets.');
    }
    DB::table('dam_home_layouts')->insert(['admin_id' => $this->homeAdmin->id, 'widgets' => '{not json', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/admin/home/layout')->assertOk()->assertJsonPath('links', []);
});

it('offers only the links and upload a role can use', function () {
    $this->get('/admin/home')->assertOk()
        ->assertSee('class="bridge-btn-card bridge-top-upload', false)
        ->assertSee('data-library-allowed="1"', false)
        ->assertSee('data-shares="'.route('admin.dam.shared-links.index').'"', false)
        ->assertSee('data-requests="'.route('admin.dam.upload-requests.index').'"', false);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->get('/admin/home')->assertOk()
        ->assertDontSee('class="bridge-btn-card bridge-top-upload', false)
        ->assertSee('data-library-allowed="0"', false)
        ->assertSee('data-shares=""', false)
        ->assertSee('data-requests=""', false)
        ->assertSee('data-new-uploads=""', false);
});

it('shows no Creative Hub apps now the in-app Hub is gone, even with dam.hub.enabled on', function () {
    config(['dam.hub.enabled' => true]);
    $this->get('/admin/home')->assertOk()
        ->assertDontSee('id="agh-hub-title"', false)
        ->assertDontSee('data-hub-apps', false);
});

it('leaves Transfers and the Icon Library to their own places, with no link on Home', function () {
    config([
        'dam.transfers.enabled'     => true,
        'dam.transfers.origin'      => 'https://transfers.example.test',
        'dam.transfers.shared_key'  => str_repeat('k', 40),
        'dam-home.icon_library_url' => 'https://icons.example.org',
    ]);

    // Transfers is on the rail and the Icon Library on the Library page.
    $this->get('/admin/home')->assertOk()
        ->assertDontSee('<span>Send files</span>', false)
        ->assertDontSee('data-block="send"', false)
        ->assertDontSee('https://icons.example.org/', false);
});

it('keeps every tool launcher reachable from the Tools shelf', function () {
    config(['dam-home.video_api' => 'https://video.example.test']);

    $this->get('/admin/home')->assertOk()
        ->assertSee('data-tools', false)
        ->assertSee('data-calendar-open', false)
        ->assertSee('data-phone-open', false);

    $downloader = collect($this->getJson('/admin/home/layout')->assertOk()->json('catalog'))->firstWhere('id', 'video-downloader');

    expect($downloader['kind'])->toBe('tool')->and($downloader['url'])->not->toBeEmpty();
});
