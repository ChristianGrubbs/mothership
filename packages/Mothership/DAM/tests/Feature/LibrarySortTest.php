<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\User\Models\Admin;

/*
 * P4 (D11): the Library sort. The viewer's choice is kept on the server per admin (relevance and manual position
 * belong to one search or container and are never saved); manual position needs a label, board or section; a
 * section's default sort applies until the viewer picks one (SectionsTest). Popularity sits behind its flag and,
 * until analytics exist, scores every file 0, so it lists newest first.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->member = organizeMember($this->open);
    $this->old = organizeAsset($this->open, 'old');
    $this->new = organizeAsset($this->open, 'new');
    Asset::query()->whereKey($this->old->id)->update(['created_at' => now()->subWeek()]);
});

it('keeps each admin\'s sort choice on the server', function () {
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBeNull();

    $this->putJson(route('admin.dam.library.preferences.update'), ['sort' => 'name:asc'])->assertOk()->assertJsonPath('data.sort', 'name:asc');
    $this->putJson(route('admin.dam.library.preferences.update'), ['sort' => 'size:desc'])->assertOk();
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBe('size:desc');

    foreach (['position:asc', 'relevance:desc', 'popularity:desc', 'name'] as $refused) {
        $this->putJson(route('admin.dam.library.preferences.update'), ['sort' => $refused])->assertUnprocessable();
    }

    $other = Admin::factory()->create(['status' => 1, 'role_id' => $this->member->role_id]);
    $this->actingAs($other, 'admin');
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBeNull();

    $this->actingAs($this->member, 'admin');
    $this->putJson(route('admin.dam.library.preferences.update'), ['sort' => null])->assertOk();
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBeNull();
});

it('falls back to newest first for the position sort outside a label, board or section', function () {
    expect(organizeListedIds(['sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->new->id, $this->old->id]);
});

it('offers popularity only behind its flag, listing newest first until analytics exist', function () {
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'sort_by' => 'popularity']))->assertUnprocessable();
    expect($this->getJson(route('admin.dam.organize.index'))->json('popularity'))->toBeFalse();

    config(['dam.popularity_sort_enabled' => true]);

    expect(organizeListedIds(['sort_by' => 'popularity', 'sort_order' => 'desc']))->toBe([$this->new->id, $this->old->id])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('popularity'))->toBeTrue();
    $this->putJson(route('admin.dam.library.preferences.update'), ['sort' => 'popularity:desc'])->assertOk();
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBe('popularity:desc');

    // Turned off again, the saved popularity choice reads as no choice.
    config(['dam.popularity_sort_enabled' => false]);
    expect($this->getJson(route('admin.dam.organize.index'))->json('sort'))->toBeNull();
});
