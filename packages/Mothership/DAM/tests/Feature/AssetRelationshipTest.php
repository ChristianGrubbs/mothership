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

beforeEach(function () {
    $this->loginAsAdmin();
});

function actAsRestrictedRelationshipAdmin(Directory $grantedDirectory, array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $grantedDirectory->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $admin = Admin::factory()->create(['role_id' => $role->id]);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return $admin;
}

function attachRelationshipAssetToDirectory(Asset $asset, Directory $directory): Asset
{
    $asset->directories()->attach($directory->id);

    return $asset;
}

it('lists outgoing and incoming relationships grouped by type', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'current.jpg',
        'title'     => 'Current asset',
    ]);
    $outgoingAsset = Asset::factory()->create([
        'file_name' => 'texture.jpg',
        'title'     => 'Texture',
        'path'      => 'assets/Root/texture.jpg',
    ]);
    $incomingAsset = Asset::factory()->create([
        'file_name' => 'container.glb',
        'title'     => 'Container',
        'path'      => 'assets/Root/container.glb',
    ]);

    $outgoing = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $outgoingAsset->id,
        'type'          => AssetRelationship::TYPE_USES_TEXTURE,
        'metadata'      => ['channel' => 'albedo'],
    ]);
    $incoming = AssetRelationship::factory()->create([
        'from_asset_id' => $incomingAsset->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_CONTAINS,
    ]);

    $response = $this->getJson(route('admin.dam.assets.relationships.index', $asset->id));

    $response->assertOk()
        ->assertJsonPath('outgoing.uses-texture.0.relationship_id', $outgoing->id)
        ->assertJsonPath('outgoing.uses-texture.0.id', $outgoingAsset->id)
        ->assertJsonPath('outgoing.uses-texture.0.file_name', 'texture.jpg')
        ->assertJsonPath('outgoing.uses-texture.0.title', 'Texture')
        ->assertJsonPath(
            'outgoing.uses-texture.0.thumbnail_url',
            route('admin.dam.file.thumbnail', ['path' => urlencode('assets/Root/texture.jpg')])
        )
        ->assertJsonPath('outgoing.uses-texture.0.asset.id', $outgoingAsset->id)
        ->assertJsonPath('outgoing.uses-texture.0.asset.file_name', 'texture.jpg')
        ->assertJsonPath('outgoing.uses-texture.0.asset.title', 'Texture')
        ->assertJsonPath(
            'outgoing.uses-texture.0.asset.thumbnail_url',
            route('admin.dam.file.thumbnail', ['path' => urlencode('assets/Root/texture.jpg')])
        )
        ->assertJsonPath('outgoing.uses-texture.0.url', route('admin.dam.assets.edit', $outgoingAsset->id))
        ->assertJsonPath('outgoing.uses-texture.0.asset.url', route('admin.dam.assets.edit', $outgoingAsset->id))
        ->assertJsonPath('outgoing.uses-texture.0.metadata.channel', 'albedo')
        ->assertJsonPath('incoming.contains.0.relationship_id', $incoming->id)
        ->assertJsonPath('incoming.contains.0.id', $incomingAsset->id)
        ->assertJsonPath('incoming.contains.0.file_name', 'container.glb')
        ->assertJsonPath('incoming.contains.0.title', 'Container')
        ->assertJsonPath(
            'incoming.contains.0.thumbnail_url',
            route('admin.dam.file.thumbnail', ['path' => urlencode('assets/Root/container.glb')])
        )
        ->assertJsonPath('incoming.contains.0.asset.id', $incomingAsset->id)
        ->assertJsonPath('incoming.contains.0.asset.file_name', 'container.glb')
        ->assertJsonPath('incoming.contains.0.asset.title', 'Container')
        ->assertJsonPath('incoming.contains.0.url', route('admin.dam.assets.edit', $incomingAsset->id))
        ->assertJsonPath('incoming.contains.0.asset.url', route('admin.dam.assets.edit', $incomingAsset->id))
        ->assertJsonPath(
            'incoming.contains.0.asset.thumbnail_url',
            route('admin.dam.file.thumbnail', ['path' => urlencode('assets/Root/container.glb')])
        )
        ->assertJsonFragment(['types' => AssetRelationship::TYPES]);
});

it('creates a relationship with metadata', function () {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();

    $response = $this->postJson(route('admin.dam.assets.relationships.store', $asset->id), [
        'to_asset_id' => $target->id,
        'type'        => AssetRelationship::TYPE_DERIVED_FROM,
        'metadata'    => ['reason' => 'retouched'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('relationship.type', AssetRelationship::TYPE_DERIVED_FROM)
        ->assertJsonPath('relationship.asset.id', $target->id)
        ->assertJsonPath('relationship.metadata.reason', 'retouched');

    $this->assertDatabaseHas('dam_asset_relationships', [
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $target->id,
        'type'          => AssetRelationship::TYPE_DERIVED_FROM,
    ]);
});

it('rejects a duplicate relationship with 422', function () {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();

    AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $target->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    $response = $this->postJson(route('admin.dam.assets.relationships.store', $asset->id), [
        'to_asset_id' => $target->id,
        'type'        => AssetRelationship::TYPE_RELATED_TO,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['to_asset_id']);

    expect(AssetRelationship::query()
        ->where('from_asset_id', $asset->id)
        ->where('to_asset_id', $target->id)
        ->where('type', AssetRelationship::TYPE_RELATED_TO)
        ->count())->toBe(1);
});

it('treats a relationship type in another case as the same type (UX eval G2-16)', function () {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();
    $store = fn (string $type) => $this->postJson(route('admin.dam.assets.relationships.store', $asset->id), ['to_asset_id' => $target->id, 'type' => $type]);

    $store(AssetRelationship::TYPE_RELATED_TO)->assertCreated();
    $store(' Related-To ')->assertUnprocessable()->assertJsonValidationErrors(['to_asset_id']);

    // A vocabulary type is stored in its own spelling; a custom one keeps the person's, once.
    $store('Version-Of')->assertCreated()->assertJsonPath('relationship.type', AssetRelationship::TYPE_VERSION_OF);
    $store('Lit-By')->assertCreated();
    $store('lit-by')->assertUnprocessable();

    expect(AssetRelationship::query()->where('from_asset_id', $asset->id)->pluck('type')->sort()->values()->all())
        ->toBe(['Lit-By', AssetRelationship::TYPE_RELATED_TO, AssetRelationship::TYPE_VERSION_OF]);
});

it('rejects a self relationship with 422', function () {
    $asset = Asset::factory()->create();

    $response = $this->postJson(route('admin.dam.assets.relationships.store', $asset->id), [
        'to_asset_id' => $asset->id,
        'type'        => AssetRelationship::TYPE_RELATED_TO,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['to_asset_id']);

    expect(AssetRelationship::query()->where('from_asset_id', $asset->id)->exists())->toBeFalse();
});

it('deletes a relationship attached to the asset', function () {
    $asset = Asset::factory()->create();
    $source = Asset::factory()->create();
    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $source->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_CONTAINS,
    ]);

    $response = $this->deleteJson(route('admin.dam.assets.relationships.destroy', [
        'asset'        => $asset->id,
        'relationship' => $relationship->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('deleted', true);

    $this->assertDatabaseMissing('dam_asset_relationships', ['id' => $relationship->id]);
});

it('lists the asset files with role label size and sha256', function () {
    $asset = Asset::factory()->create();
    $fileObject = FileObject::factory()->create([
        'size'      => 4096,
        'sha256'    => str_repeat('a', 64),
        'mime_type' => 'image/png',
    ]);
    $assetFile = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $fileObject->id,
        'role'           => AssetFile::ROLE_SIDECAR,
        'label'          => 'Camera metadata',
        'sort'           => 3,
    ]);

    $response = $this->getJson(route('admin.dam.assets.files.index', $asset->id));

    $response->assertOk()
        ->assertJsonPath('files.0.id', $assetFile->id)
        ->assertJsonPath('files.0.role', AssetFile::ROLE_SIDECAR)
        ->assertJsonPath('files.0.label', 'Camera metadata')
        ->assertJsonPath('files.0.size', 4096)
        ->assertJsonPath('files.0.sha256', str_repeat('a', 64))
        ->assertJsonPath('files.0.mime_type', 'image/png');

    $html = $this->get(route('admin.dam.assets.edit', $asset->id).'?files')->assertOk()->getContent();
    expect(str_contains($html, 'data-dam-preview-backdrop'))->toBeTrue('Files tab must include the shared in-page viewer')
        ->and(str_contains($html, 'data-dam-file-preview'))->toBeFalse('Files tab must not render a redundant private viewer')
        ->and(str_contains($html, "emit('dam-open-preview'"))->toBeTrue('Open must route to the shared viewer event')
        ->and(str_contains($html, 'data-dam-file-open'))->toBeTrue('Open must be an in-page button')
        ->and(str_contains($html, 'data-dam-file-text'))->toBeTrue('Text files must render in the built-in viewer')
        ->and(str_contains($html, ':href="file.url" target="_blank"'))->toBeFalse('Open must not leave the page');
});

it('redirects unauthenticated relationship requests to the admin login', function () {
    $asset = Asset::factory()->create();

    auth('admin')->logout();

    $this->get(route('admin.dam.assets.relationships.index', $asset->id))
        ->assertRedirect(route('admin.session.create'));
});

it('omits outgoing and incoming relationships whose opposite asset is inaccessible', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();

    $asset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $visibleAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $deniedOutgoingAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $deniedIncomingAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);

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

    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.index']);

    $response = $this->getJson(route('admin.dam.assets.relationships.index', $asset->id));

    $response->assertOk()
        ->assertJsonPath('outgoing.related-to.0.relationship_id', $visible->id)
        ->assertJsonMissing(['relationship_id' => $deniedOutgoing->id])
        ->assertJsonMissing(['relationship_id' => $deniedIncoming->id]);
});

it('paginates dense relationship directions with a bounded query count for restricted roles', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $firstDeniedRelationship = null;

    foreach (range(1, 26) as $index) {
        $visibleOutgoing = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
        $visibleIncoming = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
        $deniedOutgoing = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
        $deniedIncoming = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);

        AssetRelationship::factory()->create([
            'from_asset_id' => $asset->id,
            'to_asset_id'   => $visibleOutgoing->id,
            'type'          => AssetRelationship::TYPE_RELATED_TO,
        ]);
        AssetRelationship::factory()->create([
            'from_asset_id' => $visibleIncoming->id,
            'to_asset_id'   => $asset->id,
            'type'          => AssetRelationship::TYPE_RELATED_TO,
        ]);
        $deniedRelationship = AssetRelationship::factory()->create([
            'from_asset_id' => $asset->id,
            'to_asset_id'   => $deniedOutgoing->id,
            'type'          => AssetRelationship::TYPE_RELATED_TO,
        ]);
        AssetRelationship::factory()->create([
            'from_asset_id' => $deniedIncoming->id,
            'to_asset_id'   => $asset->id,
            'type'          => AssetRelationship::TYPE_RELATED_TO,
        ]);

        $firstDeniedRelationship ??= $deniedRelationship;
    }

    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.index']);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson(route('admin.dam.assets.relationships.index', $asset->id));
    $queries = DB::getQueryLog();
    $queryCount = count($queries);
    DB::disableQueryLog();

    if (getenv('DAM_RELATIONSHIP_QUERY_PROBE') === '1') {
        fwrite(STDERR, json_encode([
            'event' => 'dam.relationship_query_probe',
            'count' => $queryCount,
            'sql'   => array_column($queries, 'query'),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    $response->assertOk()
        ->assertJsonCount(25, 'outgoing.related-to')
        ->assertJsonCount(25, 'incoming.related-to')
        ->assertJsonPath('pagination.outgoing.current_page', 1)
        ->assertJsonPath('pagination.outgoing.last_page', 2)
        ->assertJsonPath('pagination.outgoing.per_page', 25)
        ->assertJsonPath('pagination.outgoing.total', 26)
        ->assertJsonPath('pagination.incoming.total', 26)
        ->assertJsonMissing(['relationship_id' => $firstDeniedRelationship->id]);

    expect($queryCount)->toBeLessThanOrEqual(30);

    $this->getJson(route('admin.dam.assets.relationships.index', [
        'asset'         => $asset->id,
        'outgoing_page' => 2,
        'incoming_page' => 2,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'outgoing.related-to')
        ->assertJsonCount(1, 'incoming.related-to')
        ->assertJsonPath('pagination.outgoing.current_page', 2)
        ->assertJsonPath('pagination.incoming.current_page', 2);
});

it('returns 404, as for a missing id, when listing relationships for an inaccessible asset', function () {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $asset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);

    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.index']);

    $this->getJson(route('admin.dam.assets.relationships.index', $asset->id))
        ->assertNotFound();
});

it('refuses to create a relationship when either endpoint is inaccessible', function (bool $sourceIsAllowed) {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $allowedAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $deniedAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);

    $source = $sourceIsAllowed ? $allowedAsset : $deniedAsset;
    $target = $sourceIsAllowed ? $deniedAsset : $allowedAsset;

    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.store']);

    $this->postJson(route('admin.dam.assets.relationships.store', $source->id), [
        'to_asset_id' => $target->id,
        'type'        => AssetRelationship::TYPE_RELATED_TO,
    ])->assertStatus($sourceIsAllowed ? 422 : 404);

    $this->assertDatabaseMissing('dam_asset_relationships', [
        'from_asset_id' => $source->id,
        'to_asset_id'   => $target->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);
})->with([
    'allowed to denied' => [true],
    'denied to allowed' => [false],
]);

it('refuses to delete a relationship when either endpoint is inaccessible', function (bool $sourceIsAllowed) {
    $allowedDirectory = Directory::factory()->create();
    $deniedDirectory = Directory::factory()->create();
    $allowedAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $deniedAsset = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);

    $relationship = AssetRelationship::factory()->create([
        'from_asset_id' => $sourceIsAllowed ? $allowedAsset->id : $deniedAsset->id,
        'to_asset_id'   => $sourceIsAllowed ? $deniedAsset->id : $allowedAsset->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.destroy']);

    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [
        'asset'        => $allowedAsset->id,
        'relationship' => $relationship->id,
    ]))->assertNotFound();

    $this->assertDatabaseHas('dam_asset_relationships', ['id' => $relationship->id]);
})->with([
    'allowed to denied' => [true],
    'denied to allowed' => [false],
]);

it('keeps relationship reads and writes aligned for multi-directory subjects', function () {
    $deniedDirectory = Directory::factory()->create();
    $allowedDirectory = Directory::factory()->create();
    $deniedFirst = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $deniedFirst->directories()->attach($allowedDirectory->id);
    $allowedFirst = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $allowedFirst->directories()->attach(Directory::factory()->create()->id);
    $target = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    actAsRestrictedRelationshipAdmin($allowedDirectory, ['dam.asset.relationships.index', 'dam.asset.relationships.store']);

    $this->getJson(route('admin.dam.assets.relationships.index', $deniedFirst->id))->assertForbidden();
    $this->postJson(route('admin.dam.assets.relationships.store', $deniedFirst->id), [
        'to_asset_id' => $target->id, 'type' => AssetRelationship::TYPE_RELATED_TO,
    ])->assertForbidden();
    $this->getJson(route('admin.dam.assets.relationships.index', $allowedFirst->id))->assertOk();
    $this->postJson(route('admin.dam.assets.relationships.store', $allowedFirst->id), [
        'to_asset_id' => $target->id, 'type' => AssetRelationship::TYPE_RELATED_TO,
    ])->assertSuccessful();
});

it('uses the listed target access rule when creating and deleting relationships', function () {
    $deniedDirectory = Directory::factory()->create();
    $allowedDirectory = Directory::factory()->create();
    $subject = attachRelationshipAssetToDirectory(Asset::factory()->create(), $allowedDirectory);
    $target = attachRelationshipAssetToDirectory(Asset::factory()->create(), $deniedDirectory);
    $target->directories()->attach($allowedDirectory->id);
    expect((int) DB::table('dam_asset_directory')->where('asset_id', $target->id)->value('directory_id'))
        ->toBe($deniedDirectory->id);
    actAsRestrictedRelationshipAdmin($allowedDirectory, [
        'dam.asset.relationships.index', 'dam.asset.relationships.store', 'dam.asset.relationships.destroy',
    ]);
    $response = $this->postJson(route('admin.dam.assets.relationships.store', $subject->id), [
        'to_asset_id' => $target->id, 'type' => AssetRelationship::TYPE_RELATED_TO,
    ])->assertSuccessful();
    $relationshipId = $response->json('relationship.relationship_id');
    $this->getJson(route('admin.dam.assets.relationships.index', $subject->id))->assertOk()
        ->assertJsonPath('outgoing.related-to.0.id', $target->id);
    $this->deleteJson(route('admin.dam.assets.relationships.destroy', [
        'asset' => $subject->id, 'relationship' => $relationshipId,
    ]))->assertSuccessful();
    expect(AssetRelationship::find($relationshipId))->toBeNull();
});
