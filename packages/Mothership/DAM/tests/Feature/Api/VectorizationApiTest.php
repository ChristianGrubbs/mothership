<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Image Vectorization over REST (docs/ops/image-vectorization.md): filed onto an asset, or a one-off upload, through
 * the same jobs, allowance and replay as the Library and Home. The provider is always faked: no test spends credits.
 */

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    config(['app.debug' => false]);
    $this->owner = Admin::factory()->create();
    $this->agent = Admin::factory()->create();
    // The owner is the human owner; the agent account is unlimited through the list, as on the live runtime.
    config(['vectorization.enabled'                                         => true, 'vectorization.owner_admin_id' => $this->owner->id,
        'vectorization.unlimited_admin_ids'                                 => [$this->agent->id],
        'vectorization.api_id'                                              => 'x', 'vectorization.api_secret' => 'y']);
    $image = UploadedFile::fake()->image('source.png', 20, 20);
    Storage::disk(Directory::getAssetDisk())->put('assets/source.png', file_get_contents($image->getRealPath()));
    $this->source = Asset::factory()->create(['file_name' => 'source.png', 'path' => 'assets/source.png', 'extension' => 'png', 'file_type' => 'image']);
    $this->headers = vectorizationHeadersFor(admin: $this->agent);
    /** Runs the queued job against a faked provider that must be called exactly once. */
    $this->work = function (string $id): void {
        $client = Mockery::mock(Vectorizer::class);
        $client->shouldReceive('ready')->andReturn(true);
        $client->shouldReceive('convert')->once()->andReturn(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'pdf' => "%PDF-1.7\n%%EOF"]);
        app()->instance(Vectorizer::class, $client);
        (new VectorizeImage(id: $id))->handle();
        app()->forgetInstance(Vectorizer::class);
    };
});

/**
 * Bearer headers for $admin's own API key. forgetGuards(): Passport's guard caches the first user it resolves.
 *
 * @param  array<int, string>|null  $permissions
 * @return array<string, string>
 */
function vectorizationHeadersFor(Admin $admin, string $permissionType = 'all', ?array $permissions = null): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();
    $admin->forceFill(['password' => Hash::make('password')])->save();
    $client = (new ClientRepository)->createPasswordGrantClient('Client for testing vectorization', 'admins', confidential: true);
    $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();
    Apikey::factory()->create(['permission_type' => $permissionType, 'admin_id' => $admin->id, 'oauth_client_id' => $client->getKey(), 'permissions' => $permissions]);
    $credential = test()->postJson('/oauth/token', [
        'grant_type' => 'password', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
        'username'   => $admin->email, 'password' => 'password', 'scope' => '',
    ])->json('access_token');

    return ['Authorization' => 'Bearer '.$credential, 'Accept' => 'application/json'];
}

it('files the chosen format onto the asset for an unlimited agent without touching its allowance', function () {
    $response = $this->postJson('/api/v1/rest/assets/'.$this->source->id.'/vectorize', ['format' => 'svg'], $this->headers)
        ->assertAccepted()
        ->assertJsonPath('data.vectorization.status', 'queued')
        ->assertJsonPath('data.vectorization.kind', 'asset')
        ->assertJsonPath('data.vectorization.format', 'svg')
        ->assertJsonPath('data.vectorization.source_asset_id', $this->source->id)
        ->assertJsonPath('data.vectorization.charged', false)
        ->assertJsonPath('data.vectorization.downloads', null);
    $id = $response->json('data.vectorization.id');
    expect(DB::table('dam_vectorization_allowances')->where('admin_id', $this->agent->id)->value('remaining'))->toBe(0);

    ($this->work)($id);

    $output = $this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    $this->getJson('/api/v1/rest/vectorizations/'.$id, $this->headers)->assertOk()
        ->assertJsonPath('data.vectorization.status', 'completed')
        ->assertJsonPath('data.vectorization.output', ['asset_id' => $this->source->id, 'file_id' => $output->id])
        ->assertJsonPath('data.vectorization.formats', ['svg', 'pdf']);
    $svg = $this->get('/api/v1/rest/vectorizations/'.$id.'/svg', $this->headers)->assertOk();
    expect($svg->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($svg->headers->get('Cache-Control'))->toContain('private')
        ->and(file_get_contents($svg->baseResponse->getFile()->getPathname()))->toContain('<svg');

    // Stored results stay readable while the provider is not connected; only new submissions need it.
    config(['vectorization.enabled' => false]);
    $this->getJson('/api/v1/rest/vectorizations/'.$id, $this->headers)->assertOk()->assertJsonPath('data.vectorization.status', 'completed');
    $this->get('/api/v1/rest/vectorizations/'.$id.'/pdf', $this->headers)->assertOk();
    $this->postJson('/api/v1/rest/assets/'.$this->source->id.'/vectorize', ['format' => 'svg'], $this->headers)->assertStatus(503);
});

it('runs a one-off upload with replay and both formats, filing nothing', function () {
    $upload = fn (int $size = 24) => UploadedFile::fake()->image('logo.png', $size, $size);
    $requestId = (string) Str::uuid();
    $file = $upload();
    $first = $this->post('/api/v1/rest/vectorizations', ['file' => $file, 'format' => 'pdf', 'request_id' => $requestId], $this->headers)
        ->assertAccepted()->assertJsonPath('data.vectorization.kind', 'one_off')->json('data.vectorization.id');
    $this->post('/api/v1/rest/vectorizations', ['file' => $file, 'request_id' => $requestId], $this->headers)
        ->assertAccepted()->assertJsonPath('data.vectorization.id', $first);
    $this->post('/api/v1/rest/vectorizations', ['file' => $upload(32), 'request_id' => $requestId], $this->headers)->assertConflict();
    Queue::assertPushed(VectorizeImage::class, 1);

    ($this->work)($first);

    $this->getJson('/api/v1/rest/vectorizations/'.$first, $this->headers)->assertOk()
        ->assertJsonPath('data.vectorization.status', 'completed')
        ->assertJsonPath('data.vectorization.output', null)
        ->assertJsonPath('data.vectorization.downloads.pdf', route('admin.api.dam.vectorizations.file', ['vectorization' => $first, 'format' => 'pdf']));
    $pdf = $this->get('/api/v1/rest/vectorizations/'.$first.'/pdf', $this->headers)->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(AssetFile::query()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('keeps the allowance for accounts that are not unlimited', function () {
    $limited = Admin::factory()->create();
    $headers = vectorizationHeadersFor(admin: $limited);
    $this->post('/api/v1/rest/vectorizations', ['file' => UploadedFile::fake()->image('a.png', 8, 8)], $headers)
        ->assertForbidden();
    expect(DB::table('dam_vectorizations')->where('admin_id', $limited->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses without a bearer, without the scope, and for another account\'s conversion', function () {
    $id = $this->postJson('/api/v1/rest/assets/'.$this->source->id.'/vectorize', ['format' => 'pdf'], $this->headers)
        ->assertAccepted()->json('data.vectorization.id');
    ($this->work)($id);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/rest/assets/'.$this->source->id.'/vectorize', ['format' => 'svg'], ['Accept' => 'application/json'])->assertUnauthorized();
    $this->getJson('/api/v1/rest/vectorizations/'.$id, ['Accept' => 'application/json'])->assertUnauthorized();

    $unscoped = vectorizationHeadersFor(admin: Admin::factory()->create(), permissionType: 'custom', permissions: ['api.dam.assets']);
    $this->postJson('/api/v1/rest/assets/'.$this->source->id.'/vectorize', ['format' => 'svg'], $unscoped)->assertForbidden();
    $this->getJson('/api/v1/rest/vectorizations/'.$id, $unscoped)->assertForbidden();

    $stranger = vectorizationHeadersFor(admin: $this->owner, permissionType: 'custom', permissions: ['api.dam.vectorizations.show']);
    $this->getJson('/api/v1/rest/vectorizations/'.$id, $stranger)->assertNotFound();
    $this->get('/api/v1/rest/vectorizations/'.$id.'/pdf', $stranger)->assertNotFound();
    Queue::assertPushed(VectorizeImage::class, 1);

    // Taken off the paid tool (a custom role leaves it out): its own results are no longer readable either.
    $this->agent->forceFill(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => []])->id])->save();
    $revoked = vectorizationHeadersFor(admin: $this->agent->fresh(), permissionType: 'custom', permissions: ['api.dam.vectorizations.show']);
    $this->getJson('/api/v1/rest/vectorizations/'.$id, $revoked)->assertForbidden();
    $this->get('/api/v1/rest/vectorizations/'.$id.'/svg', $revoked)->assertForbidden();
    $this->get('/api/v1/rest/vectorizations/'.$id.'/pdf', $revoked)->assertForbidden();
});

it('validates the format and the image, and answers 503 while the provider is not connected', function () {
    $url = '/api/v1/rest/assets/'.$this->source->id.'/vectorize';
    $this->postJson($url, ['format' => 'png'], $this->headers)->assertUnprocessable();
    $this->post('/api/v1/rest/vectorizations', ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')], $this->headers)->assertUnprocessable();
    $this->source->update(['extension' => 'svg']);
    $this->postJson($url, ['format' => 'svg'], $this->headers)->assertUnprocessable();
    config(['vectorization.enabled' => false]);
    $this->postJson($url, ['format' => 'svg'], $this->headers)->assertStatus(503);
    Queue::assertNothingPushed();
});
