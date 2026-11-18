<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Http\Controllers\HomeWidgetAccessController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Tests\DamTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** P2 S7: the immutable inventory drives the route sweep; the matrix runs on production's database driver. */
final class AccessMatrixPostgresTest extends DamTestCase
{
    public static function matrix(): array
    {
        $cases = [];
        foreach (['grants', 'lists'] as $model) {
            foreach (['no-access', 'granted', 'editor', 'admin', 'all-directories'] as $viewer) {
                foreach (['hidden', 'draft', 'pending_review', 'archived', 'scheduled', 'expired', 'trashed', 'merged'] as $state) {
                    $cases["{$model} {$viewer} {$state}"] = [$model, $viewer, $state];
                }
            }
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This acceptance matrix targets PostgreSQL; SQLite cannot establish PostgreSQL acceptance.');
        }
        Storage::fake(Directory::getAssetDisk());
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['app.debug' => false, 'sphereseam.enabled' => true]);
    }

    private function image(): Asset
    {
        $image = UploadedFile::fake()->image('matrix.png', 8, 8);
        $pixels = imagecreatetruecolor(8, 8);
        imagefill($pixels, 0, 0, random_int(0, 0xFFFFFF));
        imagepng($pixels, $image->getRealPath());
        $asset = Asset::factory()->create([
            'file_name' => 'matrix-'.bin2hex(random_bytes(6)).'.png',
            'extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png', 'created_by' => null,
        ]);
        Storage::disk(Directory::getAssetDisk())->put($asset->path, file_get_contents($image->getRealPath()));
        $asset->markGraphContentChanged()->save();

        return $asset->refresh();
    }

    private function state(Asset $asset, string $state): void
    {
        if ($state === 'trashed') {
            $asset->delete();
        } elseif ($state === 'scheduled') {
            $asset->update(['available_from' => now()->addDay()]);
        } elseif ($state === 'expired') {
            $asset->update(['available_until' => now()->subDay()]);
        } elseif ($state === 'merged') {
            // merged_into_id is deliberately not mass-assignable: only the merge service sets it.
            DB::table('dam_assets')->where('id', $asset->id)->update(['merged_into_id' => $this->image()->id]);
        } elseif ($state !== 'hidden') {
            $asset->update(['availability' => $state]);
        }
    }

    /** Match only an asset's own id, not upload UUIDs, comment ids, AI suggestion ids or trash-management ids. */
    private function assetRoutes(): array
    {
        $inventory = require __DIR__.'/../Fixtures/route-inventory.php';
        $probes = require __DIR__.'/../Fixtures/route-probes.php';
        $routes = [];
        foreach ($inventory as $name => $kind) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $params = $route->parameterNames();
            $assetParameter = match (true) {
                in_array('asset', $params, true)                                             => 'asset',
                in_array('asset_id', $params, true)                                          => 'asset_id',
                in_array('assetId', $params, true) && ! str_starts_with($name, 'dam.share.') => 'assetId',
                (str_starts_with($name, 'admin.dam.assets.') || str_starts_with($name, 'admin.api.dam.assets.')) && in_array('id', $params, true)
                    && ! str_starts_with($name, 'admin.dam.assets.ai-tags.')                                                                                                                                                        => 'id',
                in_array('id', $params, true) && (str_contains($route->uri(), 'edit/{id}/') || str_starts_with($name, 'admin.dam.pages.') || str_starts_with($name, 'admin.dam.access.') || $name === 'admin.api.dam.property.add') => 'id',
                str_starts_with($name, 'admin.api.dam.comment.') && in_array('id', $params, true)                                                                                                                                   => 'comment-id',
                str_starts_with($name, 'admin.api.dam.property.') && in_array('id', $params, true)                                                                                                                                  => 'property-id',
                $name === 'admin.dam.asset.property.edit'                                                                                                                                                                           => 'property-id',
                str_starts_with($name, 'admin.dam.file.') && (in_array('path', $params, true) || $params === [])                                                                                                                    => 'path',
                default                                                                                                                                                                                                             => null,
            };
            if ($assetParameter === null || $kind === 'public') {
                continue;
            }
            if (in_array($kind, ['bytes', 'listing'], true)) {
                $this->assertArrayHasKey($name, $probes, 'The immutable route registry must retain its existing probe: '.$name);
            }
            $category = $kind === 'bytes'
                ? (str_contains($name, 'download') || str_contains($name, 'asset-file') ? 'download' : 'preview')
                : $kind;
            $routes[$name] = [$route->methods()[0], $assetParameter, $params, $category];
        }
        $this->assertNotEmpty($routes);

        return $routes;
    }

    private function requestFor(string $name, array $definition, int $id, int $file, array $children): TestResponse
    {
        [$method, $assetParameter, $parameters] = $definition;
        $values = array_fill_keys($parameters, $file);
        if (isset($values['attachment'])) {
            $values['attachment'] = AssetFile::findOrFail($file)->attachment_id;
        }
        if (in_array($assetParameter, ['comment-id', 'property-id'], true)) {
            $values['id'] = $children[$assetParameter];
        } elseif ($assetParameter === 'path') {
            $values['path'] = $children['path'];
        } else {
            $values[$assetParameter] = $id;
        }
        if (isset($values['type'])) {
            $values['type'] = 'asset';
        }
        if (isset($values['resource'])) {
            $values['resource'] = '10000000-0000-4000-8000-000000000001';
        }
        if (str_ends_with($name, 'merges.preview')) {
            $values['sources'] = [$children['source-id']];
        }
        $payload = match ($name) {
            'admin.dam.assets.relationships.store'                                 => ['to_asset_id' => $id, 'type' => 'related-to'],
            'admin.dam.assets.panorama.mode'                                       => ['mode' => 'auto'],
            'admin.dam.asset.property.store'                                       => ['name' => 'matrix', 'value' => 'value'],
            'admin.dam.asset.comment.store'                                        => ['comments' => 'matrix'],
            'admin.api.dam.assets.relationships.store'                             => ['to_asset_id' => $id, 'type' => 'related-to'],
            'admin.api.dam.property.add'                                           => ['name' => 'matrix', 'value' => 'value'],
            'admin.dam.assets.stack-instead', 'admin.api.dam.assets.stack-instead' => ['target_id' => $id],
            'admin.dam.assets.merges.store', 'admin.api.dam.assets.merges.store'   => ['sources' => [$children['source-id']]],
            default                                                                => [],
        };

        // A successful mutation cannot contaminate the next route or its missing-id control.
        DB::beginTransaction();
        try {
            return $this->json($method, route($name, $values), $payload);
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('matrix')]
    public function test_asset_routes_obey_the_access_and_lifecycle_matrix(string $model, string $viewer, string $state): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
        app(AccessModel::class)->flush();
        $permissions = collect(config('acl'))->pluck('key')->push('sphereseam')->unique();
        if (in_array($viewer, ['no-access', 'granted', 'all-directories'], true)) {
            $permissions = $permissions->reject(fn ($key) => $key === 'dam.asset.update');
        }
        $role = Role::factory()->create(['permission_type' => $viewer === 'admin' ? 'all' : 'custom', 'permissions' => $permissions->values()->all()]);
        $actor = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
        if ($viewer === 'all-directories') {
            DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($role->permission_type === 'custom') {
            // Reach the asset gate instead of the tool entitlement gate; the settings API preserves all_directories.
            $this->assertSame(200, app(HomeWidgetAccessController::class)->update(
                request: Request::create('/', 'PUT', ['role_id' => $role->id, 'enabled' => true]),
                widget: 'vectorization',
            )->getStatusCode(), 'The matrix role must receive its explicit tool entitlement.');
        }
        // Use the repository's real OAuth helper, then give that same API actor this matrix role.
        $headers = $this->getAuthenticationHeaders();
        $apiActor = Admin::latest('id')->firstOrFail();
        $apiActor->update(['role_id' => $role->id, 'status' => 1]);
        $folder = Directory::factory()->create();
        $asset = $this->image();
        $folder->assets()->attach($asset->id);
        $file = \attachPreviewFile($asset, 'matrix-sidecar.txt', 'text/plain', AssetFile::ROLE_SIDECAR);
        $comment = AssetComments::factory()->create(['dam_asset_id' => $asset->id, 'admin_id' => $actor->id]);
        $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);
        $source = $this->image();
        $candidateObject = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;
        $this->assertNotNull($candidateObject);
        Storage::disk(Directory::getAssetDisk())->put($source->path, Storage::disk($candidateObject->disk)->get($candidateObject->path));
        $source->markGraphContentChanged()->save();
        $peer = $this->image();
        $controlFolder = Directory::factory()->create();
        $controlFolder->assets()->attach([$source->id, $peer->id]);
        $groupKey = 'matrix-'.bin2hex(random_bytes(8));
        $asset->update(['stack_basis' => 'product', 'stack_key' => $groupKey]);
        $source->update(['stack_basis' => 'campaign', 'stack_key' => $groupKey]);
        $peer->update(['stack_basis' => 'other', 'stack_key' => $groupKey]);
        if ($model === AccessModel::GRANTS) {
            DB::table('dam_directory_role')->insert(['directory_id' => $controlFolder->id, 'role_id' => $role->id]);
        } else {
            foreach ([$actor, $apiActor] as $controlViewer) {
                DB::table('dam_directory_user_access')->insert(['directory_id' => $controlFolder->id, 'admin_id' => $controlViewer->id]);
                foreach ([$source, $peer] as $controlAsset) {
                    DB::table('dam_asset_user_access')->insert(['asset_id' => $controlAsset->id, 'admin_id' => $controlViewer->id]);
                }
            }
        }
        if ($viewer !== 'no-access' && $state !== 'hidden') {
            if ($model === AccessModel::GRANTS) {
                DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
            } else {
                DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $actor->id]);
                DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $actor->id]);
                DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $apiActor->id]);
                DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $apiActor->id]);
            }
        }
        $this->state($asset, $state);
        $this->actingAs($actor, 'admin');
        app(DirectoryPermissionService::class)->flush();
        $this->assertTrue(app(HomeWidgetRegistry::class)->permits('vectorization'), 'The route sweep must reach the asset gate rather than the widget gate.');
        $refused = in_array($state, ['trashed', 'merged'], true)
            || ($viewer === 'no-access')
            || ($state === 'hidden' && $viewer !== 'admin' && ! ($viewer === 'all-directories' && $model === 'grants'))
            || ($state !== 'hidden' && in_array($viewer, ['granted', 'all-directories'], true));
        $this->assertSame(! $refused, app(AssetAccessDecision::class)->for(AccessContext::library(), $asset->id)->visible);
        if ($viewer === 'all-directories') {
            $this->assertFalse(app(DirectoryPermissionService::class)->fullAdmin());
            $this->assertSame($model === 'grants', app(DirectoryPermissionService::class)->bypass());
        }
        if (! $refused) {
            // Editors and full admins can inspect every unavailable state, including its real attachment.
            $this->getJson(route('admin.dam.assets.files.index', $asset->id))->assertOk();
            $this->get(route('admin.dam.file.asset-file', [$asset->id, $file->id]))->assertOk();
            $this->getJson(route('admin.dam.assets.relationships.index', $asset->id))->assertOk();

            return;
        }
        $missing = (int) Asset::withTrashed()->max('id') + 10000;
        $failures = [];
        foreach ($this->assetRoutes() as $name => $definition) {
            $this->defaultHeaders = str_starts_with($name, 'admin.api.') ? $headers : [];
            $hidden = $this->requestFor($name, $definition, $asset->id, $file->id, ['comment-id' => $comment->id, 'property-id' => $property->id, 'path' => $asset->path, 'source-id' => $source->id]);
            $absent = $this->requestFor($name, $definition, $missing, $file->id, ['comment-id' => $comment->id + 10000, 'property-id' => $property->id + 10000, 'path' => 'assets/Root/matrix-missing.png', 'source-id' => $source->id]);
            // Read routes must reach the asset gate. Mutation validators/ACLs can refuse earlier,
            // but must do so identically for a hidden and a missing target.
            $expected = $definition[0] === 'GET' ? [404] : [403, 404, 422];
            if (! in_array($hidden->status(), $expected, true)
                || $hidden->status() !== $absent->status() || $hidden->getContent() !== $absent->getContent()) {
                $failures[] = $name.' ['.$definition[3].']: '.$hidden->status().' / '.$absent->status();
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
        $this->defaultHeaders = [];
        // Inventory listing routes have no asset-id parameter. They still must omit this target.
        foreach (require __DIR__.'/../Fixtures/route-inventory.php' as $name => $kind) {
            if (! str_ends_with($name, 'stack-suggestions.index')) {
                continue;
            }
            $this->assertSame('listing', $kind);
            Cache::flush();
            $this->defaultHeaders = str_starts_with($name, 'admin.api.') ? $headers : [];
            $response = $this->getJson(route($name))->assertOk();
            $groups = $response->json('suggestions') ?? $response->json('data.suggestions');
            $ownGroup = array_values(array_filter($groups, fn ($group) => $group['kind'] === 'key' && $group['key'] === $groupKey));
            $this->assertCount(1, $ownGroup, 'The two visible controls must form a real suggested group: '.$name);
            $this->assertSame(2, $ownGroup[0]['count'], $name);
            foreach ($groups as $group) {
                $this->assertNotContains($asset->id, array_column($group['members'], 'id'), $name);
            }
        }
        $object = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;
        $this->assertNotNull($object);
        $notices = app(ExactDuplicateNotices::class)->notices(
            receipts: [new UploadReceipt(assetId: $source->id, sha256: $object->sha256, outcome: UploadReceipt::CREATED)],
            context: AccessContext::library(),
            canStackInstead: true,
        );
        $this->assertSame([], $notices, 'A hidden original must not be disclosed as an exact duplicate.');
        $this->defaultHeaders = [];
        $this->assertNotNull(Asset::withTrashed()->find($asset->id), 'Refused mutations must not delete the asset.');
    }

    public static function publicStates(): array
    {
        $cases = [];
        foreach (['grants', 'lists'] as $model) {
            foreach (['draft' => 404, 'pending_review' => 404, 'archived' => 410, 'scheduled' => 410, 'expired' => 410, 'trashed' => 410, 'merged' => 410] as $state => $status) {
                $cases["{$model} {$state}"] = [$model, $state, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('publicStates')]
    public function test_public_asset_links_recheck_lifecycle_including_alternate_downloads(string $model, string $state, int $status): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
        app(AccessModel::class)->flush();
        $asset = $this->image();
        $admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($admin, 'admin');
        $attachment = $this->postJson(route('admin.dam.assets.files.store', $asset->id), [
            'file' => UploadedFile::fake()->createWithContent('matrix.txt', 'matrix-alternate-bytes'),
            'role' => AssetFile::ROLE_ALTERNATE,
        ])->assertCreated()->json('attachment_id');
        $share = Share::factory()->forAsset($asset->id)->create();
        $folder = Directory::factory()->create();
        $folder->assets()->attach($asset->id);
        $folderShare = Share::factory()->forDirectory($folder->id)->create();
        Auth::guard('admin')->logout();
        $routes = ['dam.share.show', 'dam.share.download', 'dam.share.file_download', 'dam.share.thumbnail', 'dam.share.preview', 'dam.share.proxy'];
        $parameters = ['token' => $share->urlToken(), 'assetId' => $asset->id, 'attachment' => $attachment];
        $folderParameters = array_replace($parameters, ['token' => $folderShare->urlToken()]);
        // Both alternate URLs really serve a valid attachment before lifecycle changes.
        $this->get(route('dam.share.file_download', $parameters))->assertOk();
        $this->get(route('dam.share.asset_file_download', $folderParameters))->assertOk();
        $this->state($asset, $state);
        foreach ($routes as $name) {
            $response = $this->get(route($name, $parameters));
            $response->assertStatus($status);
            if ($status === 404) {
                $missing = $this->get(route($name, array_replace($parameters, ['token' => bin2hex(random_bytes(20))])));
                $this->assertSame($missing->getContent(), $response->getContent(), $name);
            }
        }
        // A live folder link omits unavailable members: its per-asset URLs are missing, not expired links.
        foreach (['dam.share.asset_view', 'dam.share.asset_download', 'dam.share.asset_file_download', 'dam.share.thumbnail', 'dam.share.asset_preview', 'dam.share.asset_proxy'] as $name) {
            $response = $this->get(route($name, $folderParameters))->assertNotFound();
            $missing = $this->get(route($name, array_replace($folderParameters, ['assetId' => $asset->id + 10000])))->assertNotFound();
            $this->assertSame($missing->getContent(), $response->getContent(), $name);
        }
        $listed = $this->getJson(route('dam.share.list_assets', $folderShare->urlToken()))->assertOk()->json('data');
        $this->assertNotContains($asset->id, array_column($listed, 'id'));
        $this->assertSame(1, $share->fresh()->download_count, 'Only the positive alternate control counts; refused reads do not.');
        $this->assertSame(1, $folderShare->fresh()->download_count);
    }

    public function test_password_and_download_off_never_serve_download_bytes_without_authority(): void
    {
        $asset = $this->image();
        $file = \attachPreviewFile($asset, 'matrix-alternate.txt', 'text/plain', AssetFile::ROLE_ALTERNATE);
        $folder = Directory::factory()->create();
        $folder->assets()->attach($asset->id);
        Auth::guard('admin')->logout();
        foreach ([true, false] as $protected) {
            $attributes = $protected
                ? ['password_hash' => Hash::make(bin2hex(random_bytes(24)))]
                : ['allow_download' => false];
            $direct = Share::factory()->forAsset($asset->id)->create($attributes);
            $group = Share::factory()->forDirectory($folder->id)->create($attributes);
            foreach (require __DIR__.'/../Fixtures/route-inventory.php' as $name => $kind) {
                if ($kind !== 'public' || ! str_starts_with($name, 'dam.share.') || $name === 'dam.share.unlock') {
                    continue;
                }
                if (! $protected && ! in_array($name, ShareGate::DOWNLOAD_ROUTES, true)) {
                    continue;
                }
                $route = Route::getRoutes()->getByName($name);
                $this->assertNotNull($route);
                if ($name === 'dam.share.slug') {
                    // The custom address serves nothing: it only leads to the token URL, where the gate answers.
                    $slug = 'matrix-'.bin2hex(random_bytes(6));
                    DB::table('dam_shares')->where('id', $direct->id)->update(['slug' => $slug]);
                    $alias = $this->get(route($name, ['slug' => $slug]));
                    $alias->assertRedirect(route('dam.share.show', ['token' => $direct->urlToken()]));
                    $this->get($alias->headers->get('Location'))->assertStatus(401);

                    continue;
                }
                $member = in_array('assetId', $route->parameterNames(), true) || in_array($name, ['dam.share.download_zip', 'dam.share.list_assets'], true);
                $values = [
                    'token'      => ($member ? $group : $direct)->urlToken(),
                    'assetId'    => $asset->id,
                    'attachment' => $file->attachment_id,
                    'grant'      => app(ShareGate::class)->pageGrant($direct),
                    // A member page's whole identity: {asset}.{attachment}.{file}.{full sha256}.
                    'pin'        => $asset->id.'.'.$file->attachment_id.'.'.$file->id.'.'.$file->fileObject->sha256,
                ];
                // A valid page grant is an alternate authority; this test intentionally sends none.
                if (in_array($name, ['dam.share.page_granted', 'dam.share.page_file_granted'], true)) {
                    $values['grant'] = '1.'.str_repeat('0', 64);
                }
                $response = $this->get(route($name, $values));
                $response->assertStatus($protected ? 401 : 403);
                if ($protected && ! in_array($name, ShareGate::PROMPT_ROUTES, true)) {
                    $this->assertSame('', $response->getContent(), $name);
                }
            }
            $this->assertSame(0, $direct->fresh()->download_count);
            $this->assertSame(0, $group->fresh()->download_count);
        }
    }

    public function test_active_merged_source_share_serves_only_its_contributed_slots(): void
    {
        $admin = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
        $this->actingAs($admin, 'admin');
        app(DirectoryPermissionService::class)->flush();
        $source = $this->image();
        $destination = $this->image();
        $own = \attachPreviewFile($source, 'source-alternate.txt', 'text/plain', AssetFile::ROLE_ALTERNATE);
        $other = \attachPreviewFile($destination, 'destination-alternate.txt', 'text/plain', AssetFile::ROLE_ALTERNATE);
        $share = Share::factory()->forAsset($source->id)->create();
        $original = $source->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;
        $this->assertNotNull($original);
        app(AssetMergeService::class)->merge(destination: $destination, sourceIds: [$source->id], unionTags: true, actor: $admin, via: AccessContext::VIA_LIBRARY);
        $slot = Attachment::where('dam_asset_id', $destination->id)->where('origin_attachment_id', $own->attachment_id)->sole();
        Auth::guard('admin')->logout();
        $response = $this->get(route('dam.share.download', $share->urlToken()))->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(Storage::disk($original->disk)->get($original->path), file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $slot->id]))->assertOk();
        $refused = $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $other->attachment_id]))->assertNotFound();
        $missing = $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $other->attachment_id + 10000]))->assertNotFound();
        $this->assertSame($missing->getContent(), $refused->getContent());
        $this->assertSame(2, $share->fresh()->download_count);
    }
}
