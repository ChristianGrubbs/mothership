<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

// Large "Related files" uploads go through the resumable chunk path and finish as an AssetFile on the
// asset, not as a new asset (one request is capped by post_max_size and by Cloudflare's body limit).

beforeEach(function () {
    $this->loginAsAdmin();
    config(['dam.explorer.upload.chunk_bytes' => 4]);
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->asset = Asset::factory()->create(['file_name' => 'logo.png', 'extension' => 'png']);
    $this->initUrl = route('admin.dam.assets.files.chunks', $this->asset->id);

    $this->startAttach = function (string $contents, string $name = 'logo.psd', string $role = 'alternate', ?string $label = null) {
        return $this->postJson($this->initUrl, [
            'original_name' => $name,
            'mime_type'     => 'application/octet-stream',
            'size'          => strlen($contents),
            'last_modified' => 1_788_864_000_000,
            'fingerprint'   => hash('sha256', $contents),
            'role'          => $role,
            'label'         => $label,
        ]);
    };

    $this->sendParts = function (string $uuid, string $contents): void {
        foreach (str_split($contents, 4) as $index => $chunk) {
            $start = $index * 4;
            $this->call('PUT', route('admin.dam.assets.chunk-upload.store', [$uuid, $index]), server: [
                'CONTENT_TYPE'        => 'application/octet-stream',
                'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, strlen($contents)),
                'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
            ], content: $chunk)->assertOk();
        }
    };

    $this->finish = function (string $uuid): ChunkUpload {
        $this->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))->assertAccepted();
        $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
        (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

        return $upload->fresh();
    };
});

it('attaches a chunked upload to the asset under its role instead of creating an asset', function () {
    $contents = 'layered-photoshop-bytes';
    $assets = Asset::count();
    $uuid = ($this->startAttach)($contents)->assertCreated()->json('upload.uuid');
    ($this->sendParts)($uuid, $contents);
    $upload = ($this->finish)($uuid);

    $file = AssetFile::findOrFail($upload->asset_file_id);
    expect($upload->state)->toBe(ChunkUpload::STATE_COMPLETED)
        ->and((int) $upload->asset_id)->toBe($this->asset->id)
        ->and(Asset::count())->toBe($assets)
        ->and($file->dam_asset_id)->toBe($this->asset->id)
        ->and($file->role)->toBe('alternate')
        ->and($file->label)->toBe('logo.psd')
        ->and($file->fileObject->sha256)->toBe(hash('sha256', $contents))
        ->and(Storage::disk($file->fileObject->disk)->get($file->fileObject->path))->toBe($contents);
    $this->getJson(route('admin.dam.assets.chunk-upload.status', $uuid))->assertOk()
        ->assertJsonPath('upload.asset_file_id', $file->id);
});

it('accepts a zip that would be refused as a web page bundle, since attachments are only downloaded', function () {
    $path = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<h1>hi</h1>');
    $zip->addFromString('shell.php', '<?php echo 1;');
    $zip->close();
    $contents = file_get_contents($path);

    $uuid = ($this->startAttach)($contents, 'site-source.zip')->assertCreated()->json('upload.uuid');
    ($this->sendParts)($uuid, $contents);
    expect(($this->finish)($uuid)->state)->toBe(ChunkUpload::STATE_COMPLETED);

    // The single-request path agrees.
    $this->post(route('admin.dam.assets.files.store', $this->asset->id), [
        'file' => new UploadedFile($path, 'site-copy.zip', 'application/zip', null, true), 'role' => 'source',
    ])->assertCreated();
    @unlink($path);
});

it('still refuses an executable attachment', function () {
    $uuid = ($this->startAttach)('#!/bin/sh echo', 'run.sh')->assertCreated()->json('upload.uuid');
    ($this->sendParts)($uuid, '#!/bin/sh echo');
    $upload = ($this->finish)($uuid);

    expect($upload->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->failure_code)->toBe('forbidden_file')
        ->and(AssetFile::where('dam_asset_id', $this->asset->id)->exists())->toBeFalse();
});

it('fails cleanly when the asset is deleted before the upload finishes', function () {
    $uuid = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');
    ($this->sendParts)($uuid, 'abcdefgh');
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))->assertAccepted();
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    DB::table('dam_assets')->where('id', $this->asset->id)->delete();

    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('attach_target_missing');
});

it('resumes the same source file but starts fresh after a finished upload', function () {
    $first = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');
    ($this->startAttach)('abcdefgh')->assertOk()->assertJsonPath('upload.uuid', $first);
    ($this->sendParts)($first, 'abcdefgh');
    ($this->finish)($first);

    $again = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');
    expect($again)->not->toBe($first);
});

it('requires file permission, edit permission and asset access to start or continue', function (bool $filePermission, bool $edit, bool $access) {
    $permissions = ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index'];
    if ($filePermission) {
        $permissions[] = 'dam.asset.files.store';
    }
    if ($edit) {
        $permissions[] = 'dam.asset.edit';
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $directory = Directory::factory()->create();
    $this->asset->directories()->attach($directory->id);
    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
    }
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();

    // An asset the member cannot open answers like a missing one (P2 S1); a visible one they may not change is 403.
    ($this->startAttach)('abcdefgh')->assertStatus($access ? 403 : 404);
})->with([[false, true, true], [true, false, true], [true, true, false]]);

it('stops an in-flight upload once the uploader loses access to the asset', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.files.store']]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $directory = Directory::factory()->create();
    $this->asset->directories()->attach($directory->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $uuid = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');

    DB::table('dam_directory_role')->where('role_id', $role->id)->delete();
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))
        ->assertForbidden()->assertJsonPath('code', 'attach_forbidden');
});

it('decides a queued attachment on the uploader alone, with no session, after completion was queued', function (string $revoke) {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.files.store']]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $directory = Directory::factory()->create();
    $this->asset->directories()->attach($directory->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $uuid = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');
    ($this->sendParts)($uuid, 'abcdefgh');
    $this->postJson(route('admin.dam.assets.chunk-upload.complete', $uuid))->assertAccepted();

    match ($revoke) {
        'files'  => $role->update(['permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit']]),
        'edit'   => $role->update(['permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.store']]),
        'access' => DB::table('dam_directory_role')->where('role_id', $role->id)->delete(),
        'status' => $member->update(['status' => 0]),
        'none'   => null,
    };
    // A queue worker runs with no authenticated user.
    Auth::forgetGuards();
    $upload = ChunkUpload::where('uuid', $uuid)->firstOrFail();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    if ($revoke === 'none') {
        expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_COMPLETED)
            ->and(AssetFile::where('dam_asset_id', $this->asset->id)->where('role', 'alternate')->exists())->toBeTrue();

        return;
    }

    expect($upload->fresh()->state)->toBe(ChunkUpload::STATE_FAILED)
        ->and($upload->fresh()->failure_code)->toBe('attach_forbidden')
        ->and(AssetFile::where('dam_asset_id', $this->asset->id)->exists())->toBeFalse();
})->with(['files', 'edit', 'access', 'status', 'none']);

it('starts a fresh upload instead of resuming a failed one, so a corrupt chunk can be re-sent', function () {
    $first = ($this->startAttach)('abcdefgh')->assertCreated()->json('upload.uuid');
    ($this->sendParts)($first, 'abcdefgh');
    ChunkUpload::where('uuid', $first)->update(['state' => ChunkUpload::STATE_FAILED, 'failure_code' => 'chunk_corrupt']);

    $second = ($this->startAttach)('abcdefgh')->assertCreated()->json();
    expect($second['upload']['uuid'])->not->toBe($first)
        ->and($second['upload']['received_indices'])->toBe([]);

    ($this->sendParts)($second['upload']['uuid'], 'abcdefgh');
    expect(($this->finish)($second['upload']['uuid'])->state)->toBe(ChunkUpload::STATE_COMPLETED);
});
