<?php

/*
 * Optimize and the image editor on one stacked file (2026-10-20): the request names the file's slot with `attachment`,
 * the work reads that file's bytes, and the result lands on that file (a new revision, or an output beside it), never
 * on the stack's main file. The cover here is an SVG, which neither the editor nor Optimize takes, so every success
 * below can only have come from the member.
 */

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\OptimizeAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Stack\StackMembers;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    config(['optimization.api' => 'http://clop.test']);
    $this->loginAsAdmin();
    $this->stack = Asset::factory()->create(['file_name' => 'logo.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'file_type' => 'image', 'path' => 'assets/Stack/logo.svg']);
    Storage::disk(Directory::getAssetDisk())->put($this->stack->path, '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $this->stack->markGraphContentChanged()->save();
    $this->slot = (int) $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$this->stack->id.'/files', [
        'file' => UploadedFile::fake()->image('member.png', 200, 100), 'role' => AssetFile::ROLE_ALTERNATE,
    ])->assertCreated()->json('attachment_id');
    $this->member = fn () => app(StackMembers::class)->find(asset: $this->stack->fresh(), attachmentId: $this->slot);
    $this->coverSha = app(ContentStore::class)->current($this->stack->fresh())->sha256;
});

function stackMemberRender(int $red): UploadedFile
{
    $image = imagecreatetruecolor(200, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, 10, 10));
    $path = tempnam(sys_get_temp_dir(), 'member-render-').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, 'render.png', 'image/png', null, true);
}

it('optimizes the stacked file named by its slot and saves the copy beside the stack, leaving the cover alone', function () {
    $member = ($this->member)();
    $query = '?attachment='.$this->slot;
    $this->postJson(route('admin.dam.assets.optimization.store', $this->stack->id), ['request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0'])
        ->assertUnprocessable();
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->stack->id).$query, ['request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0'])
        ->assertAccepted()->json('result.id');
    $row = DB::table('dam_optimizations')->where('id', $id)->first();

    expect((int) $row->attachment_id)->toBe($this->slot)
        ->and($row->source_sha256)->toBe($member->fileObject->sha256)
        ->and((int) $row->source_asset_file_id)->toBe((int) $member->id);

    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->withArgs(fn (string $localPath, string $filename) => $filename === 'member.png'
        && hash_file('sha256', $localPath) === $member->fileObject->sha256)->andReturnUsing(function () {
            $dir = sys_get_temp_dir().'/optimize-member-'.bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents($dir.'/out.jpg', 'smaller bytes');

            return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 400, 'new_bytes' => 13, 'width' => 200, 'height' => 100];
        });
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();

    $this->getJson(route('admin.dam.assets.optimization.state', $this->stack->id).$query)->assertOk()->assertJsonPath('result.status', 'completed');
    // The cover's own Optimize does not show the member's run.
    $this->getJson(route('admin.dam.assets.optimization.state', $this->stack->id))->assertOk()->assertJsonPath('result', null);
    expect($this->stack->files()->where('role', AssetFile::ROLE_OUTPUT)->pluck('label')->all())->toBe(['member-optimized.jpg'])
        ->and(app(ContentStore::class)->current($this->stack->fresh())->sha256)->toBe($this->coverSha);
});

it('refuses a slot that is not a member of the asset', function () {
    $other = Asset::factory()->create();
    $foreign = (int) DB::table('dam_attachments')->where('dam_asset_id', $other->id)->value('id');
    $this->postJson(route('admin.dam.assets.optimization.store', $this->stack->id).'?attachment='.($foreign ?: 999999), ['request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0'])
        ->assertNotFound();
    $this->getJson(route('admin.dam.assets.editor.document', $this->stack->id).'?attachment=999999')->assertNotFound();
    Queue::assertNothingPushed();
});

it('edits a stacked PNG under an SVG cover: a version becomes its next revision, a copy becomes a new entry', function () {
    $member = ($this->member)();
    $query = '?attachment='.$this->slot;
    $this->getJson(route('admin.dam.assets.editor.document', $this->stack->id))->assertUnprocessable();
    $opened = $this->getJson(route('admin.dam.assets.editor.document', $this->stack->id).$query)->assertOk()->json();

    expect(ImageEditorResource::findOrFail($opened['original_base'])->file_object_id)->toBe((int) $member->file_object_id);
    foreach ($opened['resources'] as $url) {
        expect($url)->toContain('attachment='.$this->slot);
        $this->get($url)->assertOk();
    }

    $save = fn (array $doc, string $mode, int $red) => $this->post(route('admin.dam.assets.editor.save', $this->stack->id).$query, [
        'revision' => $doc['revision'], 'mode' => $mode, 'document' => json_encode($doc['document']), 'render' => stackMemberRender($red),
    ], ['Accept' => 'application/json']);
    $save($opened, 'version', 200)->assertOk()->assertJsonPath('asset_id', $this->stack->id);
    $written = ($this->member)();

    expect((int) $written->id)->not->toBe((int) $member->id)
        ->and((int) $written->attachment_id)->toBe($this->slot)
        ->and((int) $written->revision_number)->toBe((int) $member->revision_number + 1)
        ->and(app(ContentStore::class)->current($this->stack->fresh())->sha256)->toBe($this->coverSha)
        ->and(ImageEditorDocument::where('attachment_id', $this->slot)->where('source_asset_file_id', $written->id)->exists())->toBeTrue();

    // The document opened before that save is stale for a version, but still saves as a new entry.
    $save($opened, 'version', 120)->assertConflict();
    $copyId = $save($opened, 'new', 60)->assertOk()->json('asset_id');
    $copy = Asset::findOrFail($copyId);

    expect($copy->file_name)->toBe('member - edited.png')
        ->and(dirname($copy->path))->toBe('assets/Stack')
        ->and(app(ContentStore::class)->current($this->stack->fresh())->sha256)->toBe($this->coverSha);

    // Reopening the member loads its new revision's document.
    $reopened = $this->getJson(route('admin.dam.assets.editor.document', $this->stack->id).$query)->assertOk()->json();
    expect($reopened['revision'])->not->toBe($opened['revision']);
});
