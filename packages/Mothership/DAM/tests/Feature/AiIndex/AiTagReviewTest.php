<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Tag;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->asset = Asset::factory()->create(['file_name' => 'open-house.jpg']);
    DB::table('dam_ai_index')->insert(['asset_id' => $this->asset->id, 'status' => 'done', 'caption' => 'A crowd at an open house.', 'keywords' => json_encode(['crowd']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_tags')->insert([
        ['asset_id' => $this->asset->id, 'term' => 'campus-event', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

it('keeps AI suggestions out of shared tags and shows them in info', function () {
    $this->loginAsAdmin();

    $ai = $this->getJson(route('admin.dam.assets.info', $this->asset->id))->assertOk()->json('ai');

    expect($ai['caption'])->toBe('A crowd at an open house.')
        ->and(array_column($ai['tags'], 'label'))->toBe(['Campus event', 'Photo'])
        ->and(Tag::count())->toBe(0);
});

it('accepts a suggestion into the shared tag once, and dismiss hides another', function () {
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'campus-event'])->assertOk();
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'campus-event'])->assertStatus(422);
    $this->postJson(route('admin.dam.assets.ai-tags.dismiss', $this->asset->id), ['term' => 'photo'])->assertOk();

    expect($this->asset->fresh()->tags->pluck('name')->all())->toBe(['Campus event'])
        ->and(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->pluck('status', 'term')->all())
        ->toEqual(['campus-event' => 'accepted', 'photo' => 'dismissed']);
});

it('rejects terms outside the vocabulary and people without the tagging permission', function () {
    $this->loginAsAdmin();
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'lion'])->assertStatus(422);

    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    $this->actingAs($viewer, 'admin');
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertStatus(403);
});

it('answers a hidden asset like a missing one', function () {
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update', 'dam.asset.tag']])->id]);
    $this->actingAs($member, 'admin');

    $hidden = $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo']);
    $missing = $this->postJson(route('admin.dam.assets.ai-tags.accept', 999999), ['term' => 'photo']);

    expect($hidden->status())->toBe(404)->and($missing->status())->toBe(404);
});

it('refuses an accept after a dismiss and keeps the dismissal', function () {
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.assets.ai-tags.dismiss', $this->asset->id), ['term' => 'photo'])->assertOk();
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertStatus(422);

    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->where('term', 'photo')->value('status'))->toBe('dismissed')
        ->and($this->asset->fresh()->tags)->toHaveCount(0);
});

it('reuses an existing shared tag with different casing and drops the term from the returned list', function () {
    $this->loginAsAdmin();
    $existing = Tag::create(['name' => 'campus EVENT']);

    $ai = $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'campus-event'])->assertOk()->json('ai');

    expect(Tag::count())->toBe(1)
        ->and($this->asset->fresh()->tags->pluck('id')->all())->toBe([$existing->id])
        ->and(array_column($ai['tags'], 'term'))->toBe(['photo']);
});

it('leaves a hidden asset untouched and answers exactly like a missing one', function () {
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update', 'dam.asset.tag']])->id]);
    $this->actingAs($member, 'admin');
    // Debug pages embed the caller's line number; compare what production would send.
    config(['app.debug' => false]);

    $hidden = $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo']);
    $missing = $this->postJson(route('admin.dam.assets.ai-tags.accept', 999999), ['term' => 'photo']);

    expect($hidden->getContent())->toBe($missing->getContent())
        ->and(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->where('term', 'photo')->value('status'))->toBe('suggested')
        ->and(Tag::count())->toBe(0);
});
