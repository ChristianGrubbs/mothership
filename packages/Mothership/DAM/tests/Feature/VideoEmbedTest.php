<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Embed;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AssetEmbeds;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P6 D26: tokenized embeds. A public surface: every refusal answers like an unknown token, the token is stored only
 * as its digest, the page frames only where its allowed origins say, and nothing in it downloads.
 */

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
});

if (! function_exists('p6vVideo')) {
    /** A 1080p H.264 MP4 the browser plays as it is, in a folder, with its original bytes in place. */
    function p6vVideo(array $attributes = [], string $bytes = 'ORIGINAL-VIDEO-BYTES'): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'file_name' => 'clip-'.uniqid().'.mp4',
            'file_type' => 'video',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'codec_name' => 'h264'],
        ], $attributes));
        Directory::factory()->create()->assets()->attach($asset->id);

        return putAssetContent($asset, $bytes);
    }
}

if (! function_exists('p6vPublishProxy')) {
    /** A published 1080p preview proxy: the blob, its proxy attachment and the finished rendition naming it. */
    function p6vPublishProxy(Asset $asset, string $bytes = 'PROXY-MP4-BYTES'): AssetFile
    {
        $sha = hash('sha256', $bytes.'|'.$asset->id);
        $object = FileObject::factory()->create([
            'sha256'    => $sha,
            'size'      => strlen($bytes),
            'disk'      => Directory::getAssetDisk(),
            'path'      => storeFixtureBlob($sha, $bytes, 'mp4'),
            'mime_type' => 'video/mp4',
            'technical' => ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
        ]);
        $file = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_PROXY, 'label' => 'Preview (1080p)', 'sort' => 900]);
        Rendition::factory()->create([
            'dam_asset_id'          => $asset->id,
            'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
            'status'                => Rendition::STATUS_DONE,
            'output_file_object_id' => $object->id,
            'finished_at'           => now(),
        ]);

        return $file;
    }
}

/** Makes an embed through the Library route and returns its token, read from the URL the one response gives. */
function p6vEmbed(Asset $asset, array $payload = []): string
{
    $url = test()->postJson(route('admin.dam.assets.embeds.store', $asset->id), $payload)->assertCreated()->json('data.url');

    return basename(parse_url($url, PHP_URL_PATH));
}

/** What a refusal sends, for comparing two of them: status, body and every header but the date and the throttle's count. */
function p6vAnswer(TestResponse $response): array
{
    // x-request-id (P1a) is unique per request by design, so it is no oracle.
    $headers = collect($response->headers->all())->except(['date', 'x-ratelimit-remaining', 'x-request-id'])->sortKeys()->all();

    return [$response->getStatusCode(), $response->getContent(), $headers];
}

it('plays a valid embed: the page answers 200 and its media 206 for a byte range', function () {
    $asset = p6vVideo(bytes: 'ORIGINAL-0123456789');
    $token = p6vEmbed($asset);

    auth('admin')->logout();

    $page = $this->get(route('dam.embed.show', $token));

    $page->assertOk()
        ->assertSee(route('dam.embed.media', $token), escape: false)
        ->assertSee('controlslist="nodownload"', escape: false)
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeaderMissing('Set-Cookie');
    expect($page->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store');

    $media = $this->withHeader('Range', 'bytes=0-7')->get(route('dam.embed.media', $token));

    $media->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-7/19')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeaderMissing('Set-Cookie');
    expect($media->headers->get('Content-Disposition'))->toStartWith('inline');

    ob_start();
    $media->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('ORIGINAL');
});

it('plays the published proxy, and the original only when there is no proxy and the browser can play it', function () {
    $asset = p6vVideo(['meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160, 'codec_name' => 'h264']]);
    p6vPublishProxy($asset, 'PROXY-MP4-BYTES');
    $token = p6vEmbed($asset);

    $media = $this->get(route('dam.embed.media', $token))->assertOk();
    ob_start();
    $media->baseResponse->sendContent();
    expect(ob_get_clean())->toBe('PROXY-MP4-BYTES');

    // A ProRes master with no proxy has nothing a browser can play: the embed answers as unavailable.
    $prores = p6vVideo(['extension' => 'mov', 'mime_type' => 'video/quicktime', 'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'codec_name' => 'prores']]);
    $this->get(route('dam.embed.show', p6vEmbed($prores)))->assertNotFound();
});

it('names no source type for a QuickTime file, which Chrome rejects, and keeps it for an MP4', function () {
    $mov = p6vVideo(['extension' => 'mov', 'mime_type' => 'video/quicktime']);
    $mp4 = p6vVideo();
    $movToken = p6vEmbed($mov);
    $mp4Token = p6vEmbed($mp4);
    auth('admin')->logout();

    $this->get(route('dam.embed.show', $movToken))->assertOk()
        ->assertSee('<source src="'.route('dam.embed.media', $movToken).'">', escape: false)
        ->assertDontSee('video/quicktime');
    $this->get(route('dam.embed.show', $mp4Token))->assertOk()
        ->assertSee('type="video/mp4"', escape: false);
});

it('answers revoked, expired, unknown, unavailable, trashed and merged embeds identically, page and media alike', function () {
    $unknown = str_repeat('Z', AssetEmbeds::TOKEN_LENGTH);

    $revoked = p6vEmbed(p6vVideo());
    Embed::query()->update(['revoked_at' => now()]);

    $expiredAsset = p6vVideo();
    $expired = p6vEmbed($expiredAsset, ['expiry_days' => 1]);
    Embed::query()->where('asset_id', $expiredAsset->id)->update(['expires_at' => now()->subMinute()]);

    $draftAsset = p6vVideo();
    $draft = p6vEmbed($draftAsset);
    $draftAsset->update(['availability' => Asset::AVAILABILITY_DRAFT]);

    $closedAsset = p6vVideo();
    $closed = p6vEmbed($closedAsset);
    $closedAsset->update(['available_until' => now()->subDay()]);

    $trashedAsset = p6vVideo();
    $trashed = p6vEmbed($trashedAsset);
    $trashedAsset->delete();

    // P3b-1: a merge source is closed to the public like a trashed asset (its embed would otherwise keep playing it).
    $mergedAsset = p6vVideo();
    $merged = p6vEmbed($mergedAsset);
    DB::table('dam_assets')->where('id', $mergedAsset->id)->update(['merged_into_id' => p6vVideo()->id]);

    // Nobody is exempt on a public surface: a full admin signed in beside it still gets the refusal.
    $this->get(route('dam.embed.show', $draft))->assertNotFound();

    auth('admin')->logout();

    foreach (['dam.embed.show', 'dam.embed.media'] as $route) {
        $expected = p6vAnswer($this->get(route($route, $unknown)));
        expect($expected[0])->toBe(404);

        foreach (compact('revoked', 'expired', 'draft', 'closed', 'trashed', 'merged') as $case => $token) {
            expect(p6vAnswer($this->get(route($route, $token))))->toBe($expected, $route.' '.$case);
        }

        // A token of any other shape is refused the same way, not by the framework's own 404 page.
        expect(p6vAnswer($this->get('/embed/'.rawurlencode('not a token!').($route === 'dam.embed.media' ? '/media' : ''))))->toBe($expected, $route.' malformed');
    }
});

it('looks a token up only by its digest and compares it in constant time', function () {
    $asset = p6vVideo();
    $token = p6vEmbed($asset);
    $stored = Embed::query()->where('asset_id', $asset->id)->value('token_hash');

    auth('admin')->logout();

    $this->get(route('dam.embed.show', $token))->assertOk();

    // The stored digest is no credential, and neither is a near miss.
    $this->get(route('dam.embed.show', $stored))->assertNotFound();
    $this->get(route('dam.embed.show', strtoupper($token) === $token ? strtolower($token) : strtoupper($token)))->assertNotFound();
    $this->get(route('dam.embed.show', substr($token, 0, -1)))->assertNotFound();

    expect(app(AssetEmbeds::class)->resolve($stored))->toBeNull()
        ->and(app(AssetEmbeds::class)->resolve($token)['embed']->asset_id)->toBe($asset->id);
});

it('never stores the token in plaintext, and never lists it', function () {
    $asset = p6vVideo();
    $response = $this->postJson(route('admin.dam.assets.embeds.store', $asset->id))->assertCreated();
    $token = basename(parse_url($response->json('data.url'), PHP_URL_PATH));

    expect(strlen($token))->toBe(AssetEmbeds::TOKEN_LENGTH)
        ->and($response->json('data.snippet'))->toContain('<iframe src="'.route('dam.embed.show', $token).'"');

    $row = (array) DB::table('dam_embeds')->where('asset_id', $asset->id)->first();

    expect($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(collect($row)->filter(fn ($value) => is_string($value) && str_contains($value, $token)))->toBeEmpty();

    $listed = $this->getJson(route('admin.dam.assets.embeds.index', $asset->id))->assertOk()->getContent();

    expect($listed)->not->toContain($token)
        ->and($listed)->not->toContain($row['token_hash']);
});

it('sets frame-ancestors from the allowed origins and refuses anything that is not an origin', function () {
    $asset = p6vVideo();
    $token = p6vEmbed($asset, ['allowed_origins' => "https://Partner.example/\nhttps://*.cdn.example:8443"]);

    $csp = $this->get(route('dam.embed.show', $token))->assertOk()->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-ancestors https://partner.example https://*.cdn.example:8443');

    $mediaCsp = $this->get(route('dam.embed.media', $token))->headers->get('Content-Security-Policy');
    expect($mediaCsp)->toContain('frame-ancestors https://partner.example https://*.cdn.example:8443');

    foreach (['https://partner.example/page', 'https://a.example; script-src *', 'javascript:alert(1)', '*', 'ftp://files.example'] as $bad) {
        $this->postJson(route('admin.dam.assets.embeds.store', $asset->id), ['allowed_origins' => [$bad]])
            ->assertUnprocessable()->assertJsonValidationErrors('allowed_origins');
    }

    $this->postJson(route('admin.dam.assets.embeds.store', $asset->id), ['allowed_origins' => array_map(fn ($i) => "https://s{$i}.example", range(1, 11))])
        ->assertUnprocessable()->assertJsonValidationErrors('allowed_origins');
});

it('offers no download through an embed', function () {
    $asset = p6vVideo();
    $token = p6vEmbed($asset);

    $embedRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'dam.embed.'))
        ->map(fn ($route) => $route->getName())->sort()->values()->all();
    expect($embedRoutes)->toBe(['dam.embed.media', 'dam.embed.show']);

    $page = $this->get(route('dam.embed.show', $token))->assertOk()->getContent();
    expect($page)->not->toContain(route('admin.dam.assets.download', $asset->id))
        ->and($page)->not->toMatch('/<a\b|\bdownload=/i');

    $this->get(route('dam.embed.show', $token).'/download')->assertNotFound();
    expect($this->get(route('dam.embed.media', $token))->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('makes an embed only for someone who can see the asset and holds the embed permission', function () {
    $granted = Directory::factory()->create();
    $visible = p6vVideo();
    $granted->assets()->attach($visible->id);
    $hidden = p6vVideo();
    $image = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png']);
    $granted->assets()->attach($image->id);
    $missingId = Asset::max('id') + 1000;

    // View, but no embed permission: refused once the asset is visible.
    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view']);
    $this->postJson(route('admin.dam.assets.embeds.store', $visible->id))->assertForbidden();

    // The embed permission, on an asset the viewer cannot see: exactly like an id that does not exist.
    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.embed']);
    $hiddenAnswer = $this->postJson(route('admin.dam.assets.embeds.store', $hidden->id));
    $missingAnswer = $this->postJson(route('admin.dam.assets.embeds.store', $missingId));
    expect($hiddenAnswer->getStatusCode())->toBe(404)
        ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
    $this->getJson(route('admin.dam.assets.embeds.index', $hidden->id))->assertNotFound();

    $this->postJson(route('admin.dam.assets.embeds.store', $image->id))->assertUnprocessable();
    $this->postJson(route('admin.dam.assets.embeds.store', $visible->id))->assertCreated();

    expect(Embed::query()->where('asset_id', $hidden->id)->exists())->toBeFalse();
});

it('lists and revokes embeds; another member without edit may not revoke one', function () {
    $granted = Directory::factory()->create();
    $asset = p6vVideo();
    $granted->assets()->attach($asset->id);
    $token = p6vEmbed($asset);
    $embedId = Embed::query()->where('asset_id', $asset->id)->value('id');

    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.embed']);
    $this->getJson(route('admin.dam.assets.embeds.index', $asset->id))->assertOk()
        ->assertJsonPath('data.0.id', $embedId)
        ->assertJsonPath('data.0.status', Embed::STATUS_ACTIVE)
        ->assertJsonPath('data.0.can_revoke', false);
    $this->postJson(route('admin.dam.assets.embeds.revoke', [$asset->id, $embedId]))->assertForbidden();

    $this->loginAsAdmin();
    $this->postJson(route('admin.dam.assets.embeds.revoke', [$asset->id, $embedId]))->assertOk()->assertJsonPath('data.status', Embed::STATUS_REVOKED);
    $this->postJson(route('admin.dam.assets.embeds.revoke', [$asset->id, $embedId]))->assertOk()->assertJsonPath('success', false);

    // An embed named under another asset's id is not found there.
    $other = p6vVideo();
    $this->postJson(route('admin.dam.assets.embeds.revoke', [$other->id, $embedId]))->assertNotFound();

    $this->get(route('dam.embed.show', $token))->assertNotFound();
});

it('makes, lists and revokes embeds through the REST API under the same rules', function () {
    $asset = p6vVideo();
    $headers = $this->getAuthenticationHeaders();

    $made = $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.embeds.store', $asset->id), ['allowed_origins' => ['https://partner.example'], 'expiry_days' => 30])
        ->assertCreated()
        ->assertJsonPath('data.embed.allowed_origins.0', 'https://partner.example')
        ->assertJsonPath('data.embed.status', Embed::STATUS_ACTIVE);
    $token = basename(parse_url($made->json('data.url'), PHP_URL_PATH));
    $embedId = $made->json('data.embed.id');

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.embeds.index', $asset->id))->assertOk()->assertJsonPath('data.0.id', $embedId);
    $this->get(route('dam.embed.show', $token))->assertOk();

    $missingId = Asset::max('id') + 1000;
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.embeds.store', $missingId))->assertNotFound();

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.embeds.revoke', [$asset->id, $embedId]))->assertOk()->assertJsonPath('success', true);
    $this->get(route('dam.embed.show', $token))->assertNotFound();
});

it('answers a hidden asset like a missing one on the REST embed routes', function () {
    // The bearer alone decides what is visible: no Library session beside it.
    auth('admin')->logout();
    $headers = $this->getAuthenticationHeaders();
    $granted = Directory::factory()->create();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $granted->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    Admin::latest('id')->first()->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $visible = p6vVideo();
    $granted->assets()->attach($visible->id);
    $hidden = p6vVideo();
    $missingId = Asset::max('id') + 1000;

    foreach (['admin.api.dam.assets.embeds.index' => 'getJson', 'admin.api.dam.assets.embeds.store' => 'postJson'] as $route => $method) {
        $hiddenAnswer = $this->withHeaders($headers)->{$method}(route($route, $hidden->id));
        $missingAnswer = $this->withHeaders($headers)->{$method}(route($route, $missingId));

        expect($hiddenAnswer->getStatusCode())->toBe(404, $route)
            ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent(), $route);
    }

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.embeds.index', $visible->id))->assertOk();
    expect(Embed::query()->where('asset_id', $hidden->id)->exists())->toBeFalse();
});

it('refuses a REST key without the embed scopes at the route', function () {
    $asset = p6vVideo();
    $limited = $this->getAuthenticationHeaders('custom', ['api.dam', 'api.dam.assets', 'api.dam.assets.getById']);

    $this->withHeaders($limited)->postJson(route('admin.api.dam.assets.embeds.store', $asset->id))->assertForbidden();
    $this->withHeaders($limited)->getJson(route('admin.api.dam.assets.embeds.index', $asset->id))->assertForbidden();
    expect(Embed::query()->count())->toBe(0);
});
