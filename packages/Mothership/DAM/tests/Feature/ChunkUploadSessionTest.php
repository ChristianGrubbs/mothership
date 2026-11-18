<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\ChunkUploadPart;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->admin = $this->loginAsAdmin();

    $this->validChunkMetadata = function (array $overrides = []): array {
        $directory = Directory::factory()->create();
        $tracker = UploadTracker::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => auth()->id(),
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
        ]);

        return array_merge([
            'session_uuid'  => $tracker->uuid,
            'directory_id'  => $directory->id,
            'original_name' => 'master.mp4',
            'mime_type'     => 'video/mp4',
            'size'          => 12,
            'last_modified' => 1_788_864_000_000,
            'fingerprint'   => str_repeat('a', 64),
            'relative_path' => null,
            'preserve_root' => false,
        ], $overrides);
    };
});

it('initializes the exact 20 GiB boundary', function () {
    $metadata = ($this->validChunkMetadata)(['size' => 21_474_836_480]);

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('upload.chunk_size', 33_554_432)
        ->assertJsonPath('upload.chunk_count', 640)
        ->assertJsonPath('upload.received_indices', []);
});

it('rejects one byte above 20 GiB before transfer', function () {
    // The table may already hold rows (a development database): the refusal must add none.
    $before = ChunkUpload::query()->count();

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), ($this->validChunkMetadata)([
        'size' => 21_474_836_481,
    ]))
        ->assertStatus(413)
        ->assertJsonPath('code', 'file_too_large')
        ->assertJsonPath('retryable', false);

    expect(ChunkUpload::query()->count())->toBe($before);
});

it('rejects path separators in the original filename', function (string $name) {
    $before = ChunkUpload::query()->count();

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), ($this->validChunkMetadata)([
        'original_name' => $name,
    ]))->assertUnprocessable()->assertJsonPath('code', 'invalid_upload_name');
    expect(ChunkUpload::query()->count())->toBe($before);
})->with(['../outside.txt', 'nested/file.txt', 'nested\\file.txt']);

it('rejects unauthenticated initialization', function () {
    $metadata = ($this->validChunkMetadata)();
    auth('admin')->logout();

    $response = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata);

    expect(in_array($response->status(), [302, 401, 403], true))->toBeTrue();
});

it('rejects initialization into an inaccessible directory', function () {
    $metadata = ($this->validChunkMetadata)();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.upload']]);
    // A granted child keeps the target in the role's tree, so the refusal is the service's own, not the
    // missing-id answer a folder the role cannot see gets.
    $child = Directory::factory()->create(['parent_id' => $metadata['directory_id']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $child->id, 'role_id' => $role->id]);
    $limitedAdmin = Admin::factory()->create(['role_id' => $role->id]);
    UploadTracker::where('uuid', $metadata['session_uuid'])->update(['user_id' => $limitedAdmin->id]);
    $this->actingAs($limitedAdmin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)
        ->assertForbidden()
        ->assertJsonPath('code', 'directory_forbidden');
});

it('returns the same upload for identical initialization and rejects a changed source', function () {
    $metadata = ($this->validChunkMetadata)();

    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)
        ->assertCreated()
        ->json('upload.uuid');

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)
        ->assertOk()
        ->assertJsonPath('upload.uuid', $uuid);

    $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), array_merge($metadata, [
        'fingerprint' => str_repeat('b', 64),
    ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'source_mismatch')
        ->assertJsonPath('retryable', false);
});

it('returns accepted indices and an ISO-8601 expiry from status', function () {
    $metadata = ($this->validChunkMetadata)();
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)->json('upload.uuid');
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    ChunkUploadPart::create([
        'chunk_upload_id' => $upload->id,
        'chunk_index'     => 0,
        'byte_offset'     => 0,
        'byte_length'     => 10,
        'sha256'          => hash('sha256', 'abcdefghij'),
        'staged_path'     => $upload->staged_root.'/parts/0.part',
    ]);

    $response = $this->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('upload.received_indices.0', 0);

    expect(date_create_immutable($response->json('upload.expires_at')))->not->toBeFalse();
});

it('returns 404 for an unknown upload', function () {
    $this->getJson(route('admin.dam.assets.chunk-upload.status', (string) Str::uuid()))
        ->assertNotFound()
        ->assertJsonPath('code', 'upload_not_found');
});

it('prevents a different user from inspecting an upload', function () {
    $metadata = ($this->validChunkMetadata)();
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)->json('upload.uuid');
    $this->actingAs(Admin::factory()->create(), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))
        ->assertForbidden()
        ->assertJsonPath('code', 'upload_forbidden');
});

it('rechecks directory permission when inspecting an upload', function () {
    $metadata = ($this->validChunkMetadata)();
    $uuid = $this->postJson(route('admin.dam.assets.chunk-upload.initialize'), $metadata)->json('upload.uuid');
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.upload']]);
    $this->admin->update(['role_id' => $role->id]);
    $this->admin->unsetRelation('role');
    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => false, 'created_at' => now(), 'updated_at' => now()],
    );
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))
        ->assertForbidden()
        ->assertJsonPath('code', 'directory_forbidden');
});
