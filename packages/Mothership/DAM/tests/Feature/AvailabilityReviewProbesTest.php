<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\CaptureGroup;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * P2 S3 review (Codex, 2026-10-07). A member who may open a file's folder (or, under lists, is on its list) but may
 * not manage availability (no dam.asset.update) meets a file in each unavailable state: a draft, one pending review,
 * an archived one, one whose window has not opened, and one whose window has closed. Each answers exactly like a
 * missing id, same status and body, and drops out of every listing, count and aggregate; an available file is the
 * positive control. The owner and editors still see their unavailable files, by design.
 */

function avrAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** Every registered ACL key except the ones named, so no route ACL refuses before access is asked. */
function avrRole(array $except = ['dam.asset.update']): Role
{
    return tickEveryHomeTool(Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->push('sphereseam')->unique()
            ->reject(fn ($key) => in_array($key, $except, true))->values()->all(),
    ]));
}

/** A real PNG on the asset disk, so downloads, thumbnails and zips have bytes to serve. */
function avrImage(string $name, array $attributes = []): Asset
{
    $path = 'assets/Root/'.$name.'-'.Str::lower(Str::random(6)).'.png';
    $image = UploadedFile::fake()->image('x.png', 8, 8);
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($image->getRealPath()));

    return Asset::factory()->create([
        'file_name' => basename($path), 'path' => $path, 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'file_size' => 100,
    ] + $attributes);
}

/** The five ways a file is not out for use (P2 S3), as asset attributes. */
function avrUnavailableStates(): array
{
    return [
        'draft'     => ['availability' => Asset::AVAILABILITY_DRAFT],
        'pending'   => ['availability' => Asset::AVAILABILITY_PENDING_REVIEW],
        'archived'  => ['availability' => Asset::AVAILABILITY_ARCHIVED],
        'scheduled' => ['available_from' => now()->addDay()],
        'expired'   => ['available_until' => now()->subMinute()],
    ];
}

function avrLikeMissing(TestResponse $hidden, TestResponse $missing, string $label, int $status = 404): void
{
    expect($hidden->getStatusCode())->toBe($status, $label)
        ->and($missing->getStatusCode())->toBe($status, $label)
        ->and($hidden->getContent())->toBe($missing->getContent(), $label);
}

/** Lets $member open $assets: their folder under grants; under lists, the folder and each file's own list. */
function avrGrant(Admin $member, Directory $folder, array $assets): void
{
    if (app(AccessModel::class)->lists()) {
        DB::table('dam_directory_user_access')->insertOrIgnore(['directory_id' => $folder->id, 'admin_id' => $member->id]);
        foreach ($assets as $asset) {
            DB::table('dam_asset_user_access')->insertOrIgnore(['asset_id' => $asset->id, 'admin_id' => $member->id]);
        }
    } else {
        DB::table('dam_directory_role')->insertOrIgnore(['directory_id' => $folder->id, 'role_id' => $member->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    app(DirectoryPermissionService::class)->flush();
}

function avrBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
        $base instanceof StreamedResponse   => (string) $response->streamedContent(),
        default                             => (string) $base->getContent(),
    };
}

/** @return list<string> */
function avrZipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'avr-zip-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $names;
}

/** The relative form of a signed URL, which the test client can follow. */
function avrRelative(string $url): string
{
    return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    avrAccessModel(AccessModel::GRANTS);
    config(['app.debug' => false]);

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->root = $root;
    $this->granted = Directory::factory()->create(['name' => 'Review granted folder', 'parent_id' => $root->id]);
    $this->member = Admin::factory()->create(['status' => 1, 'role_id' => avrRole()->id]);

    // created_by stays null: the member owns none of them, so ownership never opens one.
    $this->visible = avrImage('review-visible');
    $this->refused = collect(avrUnavailableStates())->map(fn (array $state, string $case) => avrImage('review-'.$case, $state))->all();
    $this->granted->assets()->attach([$this->visible->id, ...collect($this->refused)->map->id->values()->all()]);

    $this->missingId = Asset::max('id') + 1000;
});

it('answers a draft, pending, archived, scheduled or expired file like a missing one on downloads, previews and thumbnails', function (string $model) {
    avrAccessModel($model);
    avrGrant($this->member, $this->granted, [$this->visible, ...array_values($this->refused)]);
    $this->actingAs($this->member, 'admin');

    $byId = [
        'admin.dam.assets.download'            => fn (int $id) => $this->get(route('admin.dam.assets.download', $id)),
        'admin.dam.assets.download_compressed' => fn (int $id) => $this->get(route('admin.dam.assets.download_compressed', $id)),
        'admin.dam.assets.custom_download'     => fn (int $id) => $this->get(route('admin.dam.assets.custom_download', ['id' => $id]).'?format=png'),
    ];
    $byPath = [
        'admin.dam.file.thumbnail' => fn (string $path) => $this->get(route('admin.dam.file.thumbnail', ['path' => $path]), ['Accept' => 'image/*']),
        'admin.dam.file.preview'   => fn (string $path) => $this->get(route('admin.dam.file.preview', ['path' => $path]), ['Accept' => 'image/*']),
    ];

    foreach ($this->refused as $case => $asset) {
        foreach ($byId as $route => $send) {
            avrLikeMissing($send($asset->id), $send($this->missingId), "{$model} {$route} {$case}");
        }
        foreach ($byPath as $route => $send) {
            avrLikeMissing($send($asset->path), $send('assets/Root/nothing-here.png'), "{$model} {$route} {$case}");
        }
    }

    // Positive control: the same reads open the available file.
    foreach ($byId as $route => $send) {
        expect($send($this->visible->id)->getStatusCode())->toBe(200, "{$model} {$route} visible");
    }
    foreach ($byPath as $route => $send) {
        expect($send($this->visible->path)->getStatusCode())->toBe(200, "{$model} {$route} visible");
    }
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('leaves a draft, pending, archived, scheduled or expired file out of a folder zip', function (string $model) {
    avrAccessModel($model);
    avrGrant($this->member, $this->granted, [$this->visible, ...array_values($this->refused)]);
    $this->actingAs($this->member, 'admin');

    $entries = avrZipEntries(avrBody($this->get(route('admin.dam.directory.zip_download', $this->granted->id))->assertOk()));

    expect(collect($entries)->filter(fn (string $entry) => str_contains($entry, $this->visible->file_name))->count())->toBe(1, $model);
    foreach ($this->refused as $case => $asset) {
        expect(collect($entries)->filter(fn (string $entry) => str_contains($entry, $asset->file_name))->all())->toBe([], "{$model} zip {$case}");
    }
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('leaves an unavailable file out of the explorer listing and answers it as a Find-similar seed like a missing one', function (string $model) {
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);
    avrAccessModel($model);
    avrGrant($this->member, $this->granted, [$this->visible, ...array_values($this->refused)]);
    foreach ([$this->visible, ...array_values($this->refused)] as $asset) {
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'text' => 'review', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($this->member, 'admin');

    $listing = $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $this->granted->id]))->assertOk();
    expect(collect($listing->json('assets'))->pluck('id')->all())->toBe([$this->visible->id], $model)
        ->and($listing->json('meta.total_assets'))->toBe(1, $model);

    $similar = fn (int $id) => $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'similar_to' => $id]));
    foreach ($this->refused as $case => $asset) {
        avrLikeMissing($similar($asset->id), $similar($this->missingId), "{$model} similar_to {$case}");
    }

    // The available seed works, and ranks no unavailable file.
    $found = $similar($this->visible->id)->assertOk();
    expect(array_intersect(collect($found->json('assets'))->pluck('id')->all(), collect($this->refused)->map->id->all()))->toBe([]);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('opens a window at its start instant and closes it at its end instant, for one file and in listings', function () {
    avrGrant($this->member, $this->granted, []);
    $now = now()->startOfSecond();
    $this->travelTo($now);

    $opensNow = avrImage('review-opens-now', ['available_from' => $now->copy()]);
    $opensNext = avrImage('review-opens-next', ['available_from' => $now->copy()->addSecond()]);
    $closesNow = avrImage('review-closes-now', ['available_until' => $now->copy()]);
    $closesNext = avrImage('review-closes-next', ['available_until' => $now->copy()->addSecond()]);
    $this->granted->assets()->attach([$opensNow->id, $opensNext->id, $closesNow->id, $closesNext->id]);
    // Signed in only now: a file created while signed in is the creator's own, and an owner sees it in any state.
    $this->actingAs($this->member, 'admin');

    $download = fn (Asset $asset) => $this->get(route('admin.dam.assets.download', $asset->id))->getStatusCode();
    $listed = fn () => collect($this->getJson(route('admin.dam.explorer.index', ['directory_id' => $this->granted->id, 'per_page' => 250]))->assertOk()->json('assets'))->pluck('id')->all();

    // At the instant: from is inclusive, until is exclusive.
    expect($download($opensNow))->toBe(200)->and($download($opensNext))->toBe(404)
        ->and($download($closesNow))->toBe(404)->and($download($closesNext))->toBe(200);
    expect($listed())->toContain($opensNow->id, $closesNext->id)->not->toContain($opensNext->id)->not->toContain($closesNow->id);

    // One second on, the later boundaries pass too.
    $this->travelTo($now->copy()->addSecond());
    expect($download($opensNext))->toBe(200)->and($download($closesNext))->toBe(404);
    expect($listed())->toContain($opensNow->id, $opensNext->id)->not->toContain($closesNow->id)->not->toContain($closesNext->id);
});

it('re-checks a REST download link when it is used, so one issued before the file became unavailable answers like a missing file', function () {
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);
    avrGrant($caller, $this->granted, []);
    $issue = fn (int $id) => $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', $id));
    $missingUrl = avrRelative(URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(10), ['id' => $this->missingId, 'by' => $caller->id]));

    // The REST download itself answers an unavailable file like a missing one.
    foreach ($this->refused as $case => $asset) {
        avrLikeMissing($issue($asset->id), $issue($this->missingId), "admin.api.dam.assets.download {$case}");
    }

    // Files someone else owns: one created while the caller is signed in would otherwise be the caller's own.
    $owner = Admin::factory()->create(['status' => 1]);
    foreach (avrUnavailableStates() as $case => $state) {
        $asset = avrImage('review-later-'.$case, ['created_by' => $owner->id]);
        $this->granted->assets()->attach($asset->id);
        app(DirectoryPermissionService::class)->flush();
        $url = avrRelative($issue($asset->id)->assertOk()->json('data.download_url'));
        expect($url)->toContain('by='.$caller->id);
        $this->get($url)->assertOk();

        $asset->update($state);

        avrLikeMissing($this->get($url), $this->get($missingUrl), "signed download after {$case}");
    }

    // A window that closes between issue and use.
    $closing = avrImage('review-closing', ['available_until' => now()->addMinutes(5), 'created_by' => $owner->id]);
    $this->granted->assets()->attach($closing->id);
    $url = avrRelative($issue($closing->id)->assertOk()->json('data.download_url'));
    $this->travel(6)->minutes();
    avrLikeMissing($this->get($url), $this->get(avrRelative(URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(10), ['id' => $this->missingId, 'by' => $caller->id]))), 'signed download after the window closed');
    $this->travelBack();

    // A link signed without an issuer names nobody, so the file must be out for everyone.
    $legacy = fn (int $id) => $this->get(avrRelative(URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(10), ['id' => $id])));
    avrLikeMissing($legacy($this->refused['draft']->id), $legacy($this->missingId), 'issuer-less signed download of a draft');
    $legacy($this->visible->id)->assertOk();

    // An editor's link to a draft keeps working: editors see unavailable files.
    $editor = Admin::factory()->create(['status' => 1, 'role_id' => avrRole(except: [])->id]);
    avrGrant($editor, $this->granted, []);
    $this->get(avrRelative(URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(10), ['id' => $this->refused['draft']->id, 'by' => $editor->id])))->assertOk();
});

it('leaves links to unavailable files out of the Shared Links page and REST share list for a custom role that reaches every folder', function () {
    $owner = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $this->member->role_id], ['all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(DirectoryPermissionService::class)->flush();
    $shares = collect([...$this->refused, 'visible' => $this->visible])->map(fn (Asset $asset) => Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id, 'name' => null]));
    $folderShare = Share::factory()->forDirectory($this->granted->id)->create(['created_by' => $owner->id, 'name' => null]);
    $expected = collect([$shares['visible']->id, $folderShare->id])->sort()->values()->all();

    $this->actingAs($this->member, 'admin');
    expect(app(DirectoryPermissionService::class)->bypass())->toBeTrue();

    $page = $this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    expect(collect($page->json('records'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe($expected);
    foreach ($this->refused as $case => $asset) {
        expect(str_contains($page->getContent(), $asset->file_name))->toBeFalse("shared-links.index names {$case}");
    }

    // The same role through the REST API.
    $headers = $this->getAuthenticationHeaders();
    Admin::latest('id')->first()->update(['role_id' => $this->member->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $listed = $this->withHeaders($headers)->getJson(route('admin.api.dam.shares.index', ['limit' => 100]))->assertOk();
    expect(collect($listed->json('data'))->pluck('id')->sort()->values()->all())->toBe($expected);

    // Nor may it act on such a link: it answers exactly like a link that does not exist.
    $this->actingAs($this->member, 'admin');
    avrLikeMissing(
        $this->patchJson(route('admin.dam.shared-links.revoke', $shares['draft']->id)),
        $this->patchJson(route('admin.dam.shared-links.revoke', Share::max('id') + 1000)),
        'shared-links.revoke on a link to a draft',
    );
    expect($shares['draft']->fresh()->revoked_at)->toBeNull();

    // An editor with the same folder bypass still lists every link.
    $editorRole = avrRole(except: []);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $editorRole->id], ['all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['status' => 1, 'role_id' => $editorRole->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(collect($this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('records'))->count())
        ->toBe($shares->count() + 1);
});

it('copies a single file with its availability and window, and refuses a source that is not out for the copier', function () {
    avrGrant($this->member, $this->granted, []);
    $target = Directory::factory()->create(['name' => 'Review copy target', 'parent_id' => $this->root->id]);
    avrGrant($this->member, $target, []);
    $this->actingAs($this->member, 'admin');

    // The owner may copy their own unavailable file; the copy is exactly as unavailable as its source.
    foreach (avrUnavailableStates() as $case => $state) {
        $source = avrImage('review-own-'.$case, $state + ['created_by' => $this->member->id]);
        $this->granted->assets()->attach($source->id);

        $copyId = $this->postJson(route('admin.dam.explorer.copy.asset'), ['asset_id' => $source->id, 'target_directory_id' => $target->id])
            ->assertCreated()->json('asset.id');
        $copy = Asset::findOrFail($copyId);

        expect(Asset::availabilityOf($copy))->toBe(Asset::availabilityOf($source->fresh()), "copy of {$case}")
            ->and($copy->unavailableBecause())->toBe($source->fresh()->unavailableBecause(), "copy of {$case}");
    }

    // Someone else's unavailable file fails exactly as a missing one does.
    foreach ($this->refused as $case => $asset) {
        avrLikeMissing(
            $this->postJson(route('admin.dam.explorer.copy.asset'), ['asset_id' => $asset->id, 'target_directory_id' => $target->id]),
            $this->postJson(route('admin.dam.explorer.copy.asset'), ['asset_id' => $this->missingId, 'target_directory_id' => $target->id]),
            "copy.asset {$case}",
            status: 422,
        );
    }
});

it('answers a linked-resource mapping on an unavailable file like a missing mapping on the REST API', function () {
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);
    avrGrant($caller, $this->granted, []);
    $mapping = fn (Asset $asset) => DB::table('dam_asset_resource_mappings')->insertGetId(['type' => 'category', 'related_field' => 'review-'.$asset->id, 'dam_asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now()]);
    $read = fn (int $id) => $this->withHeaders($headers)->getJson(route('admin.api.dam.linked_resource.get', $id));
    $missing = (int) DB::table('dam_asset_resource_mappings')->max('id') + 1000;

    foreach ($this->refused as $case => $asset) {
        avrLikeMissing($read($mapping($asset)), $read($missing), "linked_resource.get {$case}");
    }
    $read($mapping($this->visible))->assertOk()->assertJsonPath('data.dam_asset_id', $this->visible->id);
});

it('answers the Share dialog access read and the move preview for an unavailable listed file like a missing one under lists', function () {
    avrAccessModel(AccessModel::LISTS);
    avrGrant($this->member, $this->granted, [$this->visible, ...array_values($this->refused)]);
    $target = Directory::factory()->create(['name' => 'Review move target', 'parent_id' => $this->root->id]);
    avrGrant($this->member, $target, []);
    $this->actingAs($this->member, 'admin');

    $show = fn (int $id) => $this->getJson(route('admin.dam.access.show', ['type' => 'asset', 'id' => $id]));
    $preview = fn (int $id) => $this->getJson(route('admin.dam.access.move_preview', ['type' => 'asset', 'ids' => [$id], 'target' => $target->id]));

    foreach ($this->refused as $case => $asset) {
        avrLikeMissing($show($asset->id), $show($this->missingId), "access.show {$case}");
        avrLikeMissing($preview($asset->id), $preview($this->missingId), "access.move_preview {$case}");
    }

    $show($this->visible->id)->assertOk();
    $preview($this->visible->id)->assertOk();
});

it('counts only files the viewer can list in tag counts, the relationship and file badges and the folder delete answer', function () {
    Bus::fake();
    avrGrant($this->member, $this->granted, []);
    $refusedIds = collect($this->refused)->map->id->values()->all();

    // Tags: one on unavailable files only, one also on the available file.
    $onlyUnavailable = Tag::query()->create(['name' => 'review-only-unavailable']);
    $onlyUnavailable->assets()->attach($refusedIds);
    $mixed = Tag::query()->create(['name' => 'review-mixed']);
    $mixed->assets()->attach([$this->visible->id, ...$refusedIds]);

    // Relationships both ways between the available file, a second available file and every unavailable one.
    $other = avrImage('review-other');
    $this->granted->assets()->attach($other->id);
    AssetRelationship::create(['from_asset_id' => $this->visible->id, 'to_asset_id' => $other->id, 'type' => AssetRelationship::TYPE_ALTERNATE_OF]);
    foreach ($refusedIds as $id) {
        AssetRelationship::create(['from_asset_id' => $this->visible->id, 'to_asset_id' => $id, 'type' => AssetRelationship::TYPE_ALTERNATE_OF]);
        AssetRelationship::create(['from_asset_id' => $id, 'to_asset_id' => $this->visible->id, 'type' => AssetRelationship::TYPE_DERIVED_FROM]);
    }

    // A capture group: the available file is the primary, a draft and an expired file are companions.
    $profile = CaptureProfile::create(['name' => 'Review capture', 'enabled' => true, 'extensions' => ['png'], 'priority' => ['png']]);
    $group = CaptureGroup::create(['profile_id' => $profile->id, 'primary_asset_id' => $this->visible->id]);
    Asset::whereIn('id', [$this->visible->id, $this->refused['draft']->id, $this->refused['expired']->id])->update(['capture_group_id' => $group->id]);

    $this->actingAs($this->member, 'admin');

    $tags = collect($this->getJson(route('admin.dam.tags.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('records'))->keyBy('name');
    expect((int) $tags['review-only-unavailable']['assets_count'])->toBe(0)
        ->and((int) $tags['review-mixed']['assets_count'])->toBe(1);

    $badges = $this->getJson(route('admin.dam.assets.show', $this->visible->id))->assertOk()->json('badgeCounts');
    $relationships = $this->getJson(route('admin.dam.assets.relationships.index', $this->visible->id))->assertOk();
    $listedRelationships = collect($relationships->json('outgoing'))->flatten(1)->count() + collect($relationships->json('incoming'))->flatten(1)->count();
    $files = $this->getJson(route('admin.dam.assets.files.index', $this->visible->id))->assertOk()->json('files');
    expect($badges['relationships'])->toBe(1)->toBe($listedRelationships)
        ->and($badges['files'])->toBe(count($files))
        ->and(collect($files)->pluck('asset_id')->unique()->values()->all())->not->toContain($this->refused['draft']->id);

    // Deleting a subfolder answers with its parent for the tree to redraw: only what the tree lists.
    $hiddenChild = Directory::factory()->create(['name' => 'Review hidden child', 'parent_id' => $this->granted->id]);
    $doomed = Directory::factory()->create(['name' => 'Review doomed', 'parent_id' => $this->granted->id, 'created_by' => $this->member->id]);
    avrGrant($this->member, $doomed, []);
    $parent = $this->deleteJson(route('admin.dam.directory.destroy', $doomed->id))->assertOk()->json('data');
    expect(collect($parent['assets'])->pluck('id')->sort()->values()->all())->toBe(collect([$this->visible->id, $other->id])->sort()->values()->all())
        ->and(collect($parent['children'])->pluck('id')->all())->not->toContain($hiddenChild->id);
});

it('closes a StylePress reference or vectorization result once its Library source is no longer out for the viewer', function () {
    Storage::fake('local');
    Storage::fake('private');
    config(['stylepress.enabled' => true, 'stylepress.import_root' => storage_path('framework/testing/stylepress-none-'.Str::uuid())]);
    avrGrant($this->member, $this->granted, []);
    $uuid = fn () => (string) Str::uuid();
    $reference = function (?int $assetId) use ($uuid) {
        $id = $uuid();
        Storage::disk('local')->put('stylepress/references/'.$id.'.png', 'reference-'.$id);
        DB::table('dam_stylepress_references')->insert(['id' => $id, 'admin_id' => $this->member->id, 'path' => 'stylepress/references/'.$id.'.png', 'name' => 'r.png', 'sha256' => str_repeat('a', 64), 'asset_id' => $assetId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $vectorization = function (?int $assetId) use ($uuid) {
        $id = $uuid();
        DB::table('dam_vectorizations')->insert(['id' => $id, 'admin_id' => $this->member->id, 'request_id' => $id, 'input_sha' => str_repeat('b', 64), 'input_path' => 'vectorizations/input/'.$id, 'charged' => 1, 'status' => 'completed', 'source_asset_id' => $assetId, 'output_format' => 'svg', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('private')->put('vectorizations/results/'.$id.'/result.svg', '<svg/>');

        return $id;
    };

    // Each taken from a file while it was available; the file then stops being out for use.
    $rows = [];
    foreach (avrUnavailableStates() as $case => $state) {
        $source = avrImage('review-source-'.$case);
        $this->granted->assets()->attach($source->id);
        $rows[$case] = ['reference' => $reference($source->id), 'vectorization' => $vectorization($source->id), 'source' => $source, 'state' => $state];
    }
    $upload = $reference(null);
    $standaloneVectorization = $vectorization(null);
    $orphan = $reference($this->missingId);
    $visibleReference = $reference($this->visible->id);
    $visibleVectorization = $vectorization($this->visible->id);

    $this->actingAs($this->member, 'admin');
    $missing = $uuid();
    foreach ($rows as $case => $row) {
        $this->get(route('admin.home.stylepress.reference-file', $row['reference']))->assertOk();
        $row['source']->update($row['state']);

        avrLikeMissing($this->get(route('admin.home.stylepress.reference-file', $row['reference'])), $this->get(route('admin.home.stylepress.reference-file', $missing)), "stylepress.reference-file {$case}");
        avrLikeMissing($this->getJson(route('admin.home.vectorization.show', $row['vectorization'])), $this->getJson(route('admin.home.vectorization.show', $missing)), "vectorization.show {$case}");
        avrLikeMissing($this->get(route('admin.home.vectorization.file', ['id' => $row['vectorization'], 'format' => 'svg'])), $this->get(route('admin.home.vectorization.file', ['id' => $missing, 'format' => 'svg'])), "vectorization.file {$case}");
    }

    expect(collect($this->getJson(route('admin.home.vectorization.state'))->assertOk()->json('results'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$standaloneVectorization, $visibleVectorization])->sort()->values()->all());

    // An upload, a copy whose source is gone, and one whose source is still out stay readable.
    foreach (['upload' => $upload, 'orphan' => $orphan, 'visible' => $visibleReference] as $case => $id) {
        expect($this->get(route('admin.home.stylepress.reference-file', $id))->getStatusCode())->toBe(200, "reference {$case}");
    }
    $this->get(route('admin.home.vectorization.file', ['id' => $visibleVectorization, 'format' => 'svg']))->assertOk();
    $this->get(route('admin.home.vectorization.file', ['id' => $standaloneVectorization, 'format' => 'svg']))->assertOk();
});

it('answers every change to a link whose target the actor cannot see like a missing link, the link creator included', function (string $model) {
    avrAccessModel($model);
    $fullAdmin = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]);
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);

    // A file hidden by its folder or list, and a folder the actors cannot open, beside the unavailable files.
    $hiddenFolder = Directory::factory()->create(['name' => 'Review share hidden folder', 'parent_id' => $this->root->id]);
    $folderHidden = avrImage('review-share-folder-hidden', ['created_by' => $fullAdmin->id]);
    $hiddenFolder->assets()->attach($folderHidden->id);
    foreach ([$this->member, $caller] as $actor) {
        // Listed on the unavailable files too, so under lists only availability hides them.
        avrGrant($actor, $this->granted, [$this->visible, ...array_values($this->refused)]);
    }

    // Links each actor made itself, to targets it can no longer see: a creator who lost sight of the target.
    $linksBy = fn (Admin $actor) => collect([...$this->refused, 'folder-hidden file' => $folderHidden])
        ->map(fn (Asset $asset) => Share::factory()->forAsset($asset->id)->create(['created_by' => $actor->id, 'name' => 'kept']))
        ->put('hidden folder', Share::factory()->forDirectory($hiddenFolder->id)->create(['created_by' => $actor->id, 'name' => 'kept']));
    $webLinks = $linksBy($this->member);
    $restLinks = $linksBy($caller);
    $missing = Share::max('id') + 1000;

    $web = fn (int $id): array => [
        'update'      => ['PATCH', route('admin.dam.shared-links.update', $id), ['name' => 'changed']],
        'revoke'      => ['PATCH', route('admin.dam.shared-links.revoke', $id), []],
        'reauthorize' => ['PATCH', route('admin.dam.shared-links.reauthorize', $id), ['public_link_confirmed' => true, 'no_expiry' => true]],
        'destroy'     => ['DELETE', route('admin.dam.shared-links.destroy', $id), []],
    ];
    $rest = fn (int $id): array => [
        'show'        => ['GET', route('admin.api.dam.shares.show', $id), []],
        'update'      => ['PUT', route('admin.api.dam.shares.update', $id), ['name' => 'changed']],
        'revoke'      => ['POST', route('admin.api.dam.shares.revoke', $id), []],
        'reauthorize' => ['POST', route('admin.api.dam.shares.reauthorize', $id), ['no_expiry' => true]],
        'destroy'     => ['DELETE', route('admin.api.dam.shares.destroy', $id), []],
    ];

    $this->actingAs($this->member, 'admin');
    foreach ($webLinks as $case => $share) {
        foreach ($web($share->id) as $name => $request) {
            avrLikeMissing($this->json(...$request), $this->json(...$web($missing)[$name]), "{$model} web {$name} {$case}");
        }
    }
    foreach ($restLinks as $case => $share) {
        foreach ($rest($share->id) as $name => $request) {
            avrLikeMissing($this->withHeaders($headers)->json(...$request), $this->withHeaders($headers)->json(...$rest($missing)[$name]), "{$model} REST {$name} {$case}");
        }
    }

    // Nothing was changed.
    foreach ([...$webLinks->all(), ...$restLinks->all()] as $share) {
        expect($share->fresh())->not->toBeNull()
            ->and($share->fresh()->name)->toBe('kept')
            ->and($share->fresh()->revoked_at)->toBeNull();
    }

    // 403 stays for a target the actor can see but may not change the link of: under lists, someone else's link;
    // under grants, a role without the revoke permission.
    $theirs = Share::factory()->forAsset($this->visible->id)->create(['created_by' => $fullAdmin->id]);
    if ($model === AccessModel::LISTS) {
        $this->actingAs($this->member, 'admin');
    } else {
        $noRevoke = Admin::factory()->create(['status' => 1, 'role_id' => avrRole(except: ['dam.asset.update', 'dam.shares.revoke'])->id]);
        avrGrant($noRevoke, $this->granted, []);
        $this->actingAs($noRevoke, 'admin');
    }
    $this->patchJson(route('admin.dam.shared-links.revoke', $theirs->id))->assertForbidden();

    // Positive controls: the creator of a link to a file it can see, an editor's own link to a draft, and a full
    // admin on anyone's link to a draft.
    $this->actingAs($this->member, 'admin');
    $own = Share::factory()->forAsset($this->visible->id)->create(['created_by' => $this->member->id]);
    $this->patchJson(route('admin.dam.shared-links.revoke', $own->id))->assertOk();

    $editor = Admin::factory()->create(['status' => 1, 'role_id' => avrRole(except: [])->id]);
    avrGrant($editor, $this->granted, [$this->refused['draft']]);
    $editorsDraftLink = Share::factory()->forAsset($this->refused['draft']->id)->create(['created_by' => $editor->id]);
    $this->actingAs($editor, 'admin');
    $this->patchJson(route('admin.dam.shared-links.revoke', $editorsDraftLink->id))->assertOk();

    $this->actingAs($fullAdmin, 'admin');
    $this->patchJson(route('admin.dam.shared-links.revoke', $webLinks['draft']->id))->assertOk();
    $this->deleteJson(route('admin.dam.shared-links.destroy', $webLinks['hidden folder']->id))->assertOk();
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('stops a REST download link a member was issued once the file becomes unavailable', function () {
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);
    avrGrant($caller, $this->granted, []);
    $owner = Admin::factory()->create(['status' => 1]);
    $asset = avrImage('review-member-issued', ['created_by' => $owner->id]);
    $this->granted->assets()->attach($asset->id);

    // Issued by a member (no dam.asset.update), who is the only actor named in the signed URL.
    $url = avrRelative($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', $asset->id))->assertOk()->json('data.download_url'));
    $this->get($url)->assertOk();

    $asset->update(['availability' => Asset::AVAILABILITY_DRAFT]);
    $missingUrl = avrRelative(URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(10), ['id' => $this->missingId, 'by' => $caller->id]));
    avrLikeMissing($this->get($url), $this->get($missingUrl), 'member-issued signed download after the file became a draft');
});

it('streams a REST download link on a disk that signs URLs instead of handing out a blob URL that outlives it', function () {
    $headers = $this->getAuthenticationHeaders();
    $caller = Admin::latest('id')->first();
    $caller->update(['role_id' => $this->member->role_id]);
    avrGrant($caller, $this->granted, []);
    $asset = avrImage('review-s3-stream');
    $this->granted->assets()->attach($asset->id);
    app(DirectoryPermissionService::class)->flush();

    $object = app(ContentStore::class)->current($asset);
    $bytes = Storage::disk($object->disk)->get($object->path);
    $object->forceFill(['disk' => 's3-content'])->save();
    $s3 = Mockery::mock(FilesystemAdapter::class);
    $s3->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $s3->shouldReceive('providesTemporaryUrls')->andReturn(true);
    $s3->shouldReceive('exists')->with($object->path)->andReturn(true);
    $s3->shouldReceive('size')->with($object->path)->andReturn(strlen($bytes));
    $s3->shouldReceive('readStream')->with($object->path)->andReturnUsing(function () use ($bytes) {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    });
    // A blob URL minted when the link is used would live its own 5 minutes past the link's 10.
    $s3->shouldNotReceive('temporaryUrl');
    Storage::set('s3-content', $s3);

    $url = avrRelative($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', $asset->id))->assertOk()->json('data.download_url'));
    $response = $this->get($url)->assertOk();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe($bytes);
});
