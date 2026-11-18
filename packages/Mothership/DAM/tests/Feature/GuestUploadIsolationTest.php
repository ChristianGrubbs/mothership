<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\User\Models\Admin;

/*
 * P5a (D1): what a guest's upload link opens, and what it does not. The sweep is driven from the route inventory
 * (tests/Fixtures/route-inventory.php), so a route added later is swept without anyone listing it here.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    config(['dam.explorer.upload.chunk_bytes' => 4]);
});

/** Every route the inventory classifies, by name (RouteInventoryTest keeps the inventory equal to the DAM routes). */
function p5aInventoryRoutes(array $inventory): array
{
    return collect($inventory)->mapWithKeys(fn (string $class, string $name) => [$name => Route::getRoutes()->getByName($name)])->filter()->all();
}

/** The URI of `$route` with every parameter filled from what the guest holds: their token, upload, file and folder. */
function p5aGuestUri(RoutingRoute $route, array $held): string
{
    return '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($held) {
        $name = strtolower($match[1]);

        return match (true) {
            str_contains($name, 'token') || $name === 'grant' || $name === 'slug'  => $held['token'],
            $name === 'uuid'                                                       => $held['uuid'],
            str_contains($name, 'directory') || $name === 'folder'                 => (string) $held['folder'],
            $name === 'path'                                                       => $held['path'],
            default                                                                => (string) $held['asset'],
        };
    }, $route->uri()), '/');
}

it('answers the guest\'s token and visit cookie on no route but its own portal', function () {
    $creator = p5aFullAdmin();
    [$request, $token] = p5aRequest(creator: $creator);
    [, $otherToken] = p5aRequest(creator: $creator);
    $visit = p5aVisit($token);
    $upload = p5aUpload($token, $visit, 'guest-secret-name.txt', 'secret guest bytes');
    $asset = Asset::query()->findOrFail($upload->asset_id);
    $held = ['token' => $token, 'uuid' => $upload->uuid, 'asset' => $asset->id, 'folder' => $request->directory_id, 'path' => $asset->path];
    $inventory = require __DIR__.'/../Fixtures/route-inventory.php';
    $login = route('admin.session.create');
    $swept = 0;

    // A guest has no session: whatever an admin's browser held is gone.
    auth()->forgetGuards();

    expect(count(p5aInventoryRoutes($inventory)))->toBe(count($inventory));

    foreach (p5aInventoryRoutes($inventory) as $name => $route) {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $isPortal = $inventory[$name] === 'public-link';

        // The guest's own link is the one place the token works; the same calls on another request's link are swept.
        $uris = $isPortal ? [str_replace($token, $otherToken, p5aGuestUri($route, $held))] : [p5aGuestUri($route, $held)];

        if ($isPortal && in_array($name, ['dam.upload_portal.show', 'dam.upload_portal.unlock'], true)) {
            // Opening another link's page is just a new visit there, owed nothing of this one: checked below.
            continue;
        }

        foreach ($uris as $uri) {
            $response = p5aCall($method, $uri.'?token='.$token, $visit, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            $swept++;

            // A redirect that is not to the admin login (an unknown share's unlock sends it to its landing) is followed
            // once, with the same credentials, and judged by where it lands.
            $location = (string) $response->headers->get('Location');

            if ($response->getStatusCode() === 302 && ! str_starts_with($location, $login)) {
                $response = p5aCall('GET', $location, $visit, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            }

            $status = $response->getStatusCode();

            // No route hands the guest anything: never a 2xx, never a redirect but to the admin login, never a 5xx.
            $redirectsToLogin = $status === 302 && str_starts_with((string) $response->headers->get('Location'), $login);
            // Nor its bytes, nor its name (unless the request itself carried it, as a `{path}` echoed by a 404 does).
            $body = (string) $response->getContent();
            // P1b's public analytics beacon takes events from anyone and returns none; the sweep's body is not a beacon.
            $refusals = $name === 'dam.analytics.ingest' ? [400, 401, 403, 404, 405, 410] : [401, 403, 404, 405, 410];
            expect(in_array($status, $refusals, true) || $redirectsToLogin)->toBeTrue("{$name} {$method} {$uri} answered {$status}")
                ->and(str_contains($body, 'secret guest bytes'))->toBeFalse("{$name} served the guest's bytes")
                ->and(str_contains($uri, 'guest-secret-name') || ! str_contains($body, 'guest-secret-name'))->toBeTrue("{$name} named the guest's file");

            // Every route that takes a link token in its path (share, page grant, another request's portal) answers it
            // like a token it has never seen: 404, or 401 for a portal call that needs that link's own visit.
            if (preg_match('/\{(token|grant)\}/', $route->uri()) === 1) {
                expect($status)->toBe($isPortal ? 401 : 404, "{$name} answered the guest's token with {$status}");
            }
        }
    }

    // Another request's page opens as a new visit there and shows nothing of this one.
    $other = p5aCall('GET', route('dam.upload_portal.show', ['token' => $otherToken]), $visit);
    expect($other->getStatusCode())->toBe(200)
        ->and($other->getContent())->not->toContain('guest-secret-name')
        ->and($swept)->toBe(count($inventory) - 2)
        ->and($asset->fresh()->availability)->toBe(Asset::AVAILABILITY_PENDING_REVIEW);
});

it('never overwrites: a guest file named like one in the intake folder becomes a new entry and the old bytes stay', function () {
    $creator = p5aFullAdmin();
    [$request, $token] = p5aRequest(creator: $creator);
    $this->actingAs($creator, 'admin');
    $folder = Directory::query()->findOrFail($request->directory_id);
    $existing = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'report.txt', 'extension' => 'txt', 'mime_type' => 'text/plain',
        'path'      => Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath().'/report.txt',
    ]));
    $existing->directories()->attach($folder->id);
    putAssetContent($existing->fresh(), 'original staff bytes');
    $before = app(ContentStore::class)->current($existing->fresh());
    $revisions = DB::table('dam_asset_files')->where('dam_asset_id', $existing->id)->count();
    auth()->forgetGuards();

    $upload = p5aUpload($token, p5aVisit($token), 'report.txt', 'guest replacement attempt');
    $after = app(ContentStore::class)->current($existing->fresh());
    $guest = Asset::query()->findOrFail($upload->asset_id);

    expect($upload->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and($guest->id)->not->toBe($existing->id)
        ->and($guest->file_name)->not->toBe('report.txt')
        ->and($after->sha256)->toBe($before->sha256)
        ->and(Storage::disk($after->disk)->get($after->path))->toBe('original staff bytes')
        ->and($existing->fresh()->file_name)->toBe('report.txt')
        ->and(DB::table('dam_asset_files')->where('dam_asset_id', $existing->id)->count())->toBe($revisions)
        ->and($guest->availability)->toBe(Asset::AVAILABILITY_PENDING_REVIEW);
});

it('keeps a pending guest file out of the Library, search and REST for other staff until it is accepted', function (string $model) {
    p5aAccessModel($model);
    $folder = Directory::create(['name' => 'Private intake', 'parent_id' => null]);
    $creator = p7ReviewMember(folders: [$folder], editor: true);
    $colleague = p7ReviewMember(folders: [$folder], editor: true);

    if ($model === AccessModel::LISTS) {
        DB::table('dam_directory_user_access')->insert([['directory_id' => $folder->id, 'admin_id' => $creator->id], ['directory_id' => $folder->id, 'admin_id' => $colleague->id]]);
    }

    [$request, $token] = p5aRequest(folder: $folder, creator: $creator);
    $assetId = (int) p5aUpload($token, p5aVisit($token), 'pendingfile.txt', 'pending bytes')->asset_id;

    $seen = function (Admin $admin) use ($folder, $assetId): array {
        $this->actingAs($admin, 'admin');
        $explorer = collect($this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id]))->assertOk()->json('assets'))->pluck('id')->map(fn ($id) => (int) $id);
        $search = collect($this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id, 'search' => 'pendingfile']))->assertOk()->json('assets'))->pluck('id')->map(fn ($id) => (int) $id);
        $detail = $this->get(route('admin.dam.assets.edit', $assetId))->getStatusCode();
        $headers = p5aApiHeaders($admin);
        $rest = collect($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.index', ['limit' => 100]))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $restShow = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.show', $assetId))->getStatusCode();

        return [
            'explorer' => $explorer->contains($assetId),
            'search'   => $search->contains($assetId),
            'detail'   => $detail,
            'rest'     => $rest->contains($assetId),
            'restShow' => $restShow,
        ];
    };

    expect($seen($colleague))->toBe(['explorer' => false, 'search' => false, 'detail' => 404, 'rest' => false, 'restShow' => 404])
        ->and($seen($creator))->toMatchArray(['explorer' => true, 'detail' => 200, 'restShow' => 200]);

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => [$assetId]])->assertOk();

    expect($seen($colleague))->toMatchArray(['explorer' => true, 'detail' => 200, 'rest' => true, 'restShow' => 200]);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('shows an exact-duplicate notice to staff only, never to the guest', function () {
    $creator = p5aFullAdmin();
    [$request, $token] = p5aRequest(creator: $creator);
    $this->actingAs($creator, 'admin');
    $original = p7ReviewAsset(Directory::query()->findOrFail($request->directory_id), 'document', 'identical bytes');
    auth()->forgetGuards();

    $visit = p5aVisit($token);
    $upload = p5aUpload($token, $visit, 'copy.txt', 'identical bytes');
    $guestView = p5aCall('GET', route('dam.upload_portal.status', ['token' => $token, 'uuid' => $upload->uuid]), $visit)->assertOk();
    $page = p5aCall('GET', route('dam.upload_portal.show', ['token' => $token]), $visit)->assertOk();

    $this->actingAs($creator, 'admin');
    $staff = $this->getJson(route('admin.dam.upload-requests.submissions', $request->id))->assertOk();

    expect($staff->json('submissions.0.files.0.duplicate.match.id'))->toBe($original->id)
        ->and($guestView->getContent())->not->toContain($original->file_name)->not->toContain('duplicate')
        ->and($page->getContent())->not->toContain($original->file_name);
});
