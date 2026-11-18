<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Every per-asset endpoint outside AssetController (which AssetTest covers) answers an asset the viewer
 * cannot open exactly as it answers an id that does not exist: same status, same body. A route ACL 403 is
 * the same for every id and stays; only the per-asset branch is compared here.
 */

function hiddenEqualsMissingImage(string $name): Asset
{
    $path = 'assets/Root/'.$name.'-'.uniqid().'.png';
    $image = UploadedFile::fake()->image('x.png', 8, 8);
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents($image->getRealPath()));

    return Asset::factory()->create(['file_name' => basename($path), 'path' => $path, 'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png']);
}

/** A role holding every registered ACL key, so no route ACL refuses before the per-asset branch runs. */
function hiddenEqualsMissingRole(): Role
{
    return tickEveryHomeTool(Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->push('sphereseam')->unique()->values()->all(),
    ]));
}

function useAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** Requests whose asset id sits in the body and is validated: a refused id fails validation (422). */
const HIDDEN_EQUALS_MISSING_VALIDATED = ['relationships.target', 'capture.primary', 'tag.add', 'tag.remove', 'copy.asset', 'comment.store'];

function expectHiddenEqualsMissing(array $hiddenRequests, array $missingRequests, callable $send): void
{
    foreach ($hiddenRequests as $name => $request) {
        $hidden = $send($request);
        $missing = $send($missingRequests[$name]);
        $expected = in_array($name, HIDDEN_EQUALS_MISSING_VALIDATED, true) ? 422 : 404;

        expect($hidden->getStatusCode())->toBe($expected, $name)
            ->and($missing->getStatusCode())->toBe($expected, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name);
    }
}

/** Every admin per-asset request this sweep changed, for one asset id. */
function hiddenEqualsMissingAdminRequests(int $id, int $fileId, int $visibleId, int $directoryId): array
{
    return [
        'relationships.index'      => ['GET', route('admin.dam.assets.relationships.index', $id), []],
        'relationships.store'      => ['POST', route('admin.dam.assets.relationships.store', $id), ['to_asset_id' => $visibleId, 'type' => 'related-to']],
        'relationships.target'     => ['POST', route('admin.dam.assets.relationships.store', $visibleId), ['to_asset_id' => $id, 'type' => 'related-to']],
        'files.index'              => ['GET', route('admin.dam.assets.files.index', $id), []],
        'file.asset-file'          => ['GET', route('admin.dam.file.asset-file', [$id, $fileId]), []],
        'file.cover-art'           => ['GET', route('admin.dam.file.cover-art', $id), []],
        'custom_thumbnail'         => ['POST', route('admin.dam.assets.custom_thumbnail.store', $id), []],
        'model_thumbnail.show'     => ['GET', route('admin.dam.assets.model_thumbnail.show', $id), []],
        'model_thumbnail.store'    => ['POST', route('admin.dam.assets.model_thumbnail.store', $id), []],
        'primary_view.show'        => ['GET', route('admin.dam.assets.primary_view.show', $id), []],
        'primary_view.save'        => ['POST', route('admin.dam.assets.primary_view.save', $id), []],
        'primary_view.restore'     => ['DELETE', route('admin.dam.assets.primary_view.restore', $id), []],
        'editor.document'          => ['GET', route('admin.dam.assets.editor.document', $id), []],
        'editor.upload'            => ['POST', route('admin.dam.assets.editor.upload', $id), []],
        'editor.save'              => ['POST', route('admin.dam.assets.editor.save', $id), []],
        'capture.primary'          => ['PUT', route('admin.dam.assets.capture.update', $visibleId), ['primary_asset_id' => $id]],
        'vectorization.state'      => ['GET', route('admin.dam.assets.vectorization.state', $id), []],
        'vectorization.store'      => ['POST', route('admin.dam.assets.vectorization.store', $id), []],
        'optimization.state'       => ['GET', route('admin.dam.assets.optimization.state', $id), []],
        'optimization.store'       => ['POST', route('admin.dam.assets.optimization.store', $id), []],
        'panorama.state'           => ['GET', route('admin.dam.assets.panorama.state', $id), []],
        'panorama.build'           => ['POST', route('admin.dam.assets.panorama.build', $id), []],
        'panorama.mode'            => ['PATCH', route('admin.dam.assets.panorama.mode', $id), ['mode' => 'auto']],
        'generation'               => ['GET', route('admin.dam.assets.generation', $id), []],
        'generation.output'        => ['GET', route('admin.dam.assets.generation.output', $id), []],
        'pages.show'               => ['GET', route('admin.dam.pages.show', $id), []],
        'pages.grant'              => ['GET', route('admin.dam.pages.grant', $id), []],
        'tag.add'                  => ['POST', route('admin.dam.assets.tag'), ['asset_id' => $id, 'tag' => 'probe']],
        'tag.remove'               => ['POST', route('admin.dam.assets.remove-tag'), ['asset_id' => $id, 'tag' => 'probe']],
        'copy.asset'               => ['POST', route('admin.dam.explorer.copy.asset'), ['asset_id' => $id, 'target_directory_id' => $directoryId]],
        'sphereseam.resolve'       => ['POST', route('sphereseam.assets.resolve'), ['ids' => [$id]]],
        'sphereseam.source'        => ['GET', route('sphereseam.assets.source', $id), []],
    ];
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['sphereseam.enabled' => true]);

    $this->directory = Directory::factory()->create();
    $this->hidden = hiddenEqualsMissingImage('hidden');
    $this->visible = hiddenEqualsMissingImage('visible');
    $this->directory->assets()->attach([$this->hidden->id, $this->visible->id]);
    // A real attachment on the hidden asset, so the attachment read is refused for the asset, not the file id.
    $this->hiddenFile = attachPreviewFile($this->hidden, 'assets/Root/hidden-captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR)->id;
});

it('answers a folder-visible but file-private asset like a missing one on every per-asset endpoint', function () {
    $member = Admin::factory()->create(['role_id' => hiddenEqualsMissingRole()->id]);
    useAccessModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    // Debug pages embed the caller's line number; compare what production sends.
    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id, int $fileId): array => hiddenEqualsMissingAdminRequests(id: $id, fileId: $fileId, visibleId: $this->visible->id, directoryId: $this->directory->id);

    expectHiddenEqualsMissing(
        hiddenRequests: $requests($this->hidden->id, $this->hiddenFile),
        missingRequests: $requests($missingId, $this->hiddenFile),
        send: fn (array $request) => $this->json(...$request),
    );

    // A browser's pages and inline files answer the same way, headers included.
    foreach (['admin.dam.pages.show', 'admin.dam.assets.generation.view', 'sphereseam.assets.source', 'admin.dam.file.cover-art'] as $route) {
        $hidden = $this->get(route($route, $this->hidden->id));
        $missing = $this->get(route($route, $missingId));

        expect($hidden->getStatusCode())->toBe(404, $route)
            ->and($hidden->getContent())->toBe($missing->getContent(), $route)
            ->and($hidden->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'), $route);
    }

    // The path-addressed reads answer a hidden file's path like a path nothing holds.
    $hidden = $this->get(route('admin.dam.file.fetch', $this->hidden->path));
    $missing = $this->get(route('admin.dam.file.fetch', 'assets/Root/nothing-here.png'));
    expect($hidden->getStatusCode())->toBe(404)->and($hidden->getContent())->toBe($missing->getContent());

    // So do its thumbnail and preview: a hidden image never comes back as itself or as a different answer.
    $derivative = fn (string $route, string $path) => $this->get(route($route, ['path' => $path]), ['Accept' => 'image/*']);
    foreach (['admin.dam.file.thumbnail', 'admin.dam.file.preview'] as $route) {
        $hidden = $derivative($route, $this->hidden->path);
        $missing = $derivative($route, 'assets/Root/nothing-here.png');

        expect($hidden->getStatusCode())->toBe($missing->getStatusCode(), $route)
            ->and($hidden->getStatusCode())->toBe(404, $route)
            ->and($hidden->getContent())->toBe($missing->getContent(), $route);
    }

    // Page moves report a hidden page with the reason a missing id gets.
    $rejected = fn (int $id) => $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$id], 'collection_id' => null])->json('rejected.0.reason');
    expect($rejected($this->hidden->id))->toBe('missing')->and($rejected($missingId))->toBe('missing');

    // Nothing was written against the hidden asset.
    expect(DB::table('dam_asset_relationships')->where('to_asset_id', $this->hidden->id)->orWhere('from_asset_id', $this->hidden->id)->exists())->toBeFalse()
        ->and(Asset::query()->where('file_name', $this->hidden->file_name)->count())->toBe(1);

    // Once the file's own list names the member, the same reads open.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->hidden->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.relationships.index', $this->hidden->id))->assertOk();
    $this->getJson(route('admin.dam.assets.files.index', $this->hidden->id))->assertOk();
    $this->postJson(route('sphereseam.assets.resolve'), ['ids' => [$this->hidden->id]])->assertOk()->assertJsonPath('item.id', $this->hidden->id);
    $this->get(route('sphereseam.assets.source', $this->hidden->id))->assertOk();
    $this->get(route('admin.dam.file.fetch', $this->hidden->path))->assertOk();
    $derivative('admin.dam.file.thumbnail', $this->hidden->path)->assertOk();
    $derivative('admin.dam.file.preview', $this->hidden->path)->assertOk();
});

it('answers an asset in no granted folder like a missing one under grants', function () {
    $role = hiddenEqualsMissingRole();
    $member = Admin::factory()->create(['role_id' => $role->id]);
    useAccessModel(AccessModel::GRANTS);
    $this->actingAs($member, 'admin');

    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id): array => [
        'relationships.index' => ['GET', route('admin.dam.assets.relationships.index', $id), []],
        'files.index'         => ['GET', route('admin.dam.assets.files.index', $id), []],
        'file.asset-file'     => ['GET', route('admin.dam.file.asset-file', [$id, $this->hiddenFile]), []],
        'panorama.state'      => ['GET', route('admin.dam.assets.panorama.state', $id), []],
        'sphereseam.source'   => ['GET', route('sphereseam.assets.source', $id), []],
    ];

    expectHiddenEqualsMissing(
        hiddenRequests: $requests($this->hidden->id),
        missingRequests: $requests($missingId),
        send: fn (array $request) => $this->json(...$request),
    );
});

it('answers a hidden asset like a missing one on the REST API', function () {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => hiddenEqualsMissingRole()->id]);
    useAccessModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();

    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id): array => [
        'show'          => ['GET', route('admin.api.dam.assets.show', $id), []],
        'edit'          => ['PUT', route('admin.api.dam.assets.edit', $id), []],
        'update'        => ['PUT', route('admin.api.dam.assets.update', $id), ['file_name' => 'renamed.png']],
        'destroy'       => ['DELETE', route('admin.api.dam.assets.destroy', $id), []],
        'metadata'      => ['GET', route('admin.api.dam.assets.metadata', $id), []],
        'reupload'      => ['POST', route('admin.api.dam.assets.reUpload'), ['asset_id' => $id, 'file' => UploadedFile::fake()->create('new.png', 1)]],
        'comment.store' => ['POST', route('admin.api.dam.comment.store'), ['dam_asset_id' => $id, 'comments' => 'probe']],
        'tag.add'       => ['POST', route('admin.api.dam.tag.add'), ['asset_id' => $id, 'tag' => 'probe']],
        'tag.remove'    => ['DELETE', route('admin.api.dam.tag.delete'), ['asset_id' => $id, 'tag' => 'probe']],
    ];

    expectHiddenEqualsMissing(
        hiddenRequests: $requests($this->hidden->id),
        missingRequests: $requests($missingId),
        send: fn (array $request) => $this->withHeaders($headers)->json(...$request),
    );

    expect(Asset::find($this->hidden->id)?->file_name)->toBe($this->hidden->file_name);
});

it('never errors on a missing id for a role that sees every asset', function () {
    // The visibility checks pass for a bypass role, so each endpoint's own missing-id answer must still hold.
    $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]), 'admin');
    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;

    foreach (hiddenEqualsMissingAdminRequests(id: $missingId, fileId: $this->hiddenFile, visibleId: $this->visible->id, directoryId: $this->directory->id) as $name => $request) {
        $expected = in_array($name, HIDDEN_EQUALS_MISSING_VALIDATED, true) ? 422 : 404;

        expect($this->json(...$request)->getStatusCode())->toBe($expected, $name);
    }
});

it('answers a comment, property or former 403 route on a hidden asset like a missing one (P2 S1)', function () {
    $member = Admin::factory()->create(['role_id' => hiddenEqualsMissingRole()->id]);
    useAccessModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $comment = AssetComments::factory()->create(['dam_asset_id' => $this->hidden->id, 'admin_id' => $member->id, 'comments' => 'secret note']);
    $property = AssetProperty::factory()->create(['dam_asset_id' => $this->hidden->id]);
    $missingId = Asset::max('id') + 1000;
    $missingChild = max($comment->id, $property->id) + 1000;
    $visible = $this->visible->id;
    config(['app.debug' => false]);

    // Each request for the hidden asset's own row or id, and the same request for a row or id that does not exist.
    $web = fn (int $asset, int $comment, int $property): array => [
        'comment.show'    => ['GET', route('admin.dam.asset.comments.index', $comment), []],
        'comment.update'  => ['PUT', route('admin.dam.asset.comment.update', $visible), ['id' => $comment, 'comments' => 'probe text']],
        'comment.delete'  => ['DELETE', route('admin.dam.asset.comment.delete', $visible), ['id' => $comment]],
        'property.edit'   => ['GET', route('admin.dam.asset.property.edit', $property), []],
        'property.update' => ['PUT', route('admin.dam.asset.properties.update', $visible), ['id' => $property, 'name' => 'probe', 'value' => 'x']],
        'property.delete' => ['DELETE', route('admin.dam.asset.properties.delete', ['asset_id' => $visible, 'id' => $property]), []],
        'properties.list' => ['GET', route('admin.dam.asset.properties.index', $asset), []],
        'property.store'  => ['POST', route('admin.dam.asset.property.store', $asset), ['name' => 'probe', 'value' => 'x']],
        'capture.show'    => ['GET', route('admin.dam.assets.capture.show', $asset), []],
        'bg_preview'      => ['POST', route('admin.dam.assets.image_edit.bg_preview', $asset), []],
    ];
    $this->actingAs($member, 'admin');
    expectHiddenEqualsMissing(
        hiddenRequests: $web($this->hidden->id, $comment->id, $property->id),
        missingRequests: $web($missingId, $missingChild, $missingChild),
        send: fn (array $request) => $this->json(...$request),
    );

    // Nothing was read out or changed.
    expect($comment->fresh()->comments)->toBe('secret note')->and($property->fresh())->not->toBeNull();
});

it('answers a comment or property on a hidden asset like a missing one on the REST API (P2 S1)', function () {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => hiddenEqualsMissingRole()->id]);
    useAccessModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->directory->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $comment = AssetComments::factory()->create(['dam_asset_id' => $this->hidden->id, 'admin_id' => $member->id]);
    $property = AssetProperty::factory()->create(['dam_asset_id' => $this->hidden->id]);
    $missingId = Asset::max('id') + 1000;
    $missingChild = max($comment->id, $property->id) + 1000;
    config(['app.debug' => false]);

    $rest = fn (int $asset, int $comment, int $property): array => [
        'comment.get'     => ['GET', route('admin.api.dam.comment.get', $comment), []],
        'comment.update'  => ['PUT', route('admin.api.dam.comment.update', $comment), ['comments' => 'probe text']],
        'comment.delete'  => ['DELETE', route('admin.api.dam.comment.delete', $comment), []],
        'property.get'    => ['GET', route('admin.api.dam.property.get', $property), []],
        'property.update' => ['PATCH', route('admin.api.dam.property.update', $property), ['name' => 'probe', 'value' => 'x']],
        'property.delete' => ['DELETE', route('admin.api.dam.property.delete', $property), []],
        'property.add'    => ['POST', route('admin.api.dam.property.add', $asset), ['name' => 'probe', 'value' => 'x']],
        'download'        => ['GET', route('admin.api.dam.assets.download', $asset), []],
    ];
    expectHiddenEqualsMissing(
        hiddenRequests: $rest($this->hidden->id, $comment->id, $property->id),
        missingRequests: $rest($missingId, $missingChild, $missingChild),
        send: fn (array $request) => $this->withHeaders($headers)->json(...$request),
    );

    expect($comment->fresh())->not->toBeNull()->and($property->fresh())->not->toBeNull();
});

it('serves a file under grants when a folder other than its first one shows it (P2 S1)', function () {
    $role = hiddenEqualsMissingRole();
    $member = Admin::factory()->create(['role_id' => $role->id]);
    useAccessModel(AccessModel::GRANTS);
    // The asset's first folder is not granted; a second one is.
    $second = Directory::factory()->create();
    $this->hidden->directories()->attach($second->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $second->id, 'role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    expect($this->hidden->directories()->value('dam_directories.id'))->toBe($this->directory->id);
    $this->get(route('admin.dam.file.asset-file', [$this->hidden->id, $this->hiddenFile]))->assertOk();
});

it('answers a trashed asset like a missing one on every per-asset endpoint and the REST API, to a full admin too (P2 S4)', function () {
    $headers = $this->getAuthenticationHeaders();
    $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]), 'admin');
    $comment = AssetComments::factory()->create(['dam_asset_id' => $this->hidden->id, 'comments' => 'trashed note']);
    $property = AssetProperty::factory()->create(['dam_asset_id' => $this->hidden->id]);
    $path = $this->hidden->path;
    $this->hidden->delete();
    config(['app.debug' => false]);
    $missingId = Asset::withTrashed()->max('id') + 1000;
    $missingChild = max($comment->id, $property->id) + 1000;

    expectHiddenEqualsMissing(
        hiddenRequests: hiddenEqualsMissingAdminRequests(id: $this->hidden->id, fileId: $this->hiddenFile, visibleId: $this->visible->id, directoryId: $this->directory->id),
        missingRequests: hiddenEqualsMissingAdminRequests(id: $missingId, fileId: $this->hiddenFile, visibleId: $this->visible->id, directoryId: $this->directory->id),
        send: fn (array $request) => $this->json(...$request),
    );

    foreach (['admin.dam.pages.show', 'admin.dam.assets.generation.view', 'sphereseam.assets.source', 'admin.dam.file.cover-art'] as $route) {
        $trashed = $this->get(route($route, $this->hidden->id));

        expect($trashed->getStatusCode())->toBe(404, $route)
            ->and($trashed->getContent())->toBe($this->get(route($route, $missingId))->getContent(), $route);
    }

    // Its old path and its trash path read like a path nothing holds, and so do their thumbnail and preview.
    $nothing = 'assets/Root/nothing-here.png';
    foreach ([$path, Asset::onlyTrashed()->findOrFail($this->hidden->id)->path] as $read) {
        expect($this->get(route('admin.dam.file.fetch', $read))->getContent())->toBe($this->get(route('admin.dam.file.fetch', $nothing))->getContent());
        foreach (['admin.dam.file.thumbnail', 'admin.dam.file.preview'] as $route) {
            $trashed = $this->get(route($route, ['path' => $read]), ['Accept' => 'image/*']);

            expect($trashed->getStatusCode())->toBe(404, $route)
                ->and($trashed->getContent())->toBe($this->get(route($route, ['path' => $nothing]), ['Accept' => 'image/*'])->getContent(), $route);
        }
    }

    $rest = fn (int $id, int $comment, int $property): array => [
        'show'          => ['GET', route('admin.api.dam.assets.show', $id), []],
        'update'        => ['PUT', route('admin.api.dam.assets.update', $id), ['file_name' => 'renamed.png']],
        'destroy'       => ['DELETE', route('admin.api.dam.assets.destroy', $id), []],
        'metadata'      => ['GET', route('admin.api.dam.assets.metadata', $id), []],
        'download'      => ['GET', route('admin.api.dam.assets.download', $id), []],
        'comment.get'   => ['GET', route('admin.api.dam.comment.get', $comment), []],
        'property.get'  => ['GET', route('admin.api.dam.property.get', $property), []],
        'comment.store' => ['POST', route('admin.api.dam.comment.store'), ['dam_asset_id' => $id, 'comments' => 'probe']],
        'tag.add'       => ['POST', route('admin.api.dam.tag.add'), ['asset_id' => $id, 'tag' => 'probe']],
    ];
    expectHiddenEqualsMissing(
        hiddenRequests: $rest($this->hidden->id, $comment->id, $property->id),
        missingRequests: $rest($missingId, $missingChild, $missingChild),
        send: fn (array $request) => $this->withHeaders($headers)->json(...$request),
    );

    // Still in the trash, as it was.
    $trashed = Asset::onlyTrashed()->findOrFail($this->hidden->id);
    expect($trashed->file_name)->toBe($this->hidden->file_name)
        ->and($trashed->trashed_from_path)->toBe($path)
        ->and(DB::table('dam_asset_relationships')->where('to_asset_id', $this->hidden->id)->orWhere('from_asset_id', $this->hidden->id)->exists())->toBeFalse();
});

/**
 * P2 S3 re-review: a custom role that reaches every folder (all_directories) but may not manage availability. The
 * folder rule passes for any id, so only availability hides a file, and a missing id must still answer exactly as
 * an unavailable file does, not with the endpoint's own not-found body.
 */
function allFoldersNonManager(): Admin
{
    $role = tickEveryHomeTool(Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->push('sphereseam')->unique()
            ->reject(fn ($key) => $key === 'dam.asset.update')->values()->all(),
    ]));
    DB::table('dam_role_settings')->where('role_id', $role->id)->update(['all_directories' => true]);

    return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
}

/** One file in each state that is not out for use (P2 S3), each with an attachment, keyed by state. */
function unavailableFilesWithAttachments(Directory $directory): array
{
    $states = [
        'draft'     => ['availability' => Asset::AVAILABILITY_DRAFT],
        'pending'   => ['availability' => Asset::AVAILABILITY_PENDING_REVIEW],
        'archived'  => ['availability' => Asset::AVAILABILITY_ARCHIVED],
        'scheduled' => ['available_from' => now()->addDay()],
        'expired'   => ['available_until' => now()->subMinute()],
    ];

    return collect($states)->map(function (array $state, string $case) use ($directory) {
        $asset = hiddenEqualsMissingImage('unavailable-'.$case);
        $asset->update($state);
        $directory->assets()->attach($asset->id);

        return ['asset' => $asset, 'file' => attachPreviewFile($asset, 'assets/Root/'.$case.'-captions.vtt', 'text/vtt', AssetFile::ROLE_SIDECAR)->id];
    })->all();
}

it('answers an unavailable asset like a missing one on every per-asset endpoint for a role that reaches every folder (P2 S3)', function () {
    useAccessModel(AccessModel::GRANTS);
    $files = unavailableFilesWithAttachments($this->directory);
    $member = allFoldersNonManager();
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');
    expect(app(DirectoryPermissionService::class)->bypass())->toBeTrue();
    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id, int $fileId): array => hiddenEqualsMissingAdminRequests(id: $id, fileId: $fileId, visibleId: $this->visible->id, directoryId: $this->directory->id);

    foreach ($files as $case => ['asset' => $asset, 'file' => $file]) {
        expectHiddenEqualsMissing(
            hiddenRequests: $requests($asset->id, $file),
            missingRequests: $requests($missingId, $file),
            send: fn (array $request) => $this->json(...$request),
        );
    }

    // Positive control: the same role opens an available file.
    $this->getJson(route('admin.dam.assets.relationships.index', $this->visible->id))->assertOk();
    $this->getJson(route('admin.dam.assets.files.index', $this->visible->id))->assertOk();
});

it('answers an unavailable asset like a missing one on the REST API for a role that reaches every folder (P2 S3)', function () {
    useAccessModel(AccessModel::GRANTS);
    $files = unavailableFilesWithAttachments($this->directory);
    $headers = $this->getAuthenticationHeaders();
    Admin::latest('id')->first()->update(['role_id' => allFoldersNonManager()->role_id]);
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);
    $missingId = Asset::max('id') + 1000;
    $requests = fn (int $id): array => [
        'show'          => ['GET', route('admin.api.dam.assets.show', $id), []],
        'download'      => ['GET', route('admin.api.dam.assets.download', $id), []],
        'edit'          => ['PUT', route('admin.api.dam.assets.edit', $id), []],
        'update'        => ['PUT', route('admin.api.dam.assets.update', $id), ['file_name' => 'renamed.png']],
        'destroy'       => ['DELETE', route('admin.api.dam.assets.destroy', $id), []],
        'metadata'      => ['GET', route('admin.api.dam.assets.metadata', $id), []],
        'property.add'  => ['POST', route('admin.api.dam.property.add', $id), ['name' => 'probe', 'value' => 'x']],
        'comment.store' => ['POST', route('admin.api.dam.comment.store'), ['dam_asset_id' => $id, 'comments' => 'probe']],
        'tag.add'       => ['POST', route('admin.api.dam.tag.add'), ['asset_id' => $id, 'tag' => 'probe']],
        'tag.remove'    => ['DELETE', route('admin.api.dam.tag.delete'), ['asset_id' => $id, 'tag' => 'probe']],
    ];

    foreach ($files as $case => ['asset' => $asset]) {
        expectHiddenEqualsMissing(
            hiddenRequests: $requests($asset->id),
            missingRequests: $requests($missingId),
            send: fn (array $request) => $this->withHeaders($headers)->json(...$request),
        );
        expect(Asset::find($asset->id)?->file_name)->toBe($asset->file_name);
    }

    // Positive control: the same role downloads an available file.
    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.download', $this->visible->id))->assertOk();
});

/**
 * Codex review of the S4 + #144 + main merge (a1ff2db1, M1): the REST comment store checked the reply parent beside
 * the asset, so a hidden asset whose parent was a real top-level comment on it failed on dam_asset_id alone, while
 * any other parent added a parent_id error: a probe for which comment ids exist on a hidden asset. A target the
 * viewer cannot open now answers exactly as a missing asset with a missing parent, whatever parent is sent.
 */
function expectReplyParentSilentOnHiddenTarget($test, array $headers, Asset $target, string $label): void
{
    $top = AssetComments::factory()->create(['dam_asset_id' => $target->id]);
    $reply = AssetComments::factory()->create(['dam_asset_id' => $target->id, 'parent_id' => $top->id]);
    $foreign = AssetComments::factory()->create(['dam_asset_id' => $test->visible->id]);
    $missingAsset = Asset::withTrashed()->max('id') + 1000;
    $missingParent = AssetComments::max('id') + 1000;
    $post = fn (int $assetId, int $parentId) => $test->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), [
        'comments' => 'a reply probe', 'dam_asset_id' => $assetId, 'parent_id' => $parentId,
    ]);

    $expected = $post($missingAsset, $missingParent)->assertUnprocessable()->getContent();
    expect(json_decode($expected, true)['errors'] ?? [])->toHaveKey('dam_asset_id')->not->toHaveKey('parent_id');

    foreach (['its own top-level comment' => $top->id, 'a reply' => $reply->id, 'a foreign comment' => $foreign->id, 'a missing comment' => $missingParent] as $parent => $parentId) {
        expect($post($target->id, $parentId)->assertUnprocessable()->getContent())->toBe($expected, "{$label}, parent is {$parent}");
    }
    expect(AssetComments::query()->where('comments', 'a reply probe')->exists())->toBeFalse();
}

it('answers a reply on an asset the viewer cannot open exactly as a missing asset, whatever its parent (REST)', function () {
    config(['app.debug' => false]);

    // Hidden: a member who reaches the folder but not this file (lists model).
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => hiddenEqualsMissingRole()->id]);
    useAccessModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    expectReplyParentSilentOnHiddenTarget($this, $headers, $this->hidden, 'hidden');

    // The positive control: the visible asset still validates its parent.
    $top = AssetComments::factory()->create(['dam_asset_id' => $this->visible->id]);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), ['comments' => 'a fair reply', 'dam_asset_id' => $this->visible->id, 'parent_id' => $top->id + 1000])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), ['comments' => 'a fair reply', 'dam_asset_id' => $this->visible->id, 'parent_id' => $top->id])
        ->assertCreated();
});

it('answers a reply on an unavailable or trashed asset exactly as a missing asset, whatever its parent (REST)', function () {
    config(['app.debug' => false]);
    useAccessModel(AccessModel::GRANTS);
    $files = unavailableFilesWithAttachments($this->directory);
    $headers = $this->getAuthenticationHeaders();
    $admin = Admin::latest('id')->first();
    $admin->update(['role_id' => allFoldersNonManager()->role_id]);
    app(DirectoryPermissionService::class)->flush();
    foreach ($files as $case => ['asset' => $asset]) {
        expectReplyParentSilentOnHiddenTarget($this, $headers, $asset, $case);
    }

    // Trashed: hidden from a full admin too.
    $admin->update(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->hidden->delete();
    expectReplyParentSilentOnHiddenTarget($this, $headers, $this->hidden, 'trashed');
});
