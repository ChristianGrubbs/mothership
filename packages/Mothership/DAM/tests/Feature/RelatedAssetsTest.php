<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P4 D14 related assets: a live ranking from shared tags (IDF), properties, file stem, folder, stack key and the AI
 * vector, only ever among what the viewer may list, without the seed's stack members or explicit relationships.
 */

function relatedTag(string $name, Asset ...$assets): Tag
{
    $tag = Tag::query()->firstOrCreate(['name' => $name]);
    $tag->assets()->syncWithoutDetaching(array_map(fn (Asset $asset) => $asset->id, $assets));

    return $tag;
}

function relatedVector(Asset $asset, array $vector): void
{
    DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode(VectorCodec::normalize($vector)), 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<int, array<string, mixed>> keyed by id, in rank order */
function relatedFor(Asset|int $seed, array $query = []): array
{
    $id = $seed instanceof Asset ? $seed->id : $seed;
    $items = test()->getJson(route('admin.dam.assets.related', ['asset' => $id] + $query))->assertOk()->json('data.related');

    return collect($items)->keyBy('id')->all();
}

function relatedSignals(array $item): array
{
    return array_column($item['reasons'], 'signal');
}

beforeEach(function () {
    $this->folder = Directory::factory()->create(['name' => 'Campaigns', 'parent_id' => null]);
    $this->elsewhere = Directory::factory()->create(['name' => 'Elsewhere', 'parent_id' => null]);
    $this->make = function (string $fileName, ?Directory $folder = null, array $attributes = []): Asset {
        $asset = Asset::factory()->create(['file_name' => $fileName, 'path' => 'assets/'.uniqid().'/'.$fileName] + $attributes);
        $asset->directories()->attach(($folder ?? $this->elsewhere)->id);

        return $asset;
    };
    $this->seed = ($this->make)('Poster_v2.psd', $this->folder, ['stack_basis' => 'campaign', 'stack_key' => 'Fall Gala']);
});

it('ranks by each signal and says why', function () {
    config(['ai-index.search_enabled' => true]);
    $make = $this->make;
    $rare = $make('rare.jpg');
    $common = $make('common.jpg');
    $property = $make('property.jpg');
    $stem = $make('poster (1).jpg');
    $stack = $make('stack.jpg', attributes: ['stack_basis' => 'campaign', 'stack_key' => 'Fall Gala']);
    $folder = $make('folder.jpg', $this->folder);
    $looks = $make('looks.jpg');
    $unrelated = $make('unrelated.jpg');
    $filler = collect(range(1, 6))->map(fn ($i) => $make("filler-$i.jpg"));

    relatedTag('lighthouse', $this->seed, $rare);
    relatedTag('event', $this->seed, $common, ...$filler->all());
    foreach ([$this->seed, $property] as $asset) {
        DB::table('dam_asset_properties')->insert(['dam_asset_id' => $asset->id, 'name' => 'Sponsor', 'type' => 'text', 'language' => 'en', 'value' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
    }
    relatedVector($this->seed, [1, 0, 0]);
    relatedVector($looks, [0.95, 0.05, 0]);
    relatedVector($unrelated, [0, 1, 0]);
    $this->loginAsAdmin();

    $related = relatedFor($this->seed, ['limit' => 24]);

    expect(relatedSignals($related[$rare->id]))->toBe(['tags'])
        ->and(relatedSignals($related[$common->id]))->toBe(['tags'])
        ->and($related[$rare->id]['score'])->toBeGreaterThan($related[$common->id]['score'])
        ->and($related[$rare->id]['why'])->toBe('1 shared tag')
        ->and(relatedSignals($related[$property->id]))->toBe(['property'])
        ->and($related[$property->id]['why'])->toBe('same sponsor')
        ->and(relatedSignals($related[$stem->id]))->toBe(['stem'])
        ->and($related[$stem->id]['why'])->toBe('similar file name')
        ->and(relatedSignals($related[$stack->id]))->toBe(['stack'])
        ->and($related[$stack->id]['why'])->toBe('same campaign')
        ->and(relatedSignals($related[$folder->id]))->toBe(['folder'])
        ->and(relatedSignals($related[$looks->id]))->toBe(['vector'])
        ->and($related[$looks->id]['why'])->toBe('looks similar')
        ->and($related)->not->toHaveKey($unrelated->id)
        ->and($related)->not->toHaveKey($this->seed->id)
        // Strongest deliberate grouping first, the bare folder last.
        ->and(array_key_first($related))->toBe($stack->id)
        ->and(array_key_last($related))->toBe($folder->id);

    // Several reasons add up and read strongest first; the default list is 8 long.
    relatedTag('lighthouse', $stack);
    $top = relatedFor($this->seed);

    expect($top[$stack->id]['why'])->toBe('same campaign · 1 shared tag')
        ->and($top)->toHaveCount(8);
});

function relatedHash(Asset $asset, string $sha): void
{
    DB::table('dam_asset_phashes')->insert(['asset_id' => $asset->id, 'sha256' => str_pad($sha, 64, '0'), 'algorithm' => 'dhash64', 'status' => 'ok']);
}

it('names each card\'s folder and collapses only byte-identical files in the same folder', function () {
    $this->loginAsAdmin();
    $make = $this->make;
    $tag = relatedTag('gala', $this->seed);
    $twinA = $make('Gala Photo.jpg', $this->folder, ['file_size' => 4096]);
    $twinB = $make('Gala Photo.jpg', $this->folder, ['file_size' => 4096]);
    $elsewhere = $make('Gala Photo.jpg', $this->elsewhere, ['file_size' => 4096]);
    // Same name and size, different bytes: a different file, not a twin.
    $other = $make('Gala Photo.jpg', $this->folder, ['file_size' => 4096]);
    // Same label under a different parent is a different folder.
    $parent = Directory::factory()->create(['name' => 'Archive', 'parent_id' => null]);
    $sameLabel = Directory::factory()->create(['name' => 'Campaigns', 'parent_id' => $parent->id]);
    $labelTwin = $make('Gala Photo.jpg', $sameLabel, ['file_size' => 4096]);
    // No stored checksum: nothing proves identity, so never collapsed.
    $unhashedA = $make('Unhashed.jpg', $this->folder);
    $unhashedB = $make('Unhashed.jpg', $this->folder);

    foreach ([$twinA, $twinB, $elsewhere, $labelTwin] as $asset) {
        relatedHash($asset, 'aaaa');
    }

    relatedHash($other, 'bbbb');
    $tag->assets()->syncWithoutDetaching([$twinA->id, $twinB->id, $elsewhere->id, $other->id, $labelTwin->id, $unhashedA->id, $unhashedB->id]);

    $items = relatedFor($this->seed);

    // twinA/twinB collapse to one; the different hash, the other folder, the same-labelled folder and both unhashed stay.
    expect($items)->toHaveCount(6)
        ->and(collect([$twinA->id, $twinB->id])->filter(fn ($id) => isset($items[$id]))->count())->toBe(1)
        ->and(collect($items)->pluck('folder')->filter()->sort()->values()->all())->toBe(['Campaigns', 'Campaigns', 'Campaigns', 'Campaigns', 'Campaigns', 'Elsewhere']);
});

it('leaves out the seed, its stack members and its explicit relationships in either direction', function () {
    $make = $this->make;
    $member = $make('member.jpg');
    $outgoing = $make('outgoing.jpg');
    $incoming = $make('incoming.jpg');
    $kept = $make('kept.jpg');
    $profile = DB::table('dam_capture_profiles')->insertGetId(['name' => 'Raw+JPEG', 'extensions' => '[]', 'priority' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $group = DB::table('dam_capture_groups')->insertGetId(['profile_id' => $profile, 'primary_asset_id' => $this->seed->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_assets')->whereIn('id', [$this->seed->id, $member->id])->update(['capture_group_id' => $group]);
    AssetRelationship::create(['from_asset_id' => $this->seed->id, 'to_asset_id' => $outgoing->id, 'type' => AssetRelationship::TYPE_RELATED_TO]);
    AssetRelationship::create(['from_asset_id' => $incoming->id, 'to_asset_id' => $this->seed->id, 'type' => AssetRelationship::TYPE_VERSION_OF]);
    relatedTag('shared', $this->seed, $member, $outgoing, $incoming, $kept);
    $this->loginAsAdmin();

    expect(array_keys(relatedFor($this->seed->fresh())))->toBe([$kept->id]);
});

it('never shows or counts a hidden asset, and answers a hidden seed like a missing one', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    $make = $this->make;
    $visible = $make('visible.jpg', $this->folder);
    $hidden = $make('hidden.jpg', $this->folder);
    $hiddenSeed = $make('hidden-seed.jpg', $this->folder);
    // The hidden file shares everything: tags, stem, stack, folder, property and looks.
    $hiddenTwin = $make('Poster (3).psd', $this->folder, ['stack_basis' => 'campaign', 'stack_key' => 'Fall Gala']);
    relatedTag('lighthouse', $this->seed, $visible, $hidden, $hiddenTwin, $hiddenSeed);
    $member = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert([['asset_id' => $this->seed->id, 'admin_id' => $member->id], ['asset_id' => $visible->id, 'admin_id' => $member->id]]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    $related = relatedFor($this->seed);

    expect(array_keys($related))->toBe([$visible->id]);

    // Not counted either: more hidden files carrying the tag leave the visible match's IDF weight alone.
    $score = $related[$visible->id]['score'];
    collect(range(1, 5))->each(fn ($i) => relatedTag('lighthouse', $make("more-hidden-$i.jpg", $this->folder)));

    expect(relatedFor($this->seed)[$visible->id]['score'])->toBe($score);

    // A hidden seed answers exactly like a missing one.
    config(['app.debug' => false]);
    $hiddenAnswer = $this->getJson(route('admin.dam.assets.related', $hiddenSeed->id));
    $missingAnswer = $this->getJson(route('admin.dam.assets.related', 999999));

    expect($hiddenAnswer->status())->toBe(404)
        ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
});

it('leaves out trashed and unavailable files for everyone, a full admin included', function () {
    $make = $this->make;
    $trashed = $make('trashed.jpg');
    $draft = $make('draft.jpg', attributes: ['availability' => Asset::AVAILABILITY_DRAFT]);
    $live = $make('live.jpg');
    relatedTag('lighthouse', $this->seed, $trashed, $draft, $live);
    DB::table('dam_assets')->where('id', $trashed->id)->update(['deleted_at' => now()]);
    $this->loginAsAdmin();

    expect(array_keys(relatedFor($this->seed)))->toBe([$live->id]);
});

it('renders the Related section on the asset page', function () {
    $this->loginAsAdmin();

    $this->get(route('admin.dam.assets.edit', $this->seed->id))
        ->assertOk()
        ->assertSee('<v-dam-related-assets :initial-asset-id="'.$this->seed->id.'">', escape: false)
        ->assertSee('data-dam-related', escape: false);
});

it('answers the same body over REST as on the asset page', function () {
    $make = $this->make;
    $a = $make('alpha.jpg');
    $b = $make('poster-final.png');
    relatedTag('lighthouse', $this->seed, $a);
    $headers = $this->getAuthenticationHeaders();
    $this->actingAs(Admin::latest('id')->first(), 'admin');

    $web = $this->getJson(route('admin.dam.assets.related', $this->seed->id))->assertOk()->json();
    $rest = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.related', $this->seed->id))->assertOk()->json();

    expect($rest)->toBe($web)
        ->and(array_column($rest['data']['related'], 'id'))->toEqualCanonicalizing([$a->id, $b->id]);
});

it('answers a hidden seed like a missing one over REST, and lists only what the key owner may see', function () {
    $make = $this->make;
    $visible = $make('visible.jpg');
    $hidden = $make('hidden.jpg', $this->folder);
    $otherSeed = $make('other-seed.jpg');
    relatedTag('lighthouse', $this->seed, $visible, $hidden, $otherSeed);

    // The key owner's role is granted only the "Elsewhere" folder.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->elsewhere->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $headers = $this->getAuthenticationHeaders();
    Admin::latest('id')->first()->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);

    $hiddenSeed = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.related', $this->seed->id));
    $missing = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.related', 999999));
    $listed = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.related', $otherSeed->id))->assertOk();

    expect($hiddenSeed->status())->toBe(404)
        ->and($hiddenSeed->getContent())->toBe($missing->getContent())
        ->and(array_column($listed->json('data.related'), 'id'))->toBe([$visible->id]);
});
