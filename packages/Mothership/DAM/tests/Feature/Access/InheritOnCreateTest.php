<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Database\Seeders\DamDemoDataSeeder;
use Mothership\DAM\Database\Seeders\DirectoryTableSeeder;
use Mothership\DAM\Helpers\Import as DamImport;
use Mothership\DAM\Jobs\CompleteStylePressGeneration;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Jobs\CopyDirectoryStructure;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\GenerationDirectory;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\DataTransfer\Jobs\Import\ImportTrackBatch;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * A new file or folder takes the access list of the folder it lands in (ADR 0010, spec D3), and records its
 * owner — one case per writer in the sharing slice 2a plan's hook list, driven through the controller, job
 * or service that writer really runs in, so the case proves the wiring and not just the writer.
 *
 * The writer dataset runs under both access models and over four list shapes — empty, a user, a group, and
 * the built-in Everyone — because slice 2a keeps the lists current whichever model is live: they must already
 * be true of the library on the day the operator runs dam:access:switch lists. The only model-dependent
 * difference is the legacy creator grant, which two cases at the end pin on either side.
 *
 * Two writers are asserted where their fixtures already live rather than rebuilt here: chunk finalize in
 * ChunkUploadCompletionTest and the SphereSeam import in SphereSeamTest.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    $this->admin = $this->loginAsAdmin();
    $this->friend = inheritAdmin();
    $this->group = DB::table('dam_groups')->insertGetId(['name' => 'Inherit group '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $this->everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');

    // The folder everything below is created in. Its list is set per case by inheritShare().
    $this->shared = Directory::create(['name' => 'Inherit shared '.Str::random(6), 'parent_id' => null]);
    inheritShare($this, 'user and group');
});

it('gives a new item its folder\'s access list, and an owner', function (string $writer) {
    $made = inheritRun($this, $writer);

    expect($made['ids'])->not->toBe([]);

    foreach ($made['ids'] as $id) {
        expect(inheritUsers($made['type'], $id))->toBe([$this->friend->id])
            ->and(inheritGroups($made['type'], $id))->toBe([$this->group]);
    }

    // Every writer here runs with an `admin` session, so the owner is the acting admin.
    $table = $made['type'] === 'directory' ? 'dam_directories' : 'dam_assets';

    expect(DB::table($table)->whereIn('id', $made['ids'])->pluck('created_by')->unique()->all())
        ->toBe([$this->admin->id]);
})->with(inheritWriters());

it('gives a new item its folder\'s access list under either access model', function (string $writer, string $model) {
    inheritModel($model);

    $made = inheritRun($this, $writer);

    foreach ($made['ids'] as $id) {
        expect(inheritUsers($made['type'], $id))->toBe([$this->friend->id])
            ->and(inheritGroups($made['type'], $id))->toBe([$this->group]);
    }
})->with(['folder endpoint', 'admin upload endpoint', 'folder upload endpoint', 'explorer asset copy endpoint', 'image editor save as new'])
    ->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('gives a new item whatever shape of list its folder has', function (string $writer, string $shape, string $model) {
    inheritShare($this, $shape);
    inheritModel($model);

    $made = inheritRun($this, $writer);

    expect($made['ids'])->not->toBe([]);

    [$users, $groups] = inheritExpected($this, $shape);

    foreach ($made['ids'] as $id) {
        expect(inheritUsers($made['type'], $id))->toBe($users)
            ->and(inheritGroups($made['type'], $id))->toBe($groups);
    }
})->with(['folder endpoint', 'admin upload endpoint', 'explorer asset copy endpoint'])
    ->with(['only me', 'user', 'group', 'everyone', 'user and group'])
    ->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('gives a file in two folders the union of both lists, and adds nothing on a repeat', function (string $model) {
    inheritModel($model);

    $second = Directory::create(['name' => 'Inherit second '.Str::random(6), 'parent_id' => null]);
    $stranger = inheritAdmin();
    // Overlapping principals on purpose: the group is on both lists, so a union that double-counted would
    // break the primary key rather than pass.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $second->id, 'admin_id' => $stranger->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $second->id, 'group_id' => $this->group]);

    $id = (int) $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('inherit-union.png', 40, 20)],
        'directory_id' => $this->shared->id,
    ])->assertStatus(201)->json('files.0.id');

    // The second placement, through the attach every upload and REST attach shares.
    app(AccessModel::class)->sharedly(function () use ($second, $id): void {
        DB::table('dam_asset_directory')->insert(['asset_id' => $id, 'directory_id' => $second->id]);

        app(AccessListWriter::class)->inheritAssets([$id]);
    });

    expect(inheritUsers('asset', $id))->toEqualCanonicalizing([$this->friend->id, $stranger->id])
        ->and(inheritGroups('asset', $id))->toBe([$this->group]);

    // Idempotent: running the same inherit again writes nothing and breaks nothing.
    app(AccessModel::class)->sharedly(fn () => app(AccessListWriter::class)->inheritAssets([$id]));

    expect(inheritUsers('asset', $id))->toEqualCanonicalizing([$this->friend->id, $stranger->id])
        ->and(inheritGroups('asset', $id))->toBe([$this->group]);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('records the exact api user as the owner of a REST folder create, and its folder\'s list', function () {
    $apiAdmin = inheritApiOnly($this);

    $id = (int) $this->postJson(route('admin.api.dam.directory.store'), ['name' => 'Inherit rest folder', 'parent_id' => $this->shared->id])
        ->assertStatus(201)->json('data.id');

    // A REST request signs in on `api`, never on `admin`, and inheritApiOnly() has cleared the admin session
    // so nothing but that fallback can explain the owner (Codex review of PR #74, finding 7).
    expect((int) DB::table('dam_directories')->where('id', $id)->value('created_by'))->toBe($apiAdmin)
        ->and(inheritUsers('directory', $id))->toBe([$this->friend->id])
        ->and(inheritGroups('directory', $id))->toBe([$this->group]);
});

it('records the exact api user as the owner of a REST upload, and its folder\'s list', function () {
    $apiAdmin = inheritApiOnly($this);

    $id = (int) $this->post(route('admin.api.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('inherit-rest.png', 40, 20)],
        'directory_id' => $this->shared->id,
    ])->assertStatus(201)->json('files.0.id');

    expect((int) DB::table('dam_assets')->where('id', $id)->value('created_by'))->toBe($apiAdmin)
        ->and(inheritUsers('asset', $id))->toBe([$this->friend->id])
        ->and(inheritGroups('asset', $id))->toBe([$this->group]);
});

it('gives a queued bundle import its folder\'s list and the importing admin as owner', function () {
    Storage::fake('private');

    $archive = Storage::disk('private')->path('imports/inherit-bundle.zip');
    File::ensureDirectoryExists(dirname($archive));
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('products.csv', "sku,name\nsku-1,One");
    $zip->addFromString('assets/'.$this->shared->name.'/Nested/hero.jpg', 'hero-bytes');
    $zip->close();

    // The real DataTransfer path: a real job_track row carrying the requester, through the DAM's own
    // Helpers\Import override — the getSource() the import job calls — not AssetBundleReader directly
    // (Codex review of PR #74 round 2, finding 4).
    inheritImport($this->friend->id, 'imports/inherit-bundle.zip');

    $nested = Directory::where('name', 'Nested')->where('parent_id', $this->shared->id)->firstOrFail();
    $asset = Asset::where('path', 'assets/'.$this->shared->name.'/Nested/hero.jpg')->firstOrFail();

    expect((int) $nested->created_by)->toBe($this->friend->id)
        ->and((int) $asset->created_by)->toBe($this->friend->id)
        ->and(inheritUsers('directory', $nested->id))->toBe([$this->friend->id])
        ->and(inheritGroups('directory', $nested->id))->toBe([$this->group])
        // The file's own list is the union of its folders' — here the one nested folder's, which is the
        // shared folder's copied down by the Directory::created listener.
        ->and(inheritUsers('asset', $asset->id))->toBe([$this->friend->id])
        ->and(inheritGroups('asset', $asset->id))->toBe([$this->group]);
});

it('unpacks a bundle through the DAM\'s own import override', function () {
    Storage::fake('private');

    $archive = Storage::disk('private')->path('imports/inherit-helper.zip');
    File::ensureDirectoryExists(dirname($archive));
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('products.csv', "sku,name\nsku-1,One");
    $zip->addFromString('assets/'.$this->shared->name.'/Helper/hero.jpg', 'hero-bytes');
    $zip->close();

    // A focused test of Helpers\Import::getSource() on its own, which isolates the override from the job's
    // guard handling. The queued-job coverage is the two cases above, which run ImportTrackBatch::handle().
    inheritImportThroughTheHelper($this->friend->id, 'imports/inherit-helper.zip');

    $asset = Asset::where('path', 'assets/'.$this->shared->name.'/Helper/hero.jpg')->firstOrFail();

    expect(inheritUsers('asset', $asset->id))->toBe([$this->friend->id]);
});

it('takes the queued generation requester from the generation row, not from a session', function () {
    [$manifestPath, , $destination] = inheritGenerationPackage();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $destination->id, 'admin_id' => $this->friend->id]);

    // The plumbing the job really uses: no session, and the requester read from dam_home_generations.
    auth()->guard('admin')->logout();

    $generation = (string) Str::uuid();
    DB::table('dam_home_generations')->insert([
        'id'          => $generation,
        'admin_id'    => $this->friend->id,
        'request_id'  => 'inherit-'.$generation,
        'status_url'  => 'higgsfield-cli:'.$generation,
        'status'      => 'completed',
        'inputs'      => json_encode(['references' => []], JSON_THROW_ON_ERROR),
        'save_status' => 'pending',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    // The job packages from its own outbox; point that at the package this test already wrote.
    $outbox = storage_path('app/private/stylepress/outbox/'.$generation);
    File::ensureDirectoryExists($outbox);
    File::copy(dirname($manifestPath).'/output.png', $outbox.'/output.png');
    File::copy($manifestPath, $outbox.'/generation.json');
    File::put($outbox.'/generation.json', str_replace('"generation_id":"inherit:example"', '"generation_id":"inherit:'.$generation.'"', File::get($outbox.'/generation.json')));

    (new CompleteStylePressGeneration($generation))->handle();

    $assetId = (int) DB::table('dam_home_generations')->where('id', $generation)->value('asset_id');

    expect($assetId)->toBeGreaterThan(0)
        ->and((int) DB::table('dam_assets')->where('id', $assetId)->value('created_by'))->toBe($this->friend->id)
        ->and(inheritUsers('asset', $assetId))->toBe([$this->friend->id]);

    File::deleteDirectory($outbox);
});

it('leaves a bundle import unowned when its job track names no user', function () {
    Storage::fake('private');

    $archive = Storage::disk('private')->path('imports/inherit-unowned.zip');
    File::ensureDirectoryExists(dirname($archive));
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('products.csv', "sku,name\nsku-1,One");
    $zip->addFromString('assets/'.$this->shared->name.'/Orphan/hero.jpg', 'hero-bytes');
    $zip->close();

    // A queue worker has no session either, which is the whole reason the owner has to be passed explicitly.
    auth()->guard('admin')->logout();

    // job_track.user_id is nullable: a bundle imported with no requester leaves created_by null, and full
    // admins manage those items (ADR 0010). Through the real DataTransfer path, as above.
    inheritImport(null, 'imports/inherit-unowned.zip');

    $folder = Directory::where('name', 'Orphan')->where('parent_id', $this->shared->id)->firstOrFail();
    $asset = Asset::where('path', 'assets/'.$this->shared->name.'/Orphan/hero.jpg')->firstOrFail();

    expect($folder->created_by)->toBeNull()
        ->and($asset->created_by)->toBeNull()
        // The list still follows the create: ownership and access are separate rules.
        ->and(inheritUsers('asset', $asset->id))->toBe([$this->friend->id]);
});

it('gives a queued generation import the generation folder\'s list and the requester as owner', function () {
    // The folder the manifest routes to, resolved first and shared, because the AI-Generations chain may
    // already exist and then nothing new inherits anything.
    [$manifestPath, , $destination] = inheritGenerationPackage();

    DB::table('dam_directory_user_access')->insert(['directory_id' => $destination->id, 'admin_id' => $this->friend->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $destination->id, 'group_id' => $this->group]);

    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: $manifestPath, ownerId: $this->friend->id);

    $asset = Asset::findOrFail($result['asset_id']);

    expect((int) $asset->created_by)->toBe($this->friend->id)
        ->and(inheritUsers('asset', $asset->id))->toBe([$this->friend->id])
        ->and(inheritGroups('asset', $asset->id))->toBe([$this->group]);
});

it('rebuilds a generated file\'s list when a re-import replaces its folders', function () {
    $style = ['slug' => 'example', 'title' => 'Example Style '.Str::random(6), 'category' => '04 Style', 'template' => 'Verbatim', 'version' => 'one'];
    [$manifestPath] = inheritGenerationPackage();

    $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: $manifestPath, ownerId: $this->friend->id);
    $asset = Asset::findOrFail($result['asset_id']);

    // Where it is now, shared with the friend; where the styled manifest will route it, shared with someone
    // else instead. A recompute, not a widen: the old folder's row must not survive the move.
    $stranger = inheritAdmin();
    $from = $asset->directories()->firstOrFail();
    $to = app(GenerationDirectory::class)->ensure(segments: ['AI-Generations', 'Images', 'Pre-Built-Styles', $style['category'], $style['title']]);

    DB::table('dam_directory_user_access')->insert(['directory_id' => $from->id, 'admin_id' => $this->friend->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->friend->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $to->id, 'admin_id' => $stranger->id]);

    // A manifest carrying a style routes the output into that folder, so the importer detaches the old
    // membership and attaches the new one.
    inheritGenerationPackage(style: $style);

    app(GenerationPackageImporter::class)->importManifest(manifestPath: $manifestPath, ownerId: $this->friend->id);

    expect($asset->fresh()->directories()->pluck('dam_directories.id')->all())->not->toContain($from->id)
        ->and(inheritUsers('asset', $asset->id))->toBe([$stranger->id]);
});

it('gives seeded demo files their folders\' lists', function () {
    $this->damResetAssetTables();
    app(DirectoryTableSeeder::class)->run();

    $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $root->id, 'admin_id' => $this->friend->id]);

    app(DamDemoDataSeeder::class)->run();

    $seeded = DB::table('dam_asset_directory')->pluck('asset_id')->unique()->all();

    expect($seeded)->not->toBe([]);

    // Every seeded folder inherited the root's list through the created listener, and every seeded file
    // inherited its folder's through the bulk attach.
    expect(DB::table('dam_asset_user_access')->whereIn('asset_id', $seeded)->where('admin_id', $this->friend->id)->count())
        ->toBe(count($seeded));
});

it('still writes the legacy creator grant under grants', function () {
    $creator = inheritGrantedCreator($this);

    $id = (int) $this->postJson(route('admin.dam.directory.store'), ['name' => 'Inherit grants folder', 'parent_id' => $this->shared->id])
        ->assertSuccessful()->json('data.id');

    expect(DB::table('dam_directory_role')->where('role_id', $creator->role_id)->where('directory_id', $id)->exists())->toBeTrue();
});

it('skips the legacy creator grant under lists, where the owner rule replaces it', function () {
    inheritModel(AccessModel::LISTS);

    $creator = inheritGrantedCreator($this);

    $id = (int) $this->postJson(route('admin.dam.directory.store'), ['name' => 'Inherit lists folder', 'parent_id' => $this->shared->id])
        ->assertSuccessful()->json('data.id');

    expect(DB::table('dam_directory_role')->where('directory_id', $id)->exists())->toBeFalse()
        // The list still follows the create: slice 2a maintains it under both models.
        ->and(inheritUsers('directory', $id))->toEqualCanonicalizing([$this->friend->id, $creator->id])
        ->and((int) DB::table('dam_directories')->where('id', $id)->value('created_by'))->toBe($creator->id);
});

/** @return list<string> every writer in the slice 2a plan's hook list that this file drives itself */
function inheritWriters(): array
{
    return [
        'directory repository',
        'folder endpoint',
        'folder path segments endpoint',
        'explorer folder structure copy',
        'copy directory job',
        'copy directory structure job',
        'mass copy folders job',
        'mass copy folder with files job',
        'generation folder service',
        'admin upload endpoint',
        'folder upload endpoint',
        'explorer asset copy endpoint',
        'mass copy assets job',
        'copy directory job files',
        'image editor save as new',
    ];
}

/** Replaces the fixture folder's access list with one of the shapes the Share dialog can leave behind. */
function inheritShare($test, string $shape): void
{
    DB::table('dam_directory_user_access')->where('directory_id', $test->shared->id)->delete();
    DB::table('dam_directory_group_access')->where('directory_id', $test->shared->id)->delete();

    [$users, $groups] = inheritExpected($test, $shape);

    foreach ($users as $adminId) {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $test->shared->id, 'admin_id' => $adminId]);
    }

    foreach ($groups as $groupId) {
        DB::table('dam_directory_group_access')->insert(['directory_id' => $test->shared->id, 'group_id' => $groupId]);
    }
}

/**
 * The rows a shape means, on the folder and so on everything created in it.
 *
 * @return array{0: list<int>, 1: list<int>}
 */
function inheritExpected($test, string $shape): array
{
    return match ($shape) {
        'only me'         => [[], []],
        'user'            => [[$test->friend->id], []],
        'group'           => [[], [$test->group]],
        'everyone'        => [[], [$test->everyone]],
        'user and group'  => [[$test->friend->id], [$test->group]],
    };
}

/**
 * One writer from the plan's hook list, driven the way it really runs.
 *
 * @return array{type: string, ids: list<int>}
 */
function inheritRun($test, string $writer): array
{
    $shared = $test->shared;

    return match ($writer) {
        'directory repository' => inheritFolders([
            app(DirectoryRepository::class)->create(['name' => 'Inherit repo folder', 'parent_id' => $shared->id])->id,
        ]),

        'folder endpoint' => inheritFolders([
            $test->postJson(route('admin.dam.directory.store'), ['name' => 'Inherit endpoint folder', 'parent_id' => $shared->id])
                ->assertOk()->json('data.id'),
        ]),

        'folder path segments endpoint' => (function () use ($test, $shared) {
            $test->postJson(route('admin.dam.directory.create_structure'), [
                'directory_id' => $shared->id,
                'paths'        => ['One/Two'],
            ])->assertOk();

            return inheritFolders(Directory::whereIn('name', ['One', 'Two'])->pluck('id')->all());
        })(),

        'explorer folder structure copy' => (function () use ($test, $shared) {
            $source = Directory::create(['name' => 'Inherit copy source', 'parent_id' => null]);
            Directory::create(['name' => 'Inherit copy leaf', 'parent_id' => $source->id]);

            $test->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
                'source_id' => $source->id,
                'target_id' => $shared->id,
            ])->assertStatus(202);

            return inheritFolders(inheritDescendants($shared->id));
        })(),

        'copy directory job' => (function () use ($test, $shared) {
            $source = Directory::create(['name' => 'Inherit job source', 'parent_id' => null]);

            (new CopyDirectory($source->id, $shared->id, $test->admin->id))->handle();

            return inheritFolders(inheritDescendants($shared->id));
        })(),

        'copy directory structure job' => (function () use ($test, $shared) {
            // This job copies a folder beside itself, so the new root's parent is the shared folder.
            $source = Directory::create(['name' => 'Inherit structure source', 'parent_id' => $shared->id]);

            (new CopyDirectoryStructure($source->id, $test->admin->id))->handle();

            return inheritFolders(array_values(array_diff(inheritDescendants($shared->id), [$source->id])));
        })(),

        'mass copy folders job' => (function () use ($test, $shared) {
            $source = Directory::create(['name' => 'Inherit mass source', 'parent_id' => null]);

            (new MassCopy([], [$source->id], $shared->id, $test->admin->id))->handle();

            return inheritFolders(inheritDescendants($shared->id));
        })(),

        // The same job's folder branch with a file inside the folder it copies: deepCopy() inserts the copy's
        // rows itself, so the file's list comes from its own hook and not from the folder listener.
        'mass copy folder with files job' => (function () use ($test, $shared) {
            $source = Directory::create(['name' => 'Inherit mass filed source', 'parent_id' => null]);
            $original = inheritFileOutside($test, directoryId: $source->id);

            (new MassCopy([], [$source->id], $shared->id, $test->admin->id))->handle();

            $copies = array_values(array_diff(inheritFilesIn(...inheritDescendants($shared->id)), [$original->id]));

            expect($copies)->toHaveCount(1);

            return inheritAssets($copies);
        })(),

        'generation folder service' => (function () use ($test) {
            // ensure() builds from the lowest-id root, so that root is the folder to share. The segment is
            // unique, because the ordinary AI-Generations chain may already exist and an ensure() that finds
            // its folder creates nothing to inherit.
            $root = Directory::whereNull('parent_id')->orderBy('id')->firstOrFail();
            DB::table('dam_directory_user_access')->insert(['directory_id' => $root->id, 'admin_id' => $test->friend->id]);
            DB::table('dam_directory_group_access')->insert(['directory_id' => $root->id, 'group_id' => $test->group]);

            return inheritFolders([app(GenerationDirectory::class)
                ->ensure(segments: ['Inherit generations '.Str::random(6)], ownerId: $test->admin->id)->id]);
        })(),

        'admin upload endpoint' => inheritAssets([
            $test->postJson(route('admin.dam.assets.upload'), [
                'files'        => [UploadedFile::fake()->image('inherit-upload.png', 40, 20)],
                'directory_id' => $shared->id,
            ])->assertStatus(201)->json('files.0.id'),
        ]),

        'folder upload endpoint' => (function () use ($test, $shared) {
            // uploadFolder creates the path folders, then attaches the files through mappedWithDirectory.
            $ids = $test->postJson(route('admin.dam.assets.upload_folder'), [
                'files'          => [UploadedFile::fake()->image('inherit-nested.png', 40, 20)],
                'relative_paths' => ['Batch/inherit-nested.png'],
                'directory_id'   => $shared->id,
                'preserve_root'  => true,
            ])->assertStatus(201)->json('files.*.id');

            return inheritAssets($ids);
        })(),

        'explorer asset copy endpoint' => (function () use ($test, $shared) {
            $asset = inheritFileOutside($test);

            return inheritAssets([
                $test->postJson(route('admin.dam.explorer.copy.asset'), [
                    'asset_id'            => $asset->id,
                    'target_directory_id' => $shared->id,
                ])->assertStatus(201)->json('asset.id'),
            ]);
        })(),

        'mass copy assets job' => (function () use ($test, $shared) {
            $asset = inheritFileOutside($test);

            (new MassCopy([$asset->id], [], $shared->id, $test->admin->id))->handle();

            return inheritAssets(inheritFilesIn($shared->id));
        })(),

        'copy directory job files' => (function () use ($test, $shared) {
            $source = Directory::create(['name' => 'Inherit filed source', 'parent_id' => null]);
            $asset = inheritFileOutside($test, directoryId: $source->id);

            (new CopyDirectory($source->id, $shared->id, $test->admin->id))->handle();

            return inheritAssets(array_values(array_diff(inheritFilesIn(...inheritDescendants($shared->id)), [$asset->id])));
        })(),

        'image editor save as new' => (function () use ($test, $shared) {
            $asset = Asset::findOrFail($test->postJson(route('admin.dam.assets.upload'), [
                'files'        => [UploadedFile::fake()->image('inherit-editable.png', 200, 100)],
                'directory_id' => $shared->id,
            ])->assertStatus(201)->json('files.0.id'));

            $opened = $test->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();

            $copyId = $test->post(route('admin.dam.assets.editor.save', $asset->id), [
                'revision' => $opened['revision'],
                'mode'     => 'new',
                'document' => json_encode($opened['document']),
                'render'   => inheritRender(),
            ], ['Accept' => 'application/json'])->assertOk()->json('asset_id');

            expect(ImageEditorResource::where('asset_id', $copyId)->exists())->toBeTrue();

            return inheritAssets([$copyId]);
        })(),
    };
}

/** @return array{type: string, ids: list<int>} */
function inheritFolders(array $ids): array
{
    return ['type' => 'directory', 'ids' => array_values(array_map('intval', $ids))];
}

/** @return array{type: string, ids: list<int>} */
function inheritAssets(array $ids): array
{
    return ['type' => 'asset', 'ids' => array_values(array_map('intval', $ids))];
}

/** @return list<int> every folder beneath $parentId, the parent itself excluded */
function inheritDescendants(int $parentId): array
{
    $bounds = DB::table('dam_directories')->where('id', $parentId)->first(['_lft', '_rgt']);

    return DB::table('dam_directories')
        ->whereBetween('_lft', [(int) $bounds->_lft, (int) $bounds->_rgt])
        ->where('id', '!=', $parentId)
        ->orderBy('_lft')
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

/** @return list<int> the files sitting in any of $directoryIds */
function inheritFilesIn(int ...$directoryIds): array
{
    return DB::table('dam_asset_directory')
        ->whereIn('directory_id', $directoryIds)
        ->distinct()
        ->pluck('asset_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

/**
 * A file in a folder nobody has shared, so only the destination's list can explain the copy's. Uploaded
 * through the real route, because a copy shares the source's blob and a factory row has none.
 */
function inheritFileOutside($test, ?int $directoryId = null): Asset
{
    $directoryId ??= Directory::create(['name' => 'Inherit unshared '.Str::random(6), 'parent_id' => null])->id;

    $id = $test->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('inherit-source-'.Str::random(6).'.png', 40, 20)],
        'directory_id' => $directoryId,
    ])->assertStatus(201)->json('files.0.id');

    $asset = Asset::findOrFail($id);

    expect(inheritUsers('asset', $asset->id))->toBe([]);

    return $asset;
}

/** A 200x100 PNG whose bytes differ from a blank fake upload's, so the editor's render is its own blob. */
function inheritRender(): UploadedFile
{
    $image = imagecreatetruecolor(200, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, 12, 200, 90));
    $path = tempnam(sys_get_temp_dir(), 'inherit-render-').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, 'render.png', 'image/png', null, true);
}

/**
 * A StylePress generation package on disk, the root its folders are built under, and the folder this
 * manifest routes its output into.
 *
 * @return array{0: string, 1: Directory, 2: Directory}
 */
function inheritGenerationPackage(?array $style = null): array
{
    static $root = null;
    $root ??= sys_get_temp_dir().'/inherit-generation-'.Str::uuid();
    File::ensureDirectoryExists($root);
    $output = $root.'/output.png';

    if (! is_file($output)) {
        $image = imagecreatetruecolor(3, 2);
        imagepng($image, $output);
        imagedestroy($image);
    }

    $manifest = [
        'schema_version' => 1,
        'generation_id'  => 'inherit:example',
        'title'          => 'Inherited generation',
        'kind'           => 'image',
        'provider'       => 'higgsfield-generate',
        'model'          => 'gpt_image_2',
        'prompt'         => 'Prompt',
        'style'          => $style,
        'settings'       => ['quality' => 'low'],
        'output'         => ['path' => 'output.png', 'sha256' => hash_file('sha256', $output), 'mime' => 'image/png'],
        'references'     => [],
    ];
    File::put($root.'/generation.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    return [
        $root.'/generation.json',
        Directory::whereNull('parent_id')->orderBy('id')->firstOrFail(),
        app(GenerationDirectory::class)->forManifest(manifest: $manifest),
    ];
}

/**
 * An admin whose custom role reaches the shared folder through a legacy role grant, which is what
 * DirectoryController::autoGrantToCreator() extends to a folder they then create (DirectoryAutoGrantTest's
 * shape).
 */
function inheritGrantedCreator($test): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.directory.store']]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $test->shared->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $role->id], ['inherit_children' => true]);

    $creator = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);

    // The same reach under either model: a role grant answers under `grants`, the access list under `lists`.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $test->shared->id, 'admin_id' => $creator->id]);
    $test->loginAsAdmin($creator);
    app(DirectoryPermissionService::class)->flush();

    return $creator;
}

/**
 * Runs a bundle import through the real queued job: ImportTrackBatch::handle() on a real job_track row naming
 * $userId and the zip at $filePath. The job sets the admin guard from `user_id`, resolves ImportHelper — which
 * the DAM provider binds to its own Helpers\Import override — and validate() calls getSource(), which unpacks
 * the bundle (Codex review of PR #74 round 3, finding 3).
 *
 * The product-import phases after that are irrelevant here and are allowed to fail; the ownership evidence is
 * the unpacked bundle and the rewritten file path, both asserted.
 */
function inheritImport(?int $userId, string $filePath): void
{
    $track = JobTrack::factory()->create(['file_path' => $filePath, 'user_id' => $userId]);

    try {
        (new ImportTrackBatch($track))->handle();
    } catch (Throwable) {
        // The product importer's own phases, not the bundle unpack: the assertions below say whether the part
        // this test is about ran.
    }

    // The override really ran inside the job: the track now points at the data file inside the unpacked bundle.
    expect($track->fresh()->file_path)->toBe('imports/bundles/'.$track->id.'/products.csv');
}

/**
 * The same unpack driven through the helper alone. A focused test of Helpers\Import::getSource(), kept because
 * it isolates the override from the job's guard handling; it is not queued-job coverage on its own.
 */
function inheritImportThroughTheHelper(?int $userId, string $filePath): JobTrack
{
    $track = JobTrack::factory()->create(['file_path' => $filePath, 'user_id' => $userId]);

    expect(app(DamImport::class)->setImport($track)->getSource())->not->toBeNull()
        ->and($track->fresh()->file_path)->toBe('imports/bundles/'.$track->id.'/products.csv');

    return $track;
}

/**
 * Authenticates for the REST API and clears the `admin` session the fixture opened, so nothing but the
 * `api`-guard fallback in the models' creating hooks can supply an owner. Returns that api user's id.
 */
function inheritApiOnly($test): int
{
    $headers = $test->getAuthenticationHeaders();
    $test->withHeaders($headers);
    auth()->guard('admin')->logout();

    return (int) DB::table('api_keys')->orderByDesc('id')->value('admin_id');
}

function inheritAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** Leaves the switch row at $model, as a finished `dam:access:switch` would. */
function inheritModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** @return list<int> the admin ids listed on one item */
function inheritUsers(string $type, int $id): array
{
    return DB::table("dam_{$type}_user_access")->where("{$type}_id", $id)->orderBy('admin_id')->pluck('admin_id')->map(fn ($v) => (int) $v)->all();
}

/** @return list<int> the group ids listed on one item */
function inheritGroups(string $type, int $id): array
{
    return DB::table("dam_{$type}_group_access")->where("{$type}_id", $id)->orderBy('group_id')->pluck('group_id')->map(fn ($v) => (int) $v)->all();
}
