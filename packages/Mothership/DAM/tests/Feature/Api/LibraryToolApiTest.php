<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/CreativeHubFixtures.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Mcp\LibraryTool;
use Mothership\DAM\Mcp\MothershipServer;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Vectorization\AgentVectorizations;
use Mothership\User\Models\Admin;

/*
 * The library tools over HTTPS for the local `mothership` launcher (LibraryToolController, docs/api/mothership-mcp.md):
 * LibraryTool's rules and LibraryOperations as the stdio server runs them, as the bearer key's own admin, behind the
 * scope of each tool's REST twin. The server never opens a path the caller names: a file to save comes with the call.
 */

const LIBRARY_REST_KEYS = [...HUB_MEMBER_KEYS, 'dam.asset.files.store', 'dam.asset.edit', 'dam.asset.update', 'dam.asset.tag', 'dam.asset.relationships.store', 'dam.directory', 'dam.directory.store'];

/** Bearer headers for $admin's own API key: every scope, or only $permissions. */
function libraryToolHeaders(Admin $admin, string $permissionType = 'all', ?array $permissions = null): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();
    $admin->forceFill(['password' => Hash::make('password')])->save();
    $client = (new ClientRepository)->createPasswordGrantClient('Client for testing the library tools', 'admins', confidential: true);
    $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();
    Apikey::factory()->create(['permission_type' => $permissionType, 'admin_id' => $admin->id, 'oauth_client_id' => $client->getKey(), 'permissions' => $permissions]);
    $credential = test()->postJson('/oauth/token', [
        'grant_type' => 'password', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
        'username'   => $admin->email, 'password' => 'password', 'scope' => '',
    ])->json('access_token');
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$credential, 'Accept' => 'application/json'];
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->folder = hubFolder('Renders');
    $this->member = hubMember($this->folder, LIBRARY_REST_KEYS);
    $this->headers = libraryToolHeaders($this->member);
    $this->scratch = sys_get_temp_dir().'/mothership-rest-test-'.Str::random(10);
    File::ensureDirectoryExists($this->scratch.'/in');
    $this->png = function (string $name, int $size = 16): string {
        $path = $this->scratch.'/in/'.$name;
        // Held in a variable: the fake file's temp path disappears once the object is released.
        $image = UploadedFile::fake()->image('x.png', $size, $size);
        copy($image->getRealPath(), $path);

        return $path;
    };
    /** One tool call as the launcher sends it: JSON arguments, or multipart with the file at $upload. */
    $this->send = function (string $route, array $arguments = [], ?string $upload = null, ?array $headers = null): TestResponse {
        $request = $this->withHeaders($headers ?? $this->headers);
        if ($upload === null) {
            return $request->postJson(route($route), $arguments);
        }

        return $request->post(route($route), [
            'arguments' => json_encode($arguments),
            'upload'    => json_encode(['name' => basename($upload)]),
            'file'      => new UploadedFile($upload, basename($upload), null, null, true),
        ]);
    };
    $this->saveArguments = fn (string $path, array $extra = []): array => [
        'local_path' => $path, 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'blender'], 'request_id' => (string) Str::uuid(), ...$extra,
    ];
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

it('refuses a library tool without the scope of its REST twin, and runs it with that scope', function () {
    $path = ($this->png)('scoped.png');
    $arguments = ($this->saveArguments)($path);

    $reader = libraryToolHeaders(hubMember($this->folder, LIBRARY_REST_KEYS), 'custom', ['api.dam', 'api.dam.assets']);
    ($this->send)('admin.api.dam.library_tools.save', $arguments, $path, $reader)
        ->assertForbidden()
        ->assertExactJson(['error' => 'This action is unauthorized']);
    expect(Asset::query()->where('file_name', 'scoped.png')->exists())->toBeFalse();
    ($this->send)('admin.api.dam.library_tools.search', [], null, $reader)->assertOk()->assertJsonPath('result.assets', []);

    $uploader = libraryToolHeaders(hubMember($this->folder, LIBRARY_REST_KEYS), 'custom', ['api.dam', 'api.dam.assets', 'api.dam.assets.upload']);
    ($this->send)('admin.api.dam.library_tools.save', $arguments, $path, $uploader)
        ->assertOk()
        ->assertJsonPath('result.name', 'scoped.png')
        ->assertJsonPath('result.replayed', false);

    // Without a bearer: the headers set above persist on the test client until flushed.
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->postJson(route('admin.api.dam.library_tools.search'), [], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('returns the first asset for a repeated request_id instead of saving a second', function () {
    $path = ($this->png)('hero-render.png');
    $reference = (int) hubAsset($this->folder)->id;
    $arguments = ($this->saveArguments)($path, [
        'title'      => 'Hero render', 'tags' => ['Lobby'],
        'producer'   => ['tool' => 'blender', 'version' => '4.4'],
        'provenance' => ['backend' => 'cycles', 'reference_asset_ids' => [$reference]],
    ]);

    $first = ($this->send)('admin.api.dam.library_tools.save', $arguments, $path)->assertOk()->json('result');
    $again = ($this->send)('admin.api.dam.library_tools.save', $arguments, $path)->assertOk()->json('result');

    expect($first['replayed'])->toBeFalse()
        ->and($first['name'])->toBe('hero-render.png')
        ->and($first['source_sha256'])->toBe(hash_file('sha256', $path))
        ->and($first['derived_from'])->toBe([$reference])
        ->and($again['replayed'])->toBeTrue()
        ->and($again['asset_id'])->toBe($first['asset_id'])
        ->and(Asset::query()->where('file_name', 'like', 'hero-render%')->count())->toBe(1)
        ->and(Asset::query()->whereKey($first['asset_id'])->value('created_by'))->toBe((int) $this->member->id);

    // The same request_id with other bytes is refused, never a second asset.
    $other = ($this->png)('other.png', 24);
    ($this->send)('admin.api.dam.library_tools.save', ['local_path' => $other] + $arguments, $other)->assertStatus(409);
    expect(Asset::query()->count())->toBe(2);

    $asset = ($this->send)('admin.api.dam.library_tools.get_asset', ['asset_id' => $first['asset_id']])->assertOk()->json('result');
    expect($asset['producer'])->toBe('blender')->and($asset['producer_version'])->toBe('4.4')->and($asset['sha256'])->toBe($first['sha256']);
});

it('names a saved file as the stdio server does, backslash and all', function () {
    // A backslash is a legal file name character here; PHP keeps only what follows it in an upload's own file name.
    $path = ($this->png)('draft\\hero.png');

    ($this->send)('admin.api.dam.library_tools.save', ($this->saveArguments)($path), $path)
        ->assertOk()
        ->assertJsonPath('result.name', 'draft hero.png');
});

it('never reads or writes a path the caller names', function () {
    $path = ($this->png)('on-the-server.png');
    ($this->send)('admin.api.dam.library_tools.save', ($this->saveArguments)($path))
        ->assertStatus(422)
        ->assertExactJson(['error' => 'Send the file at local_path with the call, as the multipart field file.']);
    ($this->send)('admin.api.dam.library_tools.vectorize', ['local_path' => $path, 'request_id' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertExactJson(['error' => 'Send the file at local_path with the call, as the multipart field file.']);
    ($this->send)('admin.api.dam.library_tools.search', [], $path)
        ->assertStatus(422)
        ->assertExactJson(['error' => 'Send a file only with local_path, to library_save or library_vectorize.']);
    expect(Asset::query()->where('file_name', 'on-the-server.png')->exists())->toBeFalse();

    // A fetch answers with the bytes; dest_dir is checked like any argument and never written.
    $saved = ($this->send)('admin.api.dam.library_tools.save', ($this->saveArguments)($path), $path)->assertOk()->json('result');
    $response = ($this->send)('admin.api.dam.library_tools.fetch', ['asset_id' => $saved['asset_id'], 'dest_dir' => $this->scratch.'/out'])->assertOk();
    $bytes = $response->streamedContent();
    $meta = json_decode(base64_decode((string) $response->headers->get('X-Library-Result')), true);
    expect(is_dir($this->scratch.'/out'))->toBeFalse()
        ->and($bytes)->toBe(file_get_contents($path))
        ->and($meta['sha256'])->toBe(hash('sha256', $bytes))
        ->and($meta['size'])->toBe(strlen($bytes))
        ->and($meta['name'])->toBe('on-the-server.png')
        ->and($meta['result']['path'])->toBeNull()
        ->and($meta['result']['reused'])->toBeNull()
        ->and($meta['result']['asset_id'])->toBe($saved['asset_id']);
    ($this->send)('admin.api.dam.library_tools.fetch', ['asset_id' => $saved['asset_id'], 'dest_dir' => ['not', 'a', 'path']])->assertStatus(422);
});

it('gives the stdio server\'s words for a refusal', function () {
    config(['mothership-mcp.user_id' => (int) $this->member->id]);
    $stdio = function (string $action, array $arguments): string {
        $response = MothershipServer::tool(new LibraryTool(action: $action), $arguments);
        $raw = (fn () => $this->response->toArray())->call($response);

        return (string) ($raw['result']['content'][0]['text'] ?? $raw['error']['message'] ?? '');
    };
    $hidden = hubAsset(hubFolder('Private'));
    app(DirectoryPermissionService::class)->flush();
    $cases = [
        ['save', ['folder_id' => (int) $this->folder->id], 422],
        // local_path with no file and no request_id: the field rules answer first, as on stdio, not the missing upload.
        ['save', ['local_path' => $this->scratch.'/in/absent.png', 'folder_id' => (int) $this->folder->id, 'producer' => ['tool' => 'blender']], 422],
        ['search', ['surprise'         => true], 422],
        ['get_asset', ['asset_id'      => (int) $hidden->id], 404],
        ['create_folder', ['parent_id' => (int) $this->folder->id, 'name' => 'a/b'], 422],
    ];

    foreach ($cases as [$action, $arguments, $status]) {
        $error = ($this->send)('admin.api.dam.library_tools.'.$action, $arguments)->assertStatus($status)->json('error');
        expect($error)->toBe($stdio($action, $arguments));
    }
});

it('keeps hidden folders and assets out of every library tool over REST', function () {
    $hidden = hubFolder('Private');
    $secret = hubAsset($hidden);
    app(DirectoryPermissionService::class)->flush();

    $found = ($this->send)('admin.api.dam.library_tools.search', ['query' => 'quadrants'])->assertOk()->json('result.assets');
    $folders = ($this->send)('admin.api.dam.library_tools.list_folders')->assertOk()->json('result.folders');
    expect(array_column($found, 'asset_id'))->not->toContain((int) $secret->id)
        ->and(array_column($folders, 'folder_id'))->not->toContain((int) $hidden->id)->toContain((int) $this->folder->id);
    ($this->send)('admin.api.dam.library_tools.list_stack', ['asset_id' => (int) $secret->id])->assertNotFound()->assertExactJson(['error' => 'Not found.']);
    ($this->send)('admin.api.dam.library_tools.fetch', ['asset_id' => (int) $secret->id])->assertNotFound()->assertExactJson(['error' => 'Not found.']);
});

it('answers another account\'s conversion like a missing one over REST', function () {
    $owner = Admin::factory()->create();
    $viewer = Admin::factory()->create();
    config(['vectorization.enabled' => true, 'vectorization.owner_admin_id' => (int) $owner->id, 'vectorization.api_id' => 'x', 'vectorization.api_secret' => 'y']);
    Storage::fake('private');
    $row = app(AgentVectorizations::class)->fromFile(actor: $owner, path: ($this->png)('logo.png', 24), requestId: (string) Str::uuid());
    $headers = libraryToolHeaders($viewer);

    ($this->send)('admin.api.dam.library_tools.vectorization', ['id' => (string) $row->id], null, $headers)->assertNotFound()->assertExactJson(['error' => 'Not found.']);
    ($this->send)('admin.api.dam.library_tools.vectorization', ['id' => (string) $row->id, 'format' => 'svg'], null, $headers)->assertNotFound()->assertExactJson(['error' => 'Not found.']);
    expect(DB::table('dam_vectorizations')->where('id', $row->id)->value('admin_id'))->toBe((int) $owner->id);
});
