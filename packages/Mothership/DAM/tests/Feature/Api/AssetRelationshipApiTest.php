<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

function relationshipApiHeaders(array $permissions, ?Directory $grantedDirectory = null): array
{
    $headers = test()->getAuthenticationHeaders('custom', $permissions);

    if ($grantedDirectory) {
        $role = Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => ['dam.asset.view'],
        ]);

        DB::table('dam_directory_role')->insert([
            'directory_id' => $grantedDirectory->id,
            'role_id'      => $role->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        Admin::latest('id')->firstOrFail()->update(['role_id' => $role->id]);
        app(DirectoryPermissionService::class)->flush();
    }

    return $headers;
}

function attachApiRelationshipAsset(Asset $asset, Directory $directory): Asset
{
    $asset->directories()->attach($directory->id);

    return $asset;
}

it('serves relationship and file operations to a token with every required scope', function () {
    $asset = Asset::factory()->create();
    $listedTarget = Asset::factory()->create();
    $createdTarget = Asset::factory()->create();
    $listedRelationship = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $listedTarget->id,
        'type'          => AssetRelationship::TYPE_USES_TEXTURE,
    ]);
    $fileObject = FileObject::factory()->create([
        'size'   => 2048,
        'sha256' => str_repeat('b', 64),
    ]);
    $assetFile = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $fileObject->id,
        'role'           => AssetFile::ROLE_SIDECAR,
    ]);
    $headers = relationshipApiHeaders([
        'api.dam.assets.relationships.index',
        'api.dam.assets.relationships.store',
        'api.dam.assets.relationships.destroy',
        'api.dam.assets.files.index',
    ]);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.relationships.index', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.outgoing.uses-texture.0.relationship_id', $listedRelationship->id);

    $createResponse = $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.relationships.store', $asset->id), [
            'to_asset_id' => $createdTarget->id,
            'type'        => AssetRelationship::TYPE_DERIVED_FROM,
            'metadata'    => ['reason' => 'api test'],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.relationship.asset.id', $createdTarget->id);

    $this->withHeaders($headers)
        ->deleteJson(route('admin.api.dam.assets.relationships.destroy', [
            'asset'        => $asset->id,
            'relationship' => $listedRelationship->id,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.deleted', true);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.files.index', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.files.0.id', $assetFile->id)
        ->assertJsonPath('data.files.0.sha256', str_repeat('b', 64));
});

it('returns 403 when the token lacks the operation scope', function (string $operation) {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();
    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $target->id,
    ]);
    $headers = relationshipApiHeaders([]);

    [$method, $route, $payload] = match ($operation) {
        'index' => ['GET', route('admin.api.dam.assets.relationships.index', $asset->id), []],
        'store' => ['POST', route('admin.api.dam.assets.relationships.store', $asset->id), [
            'to_asset_id' => $target->id,
            'type'        => AssetRelationship::TYPE_RELATED_TO,
        ]],
        'destroy' => ['DELETE', route('admin.api.dam.assets.relationships.destroy', [
            'asset'        => $asset->id,
            'relationship' => $relationship->id,
        ]), []],
        'files' => ['GET', route('admin.api.dam.assets.files.index', $asset->id), []],
    };

    $this->withHeaders($headers)->json($method, $route, $payload)->assertForbidden();
})->with(['index', 'store', 'destroy', 'files']);

it('omits relationships to assets outside the api users granted directories', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachApiRelationshipAsset(Asset::factory()->create(), $allowedDirectory);
    $visibleAsset = attachApiRelationshipAsset(Asset::factory()->create(), $allowedDirectory);
    $deniedOutgoingAsset = attachApiRelationshipAsset(Asset::factory()->create(), $deniedDirectory);
    $deniedIncomingAsset = attachApiRelationshipAsset(Asset::factory()->create(), $deniedDirectory);
    $visible = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $visibleAsset->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);
    $deniedOutgoing = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $deniedOutgoingAsset->id,
        'type'          => AssetRelationship::TYPE_USES_TEXTURE,
    ]);
    $deniedIncoming = AssetRelationship::factory()->create([
        'from_asset_id' => $deniedIncomingAsset->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_CONTAINS,
    ]);
    $headers = relationshipApiHeaders(
        ['api.dam.assets.relationships.index'],
        $allowedDirectory
    );

    $response = $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.relationships.index', $asset->id));

    $response->assertOk()
        ->assertJsonPath('data.outgoing.related-to.0.relationship_id', $visible->id)
        ->assertJsonMissing(['relationship_id' => $deniedOutgoing->id])
        ->assertJsonMissing(['relationship_id' => $deniedIncoming->id]);
});

it('returns 403 for relationship and file operations on an inaccessible requested asset', function (string $operation) {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $deniedAsset = attachApiRelationshipAsset(Asset::factory()->create(), $deniedDirectory);
    $allowedAsset = attachApiRelationshipAsset(Asset::factory()->create(), $allowedDirectory);
    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $deniedAsset->id,
        'to_asset_id'   => $allowedAsset->id,
    ]);
    $scope = match ($operation) {
        'index'   => 'api.dam.assets.relationships.index',
        'store'   => 'api.dam.assets.relationships.store',
        'destroy' => 'api.dam.assets.relationships.destroy',
        'files'   => 'api.dam.assets.files.index',
    };
    $headers = relationshipApiHeaders([$scope], $allowedDirectory);

    [$method, $route, $payload] = match ($operation) {
        'index' => ['GET', route('admin.api.dam.assets.relationships.index', $deniedAsset->id), []],
        'store' => ['POST', route('admin.api.dam.assets.relationships.store', $deniedAsset->id), [
            'to_asset_id' => $allowedAsset->id,
            'type'        => AssetRelationship::TYPE_RELATED_TO,
        ]],
        'destroy' => ['DELETE', route('admin.api.dam.assets.relationships.destroy', [
            'asset'        => $deniedAsset->id,
            'relationship' => $relationship->id,
        ]), []],
        'files' => ['GET', route('admin.api.dam.assets.files.index', $deniedAsset->id), []],
    };

    $this->withHeaders($headers)->json($method, $route, $payload)->assertNotFound();
})->with(['index', 'store', 'destroy', 'files']);

it('refuses creating or deleting an api relationship with an inaccessible opposite endpoint as if it were missing', function (string $operation, bool $sourceIsAllowed) {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $allowedAsset = attachApiRelationshipAsset(Asset::factory()->create(), $allowedDirectory);
    $deniedAsset = attachApiRelationshipAsset(Asset::factory()->create(), $deniedDirectory);
    $source = $sourceIsAllowed ? $allowedAsset : $deniedAsset;
    $target = $sourceIsAllowed ? $deniedAsset : $allowedAsset;
    $scope = $operation === 'store'
        ? 'api.dam.assets.relationships.store'
        : 'api.dam.assets.relationships.destroy';
    $headers = relationshipApiHeaders([$scope], $allowedDirectory);

    if ($operation === 'store') {
        $this->withHeaders($headers)
            ->postJson(route('admin.api.dam.assets.relationships.store', $source->id), [
                'to_asset_id' => $target->id,
                'type'        => AssetRelationship::TYPE_RELATED_TO,
            ])
            ->assertStatus($sourceIsAllowed ? 422 : 404);

        $this->assertDatabaseMissing('dam_asset_relationships', [
            'from_asset_id' => $source->id,
            'to_asset_id'   => $target->id,
        ]);

        return;
    }

    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $source->id,
        'to_asset_id'   => $target->id,
    ]);

    $this->withHeaders($headers)
        ->deleteJson(route('admin.api.dam.assets.relationships.destroy', [
            'asset'        => $allowedAsset->id,
            'relationship' => $relationship->id,
        ]))
        ->assertNotFound();

    $this->assertDatabaseHas('dam_asset_relationships', ['id' => $relationship->id]);
})->with([
    'store allowed to denied'   => ['store', true],
    'store denied to allowed'   => ['store', false],
    'destroy allowed to denied' => ['destroy', true],
    'destroy denied to allowed' => ['destroy', false],
]);

it('returns 401 to unauthenticated relationship and file requests', function (string $operation) {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();
    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $target->id,
    ]);

    [$method, $route, $payload] = match ($operation) {
        'index' => ['GET', route('admin.api.dam.assets.relationships.index', $asset->id), []],
        'store' => ['POST', route('admin.api.dam.assets.relationships.store', $asset->id), [
            'to_asset_id' => $target->id,
            'type'        => AssetRelationship::TYPE_RELATED_TO,
        ]],
        'destroy' => ['DELETE', route('admin.api.dam.assets.relationships.destroy', [
            'asset'        => $asset->id,
            'relationship' => $relationship->id,
        ]), []],
        'files' => ['GET', route('admin.api.dam.assets.files.index', $asset->id), []],
    };

    $this->json($method, $route, $payload)->assertUnauthorized();
})->with(['index', 'store', 'destroy', 'files']);
