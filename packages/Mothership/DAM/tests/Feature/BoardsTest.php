<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/*
 * P4 (D13): personal boards and favorites. A board is its owner's alone: nobody else lists, reads, changes or even
 * learns of it (an id answers like a missing one). A board never grants: a member the owner can no longer open
 * leaves the board's listing and counts, and is back the moment access returns.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->other = Directory::factory()->create(['name' => 'Other']);
    $this->owner = organizeMember($this->open, without: ['dam.labels', 'dam.labels.manage', 'dam.labels.assign']);
    $this->ownerRole = $this->owner->role;
    organizeGrant(role: $this->ownerRole, directory: $this->other);
    $this->a = organizeAsset($this->open, 'alpha');
    $this->b = organizeAsset($this->open, 'bravo');
    $this->elsewhere = organizeAsset($this->other, 'elsewhere');
});

function makeBoard(string $name): int
{
    return (int) test()->postJson(route('admin.dam.boards.store'), ['name' => $name])->assertCreated()->json('data.id');
}

it('lets anyone who uses the Library keep boards, without any label permission', function () {
    $board = makeBoard('Moodboard');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->a->id, $this->elsewhere->id]])->assertOk()->assertJsonPath('data.added', 2);

    $boards = $this->getJson(route('admin.dam.organize.index', ['asset_ids' => [$this->a->id, $this->b->id]]))->assertOk()->json('boards');

    expect($boards)->toHaveCount(1)
        ->and($boards[0])->toMatchArray(['id' => $board, 'name' => 'Moodboard', 'kind' => 'board', 'count' => 2, 'holds' => 1])
        ->and(organizeListedIds(['label_id' => $board, 'sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->a->id, $this->elsewhere->id])
        ->and(Label::query()->find($board)->owner_id)->toBe($this->owner->id);
});

it('keeps a board invisible to everyone else, answering its id like a missing one', function () {
    $board = makeBoard('Private');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->a->id]])->assertOk();

    // A colleague on the same role, and a full admin.
    $colleague = Admin::factory()->create(['status' => 1, 'role_id' => $this->ownerRole->id]);
    $full = Admin::query()->where('id', 1)->first() ?? Admin::factory()->create(['status' => 1]);
    $missing = (int) Label::max('id') + 100;
    config(['app.debug' => false]);

    foreach (['colleague' => $colleague, 'full admin' => $full] as $who => $viewer) {
        $this->actingAs($viewer, 'admin');
        app(DirectoryPermissionService::class)->flush();

        expect($this->getJson(route('admin.dam.organize.index'))->assertOk()->json('boards'))->toBe([], $who);

        $requests = fn (int $id) => [
            'listing' => fn () => $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'label_id' => $id])),
            'filter'  => fn () => $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'filter_labels' => [$id]])),
            'rename'  => fn () => $this->putJson(route('admin.dam.boards.update', $id), ['name' => 'Mine now']),
            'add'     => fn () => $this->postJson(route('admin.dam.boards.assets.store', $id), ['asset_ids' => [$this->a->id]]),
            'remove'  => fn () => $this->deleteJson(route('admin.dam.boards.assets.destroy', $id), ['asset_ids' => [$this->a->id]]),
            'move'    => fn () => $this->putJson(route('admin.dam.boards.assets.update', [$id, $this->a->id]), ['position' => 0]),
            'delete'  => fn () => $this->deleteJson(route('admin.dam.boards.destroy', $id)),
            // A board is not a shared label either.
            'as label' => fn () => $this->putJson(route('admin.dam.labels.update', $id), ['name' => 'Mine now']),
        ];

        foreach ($requests($board) as $name => $send) {
            // The colleague's role has no label permission: the route ACL refuses every id alike, first.
            $status = $name === 'as label' && $who === 'colleague' ? 403 : 404;
            organizeExpectSameAnswer(hidden: $send(), missing: $requests($missing)[$name](), status: $status, what: "{$who} {$name}");
        }
    }

    expect(Label::query()->find($board)->name)->toBe('Private')
        ->and(DB::table('dam_label_assets')->where('label_id', $board)->count())->toBe(1);
});

it('hides a board member the owner can no longer open and brings it back when access returns', function () {
    $board = makeBoard('Shifting');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->a->id, $this->elsewhere->id]])->assertOk();

    organizeRevoke(role: $this->ownerRole, directory: $this->other);

    expect(organizeListedIds(['label_id' => $board]))->toBe([$this->a->id])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('boards.0.count'))->toBe(1)
        // The membership row stays: the board never decides access, and never forgets.
        ->and(DB::table('dam_label_assets')->where('label_id', $board)->count())->toBe(2);

    organizeGrant(role: $this->ownerRole, directory: $this->other);

    expect(organizeListedIds(['label_id' => $board, 'sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->a->id, $this->elsewhere->id])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('boards.0.count'))->toBe(2);
});

it('filters to the viewer\'s favorites, alone and with a search, and never counts a hidden one', function () {
    foreach ([$this->a, $this->b, $this->elsewhere] as $asset) {
        DB::table('dam_asset_favorites')->insert(['admin_id' => $this->owner->id, 'dam_asset_id' => $asset->id, 'created_at' => now()]);
    }
    // Someone else's favorite is not the viewer's.
    $someone = Admin::factory()->create(['status' => 1]);
    $mine = organizeAsset($this->open, 'notmine');
    DB::table('dam_asset_favorites')->insert(['admin_id' => $someone->id, 'dam_asset_id' => $mine->id, 'created_at' => now()]);

    expect(organizeListedIds(['filter_favorites' => 1]))->toEqualCanonicalizing([$this->a->id, $this->b->id, $this->elsewhere->id])
        ->and(organizeListedIds(['filter_favorites' => 1, 'search' => 'bravo']))->toBe([$this->b->id])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('favorites'))->toBe(3);

    organizeRevoke(role: $this->ownerRole, directory: $this->other);

    expect(organizeListedIds(['filter_favorites' => 1]))->toEqualCanonicalizing([$this->a->id, $this->b->id])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('favorites'))->toBe(2);
});

it('deletes a board without touching its files, and an owner\'s boards go with the owner', function () {
    $board = makeBoard('Done');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->a->id]])->assertOk();
    $this->deleteJson(route('admin.dam.boards.destroy', $board))->assertOk();

    expect(Label::query()->find($board))->toBeNull()
        ->and(Asset::query()->whereKey($this->a->id)->exists())->toBeTrue();

    $kept = makeBoard('Kept');
    $this->postJson(route('admin.dam.boards.assets.store', $kept), ['asset_ids' => [$this->a->id]])->assertOk();
    $this->owner->delete();

    expect(Label::query()->find($kept))->toBeNull()
        ->and(DB::table('dam_label_assets')->where('label_id', $kept)->count())->toBe(0)
        ->and(Asset::query()->whereKey($this->a->id)->exists())->toBeTrue();
});

it('calls a board a board when it answers a create, a rename, a delete or a duplicate name', function () {
    $created = $this->postJson(route('admin.dam.boards.store'), ['name' => 'Moodboard'])->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('message'))->toBe(trans('dam::app.admin.organize.labels.created-board'));
    $this->postJson(route('admin.dam.boards.store'), ['name' => 'Moodboard'])->assertUnprocessable()
        ->assertJsonPath('errors.name.0', trans('dam::app.admin.organize.labels.name-taken-board'));
    $this->putJson(route('admin.dam.boards.update', $id), ['name' => 'Mood'])->assertOk()->assertJsonPath('message', trans('dam::app.admin.organize.labels.updated-board'));
    $this->deleteJson(route('admin.dam.boards.destroy', $id))->assertOk()->assertJsonPath('message', trans('dam::app.admin.organize.labels.deleted-board'));
});
