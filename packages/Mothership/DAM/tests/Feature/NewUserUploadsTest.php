<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\NewUserUploads;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Operator decision 2026-09-30: users upload anywhere they can, every file shows its owner, and admins can gather
 * what other users added into a "To Sort" folder. A non-admin upload is flagged dam_assets.needs_sorting_at; the
 * admin-only "New user uploads" page lists the flagged files and sorts them.
 */

beforeEach(function () {
    Queue::fake();
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);

    // The production access model (ADR 0010): an asset's own list decides who sees it.
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'lists']);
    app(AccessModel::class)->flush();

    $this->root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    $this->folder = Directory::create(['name' => 'Uploads '.uniqid(), 'parent_id' => null]);
    $this->admin = sortingUser('all');
});

function sortingUser(string $permissionType): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $permissionType,
        'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.upload', 'dam.asset.download'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** A real upload through the publisher, made as $owner so created_by is theirs. */
function sortingUpload(Directory $folder, string $disk, string $name, ?Admin $owner = null): Asset
{
    if ($owner) {
        test()->actingAs($owner, 'admin');
    }

    return app(AssetUploadPublisher::class)->publishUploadedFile([
        'directory'      => $folder,
        'original_name'  => $name,
        'mime_type'      => 'image/jpeg',
        'extension'      => 'jpg',
        'source'         => UploadedFile::fake()->createWithContent($name, 'bytes-'.$name),
        'disk'           => $disk,
        'existing_asset' => null,
    ]);
}

/** A flagged file owned by $owner, private to them. */
function sortingFlagged(Directory $folder, Admin $owner, string $disk): Asset
{
    return sortingUpload($folder, $disk, 'f-'.uniqid().'.jpg', $owner)->refresh();
}

function sortingVisibleIds(Admin $as, array $params = []): array
{
    test()->actingAs($as, 'admin');

    return collect(test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100] + $params))->assertOk()->json('assets'))->pluck('id')->all();
}

function sortingCanDownload(Admin $as, Asset $asset): bool
{
    test()->actingAs($as, 'admin');

    return test()->get(route('admin.dam.assets.download', $asset->id))->getStatusCode() === 200;
}

it('flags a direct upload by a role-User, and not one by an admin', function () {
    expect(sortingUpload($this->folder, $this->disk, 'user.jpg', sortingUser('custom'))->needs_sorting_at)->not->toBeNull()
        ->and(sortingUpload($this->folder, $this->disk, 'admin.jpg', $this->admin)->fresh()->needs_sorting_at)->toBeNull();
});

it('flags files uploaded through the real upload route by a role-User, and not by an admin', function () {
    $user = sortingUser('custom');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $user->id]);

    $post = fn (Admin $as, string $name) => tap($this->actingAs($as, 'admin')->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image($name, 40, 40)],
        'directory_id' => $this->folder->id,
    ]))->assertStatus(201)->json('files.0.id');

    expect(Asset::find($post($user, 'route-user.png'))->needs_sorting_at)->not->toBeNull()
        ->and(Asset::find($post($this->admin, 'route-admin.png'))->needs_sorting_at)->toBeNull();
});

it('flags a chunk upload finalized for a role-User (queued, no session), and not one for an admin', function () {
    $finalize = function (Admin $owner, string $name): Asset {
        $staged = app(ContentStore::class)->stagingPath('jpg');
        Storage::disk($this->disk)->put($staged, 'chunked-'.$name);
        auth()->forgetGuards();

        return app(AssetUploadPublisher::class)->publishStagedFile([
            'directory'     => $this->folder,
            'original_name' => $name,
            'mime_type'     => 'image/jpeg',
            'extension'     => 'jpg',
            'source'        => $staged,
            'disk'          => $this->disk,
            'owner_id'      => $owner->id,
        ])->fresh();
    };

    expect($finalize(sortingUser('custom'), 'chunk-user.jpg')->needs_sorting_at)->not->toBeNull()
        ->and($finalize($this->admin, 'chunk-admin.jpg')->needs_sorting_at)->toBeNull();
});

it('refuses the New user uploads page and its actions to anyone but an admin', function () {
    $this->actingAs(sortingUser('custom'), 'admin');

    $this->get(route('admin.dam.new-uploads.index'))->assertForbidden();
    $this->getJson(route('admin.dam.new-uploads.list'))->assertForbidden();
    $this->postJson(route('admin.dam.new-uploads.move'), ['asset_ids' => [1]])->assertForbidden();
    $this->postJson(route('admin.dam.new-uploads.mark-sorted'), ['asset_ids' => [1]])->assertForbidden();

    $this->actingAs($this->admin, 'admin');

    $this->get(route('admin.dam.new-uploads.index'))->assertOk();
});

it('rejects a bulk action of more than 1,000 ids', function () {
    $this->actingAs($this->admin, 'admin');

    $this->postJson(route('admin.dam.new-uploads.mark-sorted'), ['asset_ids' => range(1, 1001)])->assertStatus(422);
    $this->postJson(route('admin.dam.new-uploads.mark-sorted'), ['asset_ids' => range(1, 1000)])->assertOk();
});

it('lists only flagged files, with their owner and folder', function () {
    $owner = sortingUser('custom');
    $flagged = sortingFlagged($this->folder, $owner, $this->disk);
    Asset::query()->whereKey(sortingUpload($this->folder, $this->disk, 'plain.jpg', $this->admin)->id)->update(['needs_sorting_at' => null]);

    $this->actingAs($this->admin, 'admin');

    $rows = $this->getJson(route('admin.dam.new-uploads.list'))->assertOk()->json('assets');

    expect(collect($rows)->pluck('id')->all())->toBe([$flagged->id])
        ->and($rows[0]['owner'])->toBe($owner->name)
        ->and($rows[0]['folder'])->toBe($this->folder->name);
});

it('moves flagged files into a To Sort folder created once, keeps them with their owner and admins only, and clears the flag', function () {
    $owner = sortingUser('custom');
    $viewer = sortingUser('custom');
    $groupMember = sortingUser('custom');
    $group = DB::table('dam_groups')->insertGetId(['name' => 'Sort group '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $groupMember->id]);

    // The upload folder is shared with a user and a group, so the new file starts visible to both.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $viewer->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $this->folder->id, 'group_id' => $group]);
    $first = sortingFlagged($this->folder, $owner, $this->disk);
    $second = sortingFlagged($this->folder, $owner, $this->disk);

    expect(sortingVisibleIds($viewer))->toContain($first->id)
        ->and(sortingCanDownload($groupMember, $first))->toBeTrue();

    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.new-uploads.move'), ['asset_ids' => [$first->id, 999999]])
        ->assertOk()->assertJson(['moved' => 1, 'ignored' => 1]);

    $sort = Directory::where('name', 'To Sort')->get();
    expect($sort)->toHaveCount(1)
        ->and($sort->first()->parent_id)->toBe($this->root->id)
        ->and($first->fresh()->directories()->pluck('dam_directories.id')->all())->toBe([$sort->first()->id])
        ->and($first->fresh()->needs_sorting_at)->toBeNull()
        ->and($first->fresh()->created_by)->toBe($owner->id)
        ->and($second->fresh()->needs_sorting_at)->not->toBeNull();

    // Behaviour, not rows: the owner and an admin still list and download it; the former viewers do not.
    expect(sortingVisibleIds($owner))->toContain($first->id)
        ->and(sortingCanDownload($owner, $first))->toBeTrue()
        ->and(sortingVisibleIds($this->admin))->toContain($first->id)
        ->and(sortingCanDownload($this->admin, $first))->toBeTrue()
        ->and(sortingVisibleIds($viewer))->not->toContain($first->id)
        ->and(sortingCanDownload($viewer, $first))->toBeFalse()
        ->and(sortingVisibleIds($groupMember))->not->toContain($first->id)
        ->and(sortingCanDownload($groupMember, $first))->toBeFalse();

    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.new-uploads.move'), ['asset_ids' => [$second->id]])->assertOk()->assertJson(['moved' => 1]);

    expect(Directory::where('name', 'To Sort')->count())->toBe(1)
        ->and($second->fresh()->needs_sorting_at)->toBeNull();
});

it('marks files sorted without moving them', function () {
    $first = sortingFlagged($this->folder, sortingUser('custom'), $this->disk);

    $this->actingAs($this->admin, 'admin');

    $this->postJson(route('admin.dam.new-uploads.mark-sorted'), ['asset_ids' => [$first->id, 999999]])
        ->assertOk()->assertJson(['cleared' => 1, 'ignored' => 1]);

    expect($first->fresh()->needs_sorting_at)->toBeNull()
        ->and($first->fresh()->directories()->pluck('dam_directories.id')->all())->toBe([$this->folder->id])
        ->and(Directory::where('name', 'To Sort')->exists())->toBeFalse();
});

// A folder somebody else already called "To Sort" is never the sorting folder: moving private uploads into it
// would hand them its viewers.
it('never gives a pre-existing To Sort folder\'s viewers the moved files', function (string $shape) {
    $owner = sortingUser('custom');
    $viewer = sortingUser('custom');
    $group = DB::table('dam_groups')->insertGetId(['name' => 'Squatter '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $viewer->id]);
    $everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');

    $this->actingAs($shape === 'non-admin-owned' ? $viewer : $this->admin, 'admin');
    $squatter = Directory::create(['name' => 'To Sort', 'parent_id' => $this->root->id]);
    DB::table('dam_directory_user_access')->where('directory_id', $squatter->id)->delete();
    DB::table('dam_directory_group_access')->where('directory_id', $squatter->id)->delete();

    match ($shape) {
        'user-shared'  => DB::table('dam_directory_user_access')->insert(['directory_id' => $squatter->id, 'admin_id' => $viewer->id]),
        'group-shared' => DB::table('dam_directory_group_access')->insert(['directory_id' => $squatter->id, 'group_id' => $group]),
        'everyone'     => DB::table('dam_directory_group_access')->insert(['directory_id' => $squatter->id, 'group_id' => $everyone]),
        default        => null,
    };

    $file = sortingFlagged($this->folder, $owner, $this->disk);

    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.new-uploads.move'), ['asset_ids' => [$file->id]])->assertOk()->assertJson(['moved' => 1]);

    $target = $file->fresh()->directories()->first();
    expect($target->id)->not->toBe($squatter->id)
        ->and($target->name)->toBe('To Sort (2)')
        ->and(sortingVisibleIds($owner))->toContain($file->id)
        ->and(sortingCanDownload($owner, $file))->toBeTrue()
        ->and(sortingCanDownload($this->admin, $file))->toBeTrue()
        ->and(sortingVisibleIds($viewer))->not->toContain($file->id)
        ->and(sortingCanDownload($viewer, $file))->toBeFalse();
})->with(['user-shared', 'group-shared', 'everyone', 'non-admin-owned']);

it('makes one admins-only sorting folder however often it is asked for, and a new one when it is deleted', function () {
    $service = app(NewUserUploads::class);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->root->id, 'admin_id' => sortingUser('custom')->id]);

    $first = $service->sortFolder(adminId: $this->admin->id);

    expect($service->sortFolder(adminId: $this->admin->id)->id)->toBe($first->id)
        ->and(Directory::where('name', 'To Sort')->count())->toBe(1)
        ->and(DB::table('dam_directory_user_access')->where('directory_id', $first->id)->count())->toBe(0)
        ->and(DB::table('dam_directory_group_access')->where('directory_id', $first->id)->count())->toBe(0);

    $first->delete();

    $replacement = $service->sortFolder(adminId: $this->admin->id);

    expect($replacement->id)->not->toBe($first->id)
        ->and($replacement->parent_id)->toBe($this->root->id)
        ->and((int) DB::table('dam_configuration')->where('key', NewUserUploads::CONFIG_KEY)->value('value'))->toBe($replacement->id);
});

it('empties the sorting folder again before a move when an admin shared it in between', function () {
    $owner = sortingUser('custom');
    $viewer = sortingUser('custom');
    $group = DB::table('dam_groups')->insertGetId(['name' => 'Later '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $viewer->id]);

    $sort = app(NewUserUploads::class)->sortFolder(adminId: $this->admin->id);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $sort->id, 'admin_id' => $viewer->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $sort->id, 'group_id' => $group]);

    $file = sortingFlagged($this->folder, $owner, $this->disk);

    $this->actingAs($this->admin, 'admin');
    $this->postJson(route('admin.dam.new-uploads.move'), ['asset_ids' => [$file->id]])->assertOk()->assertJson(['moved' => 1]);

    expect($file->fresh()->directories()->pluck('dam_directories.id')->all())->toBe([$sort->id])
        ->and(sortingVisibleIds($viewer))->not->toContain($file->id)
        ->and(sortingCanDownload($viewer, $file))->toBeFalse()
        ->and(sortingCanDownload($owner, $file))->toBeTrue();
});

it('returns only the signed-in role-User\'s own files for Uploaded by: Me, never anything they cannot see', function () {
    $me = sortingUser('custom');
    $other = sortingUser('custom');
    $mine = sortingUpload($this->folder, $this->disk, 'mine.jpg', $me);
    $hidden = sortingUpload($this->folder, $this->disk, 'hidden.jpg', $other);
    $shared = sortingUpload($this->folder, $this->disk, 'shared.jpg', $other);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $shared->id, 'admin_id' => $me->id]);

    $all = sortingVisibleIds($me);
    $mineOnly = sortingVisibleIds($me, ['filter_uploaded_by' => 'me']);

    expect($all)->toContain($mine->id, $shared->id)->not->toContain($hidden->id)
        ->and($mineOnly)->toBe([$mine->id]);

    $row = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->json('assets'))->firstWhere('id', $shared->id);
    expect($row['owner_name'])->toBe($other->name);
});

it('shows the uploader on the info panel data and the edit page', function () {
    // A fixed name with an apostrophe and markup, not Faker's: a random "O'Kon" once failed CI because the
    // default edit tab only carries the name inside @json props (O'Kon). The Blade row is on ?properties.
    $owner = sortingUser('custom');
    $owner->forceFill(['name' => "Gino O'Kon <b>x</b>"])->save();
    $asset = sortingUpload($this->folder, $this->disk, 'info.jpg', $owner);

    $this->actingAs($this->admin, 'admin');

    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('uploaded_by', $owner->name);
    $this->get(route('admin.dam.assets.edit', $asset->id).'?properties')->assertOk()
        ->assertSee('<dd class="text-xs font-medium text-gray-800 dark:text-gray-100 text-right break-words">Gino O&#039;Kon &lt;b&gt;x&lt;/b&gt;</dd>', false)
        ->assertDontSee('<b>x</b>', false);
});
