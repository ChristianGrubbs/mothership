<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Services\UploadRequests\IntakeReview;
use Mothership\User\Models\Admin;

/*
 * P5a (D1): the staff side of upload requests. Creating and managing a request, seeing what guests sent, and the
 * Accept / Reject decision: 404 for a file the actor cannot see, 403 without edit rights on the intake folder,
 * the folder's access list on accept, the trash on reject, one file or many, in the Library and over REST alike.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    config(['dam.explorer.upload.chunk_bytes' => 4]);
});

/** A custom role with every DAM key, as p7ReviewMember, but without `dam.asset.update` unless `$editor`. */
function p5aMember(array $folders, bool $editor): Admin
{
    return p7ReviewMember(folders: $folders, editor: $editor);
}

/**
 * A request by `$creator` with two guest files sent to it.
 *
 * @return array{0: UploadRequest, 1: list<int>}
 */
function p5aWithGuestFiles(Directory $folder, Admin $creator, array $names = ['one.txt', 'two.txt']): array
{
    [$request, $token] = p5aRequest(folder: $folder, creator: $creator);
    $visit = p5aVisit($token);
    $ids = [];

    foreach ($names as $i => $name) {
        $ids[] = (int) p5aUpload($token, $visit, $name, 'guest-'.$i.'-bytes')->asset_id;
    }

    return [$request, $ids];
}

it('lists only the upload requests the viewer may see, in the Library and over REST', function () {
    $mine = Directory::create(['name' => 'Granted intake', 'parent_id' => null]);
    $theirs = Directory::create(['name' => 'Hidden intake', 'parent_id' => null]);
    $member = p5aMember(folders: [$mine], editor: true);
    [$visible] = p5aRequest(folder: $mine);
    [$hidden] = p5aRequest(folder: $theirs);

    $this->actingAs($member, 'admin');
    $web = $this->getJson(route('admin.dam.upload-requests.list'))->assertOk()->json('requests.*.id');
    $this->get(route('admin.dam.upload-requests.show', $hidden->id))->assertNotFound();
    // The pages themselves render (a Blade directive clash once broke the review page while its JSON was fine).
    $this->get(route('admin.dam.upload-requests.index'))->assertOk()->assertSee('data-dam-upload-requests', false);
    $this->get(route('admin.dam.upload-requests.show', $visible->id))->assertOk()->assertSee('v-dam-upload-request-review', false);
    $this->getJson(route('admin.dam.upload-requests.submissions', $hidden->id))->assertNotFound();

    $headers = p5aApiHeaders($member);
    $rest = $this->withHeaders($headers)->getJson(route('admin.api.dam.upload_requests.index'))->assertOk()->json('data.*.id');
    $this->withHeaders($headers)->getJson(route('admin.api.dam.upload_requests.show', $hidden->id))->assertNotFound();
    $missing = $this->withHeaders($headers)->getJson(route('admin.api.dam.upload_requests.show', 999_999))->assertNotFound();

    expect($web)->toBe([$visible->id])
        ->and($rest)->toBe([$visible->id])
        ->and($this->withHeaders($headers)->getJson(route('admin.api.dam.upload_requests.show', $hidden->id))->getContent())->toBe($missing->getContent());
});

it('lists only the submitted files the viewer may see, and counts the rest, in the Library and over REST', function () {
    $folder = Directory::create(['name' => 'Review intake', 'parent_id' => null]);
    $creator = p5aMember(folders: [$folder], editor: true);
    $colleague = p5aMember(folders: [$folder], editor: true);
    [$request, $ids] = p5aWithGuestFiles(folder: $folder, creator: $creator);

    $filesFor = function (Admin $admin) use ($request): array {
        $this->actingAs($admin, 'admin');
        $data = $this->getJson(route('admin.dam.upload-requests.submissions', $request->id))->assertOk()->json();
        $rest = $this->withHeaders(p5aApiHeaders($admin))->getJson(route('admin.api.dam.upload_requests.submissions', $request->id))->assertOk()->json('data');
        $files = collect($data['submissions'])->flatMap(fn ($s) => $s['files']);

        expect(collect($rest['submissions'])->flatMap(fn ($s) => $s['files'])->pluck('id')->all())->toBe($files->pluck('id')->all())
            ->and($rest['hidden'])->toBe($data['hidden']);

        return [$files->pluck('status', 'id')->all(), $data['hidden']];
    };

    expect($filesFor($creator))->toBe([[$ids[0] => 'pending', $ids[1] => 'pending'], 0])
        // The colleague may open the folder and the request, but a pending file is its owner's alone.
        ->and($filesFor($colleague))->toBe([[], 2]);

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => [$ids[0]]])->assertOk();

    expect($filesFor($colleague))->toBe([[$ids[0] => 'accepted'], 1]);
});

it('creates, edits, closes and reissues a request in the Library, and only its creator or a full admin may change it', function () {
    $folder = Directory::create(['name' => 'Staff intake', 'parent_id' => null]);
    $hiddenFolder = Directory::create(['name' => 'Not yours', 'parent_id' => null]);
    $creator = p5aMember(folders: [$folder], editor: true);
    $colleague = p5aMember(folders: [$folder], editor: true);

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.store'), ['title' => 'Nope', 'directory_id' => $hiddenFolder->id])->assertNotFound();
    $this->postJson(route('admin.dam.upload-requests.store'), ['title' => 'Bad', 'directory_id' => $folder->id, 'allowed_types' => ['../etc']])->assertUnprocessable();
    $created = $this->postJson(route('admin.dam.upload-requests.store'), [
        'title'              => 'Campus shoot', 'directory_id' => $folder->id, 'expiry_days' => 7, 'max_files' => 20,
        'max_bytes_per_file' => 1_048_576, 'allowed_types' => ['jpg', 'image/*'], 'password' => p5aPassword(),
    ])->assertCreated()->json('request');

    expect($created)->has_password->toBeTrue()->max_files->toBe(20)->allowed_types->toBe(['jpg', 'image/*'])
        ->and($created['url'])->toStartWith(url('/u/'))
        ->and(json_encode($created))->not->toContain(p5aPassword());

    $id = $created['id'];
    $this->patchJson(route('admin.dam.upload-requests.update', $id), ['title' => 'Campus shoot 2', 'remove_password' => true])
        ->assertOk()->assertJsonPath('request.title', 'Campus shoot 2')->assertJsonPath('request.has_password', false);

    $this->actingAs($colleague, 'admin');
    $this->patchJson(route('admin.dam.upload-requests.update', $id), ['title' => 'Hijack'])->assertForbidden();
    $this->patchJson(route('admin.dam.upload-requests.close', $id))->assertForbidden();
    $this->postJson(route('admin.dam.upload-requests.reissue', $id))->assertForbidden();

    $this->actingAs($creator, 'admin');
    $old = $created['url'];
    $reissued = $this->postJson(route('admin.dam.upload-requests.reissue', $id))->assertOk()->json('request.url');
    $this->patchJson(route('admin.dam.upload-requests.close', $id))->assertOk()->assertJsonPath('request.status', 'closed');
    $this->postJson(route('admin.dam.upload-requests.reissue', $id))->assertStatus(409);

    expect($reissued)->not->toBe($old)
        ->and(UploadRequest::query()->findOrFail($id))->status->toBe(UploadRequest::STATUS_CLOSED)->title->toBe('Campus shoot 2');
});

it('answers 404 for a file the actor cannot see and 403 without edit rights on the intake folder', function () {
    $folder = Directory::create(['name' => 'Decide intake', 'parent_id' => null]);
    // The creator owns the guest files, but holds no edit right.
    $creator = p5aMember(folders: [$folder], editor: false);
    $editor = p5aMember(folders: [$folder], editor: true);
    [$request, $ids] = p5aWithGuestFiles(folder: $folder, creator: $creator);
    $foreign = Asset::factory()->create();

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => $ids])->assertForbidden();
    $this->postJson(route('admin.dam.upload-requests.reject', $request->id), ['asset_ids' => $ids])->assertForbidden();

    $this->actingAs($editor, 'admin');
    $hidden = $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => [$ids[0]]]);
    $missing = $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => [$foreign->id]]);

    expect($hidden->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404)
        ->and(str_replace((string) $ids[0], 'ID', $hidden->getContent()))->toBe(str_replace((string) $foreign->id, 'ID', $missing->getContent()))
        ->and(Asset::query()->whereIn('id', $ids)->pluck('availability')->unique()->all())->toBe([Asset::AVAILABILITY_PENDING_REVIEW]);

    // The REST API answers the same.
    $headers = p5aApiHeaders($creator);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.accept', $request->id), ['asset_ids' => $ids])->assertForbidden();
    $headers = p5aApiHeaders($editor);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.reject', $request->id), ['asset_ids' => [$ids[0]]])->assertNotFound();
});

it('accepts a file into the Library with the intake folder\'s access list, and flags it for New user uploads', function () {
    p5aAccessModel(AccessModel::LISTS);
    $folder = Directory::create(['name' => 'Listed intake', 'parent_id' => null]);
    $friend = p5aMember(folders: [], editor: false);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $friend->id]);
    $creator = p5aFullAdmin();
    [$request, $ids] = p5aWithGuestFiles(folder: $folder, creator: $creator, names: ['keep.txt']);
    $listOf = fn (int $id) => DB::table('dam_asset_user_access')->where('asset_id', $id)->pluck('admin_id')->map(fn ($v) => (int) $v)->all();

    // Pending: the folder's list is not the file's; the friend cannot see it.
    expect($listOf($ids[0]))->not->toContain($friend->id);
    $this->actingAs($friend, 'admin');
    $this->get(route('admin.dam.assets.edit', $ids[0]))->assertNotFound();

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => $ids])
        ->assertOk()->assertJsonPath('outcomes.'.$ids[0], 'accepted');

    $asset = Asset::query()->findOrFail($ids[0]);

    expect($asset->availability)->toBe(Asset::AVAILABILITY_AVAILABLE)
        ->and($listOf($ids[0]))->toBe([$friend->id])
        ->and($asset->needs_sorting_at)->not->toBeNull()
        ->and(DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::GUEST_SUBMISSION_ACCEPTED)->count())->toBe(1);

    $this->getJson(route('admin.dam.new-uploads.list'))->assertOk()
        ->assertJsonFragment(['id' => $ids[0], 'guest' => true]);

    $this->actingAs($friend, 'admin');
    $this->get(route('admin.dam.assets.edit', $ids[0]))->assertOk();
    // Decided once: a second accept changes nothing.
    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => $ids])->assertStatus(409);
});

it('accepts into another folder through the Library move, and rejects to the trash, one file or many', function () {
    $folder = Directory::create(['name' => 'Bulk intake', 'parent_id' => null]);
    $destination = Directory::create(['name' => 'Final home', 'parent_id' => null]);
    $creator = p5aFullAdmin();
    [$request, $ids] = p5aWithGuestFiles(folder: $folder, creator: $creator, names: ['a.txt', 'b.txt', 'c.txt', 'd.txt']);

    $this->actingAs($creator, 'admin');
    $this->postJson(route('admin.dam.upload-requests.accept', $request->id), ['asset_ids' => [$ids[0], $ids[1]], 'destination_id' => $destination->id])
        ->assertOk()->assertJsonPath('outcomes.'.$ids[0], 'accepted')->assertJsonPath('outcomes.'.$ids[1], 'accepted');
    $this->postJson(route('admin.dam.upload-requests.reject', $request->id), ['asset_ids' => [$ids[2], $ids[3]]])
        ->assertOk()->assertJsonPath('outcomes.'.$ids[2], 'rejected');

    $moved = Asset::query()->whereIn('id', [$ids[0], $ids[1]])->get();

    expect($moved->pluck('availability')->unique()->all())->toBe([Asset::AVAILABILITY_AVAILABLE])
        ->and($moved->map(fn ($asset) => $asset->directories()->pluck('dam_directories.id')->all())->all())->toBe([[$destination->id], [$destination->id]])
        ->and($moved->pluck('needs_sorting_at')->filter()->all())->toBe([])
        ->and(Asset::onlyTrashed()->whereIn('id', [$ids[2], $ids[3]])->pluck('deleted_by')->map(fn ($v) => (int) $v)->all())->toBe([$creator->id, $creator->id])
        ->and(DB::table(SecurityEventRecorder::TABLE)->where('event', SecurityEvent::GUEST_SUBMISSION_REJECTED)->count())->toBe(1);

    $this->getJson(route('admin.dam.trash.list'))->assertOk()->assertJsonFragment(['id' => $ids[2]]);
    $this->getJson(route('admin.dam.upload-requests.submissions', $request->id))->assertOk()
        ->assertJsonPath('submissions.0.files.2.status', 'rejected');
});

it('lets a role limited to its own items reject only files sent through a link it made, in the Library and over REST', function () {
    $folder = Directory::create(['name' => 'Own-only intake', 'parent_id' => null]);
    $creator = p5aMember(folders: [$folder], editor: true);
    $limited = p5aMember(folders: [$folder], editor: true);
    DB::table('dam_role_settings')->insert(['role_id' => $limited->role_id, 'delete_own_only' => true, 'created_at' => now(), 'updated_at' => now()]);
    [$theirs, $theirIds] = p5aWithGuestFiles(folder: $folder, creator: $creator);
    [$mine, $myIds] = p5aWithGuestFiles(folder: $folder, creator: $limited);
    $trashed = fn (array $ids) => Asset::onlyTrashed()->whereIn('id', $ids)->count();
    $message = trans('dam::app.admin.upload-requests.review.reject-own-only');

    // Backstop for a case the visibility rule does not reach today: a decider who can open someone else's pending files
    // (this stand-in treats them as decidable) is still refused before anything is trashed.
    app()->bind(IntakeReview::class, fn () => new class(app(AssetAccessDecision::class), app(AccessModel::class), app(AccessListWriter::class), app(AssetTrashService::class)) extends IntakeReview
    {
        protected function triage(Admin $actor, UploadRequest $request, array $assetIds): array
        {
            return [[], array_values(array_map('intval', $assetIds))];
        }
    });

    $this->actingAs($limited, 'admin');
    $this->postJson(route('admin.dam.upload-requests.reject', $theirs->id), ['asset_ids' => $theirIds])->assertForbidden()->assertJsonPath('message', $message);
    expect($trashed($theirIds))->toBe(0);
    $this->withHeaders(p5aApiHeaders($limited))->postJson(route('admin.api.dam.upload_requests.reject', $theirs->id), ['asset_ids' => $theirIds])
        ->assertForbidden()->assertJsonPath('message', $message);
    expect($trashed($theirIds))->toBe(0);

    // Their own link's files reject normally, and a full administrator rejects any link's files.
    $this->actingAs($limited, 'admin');
    $this->postJson(route('admin.dam.upload-requests.reject', $mine->id), ['asset_ids' => $myIds])->assertOk();
    expect($trashed($myIds))->toBe(2);
    $this->actingAs(p5aFullAdmin(), 'admin');
    $this->postJson(route('admin.dam.upload-requests.reject', $theirs->id), ['asset_ids' => [$theirIds[1]]])->assertOk();
    expect($trashed([$theirIds[1]]))->toBe(1);
});

it('answers as missing when a role limited to its own items tries to reject files from a link it did not make', function () {
    $folder = Directory::create(['name' => 'Own-only intake 2', 'parent_id' => null]);
    $creator = p5aMember(folders: [$folder], editor: true);
    $limited = p5aMember(folders: [$folder], editor: true);
    DB::table('dam_role_settings')->insert(['role_id' => $limited->role_id, 'delete_own_only' => true, 'created_at' => now(), 'updated_at' => now()]);
    [$theirs, $theirIds] = p5aWithGuestFiles(folder: $folder, creator: $creator);

    $this->actingAs($limited, 'admin');
    $this->postJson(route('admin.dam.upload-requests.reject', $theirs->id), ['asset_ids' => $theirIds])->assertNotFound();
    expect(Asset::onlyTrashed()->whereIn('id', $theirIds)->count())->toBe(0);
});

it('gives the same answers over REST: create, edit, close, reissue, accept and reject', function () {
    $folder = Directory::create(['name' => 'Rest intake', 'parent_id' => null]);
    $creator = p5aMember(folders: [$folder], editor: true);
    $colleague = p5aMember(folders: [$folder], editor: true);
    $headers = p5aApiHeaders($creator);

    $created = $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.store'), ['title' => 'Rest request', 'directory_id' => $folder->id, 'max_files' => 3])
        ->assertCreated()->json('data');
    $id = $created['id'];

    expect($created['url'])->toStartWith(url('/u/'))->and($created['max_files'])->toBe(3);

    $this->withHeaders($headers)->putJson(route('admin.api.dam.upload_requests.update', $id), ['title' => 'Renamed'])->assertOk()->assertJsonPath('data.title', 'Renamed');
    $reissued = $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.reissue', $id))->assertOk()->json('data.url');
    expect($reissued)->not->toBe($created['url']);

    $token = basename((string) parse_url($reissued, PHP_URL_PATH));
    $visit = p5aVisit($token);
    $assetIds = [(int) p5aUpload($token, $visit, 'x.txt', 'xxxx')->asset_id, (int) p5aUpload($token, $visit, 'y.txt', 'yyyy')->asset_id];

    $headers = p5aApiHeaders($colleague);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.upload_requests.update', $id), ['title' => 'Hijack'])->assertForbidden();
    $this->withHeaders($headers)->getJson(route('admin.api.dam.upload_requests.show', $id))->assertOk()->assertJsonPath('data.url', null);

    $headers = p5aApiHeaders($creator);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.accept', $id), ['asset_ids' => [$assetIds[0]]])->assertOk()
        ->assertJsonPath('data.outcomes.'.$assetIds[0], 'accepted');
    $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.reject', $id), ['asset_ids' => [$assetIds[1]]])->assertOk()
        ->assertJsonPath('data.outcomes.'.$assetIds[1], 'rejected');
    $this->withHeaders($headers)->postJson(route('admin.api.dam.upload_requests.close', $id))->assertOk()->assertJsonPath('data.status', 'closed');

    expect(Asset::query()->findOrFail($assetIds[0])->availability)->toBe(Asset::AVAILABILITY_AVAILABLE)
        ->and(Asset::onlyTrashed()->whereKey($assetIds[1])->exists())->toBeTrue();
});
