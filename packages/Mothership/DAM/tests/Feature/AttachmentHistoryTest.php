<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\HistoryControl\Resolvers\ActorResolver;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use OwenIt\Auditing\Models\Audit;

/*
 * P3a S5: each file keeps its own history (who, when, size, checksum, note), and restoring an earlier
 * revision appends it as the next one, sharing its blob. Acceptance line 7. Also finding F4: every History
 * row names a version and a person (or the system), in the page's own font.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Revisions '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'menu.txt', 'path' => 'assets/Revisions/menu.txt', 'file_type' => 'document',
        'extension' => 'txt', 'mime_type' => 'text/plain', 'file_size' => 0,
    ]));
    $this->asset->directories()->attach($this->folder->id);
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id;
});

/** Replaces the entry's content as an upload would: a new revision of the representative and a new version. */
$replace = function (Asset $asset, string $bytes): AssetFile {
    putAssetContent($asset->fresh(), $bytes);

    return AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
};

it('restores a representative revision as the next revision and version, sharing its blob', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $second = $replace($this->asset, 'second edition');
    $slot = $first->attachment_id;
    expect($second->attachment_id)->toBe($slot);

    $history = $this->getJson($this->base.'/attachments/'.$slot.'/revisions')->assertOk()->json('revisions');
    expect(array_column($history, 'revision_number'))->toBe([2, 1])
        ->and(array_column($history, 'is_current'))->toBe([true, false])
        ->and($history[1]['size'])->toBe(5);

    $response = $this->postJson($this->base.'/attachments/'.$slot.'/revisions/'.$first->id.'/restore')->assertOk();

    $current = AssetFile::where('attachment_id', $slot)->where('is_current', true)->firstOrFail();
    expect($current->revision_number)->toBe(3)
        ->and($current->file_object_id)->toBe($first->file_object_id)
        ->and($current->role)->toBe(AssetFile::ROLE_ORIGINAL)
        ->and($current->note)->toBe('Restored revision 1')
        ->and(AssetFile::where('attachment_id', $slot)->count())->toBe(3)
        ->and($this->asset->fresh()->file_size)->toBe(5);

    $version = AssetVersion::where('dam_asset_id', $this->asset->id)->orderByDesc('number')->firstOrFail();
    expect($version->primary_asset_file_id)->toBe($current->id)->and($version->note)->toBe('Restored revision 1');
    Queue::assertPushed(ProcessAssetUpload::class);

    $revisions = $response->json('revisions');
    expect($revisions[0])->toMatchArray(['revision_number' => 3, 'is_current' => true, 'note' => 'Restored revision 1', 'author' => auth()->guard('admin')->user()->name]);

    // The current revision, and an older one with the same bytes as it, are already the content.
    $this->postJson($this->base.'/attachments/'.$slot.'/revisions/'.$current->id.'/restore')->assertUnprocessable();
    $this->postJson($this->base.'/attachments/'.$slot.'/revisions/'.$first->id.'/restore')->assertUnprocessable();
});

it('keeps the name a person gave the entry when an earlier revision is restored', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $first->update(['label' => 'old-draft.txt']);
    $replace($this->asset, 'second edition');
    app(AssetRename::class)->rename($this->asset->fresh(), 'final-menu.txt');
    $named = $this->asset->fresh()->only(['file_name', 'path']);
    expect($named['file_name'])->toBe('final-menu.txt');

    $this->postJson($this->base.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')->assertOk();

    // The restored revision was once called old-draft.txt; that label is history, not a rename.
    expect($this->asset->fresh()->only(['file_name', 'path']))->toBe($named)
        ->and($this->asset->fresh()->file_size)->toBe(5);
});

it('asks the disk whether a restored revision is still held before any row is locked', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $replace($this->asset, 'second edition');
    $levels = [];
    $store = Mockery::mock(ContentStore::class)->makePartial();
    $store->shouldReceive('holdsBlob')->andReturnUsing(function () use (&$levels) {
        $levels[] = DB::transactionLevel();

        return true;
    });
    app()->instance(ContentStore::class, $store);

    $this->postJson($this->base.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')->assertOk();

    // Under DatabaseTransactions the test itself holds level 1; a check inside the ledger's transaction would read 2.
    expect($levels)->not->toBeEmpty()->and(array_unique($levels))->toBe([1]);
});

it('restores an attachment revision in its own slot and lists only the current file', function () {
    $notes = $this->postJson($this->base.'/files', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'draft notes'), 'role' => 'sidecar'])
        ->assertCreated()->json();
    $draft = AssetFile::findOrFail($notes['id']);
    // A later revision of the same slot, as a future replace-attachment writer would add it.
    $draft->update(['role' => AssetFile::ROLE_VERSION, 'is_current' => false]);
    $final = AssetFile::create([
        'dam_asset_id'  => $this->asset->id, 'file_object_id' => FileObject::factory()->create()->id, 'role' => 'sidecar',
        'attachment_id' => $draft->attachment_id, 'revision_number' => 2, 'is_current' => true, 'sort' => $draft->sort,
    ]);

    $this->postJson($this->base.'/attachments/'.$draft->attachment_id.'/revisions/'.$draft->id.'/restore')->assertOk();

    $slot = AssetFile::where('attachment_id', $draft->attachment_id)->orderBy('revision_number')->get();
    expect($slot->map->only(['revision_number', 'role', 'is_current'])->all())->toBe([
        ['revision_number' => 1, 'role' => 'version', 'is_current' => false],
        ['revision_number' => 2, 'role' => 'version', 'is_current' => false],
        ['revision_number' => 3, 'role' => 'sidecar', 'is_current' => true],
    ])->and($slot[2]->file_object_id)->toBe($draft->file_object_id)
        ->and($slot[2]->created_by)->toBe((int) auth()->guard('admin')->id())
        ->and($final->fresh()->is_current)->toBeFalse();

    $files = collect($this->getJson($this->base.'/files')->assertOk()->json('files'))->where('attachment_id', $draft->attachment_id);
    expect($files)->toHaveCount(1)->and($files->first()['id'])->toBe($slot[2]->id);
});

it('refuses to restore a revision whose blob is gone, changing nothing', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $second = $replace($this->asset, 'second edition');
    $object = FileObject::findOrFail($first->file_object_id);
    Storage::disk($object->disk)->delete($object->path);
    $before = $this->asset->fresh()->getAttributes();

    $this->postJson($this->base.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')
        ->assertUnprocessable()->assertJsonValidationErrors('revision');

    expect(AssetFile::where('attachment_id', $first->attachment_id)->count())->toBe(2)
        ->and($second->fresh()->is_current)->toBeTrue()
        ->and($this->asset->fresh()->getAttributes())->toBe($before);
    Queue::assertNotPushed(ProcessAssetUpload::class);
});

it('keeps a restored representative at its slot\'s place after a reorder', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $replace($this->asset, 'second edition');
    $notes = $this->postJson($this->base.'/files', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'role' => 'sidecar'])
        ->assertCreated()->json();
    $this->putJson($this->base.'/attachments/order', ['order' => [$notes['attachment_id'], $first->attachment_id]])->assertOk();

    $this->postJson($this->base.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')->assertOk();

    $restored = AssetFile::where('attachment_id', $first->attachment_id)->where('is_current', true)->firstOrFail();
    expect($restored->sort)->toBe(1)
        ->and(array_column($this->getJson($this->base.'/files')->assertOk()->json('files'), 'id'))->toBe([$notes['id'], $restored->id]);
});

it('lets a viewer read history but not restore, hides a hidden entry, and refuses another entry\'s slot', function () use ($replace) {
    $first = $replace($this->asset, 'first');
    $replace($this->asset, 'second edition');
    $other = Asset::withoutEvents(fn () => Asset::factory()->create());
    $foreign = AssetFile::factory()->create(['dam_asset_id' => $other->id, 'role' => 'sidecar']);
    $this->getJson($this->base.'/attachments/'.$foreign->attachment_id.'/revisions')->assertNotFound();
    $this->postJson($this->base.'/attachments/'.$foreign->attachment_id.'/revisions/'.$foreign->id.'/restore')->assertNotFound();

    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files', 'dam.asset.files.index']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $viewer->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $viewer->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->getJson($this->base.'/attachments/'.$first->attachment_id.'/revisions')->assertOk()->assertJsonCount(2, 'revisions');
    $this->postJson($this->base.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')->assertForbidden();

    $outsider = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files', 'dam.asset.files.index']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $outsider->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->getJson($this->base.'/attachments/'.$first->attachment_id.'/revisions')->assertNotFound();
});

it('does the same through REST, each route under its own scope', function () use ($replace) {
    Queue::fake();
    $first = $replace($this->asset, 'first');
    $replace($this->asset, 'second edition');
    $route = ['asset' => $this->asset->id, 'attachment' => $first->attachment_id];
    // A REST client has no admin session, only its key.
    Auth::guard('admin')->logout();

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.files.history', 'api.dam.assets.files.restore']);
    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.attachments.revisions', $route))->assertOk()->assertJsonCount(2, 'data.revisions');
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.attachments.restore', [...$route, 'revision' => $first->id]))
        ->assertOk()->assertJsonPath('data.revisions.0.revision_number', 3)->assertJsonPath('data.revisions.0.note', 'Restored revision 1');

    // The restore re-described the asset; its audit names the key's owner, not nobody.
    expect(Audit::where('auditable_id', $this->asset->id)->where('tags', 'asset')->latest('id')->value('user_id'))
        ->toBe((int) Apikey::latest('id')->value('admin_id'));
});

it('refuses a REST restore to a key that may only read history', function () use ($replace) {
    $first = $replace($this->asset, 'first');
    $replace($this->asset, 'second edition');
    $readOnly = $this->getAuthenticationHeaders('custom', ['api.dam.assets.files.history']);

    $this->withHeaders($readOnly)->postJson(route('admin.api.dam.assets.attachments.restore', ['asset' => $this->asset->id, 'attachment' => $first->attachment_id, 'revision' => $first->id]))
        ->assertForbidden();
});

it('names the person who queued a job on its audits, and the system when nobody did', function () {
    $admin = auth()->guard('admin')->user();
    $context = app(Repository::class);
    ActorResolver::rememberDispatcher($context);
    expect($context->getHidden(ActorResolver::CONTEXT_KEY))->toBe((int) $admin->id);

    // A queue worker: nobody signed in, the dispatcher's id rehydrated from the job's context.
    Auth::guard('admin')->logout();
    expect(ActorResolver::resolve()?->id)->toBe($admin->id);
    $this->asset->update(['file_name' => 'queued.txt']);
    expect(Audit::where('auditable_id', $this->asset->id)->latest('id')->value('user_id'))->toBe($admin->id);

    Context::forgetHidden(ActorResolver::CONTEXT_KEY);
    expect(ActorResolver::resolve())->toBeNull();
});

it('numbers History versions on SQLite and keeps the date and user out of the icon font', function () {
    $this->asset->update(['file_name' => 'one.txt']);
    $this->travel(2)->seconds();
    $this->asset->update(['file_name' => 'two.txt']);
    expect(Audit::where('tags', 'asset')->where('history_id', $this->asset->id)->orderBy('id')->pluck('version_id')->all())->toBe([1, 2]);

    DB::table('audits')->where('history_id', $this->asset->id)->where('tags', 'asset')->orderByDesc('id')->limit(1)->update(['user_id' => null, 'user_type' => null]);
    $records = $this->getJson(route('admin.history.index', ['asset', $this->asset->id]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('records');

    expect(array_column($records, 'version_id'))->toContain(1, 2);
    foreach ($records as $record) {
        expect($record['user'])->toStartWith('<span class="icon-user" aria-hidden="true"></span> ')
            ->and($record['updated_at'])->toStartWith('<span class="icon-calendar" aria-hidden="true"></span> ');
    }
    expect(collect($records)->pluck('user')->contains(fn ($user) => str_ends_with($user, ' System')))->toBeTrue();
});
