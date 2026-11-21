<?php

require_once __DIR__.'/../../packages/Mothership/DAM/tests/Support/CreativeHubFixtures.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Registrar;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Mcp\LibraryTool;
use Mothership\DAM\Mcp\MothershipServer;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;

/*
 * The `mothership` stdio library server (docs/api/mothership-mcp.md), called through laravel/mcp's own test
 * transport. Every call acts as MOTHERSHIP_MCP_USER_ID with that account's permissions and folder access; files go
 * out only as sha256-checked copies outside storage, and come in through the same create-only save the Library uses.
 */

const LIBRARY_OWNER_KEYS = [...HUB_MEMBER_KEYS, 'dam.asset.files.store', 'dam.asset.edit', 'dam.asset.update', 'dam.asset.tag', 'dam.directory', 'dam.directory.store'];

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->folder = hubFolder('Renders');
    $this->owner = hubMember($this->folder, LIBRARY_OWNER_KEYS);
    config(['mothership-mcp.user_id' => (int) $this->owner->id]);
    auth()->forgetGuards();
    $this->scratch = sys_get_temp_dir().'/mothership-test-'.Str::random(10);
    File::ensureDirectoryExists($this->scratch.'/in');
    /** The JSON-RPC answer as an array: [structured result or null, error text or null]. */
    $this->call = function (string $action, array $arguments = []): array {
        $response = MothershipServer::tool(new LibraryTool(action: $action), $arguments);
        $raw = (fn () => $this->response->toArray())->call($response);
        $result = $raw['result'] ?? [];

        return ($result['isError'] ?? false) === true
            ? [null, (string) ($result['content'][0]['text'] ?? '')]
            : [$result['structuredContent'] ?? json_decode((string) ($result['content'][0]['text'] ?? 'null'), true), null];
    };
    $this->ok = function (string $action, array $arguments = []): array {
        [$data, $error] = ($this->call)($action, $arguments);
        expect($error)->toBeNull();

        return $data;
    };
    $this->png = function (string $name, int $size = 16): string {
        $path = $this->scratch.'/in/'.$name;
        // Held in a variable: the fake file's temp path disappears once the object is released.
        $image = UploadedFile::fake()->image('x.png', $size, $size);
        copy($image->getRealPath(), $path);

        return $path;
    };
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('registers only the local stdio server and no hosted MCP or upload routes', function () {
    expect(app(Registrar::class)->getLocalServer('mothership'))->not->toBeNull()
        ->and(Route::has('craft.mcp'))->toBeFalse()
        ->and(Route::has('craft.drop.show'))->toBeFalse()
        ->and(LibraryTool::actions())->toBe(['search', 'list_folders', 'create_folder', 'get_asset', 'list_stack', 'fetch', 'save', 'update', 'vectorize', 'vectorization']);
});

it('refuses every tool until an active owner account is configured', function () {
    config(['mothership-mcp.user_id' => 0]);
    [, $error] = ($this->call)('search');
    expect($error)->toContain('MOTHERSHIP_MCP_USER_ID');

    $this->owner->forceFill(['status' => 0])->save();
    config(['mothership-mcp.user_id' => (int) $this->owner->id]);
    [, $error] = ($this->call)('list_folders');
    expect($error)->toContain('active admin');
});

it('saves a local file as a new asset with its producer and provenance, and replays a retry', function () {
    $path = ($this->png)('hero-render.png');
    $requestId = (string) Str::uuid();
    $arguments = [
        'local_path' => $path, 'folder_id' => (int) $this->folder->id, 'title' => 'Hero render', 'tags' => ['Lobby', 'night'],
        'producer'   => ['tool' => 'blender', 'version' => '4.4'],
        'provenance' => ['backend' => 'cycles', 'reference_asset_ids' => [7], 'vault_note' => 'Projects/Lobby/render.md'],
        'notes'      => 'Final lighting pass.', 'request_id' => $requestId,
    ];
    $saved = ($this->ok)('save', $arguments);

    expect($saved['replayed'])->toBeFalse()
        ->and($saved['name'])->toBe('hero-render.png')
        ->and($saved['title'])->toBe('Hero render')
        ->and($saved['tags'])->toBe(['Lobby', 'night'])
        ->and($saved['source_sha256'])->toBe(hash_file('sha256', $path))
        ->and(Asset::query()->whereKey($saved['asset_id'])->value('created_by'))->toBe((int) $this->owner->id);

    $again = ($this->ok)('save', $arguments);
    expect($again['asset_id'])->toBe($saved['asset_id'])->and($again['replayed'])->toBeTrue()
        ->and(Asset::query()->where('file_name', 'like', 'hero-render%')->count())->toBe(1);

    // The same request_id with other bytes is refused, never a second asset.
    [, $error] = ($this->call)('save', ['local_path' => ($this->png)('other.png', 24)] + $arguments);
    expect($error)->not->toBeNull();

    $asset = ($this->ok)('get_asset', ['asset_id' => $saved['asset_id']]);
    expect($asset['producer'])->toBe('blender')
        ->and($asset['producer_version'])->toBe('4.4')
        ->and($asset['provenance'])->toBe(['backend' => 'cycles', 'reference_asset_ids' => [7], 'vault_note' => 'Projects/Lobby/render.md'])
        ->and($asset['notes'])->toBe('Final lighting pass.')
        ->and($asset['sha256'])->toBe($saved['sha256'])
        ->and($asset['folders'])->toBe([['folder_id' => (int) $this->folder->id, 'name' => $this->folder->name]]);

    $found = ($this->ok)('search', ['producer' => 'blender']);
    expect(array_column($found['assets'], 'asset_id'))->toBe([$saved['asset_id']])
        ->and($found['assets'][0]['producer'])->toBe('blender');
    expect(array_column(($this->ok)('search', ['query' => 'LOBBY'])['assets'], 'asset_id'))->toBe([$saved['asset_id']])
        ->and(array_column(($this->ok)('search', ['tag' => 'night', 'kind' => 'image'])['assets'], 'asset_id'))->toBe([$saved['asset_id']]);

    // A retry never reverts edits made since the first save.
    ($this->ok)('update', ['asset_id' => $saved['asset_id'], 'title' => 'Hero render final', 'tags_remove' => ['night']]);
    $retried = ($this->ok)('save', $arguments);
    expect($retried['replayed'])->toBeTrue()->and($retried['title'])->toBe('Hero render final')->and($retried['tags'])->toBe(['Lobby']);

    // Nor does it answer once the asset has moved where the account cannot see it.
    DB::table('dam_asset_directory')->where('asset_id', $saved['asset_id'])->update(['directory_id' => hubFolder('Private')->id]);
    [, $error] = ($this->call)('save', $arguments);
    expect($error)->toBe('Not found.')
        ->and(Asset::query()->whereKey($saved['asset_id'])->value('title'))->toBe('Hero render final');
});

it('stacks an editable source with its export and copies a member out as a checked local file', function () {
    $cover = ($this->ok)('save', ['local_path' => ($this->png)('poster.png'), 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid()]);
    $source = $this->scratch.'/in/poster.blend';
    file_put_contents($source, 'BLENDER-v404'.str_repeat("\x00", 64));
    [, $error] = ($this->call)('save', ['local_path' => $source, 'stack_asset_id' => $cover['asset_id'], 'title' => 'Renamed', 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid()]);
    expect($error)->toContain('library_update');
    $member = ($this->ok)('save', ['local_path' => $source, 'stack_asset_id' => $cover['asset_id'], 'tags' => ['source'], 'producer' => ['tool' => 'blender', 'version' => '4.4'], 'request_id' => $memberRequest = (string) Str::uuid()]);

    expect($member['stack'])->toBeTrue()->and($member['cover_changed'])->toBeFalse()->and($member['replayed'])->toBeFalse()
        ->and($member['tags'])->toBe(['source']);
    $replay = ($this->ok)('save', ['local_path' => $source, 'stack_asset_id' => $cover['asset_id'], 'tags' => ['source'], 'producer' => ['tool' => 'blender', 'version' => '4.4'], 'request_id' => $memberRequest]);
    expect($replay['file_id'])->toBe($member['file_id'])->and($replay['replayed'])->toBeTrue()->and($replay['tags'])->toBe(['source']);

    $stack = ($this->ok)('list_stack', ['asset_id' => $cover['asset_id'], 'extension' => 'blend']);
    expect($stack['files'])->toHaveCount(1)
        ->and($stack['files'][0]['file_id'])->toBe($member['file_id'])
        ->and($stack['files'][0]['producer'])->toBe('blender');

    $copy = ($this->ok)('fetch', ['asset_id' => $cover['asset_id'], 'file_id' => $member['file_id'], 'dest_dir' => $this->scratch.'/out']);
    expect($copy['path'])->toBe(realpath($this->scratch.'/out').'/poster.blend')
        ->and(hash_file('sha256', $copy['path']))->toBe($copy['sha256'])
        ->and($copy['sha256'])->toBe(hash_file('sha256', $source))
        ->and($copy['reused'])->toBeFalse()
        ->and(str_starts_with($copy['path'], storage_path()))->toBeFalse();
    expect(($this->ok)('fetch', ['asset_id' => $cover['asset_id'], 'file_id' => $member['file_id'], 'dest_dir' => $this->scratch.'/out'])['reused'])->toBeTrue();

    // A different file already at that name is never overwritten.
    file_put_contents($this->scratch.'/out/poster.png', 'not the cover');
    $main = ($this->ok)('fetch', ['asset_id' => $cover['asset_id'], 'dest_dir' => $this->scratch.'/out']);
    expect(basename($main['path']))->toBe('poster-2.png')
        ->and(file_get_contents($this->scratch.'/out/poster.png'))->toBe('not the cover');
});

it('never writes a copy inside The Mothership\'s storage', function () {
    $saved = ($this->ok)('save', ['local_path' => ($this->png)('a.png'), 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'image-gen'], 'request_id' => (string) Str::uuid()]);
    [, $error] = ($this->call)('fetch', ['asset_id' => $saved['asset_id'], 'dest_dir' => storage_path('app')]);
    expect($error)->toContain('own storage');
    [, $error] = ($this->call)('fetch', ['asset_id' => $saved['asset_id'], 'dest_dir' => 'relative/dir']);
    expect($error)->toContain('absolute');
});

it('keeps hidden folders and their assets out of every answer', function () {
    $hidden = hubFolder('Private');
    $secret = hubAsset($hidden);
    app(DirectoryPermissionService::class)->flush();

    expect(array_column(($this->ok)('search', ['query' => 'quadrants'])['assets'], 'asset_id'))->not->toContain((int) $secret->id)
        ->and(array_column(($this->ok)('list_folders')['folders'], 'folder_id'))->not->toContain((int) $hidden->id);
    [, $error] = ($this->call)('get_asset', ['asset_id' => (int) $secret->id]);
    expect($error)->toBe('Not found.');
    [, $error] = ($this->call)('fetch', ['asset_id' => (int) $secret->id, 'dest_dir' => $this->scratch.'/out']);
    expect($error)->toBe('Not found.');
    [, $error] = ($this->call)('save', ['local_path' => ($this->png)('x.png'), 'folder_id' => (int) $hidden->id, 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid()]);
    expect($error)->toBe('Not found.');
});

it('refuses a save the account may not make, and bytes that are not what the name says', function () {
    $reader = hubMember($this->folder, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download']);
    config(['mothership-mcp.user_id' => (int) $reader->id]);
    [, $error] = ($this->call)('save', ['local_path' => ($this->png)('x.png'), 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid()]);
    expect($error)->toContain('cannot do this');

    config(['mothership-mcp.user_id' => (int) $this->owner->id]);
    $fake = $this->scratch.'/in/clip.mp4';
    file_put_contents($fake, "\x00\x00\x00\x18ftypisom".str_repeat("\x00", 12)."\x00\x00\x10\x00mdat");
    [, $error] = ($this->call)('save', ['local_path' => $fake, 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'ffmpeg'], 'request_id' => (string) Str::uuid()]);
    expect($error)->not->toBeNull();

    // Every byte must belong to a whole box, even after moov.
    file_put_contents($fake, "\x00\x00\x00\x18ftypisom".str_repeat("\x00", 12)."\x00\x00\x00\x08moov".'abc');
    [, $error] = ($this->call)('save', ['local_path' => $fake, 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'ffmpeg'], 'request_id' => (string) Str::uuid()]);
    expect($error)->toContain('not a whole box');
    expect(DB::table('dam_creative_hub_saves')->count())->toBe(0);
});

it('creates a folder once and updates titles and tags', function () {
    $made = ($this->ok)('create_folder', ['parent_id' => (int) $this->folder->id, 'name' => 'Turntables']);
    expect($made['created'])->toBeTrue()
        ->and(($this->ok)('create_folder', ['parent_id' => (int) $this->folder->id, 'name' => 'turntables'])['folder_id'])->toBe($made['folder_id']);

    $saved = ($this->ok)('save', ['local_path' => ($this->png)('t.png'), 'folder_id' => $made['folder_id'], 'tags' => ['draft'], 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid()]);
    $updated = ($this->ok)('update', ['asset_id' => $saved['asset_id'], 'title' => 'Turntable 01', 'tags_add' => ['final'], 'tags_remove' => ['draft']]);
    expect($updated['title'])->toBe('Turntable 01')->and($updated['tags'])->toBe(['final']);

    [, $error] = ($this->call)('update', ['asset_id' => $saved['asset_id']]);
    expect($error)->toContain('Nothing to change');
    [, $error] = ($this->call)('search', ['unknown' => 1]);
    expect($error)->toContain('Unknown tool argument');
});

it('matches the tool list the Mac launcher answers with', function () {
    $manifest = json_decode((string) file_get_contents(base_path('tools/mcp-launcher/tools_manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $server = (new ReflectionClass(MothershipServer::class))->getDefaultProperties();
    $tools = array_map(fn (string $action): array => (new LibraryTool(action: $action))->toArray(), LibraryTool::actions());

    expect($manifest['serverInfo'])->toBe(['name' => $server['name'], 'version' => $server['version']])
        ->and($manifest['instructions'])->toBe($server['instructions'])
        ->and($manifest['protocolVersions'])->toBe(ProtocolVersion::supported())
        ->and($manifest['tools'])->toEqual(json_decode((string) json_encode($tools), true));
});

it('vectorizes a one-off local file and copies the result out, without a provider call per read', function () {
    $full = Admin::factory()->create();
    config(['mothership-mcp.user_id' => (int) $full->id, 'vectorization.enabled' => true, 'vectorization.owner_admin_id' => (int) $full->id,
        'vectorization.api_id'       => 'x', 'vectorization.api_secret' => 'y']);
    Storage::fake('private');
    $requestId = (string) Str::uuid();
    $queued = ($this->ok)('vectorize', ['local_path' => ($this->png)('logo.png', 24), 'request_id' => $requestId]);
    expect($queued['status'])->toBe('queued')->and($queued['kind'])->toBe('one_off')->and($queued['charged'])->toBeFalse()
        ->and(($this->ok)('vectorize', ['local_path' => ($this->png)('logo.png', 24), 'request_id' => $requestId])['id'])->toBe($queued['id']);
    [, $early] = ($this->call)('vectorization', ['id' => $queued['id'], 'format' => 'svg']);
    expect($early)->toContain('queued');

    $client = Mockery::mock(Vectorizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
    app()->instance(Vectorizer::class, $client);
    (new VectorizeImage(id: $queued['id']))->handle();

    $copy = ($this->ok)('vectorization', ['id' => $queued['id'], 'format' => 'svg', 'dest_dir' => $this->scratch.'/out']);
    expect($copy['status'])->toBe('completed')->and($copy['output'])->toBeNull()
        ->and(file_get_contents($copy['path']))->toContain('<svg')
        ->and($copy['sha256'])->toBe(hash_file('sha256', $copy['path']))
        ->and(($this->ok)('vectorization', ['id' => $queued['id'], 'format' => 'svg', 'dest_dir' => $this->scratch.'/out'])['reused'])->toBeTrue();
});

it('files a vectorization onto an asset and refuses an account without the paid tool', function () {
    $full = Admin::factory()->create();
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => (int) $full->id, 'vectorization.api_id' => 'x', 'vectorization.api_secret' => 'y']);
    $asset = hubAsset($this->folder);
    // The library owner's custom role has the default Home tools, which leave the paid Image Vectorization out.
    [, $refused] = ($this->call)('vectorize', ['asset_id' => (int) $asset->id, 'format' => 'pdf', 'request_id' => (string) Str::uuid()]);
    expect($refused)->toContain('cannot do this');

    config(['mothership-mcp.user_id' => (int) $full->id]);
    $queued = ($this->ok)('vectorize', ['asset_id' => (int) $asset->id, 'format' => 'pdf', 'request_id' => (string) Str::uuid()]);
    expect($queued['kind'])->toBe('asset')->and($queued['format'])->toBe('pdf')->and($queued['source_asset_id'])->toBe((int) $asset->id);
    [, $both] = ($this->call)('vectorize', ['asset_id' => (int) $asset->id, 'local_path' => ($this->png)('x.png'), 'format' => 'pdf', 'request_id' => (string) Str::uuid()]);
    expect($both)->not->toBeNull();
});
