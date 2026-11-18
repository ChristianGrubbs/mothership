<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\AiIndex\StackMemberNamingContext;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    $this->stack = Asset::factory()->create(['file_name' => 'camera.JPG', 'extension' => 'jpg']);
    $this->cover = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'label' => 'camera.JPG']);
    $this->cover->fileObject->update(['mime_type' => 'image/jpeg']);
    $this->raw = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'camera.DNG']);
    $this->apply = fn ($file, $name, $extra = []) => $this->postJson(route('admin.dam.assets.ai-rename.apply-member'),
        ['asset_id' => $this->stack->id, 'attachment_id' => $file->attachment_id, 'file_id' => $file->id, 'file_name' => $name] + $extra);
});

it('lists every current slot once in selection and slot order, including unsupported files', function () {
    $single = Asset::factory()->create(['file_name' => 'single.txt']);
    $only = AssetFile::factory()->create(['dam_asset_id' => $single->id, 'label' => 'single.txt']);
    AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'attachment_id' => $this->raw->attachment_id,
        'role'                                   => 'alternate', 'label' => 'old.DNG', 'is_current' => false, 'revision_number' => 2]);
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id, $single->id, $this->stack->id]])
        ->assertOk()->json('rows');
    expect(array_column($rows, 'file_id'))->toBe([$this->cover->id, $this->raw->id, $only->id])
        ->and($rows[0]['thumbnail_url'])->toBe(StackFileThumbnail::urlFor(file: $this->cover->fresh(), asset: $this->stack->fresh()))
        ->and($rows[1]['eligibility']['state'])->toBe('awaiting_preview')
        ->and($rows[2]['eligibility']['state'])->toBe('unsupported');
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->raw->id])
        ->assertStatus(422)->assertJsonPath('eligibility.state', 'awaiting_preview');
});

it('changes only current labels and preserves identity, bytes, extension and history', function () {
    $historical = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'attachment_id' => $this->raw->attachment_id,
        'role'                                                 => 'alternate', 'label' => 'old.DNG', 'is_current' => false, 'revision_number' => 2]);
    $object = $this->raw->fileObject->getAttributes();
    ($this->apply)($this->raw, 'new.jpg', ['title' => 'Ignored alternate title'])->assertOk()->assertJsonPath('file_name', 'new.DNG')->assertJsonPath('status', 'renamed');
    ($this->apply)($this->raw, 'new.jpg')->assertOk()->assertJsonPath('status', 'unchanged');
    expect($this->raw->fresh()->label)->toBe('new.DNG')->and($this->raw->attachment->fresh()->label)->toBe('new.DNG')
        ->and($historical->fresh()->label)->toBe('old.DNG')->and($this->stack->fresh()->file_name)->toBe('camera.JPG')
        ->and($this->raw->fileObject->fresh()->getAttributes())->toBe($object);
    ($this->apply)($historical, 'stale.jpg')->assertStatus(409);
});

it('synchronizes representative labels and rejects sibling and folder collisions', function () {
    ($this->apply)($this->cover, 'renamed.png', ['title' => 'Stack title'])->assertOk()->assertJsonPath('file_name', 'renamed.JPG');
    expect($this->stack->fresh()->file_name)->toBe('renamed.JPG')->and($this->cover->fresh()->label)->toBe('renamed.JPG')
        ->and($this->cover->attachment->fresh()->label)->toBe('renamed.JPG');
    $other = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'TAKEN.JPG']);
    ($this->apply)($this->cover, 'taken.jpg')->assertStatus(422);
    Asset::factory()->create(['path' => dirname($this->stack->path).'/folder.JPG']);
    ($this->apply)($this->cover, 'folder.png')->assertStatus(422);
    expect($this->cover->fresh()->label)->toBe('renamed.JPG');
});

it('does not rename the new representative when the reviewed file was demoted', function () {
    app(AttachmentLedger::class)->promote(asset: $this->stack, attachmentId: $this->raw->attachment_id);
    ($this->apply)($this->cover, 'old-cover.png', ['title' => 'Do not change title'])->assertOk();
    expect($this->stack->fresh()->file_name)->toBe('camera.DNG')->and($this->cover->fresh()->label)->toBe('old-cover.JPG');
});

it('does not leak inaccessible members or accept foreign slots', function () {
    $foreign = AssetFile::factory()->create();
    ($this->apply)($foreign, 'foreign.jpg')->assertNotFound();
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $foreign->id])->assertNotFound();
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom',
        'permissions'                                                                           => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.rename', 'dam.asset.files.index']])->id]);
    $this->actingAs($member, 'admin');
    $owned = Asset::factory()->create(['created_by' => $member->id]);
    $ownedFile = AssetFile::factory()->create(['dam_asset_id' => $owned->id]);
    $missing = (int) Asset::max('id') + 100;
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id, $owned->id, $missing, $this->stack->id]])
        ->assertOk()->assertJsonCount(3, 'rows')->json('rows');
    expect($rows[1]['file_id'])->toBe($ownedFile->id)->and($rows[0]['file_name'])->toBeNull()
        ->and($rows[0]['thumbnail_url'])->toBeNull()->and($rows[0]['eligibility']['state'])->toBe('unavailable');
    unset($rows[0]['asset_id'], $rows[2]['asset_id']);
    expect($rows[0])->toBe($rows[2]);
    ($this->apply)($this->raw, 'hidden.jpg')->assertNotFound();
});

it('uses each member caption without overwriting representative index metadata and permits retry', function () {
    config(['ai-index.api' => 'http://ai.test']);
    DB::table('dam_ai_index')->insert(['asset_id' => $this->stack->id, 'caption' => 'Original index caption', 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldReceive('memberPicturePath')->andReturn('preview');
    $sampler->shouldReceive('sampleLocalFile')->andReturnUsing(fn ($asset, $file) => new MediaSample(images: ['member-'.$file->id], pages: [], kind: 'image', sha256: $file->fileObject->sha256));
    $this->app->instance(MediaSampler::class, $sampler);
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->andReturn(['DateTimeOriginal' => '2026:10:01 12:00:00']);
    foreach ([$this->cover, $this->raw] as $file) {
        $file->fileObject->update(['size' => 7]);
        Storage::disk($file->fileObject->disk)->put($file->fileObject->path, 'fixture');
    }
    $failCaption = false;
    $emptyCaption = false;
    Http::fake([
        'ai.test/caption' => function ($request) use (&$failCaption, &$emptyCaption) {
            return $failCaption ? Http::response([], 503) : Http::response(['caption' => $emptyCaption ? '' : base64_decode($request['images'][0]), 'tags' => [], 'keywords' => [], 'visible_text' => '']);
        },
        'ai.test/name' => fn ($request) => Http::response(['subject' => $request['caption'], 'place' => '', 'title' => '', 'event' => '']),
        '*'            => Http::response([]),
    ]);
    foreach ([$this->cover, $this->raw] as $file) {
        $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $file->id])
            ->assertOk()->assertJsonPath('file_id', $file->id)->assertJsonPath('stem', '2026-10-01_member-'.$file->id);
    }
    expect(DB::table('dam_ai_index')->where('asset_id', $this->stack->id)->value('caption'))->toBe('Original index caption');
    $failCaption = true;
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->raw->id])->assertStatus(503);
    expect($this->raw->fresh()->label)->toBe('camera.DNG');
    $failCaption = false;
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->raw->id])->assertOk();
    $emptyCaption = true;
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->raw->id])->assertStatus(503);
});

it('keeps member operations behind list access and the own-only write boundary', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.rename']]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $folder = Directory::factory()->create();
    $this->stack->directories()->attach($folder);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');
    $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id]])->assertForbidden();
    ($this->apply)($this->raw, 'forbidden.jpg')->assertForbidden();
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->cover->id])->assertForbidden();
    config(['ai-index.api' => 'http://ai.test']);
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id])->assertStatus(409)->assertJsonPath('needs_description', true);
    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->stack->id, 'file_name' => 'legacy.JPG'])->assertOk();
    expect($this->stack->fresh()->file_name)->toBe('legacy.JPG');
    $role->update(['permissions' => [...$role->permissions, 'dam.asset.files.index']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'delete_own_only' => true]);
    $this->actingAs($member->fresh(), 'admin');
    $owned = Asset::factory()->create(['created_by' => $member->id]);
    $owned->directories()->attach($folder);
    $ownedFile = AssetFile::factory()->create(['dam_asset_id' => $owned->id]);
    $missing = (int) Asset::max('id') + 100;
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id, $owned->id, $missing]])
        ->assertOk()->json('rows');
    expect($rows[1]['file_id'])->toBe($ownedFile->id)->and($rows[1]['eligibility']['state'])->toBe('eligible');
    expect($rows[0]['eligibility']['message'])->not->toBe('dam::app.admin.explorer.ai-rename.not-eligible');
    unset($rows[0]['asset_id'], $rows[2]['asset_id']);
    expect($rows[0])->toBe($rows[2])->toBe(['attachment_id' => null, 'file_id' => null, 'file_name' => null,
        'extension'                                         => null, 'thumbnail_url' => null, 'is_representative' => false,
        'eligibility'                                       => ['state' => 'unavailable', 'message' => trans('dam::app.admin.explorer.ai-rename.not-eligible')]]);
    ($this->apply)($this->raw, 'forbidden.jpg')->assertForbidden();
    expect($this->raw->fresh()->label)->toBe('camera.DNG');
});

it('samples the selected raster bytes and never borrows representative preview art', function () {
    $other = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'selected.jpg']);
    $other->fileObject->update(['size' => 8]);
    Storage::disk($other->fileObject->disk)->put($other->fileObject->path, 'selected');
    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldReceive('rasterFrames')->once()->andReturnUsing(function ($path) {
        expect(file_get_contents($path))->toBe('selected');
        $this->samplePath = $path;

        return ['selected jpeg'];
    });
    $sample = $sampler->sampleFile(asset: $this->stack, file: $other);
    expect($sample->images)->toBe(['selected jpeg'])->and(is_file($this->samplePath))->toBeFalse();
    expect(app(MediaSampler::class)->memberPicture(asset: $this->stack, file: $this->raw))->toBeNull();
});

it('preserves real extensions for word and dotted labels and leaves unchanged labels untouched', function (string $label) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => $label]);
    $file->fileObject->update(['mime_type' => 'application/pdf']);
    $before = $file->fresh()->getAttributes();
    $slotBefore = $file->attachment->getAttributes();
    $name = StackFileName::fileName(file: $file, asset: $this->stack);
    ($this->apply)($file, $name)->assertOk()->assertJsonPath('status', 'unchanged');
    expect($file->fresh()->getAttributes())->toBe($before)->and($file->attachment->fresh()->getAttributes())->toBe($slotBefore);
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id]])->assertOk()->json('rows');
    expect(collect($rows)->firstWhere('file_id', $file->id)['extension'])->toBe('pdf');
    ($this->apply)($file, 'renamed.jpg')->assertOk()->assertJsonPath('file_name', 'renamed.pdf');
})->with(['Brochure', 'Rev 2.0', 'Brochure v1.5 final']);

it('synchronizes an extensionless representative with the actual AssetRename result', function () {
    $this->stack->update(['file_name' => 'extensionless']);
    ($this->apply)($this->cover, 'renamed.jpg')->assertOk()->assertJsonPath('file_name', 'renamed');
    expect($this->stack->fresh()->file_name)->toBe('renamed')->and($this->cover->fresh()->label)->toBe('renamed')
        ->and($this->cover->attachment->fresh()->label)->toBe('renamed');
});

it('applies the verified member type for alphabetic dotted labels and preserves matching aliases', function (string $label, string $mime, string $expectedExtension) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => $label]);
    $file->fileObject->update(['mime_type' => $mime]);
    $objectBefore = $file->fileObject->getAttributes();
    ($this->apply)($file, 'renamed.png')->assertOk()->assertJsonPath('status', 'renamed')->assertJsonPath('file_name', 'renamed.'.$expectedExtension);
    expect($file->fresh()->label)->toBe('renamed.'.$expectedExtension)
        ->and($file->attachment->fresh()->label)->toBe('renamed.'.$expectedExtension)
        ->and($file->fileObject->fresh()->getAttributes())->toBe($objectBefore);
    ($this->apply)($file, 'renamed.'.$expectedExtension)->assertOk()->assertJsonPath('status', 'unchanged');
})->with([
    ['Photo by J.Smith', 'image/jpeg', 'jpg'],
    ['Rev 2.0a', 'image/jpeg', 'jpg'],
    ['member.JPEG', 'image/jpeg', 'JPEG'],
    ['member.JPG', 'image/jpeg', 'JPG'],
    ['member.unitypackage', 'application/gzip', 'unitypackage'],
]);

it('lists model members without captures as awaiting preview', function (string $extension) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'model.'.$extension]);
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id]])->assertOk()->json('rows');
    expect(collect($rows)->firstWhere('file_id', $file->id)['eligibility']['state'])->toBe('awaiting_preview');
    expect(collect($rows)->firstWhere('file_id', $file->id)['thumbnail_url'])->toBeNull();
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $file->id])->assertStatus(422);
})->with(['glb', '3mf', 'stl']);

it('samples content-bound derivative members and lists them without decoding pictures', function (string $extension, string $kind) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'member.'.$extension]);
    $file->fileObject->update(['size' => 7, 'mime_type' => match ($extension) {
        'unitypackage' => 'application/gzip', 'tif' => 'image/tiff', 'heic' => 'image/heic', default => 'application/octet-stream',
    }]);
    Storage::disk($file->fileObject->disk)->put($file->fileObject->path, 'fixture');
    $sha = $file->fileObject->sha256;
    $path = match ($kind) {
        'dng'    => DerivativeImages::dngPreview($sha),
        'model'  => DerivativeImages::modelCapture($sha, (int) $this->stack->id),
        'poster' => DerivativeImages::poster($sha),
        default  => DerivativeImages::thumbnail($sha, $extension),
    };
    $image = imagecreatetruecolor(12, 8);
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();
    Storage::disk(Directory::getAssetDisk())->put($path, $jpeg);
    $rows = $this->postJson(route('admin.dam.assets.ai-rename.members'), ['asset_ids' => [$this->stack->id]])->assertOk()->json('rows');
    expect(collect($rows)->firstWhere('file_id', $file->id)['thumbnail_url'])->toBe(StackFileThumbnail::urlFor(file: $file->fresh(), asset: $this->stack->fresh()));
    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldNotReceive('memberPicture');
    $this->app->instance(MediaSampler::class, $sampler);
    expect(app(StackMemberNamingContext::class)->eligibility(asset: $this->stack, file: $file)['state'])->toBe('eligible');
    $sample = (new MediaSampler)->sampleFile(asset: $this->stack, file: $file);
    expect($sample->images)->toHaveCount(1)->and(getimagesizefromstring($sample->images[0])['mime'])->toBe('image/jpeg');
})->with([
    ['dng', 'dng'], ['glb', 'model'], ['blend', 'poster'], ['unitypackage', 'poster'], ['tif', 'thumbnail'], ['heic', 'thumbnail'],
]);

it('samples selected video and PDF members through the existing tools', function (string $extension, string $mime, string $fixture) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'member.'.$extension]);
    $bytes = file_get_contents(base_path($fixture));
    $file->fileObject->update(['size' => strlen($bytes), 'mime_type' => $mime]);
    Storage::disk($file->fileObject->disk)->put($file->fileObject->path, $bytes);
    $sample = app(MediaSampler::class)->sampleFile(asset: $this->stack, file: $file);
    expect($sample->images)->not->toBeEmpty()->and($sample->kind)->toBe($extension === 'mp4' ? 'video' : 'pdf');
})->with([
    ['mp4', 'video/mp4', 'tests/e2e-pw/assets/pano-1s.mp4'],
    ['pdf', 'application/pdf', 'tests/e2e-pw/assets/sample.pdf'],
]);

it('returns retryable member failure when its blob is missing', function () {
    config(['ai-index.api' => 'http://ai.test']);
    Http::fake();
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->cover->id])
        ->assertStatus(503)->assertJsonPath('retryable', true);
    Http::assertNothingSent();
    expect($this->cover->fresh()->label)->toBe('camera.JPG');
});

it('stages once for sampling and metadata and maps vocabulary terms to labels', function () {
    config(['ai-index.api' => 'http://ai.test']);
    $this->cover->fileObject->update(['size' => 7]);
    Storage::disk($this->cover->fileObject->disk)->put($this->cover->fileObject->path, 'fixture');
    $store = Mockery::mock(ContentStore::class)->makePartial();
    $store->shouldReceive('localPath')->once()->passthru();
    $this->app->instance(ContentStore::class, $store);
    $localPath = null;
    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldReceive('sampleLocalFile')->once()->andReturnUsing(function ($asset, $file, $path) use (&$localPath) {
        $localPath = $path;

        return new MediaSample(images: ['frame'], pages: [], kind: 'image', sha256: $file->fileObject->sha256);
    });
    $this->app->instance(MediaSampler::class, $sampler);
    $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->once()->andReturnUsing(function ($path, $disk, $local, $name, $extension) use (&$localPath) {
        expect($local)->toBe($localPath)->and(is_file($local))->toBeTrue();

        return [];
    });
    Http::fake([
        'ai.test/caption' => Http::response(['caption' => 'Event', 'tags' => ['campus-event'], 'keywords' => [], 'visible_text' => '']),
        'ai.test/name'    => Http::response(['subject' => 'event', 'title' => '', 'place' => '', 'event' => '']),
    ]);
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->cover->id])->assertOk();
    Http::assertSent(fn ($request) => $request->url() === 'http://ai.test/name' && $request['tags'] === ['Campus event']);
    expect(is_file($localPath))->toBeFalse();
});

it('releases the shared staged file when sampling or metadata fails', function (string $failure) {
    config(['ai-index.api' => 'http://ai.test']);
    $this->cover->fileObject->update(['size' => 7]);
    Storage::disk($this->cover->fileObject->disk)->put($this->cover->fileObject->path, 'fixture');
    $localPath = null;
    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldReceive('sampleLocalFile')->andReturnUsing(function ($asset, $file, $path) use (&$localPath, $failure) {
        $localPath = $path;
        if ($failure === 'sampling') {
            throw new RuntimeException('sampling unavailable');
        }

        return new MediaSample(images: ['frame'], pages: [], kind: 'image', sha256: $file->fileObject->sha256);
    });
    $this->app->instance(MediaSampler::class, $sampler);
    if ($failure === 'metadata') {
        $this->mock(MetadataExtractionService::class)->shouldReceive('extractMetadata')->andThrow(new RuntimeException('metadata unavailable'));
    }
    Http::fake(['ai.test/caption' => Http::response(['caption' => 'Member', 'tags' => []])]);
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->stack->id, 'file_id' => $this->cover->id])
        ->assertStatus(503)->assertJsonPath('retryable', true);
    expect($localPath)->not->toBeNull()->and(is_file($localPath))->toBeFalse();
})->with(['sampling', 'metadata']);
