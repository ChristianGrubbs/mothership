<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * A stacked file that is not the cover has no asset row, so its AI description is its own saved row
 * (dam_ai_member_descriptions), served only for the exact bytes that were described.
 */
beforeEach(function () {
    $this->admin = $this->loginAsAdmin();
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    config(['ai-index.api' => 'http://ai.test']);

    // An SVG cover, which the AI cannot describe, with a PNG attached directly beside it.
    $this->stack = Asset::factory()->create(['file_name' => 'cover.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'file_type' => 'image']);
    $this->cover = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'label' => 'cover.svg']);
    $this->cover->fileObject->update(['mime_type' => 'image/svg+xml']);
    $this->member = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'Design Lab_Logo_Horizontal.png']);
    $this->put = function (AssetFile $file, string $mime = 'image/png') {
        $file->fileObject->update(['mime_type' => $mime, 'size' => 7]);
        Storage::disk($file->fileObject->disk)->put($file->fileObject->path, 'fixture');
    };
    ($this->put)($this->member);

    $sampler = Mockery::mock(MediaSampler::class)->makePartial();
    $sampler->shouldReceive('sampleLocalFile')->andReturnUsing(fn ($asset, $file) => new MediaSample(images: ['png'], pages: [], kind: 'image', sha256: $file->fileObject->sha256));
    $this->app->instance(MediaSampler::class, $sampler);

    $this->caption = 'A blue wordmark on white.';
    $this->bridge = 'ok';
    $this->beforeBridge = null;
    Http::fake(['ai.test/caption' => function () {
        ($this->beforeBridge)?->__invoke();

        return $this->bridge === 'fail' ? Http::response([], 503)
            : Http::response(['caption' => $this->bridge === 'empty' ? '' : $this->caption, 'tags' => [], 'keywords' => ['logo'], 'visible_text' => '', 'model' => 'm', 'prompt_version' => 'p']);
    }, '*' => Http::response([], 200)]);

    $this->show = fn ($file = null, $asset = null) => $this->getJson(route('admin.dam.assets.ai-tags.member', ['id' => ($asset ?? $this->stack)->id, 'fileId' => ($file ?? $this->member)->id]));
    $this->describe = fn ($file = null, $asset = null) => $this->postJson(route('admin.dam.assets.ai-tags.member.describe', ['id' => ($asset ?? $this->stack)->id, 'fileId' => ($file ?? $this->member)->id]));
    $this->rows = fn () => DB::table('dam_ai_member_descriptions')->count();
});

it('describes a directly attached PNG on an SVG cover, saves it per file and leaves the cover and its index alone', function () {
    ($this->show)()->assertOk()->assertJsonPath('eligibility.scope', 'member')->assertJsonPath('eligibility.state', 'eligible')
        ->assertJsonPath('eligibility.can_generate', true)->assertJsonPath('ai', null)->assertJsonPath('file.name', 'Design Lab_Logo_Horizontal.png');
    $this->getJson(route('admin.dam.assets.ai-tags.show', $this->stack->id))->assertOk()->assertJsonPath('eligibility.state', 'unsupported');

    ($this->describe)()->assertOk()->assertJsonPath('ai.caption', $this->caption)->assertJsonPath('ai.keywords', ['logo']);

    // A later request (a reload) reads the saved row; nothing was queued and nothing was invented for the cover.
    ($this->show)()->assertOk()->assertJsonPath('ai.caption', $this->caption)->assertJsonPath('eligibility.can_generate', false);
    expect(DB::table('dam_ai_member_descriptions')->where('asset_id', $this->stack->id)->where('file_object_id', $this->member->file_object_id)->value('caption'))->toBe($this->caption)
        ->and(DB::table('dam_ai_index')->where('asset_id', $this->stack->id)->count())->toBe(0);
    Queue::assertNothingPushed();
    $this->getJson(route('admin.dam.assets.ai-tags.show', $this->stack->id))->assertJsonPath('eligibility.state', 'unsupported')->assertJsonPath('ai', null);
});

it('describes a PNG that came in through a merge', function () {
    $source = Asset::factory()->create(['file_name' => 'source.png', 'extension' => 'png', 'mime_type' => 'image/png', 'file_type' => 'image']);
    $file = AssetFile::factory()->create(['dam_asset_id' => $source->id, 'label' => 'source.png']);
    ($this->put)($file);
    app(AssetMergeService::class)->merge($this->stack, [$source->id], true, $this->admin, 'library');
    $slot = AssetFile::where('dam_asset_id', $this->stack->id)->where('is_current', true)->where('file_object_id', $file->file_object_id)->firstOrFail();

    ($this->show)($slot)->assertOk()->assertJsonPath('eligibility.state', 'eligible');
    ($this->describe)($slot)->assertOk()->assertJsonPath('ai.caption', $this->caption);
    ($this->show)($slot)->assertOk()->assertJsonPath('ai.caption', $this->caption);
    // The hidden merged source is not asked about through its own id.
    ($this->show)($slot, $source)->assertNotFound();
});

it('never serves or stores a description for other bytes', function () {
    ($this->describe)()->assertOk();

    $replacement = FileObject::factory()->create(['mime_type' => 'image/png', 'size' => 7]);
    app(AttachmentLedger::class)->appendRevision(asset: $this->stack, attachmentId: $this->member->attachment_id, object: $replacement, note: 'new bytes');
    $current = AssetFile::where('attachment_id', $this->member->attachment_id)->where('is_current', true)->firstOrFail();

    // The old file id is a past revision now; the new current one has no description of its own.
    ($this->show)($this->member)->assertNotFound();
    ($this->show)($current)->assertOk()->assertJsonPath('ai', null)->assertJsonPath('eligibility.can_generate', true);
});

it('writes nothing when the revision, membership or cover changes while the bridge works', function (string $race) {
    $this->beforeBridge = match ($race) {
        'replaced' => fn () => app(AttachmentLedger::class)->appendRevision(asset: $this->stack, attachmentId: $this->member->attachment_id,
            object: FileObject::factory()->create(['mime_type' => 'image/png', 'size' => 7]), note: 'race'),
        'detached' => fn () => AssetFile::whereKey($this->member->id)->update(['is_current' => false]),
        'promoted' => fn () => app(AttachmentLedger::class)->promote(asset: $this->stack, attachmentId: $this->member->attachment_id),
    };

    ($this->describe)()->assertStatus(409);
    expect(($this->rows)())->toBe(0);
})->with(['replaced', 'detached', 'promoted']);

it('leaves no saved description when the bridge fails or answers empty, and a retry works', function () {
    $this->bridge = 'fail';
    ($this->describe)()->assertStatus(503)->assertJsonPath('retryable', true)->assertJsonPath('eligibility.can_generate', true);
    $this->bridge = 'empty';
    ($this->describe)()->assertStatus(503);
    expect(($this->rows)())->toBe(0);
    ($this->show)()->assertJsonPath('ai', null);

    $this->bridge = 'ok';
    ($this->describe)()->assertOk()->assertJsonPath('ai.caption', $this->caption);
    expect(($this->rows)())->toBe(1);
});

it('refuses a file the AI cannot describe, the cover through the member route and another asset\'s file', function () {
    $svg = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'extra.svg']);
    $svg->fileObject->update(['mime_type' => 'image/svg+xml']);
    ($this->describe)($svg)->assertStatus(422)->assertJsonPath('eligibility.state', 'unsupported');

    ($this->show)($this->cover)->assertNotFound();
    $other = Asset::factory()->create(['file_name' => 'other.png', 'extension' => 'png']);
    $foreign = AssetFile::factory()->create(['dam_asset_id' => $other->id, 'role' => 'alternate', 'label' => 'foreign.png']);
    ($this->show)($foreign)->assertNotFound();
    ($this->describe)($foreign)->assertNotFound();
    expect(($this->rows)())->toBe(0);
});

it('keeps reading behind view and file-list access and describing behind tagging and own-only', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    $user = Admin::factory()->create(['role_id' => $role->id]);
    $folder = Directory::factory()->create();
    $this->stack->directories()->attach($folder);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($user, 'admin');

    ($this->show)()->assertForbidden();
    ($this->describe)()->assertForbidden();

    $role->update(['permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index']]);
    $this->actingAs($user->fresh(), 'admin');
    ($this->show)()->assertOk()->assertJsonPath('can_review', false);
    ($this->describe)()->assertForbidden();

    $role->update(['permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index', 'dam.asset.tag']]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'delete_own_only' => true]);
    $this->actingAs($user->fresh(), 'admin');
    ($this->describe)()->assertForbidden();
    expect(($this->rows)())->toBe(0);

    $this->stack->update(['created_by' => $user->id]);
    ($this->describe)()->assertOk();
    expect(($this->rows)())->toBe(1);
});

it('tells a member that is waiting for its preview that Create comes when the preview is ready, then offers Create', function () {
    $raw = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'photo.dng']);
    $raw->fileObject->update(['size' => 7, 'mime_type' => 'application/octet-stream']);
    Storage::disk($raw->fileObject->disk)->put($raw->fileObject->path, 'fixture');

    // A member is described when a person asks, never "automatically": the cover's wording would promise work that does not start.
    $waiting = ($this->show)($raw)->assertOk()->assertJsonPath('eligibility.state', 'awaiting_preview')->assertJsonPath('eligibility.can_generate', false)->json('eligibility.message');
    expect($waiting)->toBe(trans('dam::app.admin.dam.asset.edit.ai.member.awaiting'))->not->toContain('automatically');
    ($this->describe)($raw)->assertStatus(422);
    expect(($this->rows)())->toBe(0);

    $image = imagecreatetruecolor(12, 8);
    ob_start();
    imagejpeg($image);
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::dngPreview($raw->fileObject->sha256), ob_get_clean());

    ($this->show)($raw)->assertOk()->assertJsonPath('eligibility.state', 'eligible')->assertJsonPath('eligibility.can_generate', true)->assertJsonPath('eligibility.message', null);
    ($this->describe)($raw)->assertOk()->assertJsonPath('ai.caption', $this->caption);

    // The cover keeps its own automatic wording.
    expect(trans('dam::app.admin.dam.asset.edit.ai.state.awaiting'))->toContain('start automatically');
});
