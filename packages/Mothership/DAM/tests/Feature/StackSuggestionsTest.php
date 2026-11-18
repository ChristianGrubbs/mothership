<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CaptureGroup;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\StackSuggestions;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P3b-2 (D5 suggestions): the "Suggested stacks" panel groups entries the viewer can see by shared stack key, capture
 * group or filename stem. It never acts on its own: "Stack them" sets one basis and key through the P3a stack flow,
 * "Dismiss" hides a group for that viewer only. A member the viewer cannot see is neither named nor counted.
 */

function stackSugAsset(Directory $folder, string $name, array $attributes = []): Asset
{
    $asset = Asset::factory()->create([
        'file_name' => $name,
        'path'      => 'assets/Root/'.$folder->name.'/'.$name,
        ...$attributes,
    ]);
    $asset->directories()->attach($folder->id);

    return $asset;
}

/** This test's suggestions only: rows other tests committed may group too. */
function stackSugGroups(object $test, array $ownIds, string $route = 'admin.dam.stack-suggestions.index', array $headers = []): array
{
    Cache::flush();
    $response = $test->withHeaders($headers)->getJson(route($route))->assertOk();
    $groups = $response->json('suggestions') ?? $response->json('data.suggestions');

    return array_values(array_filter($groups, fn ($group) => array_diff(array_column($group['members'], 'id'), $ownIds) === []));
}

function stackSugAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

beforeEach(function () {
    $this->loginAsAdmin();
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->folder = Directory::factory()->create(['name' => 'Shoot '.Str::random(4), 'parent_id' => $root->id]);
    $this->tag = Str::lower(Str::random(6));
});

it('groups by a shared key, a capture group and a filename stem, and leaves out what is already one stack', function () {
    $tag = $this->tag;
    // Key: one campaign key spelled three ways across two bases.
    $fallA = stackSugAsset($this->folder, "poster-{$tag}.jpg", ['stack_basis' => 'campaign', 'stack_key' => "Fall {$tag}"]);
    $fallB = stackSugAsset($this->folder, "flyer-{$tag}.jpg", ['stack_basis' => 'campaign', 'stack_key' => "Fall {$tag}"]);
    $fallC = stackSugAsset($this->folder, "card-{$tag}.jpg", ['stack_basis' => 'product', 'stack_key' => " fall {$tag} "]);
    // Capture: a RAW and its JPEG. They share a stem too; the group is offered once, as the capture group.
    $profile = CaptureProfile::create(['name' => 'RAW+JPEG '.$tag, 'extensions' => ['cr3', 'jpg'], 'priority' => 1, 'enabled' => true]);
    $raw = stackSugAsset($this->folder, "IMG_{$tag}.CR3");
    $group = CaptureGroup::create(['profile_id' => $profile->id, 'primary_asset_id' => $raw->id, 'manual_primary' => false]);
    $jpeg = stackSugAsset($this->folder, "IMG_{$tag}.jpg", ['capture_group_id' => $group->id]);
    Asset::where('id', $raw->id)->update(['capture_group_id' => $group->id]);
    // Stem: version, size and copy suffixes fold away.
    $heroes = [
        stackSugAsset($this->folder, "Hero{$tag}-v1.jpg"),
        stackSugAsset($this->folder, "hero{$tag}_final.png"),
        stackSugAsset($this->folder, "Hero{$tag}@2x.png"),
        stackSugAsset($this->folder, "hero{$tag} (1).jpg"),
    ];
    // Already one stack: same basis and key on every member.
    $logos = [
        stackSugAsset($this->folder, "logo{$tag}-v1.png", ['stack_basis' => 'product', 'stack_key' => "logo {$tag}"]),
        stackSugAsset($this->folder, "logo{$tag}-v2.png", ['stack_basis' => 'product', 'stack_key' => "logo {$tag}"]),
    ];
    $own = array_map(fn (Asset $asset) => $asset->id, [$fallA, $fallB, $fallC, $raw, $jpeg, ...$heroes, ...$logos]);

    $groups = stackSugGroups($this, $own);

    expect(array_map(fn ($group) => [$group['kind'], $group['key'], $group['count']], $groups))->toBe([
        ['key', "fall {$tag}", 3],
        ['capture', (string) $group->id, 2],
        ['stem', "hero{$tag}", 4],
    ])
        ->and($groups[0]['prefill'])->toBe(['basis' => 'campaign', 'key' => "Fall {$tag}"])
        ->and(array_column($groups[1]['members'], 'id'))->toBe([$raw->id, $jpeg->id])
        ->and($groups[2]['prefill'])->toBe(['basis' => 'other', 'key' => "Hero{$tag}"])
        ->and($groups[2]['title'])->toContain('4');
});

it('never names or counts a member the viewer cannot see, even from a cached list', function () {
    stackSugAccessModel(AccessModel::GRANTS);
    $tag = $this->tag;
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $visible = [stackSugAsset($this->folder, "banner{$tag}-v1.jpg"), stackSugAsset($this->folder, "banner{$tag}-v2.jpg")];
    $hidden = stackSugAsset($hiddenFolder, "banner{$tag}-v3.jpg");
    // A group with one member the viewer can see is no group to them.
    $lonely = [stackSugAsset($this->folder, "teaser{$tag}.jpg"), stackSugAsset($hiddenFolder, "teaser{$tag}-final.jpg")];
    $own = [$visible[0]->id, $visible[1]->id, $hidden->id, $lonely[0]->id, $lonely[1]->id];

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->all()]);
    $member = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $groups = stackSugGroups($this, $own);
    $everything = json_encode($groups);
    expect(array_map(fn ($group) => [$group['kind'], $group['count'], array_column($group['members'], 'id')], $groups))
        ->toBe([['stem', 2, [$visible[0]->id, $visible[1]->id]]])
        ->and($everything)->not->toContain("banner{$tag}-v3")
        ->and($everything)->not->toContain("teaser{$tag}");

    // The cached list is re-checked on every read: a member trashed since leaves the group, which is then no group.
    $this->getJson(route('admin.dam.stack-suggestions.index'))->assertOk();
    app(AssetTrashService::class)->trash(asset: $visible[1]);
    $cached = collect($this->getJson(route('admin.dam.stack-suggestions.index'))->assertOk()->json('suggestions'))
        ->filter(fn ($group) => $group['key'] === "banner{$tag}");
    expect($cached)->toBeEmpty();

    // REST, for a key whose admin has the member's role: the same view.
    app(AssetTrashService::class)->restore($visible[1]->fresh() ?? Asset::withTrashed()->findOrFail($visible[1]->id));
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.stack_suggestions']);
    Admin::latest('id')->first()->update(['role_id' => $role->id]);
    app('auth')->forgetGuards();
    app(DirectoryPermissionService::class)->flush();
    $rest = stackSugGroups($this, $own, 'admin.api.dam.stack-suggestions.index', $headers);
    expect(array_map(fn ($group) => [$group['kind'], $group['count'], array_column($group['members'], 'id')], $rest))
        ->toBe([['stem', 2, [$visible[0]->id, $visible[1]->id]]])
        ->and(json_encode($rest))->not->toContain("banner{$tag}-v3")->not->toContain("teaser{$tag}");
});

it('keeps a dismissal for the viewer who dismissed, and only for them', function () {
    $tag = $this->tag;
    $own = [stackSugAsset($this->folder, "badge{$tag}-v1.png")->id, stackSugAsset($this->folder, "badge{$tag}-v2.png")->id];
    expect(stackSugGroups($this, $own))->toHaveCount(1);

    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'stem', 'group_key' => "badge{$tag}"])->assertOk();
    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'stem', 'group_key' => "badge{$tag}"])->assertOk();
    expect(stackSugGroups($this, $own))->toBe([])
        ->and(DB::table('dam_stack_suggestion_dismissals')->where('group_key', "badge{$tag}")->count())->toBe(1);

    $this->actingAs(Admin::factory()->create(['status' => 1]), 'admin');
    expect(stackSugGroups($this, $own))->toHaveCount(1);

    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'nonsense', 'group_key' => 'x'])->assertUnprocessable();
});

it('Stack them gives every member the chosen basis and key, and the group is then gone', function () {
    $tag = $this->tag;
    $members = [stackSugAsset($this->folder, "promo{$tag}-v1.mp4"), stackSugAsset($this->folder, "promo{$tag}-1080p.mp4"), stackSugAsset($this->folder, "promo{$tag}.jpg")];
    $own = array_map(fn (Asset $asset) => $asset->id, $members);
    $prefill = stackSugGroups($this, $own)[0]['prefill'];
    expect($prefill)->toBe(['basis' => 'other', 'key' => "promo{$tag}"]);

    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'stem', 'group_key' => "promo{$tag}", 'basis' => 'campaign', 'key' => " Promo {$tag} "])
        ->assertOk()->assertJson(['stacked' => 3, 'skipped' => 0, 'stack' => ['basis' => 'campaign', 'key' => "Promo {$tag}"]]);

    expect(Asset::whereIn('id', $own)->get()->map->only(['stack_basis', 'stack_key'])->unique()->values()->all())
        ->toBe([['stack_basis' => 'campaign', 'stack_key' => "Promo {$tag}"]])
        ->and(stackSugGroups($this, $own))->toBe([]);

    // A group that is no longer there, and a blank key, are refused.
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'stem', 'group_key' => "nothing{$tag}", 'basis' => 'campaign', 'key' => 'x'])->assertUnprocessable();
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'stem', 'group_key' => "promo{$tag}", 'basis' => 'campaign', 'key' => '  '])->assertUnprocessable();
});

it('hands the Create stack review every member of a suggestion, not only the few it names', function () {
    $tag = $this->tag;
    $members = collect(range(1, StackSuggestions::NAMED_MEMBERS + 2))->map(fn (int $n) => stackSugAsset($this->folder, "reel{$tag}-v{$n}.mp4"));
    $own = $members->pluck('id')->all();

    $group = stackSugGroups($this, $own)[0];

    expect($group['members'])->toHaveCount(StackSuggestions::NAMED_MEMBERS)
        ->and(array_column($group['entries'], 'id'))->toEqualCanonicalizing($own)
        ->and($group['entries'][0])->toHaveKeys(['id', 'name', 'extension']);
});

it('lists the same suggestions over REST for a key with the scope, and refuses one without', function () {
    $tag = $this->tag;
    $own = [stackSugAsset($this->folder, "spot{$tag}-v1.mov")->id, stackSugAsset($this->folder, "spot{$tag}-v2.mov")->id];

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.stack_suggestions']);
    $groups = stackSugGroups($this, $own, 'admin.api.dam.stack-suggestions.index', $headers);
    expect(array_map(fn ($group) => [$group['kind'], $group['key'], $group['count']], $groups))->toBe([['stem', "spot{$tag}", 2]]);

    app('auth')->forgetGuards();
    $this->withHeaders($this->getAuthenticationHeaders('custom', ['api.dam.assets']))
        ->getJson(route('admin.api.dam.stack-suggestions.index'))->assertForbidden();
});

it('re-checks more than 2,100 candidate members in bounded IN lists, and still counts only the visible ones', function () {
    stackSugAccessModel(AccessModel::GRANTS);
    $tag = $this->tag;
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $profile = CaptureProfile::create(['name' => 'Bulk '.$tag, 'extensions' => ['cr3', 'jpg'], 'priority' => 1, 'enabled' => true]);

    // 3 kinds x 20 groups x 50 members, raw inserts for speed; 10 of each group's members sit in a hidden folder.
    $rows = [];
    $now = now();
    foreach (range(1, StackSuggestions::GROUPS_PER_KIND) as $g) {
        $capture = CaptureGroup::create(['profile_id' => $profile->id, 'primary_asset_id' => null, 'manual_primary' => false]);
        foreach (range(1, StackSuggestions::MAX_MEMBERS) as $m) {
            $base = ['file_type' => 'image', 'file_size' => 1, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'created_at' => $now, 'updated_at' => $now, 'hidden' => $m <= 10,
                'name_stem'      => null, 'capture_group_id' => null, 'stack_basis' => null, 'stack_key' => null];
            $rows[] = [...$base, 'file_name' => "bulk{$tag}stem{$g} v{$m}.jpg", 'name_stem' => "bulk{$tag}stem{$g}"];
            $rows[] = [...$base, 'file_name' => "bulk{$tag}cap{$g}-{$m}.jpg", 'capture_group_id' => $capture->id];
            $rows[] = [...$base, 'file_name' => "bulk{$tag}key{$g}-{$m}.jpg", 'stack_basis' => $m % 2 ? 'campaign' : 'product', 'stack_key' => "bulk {$tag} key {$g}"];
        }
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('dam_assets')->insert(array_map(fn ($row) => array_diff_key([...$row, 'path' => 'assets/Root/'.$row['file_name']], ['hidden' => 1]), $chunk));
    }
    $ids = DB::table('dam_assets')->where('file_name', 'like', "bulk{$tag}%")->pluck('id', 'file_name');
    $hiddenNames = collect($rows)->where('hidden', true)->pluck('file_name')->flip();
    foreach ($ids->chunk(200) as $chunk) {
        DB::table('dam_asset_directory')->insert($chunk->map(fn ($id, $name) => [
            'asset_id' => $id, 'directory_id' => $hiddenNames->has($name) ? $hiddenFolder->id : $this->folder->id, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());
    }

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->all()]);
    $member = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $role->id, 'created_at' => $now, 'updated_at' => $now]);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $mostBindings = 0;
    DB::listen(function ($query) use (&$mostBindings) {
        $mostBindings = max($mostBindings, count($query->bindings));
    });
    $groups = stackSugGroups($this, $ids->values()->all());
    $hiddenIds = $ids->only($hiddenNames->keys()->all())->values()->all();

    expect(count($groups))->toBe(3 * StackSuggestions::GROUPS_PER_KIND)
        ->and(collect($groups)->countBy('kind')->all())->toBe(['key' => 20, 'capture' => 20, 'stem' => 20])
        ->and(collect($groups)->pluck('count')->unique()->values()->all())->toBe([40])
        ->and(array_intersect(collect($groups)->flatMap(fn ($group) => array_column($group['members'], 'id'))->all(), $hiddenIds))->toBe([])
        // 2,400 visible candidates, yet no statement binds more than SQL Server's 2,100 parameters.
        ->and($mostBindings)->toBeLessThan(2100);
});
