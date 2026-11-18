<?php

/**
 * P4 D2/D23: helpers the collection spec files share. Each of those files require_once()s this one, so any of them
 * runs alone.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

if (! function_exists('p4cUseModel')) {
    /** The ACL keys a collection's owner holds in these specs (no `dam.asset.share` unless a test adds it). */
    define('P4C_OWNER_KEYS', ['dam', 'dam.asset', 'dam.asset.view', 'dam.collections', 'dam.collections.create', 'dam.collections.update', 'dam.collections.delete']);

    function p4cUseModel(string $model): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
        app(AccessModel::class)->flush();
        app(DirectoryPermissionService::class)->flush();
    }

    /** A signed-in-able custom-role admin holding exactly $permissions. */
    function p4cMember(array $permissions = ['dam', 'dam.asset', 'dam.asset.view']): Admin
    {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

        return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    }

    function p4cFullAdmin(): Admin
    {
        $role = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);

        return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    }

    /** An image owned by $owner whose original is readable (ADR 0009), private to its owner under lists. */
    function p4cImage(Admin $owner, array $attributes = [], string $name = 'p4c'): Asset
    {
        $fileName = $name.'-'.bin2hex(random_bytes(4)).'.png';
        $asset = Asset::factory()->create($attributes + [
            'file_name'  => $fileName,
            'path'       => 'assets/Root/'.$fileName,
            'extension'  => 'png',
            'file_type'  => 'image',
            'mime_type'  => 'image/png',
            'created_by' => $owner->id,
        ]);
        $image = UploadedFile::fake()->image('x.png', 4, 4);
        Storage::disk(Directory::getAssetDisk())->put($asset->path, file_get_contents($image->getRealPath()));
        $asset->markGraphContentChanged()->save();

        return $asset->refresh();
    }

    /** A collection made through the service, as its owner would make it. */
    function p4cCollection(Admin $owner, array $data = []): AssetCollection
    {
        return app(CollectionService::class)->create(actor: $owner, data: $data + ['name' => 'P4c collection '.bin2hex(random_bytes(3))]);
    }

    function p4cAdd(AssetCollection $collection, Admin $actor, array $assets): void
    {
        app(CollectionService::class)->addMembers(collection: $collection, actor: $actor, assetIds: array_map(fn ($a) => $a instanceof Asset ? $a->id : (int) $a, $assets));
    }

    /** The body of a local response, whatever response class carried it. */
    function p4cBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        return match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse   => (string) $response->streamedContent(),
            default                             => (string) $base->getContent(),
        };
    }

    /** @return list<string> the entry names of a zip body */
    function p4cZipEntries(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'p4c-zip-');
        file_put_contents($path, $body);
        $zip = new ZipArchive;
        $entries = [];

        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = (string) $zip->getNameIndex($i);
            }

            $zip->close();
        }

        @unlink($path);
        sort($entries);

        return $entries;
    }

    /**
     * Bearer headers for $admin over Passport's password grant (as ShareApiMutationAuthorizationTest does), with a
     * password generated for this run.
     */
    function p4cApiHeaders(Admin $admin): array
    {
        test()->withoutMiddleware(ThrottleRequests::class);
        app('auth')->forgetGuards();

        $secret = 'p4c-'.bin2hex(random_bytes(8));
        $admin->forceFill(['password' => Hash::make($secret)])->save();

        $client = (new ClientRepository)->createPasswordGrantClient('P4c collections API', 'admins', confidential: true);
        $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();

        Apikey::factory()->create([
            'permission_type' => 'all',
            'admin_id'        => $admin->id,
            'oauth_client_id' => $client->getKey(),
            'permissions'     => null,
        ]);

        $token = test()->postJson('/oauth/token', [
            'grant_type'    => 'password',
            'client_id'     => $client->id,
            'client_secret' => $client->plainSecret,
            'username'      => $admin->email,
            'password'      => $secret,
            'scope'         => '',
        ])->json('access_token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** One anonymous GET with exactly the unlock cookie given (or none), as a browser sends it on a link's own paths. */
    function p4cGet(string $url, ?string $cookie = null)
    {
        app('auth')->forgetGuards();

        return test()->call('GET', $url, [], $cookie === null ? [] : [ShareGate::COOKIE => $cookie]);
    }

    /** Posts a link's password prompt; returns [response, the unlock cookie's value or null]. */
    function p4cUnlock(Share $share, string $password): array
    {
        $response = test()->post(route('dam.share.unlock', ['token' => $share->urlToken()]), ['password' => $password]);

        return [$response, $response->getCookie(ShareGate::COOKIE, decrypt: false)?->getValue()];
    }
}
