<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\ShareLog;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * P2 S5b: per-link password, download-off (preview only) and the creator-access option.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
});

/** The link password used throughout: generated once per run, so no fixture value is written down. */
function s5bPassword(): string
{
    static $generated = null;

    return $generated ??= 'pw-'.bin2hex(random_bytes(8));
}

/** An image whose original is readable, as an upload's would be (ADR 0009). */
function s5bImage(array $attributes = []): Asset
{
    $image = UploadedFile::fake()->image('x.png', 4, 4);
    $bytes = file_get_contents($image->getRealPath());
    // The recorded size is the bytes' own, so the stage's choice of URL (SharePlayback::stageUrl()) never varies by run.
    $asset = Asset::factory()->create($attributes + ['extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'file_size' => strlen($bytes)]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, $bytes);
    $asset->markGraphContentChanged()->save();

    return $asset->refresh();
}

function s5bFolderWith(Asset $asset): Directory
{
    $folder = Directory::create(['name' => 'S5b folder '.Str::random(6), 'parent_id' => null]);
    $asset->directories()->attach($folder->id);

    return $folder;
}

function s5bProtected(Share $share, ?string $password = null): Share
{
    $share->forceFill(['password_hash' => Hash::make($password ?? s5bPassword())])->save();

    return $share->fresh();
}

/** One GET with exactly the unlock cookie given (or none), as a browser sends it on that link's own paths. */
function s5bGet(string $url, ?string $cookie = null, array $headers = [])
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('GET', $url, [], $cookie === null ? [] : [ShareGate::COOKIE => $cookie], [], $server);
}

/** Posts the prompt; returns [response, the unlock cookie's value or null]. */
function s5bUnlock(Share $share, string $password, ?int $assetId = null): array
{
    $response = test()->post(route('dam.share.unlock', ['token' => $share->urlToken()]), array_filter([
        'password' => $password,
        'asset_id' => $assetId,
    ]));

    return [$response, $response->getCookie(ShareGate::COOKIE, decrypt: false)?->getValue()];
}

/** The share-scoped routes of an asset link and of a folder link to the same asset, by name. */
function s5bRoutes(Share $assetLink, Share $folderLink, Asset $asset): array
{
    $a = $assetLink->urlToken();
    $f = $folderLink->urlToken();

    return [
        'asset page'          => route('dam.share.show', $a),
        'asset preview'       => route('dam.share.preview', $a),
        'asset display'       => route('dam.share.display', $a),
        'asset thumbnail'     => route('dam.share.thumbnail', ['token' => $a, 'assetId' => $asset->id]),
        'asset proxy'         => route('dam.share.proxy', $a),
        'asset download'      => route('dam.share.download', $a),
        'asset inline'        => route('dam.share.download', $a).'?disposition=inline',
        'asset web page'      => route('dam.share.page', ['token' => $a]).'/',
        'folder page'         => route('dam.share.show', $f),
        'folder list'         => route('dam.share.list_assets', $f),
        'folder zip'          => route('dam.share.download_zip', $f),
        'folder thumbnail'    => route('dam.share.thumbnail', ['token' => $f, 'assetId' => $asset->id]),
        'member page'         => route('dam.share.asset_view', ['token' => $f, 'assetId' => $asset->id]),
        'member preview'      => route('dam.share.asset_preview', ['token' => $f, 'assetId' => $asset->id]),
        'member display'      => route('dam.share.asset_display', ['token' => $f, 'assetId' => $asset->id]),
        'member download'     => route('dam.share.asset_download', ['token' => $f, 'assetId' => $asset->id]),
        'member proxy'        => route('dam.share.asset_proxy', ['token' => $f, 'assetId' => $asset->id]),
        'member web page'     => route('dam.share.page', ['token' => $f]).'/'.$asset->id.'/',
    ];
}

function s5bSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function s5bCustomAdmin(array $permissions = ['dam', 'dam.asset.view']): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function s5bGrantFolder(Admin $admin, Directory $folder): void
{
    DB::table('dam_directory_user')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id, 'inherit_children' => false]);
    app(DirectoryPermissionService::class)->flush();
}

function s5bRevokeFolder(Admin $admin, Directory $folder): void
{
    DB::table('dam_directory_user')->where('directory_id', $folder->id)->where('admin_id', $admin->id)->delete();
    app(DirectoryPermissionService::class)->flush();
}

/** Bearer headers for $admin, with an all-permissions API key (the S5a pattern). */
function s5bApiHeaders(Admin $admin): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();

    $admin->forceFill(['password' => Hash::make('password')])->save();

    $client = (new ClientRepository)->createPasswordGrantClient('S5b share API client', 'admins', confidential: true);
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
        'password'      => 'password',
        'scope'         => '',
    ])->json('access_token');

    return ['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json'];
}

/** The JSONL lines ShareLog wrote while $run ran. */
function s5bShareLogDuring(Closure $run): array
{
    $path = storage_path(ShareLog::PATH);
    $before = is_file($path) ? strlen((string) file_get_contents($path)) : 0;

    $run();

    $written = is_file($path) ? substr((string) file_get_contents($path), $before) : '';

    return array_values(array_filter(array_map(fn ($line) => json_decode($line, true), explode(PHP_EOL, $written))));
}

// --- Password -------------------------------------------------------------------------------------------------------

it('refuses every share-scoped route of a protected link without the unlock cookie, and opens each with it', function () {
    // Two requests per route outrun the per-address download budget; the gate, not the throttle, is under test here.
    test()->withoutMiddleware(ThrottleRequests::class);
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $assetLink = s5bProtected(Share::factory()->forAsset($asset->id)->create());
    $folderLink = s5bProtected(Share::factory()->forDirectory($folder->id)->create());

    [$unlockedAsset, $assetCookie] = s5bUnlock($assetLink, s5bPassword());
    [$unlockedFolder, $folderCookie] = s5bUnlock($folderLink, s5bPassword());
    $unlockedAsset->assertRedirect(route('dam.share.show', $assetLink->urlToken()));
    $unlockedFolder->assertRedirect(route('dam.share.show', $folderLink->urlToken()));
    expect($assetCookie)->toBeString()->and($folderCookie)->toBeString();

    foreach (s5bRoutes($assetLink, $folderLink, $asset) as $name => $url) {
        $locked = s5bGet($url);
        $cookie = str_starts_with($name, 'asset') ? $assetCookie : $folderCookie;
        $open = s5bGet($url, cookie: $cookie);

        // Landing pages ask for the password; every byte, list and frame route is a bare 401 that names nothing.
        if (in_array($name, ['asset page', 'folder page', 'member page'], true)) {
            $locked->assertStatus(401)->assertSee('data-dam-share-password', false)->assertDontSee($asset->file_name);
        } else {
            expect($locked->getStatusCode())->toBe(401, "{$name} locked")
                ->and($locked->getContent())->toBe('', "{$name} locked body");
        }

        // Unlocked, the route answers as an unprotected link's would: the image has no proxy and is no web page.
        $expected = match ($name) {
            'asset proxy', 'member proxy', 'asset web page', 'member web page' => 404,
            default                                                            => 200,
        };
        expect($open->getStatusCode())->toBe($expected, "{$name} unlocked");
    }

    // One link's cookie opens only that link.
    s5bGet(route('dam.share.preview', $assetLink->urlToken()), cookie: $folderCookie)->assertStatus(401);
});

it('answers a locked folder link the same for a file it holds and one it does not, without the cookie', function () {
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $link = s5bProtected(Share::factory()->forDirectory($folder->id)->create());

    foreach (['dam.share.asset_download', 'dam.share.asset_preview', 'dam.share.thumbnail', 'dam.share.asset_proxy'] as $route) {
        $held = s5bGet(route($route, ['token' => $link->urlToken(), 'assetId' => $asset->id]));
        $absent = s5bGet(route($route, ['token' => $link->urlToken(), 'assetId' => 999999]));

        expect([$held->getStatusCode(), $held->getContent()])->toBe([$absent->getStatusCode(), $absent->getContent()], $route)
            ->and($held->getStatusCode())->toBe(401, $route);
    }
});

it('sets the unlock cookie HttpOnly, SameSite=Lax, for this browser session and only on the link\'s own path', function () {
    $asset = s5bImage();
    $link = s5bProtected(Share::factory()->forAsset($asset->id)->create());

    [$response] = s5bUnlock($link, s5bPassword());
    $cookie = $response->getCookie(ShareGate::COOKIE, decrypt: false);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getExpiresTime())->toBe(0)
        ->and($cookie->getPath())->toBe('/share/'.$link->urlToken())
        ->and($cookie->getValue())->not->toContain(s5bPassword())
        ->and($cookie->getValue())->not->toContain($link->urlToken());
});

it('returns a folder member page\'s visitor to that page after the password', function () {
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $link = s5bProtected(Share::factory()->forDirectory($folder->id)->create());

    s5bGet(route('dam.share.asset_view', ['token' => $link->urlToken(), 'assetId' => $asset->id]))
        ->assertStatus(401)
        ->assertSee('name="asset_id" value="'.$asset->id.'"', false);

    [$response] = s5bUnlock($link, s5bPassword(), assetId: $asset->id);
    $response->assertRedirect(route('dam.share.asset_view', ['token' => $link->urlToken(), 'assetId' => $asset->id]));
});

it('refuses a wrong password, locks after five a minute even for the right one, and logs each by share id only', function () {
    $asset = s5bImage();
    $link = s5bProtected(Share::factory()->forAsset($asset->id)->create());

    $lines = s5bShareLogDuring(function () use ($link) {
        for ($i = 0; $i < ShareGate::ATTEMPTS_PER_VISITOR; $i++) {
            [$response, $cookie] = s5bUnlock($link, 'wrong-guess-'.$i);
            $response->assertStatus(401)->assertSee('data-dam-share-password-error="wrong"', false);
            expect($cookie)->toBeNull();
        }

        [$locked, $cookie] = s5bUnlock($link, s5bPassword());
        $locked->assertStatus(429)->assertSee('data-dam-share-password-error="locked"', false);
        expect($cookie)->toBeNull();
    });

    expect(array_column($lines, 'event'))->toBe([
        ...array_fill(0, ShareGate::ATTEMPTS_PER_VISITOR, 'share.password.failure'),
        'share.password.lockout',
    ]);

    foreach ($lines as $line) {
        expect($line['ctx'])->toBe(['share_id' => $link->id]);
    }

    $raw = json_encode($lines);
    expect($raw)->not->toContain(s5bPassword())->not->toContain('wrong-guess')->not->toContain($link->urlToken());

    // Another visitor is not locked out by this one's guesses; once the minute passes, the right password opens it.
    $this->travel(ShareGate::DECAY_SECONDS + 1)->seconds();
    $lines = s5bShareLogDuring(function () use ($link) {
        [$response, $cookie] = s5bUnlock($link, s5bPassword());
        $response->assertRedirect();
        expect($cookie)->toBeString();
    });
    expect(array_column($lines, 'event'))->toBe(['share.password.success']);
});

it('locks a link for every visitor after the per-link budget of wrong passwords', function () {
    $asset = s5bImage();
    $link = s5bProtected(Share::factory()->forAsset($asset->id)->create());

    for ($i = 0; $i < ShareGate::ATTEMPTS_PER_LINK; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.'.intdiv($i, 4).'.'.($i % 4 + 1)]);
        s5bUnlock($link, 'wrong-'.$i)[0]->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9']);
    s5bUnlock($link, s5bPassword())[0]->assertStatus(429);
});

it('stops opening with an old cookie once the password changes or the link is reissued', function () {
    $this->loginAsAdmin();
    $asset = s5bImage();
    $link = s5bProtected(Share::factory()->forAsset($asset->id)->create());
    $preview = fn (Share $share) => route('dam.share.preview', $share->urlToken());

    [, $cookie] = s5bUnlock($link, s5bPassword());
    s5bGet($preview($link), cookie: $cookie)->assertOk();

    // Changed through the dialog's own update route.
    $this->patchJson(route('admin.dam.shared-links.update', $link->id), ['name' => null, 'password' => 'a-new-password'])->assertOk();
    s5bGet($preview($link), cookie: $cookie)->assertStatus(401);

    [, $cookie] = s5bUnlock($link, 'a-new-password');
    s5bGet($preview($link), cookie: $cookie)->assertOk();

    // Reissued: the new URL, with the cookie the old one was unlocked by, is locked again.
    $reissued = app(ShareRepository::class)->reissue($link->fresh());
    s5bGet($preview($reissued), cookie: $cookie)->assertStatus(401);
    [, $fresh] = s5bUnlock($reissued, 'a-new-password');
    s5bGet($preview($reissued), cookie: $fresh)->assertOk();
});

it('opens a protected web page frame only with a valid page grant, never a forged or expired one', function () {
    $asset = s5bImage();
    $link = s5bProtected(Share::factory()->forAsset($asset->id)->create());
    $grant = app(ShareGate::class)->pageGrant($link);
    $url = fn (string $grant) => route('dam.share.page_granted', ['token' => $link->urlToken(), 'grant' => $grant]).'/';

    // Past the gate (an image is no web page, so the page itself is a 404), with no cookie at all.
    s5bGet($url($grant))->assertNotFound();
    s5bGet($url(substr($grant, 0, -1).(str_ends_with($grant, 'a') ? 'b' : 'a')))->assertStatus(401);
    s5bGet($url((time() - 1).'.'.str_repeat('0', 64)))->assertStatus(401);

    $this->travel(ShareGate::PAGE_GRANT_TTL + 1)->seconds();
    s5bGet($url($grant))->assertStatus(401);
});

it('never returns the password or its hash on the web, over REST or in the grid export, only has_password', function () {
    $admin = $this->loginAsAdmin();
    $asset = s5bImage(['created_by' => $admin->id]);

    $created = $this->postJson(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $asset->id,
        'public_link_confirmed' => true,
        'password'              => s5bPassword(),
    ])->assertOk()->assertJsonPath('share.has_password', true)->assertJsonPath('share.allow_download', true)->assertJsonPath('share.requires_creator_access', false);

    $share = Share::query()->findOrFail($created->json('share.id'));
    $hash = DB::table('dam_shares')->where('id', $share->id)->value('password_hash');
    expect(Hash::check(s5bPassword(), $hash))->toBeTrue();

    $bodies = [
        'web store'  => $created->getContent(),
        'web update' => $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => 'Renamed'])->assertOk()->assertJsonPath('share.has_password', true)->getContent(),
        'web dialog' => $this->getJson(route('admin.dam.shared-links.active_for_target', ['type' => 'asset', 'targetId' => $asset->id]))->assertOk()->assertJsonPath('shares.0.has_password', true)->getContent(),
        'web grid'   => $this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent(),
    ];

    $export = $this->get(route('admin.dam.shared-links.index', ['export' => 1, 'format' => 'csv']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $bodies['csv export'] = file_get_contents($export->baseResponse->getFile()->getPathname());

    $headers = s5bApiHeaders($admin);
    $bodies['rest show'] = $this->withHeaders($headers)->getJson(route('admin.api.dam.shares.show', $share->id))->assertOk()->assertJsonPath('data.has_password', true)->getContent();
    $bodies['rest index'] = $this->withHeaders($headers)->getJson(route('admin.api.dam.shares.index'))->assertOk()->assertJsonPath('data.0.has_password', true)->getContent();
    $bodies['rest store'] = $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), [
        'share_type' => Share::TYPE_ASSET, 'asset_id' => $asset->id, 'password' => s5bPassword(),
    ])->assertCreated()->assertJsonPath('data.has_password', true)->getContent();
    $bodies['rest update'] = $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $share->id), ['password' => 'changed-'.s5bPassword()])->assertOk()->getContent();

    foreach ($bodies as $where => $body) {
        expect($body)->not->toContain(s5bPassword(), $where)
            ->not->toContain((string) $hash, $where)
            ->not->toContain('password_hash', $where);
    }

    // No form of the password reaches the audit trail either.
    $audits = DB::table('audits')->where('auditable_type', (new Share)->getMorphClass())->where('auditable_id', $share->id)->get();
    foreach ($audits as $audit) {
        expect((string) $audit->old_values.(string) $audit->new_values)->not->toContain('password_hash')->not->toContain(s5bPassword());
    }
});

it('sets, keeps, changes and clears a password on the web and over REST, under the authority that edits the link', function () {
    $admin = $this->loginAsAdmin();
    $asset = s5bImage(['created_by' => $admin->id]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $admin->id]);
    $hash = fn () => DB::table('dam_shares')->where('id', $share->id)->value('password_hash');

    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => null, 'password' => 'abc'])->assertUnprocessable();
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => null, 'password' => 'abcd', 'remove_password' => true])->assertUnprocessable();
    expect($hash())->toBeNull();

    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => null, 'password' => 'first-pass'])->assertOk()->assertJsonPath('share.has_password', true);
    $first = $hash();

    // A save that leaves the password out (or blank) keeps it.
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => 'x', 'password' => ''])->assertOk()->assertJsonPath('share.has_password', true);
    expect($hash())->toBe($first);

    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => null, 'remove_password' => true])->assertOk()->assertJsonPath('share.has_password', false);
    expect($hash())->toBeNull();

    // REST: the same fields.
    $headers = s5bApiHeaders($admin);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $share->id), ['password' => 'rest-pass'])->assertOk()->assertJsonPath('data.has_password', true);
    expect(Hash::check('rest-pass', $hash()))->toBeTrue();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $share->id), ['remove_password' => true])->assertOk()->assertJsonPath('data.has_password', false);

    // Someone who may see the target but not edit the link (grants: no dam.shares.revoke) may not clear its password.
    s5bSetModel(AccessModel::GRANTS);
    $share->forceFill(['password_hash' => Hash::make('kept')])->save();
    $other = s5bCustomAdmin(['dam', 'dam.asset.view']);
    s5bGrantFolder($other, s5bFolderWith($asset));
    $this->actingAs($other, 'admin');
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => null, 'remove_password' => true])->assertForbidden();
    expect(Hash::check('kept', $hash()))->toBeTrue();
});

// --- Download off ---------------------------------------------------------------------------------------------------

it('answers 403 on a preview-only link\'s download, alternate and zip routes while preview and thumbnail still open', function () {
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $assetLink = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $folderLink = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $a = $assetLink->urlToken();
    $f = $folderLink->urlToken();

    foreach ([
        'download'        => route('dam.share.download', $a),
        'inline original' => route('dam.share.download', $a).'?disposition=inline',
        'alternate'       => route('dam.share.file_download', ['token' => $a, 'attachment' => 1]),
        'member download' => route('dam.share.asset_download', ['token' => $f, 'assetId' => $asset->id]),
        'member alt'      => route('dam.share.asset_file_download', ['token' => $f, 'assetId' => $asset->id, 'attachment' => 1]),
        'zip'             => route('dam.share.download_zip', $f),
    ] as $name => $url) {
        expect(s5bGet($url)->getStatusCode())->toBe(403, $name);
    }

    foreach ([
        'page'             => route('dam.share.show', $a),
        'preview'          => route('dam.share.preview', $a),
        'display'          => route('dam.share.display', $a),
        'thumbnail'        => route('dam.share.thumbnail', ['token' => $a, 'assetId' => $asset->id]),
        'folder page'      => route('dam.share.show', $f),
        'member page'      => route('dam.share.asset_view', ['token' => $f, 'assetId' => $asset->id]),
        'member preview'   => route('dam.share.asset_preview', ['token' => $f, 'assetId' => $asset->id]),
        'member display'   => route('dam.share.asset_display', ['token' => $f, 'assetId' => $asset->id]),
        'member thumbnail' => route('dam.share.thumbnail', ['token' => $f, 'assetId' => $asset->id]),
    ] as $name => $url) {
        expect(s5bGet($url)->getStatusCode())->toBe(200, $name);
    }

    expect($assetLink->fresh()->download_count)->toBe(0);
});

it('shows a preview-only link with no download or zip control at all', function () {
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $assetLink = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $folderLink = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $open = Share::factory()->forAsset($asset->id)->create();

    foreach ([
        s5bGet(route('dam.share.show', $assetLink->urlToken())),
        s5bGet(route('dam.share.asset_view', ['token' => $folderLink->urlToken(), 'assetId' => $asset->id])),
    ] as $page) {
        $page->assertOk()
            ->assertSee('data-dam-share-preview-only', false)
            ->assertDontSee('/download', false)
            ->assertDontSee('icon-dam-download', false)
            ->assertSee('/preview', false);
    }

    s5bGet(route('dam.share.show', $folderLink->urlToken()))->assertOk()
        ->assertSee('data-dam-share-preview-only', false)
        ->assertDontSee(route('dam.share.download_zip', $folderLink->urlToken()), false);

    // An ordinary link keeps its download button.
    s5bGet(route('dam.share.show', $open->urlToken()))->assertOk()
        ->assertSee('href="'.route('dam.share.download', $open->urlToken()).'"', false)
        ->assertDontSee('data-dam-share-preview-only', false);
});

it('answers a preview-only link\'s stacked files: zips and media originals 403, still derivatives and thumbnails 200', function () {
    $asset = s5bImage();
    $folder = s5bFolderWith($asset);
    $still = attachPreviewFile($asset, 'angle.png', 'image/png', AssetFile::ROLE_ALTERNATE, label: 'angle.png');
    $png = fakePngBytes(6, 6);
    Storage::disk($still->fileObject->disk)->put($still->fileObject->path, $png);
    DB::table('dam_file_objects')->where('id', $still->file_object_id)->update(['size' => strlen($png)]);
    $media = collect(['clip.mp4' => 'video/mp4', 'voice.mp3' => 'audio/mpeg', 'spec.pdf' => 'application/pdf'])
        ->map(fn (string $mime, string $name) => (int) attachPreviewFile($asset, $name, $mime, AssetFile::ROLE_SIDECAR, label: $name)->attachment_id);
    $assetLink = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $folderLink = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $a = $assetLink->urlToken();
    $f = $folderLink->urlToken();
    $slot = ['attachment' => (int) $still->attachment_id];
    $member = ['token' => $f, 'assetId' => $asset->id];

    $forbidden = [
        'files zip'             => route('dam.share.files_zip', $a),
        'member files zip'      => route('dam.share.asset_files_zip', $member),
        'still download'        => route('dam.share.file_download', ['token' => $a] + $slot),
        'member still download' => route('dam.share.asset_file_download', $member + $slot),
    ];
    foreach ($media as $name => $attachment) {
        $forbidden[$name.' preview'] = route('dam.share.file_preview', ['token' => $a, 'attachment' => $attachment]);
        $forbidden['member '.$name.' preview'] = route('dam.share.asset_file_preview', $member + ['attachment' => $attachment]);
    }
    foreach ($forbidden as $name => $url) {
        expect(s5bGet($url)->getStatusCode())->toBe(403, $name);
    }

    foreach ([
        'still thumbnail'        => route('dam.share.file_thumbnail', ['token' => $a] + $slot),
        'member still thumbnail' => route('dam.share.asset_file_thumbnail', $member + $slot),
        'still preview'          => route('dam.share.file_preview', ['token' => $a] + $slot),
        'member still preview'   => route('dam.share.asset_file_preview', $member + $slot),
        'main picture'           => route('dam.share.preview', $a),
    ] as $name => $url) {
        expect(s5bGet($url)->getStatusCode())->toBe(200, $name);
    }

    expect($assetLink->fresh()->download_count)->toBe(0)
        ->and($folderLink->fresh()->download_count)->toBe(0);
});

it('refuses a preview-only link\'s original that a browser would save rather than show, and still serves it on an open link', function () {
    $asset = Asset::factory()->create(['file_name' => 'chair.glb', 'extension' => 'glb', 'file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'path' => 'assets/S5b/chair-'.Str::random(8).'.glb']);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, "glTF\x02\x00\x00\x00");
    $asset->markGraphContentChanged()->save();
    $folder = s5bFolderWith($asset);
    $closedAsset = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $closedFolder = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $open = Share::factory()->forAsset($asset->id)->create();

    expect(s5bGet(route('dam.share.preview', $closedAsset->urlToken()))->getStatusCode())->toBe(403)
        ->and(s5bGet(route('dam.share.asset_preview', ['token' => $closedFolder->urlToken(), 'assetId' => $asset->id]))->getStatusCode())->toBe(403)
        ->and(s5bGet(route('dam.share.preview', $open->urlToken()))->getStatusCode())->toBe(200)
        ->and($closedAsset->fresh()->download_count)->toBe(0);
});

it('PD10: still plays a preview-only link\'s main MP4, MP3 and PDF inline, while the same types as related files answer 403', function () {
    foreach (['clip.mp4' => ['video', 'video/mp4'], 'voice.mp3' => ['audio', 'audio/mpeg'], 'spec.pdf' => ['document', 'application/pdf']] as $name => [$type, $mime]) {
        $asset = Asset::factory()->create(['file_name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION), 'file_type' => $type, 'mime_type' => $mime, 'path' => 'assets/S5b/'.Str::random(8).'-'.$name]);
        Storage::disk(Directory::getAssetDisk())->put($asset->path, 'main-'.$name);
        $asset->markGraphContentChanged()->save();
        $related = attachPreviewFile($asset, 'extra-'.$name, $mime, AssetFile::ROLE_SIDECAR, label: 'extra-'.$name);
        $folder = s5bFolderWith($asset);
        $assetLink = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
        $folderLink = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
        $member = ['token' => $folderLink->urlToken(), 'assetId' => $asset->id];

        foreach ([route('dam.share.preview', $assetLink->urlToken()), route('dam.share.asset_preview', $member)] as $url) {
            $response = s5bGet($url);
            expect($response->getStatusCode())->toBe(200, $name)
                ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment');
        }

        foreach ([
            route('dam.share.file_preview', ['token' => $assetLink->urlToken(), 'attachment' => (int) $related->attachment_id]),
            route('dam.share.asset_file_preview', $member + ['attachment' => (int) $related->attachment_id]),
            route('dam.share.download', $assetLink->urlToken()),
        ] as $url) {
            expect(s5bGet($url)->getStatusCode())->toBe(403, $name);
        }

        expect($assetLink->fresh()->download_count)->toBe(0)
            ->and($folderLink->fresh()->download_count)->toBe(0);
    }
});

it('serves an audio asset\'s own cover art on asset and folder links, and never a cover meta_data points at another asset', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $make = function (string $name, string $bytes): Asset {
        $asset = Asset::factory()->create(['file_name' => $name, 'extension' => 'mp3', 'file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'path' => 'assets/S5b/'.Str::random(8).'-'.$name]);
        Storage::disk(Directory::getAssetDisk())->put($asset->path, $bytes);
        $asset->markGraphContentChanged()->save();

        return $asset;
    };
    $song = $make('song.mp3', 'ID3-song-bytes');
    $other = $make('other.mp3', 'ID3-other-bytes');
    $own = fakePngBytes(5, 5);
    $secret = fakePngBytes(7, 7);
    $ownPath = CoverArt::path((int) $song->id, $own, 'png');
    $secretPath = CoverArt::path((int) $other->id, $secret, 'png');
    $disk->put($ownPath, $own);
    $disk->put($secretPath, $secret);
    $folder = s5bFolderWith($song);
    $assetLink = Share::factory()->forAsset($song->id)->create();
    $folderLink = Share::factory()->forDirectory($folder->id)->create();
    $urls = [
        route('dam.share.thumbnail', ['token' => $assetLink->urlToken(), 'assetId' => $song->id]),
        route('dam.share.thumbnail', ['token' => $folderLink->urlToken(), 'assetId' => $song->id]),
    ];

    $song->update(['meta_data' => ['cover_art_path' => $ownPath]]);
    foreach ($urls as $url) {
        expect(s5bGet($url)->assertOk()->streamedContent())->toBe($own);
    }

    // Another asset's cover (different bytes) and a path outside covers/ are never served.
    foreach ([$secretPath, $other->path] as $tampered) {
        $song->update(['meta_data' => ['cover_art_path' => $tampered]]);
        foreach ($urls as $url) {
            $response = s5bGet($url);
            $body = $response->baseResponse instanceof StreamedResponse || $response->baseResponse instanceof BinaryFileResponse ? $response->streamedContent() : (string) $response->getContent();
            expect($body)->not->toBe($secret)->and($body)->not->toBe('ID3-other-bytes');
        }
    }
});

it('sets download-off and creator access from the web dialog and REST, downloads on and creator access off by default', function () {
    $admin = $this->loginAsAdmin();
    $asset = s5bImage(['created_by' => $admin->id]);

    $web = $this->postJson(route('admin.dam.shared-links.store'), [
        'share_type' => Share::TYPE_ASSET, 'target_id' => $asset->id, 'public_link_confirmed' => true,
    ])->assertOk()
        ->assertJsonPath('share.allow_download', true)
        ->assertJsonPath('share.requires_creator_access', false)
        ->assertJsonPath('share.has_password', false);

    $this->patchJson(route('admin.dam.shared-links.update', $web->json('share.id')), ['name' => null, 'allow_download' => false, 'requires_creator_access' => true])
        ->assertOk()
        ->assertJsonPath('share.allow_download', false)
        ->assertJsonPath('share.requires_creator_access', true);

    $headers = s5bApiHeaders($admin);
    $rest = $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), [
        'share_type' => Share::TYPE_ASSET, 'asset_id' => $asset->id, 'allow_download' => false, 'requires_creator_access' => true,
    ])->assertCreated()
        ->assertJsonPath('data.allow_download', false)
        ->assertJsonPath('data.requires_creator_access', true)
        ->assertJsonPath('data.has_password', false);

    $id = $rest->json('data.id');
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $id), ['allow_download' => true])
        ->assertOk()
        ->assertJsonPath('data.allow_download', true)
        ->assertJsonPath('data.requires_creator_access', true);

    $listed = collect($this->withHeaders($headers)->getJson(route('admin.api.dam.shares.index'))->assertOk()->json('data'))->keyBy('id');
    expect($listed[$id])->toMatchArray(['allow_download' => true, 'requires_creator_access' => true, 'has_password' => false]);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), [
        'share_type' => Share::TYPE_ASSET, 'asset_id' => $asset->id, 'allow_download' => 'sometimes',
    ])->assertUnprocessable();
});

// --- Creator access -------------------------------------------------------------------------------------------------

/** Under grants: a member who sees $asset only through a granted folder, and a link they made to it. */
function s5bMemberLink(bool $requiresCreatorAccess, string $type = Share::TYPE_ASSET): array
{
    s5bSetModel(AccessModel::GRANTS);
    $owner = Admin::factory()->create();
    $asset = s5bImage(['created_by' => $owner->id]);
    $folder = s5bFolderWith($asset);
    $member = s5bCustomAdmin();
    s5bGrantFolder($member, $folder);

    $factory = $type === Share::TYPE_ASSET ? Share::factory()->forAsset($asset->id) : Share::factory()->forDirectory($folder->id);
    $share = $factory->create(['created_by' => $member->id, 'requires_creator_access' => $requiresCreatorAccess]);

    return [$share, $member, $asset, $folder];
}

it('keeps a link working when its creator loses access, with the option off (the default)', function () {
    [$share, $member, $asset, $folder] = s5bMemberLink(requiresCreatorAccess: false);
    s5bRevokeFolder($member, $folder);

    s5bGet(route('dam.share.show', $share->urlToken()))->assertOk();
    s5bGet(route('dam.share.preview', $share->urlToken()))->assertOk();
    expect(Share::query()->findOrFail($share->id)->requires_creator_access)->toBeFalse();
});

it('answers a link exactly like a revoked one once its creator loses access, with the option on, and opens again on restore', function () {
    [$share, $member, $asset, $folder] = s5bMemberLink(requiresCreatorAccess: true);
    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create();
    $routes = fn (Share $s) => [
        'page'      => route('dam.share.show', $s->urlToken()),
        'preview'   => route('dam.share.preview', $s->urlToken()),
        'display'   => route('dam.share.display', $s->urlToken()),
        'download'  => route('dam.share.download', $s->urlToken()),
        'thumbnail' => route('dam.share.thumbnail', ['token' => $s->urlToken(), 'assetId' => $asset->id]),
        'proxy'     => route('dam.share.proxy', $s->urlToken()),
        'web page'  => route('dam.share.page', ['token' => $s->urlToken()]).'/',
    ];

    s5bGet(route('dam.share.show', $share->urlToken()))->assertOk();

    s5bRevokeFolder($member, $folder);

    $mine = $routes($share);
    foreach ($routes($revoked) as $name => $url) {
        $expected = s5bGet($url);
        $actual = s5bGet($mine[$name]);

        expect($actual->getStatusCode())->toBe($expected->getStatusCode(), $name);
    }
    s5bGet($mine['page'])->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));

    // Nothing was written: the link itself is still active, and opens again once access is back.
    expect($share->fresh()->revoked_at)->toBeNull();
    s5bGrantFolder($member, $folder);
    s5bGet($mine['page'])->assertOk();
    s5bGet($mine['preview'])->assertOk();
});

it('closes a creator-access link whose creator is deleted or disabled', function () {
    [$share, $member] = s5bMemberLink(requiresCreatorAccess: true);
    s5bGet(route('dam.share.show', $share->urlToken()))->assertOk();

    $member->forceFill(['status' => 0])->save();
    s5bGet(route('dam.share.show', $share->urlToken()))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));
    s5bGet(route('dam.share.preview', $share->urlToken()))->assertStatus(410);

    $member->forceFill(['status' => 1])->save();
    s5bGet(route('dam.share.show', $share->urlToken()))->assertOk();

    $member->delete();
    s5bGet(route('dam.share.show', $share->urlToken()))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));
});

it('closes a creator-access folder link when its creator loses the folder, and keeps the decided status answers first', function () {
    [$share, $member, $asset, $folder] = s5bMemberLink(requiresCreatorAccess: true, type: Share::TYPE_DIRECTORY);
    s5bGet(route('dam.share.show', $share->urlToken()))->assertOk();
    s5bGet(route('dam.share.asset_preview', ['token' => $share->urlToken(), 'assetId' => $asset->id]))->assertOk();

    s5bRevokeFolder($member, $folder);
    s5bGet(route('dam.share.show', $share->urlToken()))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));
    s5bGet(route('dam.share.asset_preview', ['token' => $share->urlToken(), 'assetId' => $asset->id]))->assertStatus(410);
    s5bGet(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]))->assertNotFound();
    s5bGet(route('dam.share.list_assets', $share->urlToken()))->assertNotFound();

    // An asset link to a draft still answers the draft's 404, whatever its creator can see (decided status rules).
    [$draftLink, $draftMember, $draft, $draftFolder] = s5bMemberLink(requiresCreatorAccess: true);
    s5bRevokeFolder($draftMember, $draftFolder);
    $draft->forceFill(['availability' => Asset::AVAILABILITY_DRAFT])->save();
    s5bGet(route('dam.share.show', $draftLink->urlToken()))->assertNotFound();
    DB::table('dam_assets')->where('id', $draft->id)->update(['availability' => Asset::AVAILABILITY_AVAILABLE, 'deleted_at' => now()]);
    s5bGet(route('dam.share.show', $draftLink->urlToken()))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.expired-title'));
});

it('shows a preview-only link\'s main DNG stored under a generic MIME as its JPEG preview, cold and warm, on asset and folder links', function () {
    $dng = minimalDngWithEmbeddedJpeg();
    $disk = Storage::disk(Directory::getAssetDisk());
    $asset = Asset::factory()->create(['file_name' => 'photo.dng', 'extension' => 'dng', 'file_type' => 'image', 'mime_type' => 'application/octet-stream', 'path' => 'assets/S5b/'.Str::random(8).'-photo.dng']);
    $disk->put($asset->path, $dng);
    $asset->markGraphContentChanged()->save();
    $object = app(ContentStore::class)->current($asset->fresh());
    DB::table('dam_file_objects')->where('id', $object->id)->update(['mime_type' => 'application/octet-stream']);
    $folder = s5bFolderWith($asset);
    $assetLink = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $folderLink = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);
    $preview = DerivativeImages::dngPreview((string) $object->sha256);

    foreach ([
        'asset link'  => route('dam.share.preview', $assetLink->urlToken()),
        'folder link' => route('dam.share.asset_preview', ['token' => $folderLink->urlToken(), 'assetId' => $asset->id]),
    ] as $link => $url) {
        DerivativeImages::purgeContent($disk, (string) $object->sha256);

        foreach (['cold', 'warm'] as $cache) {
            expect($disk->exists($preview))->toBe($cache === 'warm', "{$link} {$cache} cache");
            $response = s5bGet($url);
            $body = $response->baseResponse instanceof StreamedResponse || $response->baseResponse instanceof BinaryFileResponse ? $response->streamedContent() : (string) $response->getContent();

            expect($response->getStatusCode())->toBe(200, "{$link} {$cache}")
                ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment')
                ->and(getimagesizefromstring($body)['mime'] ?? null)->toBe('image/jpeg', "{$link} {$cache}")
                ->and($body)->not->toBe($dng);
        }
    }

    expect($assetLink->fresh()->download_count)->toBe(0)
        ->and($folderLink->fresh()->download_count)->toBe(0)
        ->and(s5bGet(route('dam.share.download', $assetLink->urlToken()))->getStatusCode())->toBe(403);
});
