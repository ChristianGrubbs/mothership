<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Http\Controllers\GroupController;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The rebuilt Groups page's backend (ADR 0010, sharing slice 4): the per-group fields list() adds in both
 * models, Everyone's and a generated role group's read-only rules, and access_roots' set-based query.
 */

beforeEach(function () {
    $this->super = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    $this->actingAs($this->super, 'admin');
});

/** Leaves the switch row at $model, as a finished `dam:access:switch` would. */
function groupsSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function groupsEveryoneId(): int
{
    return (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
}

/** A group carrying the provenance a rebuild gives a role-derived group (ADR 0010). */
function groupsMakeRoleGroup(string $name = 'Editors (from role)'): int
{
    $role = Role::factory()->create(['permission_type' => 'custom']);

    return (int) DB::table('dam_groups')->insertGetId([
        'name'       => $name,
        'system_key' => 'role:'.$role->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns member_count and is_system for every group in both models', function () {
    Admin::factory()->count(2)->create(['role_id' => $this->super->role_id, 'status' => 1]);
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Editors', 'member_ids' => [$this->super->id]])
        ->assertCreated()->json('data');

    foreach ([AccessModel::GRANTS, AccessModel::LISTS] as $model) {
        groupsSetModel($model);

        $response = $this->getJson('/admin/settings/groups/list')->assertOk();
        $everyone = collect($response->json('groups'))->firstWhere('system_key', 'everyone');
        $editors = collect($response->json('groups'))->firstWhere('id', $group['id']);

        // Everyone's count is every active admin, not member rows (it has none).
        expect($everyone['member_count'])->toBe(DB::table('admins')->where('status', true)->count());
        expect($everyone['is_system'])->toBeTrue();
        expect($everyone['read_only'])->toBeTrue();

        expect($editors['member_count'])->toBe(1);
        expect($editors['is_system'])->toBeFalse();
        expect($editors['read_only'])->toBeFalse();

        // access_roots is lists-only.
        expect(array_key_exists('access_roots', $editors))->toBe($model === AccessModel::LISTS);
    }
});

it('refuses to rename, save members onto, or delete Everyone, in both models', function () {
    $everyoneId = groupsEveryoneId();

    foreach ([AccessModel::GRANTS, AccessModel::LISTS] as $model) {
        groupsSetModel($model);

        $this->putJson('/admin/settings/groups/'.$everyoneId, ['name' => 'Renamed', 'member_ids' => []])
            ->assertStatus(422);
        $this->deleteJson('/admin/settings/groups/'.$everyoneId)->assertStatus(422);

        expect(DB::table('dam_groups')->where('id', $everyoneId)->value('name'))->not->toBe('Renamed');
        $this->assertDatabaseHas('dam_groups', ['id' => $everyoneId]);
    }
});

it('lets a role group be edited under lists but refuses it under grants', function () {
    $roleGroupId = groupsMakeRoleGroup();
    $member = Admin::factory()->create(['role_id' => $this->super->role_id, 'status' => 1]);

    groupsSetModel(AccessModel::GRANTS);
    $this->putJson('/admin/settings/groups/'.$roleGroupId, ['name' => 'Editors (from role)', 'member_ids' => [$member->id]])
        ->assertStatus(422);
    $this->deleteJson('/admin/settings/groups/'.$roleGroupId)->assertStatus(422);
    $this->assertDatabaseHas('dam_groups', ['id' => $roleGroupId]);

    groupsSetModel(AccessModel::LISTS);
    $this->putJson('/admin/settings/groups/'.$roleGroupId, ['name' => 'Editors (renamed)', 'member_ids' => [$member->id]])
        ->assertOk();
    expect(DB::table('dam_groups')->where('id', $roleGroupId)->value('name'))->toBe('Editors (renamed)');

    $this->deleteJson('/admin/settings/groups/'.$roleGroupId)->assertOk();
    $this->assertDatabaseMissing('dam_groups', ['id' => $roleGroupId]);
});

it('deletes a plain group under both models, fenced by the access-model state row', function () {
    foreach ([AccessModel::GRANTS, AccessModel::LISTS] as $model) {
        groupsSetModel($model);
        $group = $this->postJson('/admin/settings/groups', ['name' => 'Temp '.$model, 'member_ids' => []])
            ->assertCreated()->json('data');

        $this->deleteJson('/admin/settings/groups/'.$group['id'])->assertOk();
        $this->assertDatabaseMissing('dam_groups', ['id' => $group['id']]);
    }
});

it('computes access_roots over a nested fixture: a covered subtree, and a gap that yields both ends', function () {
    groupsSetModel(AccessModel::LISTS);

    $group = $this->postJson('/admin/settings/groups', ['name' => 'Studio crew', 'member_ids' => []])
        ->assertCreated()->json('data');
    $groupId = $group['id'];

    // Covered subtree: root, child and grandchild all listed -> the root alone.
    $root = Directory::create(['name' => 'Season one', 'parent_id' => null]);
    $child = Directory::create(['name' => 'Episode 1', 'parent_id' => $root->id]);
    $grandchild = Directory::create(['name' => 'Stills', 'parent_id' => $child->id]);

    // A gap: R listed, C not listed, G (child of C) listed again -> both R and G.
    $gapRoot = Directory::create(['name' => 'Season two', 'parent_id' => null]);
    $gapChild = Directory::create(['name' => 'Episode 1', 'parent_id' => $gapRoot->id]);
    $gapGrandchild = Directory::create(['name' => 'Stills', 'parent_id' => $gapChild->id]);

    // A root-level-only folder.
    $lone = Directory::create(['name' => 'Archive', 'parent_id' => null]);

    DB::table('dam_directory_group_access')->insert(collect([$root->id, $child->id, $grandchild->id, $gapRoot->id, $gapGrandchild->id, $lone->id])
        ->map(fn ($id) => ['directory_id' => $id, 'group_id' => $groupId])->all());

    $editors = collect($this->getJson('/admin/settings/groups/list')->assertOk()->json('groups'))
        ->firstWhere('id', $groupId);

    expect(collect($editors['access_roots'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$root->id, $gapRoot->id, $gapGrandchild->id, $lone->id])->sort()->values()->all());

    $lonePath = collect($editors['access_roots'])->firstWhere('id', $lone->id);
    expect($lonePath['path'])->toBe('Archive');

    // The gap's far end carries its whole ancestry, not just its own name.
    $gapGrandchildPath = collect($editors['access_roots'])->firstWhere('id', $gapGrandchild->id);
    expect($gapGrandchildPath['path'])->toBe('Season two/Episode 1/Stills');
});

it('resolves access_roots paths with the same two queries whatever the root count', function () {
    groupsSetModel(AccessModel::LISTS);

    // Reflection on the controller's own accessRoots(), isolated from list()'s other queries (locale
    // bootstrap, member counts, the users list): what finding 2 is about is specifically whether resolving
    // paths costs one query per root (Directory::find() + generatePath()) or a fixed number of them.
    $controller = app(GroupController::class);
    $accessRoots = new ReflectionMethod($controller, 'accessRoots');
    $accessRoots->setAccessible(true);

    $queriesFor = function (int $rootCount) use ($controller, $accessRoots) {
        $group = $this->postJson('/admin/settings/groups', ['name' => 'Many roots '.$rootCount, 'member_ids' => []])
            ->assertCreated()->json('data');

        $roots = collect(range(1, $rootCount))->map(fn ($n) => Directory::create(['name' => 'Root '.$rootCount.'-'.$n, 'parent_id' => null]));
        DB::table('dam_directory_group_access')->insert($roots
            ->map(fn ($root) => ['directory_id' => $root->id, 'group_id' => $group['id']])->all());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $accessRoots->invoke($controller, [$group['id']]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($result[$group['id']])->toHaveCount($rootCount);

        return $queryCount;
    };

    // Two disjoint, unrelated root-level folders against ten of them: a per-root query would show up as a
    // query count that grows with the root count. A fixed-query design (one query for the roots, one for
    // every path) costs the same either way.
    $forTwo = $queriesFor(2);
    $forTen = $queriesFor(10);

    expect($forTwo)->toBe(2);
    expect($forTen)->toBe($forTwo);
});

it('leaves Everyone out of the legacy folder-access picker under grants', function () {
    groupsSetModel(AccessModel::GRANTS);
    $folder = Directory::create(['name' => 'Legacy picker folder', 'parent_id' => null]);

    $available = collect($this->getJson('/admin/dam/directory/'.$folder->id.'/access')->assertOk()->json('available_groups'));

    expect($available->firstWhere('id', groupsEveryoneId()))->toBeNull();
});

it('ignores a forged system_key in a save request', function () {
    groupsSetModel(AccessModel::LISTS);

    $group = $this->postJson('/admin/settings/groups', ['name' => 'Ordinary group', 'member_ids' => [], 'system_key' => 'everyone'])
        ->assertCreated()->json('data');

    $stored = DB::table('dam_groups')->where('id', $group['id'])->first();
    expect($stored->system_key)->toBeNull();

    $listed = collect($this->getJson('/admin/settings/groups/list')->assertOk()->json('groups'))->firstWhere('id', $group['id']);
    expect($listed['is_system'])->toBeFalse();
    expect($listed['read_only'])->toBeFalse();
});

it('rejects create and update requests naming the exact Everyone name, as an ordinary uniqueness clash', function () {
    groupsSetModel(AccessModel::LISTS);
    $everyoneName = DB::table('dam_groups')->where('system_key', 'everyone')->value('name');

    $this->postJson('/admin/settings/groups', ['name' => $everyoneName, 'member_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
    expect(DB::table('dam_groups')->where('name', $everyoneName)->count())->toBe(1);

    $group = $this->postJson('/admin/settings/groups', ['name' => 'Not everyone yet', 'member_ids' => []])
        ->assertCreated()->json('data');

    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => $everyoneName, 'member_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    // The row itself is untouched: still its own name, still no system_key.
    $row = DB::table('dam_groups')->where('id', $group['id'])->first(['name', 'system_key']);
    expect($row->name)->toBe('Not everyone yet');
    expect($row->system_key)->toBeNull();
});

it("renaming a group onto a name similar to Everyone's does not make it read-only or system", function () {
    groupsSetModel(AccessModel::LISTS);
    $everyoneName = DB::table('dam_groups')->where('system_key', 'everyone')->value('name');

    $group = $this->postJson('/admin/settings/groups', ['name' => 'Not everyone yet', 'member_ids' => []])
        ->assertCreated()->json('data');

    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => $everyoneName.' (impostor)', 'member_ids' => []])
        ->assertOk();

    $listed = collect($this->getJson('/admin/settings/groups/list')->assertOk()->json('groups'))->firstWhere('id', $group['id']);
    expect($listed['is_system'])->toBeFalse();
    expect($listed['read_only'])->toBeFalse();
    expect(DB::table('dam_groups')->where('id', $group['id'])->value('system_key'))->toBeNull();
});

it('rejects group management by non full admins', function () {
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    $this->actingAs($member, 'admin');

    $this->getJson('/admin/settings/groups/list')->assertForbidden();
    $this->postJson('/admin/settings/groups', ['name' => 'Nope', 'member_ids' => []])->assertForbidden();
});

it('leaves integration users out of the page counts and picker without dropping their membership', function () {
    $api = Admin::factory()->create(['role_id' => null, 'status' => 1, 'type' => 'api', 'email' => 'integration+x@api.local']);
    $person = Admin::factory()->create(['role_id' => $this->super->role_id, 'status' => 1]);
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Crew', 'member_ids' => [$person->id]])->assertCreated()->json('data');
    DB::table('dam_group_members')->insert(['group_id' => $group['id'], 'admin_id' => $api->id]);

    $response = $this->getJson('/admin/settings/groups/list')->assertOk();
    $everyone = collect($response->json('groups'))->firstWhere('system_key', 'everyone');
    $crew = collect($response->json('groups'))->firstWhere('id', $group['id']);

    expect($everyone['member_count'])->toBe(DB::table('admins')->where('status', true)->where('type', '!=', 'api')->count());
    expect($crew['member_count'])->toBe(1)->and($crew['member_ids'])->toBe([$person->id]);
    expect(collect($response->json('users'))->pluck('id'))->not->toContain($api->id)->toContain($person->id);

    // Saving from the page (which never lists the integration user) keeps the hidden membership row.
    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => 'Crew', 'member_ids' => []])->assertOk();
    expect(DB::table('dam_group_members')->where('group_id', $group['id'])->pluck('admin_id')->map(fn ($id) => (int) $id)->all())->toBe([$api->id]);
});
