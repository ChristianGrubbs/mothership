<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\BuildWebMemberBundle;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\PageGrant;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Services\WebPageInspector;
use Mothership\DAM\Services\WebPageMembers;
use Mothership\DAM\Services\WebPagePolicy;
use Mothership\DAM\Services\WebPageResolver;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
});

function builtWebAsset(array $files): Asset
{
    $path = tempnam(sys_get_temp_dir(), 'wpr').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $assetPath = 'assets/Root/site.zip';
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put($assetPath, file_get_contents($path));
    $asset = Asset::factory()->create(['file_name' => 'site.zip', 'file_type' => 'web', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => $assetPath]);
    $asset->directories()->attach(1);
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    return $asset->fresh();
}

function pageUrl(Asset $asset, string $path = ''): string
{
    return PageGrant::baseUrl($asset, 1).$path;
}

it('issues a grant url to an admin with access and 409 before the bundle exists', function () {
    $this->loginAsAdmin();
    $asset = builtWebAsset(['index.html' => 'x']);

    $url = $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id]))->assertOk()->json('url');
    expect($url)->toEndWith('/');

    $pending = Asset::factory()->create(['file_type' => 'web', 'file_name' => 'p.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/p.html']);
    $pending->directories()->attach(1);
    $this->getJson(route('admin.dam.pages.grant', ['id' => $pending->id]))->assertStatus(409)->assertJson(['state' => 'preparing']);
});

it('serves the entry with the page sandbox policy, no cookie and no-store', function () {
    $asset = builtWebAsset(['index.html' => '<title>t</title>']);

    // The bundle root only serves the entry with its trailing slash; without one it redirects
    // (see the bundle-root case below), and the test client strips it.
    $r = getRawUrl(pageUrl($asset));

    $r->assertOk()
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('<title>t</title>', false);
    expect($r->headers->getCookies())->toBe([]);
});

it('pins the page CSP so only the Claude Design runtime hosts reach off-origin', function () {
    $directives = [];
    foreach (explode(';', WebPagePolicy::CSP) as $directive) {
        $parts = preg_split('/\s+/', trim($directive));
        $directives[array_shift($parts)] = $parts;
    }

    // ADR 0007 (amendment 2026-09-23): unpkg for script, Google Fonts for style and font. Spelled
    // out in full, not derived from the constant, so any added, removed or widened source fails.
    expect($directives)->toBe([
        'default-src'     => ["'self'", 'data:', 'blob:'],
        'script-src'      => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://unpkg.com'],
        'style-src'       => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
        'img-src'         => ["'self'", 'data:', 'blob:'],
        'font-src'        => ["'self'", 'data:', 'https://fonts.gstatic.com'],
        'media-src'       => ["'self'", 'blob:'],
        'connect-src'     => ["'self'"],
        'frame-src'       => ["'none'"],
        'object-src'      => ["'none'"],
        'base-uri'        => ["'none'"],
        'form-action'     => ["'none'"],
        'frame-ancestors' => ["'self'"],
        'sandbox'         => ['allow-scripts', 'allow-popups', 'allow-forms', 'allow-modals'],
    ]);
});

it('serves siblings with a mapped mime, the same policy and a 5 minute private cache', function () {
    $asset = builtWebAsset(['index.html' => 'x', 'css/a.css' => 'h1{}']);

    $r = $this->get(pageUrl($asset, 'css/a.css'));

    $r->assertOk()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)->assertHeader('Cache-Control', 'max-age=300, private');
    expect($r->headers->get('Content-Type'))->toStartWith('text/css');
});

it('never hands a sibling to nginx X-Accel, which would drop the page policy on the way out', function () {
    // The Studio sets this prefix. nginx keeps only a few upstream headers across an X-Accel
    // internal redirect, so a bundle SVG opened directly ran without the CSP sandbox and a font
    // lost Access-Control-Allow-Origin in the opaque-origin frame.
    config(['mothership.media_accel_prefix' => '/__dam_media']);
    $asset = builtWebAsset(['index.html' => 'x', 'img/a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'fonts/a.woff2' => 'wOF2']);

    foreach (['img/a.svg' => 'image/svg+xml', 'fonts/a.woff2' => 'font/woff2'] as $path => $mime) {
        $r = $this->get(pageUrl($asset, $path));

        $r->assertOk()
            ->assertHeaderMissing('X-Accel-Redirect')
            ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
            ->assertHeader('Access-Control-Allow-Origin', '*');
        expect($r->headers->get('Content-Type'))->toStartWith($mime);
    }
});

it('redirects a directory without a trailing slash so relative resources re-base under it', function () {
    $asset = builtWebAsset(['index.html' => 'root', 'docs/index.html' => '<script src="app.js"></script>', 'docs/app.js' => 'console.log(1)']);

    $redirect = $this->get(pageUrl($asset, 'docs'));
    $redirect->assertStatus(302);
    expect($redirect->headers->get('Location'))->toEndWith('/docs/');

    getRawUrl($redirect->headers->get('Location'))->assertOk()->assertSee('<script src="app.js">', false);
    $this->get(pageUrl($asset, 'docs/app.js'))->assertOk();
});

it('serves docs/ from docs/index.html', function () {
    $asset = builtWebAsset(['index.html' => 'root', 'docs/index.html' => 'nested']);

    getRawUrl(pageUrl($asset, 'docs/'))->assertOk()->assertSee('nested');
});

it('returns 404 with the policy for traversal, absolute and encoded escapes', function (string $path) {
    $asset = builtWebAsset(['index.html' => 'x']);

    $this->get(pageUrl($asset).$path)->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
})->with(['../../.env', '%2e%2e/%2e%2e/.env', '/etc/passwd', 'missing.css', '%252e%252e/%252e%252e/.env']);

it('rejects a backslash path at the resolver, below the http layer that already refuses it', function () {
    // Symfony refuses a backslash in the request URI with a 400 before routing, so this one
    // cannot be driven through the HTTP kernel; the resolver is asserted directly instead.
    $asset = builtWebAsset(['index.html' => 'x', 'css/a.css' => 'h1{}']);

    expect(app(WebPageResolver::class)->resolve($asset, 'css\\a.css'))->toBe(['status' => 404])
        ->and(app(WebPageResolver::class)->resolve($asset, '..\\..\\.env'))->toBe(['status' => 404]);
});

it('never follows a symlinked index.html out of the bundle', function () {
    $asset = builtWebAsset(['index.html' => 'x', 'docs/keep' => 'k']);
    $root = Storage::disk(Directory::getAssetDisk())->path(WebPageBundler::rootFor($asset));
    symlink('/etc/hosts', $root.'/docs/index.html');

    // `docs` redirects to `docs/` first; both ends of that hop must refuse the symlink.
    $this->get(pageUrl($asset, 'docs'))->assertStatus(302);
    getRawUrl(pageUrl($asset, 'docs/'))->assertNotFound();
});

it('rejects a bad, expired or superseded grant', function () {
    $asset = builtWebAsset(['index.html' => 'x']);
    $base = pageUrl($asset);

    $this->get(preg_replace('#/g/[^/]+/$#', '/g/'.str_repeat('A', 40).'/', $base))->assertNotFound();
    $expired = PageGrant::issue($asset->id, WebPageBundler::versionNumber($asset), 1, ttlSeconds: -5);
    $this->get(route('admin.dam.pages.file', ['id' => $asset->id, 'grant' => $expired, 'path' => '']).'/')->assertNotFound();

    AssetVersion::create(['dam_asset_id' => $asset->id, 'number' => WebPageBundler::versionNumber($asset) + 1]);
    $this->get($base)->assertNotFound();
});

it('returns 404 for a non-web asset and 409 before the bundle exists', function () {
    $image = Asset::factory()->create();
    $this->get(route('admin.dam.pages.file', ['id' => $image->id, 'grant' => PageGrant::issue($image->id, 0, 1), 'path' => '']).'/')->assertNotFound();

    $pending = Asset::factory()->create(['file_type' => 'web', 'file_name' => 'p.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/p.html']);
    $this->get(route('admin.dam.pages.file', ['id' => $pending->id, 'grant' => PageGrant::issue($pending->id, 0, 1), 'path' => '']).'/')->assertStatus(409);
});

it('serves unknown extensions as an octet-stream attachment', function () {
    $asset = builtWebAsset(['index.html' => 'x', 'data.bin' => 'zz']);

    $this->get(pageUrl($asset, 'data.bin'))->assertOk()->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename="data.bin"');
});

it('denies the grant on the directory check, not the route acl, to a viewer without a grant', function () {
    // Every permission the route ACL asks for, so a refusal here can only come from the directory
    // check: the role holds no dam_directory_role row for the directory the asset lives in. A hidden
    // asset answers 404, like a missing id, so the grant route cannot be used to probe for one.
    $admin = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.assets', 'dam.asset', 'dam.asset.view']);
    $asset = builtWebAsset(['index.html' => 'x']);

    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id]))->assertNotFound();

    // Control: the same admin, same route, once the directory is granted.
    DB::table('dam_directory_role')->insert(['directory_id' => 1, 'role_id' => $admin->role_id]);
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id]))->assertOk();
});

it('404s a malformed grant through the header middleware instead of missing the route', function () {
    $asset = builtWebAsset(['index.html' => 'x']);

    $this->get(route('admin.dam.pages.file', ['id' => $asset->id, 'grant' => 'not-a-grant', 'path' => '']).'/')
        ->assertNotFound()
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('carries the page policy on a throttled 429', function () {
    RateLimiter::for('dam-page-file', fn ($request) => Limit::perMinute(1)->by('page-test')
        ->response(fn ($request, array $headers) => WebPagePolicy::throttledResponse(public: false, headers: $headers)));

    $asset = builtWebAsset(['index.html' => 'x']);
    $url = pageUrl($asset);

    getRawUrl($url)->assertOk();
    getRawUrl($url)->assertStatus(429)
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('redirects the slashless bundle root so the entry re-bases its relative resources inside it', function () {
    $asset = builtWebAsset(['index.html' => '<script src="app.js"></script>', 'app.js' => 'console.log(1)']);

    // pageUrl() ends with the grant segment's slash; the test client strips it, which is exactly
    // the shape a browser would produce by following a link to the bundle root without one.
    $redirect = $this->get(pageUrl($asset));

    $redirect->assertStatus(302);
    expect($redirect->headers->get('Location'))->toEndWith('/');

    getRawUrl($redirect->headers->get('Location'))->assertOk()->assertSee('<script src="app.js">', false);
    $this->get(pageUrl($asset, 'app.js'))->assertOk();
});

/*
 * Selected current-file previews (ADR 0007 amendment): every current member of a Web Pages stack renders its own
 * validated page from an isolated cache, bound to the owning asset + slot + file + FULL checksum.
 */

function memberZip(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpm').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function memberAttach(object $test, Asset $asset, string $name, string $bytes, string $role): int
{
    return (int) $test->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent($name, $bytes), 'role' => $role,
    ])->assertCreated()->json('attachment_id');
}

/** A synthetic four-file stack: representative ZIP, two other ZIPs and one HTML, each with its own page text. */
function memberStack(object $test): array
{
    $asset = builtWebAsset(['index.html' => '<p>REPRESENTATIVE-PAGE</p>']);
    $slots = [
        'two'   => memberAttach($test, $asset, 'two.zip', memberZip(['index.html' => '<p>SECOND-PAGE</p>', 'app.js' => 'window.second=1']), AssetFile::ROLE_ALTERNATE),
        'three' => memberAttach($test, $asset, 'three.zip', memberZip(['index.html' => '<p>THIRD-PAGE</p>']), AssetFile::ROLE_PROJECT),
        'four'  => memberAttach($test, $asset, 'four.html', '<p>FOURTH-PAGE</p>', AssetFile::ROLE_OTHER),
    ];
    $asset = $asset->fresh();
    $members = collect(app(WebPageMembers::class)->listing($asset))->keyBy('attachment_id');

    return [$asset, $slots, $members];
}

function memberRevise(Asset $asset, int $attachmentId, string $bytes): AssetFile
{
    $sha = hash('sha256', $bytes);
    $path = app(ContentStore::class)->objectPath($sha, 'zip');
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
    $object = FileObject::factory()->create(['sha256' => $sha, 'size' => strlen($bytes), 'disk' => Directory::getAssetDisk(), 'path' => $path, 'mime_type' => 'application/zip']);

    return app(AttachmentLedger::class)->appendRevision(asset: $asset, attachmentId: $attachmentId, object: $object, note: 'Revised');
}

function memberGrantUrl(object $test, Asset $asset, array $member): string
{
    return $test->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertOk()->json('url');
}

it('lists every current member in stack order with full checksums, without building or writing anything', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $before = [$asset->meta_data, (string) $asset->updated_at, DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->count()];
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');

    $body = $this->getJson(route('admin.dam.pages.members', ['id' => $asset->id]))->assertOk()->json('members');

    expect(array_column($body, 'attachment_id'))->toBe([$members->first()['attachment_id'], $slots['two'], $slots['three'], $slots['four']])
        ->and($body[0]['is_main'])->toBeTrue()
        ->and(collect($body)->every(fn ($row) => preg_match('/^[0-9a-f]{64}$/', $row['checksum']) === 1 && $row['candidate'] === true && $row['state'] === 'none'))->toBeTrue()
        ->and(array_column($body, 'extension'))->toBe(['zip', 'zip', 'zip', 'html'])
        ->and(is_dir($namespace))->toBeFalse()
        ->and($asset->fresh()->meta_data)->toBe($before[0])
        ->and((string) $asset->fresh()->updated_at)->toBe($before[1]);
});

it('previews each of the four members from its own validated page and leaves the stack untouched', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $metaBefore = $asset->meta_data;
    $updated = (string) $asset->updated_at;
    $sourceBytes = DB::table('dam_file_objects')->orderBy('id')->pluck('sha256', 'id')->all();
    $expect = [$members->first()['attachment_id'] => 'REPRESENTATIVE-PAGE', $slots['two'] => 'SECOND-PAGE', $slots['three'] => 'THIRD-PAGE', $slots['four'] => 'FOURTH-PAGE'];

    foreach ($members as $attachmentId => $member) {
        $url = memberGrantUrl($this, $asset, $member);
        $entry = getRawUrl($url);
        $entry->assertOk()->assertSee($expect[$attachmentId])->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
        // Members never carry the screenshot/capture injection.
        expect($entry->getContent())->not->toContain('capture')->not->toContain('thumbnail');
    }

    $url = memberGrantUrl($this, $asset, $members[$slots['two']]);
    $script = $this->get($url.'app.js')->assertOk();
    expect(file_get_contents($script->baseResponse->getFile()->getPathname()))->toBe('window.second=1');

    $fresh = $asset->fresh();
    expect($fresh->meta_data)->toBe($metaBefore)
        ->and((string) $fresh->updated_at)->toBe($updated)
        ->and(DB::table('dam_file_objects')->orderBy('id')->pluck('sha256', 'id')->all())->toBe($sourceBytes)
        ->and(DB::table('dam_attachments')->where('dam_asset_id', $asset->id)->count())->toBe(4)
        ->and(glob(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m/*.building')))->toBe([]);
    foreach ($members as $member) {
        expect(is_file(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m/'.$member['extension'].'-'.$member['checksum'].'/manifest.json')))->toBeTrue();
    }
});

it('registers the members route to dam.asset.view and refuses a role without it, with hidden assets answering as missing', function () {
    expect(collect(config('acl'))->where('route', 'admin.dam.pages.members')->pluck('key')->all())->toBe(['dam.asset.view']);

    $this->loginAsAdmin();
    [$asset] = memberStack($this);

    $without = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.assets', 'dam.asset']);
    DB::table('dam_directory_role')->insert(['directory_id' => 1, 'role_id' => $without->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $this->getJson(route('admin.dam.pages.members', ['id' => $asset->id]))->assertForbidden();

    // Only the read capability the Web Pages role already holds: no relationship-edit, asset-files or update permission.
    $viewer = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.assets', 'dam.asset', 'dam.asset.view']);
    $this->getJson(route('admin.dam.pages.members', ['id' => $asset->id]))->assertNotFound();
    DB::table('dam_directory_role')->insert(['directory_id' => 1, 'role_id' => $viewer->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $this->getJson(route('admin.dam.pages.members', ['id' => $asset->id]))->assertOk()->assertJsonCount(4, 'members');

    $image = Asset::factory()->create();
    $image->directories()->attach(1);
    $this->getJson(route('admin.dam.pages.members', ['id' => $image->id]))->assertNotFound();
});

it('fails closed on a malformed, partial, foreign or non-current member context and calls a checksum mismatch stale', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['two']];
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/foreign.html', '<p>FOREIGN</p>');
    $other = Asset::factory()->create(['file_name' => 'foreign.html', 'file_type' => 'web', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/foreign.html']);
    $other->directories()->attach(1);
    $foreign = collect(app(WebPageMembers::class)->listing($other))->first();
    $grant = fn (array $query) => $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id] + $query));

    $grant(['file' => $member['file_id']])->assertNotFound();
    $grant(['checksum' => $member['checksum']])->assertNotFound();
    $grant(['file' => 'abc', 'checksum' => $member['checksum']])->assertNotFound();
    $grant(['file' => $member['file_id'], 'checksum' => substr($member['checksum'], 0, 16)])->assertNotFound();
    $grant(['file' => $member['file_id'], 'checksum' => strtoupper($member['checksum'])])->assertNotFound();
    $grant(['file' => $foreign['file_id'], 'checksum' => $foreign['checksum']])->assertNotFound();
    $grant(['file' => $member['file_id'], 'checksum' => str_repeat('a', 64)])->assertStatus(409)->assertJson(['state' => 'stale']);

    AssetFile::query()->where('id', $member['file_id'])->update(['is_current' => false]);
    $grant(['file' => $member['file_id'], 'checksum' => $member['checksum']])->assertNotFound();

    // Neither a refused context nor a stale one queued or marked anything.
    expect(glob(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m/*')))->toBe([]);
});

it('keeps the legacy grant, open and token behaviour when no file context is sent', function () {
    $this->loginAsAdmin();
    [$asset] = memberStack($this);

    $url = $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id]))->assertOk()->json('url');

    expect($url)->toContain('/g/')->and($url)->not->toContain('/m/');
    getRawUrl($url)->assertOk()->assertSee('REPRESENTATIVE-PAGE');
});

it('ends a member grant the moment its slot is re-roled, revised or removed, for the entry and every sibling', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $url = memberGrantUrl($this, $asset, $members[$slots['two']]);
    $sibling = memberGrantUrl($this, $asset, $members[$slots['three']]);
    getRawUrl($url)->assertOk()->assertSee('SECOND-PAGE');
    $this->get($url.'app.js')->assertOk();

    // Re-roled to a generated proxy, the slot is no longer a member: entry and siblings answer 404.
    DB::table('dam_attachments')->where('id', $slots['two'])->update(['role' => AssetFile::ROLE_PROXY]);
    getRawUrl($url)->assertNotFound();
    $this->get($url.'app.js')->assertNotFound();
    getRawUrl($sibling)->assertOk()->assertSee('THIRD-PAGE');

    // Revised: the same slot now holds other bytes, so the old grant (bound to the old file + checksum) is dead
    // and the new file is a different identity that must be asked for explicitly.
    $revised = memberRevise($asset, $slots['three'], memberZip(['index.html' => '<p>THIRD-REVISED</p>']));
    getRawUrl($sibling)->assertNotFound();
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $members[$slots['three']]['file_id'], 'checksum' => $members[$slots['three']]['checksum']]))->assertNotFound();
    $fresh = memberGrantUrl($this, $asset, collect(app(WebPageMembers::class)->listing($asset->fresh()))->firstWhere('attachment_id', $slots['three']));
    getRawUrl($fresh)->assertOk()->assertSee('THIRD-REVISED');
    expect($revised->id)->not->toBe($members[$slots['three']]['file_id']);

    // Removed: no row, no page.
    AssetFile::query()->where('attachment_id', $slots['four'])->delete();
    $dead = $members[$slots['four']];
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $dead['file_id'], 'checksum' => $dead['checksum']]))->assertNotFound();
});

/*
 * An already-minted member grant names the admin it was issued to. serveMember asks again whether that admin still reaches the
 * asset: before anything is built, and for the entry of a built page. Subresources of a built page stay grant-only (cheap,
 * cacheable), as for the legacy page grant (audit A2).
 */

/** A read-only admin who reaches directory 1 (the stack's), plus a switch for that access. @return array{0: Asset, 1: array, 2: object, 3: Closure} */
function revocableMemberViewer(object $test): array
{
    $test->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($test);
    $viewer = $test->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.assets', 'dam.asset', 'dam.asset.view']);
    $reaches = function (bool $reaches) use ($viewer): void {
        $row = ['directory_id' => 1, 'role_id' => $viewer->role_id];
        $reaches ? DB::table('dam_directory_role')->insert($row) : DB::table('dam_directory_role')->where($row)->delete();
        app(DirectoryPermissionService::class)->flush();
    };
    $reaches(true);

    return [$asset, $members[$slots['two']], $viewer, $reaches];
}

it('ends a minted member grant for an admin whose access was revoked before the page was built: 404 for the entry and every sibling, and nothing is queued or marked', function () {
    Queue::fake();
    [$asset, $member, $viewer, $reaches] = revocableMemberViewer($this);
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    // Minted while the admin still reached the asset, and before any build: no cache exists for it.
    $url = PageGrant::memberBaseUrl(asset: $asset, file: app(WebPageMembers::class)->current($asset, $member['file_id']), checksum: $member['checksum'], adminId: (int) $viewer->id);
    expect(is_dir($namespace))->toBeFalse();

    $reaches(false);

    getRawUrl($url)->assertNotFound();
    getRawUrl($url.'app.js')->assertNotFound();
    Queue::assertNothingPushed();
    expect(is_dir($namespace))->toBeFalse();

    // Control: the very same grant, once the access is back, reaches the build, so the 404s above came from the access check.
    $reaches(true);
    getRawUrl($url)->assertStatus(409);
    Queue::assertPushed(BuildWebMemberBundle::class, 1);
    expect(glob($namespace.'/*.building'))->toHaveCount(1);
});

it('re-checks a revoked admin on the entry of a built member page, while its subresources stay grant-only like the legacy page', function () {
    [$asset, $member, $viewer, $reaches] = revocableMemberViewer($this);
    $url = memberGrantUrl($this, $asset, $member);
    getRawUrl($url)->assertOk()->assertSee('SECOND-PAGE');
    $this->get($url.'app.js')->assertOk();
    $cache = fn () => glob(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m/*'));
    $built = $cache();
    expect($built)->not->toBe([]);

    $reaches(false);

    getRawUrl($url)->assertNotFound();
    // The entry is what closes the page; a built sibling is served for the grant's lifetime, as the legacy grant does.
    $this->get($url.'app.js')->assertOk();
    expect($cache())->toBe($built);

    $reaches(true);
    getRawUrl($url)->assertOk()->assertSee('SECOND-PAGE');
});

it('records a terminal failure for a member ZIP that is unsafe or not a page, leaves the stack untouched and keeps siblings working', function (string $case) {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $bad = match ($case) {
        'forbidden file' => memberZip(['index.html' => 'x', 'run.php' => '<?php']),
        'missing entry'  => memberZip(['readme.txt' => 'no page here']),
        'traversal'      => memberZip(['index.html' => 'x', '../escape.html' => 'out']),
        // Passes the header-only inspector, then the extractor cannot open the entry as a stream and silently drops it.
        'encrypted entry' => (function () {
            $path = tempnam(sys_get_temp_dir(), 'wps').'.zip';
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('index.html', '<p>LOCKED-PAGE</p>');
            $zip->setEncryptionName('index.html', ZipArchive::EM_AES_256, 'not-shared');
            $zip->close();

            return file_get_contents($path);
        })(),
        'symlink'        => (function () {
            $path = tempnam(sys_get_temp_dir(), 'wps').'.zip';
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('index.html', 'x');
            $zip->addFromString('link.html', '/etc/hosts');
            $zip->setExternalAttributesName('link.html', ZipArchive::OPSYS_UNIX, 0120777 << 16);
            $zip->close();

            return file_get_contents($path);
        })(),
    };
    $revision = memberRevise($asset, $slots['three'], $bad);
    $member = collect(app(WebPageMembers::class)->listing($asset->fresh()))->firstWhere('attachment_id', $slots['three']);
    $meta = $asset->fresh()->meta_data;

    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertStatus(409)->assertJson(['state' => 'failed']);

    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $listed = collect(app(WebPageMembers::class)->listing($asset->fresh()))->keyBy('attachment_id');
    expect($listed[$slots['three']]['state'])->toBe('failed')
        ->and($listed[$slots['three']]['error'])->not->toBeEmpty()
        ->and(glob($namespace.'/*.building'))->toBe([])
        ->and(glob($namespace.'/.staging-*'))->toBe([])
        ->and(glob($namespace.'/.staging-*.unpack'))->toBe([])
        ->and(is_file(dirname($namespace).'/escape.html') || is_file(dirname($namespace, 2).'/escape.html'))->toBeFalse()
        ->and($asset->fresh()->meta_data)->toBe($meta)
        ->and($revision->id)->toBeGreaterThan(0);

    // The failure is that file's alone; a sibling still previews.
    getRawUrl(memberGrantUrl($this, $asset, $members[$slots['two']]))->assertOk()->assertSee('SECOND-PAGE');

    // A refusal of the content itself is final for that checksum: the next selection (each frame poll, each public visit)
    // answers failed again without queuing another extraction.
    Queue::fake();
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertStatus(409)->assertJson(['state' => 'failed']);
    Queue::assertNothingPushed();
    expect(glob($namespace.'/*.building'))->toBe([]);
})->with(['forbidden file', 'missing entry', 'traversal', 'symlink', 'encrypted entry']);

it('keeps each member cache inside its own asset and never serves another asset a cache of the same bytes', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    // The very bytes of the second member: a ZIP rebuilt here would carry another timestamp, so another checksum.
    $object = app(WebPageMembers::class)->current($asset, $members[$slots['two']]['file_id'])->fileObject;
    $twinBytes = Storage::disk($object->disk)->get($object->path);
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/twin.zip', $twinBytes);
    $twin = Asset::factory()->create(['file_name' => 'twin.zip', 'file_type' => 'web', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => 'assets/Root/twin.zip']);
    $twin->directories()->attach(1);
    $twinMember = collect(app(WebPageMembers::class)->listing($twin))->first();
    $own = $members[$slots['two']];

    expect($twinMember['checksum'])->toBe($own['checksum']);
    $ownUrl = memberGrantUrl($this, $asset, $own);

    // The twin's own request builds under its own asset namespace, not by reusing the sibling's cache.
    expect(is_dir(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$twin->id.'/m')))->toBeFalse();
    memberGrantUrl($this, $twin, $twinMember);
    expect(is_file(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$twin->id.'/m/zip-'.$own['checksum'].'/manifest.json')))->toBeTrue();

    // A grant for one asset names its own asset and file: re-pointed at the other asset's route, or at its file id, it is 404.
    $this->get(str_replace('/'.$asset->id.'/', '/'.$twin->id.'/', $ownUrl))->assertNotFound();
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $twinMember['file_id'], 'checksum' => $twinMember['checksum']]))->assertNotFound();

    // The existing whole-asset purge takes the member caches with the rest of that asset's bundles, and only its own.
    app(WebPageBundler::class)->purge($asset->id);
    expect(is_dir(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id)))->toBeFalse()
        ->and(is_file(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$twin->id.'/m/zip-'.$own['checksum'].'/manifest.json')))->toBeTrue();
});

it('rejects a member cache whose root or manifest is a link or points at another asset or source', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['two']];
    memberGrantUrl($this, $asset, $member);
    $disk = Storage::disk(Directory::getAssetDisk());
    $dir = $disk->path('bundles/'.$asset->id.'/m/zip-'.$member['checksum']);
    $legacy = $disk->path(WebPageBundler::rootFor($asset));

    // root replaced by a link to the legacy representative bundle: another cache must never be served in its place.
    (new Filesystem)->deleteDirectory($dir.'/root');
    symlink($legacy, $dir.'/root');
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertStatus(409)->assertJson(['state' => 'failed', 'error' => 'cache_invalid']);

    // A manifest that names another checksum is not this member's cache either.
    unlink($dir.'/root');
    mkdir($dir.'/root');
    file_put_contents($dir.'/root/index.html', 'WRONG');
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
    $manifest['sha256'] = str_repeat('c', 64);
    file_put_contents($dir.'/manifest.json', json_encode($manifest));
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertStatus(409)->assertJson(['state' => 'failed', 'error' => 'cache_invalid']);

    // The whole member namespace redirected somewhere else is refused as well.
    $outside = sys_get_temp_dir().'/wpm-alias-'.bin2hex(random_bytes(4));
    mkdir($outside);
    (new Filesystem)->deleteDirectory(dirname($dir));
    symlink($outside, dirname($dir));
    $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]))
        ->assertStatus(409)->assertJson(['state' => 'failed']);
    expect(scandir($outside))->toBe(['.', '..']);
    unlink(dirname($dir));
    rmdir($outside);
});

it('queues one build per selected file, only after every access gate, and frees the marker however the job ends', function () {
    Queue::fake();
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['three']];
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $ask = fn () => $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]));

    // A viewer who cannot reach the asset (hidden = missing) queues nothing and leaves no marker.
    $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.assets', 'dam.asset', 'dam.asset.view']);
    $ask()->assertNotFound();
    Queue::assertNothingPushed();
    expect(is_dir($namespace))->toBeFalse();

    $this->loginAsAdmin();
    $ask()->assertStatus(409)->assertJson(['state' => 'preparing']);
    $ask()->assertStatus(409)->assertJson(['state' => 'preparing']);
    Queue::assertPushed(BuildWebMemberBundle::class, 1);
    expect(glob($namespace.'/*.building'))->toHaveCount(1);

    // The worker is killed or times out: its marker and the staging it left are freed and nothing is cached for the
    // content key, so the very next authorized selection queues a fresh build.
    $job = new BuildWebMemberBundle(app(WebPageMembers::class)->pin($asset, app(WebPageMembers::class)->current($asset, $member['file_id'])));
    $leftover = $namespace.'/.staging-zip-'.$member['checksum'].'-deadbeef';
    mkdir($leftover.'.unpack/inner', 0755, true);
    mkdir($leftover, 0755);
    file_put_contents($leftover.'/index.html', 'half');
    $job->failed(new RuntimeException('worker killed'));
    expect(glob($namespace.'/*.building'))->toBe([])
        ->and(glob($namespace.'/.staging-*'))->toBe([])
        ->and(glob($namespace.'/zip-*'))->toBe([])
        ->and(collect(app(WebPageMembers::class)->listing($asset))->firstWhere('attachment_id', $slots['three'])['state'])->toBe('none');
    $ask()->assertStatus(409)->assertJson(['state' => 'preparing']);
    Queue::assertPushed(BuildWebMemberBundle::class, 2);
});

it('does not cache an infrastructure fault against the content and builds again on the next selection', function (Throwable $fault) {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['three']];
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $faults = new ArrayObject([$fault]);
    // Only the materializer misbehaves, once; everything else is the real service.
    app()->bind(WebPageBundler::class, fn ($app) => new class($app->make(WebPageInspector::class), $faults) extends WebPageBundler
    {
        public function __construct(WebPageInspector $inspector, public ArrayObject $faults)
        {
            parent::__construct($inspector);
        }

        public function materialize(string $source, string $extension, string $stagingDir): array
        {
            if (count($this->faults) > 0) {
                file_put_contents($stagingDir.'/index.html', 'half-written');

                $fault = $this->faults[0];
                $this->faults->exchangeArray([]);

                throw $fault;
            }

            return parent::materialize($source, $extension, $stagingDir);
        }
    });
    $ask = fn () => $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]));

    // The sync queue runs the failing build inline: still not ready, but also not terminal and nothing left behind.
    $ask()->assertStatus(409)->assertJson(['state' => 'preparing']);
    expect(collect(app(WebPageMembers::class)->listing($asset))->firstWhere('attachment_id', $slots['three'])['state'])->toBe('none')
        ->and(glob($namespace.'/*.building'))->toBe([])
        ->and(glob($namespace.'/.staging-*'))->toBe([])
        ->and(glob($namespace.'/zip-*'))->toBe([]);

    // The fault is gone: the same pin now builds and serves its own page.
    $ask()->assertOk();
    getRawUrl(memberGrantUrl($this, $asset, $member))->assertOk()->assertSee('THIRD-PAGE');
    expect(collect(app(WebPageMembers::class)->listing($asset))->firstWhere('attachment_id', $slots['three'])['state'])->toBe('ready');
})->with([
    'a full disk'          => fn () => new RuntimeException('No space left on device'),
    'a failed second open' => fn () => new RuntimeException('invalid_zip'),
    'a php error'          => fn () => new ErrorException('mkdir(): Permission denied'),
]);

it('does not cache a blob that is briefly unavailable and builds the same pin once the bytes are back', function (string $unavailable) {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['three']];
    $object = app(WebPageMembers::class)->current($asset, $member['file_id'])->fileObject;
    $disk = Storage::disk($object->disk);
    $bytes = $disk->get($object->path);
    $namespace = $disk->path('bundles/'.$asset->id.'/m');
    $ask = fn () => $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $member['file_id'], 'checksum' => $member['checksum']]));

    // The storage volume is away (blob missing) or holds the wrong bytes (size mismatch, read as unreadable): the sync queue
    // runs the build inline, which finds nothing it may cache against this checksum.
    $unavailable === 'missing' ? $disk->delete($object->path) : $disk->put($object->path, $bytes.'-truncated-by-a-flaky-mount');
    $ask()->assertStatus(409)->assertJson(['state' => 'preparing']);
    expect(collect(app(WebPageMembers::class)->listing($asset))->firstWhere('attachment_id', $slots['three'])['state'])->toBe('none')
        ->and(glob($namespace.'/*.building'))->toBe([])
        ->and(glob($namespace.'/.staging-*'))->toBe([])
        ->and(glob($namespace.'/zip-*'))->toBe([]);

    // The volume is back with the same bytes: the same pin (same file id, same checksum) builds and serves its own page.
    $disk->put($object->path, $bytes);
    $ask()->assertOk();
    getRawUrl(memberGrantUrl($this, $asset, $member))->assertOk()->assertSee('THIRD-PAGE');
    expect(collect(app(WebPageMembers::class)->listing($asset))->firstWhere('attachment_id', $slots['three'])['state'])->toBe('ready');
})->with(['blob missing' => 'missing', 'blob unreadable' => 'wrong size']);

it('discards queued work whose slot was revised, moved or removed before it ran and recovers a marker stranded past the job timeout', function () {
    Queue::fake();
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $service = app(WebPageMembers::class);
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $pin = fn (int $slot) => $service->pin($asset, $service->current($asset, $members[$slot]['file_id']));

    $revisedPin = $pin($slots['two']);
    memberRevise($asset, $slots['two'], memberZip(['index.html' => '<p>SECOND-REVISED</p>']));
    $reroledPin = $pin($slots['three']);
    DB::table('dam_attachments')->where('id', $slots['three'])->update(['role' => AssetFile::ROLE_THUMBNAIL]);
    $removedPin = $pin($slots['four']);
    AssetFile::query()->where('attachment_id', $slots['four'])->delete();

    foreach ([$revisedPin, $reroledPin, $removedPin] as $stalePin) {
        expect($service->build($stalePin))->toBe('stale');
    }
    expect(glob($namespace.'/*'))->toBe([]);

    // A marker older than the job's own timeout is recovered; a fresh one is respected.
    $member = $members[$slots['two']];
    $revisedMember = collect($service->listing($asset->fresh()))->firstWhere('attachment_id', $slots['two']);
    $marker = $namespace.'/zip-'.$revisedMember['checksum'].'.building';
    mkdir($namespace, 0755, true);
    touch($marker);
    $ask = fn () => $this->getJson(route('admin.dam.pages.grant', ['id' => $asset->id, 'file' => $revisedMember['file_id'], 'checksum' => $revisedMember['checksum']]));
    $ask()->assertStatus(409);
    Queue::assertNothingPushed();
    touch($marker, time() - BuildWebMemberBundle::TIMEOUT - 5);
    $ask()->assertStatus(409);
    Queue::assertPushed(BuildWebMemberBundle::class, 1);
    expect($member['checksum'])->not->toBe($revisedMember['checksum']);
});

it('opens full screen on exactly the pinned file and refuses a malformed, partial or non-current pin', function () {
    $this->loginAsAdmin();
    [$asset, $slots, $members] = memberStack($this);
    $member = $members[$slots['three']];
    $show = fn (array $query) => $this->get(route('admin.dam.pages.show', ['id' => $asset->id] + $query));

    $show([])->assertOk()->assertDontSee(':file-id=', false);
    $show(['file' => $member['file_id'], 'checksum' => $member['checksum']])->assertOk()
        ->assertSee(':file-id="'.$member['file_id'].'"', false)->assertSee('checksum="'.$member['checksum'].'"', false)
        // The heading and the frame title name the pinned file, not the stack's representative.
        ->assertSee('data-web-page-heading>'.$member['name'].'</p>', false)->assertSee('file-name="'.$member['name'].'"', false)
        ->assertDontSee('data-web-page-heading>'.$asset->file_name.'</p>', false);
    expect($member['name'])->toBe('three.zip')->not->toBe($asset->file_name);
    $show([])->assertSee('data-web-page-heading>'.($asset->title ?: $asset->file_name).'</p>', false);
    // A pin from an older revision still renders the frame, which then says the file changed; it never swaps files.
    $show(['file' => $member['file_id'], 'checksum' => str_repeat('a', 64)])->assertOk();
    $show(['file' => $member['file_id']])->assertNotFound();
    $show(['file' => 'x', 'checksum' => $member['checksum']])->assertNotFound();
    $show(['file' => $member['file_id'], 'checksum' => substr($member['checksum'], 0, 16)])->assertNotFound();

    AssetFile::query()->where('id', $member['file_id'])->update(['is_current' => false]);
    $show(['file' => $member['file_id'], 'checksum' => $member['checksum']])->assertNotFound();
});
