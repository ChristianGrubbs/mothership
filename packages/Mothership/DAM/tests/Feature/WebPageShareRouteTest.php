<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Http\Controllers\WebPageController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Share\SharedFiles;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Services\WebPageMembers;
use Mothership\DAM\Services\WebPagePolicy;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
});

/** A built web bundle whose entry references a sibling, so a wrong base URL breaks visibly. */
function sharedWebAsset(?int $directoryId = null): Asset
{
    $path = tempnam(sys_get_temp_dir(), 'wps').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<title>shared</title><script src="app.js"></script>');
    $zip->addFromString('app.js', 'document.title="x"');
    $zip->close();
    // dam_assets.path is unique, and the subtree tests build more than one bundle per test.
    $name = 'site-'.bin2hex(random_bytes(6)).'.zip';
    $assetPath = 'assets/Root/'.$name;
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put($assetPath, file_get_contents($path));
    $asset = Asset::factory()->create(['file_name' => $name, 'file_type' => 'web', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => $assetPath]);
    $asset->directories()->attach($directoryId ?? 1);
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    return $asset->fresh();
}

/** Without $path this is the slashless bundle root, which the route redirects. */
function sharePageUrl(Share $share, string $path = ''): string
{
    return route('dam.share.page', ['token' => $share->urlToken()]).($path === '' ? '' : '/'.$path);
}

it('serves a shared web page with the public policy, no cookie, entry no-store, siblings cached', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    $entry = getRawUrl(sharePageUrl($share).'/');

    $entry->assertOk()
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('<title>shared</title>', false);
    expect($entry->headers->getCookies())->toBe([]);

    $sibling = $this->get(sharePageUrl($share, 'app.js'));
    $sibling->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public')
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
    expect($sibling->headers->getCookies())->toBe([]);
});

it('keeps the public page policy on a shared sibling when the X-Accel prefix is configured', function () {
    config(['mothership.media_accel_prefix' => '/__dam_media']);
    $share = Share::factory()->forAsset(sharedWebAsset()->id)->create();

    $this->get(sharePageUrl($share, 'app.js'))
        ->assertOk()
        ->assertHeaderMissing('X-Accel-Redirect')
        ->assertHeader('Content-Security-Policy', WebPagePolicy::CSP)
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('redirects the slashless bundle root of an asset share so relative resources stay inside it', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    $redirect = $this->get(sharePageUrl($share));

    $redirect->assertStatus(302);
    expect($redirect->headers->get('Location'))->toEndWith('/page/');

    getRawUrl($redirect->headers->get('Location'))->assertOk()->assertSee('<script src="app.js">', false);
    $this->get(sharePageUrl($share, 'app.js'))->assertOk();
});

it('redirects the slashless bundle root of a directory share, keeping the asset id segment', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forDirectory(1)->create();

    $redirect = $this->get(sharePageUrl($share, (string) $asset->id));

    $redirect->assertStatus(302);
    expect($redirect->headers->get('Location'))->toEndWith('/page/'.$asset->id.'/');

    getRawUrl($redirect->headers->get('Location'))->assertOk()->assertSee('<script src="app.js">', false);
    $this->get(sharePageUrl($share, $asset->id.'/app.js'))->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
});

it('serves only assets inside the shared subtree of a directory share', function () {
    $shared = Directory::create(['name' => 'Shared root', 'parent_id' => null]);
    $child = Directory::create(['name' => 'Shared child', 'parent_id' => $shared->id]);
    $unrelated = Directory::create(['name' => 'Unrelated', 'parent_id' => null]);

    $descendant = sharedWebAsset($child->id);
    $outsider = sharedWebAsset($unrelated->id);
    $share = Share::factory()->forDirectory($shared->id)->create();

    // A descendant of the shared directory is reachable, entry and sibling alike.
    getRawUrl(sharePageUrl($share, $descendant->id.'/'))->assertOk()->assertSee('<title>shared</title>', false);
    $this->get(sharePageUrl($share, $descendant->id.'/app.js'))->assertOk();

    // The same token cannot reach an asset outside the shared subtree.
    getRawUrl(sharePageUrl($share, $outsider->id.'/'))->assertNotFound();
    $this->get(sharePageUrl($share, $outsider->id.'/app.js'))->assertNotFound();
});

it('treats the first segment as a file path, not an asset id, on an asset share token', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    // An asset share is already bound to one asset, so `{assetId}/` is just a bundle path that
    // does not exist -- it must never be read as an asset selector.
    getRawUrl(sharePageUrl($share, $asset->id.'/'))->assertNotFound();
    $this->get(sharePageUrl($share, $asset->id.'/app.js'))->assertNotFound();
});

it('refuses an expired or revoked directory share on the entry and on siblings', function () {
    $shared = Directory::create(['name' => 'Gated root', 'parent_id' => null]);
    $asset = sharedWebAsset($shared->id);

    $expired = Share::factory()->forDirectory($shared->id)->expired()->create();
    getRawUrl(sharePageUrl($expired, $asset->id.'/'))->assertNotFound();
    $this->get(sharePageUrl($expired, $asset->id.'/app.js'))->assertNotFound();

    $revoked = Share::factory()->forDirectory($shared->id)->create();
    getRawUrl(sharePageUrl($revoked, $asset->id.'/'))->assertOk();

    $revoked->update(['revoked_at' => now()]);
    getRawUrl(sharePageUrl($revoked, $asset->id.'/'))->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
    $this->get(sharePageUrl($revoked, $asset->id.'/app.js'))->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
});

it('does not count views on the page route (the wrapper page already does)', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    shareVisitRan($this->get(route('dam.share.show', $share->urlToken()))->assertOk());
    getRawUrl(sharePageUrl($share).'/')->assertOk();
    $this->get(sharePageUrl($share, 'app.js'))->assertOk();

    expect($share->fresh()->view_count)->toBe(1);
});

it('refuses a non-web asset, an inactive share, and stops immediately after revocation', function () {
    $image = Asset::factory()->create();
    getRawUrl(sharePageUrl(Share::factory()->forAsset($image->id)->create()).'/')->assertNotFound();

    $asset = sharedWebAsset();
    $expired = Share::factory()->forAsset($asset->id)->expired()->create();
    getRawUrl(sharePageUrl($expired).'/')->assertNotFound();

    $share = Share::factory()->forAsset($asset->id)->create();
    getRawUrl(sharePageUrl($share).'/')->assertOk();

    $share->update(['revoked_at' => now()]);
    getRawUrl(sharePageUrl($share).'/')->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
    $this->get(sharePageUrl($share, 'app.js'))->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
});

it('rejects traversal on the share route', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(sharePageUrl($share, '../../.env'))->assertNotFound();
    $this->get(sharePageUrl($share, '%2e%2e/%2e%2e/.env'))->assertNotFound();
});

it('embeds the sandboxed iframe on the public asset page', function () {
    $asset = sharedWebAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.show', $share->urlToken()))
        ->assertOk()
        ->assertSee('sandbox="'.WebPagePolicy::SANDBOX.'"', false)
        ->assertSee(sharePageUrl($share).'/', false);
});

/*
 * Selected current-file pages on public shares (ADR 0007 amendment): `pf/{pin}` and, behind a password, `pgf/{grant}/{pin}`
 * carry the full pin so a bundle's relative resources keep it. Every gate runs before a member is looked up or built.
 */

function sharedMemberAttach(object $test, Asset $asset, string $name, string $bytes, string $role): int
{
    return (int) $test->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent($name, $bytes), 'role' => $role,
    ])->assertCreated()->json('attachment_id');
}

/**
 * A representative page plus one other ZIP page and one HTML page, each with its own text, and optionally a `.txt` file that
 * is no page. Returns [asset, members by tag]. `$owner` makes a collection-able asset of one person.
 */
function sharedMemberStack(object $test, ?int $directoryId = null, bool $text = false, ?int $owner = null): array
{
    $test->loginAsAdmin();
    $asset = sharedWebAsset($directoryId);
    $owner === null || $asset->forceFill(['created_by' => $owner])->save();
    $zip = tempnam(sys_get_temp_dir(), 'wsm').'.zip';
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('index.html', '<p>SECOND-PUBLIC</p><script src="app.js"></script>');
    $archive->addFromString('app.js', 'window.second=2');
    $archive->close();
    sharedMemberAttach($test, $asset, 'second.zip', file_get_contents($zip), AssetFile::ROLE_ALTERNATE);
    sharedMemberAttach($test, $asset, 'third.html', '<p>THIRD-PUBLIC</p>', AssetFile::ROLE_OTHER);
    $text && sharedMemberAttach($test, $asset, 'notes.txt', 'plain notes', AssetFile::ROLE_OTHER);
    $rows = collect(app(WebPageMembers::class)->listing($asset->fresh()));
    // Everything after the setup is a visitor with no session at all.
    app('auth')->guard('admin')->forgetUser();
    $test->flushSession();

    return [$asset->fresh(), ['main' => $rows[0], 'second' => $rows[1], 'third' => $rows[2]] + ($text ? ['text' => $rows[3]] : [])];
}

function sharedMemberUrl(Asset $asset, array $member, Share $share, string $path = ''): string
{
    return route('dam.share.page_file', ['token' => $share->urlToken(), 'pin' => $asset->id.'.'.$member['attachment_id'].'.'.$member['file_id'].'.'.$member['checksum']]).'/'.$path;
}

it('serves each shared member from its own pinned page, sandboxed and without capture, download or write access', function () {
    [$asset, $members] = sharedMemberStack($this);
    $share = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $meta = $asset->meta_data;

    $second = getRawUrl(sharedMemberUrl($asset, $members['second'], $share));
    $second->assertOk()->assertSee('SECOND-PUBLIC')->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
    // The global no-cache middleware only knows `dam.share.page` by name, so a member entry carries its stricter
    // rewrite of the same no-store policy; what matters is that it is never stored.
    expect($second->headers->get('Cache-Control'))->toContain('no-store')
        ->and($second->headers->getCookies())->toBe([])
        ->and($second->getContent())->not->toContain(WebPageController::CAPTURE_MARKER);
    getRawUrl(sharedMemberUrl($asset, $members['third'], $share))->assertOk()->assertSee('THIRD-PUBLIC');
    getRawUrl(sharedMemberUrl($asset, $members['main'], $share))->assertOk()->assertSee('<title>shared</title>', false);

    $script = $this->get(sharedMemberUrl($asset, $members['second'], $share, 'app.js'))->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
    expect(file_get_contents($script->baseResponse->getFile()->getPathname()))->toBe('window.second=2')
        ->and($asset->fresh()->meta_data)->toBe($meta);

    // Preview-only: the viewer shows the page and no download for it.
    $viewer = $this->get(route('dam.share.show', $share->urlToken()))->assertOk();
    $viewer->assertSee(str_replace('/', '\\/', sharedMemberUrl($asset, $members['second'], $share)), false)
        ->assertSee('"download_url":null', false)->assertDontSee('"download_url":"', false);
});

it('refuses a shared member for a stale, foreign or malformed pin and for a share that is revoked, expired or for another asset', function () {
    [$asset, $members] = sharedMemberStack($this);
    $share = Share::factory()->forAsset($asset->id)->create();
    $other = Share::factory()->forAsset(sharedWebAsset()->id)->create();
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $pin = fn (array $m, ?string $checksum = null) => $asset->id.'.'.$m['attachment_id'].'.'.$m['file_id'].'.'.($checksum ?? $m['checksum']);
    $url = fn (Share $s, string $p) => route('dam.share.page_file', ['token' => $s->urlToken(), 'pin' => $p]).'/';

    // A prefix or other checksum, another slot's file id, a foreign asset's token, a malformed pin: all 404, nothing built.
    getRawUrl($url($share, $pin($members['second'], substr($members['second']['checksum'], 0, 16))))->assertNotFound();
    getRawUrl($url($share, $pin($members['second'], str_repeat('d', 64))))->assertNotFound();
    getRawUrl($url($share, $asset->id.'.'.$members['second']['attachment_id'].'.'.$members['third']['file_id'].'.'.$members['second']['checksum']))->assertNotFound();
    getRawUrl($url($other, $pin($members['second'])))->assertNotFound();
    getRawUrl($url($share, 'x.y.z.'.$members['second']['checksum']))->assertNotFound();
    expect(is_dir($namespace))->toBeFalse();

    $second = $url($share, $pin($members['second']));
    getRawUrl($second)->assertOk();

    // Revised: the old pin is stale, and the share never silently follows the slot to the new file.
    $bytes = '<p>SECOND-NEW</p>';
    $object = FileObject::factory()->create(['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'disk' => Directory::getAssetDisk(), 'path' => app(ContentStore::class)->objectPath(hash('sha256', $bytes), 'html'), 'mime_type' => 'text/html']);
    Storage::disk(Directory::getAssetDisk())->put($object->path, $bytes);
    app(AttachmentLedger::class)->appendRevision(asset: $asset, attachmentId: $members['second']['attachment_id'], object: $object, note: 'Revised');
    getRawUrl($second)->assertNotFound();
    $this->get($second.'app.js')->assertNotFound();

    $share->update(['revoked_at' => now()]);
    getRawUrl($url($share, $pin($members['third'])))->assertNotFound()->assertHeader('Content-Security-Policy', WebPagePolicy::CSP);
    $expired = Share::factory()->forAsset($asset->id)->expired()->create();
    getRawUrl($url($expired, $pin($members['third'])))->assertNotFound();
});

it('queues nothing and writes no marker for a denied shared member request', function () {
    [$asset, $members] = sharedMemberStack($this);
    Queue::fake();
    $revoked = Share::factory()->forAsset($asset->id)->create(['revoked_at' => now()]);
    $expired = Share::factory()->forAsset($asset->id)->expired()->create();
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');

    foreach ([$revoked, $expired] as $denied) {
        getRawUrl(sharedMemberUrl($asset, $members['second'], $denied))->assertNotFound();
    }
    // A password link without its unlock is refused before the member is even looked up.
    $locked = Share::factory()->forAsset($asset->id)->create();
    $locked->forceFill(['password_hash' => Hash::make('secret-pass-1')])->save();
    getRawUrl(sharedMemberUrl($asset, $members['second'], $locked))->assertStatus(401);

    Queue::assertNothingPushed();
    expect(is_dir($namespace))->toBeFalse();
});

it('answers a locked link 401 with an empty body on both member routes and a preview-only link never 403s them', function () {
    [$asset, $members] = sharedMemberStack($this);
    $previewOnly = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    // Neither route is a download route, so a preview-only link renders the member page.
    expect(ShareGate::DOWNLOAD_ROUTES)->not->toContain('dam.share.page_file', 'dam.share.page_file_granted');
    getRawUrl(sharedMemberUrl($asset, $members['second'], $previewOnly))->assertOk()->assertSee('SECOND-PUBLIC');

    Queue::fake();
    $namespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$asset->id.'/m');
    $before = scandir($namespace);
    $pin = $asset->id.'.'.$members['second']['attachment_id'].'.'.$members['second']['file_id'].'.'.$members['second']['checksum'];
    $locked = Share::factory()->forAsset($asset->id)->create();
    $locked->forceFill(['password_hash' => Hash::make('secret-pass-1')])->save();
    $locked = $locked->fresh();

    // The same URL shapes the access matrix generates: no unlock, and a well-formed grant that does not verify.
    foreach ([
        route('dam.share.page_file', ['token' => $locked->urlToken(), 'pin' => $pin]),
        route('dam.share.page_file_granted', ['token' => $locked->urlToken(), 'grant' => '1.'.str_repeat('0', 64), 'pin' => $pin]),
    ] as $url) {
        $response = getRawUrl($url.'/')->assertStatus(401);
        expect($response->getContent())->toBe('');
    }
    Queue::assertNothingPushed();
    expect(scandir($namespace))->toBe($before);
});

function sharedMemberPin(Asset $asset, array $member): string
{
    return $asset->id.'.'.$member['attachment_id'].'.'.$member['file_id'].'.'.$member['checksum'];
}

/**
 * The container-membership gate of a shared folder or collection: a member inside serves its own content (plain and behind a
 * password grant), the same kind of pin for an asset outside serves nothing and queues and caches nothing, and a file that is no
 * page gets no page url. `$share` is built by the case, `$inside` is in it and `$outside` is not.
 */
function assertContainerMemberPages(object $test, Share $share, Asset $inside, array $members, Asset $outside, array $outsideMembers): void
{
    $token = $share->urlToken();
    $outsideNamespace = Storage::disk(Directory::getAssetDisk())->path('bundles/'.$outside->id.'/m');

    getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => sharedMemberPin($inside, $members['second'])]).'/')
        ->assertOk()->assertSee('SECOND-PUBLIC')->assertDontSee('THIRD-PUBLIC');
    getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => sharedMemberPin($inside, $members['third'])]).'/')
        ->assertOk()->assertSee('THIRD-PUBLIC');
    // From here on nothing may be queued: the inside pages are built, and a denied request must not start a build.
    Queue::fake();

    // A page of an asset the link does not contain answers like a missing one: nothing is built or queued for it.
    $before = Queue::pushedJobs();
    foreach (['main', 'second'] as $tag) {
        getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => sharedMemberPin($outside, $outsideMembers[$tag])]).'/')->assertNotFound();
    }
    // Another asset's id on an inside slot is a different identity, not a way into it.
    getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => $outside->id.'.'.$members['second']['attachment_id'].'.'.$members['second']['file_id'].'.'.$members['second']['checksum']]).'/')->assertNotFound();
    expect(Queue::pushedJobs())->toBe($before)->and(is_dir($outsideNamespace))->toBeFalse();

    // A file that is no page: no page url on its row, and its pin serves nothing.
    $rows = collect(app(SharedFiles::class)->present(share: $share, asset: $inside, token: $token, memberAssetId: $inside->id))->keyBy('file_id');
    expect($rows[$members['text']['file_id']]['page_url'])->toBeNull()
        ->and($rows[$members['second']['file_id']]['page_url'])->toContain('/pf/'.sharedMemberPin($inside, $members['second']));
    getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => sharedMemberPin($inside, $members['text'])]).'/')->assertNotFound();

    // The same behind a password: the page grant opens the inside page only.
    $share->forceFill(['password_hash' => Hash::make('secret-pass-1')])->save();
    $share = $share->fresh();
    $grant = app(ShareGate::class)->pageGrant($share);
    $granted = fn (Asset $asset, array $member, string $g) => route('dam.share.page_file_granted', ['token' => $token, 'grant' => $g, 'pin' => sharedMemberPin($asset, $member)]).'/';
    getRawUrl(route('dam.share.page_file', ['token' => $token, 'pin' => sharedMemberPin($inside, $members['second'])]).'/')->assertStatus(401);
    getRawUrl($granted($inside, $members['second'], $grant))->assertOk()->assertSee('SECOND-PUBLIC');
    getRawUrl($granted($inside, $members['second'], '1.'.str_repeat('0', 64)))->assertStatus(401);
    getRawUrl($granted($outside, $outsideMembers['second'], $grant))->assertNotFound();
    expect(Queue::pushedJobs())->toBe($before)->and(is_dir($outsideNamespace))->toBeFalse();
    expect(collect(app(SharedFiles::class)->present(share: $share, asset: $inside, token: $token, memberAssetId: $inside->id))->pluck('page_url')->filter()->every(fn ($url) => str_contains($url, '/pgf/')))->toBeTrue();
}

it('opens member pages of a shared folder only for assets inside its subtree', function () {
    $folder = Directory::factory()->create();
    [$inside, $members] = sharedMemberStack($this, directoryId: $folder->id, text: true);
    [$outside, $outsideMembers] = sharedMemberStack($this);
    $share = Share::factory()->forDirectory($folder->id)->create();

    assertContainerMemberPages($this, $share, $inside, $members, $outside, $outsideMembers);
});

it('opens member pages of a shared collection only for its servable members', function () {
    require_once __DIR__.'/../Support/CollectionTestHelpers.php';
    p4cUseModel(AccessModel::LISTS);
    $owner = p4cMember(P4C_OWNER_KEYS);
    [$inside, $members] = sharedMemberStack($this, text: true, owner: $owner->id);
    [$outside, $outsideMembers] = sharedMemberStack($this, owner: $owner->id);
    $collection = p4cCollection($owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC]);
    p4cAdd($collection, $owner, [$inside]);
    $share = Share::factory()->create(['share_type' => Share::TYPE_COLLECTION, 'target_id' => $collection->id]);

    assertContainerMemberPages($this, $share, $inside, $members, $outside, $outsideMembers);
});

it('answers a HEAD probe of a shared member page with the gates and identity of a GET and no body', function () {
    [$asset, $members] = sharedMemberStack($this);
    $share = Share::factory()->forAsset($asset->id)->create();
    $url = fn (array $member, ?Share $on = null) => route('dam.share.page_file', ['token' => ($on ?? $share)->urlToken(), 'pin' => sharedMemberPin($asset, $member)]).'/';
    $head = fn (string $target) => TestResponse::fromBaseResponse(app(Kernel::class)->handle(Request::create($target, 'HEAD')));

    $get = getRawUrl($url($members['second']))->assertOk();
    $probe = $head($url($members['second']))->assertOk();
    expect($probe->getContent())->toBe('')
        ->and($probe->headers->get('Content-Security-Policy'))->toBe($get->headers->get('Content-Security-Policy'))
        ->and($probe->headers->get('Cache-Control'))->toBe($get->headers->get('Cache-Control'))
        ->and($probe->headers->getCookies())->toBe([]);

    // Every refusal a GET gives, a HEAD gives: a bad pin, a revoked or expired link, a locked link.
    $head($url($members['second']).'x/../')->assertNotFound();
    $head(route('dam.share.page_file', ['token' => $share->urlToken(), 'pin' => $asset->id.'.'.$members['second']['attachment_id'].'.'.$members['second']['file_id'].'.'.str_repeat('d', 64)]).'/')->assertNotFound();
    $head($url($members['second'], Share::factory()->forAsset($asset->id)->expired()->create()))->assertNotFound();
    $head($url($members['second'], Share::factory()->forAsset($asset->id)->revoked()->create()))->assertNotFound();
    $locked = Share::factory()->forAsset($asset->id)->create();
    $locked->forceFill(['password_hash' => Hash::make('secret-pass-1')])->save();
    expect($head($url($members['second'], $locked))->assertStatus(401)->getContent())->toBe('');
    $granted = route('dam.share.page_file_granted', ['token' => $locked->urlToken(), 'grant' => app(ShareGate::class)->pageGrant($locked->fresh()), 'pin' => sharedMemberPin($asset, $members['second'])]).'/';
    $head($granted)->assertOk();
    $head(route('dam.share.page_file_granted', ['token' => $locked->urlToken(), 'grant' => '1.'.str_repeat('0', 64), 'pin' => sharedMemberPin($asset, $members['second'])]).'/')->assertStatus(401);
});

it('opens a password-protected member page only behind a valid page grant and offers its url on the viewer rows', function () {
    [$asset, $members] = sharedMemberStack($this);
    $share = Share::factory()->forAsset($asset->id)->create();
    $share->forceFill(['password_hash' => Hash::make('secret-pass-1')])->save();
    $share = $share->fresh();
    $grant = app(ShareGate::class)->pageGrant($share);
    $pin = $asset->id.'.'.$members['third']['attachment_id'].'.'.$members['third']['file_id'].'.'.$members['third']['checksum'];
    $granted = fn (string $g) => route('dam.share.page_file_granted', ['token' => $share->urlToken(), 'grant' => $g, 'pin' => $pin]).'/';

    getRawUrl($granted($grant))->assertOk()->assertSee('THIRD-PUBLIC');
    getRawUrl($granted(substr($grant, 0, -1).(str_ends_with($grant, 'a') ? 'b' : 'a')))->assertStatus(401);
    getRawUrl($granted((time() - 1).'.'.str_repeat('0', 64)))->assertStatus(401);

    $rows = app(SharedFiles::class)->present(share: $share, asset: $asset, token: $share->urlToken(), memberAssetId: null);
    expect(collect($rows)->pluck('page_url')->all())->each->toContain('/pgf/');
});

it('gives page urls only to eligible current web members, never to a non-page asset', function () {
    [$asset, $members] = sharedMemberStack($this);
    $share = Share::factory()->forAsset($asset->id)->create();
    $image = Asset::factory()->create();
    $imageShare = Share::factory()->forAsset($image->id)->create();
    $rows = collect(app(SharedFiles::class)->present(share: $share, asset: $asset, token: $share->urlToken(), memberAssetId: null));

    expect($rows->pluck('page_url')->every(fn ($url) => is_string($url) && str_contains($url, '/pf/')))->toBeTrue()
        ->and($rows->pluck('checksum')->all())->toBe(array_column($members, 'checksum'))
        ->and(collect(app(SharedFiles::class)->present(share: $imageShare, asset: $image, token: $imageShare->urlToken(), memberAssetId: null))->pluck('page_url')->filter()->all())->toBe([]);
});

it('never serves or advertises a member page for a merged source, which keeps its historic routes', function () {
    [$destination, $members] = sharedMemberStack($this);
    $this->loginAsAdmin();
    $source = sharedWebAsset();
    $this->postJson(route('admin.dam.assets.merges.store', $destination->id), ['sources' => [$source->id]])->assertCreated();
    $source = $source->fresh();
    $share = Share::factory()->forAsset($source->id)->create();
    app('auth')->guard('admin')->forgetUser();
    $this->flushSession();

    $rows = app(SharedFiles::class)->present(share: $share, asset: $source, token: $share->urlToken(), memberAssetId: null);
    expect($rows)->not->toBeEmpty()->and(collect($rows)->pluck('page_url')->filter()->all())->toBe([]);

    // Neither the source's own pin nor the destination's member pin opens through the source's link.
    getRawUrl(sharedMemberUrl($destination, $members['second'], $share))->assertNotFound();
    $own = collect($rows)->first();
    getRawUrl(route('dam.share.page_file', ['token' => $share->urlToken(), 'pin' => $source->id.'.'.$own['attachment_id'].'.'.$own['file_id'].'.'.$own['checksum']]).'/')->assertNotFound();
    expect(is_dir(Storage::disk(Directory::getAssetDisk())->path('bundles/'.$source->id.'/m')))->toBeFalse();
});
