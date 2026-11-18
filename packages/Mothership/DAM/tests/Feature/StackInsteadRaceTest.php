<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P3b-2 review: Stack instead decides twice. Whatever changes between the first checks and the row locks (access,
 * availability, trash, the actor's keys, the upload's stack) is caught by the second pass on the locked, freshly
 * read rows: the answer is the one a missing entry gets, and nothing is attached or trashed. The changes are made
 * from the StackInstead::EVENT_LOCKING seam, which fires exactly between the two passes.
 */

function stackRaceAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

function stackRacePng(string $seed): string
{
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, crc32($seed) & 0xFFFFFF);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function stackRaceUpload(object $test, Directory $folder, string $name, string $bytes, array $headers = [], string $route = 'admin.dam.assets.upload'): int
{
    return (int) $test->withHeaders($headers)->post(route($route), [
        'directory_id' => $folder->id,
        'files'        => [UploadedFile::fake()->createWithContent($name, $bytes)],
    ], ['Accept' => 'application/json'])->assertCreated()->json('files.0.id');
}

/**
 * Under grants: the target in folder B, the member's upload of the same bytes in folder A, both folders granted to
 * the member's role, which holds every Library key.
 *
 * @return array{member: Admin, role: Role, target: int, upload: int, folderB: Directory}
 */
function stackRaceSetup(object $test): array
{
    stackRaceAccessModel(AccessModel::GRANTS);
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $folderA = Directory::factory()->create(['name' => 'Race A '.Str::random(4), 'parent_id' => $root->id]);
    $folderB = Directory::factory()->create(['name' => 'Race B '.Str::random(4), 'parent_id' => $root->id]);
    $bytes = stackRacePng(Str::random(8));
    $target = stackRaceUpload($test, $folderB, 'original.png', $bytes);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->values()->all()]);
    $member = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
    foreach ([$folderA, $folderB] as $folder) {
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $test->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $upload = stackRaceUpload($test, $folderA, 'copy.png', $bytes);

    return ['member' => $member, 'role' => $role, 'target' => $target, 'upload' => $upload, 'folderB' => $folderB];
}

/**
 * What Stack instead writes, and a refused one must not: a slot on the target, and the Trash move of the upload
 * (its path and who moved it; a race that trashes the upload itself leaves both as they were).
 */
function stackRaceState(array $setup): array
{
    $upload = Asset::withTrashed()->find($setup['upload']);

    return [
        'target_files' => AssetFile::where('dam_asset_id', $setup['target'])->orderBy('id')->pluck('id')->all(),
        'upload_path'  => $upload->path,
        'deleted_by'   => $upload->deleted_by,
    ];
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->loginAsAdmin();
});

it('stacks when nothing changes between the first checks and the lock', function () {
    $setup = stackRaceSetup($this);
    $fired = 0;
    Event::listen(StackInstead::EVENT_LOCKING, function () use (&$fired) {
        $fired++;
    });

    $this->postJson(route('admin.dam.assets.stack-instead', $setup['upload']), ['target_id' => $setup['target']])->assertOk();

    expect($fired)->toBe(1)->and(Asset::find($setup['upload']))->toBeNull()
        ->and(AssetFile::where('dam_asset_id', $setup['target'])->where('role', 'other')->count())->toBe(1);
});

it('answers like a missing entry, and changes nothing, when the data changes before the lock', function (Closure $change) {
    $setup = stackRaceSetup($this);
    $missing = $this->postJson(route('admin.dam.assets.stack-instead', $setup['upload']), ['target_id' => Asset::withTrashed()->max('id') + 1000]);
    $before = stackRaceState($setup);
    Event::listen(StackInstead::EVENT_LOCKING, fn () => $change($setup));

    $response = $this->postJson(route('admin.dam.assets.stack-instead', $setup['upload']), ['target_id' => $setup['target']]);

    expect($response->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
        ->and($response->json('message'))->toBe($missing->json('message'))
        ->and(stackRaceState($setup))->toBe($before);
})->with([
    'the target folder grant is revoked'   => [fn (array $setup) => DB::table('dam_directory_role')->where('directory_id', $setup['folderB']->id)->delete()],
    'the target becomes a draft'           => [fn (array $setup) => Asset::where('id', $setup['target'])->update(['availability' => Asset::AVAILABILITY_DRAFT])],
    'the target expires'                   => [fn (array $setup) => Asset::where('id', $setup['target'])->update(['available_until' => now()->subMinute()])],
    'the target is trashed'                => [fn (array $setup) => Asset::where('id', $setup['target'])->update(['deleted_at' => now()])],
    'the upload is trashed'                => [fn (array $setup) => Asset::where('id', $setup['upload'])->update(['deleted_at' => now()])],
    'the upload becomes a draft'           => [fn (array $setup) => Asset::where('id', $setup['upload'])->update(['availability' => Asset::AVAILABILITY_DRAFT])],
    'the upload is stacked'                => [fn (array $setup) => Asset::where('id', $setup['upload'])->update(['stack_basis' => 'product', 'stack_key' => 'Menu'])],
    'the role loses the delete key'        => [fn (array $setup) => DB::table('roles')->where('id', $setup['role']->id)->update(['permissions' => json_encode(array_values(array_diff($setup['role']->permissions, ['dam.asset.destroy'])))])],
    'the role may delete only its own'     => [fn (array $setup) => DB::table('dam_role_settings')->updateOrInsert(['role_id' => $setup['role']->id], ['delete_own_only' => true]) && Asset::where('id', $setup['upload'])->update(['created_by' => Admin::factory()->create()->id])],
    'the actor is disabled'                => [fn (array $setup) => Admin::where('id', $setup['member']->id)->update(['status' => 0])],
    'the target no longer holds the bytes' => [fn (array $setup) => AssetFile::where('dam_asset_id', $setup['target'])->update(['is_current' => false])],
]);

it('re-checks the API key over REST: a key revoked before the lock answers like a missing entry', function () {
    $setup = stackRaceSetup($this);
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.stack_instead']);
    $apiAdmin = Admin::latest('id')->first();
    $apiAdmin->update(['role_id' => $setup['role']->id]);
    app('auth')->forgetGuards();
    app(DirectoryPermissionService::class)->flush();
    $before = stackRaceState($setup);
    Event::listen(StackInstead::EVENT_LOCKING, fn () => Apikey::where('admin_id', $apiAdmin->id)->update(['revoked' => true]));

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.stack-instead', $setup['upload']), ['target_id' => $setup['target']])
        ->assertNotFound();
    expect(stackRaceState($setup))->toBe($before);
});
