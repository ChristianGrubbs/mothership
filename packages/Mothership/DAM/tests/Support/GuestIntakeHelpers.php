<?php

/*
 * P5a (D1): fixtures shared by the guest upload request tests. A guest has no session: every portal call here sends
 * exactly the visit cookie it is given (encrypted as the browser holds it), or none.
 */

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\UploadRequests\PortalGate;
use Mothership\DAM\Services\UploadRequests\UploadRequestService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

if (! function_exists('p5aPassword')) {
    /** A link password generated once per run, so no fixture value is written down. */
    function p5aPassword(): string
    {
        static $generated = null;

        return $generated ??= 'pw-'.bin2hex(random_bytes(8));
    }

    /**
     * A request on `$folder` by `$creator` (a full admin by default), through the service the staff routes use.
     *
     * @return array{0: UploadRequest, 1: string}
     */
    function p5aRequest(?Directory $folder = null, ?Admin $creator = null, array $options = []): array
    {
        $folder ??= Directory::create(['name' => 'Intake '.bin2hex(random_bytes(4)), 'parent_id' => null]);
        $creator ??= p5aFullAdmin();

        return app(UploadRequestService::class)->create(actor: $creator, directory: $folder, data: ['title' => 'Event photos'] + $options);
    }

    /** A full admin as the app makes one: a role whose permission type is `all`. */
    function p5aFullAdmin(): Admin
    {
        return Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    }

    /** The cookie value as a browser would send it back: encrypted with its name prefix, as EncryptCookies set it. */
    function p5aBrowserCookie(string $value): string
    {
        return encrypt(CookieValuePrefix::create(PortalGate::COOKIE, app('encrypter')->getKey()).$value, false);
    }

    /** Opens the portal page with no cookie and returns the visit cookie it issued (plain value). */
    function p5aVisit(string $token): string
    {
        $response = test()->call('GET', route('dam.upload_portal.show', ['token' => $token]));
        $response->assertOk();

        return $response->getCookie(PortalGate::COOKIE)->getValue();
    }

    /** One portal call with exactly this visit cookie (or none). */
    function p5aCall(string $method, string $url, ?string $visit, array $json = [], array $server = [], ?string $content = null): TestResponse
    {
        $cookies = $visit === null ? [] : [PortalGate::COOKIE => p5aBrowserCookie($visit)];
        $server += ['HTTP_ACCEPT' => 'application/json'];

        if ($content === null && $json !== []) {
            $server += ['CONTENT_TYPE' => 'application/json'];
            $content = json_encode($json);
        }

        return test()->call($method, $url, [], $cookies, [], $server, $content);
    }

    /** The metadata the portal's uploader sends at chunk init for `$contents`. */
    function p5aFile(string $name, string $contents, ?string $mime = 'text/plain', array $extra = []): array
    {
        return [
            'original_name' => $name,
            'mime_type'     => $mime,
            'size'          => strlen($contents),
            'last_modified' => 1_788_864_000_000,
            'fingerprint'   => hash('sha256', $name.'|'.$contents),
        ] + $extra;
    }

    function p5aInit(string $token, string $visit, array $file): TestResponse
    {
        return p5aCall('POST', route('dam.upload_portal.initialize', ['token' => $token]), $visit, $file);
    }

    /** Every chunk of `$contents`, as the portal's uploader PUTs them. */
    function p5aSendChunks(string $token, string $visit, string $uuid, string $contents, int $chunkBytes): void
    {
        foreach (str_split($contents, $chunkBytes) as $index => $chunk) {
            $start = $index * $chunkBytes;
            p5aCall('PUT', route('dam.upload_portal.store', ['token' => $token, 'uuid' => $uuid, 'index' => $index]), $visit, server: [
                'CONTENT_TYPE'        => 'application/octet-stream',
                'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, strlen($contents)),
                'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
            ], content: $chunk)->assertOk();
        }
    }

    /** Init, chunks, complete and the finalizer job; returns the finished (or failed) upload row. */
    function p5aUpload(string $token, string $visit, string $name, string $contents, ?string $mime = 'text/plain'): ChunkUpload
    {
        $init = p5aInit($token, $visit, p5aFile($name, $contents, $mime));
        expect($init->getStatusCode())->toBeIn([200, 201], (string) $init->getContent());
        $uuid = $init->json('upload.uuid');
        $upload = ChunkUpload::query()->where('uuid', $uuid)->firstOrFail();

        p5aSendChunks($token, $visit, $uuid, $contents, (int) $upload->chunk_bytes);
        p5aCall('POST', route('dam.upload_portal.complete', ['token' => $token, 'uuid' => $uuid]), $visit)->assertStatus(202);

        try {
            (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));
        } catch (Throwable) {
            // A refusal at finalize marks the upload failed; the caller reads the row.
        }

        return $upload->fresh();
    }

    /** Bearer headers for `$admin` with an all-permissions API key (the S5a pattern), its password made at runtime. */
    function p5aApiHeaders(Admin $admin): array
    {
        test()->withoutMiddleware(ThrottleRequests::class);
        app('auth')->forgetGuards();
        $password = 'pw-'.bin2hex(random_bytes(8));
        $admin->forceFill(['password' => Hash::make($password)])->save();

        $client = (new ClientRepository)->createPasswordGrantClient('P5a upload request API client', 'admins', confidential: true);
        $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();

        Apikey::factory()->create([
            'permission_type' => 'all',
            'admin_id'        => $admin->id,
            'oauth_client_id' => $client->getKey(),
            'permissions'     => null,
        ]);

        $bearer = test()->postJson('/oauth/token', [
            'grant_type'    => 'password',
            'client_id'     => $client->id,
            'client_secret' => $client->plainSecret,
            'username'      => $admin->email,
            'password'      => $password,
            'scope'         => '',
        ])->json('access_token');

        return ['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json'];
    }

    /** Switches the access model for this test (ADR 0010), as AssetHiddenEqualsMissingTest does. */
    function p5aAccessModel(string $model): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
        app(AccessModel::class)->flush();
        app(DirectoryPermissionService::class)->flush();
    }
}
