<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P3b-2 (D16 exact): an upload whose bytes are already a file the uploader can see says where, and offers Open,
 * Stack instead and Keep both. A match the uploader cannot see, a trashed one and an unavailable one are never
 * disclosed: the answer is the one a file with no match gets. Web, folder, chunked and REST uploads alike.
 */

function exactDupAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** Every Library key except the editor's, so the member uploads and attaches but sees no one else's drafts. */
function exactDupMember(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->reject(fn ($key) => $key === 'dam.asset.update')->values()->all(),
    ]);

    return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
}

function exactDupPng(string $seed): string
{
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, crc32($seed) & 0xFFFFFF);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function exactDupUpload(object $test, Directory $folder, string $name, string $bytes): TestResponse
{
    return $test->postJson(route('admin.dam.assets.upload'), [
        'directory_id' => $folder->id,
        'files'        => [UploadedFile::fake()->createWithContent($name, $bytes)],
    ]);
}

/** The keys of a JSON answer, nested, so two answers can be compared for shape whatever their ids and names. */
function exactDupShape(mixed $value): mixed
{
    if (! is_array($value)) {
        return get_debug_type($value);
    }

    return array_map(fn ($item) => exactDupShape($item), $value);
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->loginAsAdmin();

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->folder = Directory::factory()->create(['name' => 'Campaigns '.Str::random(4), 'parent_id' => $root->id]);
    $this->bytes = exactDupPng('hero');
});

it('tells the uploader where a visible exact duplicate already is, and Keep both leaves two entries', function () {
    $first = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated();
    expect($first->json('duplicates'))->toBe([]);
    $firstId = $first->json('files.0.id');

    $second = exactDupUpload($this, $this->folder, 'hero-copy.png', $this->bytes)->assertCreated();
    $secondId = $second->json('files.0.id');
    $location = str_replace('/', ' / ', substr(dirname(Asset::find($firstId)->path), strlen(Directory::ASSETS_DIRECTORY) + 1)).' / hero.png';

    expect($second->json('duplicates'))->toBe([[
        'asset_id' => $secondId,
        'kind'     => 'exact',
        'outcome'  => 'created',
        'match'    => ['id' => $firstId, 'name' => 'hero.png', 'folder' => substr($location, 0, -strlen(' / hero.png')), 'location' => $location],
        'message'  => 'Already in Library: '.$location,
        'actions'  => ['open', 'stack_instead', 'keep_both'],
    ]]);

    // Keep both is the default: nothing more is sent, and both entries stay, holding the same bytes.
    $shas = Asset::whereIn('id', [$firstId, $secondId])->get()->map(fn ($asset) => app(ContentStore::class)->current($asset)->sha256)->unique();
    expect(Asset::whereIn('id', [$firstId, $secondId])->count())->toBe(2)->and($shas->count())->toBe(1);
});

it('discloses nothing for a hidden, trashed or unavailable match: the answer and its queries are those of a file with no match', function () {
    exactDupAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $member = exactDupMember();
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $member->role_id, 'created_at' => now(), 'updated_at' => now()]);

    // Set up as a full admin: one file in a folder the member cannot open, one trashed, one a draft of someone else's.
    $hiddenBytes = exactDupPng('hidden');
    $trashedBytes = exactDupPng('trashed');
    $draftBytes = exactDupPng('draft');
    exactDupUpload($this, $hiddenFolder, 'secret.png', $hiddenBytes)->assertCreated();
    $trashed = Asset::find(exactDupUpload($this, $this->folder, 'gone.png', $trashedBytes)->assertCreated()->json('files.0.id'));
    app(AssetTrashService::class)->trash(asset: $trashed);
    $draftId = exactDupUpload($this, $this->folder, 'draft.png', $draftBytes)->assertCreated()->json('files.0.id');
    Asset::where('id', $draftId)->update(['availability' => Asset::AVAILABILITY_DRAFT, 'created_by' => Admin::factory()->create()->id]);

    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $answers = [
        'none'    => exactDupUpload($this, $this->folder, 'none.png', exactDupPng('unique')),
        'hidden'  => exactDupUpload($this, $this->folder, 'hidden.png', $hiddenBytes),
        'trashed' => exactDupUpload($this, $this->folder, 'trashed.png', $trashedBytes),
        'draft'   => exactDupUpload($this, $this->folder, 'draft-copy.png', $draftBytes),
    ];

    foreach ($answers as $label => $answer) {
        $answer->assertCreated();
        expect($answer->json('duplicates'))->toBe([], $label)
            ->and(exactDupShape($answer->json()))->toBe(exactDupShape($answers['none']->json()), $label);
    }

    // The check itself runs the same queries whether the bytes match a hidden file or nothing at all.
    $notices = app(ExactDuplicateNotices::class);
    $queriesFor = function (string $bytes, int $assetId) use ($notices): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $notices->notices(receipts: [new UploadReceipt(assetId: $assetId, sha256: hash('sha256', $bytes), outcome: UploadReceipt::CREATED)], context: AccessContext::library(AccessContext::PURPOSE_LIST), canStackInstead: true);
        $queries = array_map(fn ($query) => preg_replace('/\d+/', 'N', $query['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $queries];
    };
    [$hiddenResult, $hiddenQueries] = $queriesFor($hiddenBytes, (int) $answers['hidden']->json('files.0.id'));
    [$noneResult, $noneQueries] = $queriesFor(exactDupPng('never-uploaded'), (int) $answers['none']->json('files.0.id'));
    expect($hiddenResult)->toBe([])->and($noneResult)->toBe([])->and($hiddenQueries)->toBe($noneQueries);

    // A full admin, who sees every draft in the Library, is not told about a draft or a trashed file either (fresh
    // bytes: the member's copies above are available entries, and so rightly matches).
    $this->actingAs(Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $draftBytes = exactDupPng('draft-2');
    $trashedBytes = exactDupPng('trashed-2');
    $draftId = exactDupUpload($this, $this->folder, 'draft-2.png', $draftBytes)->assertCreated()->json('files.0.id');
    Asset::where('id', $draftId)->update(['availability' => Asset::AVAILABILITY_DRAFT, 'created_by' => Admin::factory()->create()->id]);
    app(AssetTrashService::class)->trash(asset: Asset::find(exactDupUpload($this, $this->folder, 'gone-2.png', $trashedBytes)->assertCreated()->json('files.0.id')));
    expect(exactDupUpload($this, $this->folder, 'draft-again.png', $draftBytes)->assertCreated()->json('duplicates'))->toBe([])
        ->and(exactDupUpload($this, $this->folder, 'trashed-again.png', $trashedBytes)->assertCreated()->json('duplicates'))->toBe([]);
});

it('Stack instead attaches the upload to the match with its sha256 and leaves no extra Library entry', function () {
    $firstId = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated()->json('files.0.id');
    $entriesBefore = Asset::count();
    $secondId = exactDupUpload($this, $this->folder, 'hero-final.png', $this->bytes)->assertCreated()->json('files.0.id');
    $sha = hash('sha256', $this->bytes);

    $response = $this->postJson(route('admin.dam.assets.stack-instead', $secondId), ['target_id' => $firstId])->assertOk();

    $attached = AssetFile::with('fileObject')->findOrFail($response->json('file.id'));
    expect($response->json('trashed_asset_id'))->toBe($secondId)
        ->and($attached->only(['dam_asset_id', 'role', 'label', 'is_current', 'revision_number']))
        ->toBe(['dam_asset_id' => $firstId, 'role' => 'other', 'label' => 'hero-final.png', 'is_current' => true, 'revision_number' => 1])
        ->and($attached->fileObject->sha256)->toBe($sha)
        ->and(app(ContentStore::class)->current(Asset::find($firstId))->sha256)->toBe($sha)
        // The new entry is in the Trash, so the Library holds as many entries as before the upload.
        ->and(Asset::count())->toBe($entriesBefore)
        ->and(Asset::withTrashed()->find($secondId)->trashed())->toBeTrue();

    // Reversible: Restore brings the entry back whole, and the attachment can be removed from the Files tab.
    app(AssetTrashService::class)->restore(Asset::withTrashed()->findOrFail($secondId));
    $this->deleteJson(route('admin.dam.assets.files.destroy', [$firstId, $attached->id]))->assertOk();
    expect(Asset::count())->toBe($entriesBefore + 1)
        ->and(app(ContentStore::class)->current(Asset::find($secondId))->sha256)->toBe($sha);
});

it('refuses Stack instead for a target that is hidden or missing alike, for bytes it does not hold, and for itself', function () {
    exactDupAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $hiddenId = exactDupUpload($this, $hiddenFolder, 'secret.png', $this->bytes)->assertCreated()->json('files.0.id');
    $other = exactDupUpload($this, $this->folder, 'other.png', exactDupPng('other'))->assertCreated()->json('files.0.id');
    $member = exactDupMember();
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $member->role_id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $uploadId = exactDupUpload($this, $this->folder, 'mine.png', $this->bytes)->assertCreated()->json('files.0.id');

    $hidden = $this->postJson(route('admin.dam.assets.stack-instead', $uploadId), ['target_id' => $hiddenId]);
    $missing = $this->postJson(route('admin.dam.assets.stack-instead', $uploadId), ['target_id' => Asset::withTrashed()->max('id') + 1000]);
    expect($hidden->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
        ->and($hidden->json('message'))->toBe($missing->json('message'));

    $this->postJson(route('admin.dam.assets.stack-instead', $uploadId), ['target_id' => $other])->assertUnprocessable();
    $this->postJson(route('admin.dam.assets.stack-instead', $uploadId), ['target_id' => $uploadId])->assertUnprocessable();
    expect(Asset::find($uploadId))->not->toBeNull();
});

it('names its own entry when a same-name upload changes nothing, and keeps the new-version behaviour', function () {
    $id = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated()->json('files.0.id');
    $versions = AssetVersion::where('dam_asset_id', $id)->count();

    $again = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated();
    expect($again->json('files.0.id'))->toBe($id)
        ->and($again->json('duplicates.0.kind'))->toBe('unchanged')
        ->and($again->json('duplicates.0.match.id'))->toBe($id)
        ->and($again->json('duplicates.0.actions'))->toBe(['open'])
        ->and(AssetVersion::where('dam_asset_id', $id)->count())->toBe($versions);

    // Different bytes under the same name still become a new version, with no notice.
    $changed = exactDupUpload($this, $this->folder, 'hero.png', exactDupPng('hero-2'))->assertCreated();
    expect($changed->json('files.0.id'))->toBe($id)->and($changed->json('duplicates'))->toBe([])
        ->and(AssetVersion::where('dam_asset_id', $id)->count())->toBe($versions + 1);
});

it('aggregates a bulk upload\'s notices in one list, and tells only the second of two identical files about the first', function () {
    $existing = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated()->json('files.0.id');
    $twin = exactDupPng('twin');

    $response = $this->postJson(route('admin.dam.assets.upload'), [
        'directory_id' => $this->folder->id,
        'files'        => [
            UploadedFile::fake()->createWithContent('a.png', $this->bytes),
            UploadedFile::fake()->createWithContent('twin-1.png', $twin),
            UploadedFile::fake()->createWithContent('twin-2.png', $twin),
            UploadedFile::fake()->createWithContent('fresh.png', exactDupPng('fresh')),
        ],
    ])->assertCreated();
    $ids = collect($response->json('files'))->pluck('id')->all();

    expect(collect($response->json('duplicates'))->map(fn ($notice) => [$notice['asset_id'], $notice['match']['id']])->all())
        ->toBe([[$ids[0], $existing], [$ids[2], $ids[1]]]);
});

it('gives a REST upload the same notice, and Stack instead through REST', function () {
    $firstId = exactDupUpload($this, $this->folder, 'hero.png', $this->bytes)->assertCreated()->json('files.0.id');

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.upload', 'api.dam.assets.stack_instead']);
    $response = $this->withHeaders($headers)->post(route('admin.api.dam.assets.upload'), [
        'directory_id' => $this->folder->id,
        'files'        => [UploadedFile::fake()->createWithContent('rest-copy.png', $this->bytes)],
    ])->assertCreated();
    $restId = $response->json('files.0.id');

    expect($response->json('duplicates.0.asset_id'))->toBe($restId)
        ->and($response->json('duplicates.0.match.id'))->toBe($firstId)
        ->and($response->json('duplicates.0.message'))->toStartWith('Already in Library: ')
        ->and($response->json('duplicates.0.actions'))->toBe(['open', 'stack_instead', 'keep_both']);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.stack-instead', $restId), ['target_id' => $firstId])
        ->assertOk()->assertJsonPath('data.trashed_asset_id', $restId);
    expect(Asset::find($restId))->toBeNull();

    // A key without the Stack instead scope is not offered it, and the route refuses it.
    // (The api guard keeps the first key's user across requests in one test; forget it so the second key counts.)
    app('auth')->forgetGuards();
    $plain = $this->getAuthenticationHeaders('custom', ['api.dam.assets.upload']);
    $again = $this->withHeaders($plain)->post(route('admin.api.dam.assets.upload'), [
        'directory_id' => $this->folder->id,
        'files'        => [UploadedFile::fake()->createWithContent('rest-again.png', $this->bytes)],
    ])->assertCreated();
    expect($again->json('duplicates.0.actions'))->toBe(['open', 'keep_both']);
    $this->withHeaders($plain)->postJson(route('admin.api.dam.assets.stack-instead', $again->json('files.0.id')), ['target_id' => $firstId])->assertForbidden();
});

it('puts the notice on a finished chunked upload\'s status, and none on one with no visible match', function () {
    config(['dam.explorer.upload.chunk_bytes' => 4]);
    $contents = 'abcdefghijkl';
    $firstId = exactDupUpload($this, $this->folder, 'notes.txt', $contents)->assertCreated()->json('files.0.id');

    $chunked = function (string $name, string $bytes): ChunkUpload {
        $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'directory_id' => $this->folder->id, 'state' => UploadTracker::STATE_PROCESSING]);
        $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
            'session_uuid' => $tracker->uuid, 'directory_id' => $this->folder->id, 'original_name' => $name, 'mime_type' => 'text/plain',
            'size'         => strlen($bytes), 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', $bytes), 'preserve_root' => false,
        ])->assertCreated()->assertJsonPath('upload.duplicate', null)->json('upload.uuid');
        $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();

        foreach (str_split($bytes, (int) $upload->chunk_bytes) as $index => $chunk) {
            $start = $index * (int) $upload->chunk_bytes;
            $this->call('PUT', route('admin.dam.assets.chunk-upload.store', [$uuid, $index]), server: [
                'CONTENT_TYPE'        => 'application/octet-stream',
                'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, strlen($bytes)),
                'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
            ], content: $chunk)->assertOk();
        }

        $this->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))->assertAccepted();
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

        return $upload->fresh();
    };

    $duplicate = $chunked('notes-copy.txt', $contents);
    $status = $this->getJson(route('admin.dam.assets.chunk-upload.status', $duplicate->uuid))->assertOk();
    expect($duplicate->publish_outcome)->toBe('created')
        ->and($status->json('upload.duplicate.asset_id'))->toBe((int) $duplicate->asset_id)
        ->and($status->json('upload.duplicate.match.id'))->toBe($firstId)
        ->and($status->json('upload.duplicate.actions'))->toBe(['open', 'stack_instead', 'keep_both']);

    $fresh = $chunked('other.txt', 'mnopqrstuvwx');
    $this->getJson(route('admin.dam.assets.chunk-upload.status', $fresh->uuid))->assertOk()->assertJsonPath('upload.duplicate', null);
});

/*
 * P3b-2 review: the non-disclosure contract on every finalize path. Under grants, a member who may open folder A
 * uploads bytes that match a visible file, a file in a folder they cannot open, a trashed file, someone else's
 * draft, or nothing. Only the visible match is reported, and the answers for the other four have the same shape.
 */

/**
 * Seeds the four matches as a full admin and returns the member and the bytes per state.
 *
 * @return array{member: Admin, folder: Directory, bytes: array<string, string>, matches: array<string, int>}
 */
function exactDupMatrix(object $test, Directory $folder): array
{
    exactDupAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $folder->parent_id]);
    $seed = Str::random(6);
    $bytes = [];
    foreach (['visible', 'hidden', 'trashed', 'unavailable', 'absent'] as $state) {
        $bytes[$state] = exactDupPng($seed.$state);
    }

    $matches = [
        'visible'     => exactDupUpload($test, $folder, "m-visible-{$seed}.png", $bytes['visible'])->assertCreated()->json('files.0.id'),
        'hidden'      => exactDupUpload($test, $hiddenFolder, "m-hidden-{$seed}.png", $bytes['hidden'])->assertCreated()->json('files.0.id'),
        'trashed'     => exactDupUpload($test, $folder, "m-trashed-{$seed}.png", $bytes['trashed'])->assertCreated()->json('files.0.id'),
        'unavailable' => exactDupUpload($test, $folder, "m-draft-{$seed}.png", $bytes['unavailable'])->assertCreated()->json('files.0.id'),
    ];
    app(AssetTrashService::class)->trash(asset: Asset::find($matches['trashed']));
    Asset::where('id', $matches['unavailable'])->update(['availability' => Asset::AVAILABILITY_DRAFT, 'created_by' => Admin::factory()->create()->id]);

    $member = exactDupMember();
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $member->role_id, 'created_at' => now(), 'updated_at' => now()]);
    $test->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return ['member' => $member, 'folder' => $folder, 'bytes' => $bytes, 'matches' => $matches];
}

/** REST headers for a key whose admin holds the member's role. */
function exactDupRestHeaders(object $test, Admin $member): array
{
    $headers = $test->getAuthenticationHeaders('custom', ['api.dam.assets.upload', 'api.dam.assets.stack_instead']);
    Admin::latest('id')->first()->update(['role_id' => $member->role_id]);
    app('auth')->forgetGuards();
    app(DirectoryPermissionService::class)->flush();

    return $headers;
}

/** A whole chunked upload, finalized; the status the client reads at the end. */
function exactDupChunked(object $test, Directory $folder, string $name, string $bytes): array
{
    $tracker = UploadTracker::create(['uuid' => (string) Str::uuid(), 'user_id' => auth('admin')->id(), 'directory_id' => $folder->id, 'state' => UploadTracker::STATE_PROCESSING]);
    $uuid = $test->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $folder->id, 'original_name' => $name, 'mime_type' => 'image/png',
        'size'         => strlen($bytes), 'last_modified' => 1_788_864_000_000, 'fingerprint' => hash('sha256', $bytes), 'preserve_root' => false,
    ])->assertCreated()->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();

    foreach (str_split($bytes, (int) $upload->chunk_bytes) as $index => $chunk) {
        $start = $index * (int) $upload->chunk_bytes;
        $test->call('PUT', route('admin.dam.assets.chunk-upload.store', [$uuid, $index]), server: [
            'CONTENT_TYPE'        => 'application/octet-stream',
            'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, strlen($bytes)),
            'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
        ], content: $chunk)->assertOk();
    }

    $test->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    return $test->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))->assertOk()->json();
}

/**
 * One upload of `$files` (name => bytes) through `$path`; the whole JSON answer and its notices.
 *
 * @return array{json: array, notices: array<int, array>}
 */
function exactDupFinalize(object $test, string $path, Directory $folder, array $files, array $headers = []): array
{
    $uploads = fn () => collect($files)->map(fn ($bytes, $name) => UploadedFile::fake()->createWithContent($name, $bytes))->values()->all();

    $json = match ($path) {
        'web'    => $test->postJson(route('admin.dam.assets.upload'), ['directory_id' => $folder->id, 'files' => $uploads()])->assertCreated()->json(),
        'folder' => $test->postJson(route('admin.dam.assets.upload_folder'), [
            'directory_id'   => $folder->id,
            'files'          => $uploads(),
            'relative_paths' => collect($files)->keys()->map(fn ($name) => 'Shoot/'.$name)->all(),
        ])->assertCreated()->json(),
        'rest'   => $test->withHeaders($headers)->post(route('admin.api.dam.assets.upload'), ['directory_id' => $folder->id, 'files' => $uploads()])->assertCreated()->json(),
        'chunk'  => exactDupChunked($test, $folder, (string) array_key_first($files), (string) reset($files)),
    };

    $notices = $path === 'chunk' ? array_values(array_filter([$json['upload']['duplicate']])) : $json['duplicates'];

    return ['json' => $json, 'notices' => $notices];
}

it('reports only a visible match on every finalize path, and answers hidden, trashed and unavailable matches like no match', function (string $path) {
    config(['dam.explorer.upload.chunk_bytes' => 64]);
    $matrix = exactDupMatrix($this, $this->folder);
    $headers = $path === 'rest' ? exactDupRestHeaders($this, $matrix['member']) : [];

    $answers = [];
    foreach ($matrix['bytes'] as $state => $bytes) {
        $answers[$state] = exactDupFinalize($this, $path, $this->folder, ["{$path}-{$state}-".Str::random(4).'.png' => $bytes], $headers);
    }

    expect(array_map(fn ($notice) => [$notice['kind'], $notice['outcome'], $notice['match']['id']], $answers['visible']['notices']))
        ->toBe([['exact', 'created', $matrix['matches']['visible']]]);

    foreach (['hidden', 'trashed', 'unavailable', 'absent'] as $state) {
        expect($answers[$state]['notices'])->toBe([], "{$path} {$state}")
            ->and(exactDupShape($answers[$state]['json']))->toBe(exactDupShape($answers['absent']['json']), "{$path} {$state}");
    }
})->with(['web', 'folder', 'rest', 'chunk']);

it('reports only the visible match in a multi-file batch on the web, folder and REST paths', function (string $path) {
    $matrix = exactDupMatrix($this, $this->folder);
    $headers = $path === 'rest' ? exactDupRestHeaders($this, $matrix['member']) : [];
    $files = collect($matrix['bytes'])->mapWithKeys(fn ($bytes, $state) => ["batch-{$path}-{$state}-".Str::random(4).'.png' => $bytes])->all();

    $answer = exactDupFinalize($this, $path, $this->folder, $files, $headers);
    $visibleUpload = collect($answer['json']['files'])->firstWhere('file_name', array_key_first($files));

    expect(array_map(fn ($notice) => [$notice['asset_id'], $notice['match']['id']], $answer['notices']))
        ->toBe([[(int) $visibleUpload['id'], $matrix['matches']['visible']]])
        ->and(count($answer['json']['files']))->toBe(5);
})->with(['web', 'folder', 'rest']);

it('treats a folder upload that overwrites a same-name file like any version: hidden bytes say nothing, visible bytes are named', function () {
    $matrix = exactDupMatrix($this, $this->folder);
    $sameId = exactDupUpload($this, $this->folder, 'same.png', exactDupPng('same-'.Str::random(4)))->assertCreated()->json('files.0.id');
    $absent = exactDupFinalize($this, 'folder', $this->folder, ['same.png' => exactDupPng('fresh-'.Str::random(4))]);

    $hidden = exactDupFinalize($this, 'folder', $this->folder, ['same.png' => $matrix['bytes']['hidden']]);
    expect($hidden['json']['files'][0]['id'])->toBe($sameId)
        ->and($hidden['notices'])->toBe([])
        ->and(exactDupShape($hidden['json']))->toBe(exactDupShape($absent['json']));

    $visible = exactDupFinalize($this, 'folder', $this->folder, ['same.png' => $matrix['bytes']['visible']]);
    expect($visible['json']['files'][0]['id'])->toBe($sameId)
        ->and(array_map(fn ($notice) => [$notice['kind'], $notice['outcome'], $notice['match']['id'], $notice['actions']], $visible['notices']))
        ->toBe([['exact', 'versioned', $matrix['matches']['visible'], ['open']]]);

    $unchanged = exactDupFinalize($this, 'folder', $this->folder, ['same.png' => $matrix['bytes']['visible']]);
    expect(array_map(fn ($notice) => [$notice['kind'], $notice['match']['id']], $unchanged['notices']))->toBe([['unchanged', $sameId]]);
});
