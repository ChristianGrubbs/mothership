<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function (): void {
    $this->loginAsAdmin();
});

it('orders directory neighbours newest-first, matching the Library default sort', function (): void {
    $directory = Directory::factory()->create();
    $older = Asset::factory()->create(['created_at' => now()->subDays(2)]);
    $middle = Asset::factory()->create(['created_at' => now()->subDay()]);
    $newer = Asset::factory()->create(['created_at' => now()]);

    foreach ([$older, $middle, $newer] as $asset) {
        $asset->directories()->attach($directory);
    }

    // The Library's default listing sorts newest-first, so the middle asset's previous
    // (‹, the one shown to its LEFT) is the newer asset and its next (›) is the older one.
    $response = $this->getJson(route('admin.dam.assets.show', $middle->id).'?directory_id='.$directory->id);

    $response->assertOk()
        ->assertJsonPath('previousAssetId', $newer->id)
        ->assertJsonPath('nextAssetId', $older->id)
        ->assertJsonPath('assetPosition', 2)
        ->assertJsonPath('assetTotal', 3);

    // The oldest asset is the last in the newest-first order: no next, previous is the middle one.
    $lastResponse = $this->getJson(route('admin.dam.assets.show', $older->id).'?directory_id='.$directory->id);
    $lastResponse->assertOk()
        ->assertJsonPath('previousAssetId', $middle->id)
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetPosition', 3)
        ->assertJsonPath('assetTotal', 3);
});

it('breaks a created_at tie by id ascending, matching ExplorerDataController\'s tie-break', function (): void {
    $directory = Directory::factory()->create();
    $tie = now();
    // All three share the exact same created_at; only insertion order (ascending id) breaks it.
    $first = Asset::factory()->create(['created_at' => $tie]);
    $second = Asset::factory()->create(['created_at' => $tie]);
    $third = Asset::factory()->create(['created_at' => $tie]);

    foreach ([$first, $second, $third] as $asset) {
        $asset->directories()->attach($directory);
    }

    $response = $this->getJson(route('admin.dam.assets.show', $second->id).'?directory_id='.$directory->id);

    $response->assertOk()
        ->assertJsonPath('previousAssetId', $first->id)
        ->assertJsonPath('nextAssetId', $third->id)
        ->assertJsonPath('assetPosition', 2)
        ->assertJsonPath('assetTotal', 3);
});

it('reports no neighbours and position 1 of 1 for the only asset in a directory', function (): void {
    $directory = Directory::factory()->create();
    $only = Asset::factory()->create();
    $only->directories()->attach($directory);

    $response = $this->getJson(route('admin.dam.assets.show', $only->id).'?directory_id='.$directory->id);

    $response->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetPosition', 1)
        ->assertJsonPath('assetTotal', 1);
});

it('scopes neighbours to the requested directory_id rather than the asset\'s first-attached directory', function (): void {
    $dirA = Directory::factory()->create();
    $dirB = Directory::factory()->create();

    $target = Asset::factory()->create(['created_at' => now()->subHour()]);
    // Attached to dirA first, so the old asset->directories()->first() fallback would have
    // scoped neighbours to dirA even when the user is actually browsing dirB.
    $target->directories()->attach($dirA);
    $target->directories()->attach($dirB);

    $siblingOnlyInA = Asset::factory()->create(['created_at' => now()->subHours(2)]);
    $siblingOnlyInA->directories()->attach($dirA);

    $siblingOnlyInB = Asset::factory()->create(['created_at' => now()->subHours(3)]);
    $siblingOnlyInB->directories()->attach($dirB);

    $response = $this->getJson(route('admin.dam.assets.show', $target->id).'?directory_id='.$dirB->id);

    $response->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $siblingOnlyInB->id)
        ->assertJsonPath('assetTotal', 2);
});

it('falls back to the asset\'s own directory, never a broadened query, when the requested directory_id is invalid, denied, or unrelated', function (): void {
    $ownDirectory = Directory::factory()->create();
    $unrelatedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();

    $current = Asset::factory()->create(['created_at' => now()->subHour()]);
    $current->directories()->attach($ownDirectory);

    $siblingInOwn = Asset::factory()->create(['created_at' => now()->subHours(2)]);
    $siblingInOwn->directories()->attach($ownDirectory);

    // Lives in a directory the current asset is NOT in — must never leak into the fallback.
    $siblingInUnrelated = Asset::factory()->create(['created_at' => now()]);
    $siblingInUnrelated->directories()->attach($unrelatedDirectory);

    // Nonexistent directory id.
    $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id=999999')
        ->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $siblingInOwn->id)
        ->assertJsonPath('assetTotal', 2);

    // A real directory, but one the current asset does not belong to.
    $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id='.$unrelatedDirectory->id)
        ->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $siblingInOwn->id)
        ->assertJsonPath('assetTotal', 2);

    // The current asset also belongs to $deniedDirectory (attached second), but the caller's
    // role only has $ownDirectory — canActOnAsset() already permits viewing the asset (via
    // $ownDirectory, its first-attached directory), yet an explicit request for the OTHER,
    // inaccessible directory the asset also happens to sit in must fall back to $ownDirectory,
    // never broaden past what the role can actually see.
    $current->directories()->attach($deniedDirectory);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $ownDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id='.$deniedDirectory->id)
        ->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $siblingInOwn->id)
        ->assertJsonPath('assetTotal', 2);
});

it('accepts a requested directory that is viewable only through ancestry, not directly granted', function (): void {
    $parentDirectory = Directory::factory()->create();
    $childDirectory = Directory::factory()->create(['parent_id' => $parentDirectory->id]);

    $current = Asset::factory()->create(['created_at' => now()->subHour()]);
    // $childDirectory attached FIRST so canActOnAsset() (which checks only the asset's
    // first-attached directory) authorizes viewing this asset through the directly-granted
    // child, independent of whatever scope is explicitly requested below.
    $current->directories()->attach($childDirectory);
    $current->directories()->attach($parentDirectory);

    // Lives ONLY in the directly-granted child, not in $parentDirectory at all. Ordinary Library
    // asset visibility (DirectoryPermissionService::applyAssetDirectoryGrantFilter()) is
    // direct-grant-only — an ancestor-viewable-but-not-directly-granted directory exposes the
    // FOLDER for browsing, never its siblings' assets, exactly like
    // DirectoryPermissionServiceTest's own "exposes ancestors as viewable but not accessible"
    // case. So this sibling is deliberately NOT counted when
    // the request correctly scopes to $parentDirectory; it exists purely to prove the OPPOSITE
    // failure mode — that a canAccess()-based regression falling back to $childDirectory (the
    // asset's own first directory) would wrongly pull it in.
    $siblingInChild = Asset::factory()->create(['created_at' => now()->subHours(2)]);
    $siblingInChild->directories()->attach($childDirectory);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    // Only the CHILD is directly granted; the parent is never in dam_directory_role at all.
    DB::table('dam_directory_role')->insert([
        'directory_id' => $childDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    // canAccess($parentDirectory) is false (no direct grant); canView($parentDirectory) is true
    // (it is an ancestor of the granted child) — using canAccess() here would have rejected this
    // explicit, legitimate request and silently fallen back to $childDirectory, pulling
    // $siblingInChild into the pool (assetTotal 2, nextAssetId its id) even though the caller
    // asked to browse $parentDirectory specifically, not its child.
    $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id='.$parentDirectory->id)
        ->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetTotal', 1);
});

it('falls back to the unattached pool, not an empty allScopeQuery, when "all" is requested for an unattached current asset', function (): void {
    $unattachedSibling = Asset::factory()->create(['created_at' => now()->subHours(2)]);
    $unattachedCurrent = Asset::factory()->create(['created_at' => now()->subHour()]);

    $foldered = Asset::factory()->create(['created_at' => now()]);
    $foldered->directories()->attach(Directory::factory()->create());

    $response = $this->getJson(route('admin.dam.assets.show', $unattachedCurrent->id).'?directory_id=all');

    $response->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $unattachedSibling->id)
        // allScopeQuery() alone would exclude the unattached current asset (it has no
        // directories), making position exceed total or the asset vanish from its own listing.
        ->assertJsonPath('assetPosition', 1)
        ->assertJsonPath('assetTotal', 2);
});

it('returns neighbours across every accessible asset instead of null when the Library explicitly requests the "all"/search scope', function (): void {
    $directory = Directory::factory()->create();
    $otherDirectory = Directory::factory()->create();

    $current = Asset::factory()->create(['created_at' => now()->subHour()]);
    $current->directories()->attach($directory);

    // Lives in a completely different folder — the old whereDoesntHave('directories') fallback
    // never considered it (or anything else with a folder), returning null neighbours instead.
    $elsewhere = Asset::factory()->create(['created_at' => now()]);
    $elsewhere->directories()->attach($otherDirectory);

    $response = $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id=all');

    $response->assertOk()
        ->assertJsonPath('previousAssetId', $elsewhere->id)
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetTotal', 2);
});

it('reaches the "all" scope for a foldered asset even without an explicit directory_id, once the Library actually asks for it', function (): void {
    // Regression: omitting directory_id used to be the ONLY way to reach "all" scope, but a
    // foldered asset always had its own directory substituted first, making "all" unreachable
    // for it. "all" must now be reachable via the explicit literal regardless of the asset's own
    // directory attachment.
    $ownDirectory = Directory::factory()->create();
    $otherDirectory = Directory::factory()->create();

    $current = Asset::factory()->create(['created_at' => now()->subHour()]);
    $current->directories()->attach($ownDirectory);

    $elsewhere = Asset::factory()->create(['created_at' => now()]);
    $elsewhere->directories()->attach($otherDirectory);

    // Omitted directory_id: falls back to the asset's own directory, so $elsewhere (a different
    // folder) is invisible.
    $this->getJson(route('admin.dam.assets.show', $current->id))
        ->assertOk()
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetTotal', 1);

    // Explicit "all": now reaches $elsewhere.
    $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id=all')
        ->assertOk()
        ->assertJsonPath('previousAssetId', $elsewhere->id)
        ->assertJsonPath('assetTotal', 2);
});

it('includes an unattached asset in its own position/total against other unattached assets, not the "all" pool', function (): void {
    $folderedDirectory = Directory::factory()->create();

    // Unattached: no directories()->attach() call at all.
    $unattachedOlder = Asset::factory()->create(['created_at' => now()->subHours(2)]);
    $unattachedCurrent = Asset::factory()->create(['created_at' => now()->subHour()]);

    // Lives in a real folder — never appears in the "all" Library listing either way, but must
    // not leak into the unattached pool.
    $foldered = Asset::factory()->create(['created_at' => now()]);
    $foldered->directories()->attach($folderedDirectory);

    $response = $this->getJson(route('admin.dam.assets.show', $unattachedCurrent->id));

    $response->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', $unattachedOlder->id)
        // The current (unattached) asset counts toward its own total: 2, not 0 or 1.
        ->assertJsonPath('assetPosition', 1)
        ->assertJsonPath('assetTotal', 2);
});

it('hides neighbours outside the current admin\'s permitted directories, even in the "all" scope', function (): void {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();

    $current = Asset::factory()->create(['created_at' => now()->subHour()]);
    $current->directories()->attach($allowedDirectory);

    $hidden = Asset::factory()->create(['created_at' => now()]);
    $hidden->directories()->attach($deniedDirectory);

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $allowedDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $response = $this->getJson(route('admin.dam.assets.show', $current->id).'?directory_id=all');

    $response->assertOk()
        ->assertJsonPath('previousAssetId', null)
        ->assertJsonPath('nextAssetId', null)
        ->assertJsonPath('assetTotal', 1);
});

it('reports the same position/total on the edit page as on show()', function (): void {
    $directory = Directory::factory()->create();
    $first = Asset::factory()->create(['created_at' => now()->subDays(3)]);
    $second = Asset::factory()->create(['created_at' => now()->subDays(2)]);
    $third = Asset::factory()->create(['created_at' => now()->subDay()]);

    foreach ([$first, $second, $third] as $asset) {
        $asset->directories()->attach($directory);
    }

    $this->get(route('admin.dam.assets.edit', $second->id).'?directory_id='.$directory->id)
        ->assertOk()
        ->assertSee('initial-position="2"', false)
        ->assertSee('initial-total="3"', false);
});

it('authorises a multi-directory asset through the directory attached FIRST, whichever order the database would otherwise return', function (): void {
    // Regression for 2026-09-16: Asset::directories() had no ORDER BY, so on PostgreSQL the
    // "first" directory of a two-directory asset was whichever row the planner emitted, and
    // canActOnAsset() flipped between 200 and 403 across identical CI runs. The relation now
    // orders by pivot id; both attach orders below must be deterministic.
    $granted = Directory::factory()->create();
    $denied = Directory::factory()->create();

    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');

    $grantedFirst = Asset::factory()->create();
    $grantedFirst->directories()->attach($granted);
    $grantedFirst->directories()->attach($denied);

    $deniedFirst = Asset::factory()->create();
    $deniedFirst->directories()->attach($denied);
    $deniedFirst->directories()->attach($granted);

    app(DirectoryPermissionService::class)->flush();

    expect($grantedFirst->directories()->value('dam_directories.id'))->toBe($granted->id)
        ->and($deniedFirst->directories()->value('dam_directories.id'))->toBe($denied->id);

    $this->getJson(route('admin.dam.assets.show', $grantedFirst->id))->assertOk();
    // Hidden answers like missing (404), so an id probe learns nothing.
    $this->getJson(route('admin.dam.assets.show', $deniedFirst->id))->assertNotFound();
});
