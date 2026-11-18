<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Support\OrganizationNameLock;

/*
 * P4 (D4): shared labels. An asset can hold many labels, labels nest (at most five deep), a label keeps a manual
 * order, and taking a file out of a label (or deleting the label) never touches the file. A file the viewer cannot
 * open is never listed, counted, added or moved through a label, and answers exactly like a missing one.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->closed = Directory::factory()->create(['name' => 'Closed']);
    $this->member = organizeMember($this->open);
    $this->a = organizeAsset($this->open, 'alpha');
    $this->b = organizeAsset($this->open, 'bravo');
    $this->c = organizeAsset($this->open, 'charlie');
    $this->hidden = organizeAsset($this->closed, 'hidden');
});

function makeLabel(string $name, ?int $parentId = null): int
{
    return (int) test()->postJson(route('admin.dam.labels.store'), ['name' => $name, 'parent_id' => $parentId])->assertCreated()->json('data.id');
}

function labelTree(array $assetIds = []): array
{
    return test()->getJson(route('admin.dam.organize.index', ['asset_ids' => $assetIds]))->assertOk()->json('labels');
}

it('gives one asset several labels and lists each label with its own files', function () {
    $brand = makeLabel('Brand');
    $print = makeLabel('Print');

    $this->postJson(route('admin.dam.labels.assets.store', $brand), ['asset_ids' => [$this->a->id, $this->b->id]])->assertOk()->assertJsonPath('data.added', 2);
    $this->postJson(route('admin.dam.labels.assets.store', $print), ['asset_ids' => [$this->a->id]])->assertOk();
    // Adding again keeps one membership.
    $this->postJson(route('admin.dam.labels.assets.store', $print), ['asset_ids' => [$this->a->id]])->assertOk()->assertJsonPath('data.added', 0);

    expect(organizeListedIds(['label_id' => $brand]))->toEqualCanonicalizing([$this->a->id, $this->b->id])
        ->and(organizeListedIds(['label_id' => $print]))->toBe([$this->a->id])
        ->and(DB::table('dam_label_assets')->where('asset_id', $this->a->id)->count())->toBe(2);

    $tree = collect(labelTree([$this->a->id, $this->b->id]))->keyBy('name');
    expect($tree['Brand']['count'])->toBe(2)->and($tree['Brand']['holds'])->toBe(2)
        ->and($tree['Print']['count'])->toBe(1)->and($tree['Print']['holds'])->toBe(1);
});

it('nests labels up to five deep, refuses a cycle, and deleting a parent removes the nested labels but no file', function () {
    $ids = [makeLabel('L1')];

    foreach (range(2, 5) as $depth) {
        $ids[] = makeLabel('L'.$depth, end($ids));
    }

    $this->postJson(route('admin.dam.labels.store'), ['name' => 'L6', 'parent_id' => end($ids)])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->putJson(route('admin.dam.labels.update', $ids[0]), ['parent_id' => $ids[2]])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    $tree = labelTree();
    expect($tree)->toHaveCount(1)->and($tree[0]['children'][0]['children'][0]['name'])->toBe('L3');

    $this->postJson(route('admin.dam.labels.assets.store', $ids[3]), ['asset_ids' => [$this->a->id]])->assertOk();
    $this->deleteJson(route('admin.dam.labels.destroy', $ids[1]))->assertOk()->assertJsonPath('data.deleted', 4);

    expect(Label::query()->pluck('id')->all())->toBe([$ids[0]])
        ->and(DB::table('dam_label_assets')->count())->toBe(0)
        ->and(Asset::query()->whereKey($this->a->id)->exists())->toBeTrue()
        ->and($this->open->assets()->whereKey($this->a->id)->exists())->toBeTrue();
});

it('keeps a manual order that a move changes and the position sort lists', function () {
    $label = makeLabel('Ordered');
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->a->id, $this->b->id, $this->c->id]])->assertOk();

    expect(organizeListedIds(['label_id' => $label, 'sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->a->id, $this->b->id, $this->c->id]);

    $this->putJson(route('admin.dam.labels.assets.update', [$label, $this->c->id]), ['position' => 0])->assertOk();
    $ids = organizeListedIds(['label_id' => $label, 'sort_by' => 'position', 'sort_order' => 'asc'], $response);

    expect($ids)->toBe([$this->c->id, $this->a->id, $this->b->id])
        ->and(array_column($response->json('assets'), 'member_position'))->toBe([0, 1, 2])
        ->and($response->json('meta.organization'))->toMatchArray(['type' => 'label', 'id' => $label, 'name' => 'Ordered'])
        ->and($response->json('directories'))->toBe([]);

    // Reordering siblings in the tree.
    $second = makeLabel('Second');
    $this->putJson(route('admin.dam.labels.update', $second), ['position' => 0])->assertOk();
    expect(array_column(labelTree(), 'name'))->toBe(['Second', 'Ordered']);
});

it('takes a file out of a label without touching the file', function () {
    $label = makeLabel('Temporary');
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->a->id, $this->b->id]])->assertOk();

    $this->deleteJson(route('admin.dam.labels.assets.destroy', $label), ['asset_ids' => [$this->a->id]])->assertOk()->assertJsonPath('data.removed', 1);

    expect(organizeListedIds(['label_id' => $label]))->toBe([$this->b->id])
        ->and(Asset::query()->whereKey($this->a->id)->exists())->toBeTrue()
        ->and($this->open->assets()->whereKey($this->a->id)->exists())->toBeTrue()
        ->and(organizeListedIds())->toContain($this->a->id);
});

it('never lists, counts, adds or moves a file the viewer cannot open, and answers it like a missing one', function () {
    $label = makeLabel('Mixed');
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->a->id]])->assertOk();
    // Someone who can open the hidden file labelled it too.
    DB::table('dam_label_assets')->insert(['label_id' => $label, 'asset_id' => $this->hidden->id, 'position' => 1, 'created_at' => now()]);

    expect(organizeListedIds(['label_id' => $label]))->toBe([$this->a->id])
        ->and(organizeListedIds(['filter_labels' => [$label]]))->toBe([$this->a->id])
        ->and(labelTree([$this->a->id, $this->hidden->id])[0])->toMatchArray(['count' => 1, 'holds' => 1]);

    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 100;
    organizeExpectSameAnswer(
        hidden: $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->b->id, $this->hidden->id]]),
        missing: $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->b->id, $missingId]]),
        status: 404, what: 'add',
    );
    organizeExpectSameAnswer(
        hidden: $this->putJson(route('admin.dam.labels.assets.update', [$label, $this->hidden->id]), ['position' => 0]),
        missing: $this->putJson(route('admin.dam.labels.assets.update', [$label, $missingId]), ['position' => 0]),
        status: 404, what: 'move',
    );
    // Nothing was added by the refused request; removing a hidden member is a no-op.
    $this->deleteJson(route('admin.dam.labels.assets.destroy', $label), ['asset_ids' => [$this->hidden->id]])->assertOk()->assertJsonPath('data.removed', 0);
    expect(DB::table('dam_label_assets')->where('label_id', $label)->pluck('asset_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$this->a->id, $this->hidden->id])->sort()->values()->all());

    // A move among the visible members leaves the hidden member's place alone.
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->b->id]])->assertOk();
    $this->putJson(route('admin.dam.labels.assets.update', [$label, $this->b->id]), ['position' => 0])->assertOk();
    expect(organizeListedIds(['label_id' => $label, 'sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->b->id, $this->a->id]);
});

it('filters by labels, all of them by default or any of them, alone and combined with a search', function () {
    $brand = makeLabel('Brand');
    $print = makeLabel('Print');
    $this->postJson(route('admin.dam.labels.assets.store', $brand), ['asset_ids' => [$this->a->id, $this->b->id]])->assertOk();
    $this->postJson(route('admin.dam.labels.assets.store', $print), ['asset_ids' => [$this->a->id, $this->c->id]])->assertOk();

    expect(organizeListedIds(['filter_labels' => [$brand]]))->toEqualCanonicalizing([$this->a->id, $this->b->id])
        ->and(organizeListedIds(['filter_labels' => [$brand, $print]]))->toBe([$this->a->id])
        ->and(organizeListedIds(['filter_labels' => [$brand, $print], 'filter_labels_mode' => 'any']))->toEqualCanonicalizing([$this->a->id, $this->b->id, $this->c->id])
        ->and(organizeListedIds(['filter_labels' => [$brand], 'search' => 'bravo']))->toBe([$this->b->id])
        ->and(organizeListedIds(['filter_labels' => [$brand, $print], 'filter_labels_mode' => 'any', 'search' => 'charlie']))->toBe([$this->c->id]);
});

it('needs dam.labels.manage to change labels and dam.labels.assign to change what they hold', function () {
    $label = makeLabel('Locked');

    organizeMember($this->open, without: ['dam.labels.manage']);
    $this->postJson(route('admin.dam.labels.store'), ['name' => 'Nope'])->assertForbidden();
    $this->putJson(route('admin.dam.labels.update', $label), ['name' => 'Nope'])->assertForbidden();
    $this->deleteJson(route('admin.dam.labels.destroy', $label))->assertForbidden();
    $this->postJson(route('admin.dam.labels.assets.store', $label), ['asset_ids' => [$this->a->id]])->assertOk();

    organizeMember($this->open, without: ['dam.labels.assign']);
    $this->deleteJson(route('admin.dam.labels.assets.destroy', $label), ['asset_ids' => [$this->a->id]])->assertForbidden();
    $this->putJson(route('admin.dam.labels.assets.update', [$label, $this->a->id]), ['position' => 0])->assertForbidden();

    // Everyone who uses the Library sees the labels.
    expect(array_column(labelTree(), 'name'))->toBe(['Locked']);
});

it('refuses a duplicate sibling name, case-insensitively', function () {
    makeLabel('Campaign');

    $this->postJson(route('admin.dam.labels.store'), ['name' => 'campaign'])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('runs create, rename and move under the same scope lock, and refuses a clash case-insensitively', function () {
    $alpha = makeLabel('Alpha');
    $beta = makeLabel('Beta');
    $parent = makeLabel('Parent');
    makeLabel('beta', $parent);
    $anchor = fn (string $scope) => DB::table('dam_configuration')->where('key', OrganizationNameLock::anchorKey($scope))->exists();

    // Every write path takes the one anchor row of the shared scope (a database row lock, held to commit).
    DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->delete();
    $this->postJson(route('admin.dam.labels.store'), ['name' => 'Gamma'])->assertCreated();
    expect($anchor('label.shared.shared'))->toBeTrue();

    DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->delete();
    $this->putJson(route('admin.dam.labels.update', $alpha), ['name' => 'Alpha prime'])->assertOk();
    expect($anchor('label.shared.shared'))->toBeTrue();

    DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->delete();
    $empty = makeLabel('Empty');
    DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->delete();
    $this->putJson(route('admin.dam.labels.update', $beta), ['parent_id' => $empty])->assertOk();
    expect($anchor('label.shared.shared'))->toBeTrue()
        ->and(Label::query()->find($beta)->parent_id)->toBe($empty);

    // A move into a parent that already holds the name is refused (the failed change rolls back, anchor row included).
    $this->putJson(route('admin.dam.labels.update', $beta), ['parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(Label::query()->find($beta)->parent_id)->toBe($empty);

    // The check is judged against what is committed, ignoring case.
    $this->putJson(route('admin.dam.labels.update', $empty), ['name' => 'ALPHA PRIME'])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('applies a name or parent change to the stored row, not to the copy loaded before it waited', function (string $case) {
    $service = app(LabelService::class);
    $one = makeLabel('One');
    $two = makeLabel('Two');
    $id = makeLabel('Original', $case === 'name' || $case === 'name clash' ? null : $one);
    $staleOne = Label::query()->findOrFail($id);
    $staleTwo = Label::query()->findOrFail($id);
    $viewer = $this->member->id ?? null;

    $change = fn (Label $label, array $attributes) => $service->update(label: $label, attributes: $attributes, viewerId: $viewer);

    if ($case === 'name' || $case === 'name clash') {
        $change($staleOne, ['name' => 'Changed']);
        $case === 'name clash' && makeLabel('original');
        $restore = fn () => $change($staleTwo, ['name' => 'Original']);
    } else {
        $change($staleOne, ['parent_id' => $two]);
        $case === 'parent clash' && makeLabel('original', $one);
        $restore = fn () => $change($staleTwo, ['parent_id' => $one]);
    }

    if (str_ends_with($case, 'clash')) {
        // The destination now holds the name: refused, and the row stays where the first request put it.
        expect($restore)->toThrow(ValidationException::class);
        $stored = Label::query()->findOrFail($id);
        expect($case === 'name clash' ? $stored->name : (int) $stored->parent_id)->toBe($case === 'name clash' ? 'Changed' : $two);

        return;
    }

    $restore();
    $stored = Label::query()->findOrFail($id);
    expect($case === 'name' ? $stored->name : (int) $stored->parent_id)->toBe($case === 'name' ? 'Original' : $one);
})->with(['name', 'parent', 'name clash', 'parent clash']);
