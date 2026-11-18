<?php

use Illuminate\Support\Facades\DB;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

const QUICKVIEW_CANONICAL_WIDGET = ['id' => 'system-quickview', 'size' => 'wide', 'columns' => 4, 'rows' => 1, 'grid_version' => 2, 'settings' => []];

// `require`, never `require_once`: every call must hand back a fresh anonymous migration instance.
function quickViewMigration(): object
{
    return require __DIR__.'/../../src/Database/Migrations/2026_09_20_000001_append_system_quickview_to_home_layouts.php';
}

function storeHomeLayout(string $widgetsJson, int $revision = 0): int
{
    $adminId = Admin::factory()->create()->id;
    DB::table('dam_home_layouts')->insert(['admin_id' => $adminId, 'widgets' => $widgetsJson, 'revision' => $revision, 'created_at' => now(), 'updated_at' => now()]);

    return $adminId;
}

function storedHomeLayout(int $adminId): object
{
    return DB::table('dam_home_layouts')->where('admin_id', $adminId)->first();
}

function storedHomeWidgets(int $adminId): array
{
    return json_decode(storedHomeLayout($adminId)->widgets, true, 512, JSON_THROW_ON_ERROR);
}

it('appends the canonical widget once, preserving the original widgets in order, and bumps the revision', function () {
    $original = [
        ['id' => 'rss', 'size' => 'wide', 'columns' => 4, 'rows' => 1, 'grid_version' => 2, 'settings' => ['feed_id' => 7, 'count' => 5]],
        ['id' => 'links', 'size' => 'small', 'columns' => 2, 'rows' => 1, 'grid_version' => 2, 'settings' => ['links' => [['title' => 'Example', 'url' => 'https://example.org']]]],
    ];
    $adminId = storeHomeLayout(json_encode($original), 4);

    quickViewMigration()->up();

    expect(storedHomeWidgets($adminId))->toEqual([...$original, QUICKVIEW_CANONICAL_WIDGET])
        ->and((int) storedHomeLayout($adminId)->revision)->toBe(5);
});

it('makes no further change when run again', function () {
    $adminId = storeHomeLayout(json_encode([['id' => 'rss', 'size' => 'wide', 'settings' => []]]), 4);

    quickViewMigration()->up();
    $afterFirst = storedHomeLayout($adminId);
    quickViewMigration()->up();
    $afterSecond = storedHomeLayout($adminId);

    expect($afterSecond->widgets)->toBe($afterFirst->widgets)
        ->and((int) $afterSecond->revision)->toBe(5);
});

it('leaves a layout that already has the widget unchanged at any position', function (int $position) {
    $widgets = [['id' => 'rss', 'size' => 'wide', 'settings' => []], ['id' => 'links', 'size' => 'small', 'settings' => []]];
    array_splice($widgets, $position, 0, [['id' => 'system-quickview', 'size' => 'large', 'settings' => []]]);
    $adminId = storeHomeLayout(json_encode($widgets), 3);
    $before = storedHomeLayout($adminId)->widgets;

    quickViewMigration()->up();

    expect(storedHomeLayout($adminId)->widgets)->toBe($before)
        ->and((int) storedHomeLayout($adminId)->revision)->toBe(3);
})->with([0, 1, 2]);

it('gives an empty valid layout exactly one canonical widget', function () {
    $adminId = storeHomeLayout('[]', 1);

    quickViewMigration()->up();

    expect(storedHomeWidgets($adminId))->toEqual([QUICKVIEW_CANONICAL_WIDGET])
        ->and((int) storedHomeLayout($adminId)->revision)->toBe(2);
});

it('transforms multiple layout rows independently', function () {
    $plain = storeHomeLayout(json_encode([['id' => 'rss', 'size' => 'wide', 'settings' => []]]), 0);
    $already = storeHomeLayout(json_encode([QUICKVIEW_CANONICAL_WIDGET]), 9);
    $empty = storeHomeLayout('[]', 2);

    quickViewMigration()->up();

    expect(array_column(storedHomeWidgets($plain), 'id'))->toEqual(['rss', 'system-quickview'])
        ->and((int) storedHomeLayout($plain)->revision)->toBe(1)
        ->and(storedHomeWidgets($already))->toEqual([QUICKVIEW_CANONICAL_WIDGET])
        ->and((int) storedHomeLayout($already)->revision)->toBe(9)
        ->and(storedHomeWidgets($empty))->toEqual([QUICKVIEW_CANONICAL_WIDGET])
        ->and((int) storedHomeLayout($empty)->revision)->toBe(3);
});

it('leaves valid non-layout JSON byte-for-byte unchanged with its revision', function (string $stored) {
    $adminId = storeHomeLayout($stored, 6);
    $before = storedHomeLayout($adminId)->widgets;

    quickViewMigration()->up();

    expect(storedHomeLayout($adminId)->widgets)->toBe($before)
        ->and((int) storedHomeLayout($adminId)->revision)->toBe(6);
})->with([
    'object'               => ['{"id":"rss"}'],
    'empty object'         => ['{}'],
    'numeric-key object'   => ['{"0":{"id":"rss"}}'],
    'list of scalars'      => ['["rss","links"]'],
    'widget without an id' => ['[{"size":"wide"}]'],
    'non-string id'        => ['[{"id":5}]'],
]);

// Stored JSON columns refuse malformed text on some engines, so the transform itself carries the
// malformed and non-layout branches identically for every database.
it('rejects malformed and non-layout JSON in the transform', function (string $input) {
    expect(quickViewMigration()->appendIfValid($input))->toBeNull();
})->with([
    'truncated'       => ['[{"id":"rss"'],
    'empty string'    => [''],
    'not json'        => ['widgets'],
    'object'          => ['{"id":"rss"}'],
    'empty object'    => ['{}'],
    'numeric object'  => ['{"0":{"id":"rss"}}'],
    'scalar'          => ['7'],
    'null literal'    => ['null'],
    'list of scalars' => ['["rss"]'],
    'missing id'      => ['[{"size":"wide"}]'],
    'non-string id'   => ['[{"id":5}]'],
]);

it('returns layout JSON holding at most one system widget from the transform', function () {
    $migration = quickViewMigration();
    $appended = $migration->appendIfValid('[{"id":"rss","size":"wide","settings":[]}]');
    $already = '[{"id":"system-quickview","size":"large","settings":[]},{"id":"rss","size":"wide","settings":[]}]';

    expect(json_decode($appended, true))->toEqual([['id' => 'rss', 'size' => 'wide', 'settings' => []], QUICKVIEW_CANONICAL_WIDGET])
        ->and($migration->appendIfValid($appended))->toBe($appended)
        ->and($migration->appendIfValid($already))->toBe($already)
        ->and(json_decode($migration->appendIfValid('[]'), true))->toEqual([QUICKVIEW_CANONICAL_WIDGET]);
});

it('does not rewrite layouts on rollback', function () {
    $adminId = storeHomeLayout('[]', 0);
    quickViewMigration()->up();
    $before = storedHomeLayout($adminId);

    quickViewMigration()->down();

    expect(storedHomeLayout($adminId)->widgets)->toBe($before->widgets)
        ->and((int) storedHomeLayout($adminId)->revision)->toBe((int) $before->revision);
});

it('puts the widget on the fixed Home once for every account', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    $ids = array_column($this->getJson('/admin/home/layout')->assertOk()->json('widgets'), 'id');

    expect(array_keys($ids, 'system-quickview'))->toHaveCount(1);
});

it('lets a custom role with no allowlist entry fetch the widget', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["rss"]']);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    expect(array_column($this->getJson('/admin/home/layout')->assertOk()->json('catalog'), 'id'))->toContain('system-quickview');
    $this->getJson('/admin/home/data/system-quickview')->assertOk();
});
