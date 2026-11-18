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
        ->assertSee('Your day')
        ->assertSee('Recent uploads')
        ->assertSee('Studio tools')
        ->assertSee('iPhone app')
        ->assertSee('Add to Home Screen')
        ->assertSee('data-calendar-dialog', false)
        ->assertSee('id="agh-home-search"', false)
        ->assertSee('data-start-key="upload"', false)
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

it('offers only the start buttons a role can use', function () {
    $this->get('/admin/home')->assertOk()
        ->assertSee('Upload files')
        ->assertSee('Browse all files')
        ->assertDontSee('logos and colors');

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->get('/admin/home')->assertOk()
        ->assertDontSee('data-start-key="upload"', false)
        ->assertDontSee('Browse all files')
        ->assertDontSee('id="agh-home-search"', false);
});

it('shows no Creative Hub apps now the in-app Hub is gone, even with dam.hub.enabled on', function () {
    config(['dam.hub.enabled' => true]);
    $this->get('/admin/home')->assertOk()
        ->assertDontSee('id="agh-hub-title"', false)
        ->assertDontSee('data-hub-apps', false);
});

it('offers sending files to a person only when Transfers is available', function () {
    config(['dam.transfers.enabled' => false]);
    $this->get('/admin/home')->assertOk()
        ->assertDontSee('data-start-key="send"', false)
        ->assertDontSee('data-block="send"', false);

    config(['dam.transfers.enabled' => true, 'dam.transfers.origin' => 'https://transfers.example.test', 'dam.transfers.shared_key' => str_repeat('k', 40)]);
    $this->get('/admin/home')->assertOk()
        ->assertSee('data-start-key="send"', false)
        ->assertSee('data-block="send"', false)
        ->assertSee('Send files to a person');
});

it('keeps the studio admin part for full-access accounts', function () {
    $this->get('/admin/home')->assertOk()->assertSee('Studio admin')->assertSee('data-slot="status"', false);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->get('/admin/home')->assertOk()->assertDontSee('Studio admin')->assertDontSee('data-slot="status"', false);
});
