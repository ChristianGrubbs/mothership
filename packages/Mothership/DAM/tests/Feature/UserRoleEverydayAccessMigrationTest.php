<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\User\Models\Role;

const EVERYDAY_CORE_KEYS = [
    'dam.asset.files', 'dam.asset.files.index', 'dam.asset.files.store', 'dam.asset.files.destroy',
    'dam.asset.re_upload', 'dam.asset.editor', 'dam.asset.rename', 'dam.asset.moved',
    'dam.asset.download_compressed', 'dam.asset.embed', 'dam.asset.process', 'dam.asset.property.delete',
    'dam.asset.relationships', 'dam.asset.relationships.index', 'dam.asset.relationships.store', 'dam.asset.relationships.destroy',
    'dam.asset.comment', 'dam.asset.comment.index', 'dam.asset.comment.store', 'dam.asset.comment.update', 'dam.asset.comment.delete',
    'dam.asset.linked_resources', 'dam.asset.linked_resources.index',
    'dam.directory.rename', 'dam.directory.moved', 'dam.directory.download_zip', 'dam.directory.copy_structure',
    'dam.collections', 'dam.collections.create', 'dam.collections.update', 'dam.collections.delete',
    'dam.trash', 'dam.trash.index', 'dam.trash.restore',
    'dam.labels', 'dam.labels.assign', 'dam.template', 'dam.template.use',
];
const EVERYDAY_DELETE_KEYS = ['dam.asset.destroy', 'dam.asset.mass_delete', 'dam.directory.destroy', 'dam.trash.delete'];
const EVERYDAY_UPLOAD_REQUEST_KEYS = ['dam.upload_requests', 'dam.upload_requests.index', 'dam.upload_requests.create', 'dam.upload_requests.edit', 'dam.upload_requests.review'];
const EVERYDAY_SNAPSHOT_KEY = 'DAM_USER_ROLE_EVERYDAY_SNAPSHOT';

// The prod "User" role's 26 keys at 2026-10-12.
const EVERYDAY_PROD_USER_KEYS = [
    'dam', 'dam.asset', 'dam.asset.download', 'dam.asset.edit', 'dam.asset.meta_data', 'dam.asset.property',
    'dam.asset.property.create', 'dam.asset.property.update', 'dam.asset.property.view', 'dam.asset.share',
    'dam.asset.tag', 'dam.asset.update', 'dam.asset.upload', 'dam.asset.view', 'dam.assets', 'dam.directory',
    'dam.directory.index', 'dam.directory.share', 'dam.directory.store', 'dam.duplicates.view', 'dam.pages',
    'dam.pages.organize', 'dam.shares', 'dam.shares.delete', 'dam.shares.index', 'dam.shares.revoke',
];
const EVERYDAY_PROD_USER_TOOLS = ['recent', 'favorites', 'storage', 'totals', 'links', 'rss', 'trending', 'looper', 'loop-maker', 'system-quickview'];

// `require`, never `require_once`: every call must hand back a fresh anonymous migration instance.
function everydayMigration(): object
{
    return require __DIR__.'/../../src/Database/Migrations/2026_10_12_000100_grant_user_role_everyday_dam_access.php';
}

function everydayAllKeys(): array
{
    return [...EVERYDAY_CORE_KEYS, ...EVERYDAY_DELETE_KEYS, ...EVERYDAY_UPLOAD_REQUEST_KEYS];
}

function everydayRole(array $permissions, string $type = 'custom', ?array $tools = null, bool $deleteOwnOnly = false, bool $row = true): Role
{
    $role = Role::factory()->create(['permission_type' => $type, 'permissions' => $permissions]);

    if ($row) {
        DB::table('dam_role_settings')->insert([
            'role_id'         => $role->id, 'delete_own_only' => $deleteOwnOnly,
            'home_widget_ids' => $tools === null ? null : json_encode($tools), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $role;
}

function everydaySettings(Role $role): ?object
{
    return DB::table('dam_role_settings')->where('role_id', $role->id)->first();
}

function everydayTools(Role $role): ?array
{
    $value = everydaySettings($role)?->home_widget_ids;

    return $value === null ? null : json_decode($value, true);
}

function everydayKeys(Role $role): array
{
    return $role->fresh()->permissions;
}

it('grants the 47 everyday keys, delete-own-only and the free tools to a prod-shaped User role', function () {
    $role = everydayRole(EVERYDAY_PROD_USER_KEYS, tools: EVERYDAY_PROD_USER_TOOLS);

    everydayMigration()->up();

    expect(everydayAllKeys())->toHaveCount(47)
        ->and(everydayKeys($role))->toEqualCanonicalizing([...EVERYDAY_PROD_USER_KEYS, ...everydayAllKeys()])
        ->and(everydayKeys($role))->not->toContain('sphereseam', 'dam.tags', 'settings.roles')
        ->and((bool) everydaySettings($role)->delete_own_only)->toBeTrue()
        // The prod list already had Loop Refiner, Signal and Trending, and no paid tool: unchanged.
        ->and(everydayTools($role))->toBe(EVERYDAY_PROD_USER_TOOLS);
});

it('adds missing free tools and takes StylePress, SphereSeam and vectorization out of the list', function () {
    $withoutFree = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: ['recent', 'stylepress', 'sphereseam']);
    $withVectorizer = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: ['recent', 'vectorization', 'stylepress']);
    $without = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: ['recent']);

    everydayMigration()->up();

    expect(everydayTools($withoutFree))->toBe(['recent', 'looper', 'rss', 'trending'])
        ->and(everydayTools($withVectorizer))->toBe(['recent', 'looper', 'rss', 'trending'])
        ->and(everydayTools($without))->toBe(['recent', 'looper', 'rss', 'trending']);
});

it('targets by shape, not by name, and leaves admin and non-matching roles untouched', function () {
    $named = Role::factory()->create(['name' => 'User', 'permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);
    $manager = everydayRole(['dam.asset.view', 'dam.asset.upload', 'settings.roles'], tools: ['recent', 'stylepress']);
    $viewer = everydayRole(['dam.asset.view'], tools: ['recent']);
    $uploader = everydayRole(['dam.asset.upload'], tools: ['recent']);
    $all = everydayRole(['dam.asset.view', 'dam.asset.upload'], type: 'all');
    $other = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: ['recent']);
    $before = fn () => [
        $named->fresh()->permissions, $manager->fresh()->permissions, $viewer->fresh()->permissions, $uploader->fresh()->permissions, $all->fresh()->permissions,
        DB::table('dam_role_settings')->whereIn('role_id', [$manager->id, $viewer->id, $uploader->id, $all->id])->orderBy('role_id')->get()->all(),
    ];
    $snapshot = $before();

    everydayMigration()->up();

    expect($before())->toEqual($snapshot)
        ->and(everydayKeys($other))->toContain('dam.asset.destroy')
        ->and(DB::table('dam_role_settings')->where('role_id', $named->id)->exists())->toBeFalse();
});

it('changes nothing on a second run', function () {
    $role = everydayRole(EVERYDAY_PROD_USER_KEYS, tools: ['recent', 'stylepress']);
    $migration = everydayMigration();

    $migration->up();
    $afterFirst = [everydayKeys($role), everydaySettings($role), DB::table('dam_configuration')->where('key', EVERYDAY_SNAPSHOT_KEY)->value('value')];
    $migration->up();
    everydayMigration()->up();

    expect([everydayKeys($role), everydaySettings($role), DB::table('dam_configuration')->where('key', EVERYDAY_SNAPSHOT_KEY)->value('value')])->toEqual($afterFirst)
        ->and(array_count_values(everydayKeys($role))['dam.asset.files'])->toBe(1);
});

it('reverts only what it added and keeps keys, tools and settings an admin owns', function () {
    // Already had files and delete-own on by hand, plus a stylepress and the vectorizer tool.
    $role = everydayRole([...EVERYDAY_PROD_USER_KEYS, 'dam.asset.files', 'dam.asset.destroy'], tools: ['recent', 'looper', 'vectorization', 'stylepress']);
    $original = [everydayKeys($role), everydayTools($role)];

    everydayMigration()->up();
    // After the migration an admin ticks another key and another tool by hand.
    DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...everydayKeys($role), 'dam.tags'])]);
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['home_widget_ids' => json_encode([...everydayTools($role), 'storage'])]);

    everydayMigration()->down();

    expect(everydayKeys($role))->toContain('dam.tags', 'dam.asset.files', 'dam.asset.destroy')
        ->and(array_diff(everydayKeys($role), [...$original[0], 'dam.tags']))->toBe([])
        ->and(array_diff($original[0], everydayKeys($role)))->toBe([])
        ->and(everydayTools($role))->toEqualCanonicalizing([...$original[1], 'storage'])
        ->and(everydayTools($role))->toContain('stylepress', 'vectorization')
        ->and(everydayTools($role))->not->toContain('looper', 'rss', 'trending')
        ->and((bool) everydaySettings($role)->delete_own_only)->toBeFalse()
        ->and(DB::table('dam_configuration')->where('key', EVERYDAY_SNAPSHOT_KEY)->exists())->toBeFalse();
});

it('keeps delete_own_only on at rollback when it was already on before', function () {
    $role = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: ['recent'], deleteOwnOnly: true);

    everydayMigration()->up();
    everydayMigration()->down();

    expect((bool) everydaySettings($role)->delete_own_only)->toBeTrue()
        ->and(everydayTools($role))->toBe(['recent']);
});

it('gives a role with a NULL or missing tool list the default free tools and puts that state back on rollback', function () {
    $nullList = everydayRole(['dam.asset.view', 'dam.asset.upload'], tools: null);
    $noRow = everydayRole(['dam.asset.view', 'dam.asset.upload'], row: false);

    everydayMigration()->up();

    expect(everydayTools($nullList))->toBe(HomeWidgetRegistry::defaultWidgetIds())
        ->and(everydayTools($noRow))->toBe(HomeWidgetRegistry::defaultWidgetIds())
        ->and(everydayTools($noRow))->not->toContain('stylepress', 'sphereseam', 'vectorization')
        ->and((bool) everydaySettings($noRow)->delete_own_only)->toBeTrue();

    everydayMigration()->down();

    expect(everydaySettings($nullList)->home_widget_ids)->toBeNull()
        ->and((bool) everydaySettings($nullList)->delete_own_only)->toBeFalse()
        ->and(everydaySettings($noRow))->toBeNull()
        ->and(everydayKeys($noRow))->toBe(['dam.asset.view', 'dam.asset.upload']);
});

it('does nothing on rollback when it never ran', function () {
    $role = everydayRole(EVERYDAY_PROD_USER_KEYS, tools: EVERYDAY_PROD_USER_TOOLS);
    $before = [everydayKeys($role), everydaySettings($role)];

    everydayMigration()->down();

    expect([everydayKeys($role), everydaySettings($role)])->toEqual($before);
});

it('keeps a settings row it created when folder access was edited on it afterwards, and puts its own columns back', function () {
    $role = everydayRole(['dam.asset.view', 'dam.asset.upload'], row: false);
    everydayMigration()->up();
    expect(everydaySettings($role))->not->toBeNull();

    // An administrator then sets folder access on the row the migration made.
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['inherit_children' => true, 'all_directories' => true]);

    everydayMigration()->down();

    $row = everydaySettings($role);
    expect($row)->not->toBeNull()
        ->and((bool) $row->inherit_children)->toBeTrue()
        ->and((bool) $row->all_directories)->toBeTrue()
        ->and($row->home_widget_ids)->toBeNull()
        ->and((bool) $row->delete_own_only)->toBeFalse();
});

it('keeps a created row when only explicit folder ids were edited, and deletes it when nothing else changed', function () {
    $edited = everydayRole(['dam.asset.view', 'dam.asset.upload'], row: false);
    $untouched = everydayRole(['dam.asset.view', 'dam.asset.upload'], row: false);
    everydayMigration()->up();
    DB::table('dam_role_settings')->where('role_id', $edited->id)->update(['explicit_directory_ids' => json_encode([1, 2])]);

    everydayMigration()->down();

    expect(everydaySettings($edited)?->explicit_directory_ids)->not->toBeNull()
        ->and(everydaySettings($untouched))->toBeNull();
});
