<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\ShareLog;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P2 S5a: share tokens hashed at rest, Reissue, and the review of links that never expire.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
});

function s5aCustomAdmin(array $permissions): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** Under grants: puts $asset in a folder granted to $admin, so $admin can see it without owning it. */
function s5aGrantAsset(Admin $admin, Asset $asset): void
{
    $folder = Directory::create(['name' => 'S5a folder '.Str::random(6), 'parent_id' => null]);
    $asset->directories()->attach($folder->id);
    DB::table('dam_directory_user')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id, 'inherit_children' => false]);
    app(DirectoryPermissionService::class)->flush();
}

function s5aSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** Bearer headers for $admin; $permissions null is an API key with every permission, else a custom key. */
function s5aApiHeaders(Admin $admin, ?array $permissions = null): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();

    $admin->forceFill(['password' => Hash::make('password')])->save();

    $client = (new ClientRepository)->createPasswordGrantClient('S5a share API client', 'admins', confidential: true);
    $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();

    Apikey::factory()->create([
        'permission_type' => $permissions === null ? 'all' : 'custom',
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
        'permissions'     => $permissions,
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

function s5aShareUrl(string $token): string
{
    return url('/share/'.$token);
}

// --- Hashed at rest -----------------------------------------------------------------------------------------------

it('stores a new link as a hash and an encrypted copy, never the plaintext, and audits no form of it', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();

    $created = $this->postJson(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $asset->id,
        'public_link_confirmed' => true,
    ])->assertOk();

    $urlToken = $created->json('share.token');
    expect($urlToken)->toBeString()->toHaveLength(40)
        ->and($created->json('share.public_url'))->toBe(route('dam.share.show', ['token' => $urlToken]));

    $row = DB::table('dam_shares')->where('id', $created->json('share.id'))->first();

    expect($row->token)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $urlToken))
        ->and(Crypt::decryptString($row->token_encrypted))->toBe($urlToken);

    foreach ((array) $row as $column => $value) {
        expect(is_string($value) && str_contains($value, $urlToken))->toBeFalse("dam_shares.{$column} holds the plaintext");
    }

    $audits = DB::table('audits')->where('auditable_type', (new Share)->getMorphClass())->where('auditable_id', $row->id)->get();
    expect($audits)->not->toBeEmpty();

    foreach ($audits as $audit) {
        foreach (['old_values', 'new_values'] as $column) {
            $values = json_decode((string) $audit->{$column}, true) ?: [];

            expect(array_intersect_key($values, array_flip(['token', 'token_hash', 'token_encrypted'])))->toBe([])
                ->and(str_contains((string) $audit->{$column}, $urlToken))->toBeFalse();
        }
    }
});

it('cannot form a working link from a database read alone', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();
    $row = (array) DB::table('dam_shares')->where('id', $share->id)->first();

    // Every stored string, tried as the URL token: the hash fits the route's pattern and still opens nothing.
    $tried = 0;
    foreach ($row as $value) {
        if (is_string($value) && preg_match('/^[A-Za-z0-9]{20,64}$/', $value)) {
            $this->get(s5aShareUrl($value))->assertNotFound();
            $tried++;
        }
    }

    expect($tried)->toBeGreaterThan(0);
    $this->get(s5aShareUrl(rawurlencode($row['token_encrypted'])))->assertNotFound();

    // Only the decrypted copy, which needs APP_KEY, opens it.
    $this->get(s5aShareUrl($share->urlToken()))->assertOk();
});

it('keeps a legacy plaintext link working and re-stores it hashed on its first use', function () {
    $asset = Asset::factory()->create();
    $legacy = Share::factory()->legacy()->forAsset($asset->id)->create();
    $plaintext = $legacy->token;

    expect($legacy->token_hash)->toBeNull();

    shareVisitRan($this->get(s5aShareUrl($plaintext))->assertOk()->assertSeeText($asset->file_name));

    $row = DB::table('dam_shares')->where('id', $legacy->id)->first();
    expect($row->token)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $plaintext))
        ->and(Crypt::decryptString($row->token_encrypted))->toBe($plaintext);

    // The same URL keeps working, now through its hash, and the view count carried over. A visit inside P1b's
    // 30-minute dedupe window counts once, so the second visit comes after it.
    $this->travel(31)->minutes();
    shareVisitRan($this->get(s5aShareUrl($plaintext))->assertOk());
    expect($legacy->fresh()->view_count)->toBe(2)
        ->and($legacy->fresh()->urlToken())->toBe($plaintext);
});

it('opens a legacy link only by its exact token, even where the database compares case-insensitively', function () {
    $asset = Asset::factory()->create();
    $token = 'Ab'.Str::random(38);
    $legacy = Share::factory()->legacy()->forAsset($asset->id)->create(['token' => $token]);
    $variant = strtolower($token);

    // MySQL's utf8mb4_unicode_ci, simulated: the legacy read matches a differently cased token too.
    $repository = new class(app()) extends ShareRepository
    {
        protected function legacyMatch(string $token): ?object
        {
            return $this->model->newQuery()->toBase()->whereNull('token_hash')
                ->whereRaw('lower(token) = lower(?)', [$token])->first(['id', 'token']);
        }
    };
    app()->instance(ShareRepository::class, $repository);
    expect((fn () => $this->legacyMatch($variant))->call($repository)?->id)->toBe($legacy->id);

    $unknown = $this->get(s5aShareUrl(Str::random(40)))->assertNotFound();
    $cased = $this->get(s5aShareUrl($variant))->assertNotFound();
    expect($cased->getContent())->toBe($unknown->getContent());

    $row = DB::table('dam_shares')->where('id', $legacy->id)->first();
    expect($row->token)->toBe($token)->and($row->token_hash)->toBeNull()->and($row->token_encrypted)->toBeNull();

    // The exact token still opens it and re-stores the stored spelling.
    $this->get(s5aShareUrl($token))->assertOk();
    $row = DB::table('dam_shares')->where('id', $legacy->id)->first();
    expect($row->token)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and(Crypt::decryptString($row->token_encrypted))->toBe($token);
});

it('resolves a first use whose legacy row another request re-stores between its two reads', function () {
    $asset = Asset::factory()->create();
    $legacy = Share::factory()->legacy()->forAsset($asset->id)->create();

    // Request A has missed the hash read; request B's first use re-stores the row before A's legacy read runs.
    $requestA = new class(app()) extends ShareRepository
    {
        public ?Share $requestB = null;

        protected function legacyMatch(string $token): ?object
        {
            $this->requestB ??= (new ShareRepository(app()))->findByToken($token);

            return parent::legacyMatch($token);
        }
    };

    expect($requestA->findByToken($legacy->token)?->id)->toBe($legacy->id)
        ->and($requestA->requestB?->id)->toBe($legacy->id)
        ->and(DB::table('dam_shares')->where('id', $legacy->id)->value('token_hash'))->toBe(hash('sha256', $legacy->token));
});

it('converts legacy links and scrubs audited tokens only with --apply, idempotently, and logs each run', function () {
    $asset = Asset::factory()->create();
    $morph = (new Share)->getMorphClass();
    DB::table('audits')->where('auditable_type', $morph)->delete();

    $legacy = Share::factory()->legacy()->forAsset($asset->id)->count(2)->create();
    $hashed = Share::factory()->forAsset($asset->id)->create();
    $tokenKey = 'tok'.'en';
    $audit = fn (string $type, int $id, array $old, array $new) => DB::table('audits')->insertGetId([
        'event'          => 'updated', 'auditable_type' => $type, 'auditable_id' => $id, 'tags' => 'Share', 'history_id' => $id,
        'old_values'     => json_encode($old), 'new_values' => json_encode($new), 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Two share audits recorded before S5a, holding the plaintext; one share audit without it; one other model's.
    $created = $audit($morph, $legacy[0]->id, [], [$tokenKey => $legacy[0]->token, 'name' => 'Kept']);
    $updated = $audit($morph, $legacy[1]->id, [$tokenKey => $legacy[1]->token, 'name' => 'Old'], [$tokenKey => $legacy[1]->token, 'name' => 'New']);
    $clean = $audit($morph, $hashed->id, ['name' => 'A'], ['name' => 'B']);
    $other = $audit('Mothership\\Other\\Model', 1, [], [$tokenKey => 'unrelated']);
    $values = fn (int $id) => DB::table('audits')->where('id', $id)->first(['old_values', 'new_values']);
    $before = collect([$created, $updated, $clean, $other])->mapWithKeys(fn ($id) => [$id => $values($id)]);
    $logLines = fn () => is_file(storage_path(ShareLog::PATH)) ? count(file(storage_path(ShareLog::PATH))) : 0;
    $linesBefore = $logLines();

    $this->artisan('dam:shares:hash-tokens')
        ->expectsTable(['What', 'Count'], [['Legacy links (plaintext token)', 2], ['Share audit rows holding a token', 2]])
        ->assertSuccessful();

    // The dry run wrote nothing.
    expect(DB::table('dam_shares')->whereNull('token_hash')->count())->toBe(2);
    foreach ($before as $id => $row) {
        expect($values($id))->toEqual($row);
    }

    $this->artisan('dam:shares:hash-tokens', ['--apply' => true])
        ->expectsTable(['What', 'Count'], [['Legacy links converted', 2], ['Changed meanwhile, left alone', 0], ['Audit rows scrubbed', 2]])
        ->assertSuccessful();

    foreach ($legacy as $share) {
        $row = DB::table('dam_shares')->where('id', $share->id)->first();
        expect($row->token)->toBeNull()
            ->and($row->token_hash)->toBe(hash('sha256', $share->token))
            ->and(Crypt::decryptString($row->token_encrypted))->toBe($share->token);
        $this->get(s5aShareUrl($share->token))->assertOk();
    }

    expect(json_decode($values($created)->new_values, true))->toBe(['name' => 'Kept'])
        ->and(json_decode($values($updated)->old_values, true))->toBe(['name' => 'Old'])
        ->and(json_decode($values($updated)->new_values, true))->toBe(['name' => 'New'])
        ->and($values($clean))->toEqual($before[$clean])
        ->and($values($other))->toEqual($before[$other]);

    // Idempotent: nothing left to convert or scrub.
    $this->artisan('dam:shares:hash-tokens', ['--apply' => true])
        ->expectsTable(['What', 'Count'], [['Legacy links converted', 0], ['Changed meanwhile, left alone', 0], ['Audit rows scrubbed', 0]])
        ->assertSuccessful();

    $records = array_map(fn ($line) => json_decode($line, true), array_slice(file(storage_path(ShareLog::PATH)), $linesBefore));
    expect(array_column($records, 'event'))->toBe(['share_tokens.dry_run', 'share_tokens.applied', 'share_tokens.applied'])
        ->and($records[0]['ctx'])->toBe(['legacy' => 2, 'audits' => 2])
        ->and($records[1]['ctx'])->toBe(['converted' => 2, 'skipped' => 0, 'scrubbed' => 2])
        ->and($logLines())->toBe($linesBefore + 3);
});

// --- Reissue --------------------------------------------------------------------------------------------------------

it('reissues a link in place: the old URL answers like an unknown one, the new one works, counts and expiry stay', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create(['name' => 'Kept', 'view_count' => 7, 'download_count' => 3, 'expires_at' => now()->addDays(5)->startOfSecond()]);
    $old = $share->urlToken();
    $unknown = Str::random(40);

    $response = $this->postJson(route('admin.dam.shared-links.reissue', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('share.id', $share->id);

    $new = $response->json('share.token');
    expect($new)->toBeString()->not->toBe($old)
        ->and($response->json('share.public_url'))->toBe(route('dam.share.show', ['token' => $new]));

    $fresh = $share->fresh();
    expect($fresh->urlToken())->toBe($new)
        ->and($fresh->token)->toBeNull()
        ->and($fresh->name)->toBe('Kept')
        ->and($fresh->view_count)->toBe(7)
        ->and($fresh->download_count)->toBe(3)
        ->and($fresh->expires_at->equalTo($share->expires_at))->toBeTrue()
        ->and(Share::query()->count())->toBe(1);

    foreach (['', '/download'] as $suffix) {
        $dead = $this->get(s5aShareUrl($old).$suffix);
        $missing = $this->get(s5aShareUrl($unknown).$suffix);
        expect($dead->status())->toBe(404)->toBe($missing->status())
            ->and($dead->getContent())->toBe($missing->getContent());
    }

    $this->get(s5aShareUrl($new))->assertOk()->assertSeeText($asset->file_name);
});

it('reissues a legacy link too, so its old plaintext stops working', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();
    $legacy = Share::factory()->legacy()->forAsset($asset->id)->create();

    $new = $this->postJson(route('admin.dam.shared-links.reissue', $legacy->id))->assertOk()->json('share.token');

    expect($legacy->fresh()->token)->toBeNull();
    $this->get(s5aShareUrl($legacy->token))->assertNotFound();
    $this->get(s5aShareUrl($new))->assertOk();
});

it('refuses to reissue an expired or revoked link, with a clear message, and leaves it untouched', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();

    foreach ([
        'expired' => [Share::factory()->forAsset($asset->id)->expired()->create(), 'dam::app.admin.dam.share.reissue-expired'],
        'revoked' => [Share::factory()->forAsset($asset->id)->revoked()->create(), 'dam::app.admin.dam.share.reissue-revoked'],
    ] as $case => [$share, $message]) {
        $before = (array) DB::table('dam_shares')->where('id', $share->id)->first();

        $this->postJson(route('admin.dam.shared-links.reissue', $share->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans($message));

        expect((array) DB::table('dam_shares')->where('id', $share->id)->first())->toBe($before, $case);
    }
});

it('gates reissue exactly like revoke on the web, and hides a hidden target like a missing link', function () {
    s5aSetModel(AccessModel::GRANTS);
    $asset = Asset::factory()->create();
    $creator = Admin::factory()->create();

    // No dam.shares.revoke: refused both.
    $noKey = s5aCustomAdmin(['dam', 'dam.asset.view']);
    s5aGrantAsset($noKey, $asset);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $token = $share->urlToken();
    $this->actingAs($noKey, 'admin');
    $this->patchJson(route('admin.dam.shared-links.revoke', $share->id))->assertForbidden();
    $this->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertForbidden();

    // The key, but the target is hidden: both answer like a link that does not exist.
    $blind = s5aCustomAdmin(['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    $this->actingAs($blind, 'admin');
    $missing = $this->postJson(route('admin.dam.shared-links.reissue', 999999))->assertNotFound();
    $hidden = $this->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent());
    $this->patchJson(route('admin.dam.shared-links.revoke', $share->id))->assertNotFound();

    expect($share->fresh()->urlToken())->toBe($token)->and($share->fresh()->isRevoked())->toBeFalse();

    // Under lists, a listed member who neither made the link nor owns the file is refused both.
    s5aSetModel(AccessModel::LISTS);
    $member = s5aCustomAdmin(['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    $this->actingAs($member, 'admin');
    $this->patchJson(route('admin.dam.shared-links.revoke', $share->id))->assertForbidden();
    $this->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertForbidden();

    expect($share->fresh()->urlToken())->toBe($token);
});

it('returns the reissued URL on the web only to whom the #126 rule shows it', function () {
    s5aSetModel(AccessModel::GRANTS);
    $owner = Admin::factory()->create();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);

    // Under grants the revoke key plus folder access no longer reissues another's link (R14): only the link's
    // creator, the file's owner or a full admin may, and each of them is shown the URL.
    $revoker = s5aCustomAdmin(['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    s5aGrantAsset($revoker, $asset);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id]);
    $token = $share->urlToken();

    $this->actingAs($revoker, 'admin');
    $refused = $this->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertForbidden();
    expect($refused->getContent())->not->toContain((string) $token)
        ->and($share->fresh()->urlToken())->toBe($token);

    // Its creator (here also the file's owner) sees the new URL.
    $creator = s5aCustomAdmin(['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    s5aGrantAsset($creator, $asset);
    $own = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->actingAs($creator, 'admin');
    $shown = $this->postJson(route('admin.dam.shared-links.reissue', $own->id))->assertOk();
    expect($shown->json('share.token'))->toBe($own->fresh()->urlToken())
        ->and($shown->json('share.public_url'))->toBe(route('dam.share.show', ['token' => $own->fresh()->urlToken()]));
});

it('reissues over REST under the revoke permission, gated like revoke, with the URL only for the #126 rule', function () {
    s5aSetModel(AccessModel::GRANTS);
    $owner = Admin::factory()->create();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id]);
    $old = $share->urlToken();

    // An API key without api.dam.shares.revoke is refused reissue as it is revoke.
    $keyless = s5aCustomAdmin(['dam', 'dam.asset.view']);
    s5aGrantAsset($keyless, $asset);
    $headers = s5aApiHeaders($keyless, ['api.dam.shares.getById']);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.revoke', $share->id))->assertForbidden();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reissue', $share->id))->assertForbidden();

    // A hidden target answers like a missing link.
    $blind = s5aCustomAdmin(['dam', 'dam.asset.view']);
    $headers = s5aApiHeaders($blind);
    $missing = $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reissue', 999999))->assertNotFound();
    expect($this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reissue', $share->id))->assertNotFound()->getContent())
        ->toBe($missing->getContent());
    expect($share->fresh()->urlToken())->toBe($old);

    // The revoke key and folder access, but neither creator, owner nor full admin: refused (R14), link unchanged.
    $revoker = s5aCustomAdmin(['dam', 'dam.asset.view']);
    s5aGrantAsset($revoker, $asset);
    $this->withHeaders(s5aApiHeaders($revoker, ['api.dam.shares.revoke']))
        ->postJson(route('admin.api.dam.shares.reissue', $share->id))
        ->assertForbidden();
    expect($share->fresh()->urlToken())->toBe($old);

    // The owner reissues and gets the new URL; the old one stops at once. The expired case is refused here too.
    $mine = $this->withHeaders(s5aApiHeaders($owner))->postJson(route('admin.api.dam.shares.reissue', $share->id))->assertOk();
    expect($share->fresh()->urlToken())->not->toBe($old);
    $this->get(s5aShareUrl($old))->assertNotFound();
    expect($mine->json('data.token'))->toBe($share->fresh()->urlToken())
        ->and($mine->json('data.public_url'))->toBe(route('dam.share.show', ['token' => $share->fresh()->urlToken()]));

    $expired = Share::factory()->forAsset($asset->id)->expired()->create(['created_by' => $owner->id]);
    $this->withHeaders(s5aApiHeaders($owner))->postJson(route('admin.api.dam.shares.reissue', $expired->id))
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.share.reissue-expired'));
});

// --- Shared links grid -----------------------------------------------------------------------------------------------

function s5aGridRecords(Admin $viewer): array
{
    app(DirectoryPermissionService::class)->flush();
    test()->actingAs($viewer, 'admin');
    $response = test()->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    return [collect($response->json('records'))->keyBy('id')->all(), $response->getContent()];
}

/** The grid's CSV export for $viewer: [header columns, whole file]. */
function s5aGridExport(Admin $viewer): array
{
    app(DirectoryPermissionService::class)->flush();
    test()->actingAs($viewer, 'admin');
    $response = test()->get(route('admin.dam.shared-links.index', ['export' => 1, 'format' => 'csv']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

    return [str_getcsv(strtok($csv, "\n")), $csv];
}

/** A custom role that reaches every folder under grants (dam_role_settings.all_directories): no full admin. */
function s5aAllDirectoriesAdmin(array $permissions): Admin
{
    $admin = s5aCustomAdmin($permissions);
    DB::table('dam_role_settings')->insert(['role_id' => $admin->role_id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $admin;
}

it('gives the grid copy-link only to the creator, the target owner or a full admin, never an empty URL', function (string $model) {
    s5aSetModel($model);
    $permissions = ['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke'];
    $creator = s5aCustomAdmin($permissions);
    $assetOwner = s5aCustomAdmin($permissions);
    $folderOwner = s5aCustomAdmin($permissions);
    $other = s5aCustomAdmin($permissions);
    $allFolders = s5aAllDirectoriesAdmin($permissions);
    $fullAdmin = Admin::factory()->create();

    $folder = Directory::create(['name' => 'S5a grid folder '.Str::random(6), 'parent_id' => null, 'created_by' => $folderOwner->id]);
    $asset = Asset::factory()->create(['created_by' => $assetOwner->id]);
    $asset->directories()->attach($folder->id);
    foreach ([$creator, $assetOwner, $folderOwner, $other, $allFolders] as $admin) {
        DB::table('dam_directory_user')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id, 'inherit_children' => false]);
        DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id]);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $admin->id]);
    }

    // All made by $creator: an asset link, a folder link (its owner comes through the joined directory), a legacy
    // plaintext one, and one whose encrypted copy no longer decrypts.
    $assetLink = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $folderLink = Share::factory()->forDirectory($folder->id)->create(['created_by' => $creator->id]);
    $legacyLink = Share::factory()->legacy()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $broken = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    DB::table('dam_shares')->where('id', $broken->id)->update(['token_encrypted' => 'not-a-ciphertext']);

    $tokens = [$assetLink->id => $assetLink->urlToken(), $folderLink->id => $folderLink->urlToken(), $legacyLink->id => $legacyLink->token];
    $stored = DB::table('dam_shares')->get(['token_hash', 'token_encrypted'])
        ->flatMap(fn ($row) => [$row->token_hash, $row->token_encrypted])->filter()->values()->all();
    $forbidden = ['token', 'token_hash', 'token_encrypted', 'link_expires_at', 'link_created_by', 'link_target_owner_id'];

    // Who gets which URL; null is a listed row with no copy action.
    $expected = [
        'other'          => [$other, []],
        'all folders'    => [$allFolders, []],
        'creator'        => [$creator, [$assetLink->id, $folderLink->id, $legacyLink->id]],
        'asset owner'    => [$assetOwner, [$assetLink->id, $legacyLink->id]],
        'folder owner'   => [$folderOwner, [$folderLink->id]],
        'full admin'     => [$fullAdmin, [$assetLink->id, $folderLink->id, $legacyLink->id]],
    ];

    foreach ($expected as $who => [$viewer, $shown]) {
        [$records, $json] = s5aGridRecords($viewer);

        // Under lists only a link's creator, its target's owner or a full admin lists it at all.
        $listsAll = $model === AccessModel::GRANTS || in_array($who, ['creator', 'full admin'], true);
        if ($listsAll) {
            expect(array_keys($records))->toEqualCanonicalizing([$assetLink->id, $folderLink->id, $legacyLink->id, $broken->id], $who);
        }

        foreach ($records as $id => $record) {
            $copy = collect($record['actions'])->firstWhere('index', 'copy_link');
            $url = in_array($id, $shown, true) ? route('dam.share.show', ['token' => $tokens[$id]]) : null;
            expect($copy['url'] ?? null)->toBe($url, "{$who}, link {$id}")
                ->and(array_intersect(array_keys($record), $forbidden))->toBe([], $who);

            foreach ($record['actions'] as $action) {
                expect($action['url'])->toBeString()->not->toBe('', "{$who}, {$action['index']}");
            }
        }
        foreach ($shown as $id) {
            expect($records)->toHaveKey($id);
        }

        foreach ([...array_values(array_diff_key($tokens, array_flip($shown))), ...$stored] as $secret) {
            expect($json)->not->toContain($secret);
        }

        // The export writes the query's raw rows: no token column and no token in any form, whoever exports.
        [$header, $csv] = s5aGridExport($viewer);
        expect(array_intersect($header, $forbidden))->toBe([], $who);
        if ($listsAll) {
            expect($header)->toContain('id', 'share_type', 'target_id')->and(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(5);
        }
        foreach ([...array_values($tokens), ...$stored] as $secret) {
            expect($csv)->not->toContain($secret);
        }
    }
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('shows a custom role that reaches every folder no URL on the web or over REST: it is no full admin', function () {
    s5aSetModel(AccessModel::GRANTS);
    $owner = Admin::factory()->create();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id]);
    $allFolders = s5aAllDirectoriesAdmin(['dam', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    app(DirectoryPermissionService::class)->flush();

    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create(['created_by' => $owner->id]);

    // Reaching every folder is not owning the link (R14): it may neither update a link nor re-enable a revoked
    // one, and the refusal carries no URL.
    $this->actingAs($allFolders, 'admin');
    foreach ([
        [$share, $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => 'Renamed'])],
        [$revoked, $this->patchJson(route('admin.dam.shared-links.reauthorize', $revoked->id), ['public_link_confirmed' => true])],
    ] as [$link, $response]) {
        $response->assertForbidden();
        expect($response->getContent())->not->toContain($link->urlToken());
    }
    expect($share->fresh()->name)->not->toBe('Renamed')->and($revoked->fresh()->isRevoked())->toBeTrue();

    $headers = s5aApiHeaders($allFolders);
    foreach ([route('admin.api.dam.shares.show', $share->id), route('admin.api.dam.shares.index')] as $url) {
        $response = $this->withHeaders($headers)->getJson($url)->assertOk();
        expect($response->getContent())->toContain('"id":'.$share->id)->not->toContain($share->urlToken());
    }
});

it('lists never-expiring links in the grid, with Reissue on every active link and not on an expired one', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();
    $forever = Share::factory()->forAsset($asset->id)->noExpiry()->create();
    $active = Share::factory()->forAsset($asset->id)->create();
    $expired = Share::factory()->forAsset($asset->id)->expired()->create();

    $response = $this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $actions = collect($response->json('records'))->mapWithKeys(fn ($record) => [$record['id'] => array_column($record['actions'], 'index')]);

    expect($actions[$forever->id])->toContain('reissue')
        ->and($actions[$active->id])->toContain('reissue')
        ->and($actions[$expired->id])->not->toContain('reissue')
        ->and($response->getContent())->not->toContain('link_expires_at');
});

// --- Links that never expire ------------------------------------------------------------------------------------------

it('reports active links that never expire, and with --apply --days gives exactly those an expiry', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');

    $creator = Admin::factory()->create(['name' => 'Link Maker']);
    $asset = Asset::factory()->create();
    $folder = Directory::create(['name' => 'S5a never expiring', 'parent_id' => null]);
    $forever = Share::factory()->forAsset($asset->id)->noExpiry()->create(['created_by' => $creator->id, 'view_count' => 4, 'download_count' => 2]);
    $folderForever = Share::factory()->forDirectory($folder->id)->noExpiry()->create();
    $revoked = Share::factory()->forAsset($asset->id)->noExpiry()->revoked()->create();
    $dated = Share::factory()->forAsset($asset->id)->create(['expires_at' => now()->addDays(3)]);

    expect(Artisan::call('dam:shares:review-expiry', ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true);

    expect(array_column($report, 'id'))->toBe([$forever->id, $folderForever->id])
        ->and($report[0])->toMatchArray([
            'type'       => Share::TYPE_ASSET, 'target_id' => $asset->id, 'creator_id' => $creator->id, 'creator' => 'Link Maker',
            'view_count' => 4, 'download_count' => 2, 'last_accessed_at' => null,
        ])
        ->and($report[1])->toMatchArray(['type' => Share::TYPE_DIRECTORY, 'target_id' => $folder->id]);

    // --apply needs --days, and a declined confirmation writes nothing.
    $this->artisan('dam:shares:review-expiry', ['--apply' => true])->assertFailed();
    $this->artisan('dam:shares:review-expiry', ['--apply' => true, '--days' => 10])
        ->expectsConfirmation('Set an expiry of 10 days on these 2 links? Each stops working when it passes.', 'no')
        ->assertSuccessful();
    expect($forever->fresh()->expires_at)->toBeNull();

    $this->artisan('dam:shares:review-expiry', ['--apply' => true, '--days' => 10, '--force' => true])
        ->expectsOutputToContain('2 links now expire in 10 days.')
        ->assertSuccessful();

    expect($forever->fresh()->expires_at->equalTo(now()->addDays(10)))->toBeTrue()
        ->and($folderForever->fresh()->expires_at->equalTo(now()->addDays(10)))->toBeTrue()
        ->and($revoked->fresh()->expires_at)->toBeNull()
        ->and($dated->fresh()->expires_at->equalTo(now()->addDays(3)))->toBeTrue();

    $this->artisan('dam:shares:review-expiry')->expectsOutputToContain('No active link is without an expiry.')->assertSuccessful();

    Carbon::setTestNow();
});

// --- Migration ------------------------------------------------------------------------------------------------------

it('migrates down to plaintext and back up on SQLite without losing a link', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The schema round trip is checked on SQLite.');
    }

    $asset = Asset::factory()->create();
    $hashed = Share::factory()->forAsset($asset->id)->create();
    $legacy = Share::factory()->legacy()->forAsset($asset->id)->create();
    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_07_000200_hash_dam_share_tokens.php');
    $indexes = fn () => collect(Schema::getIndexes('dam_shares'))->mapWithKeys(fn ($index) => [implode(',', $index['columns']) => $index['unique']]);

    $migration->down();

    expect(Schema::hasColumns('dam_shares', ['token_hash', 'token_encrypted']))->toBeFalse()
        ->and(collect(Schema::getColumns('dam_shares'))->firstWhere('name', 'token')['nullable'])->toBeFalse()
        ->and($indexes()['token'])->toBeTrue()
        ->and(DB::table('dam_shares')->where('id', $hashed->id)->value('token'))->toBe($hashed->urlToken())
        ->and(DB::table('dam_shares')->where('id', $legacy->id)->value('token'))->toBe($legacy->token);

    $migration->up();

    expect(Schema::hasColumns('dam_shares', ['token_hash', 'token_encrypted']))->toBeTrue()
        ->and(collect(Schema::getColumns('dam_shares'))->firstWhere('name', 'token')['nullable'])->toBeTrue()
        ->and($indexes()['token'])->toBeFalse()
        ->and($indexes()['token_hash'])->toBeTrue();

    // Both rows are legacy now and still open; the first use re-stores each hashed.
    $this->get(s5aShareUrl($hashed->urlToken()))->assertOk();
    $this->get(s5aShareUrl($legacy->token))->assertOk();
    expect(DB::table('dam_shares')->whereNull('token_hash')->count())->toBe(0);
});
