<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;

/*
 * P7 review comments, without approvals: a comment names the revision it is about and optionally a point, region
 * or time on it; replies inherit the revision; the author or an editor resolves; rounds are the comments of one
 * revision, newest first, and a new revision starts a new round by itself. REST writes the same fields.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Review '.uniqid()]);
    $this->image = p7ReviewAsset($this->folder);
    $this->store = fn (Asset $asset, array $payload) => $this->postJson(route('admin.dam.asset.comment.store', $asset->id), ['comments' => 'Looks good overall', ...$payload]);
});

it('anchors a comment to the current revision with a point, a region or a time on media', function () {
    $current = p7CurrentRevision($this->image);

    ($this->store)($this->image, ['anchor' => ['type' => 'point', 'x' => 0.25, 'y' => 1]])->assertOk();
    ($this->store)($this->image, ['anchor' => ['type' => 'region', 'x' => 0.5, 'y' => 0.5, 'w' => 0.5, 'h' => 0.25]])->assertOk();
    $video = p7ReviewAsset($this->folder, 'video');
    ($this->store)($video, ['anchor' => ['type' => 'time', 'start' => 12.5, 'end' => 15]])->assertOk();
    ($this->store)($video, ['anchor' => ['type' => 'time', 'start' => '3']])->assertOk();

    $comments = AssetComments::whereIn('dam_asset_id', [$this->image->id, $video->id])->orderBy('id')->get();
    // JSON keeps 1.0 as 1, so the floats compare by value.
    expect($comments->pluck('anchor')->all())->toEqual([
        ['type' => 'point', 'x' => 0.25, 'y' => 1.0],
        ['type' => 'region', 'x' => 0.5, 'y' => 0.5, 'w' => 0.5, 'h' => 0.25],
        ['type' => 'time', 'start' => 12.5, 'end' => 15.0],
        ['type' => 'time', 'start' => 3.0],
    ])->and($comments[0]->asset_file_id)->toBe($current->id)
        ->and($comments[2]->asset_file_id)->toBe(p7CurrentRevision($video)->id);
});

it('refuses a bad anchor shape, an out-of-range value, a time on a still image and another asset\'s revision', function () {
    $other = p7ReviewAsset($this->folder, bytes: 'other');
    $bad = [
        'not an object'        => ['anchor' => 'point'],
        'unknown type'         => ['anchor' => ['type' => 'circle', 'x' => 0.1, 'y' => 0.1]],
        'missing y'            => ['anchor' => ['type' => 'point', 'x' => 0.1]],
        'extra key'            => ['anchor' => ['type' => 'point', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2]],
        'boolean coordinate'   => ['anchor' => ['type' => 'point', 'x' => true, 'y' => 0.1]],
        'x above 1'            => ['anchor' => ['type' => 'point', 'x' => 1.2, 'y' => 0.1]],
        'negative y'           => ['anchor' => ['type' => 'point', 'x' => 0.1, 'y' => -0.1]],
        'region past the edge' => ['anchor' => ['type' => 'region', 'x' => 0.6, 'y' => 0.1, 'w' => 0.5, 'h' => 0.1]],
        'empty region'         => ['anchor' => ['type' => 'region', 'x' => 0.1, 'y' => 0.1, 'w' => 0, 'h' => 0.1]],
        'time on an image'     => ['anchor' => ['type' => 'time', 'start' => 1]],
    ];

    foreach ($bad as $label => $payload) {
        expect(array_keys(($this->store)($this->image, $payload)->assertStatus(422)->json('errors')))->toBe(['anchor'], $label);
    }

    $video = p7ReviewAsset($this->folder, 'video');
    foreach ([['start' => -1], ['start' => 5, 'end' => 4], ['start' => 'soon']] as $time) {
        ($this->store)($video, ['anchor' => ['type' => 'time', ...$time]])->assertUnprocessable()->assertJsonValidationErrors('anchor');
    }

    $foreign = ($this->store)($this->image, ['asset_file_id' => p7CurrentRevision($other)->id])->assertUnprocessable()->json('errors.asset_file_id');
    $missing = ($this->store)($this->image, ['asset_file_id' => AssetFile::max('id') + 1000])->assertUnprocessable()->json('errors.asset_file_id');
    expect($foreign)->toBe($missing)
        ->and(AssetComments::where('dam_asset_id', $this->image->id)->count())->toBe(0);
});

it('keeps a reply on its parent\'s revision and refuses an anchor on a reply', function () {
    $first = p7CurrentRevision($this->image);
    $parentId = ($this->store)($this->image, ['anchor' => ['type' => 'point', 'x' => 0.5, 'y' => 0.5]])->assertOk()->json('comment.id');
    putAssetContent($this->image->fresh(), 'second');

    $replyId = ($this->store)($this->image, ['parent_id' => $parentId, 'comments' => 'Agreed, fixing it'])->assertOk()->json('comment.id');
    $reply = AssetComments::findOrFail($replyId);
    expect($reply->asset_file_id)->toBe($first->id)->and($reply->anchor)->toBeNull()
        ->and(p7CurrentRevision($this->image)->id)->not->toBe($first->id);

    ($this->store)($this->image, ['parent_id' => $parentId, 'anchor' => ['type' => 'point', 'x' => 0.1, 'y' => 0.1]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.anchor.0', trans('dam::app.admin.dam.asset.comments.review.reply-anchor'));

    // The existing rule still holds: a reply's parent is a top-level comment on the same asset.
    ($this->store)($this->image, ['parent_id' => $replyId])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
});

it('lets the author and an editor resolve and reopen, and no one else', function () {
    $author = p7ReviewMember([$this->folder]);
    $editor = p7ReviewMember([$this->folder], editor: true);
    $bystander = p7ReviewMember([$this->folder]);
    $comment = AssetComments::factory()->create(['dam_asset_id' => $this->image->id, 'admin_id' => $author->id, 'asset_file_id' => p7CurrentRevision($this->image)->id]);
    $reply = AssetComments::factory()->create(['dam_asset_id' => $this->image->id, 'admin_id' => $author->id, 'parent_id' => $comment->id]);
    $resolution = fn (AssetComments $row, bool $resolved) => $this->putJson(route('admin.dam.asset.comment.resolution', ['id' => $this->image->id, 'comment' => $row->id]), ['resolved' => $resolved]);

    $this->actingAs($bystander, 'admin');
    $resolution($comment, true)->assertForbidden();
    expect($comment->fresh()->resolved_at)->toBeNull();

    $this->actingAs($author, 'admin');
    $resolution($comment, true)->assertOk();
    expect($comment->fresh())->resolved_at->not->toBeNull()->resolved_by->toBe($author->id);
    $resolution($reply, true)->assertUnprocessable();

    $this->actingAs($bystander, 'admin');
    $resolution($comment, false)->assertForbidden();

    $this->actingAs($editor, 'admin');
    $resolution($comment, false)->assertOk();
    expect($comment->fresh())->resolved_at->toBeNull()->resolved_by->toBeNull();
    $resolution($comment, true)->assertOk();
    expect($comment->fresh()->resolved_by)->toBe($editor->id);

    // A comment named under another asset's path answers like a missing one.
    $other = p7ReviewAsset($this->folder, bytes: 'other');
    $this->putJson(route('admin.dam.asset.comment.resolution', ['id' => $other->id, 'comment' => $comment->id]), ['resolved' => false])->assertNotFound();
});

it('groups comments into rounds per revision, newest first, and a new revision starts a new round', function () {
    $legacy = AssetComments::factory()->create(['dam_asset_id' => $this->image->id, 'comments' => 'Before revisions were tracked']);
    $first = p7CurrentRevision($this->image);
    ($this->store)($this->image, ['comments' => 'Round one note'])->assertOk();

    putAssetContent($this->image->fresh(), 'second upload');
    $second = p7CurrentRevision($this->image);
    ($this->store)($this->image, ['comments' => 'Round two note'])->assertOk();
    ($this->store)($this->image, ['comments' => 'Another round two note'])->assertOk();

    $review = $this->getJson(route('admin.dam.asset.review.show', $this->image->id))->assertOk()->json();
    $rounds = collect($review['rounds']);

    expect($review['current_revision'])->toMatchArray(['id' => $second->id, 'revision_number' => $second->revision_number, 'is_current' => true])
        ->and($rounds->pluck('key')->all())->toBe(['revision-'.$second->id, 'revision-'.$first->id, 'earlier'])
        ->and($rounds[0]['revision']['revision_number'])->toBe($first->revision_number + 1)
        ->and(collect($rounds[0]['comments'])->pluck('comments')->all())->toBe(['Another round two note', 'Round two note'])
        ->and(collect($rounds[0]['comments'])->pluck('is_previous_revision')->unique()->all())->toBe([false])
        ->and($rounds[1]['comments'][0])->toMatchArray(['comments' => 'Round one note', 'is_previous_revision' => true])
        ->and($rounds[2]['comments'][0])->toMatchArray(['id' => $legacy->id, 'asset_file_id' => null, 'anchor' => null, 'is_previous_revision' => false]);

    // A restore is a revision too: it opens a round of its own.
    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$this->image->id.'/attachments/'.$first->attachment_id.'/revisions/'.$first->id.'/restore')->assertOk();
    ($this->store)($this->image, ['comments' => 'After the restore'])->assertOk();
    $keys = collect($this->getJson(route('admin.dam.asset.review.show', $this->image->id))->json('rounds'))->pluck('key');
    expect($keys->first())->toBe('revision-'.p7CurrentRevision($this->image)->id)->and($keys)->toHaveCount(4);
});

it('writes and reads the review fields over REST as the Comments tab does', function () {
    $headers = $this->getAuthenticationHeaders();
    $video = p7ReviewAsset($this->folder, 'video');
    $current = p7CurrentRevision($video);

    $created = $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), [
        'dam_asset_id' => $video->id,
        'comments'     => 'Audio dips here',
        'anchor'       => ['type' => 'time', 'start' => 4.25, 'end' => 6],
    ])->assertCreated()->json('comment');
    expect($created)->toMatchArray(['asset_file_id' => $current->id, 'anchor' => ['type' => 'time', 'start' => 4.25, 'end' => 6], 'resolved_at' => null, 'mentions' => null]);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), [
        'dam_asset_id' => $video->id, 'comments' => 'Pinned on a video', 'anchor' => ['type' => 'time', 'start' => 1, 'end' => 0.5],
    ])->assertUnprocessable()->assertJsonValidationErrors('anchor');
    $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), [
        'dam_asset_id' => $video->id, 'comments' => 'Wrong revision', 'asset_file_id' => p7CurrentRevision($this->image)->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('asset_file_id');
    $this->withHeaders($headers)->postJson(route('admin.api.dam.comment.store'), [
        'dam_asset_id' => $video->id, 'comments' => 'A reply with a time', 'parent_id' => $created['id'], 'anchor' => ['type' => 'time', 'start' => 1],
    ])->assertUnprocessable()->assertJsonValidationErrors('anchor');

    $this->withHeaders($headers)->getJson(route('admin.api.dam.comment.get', $created['id']))->assertOk()
        ->assertJsonPath('data.anchor', ['type' => 'time', 'start' => 4.25, 'end' => 6])
        ->assertJsonPath('data.asset_file_id', $current->id);

    $resolved = $this->withHeaders($headers)->putJson(route('admin.api.dam.comment.resolution', $created['id']), ['resolved' => true])->assertOk()->json('data');
    expect($resolved['resolved_at'])->not->toBeNull()->and($resolved['resolved_by'])->toBe($created['admin_id']);

    $review = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.review.show', $video->id))->assertOk()->json('data');
    expect($review['rounds'][0]['comments'][0])->toMatchArray(['id' => $created['id'], 'anchor' => ['type' => 'time', 'start' => 4.25, 'end' => 6]])
        ->and($review['rounds'][0]['comments'][0]['resolved_at'])->not->toBeNull();

    // A key without the asset-update permission resolves only its own comments.
    $limited = $this->getAuthenticationHeaders('custom', ['api.dam', 'api.dam.comment', 'api.dam.comment.edit', 'api.dam.comment.getById']);
    app('auth')->forgetGuards();
    $this->withHeaders($limited)->putJson(route('admin.api.dam.comment.resolution', $created['id']), ['resolved' => false])->assertForbidden();
});

it('renders the Comments tab with its pins, time codes and review links, and keeps comment text out of the markup', function () {
    ($this->store)($this->image, ['comments' => 'Crop </script><b>here</b>', 'anchor' => ['type' => 'point', 'x' => 0.25, 'y' => 0.5]])->assertOk();
    $video = p7ReviewAsset($this->folder, 'video');
    ($this->store)($video, ['comments' => 'Audio dips', 'anchor' => ['type' => 'time', 'start' => 4.25]])->assertOk();

    // The page is large: compare substrings so a failure names the missing piece, not the whole page.
    $page = $this->get(route('admin.dam.assets.edit', $this->image->id).'?comments')->assertOk()->getContent();
    $media = $this->get(route('admin.dam.assets.edit', $video->id).'?comments')->assertOk()->getContent();
    $has = fn (string $html, string $needle) => str_contains($html, $needle);

    expect($has($page, 'data-dam-review-pin-mode'))->toBeTrue('pin mode button')
        ->and($has($page, json_encode(route('admin.dam.asset.comment.resolution', ['id' => $this->image->id, 'comment' => '__COMMENT__']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)))->toBeTrue('resolution URL')
        ->and($has($page, '"anchor":{"type":"point","x":0.25,"y":0.5}'))->toBeTrue('pinned comment payload')
        ->and($has($page, '</script><b>here</b>'))->toBeFalse('raw comment text')
        ->and($has($media, 'data-dam-review-add-time'))->toBeTrue('add at current time')
        ->and($has($media, '"anchor":{"type":"time","start":4.25}'))->toBeTrue('time-coded comment payload');
});

it('keeps a comment on the revision the reviewer was looking at when a newer one is uploaded meanwhile', function () {
    $video = p7ReviewAsset($this->folder, 'video');
    $shown = p7CurrentRevision($video);

    // Someone else uploads while the Comments tab still shows the first revision.
    putAssetContent($video->fresh(), 'newer cut');
    expect(p7CurrentRevision($video)->id)->not->toBe($shown->id);

    ($this->store)($video, ['comments' => 'Timed on what I saw', 'asset_file_id' => $shown->id, 'anchor' => ['type' => 'time', 'start' => 2]])->assertOk();
    ($this->store)($video, ['comments' => 'General note on what I saw', 'asset_file_id' => $shown->id])->assertOk();

    expect(AssetComments::where('dam_asset_id', $video->id)->pluck('asset_file_id')->unique()->values()->all())->toBe([$shown->id]);
});

it('names what plays each video revision: the current playback, an older revision\'s own proxy, else nothing', function () {
    $video = p7ReviewAsset($this->folder, 'video', bytes: 'prores master');
    $prores = p7CurrentRevision($video);
    // A ProRes master: a browser cannot decode it, so it plays only through a proxy of its own.
    $prores->fileObject->forceFill(['technical' => ['codec_name' => 'prores']])->save();
    ($this->store)($video, ['comments' => 'On the ProRes cut', 'anchor' => ['type' => 'time', 'start' => 1]])->assertOk();

    putAssetContent($video->fresh(), 'h264 cut');
    $h264 = p7CurrentRevision($video);
    ($this->store)($video, ['comments' => 'On the H.264 cut'])->assertOk();

    $playback = fn () => collect($this->getJson(route('admin.dam.asset.review.show', $video->id))->assertOk()->json('rounds'))
        ->mapWithKeys(fn (array $round) => [$round['revision']['id'] => $round['revision']['playback']])->all();

    expect($playback())->toBe([
        $h264->id   => ['asset_file_id' => $h264->id, 'mime' => 'video/mp4', 'is_proxy' => false],
        $prores->id => null,
    ]);

    // A proxy rendered from the ProRes revision plays it; the current revision keeps its own playback.
    $path = app(ContentStore::class)->objectPath(hash('sha256', 'prores-proxy'), 'mp4');
    Storage::disk(Directory::getAssetDisk())->put($path, 'proxy-bytes');
    $object = FileObject::factory()->create([
        'sha256'    => hash('sha256', 'prores-proxy'), 'size' => strlen('proxy-bytes'), 'disk' => Directory::getAssetDisk(), 'path' => $path,
        'mime_type' => 'video/mp4', 'technical' => ['codec_name' => 'h264'],
    ]);
    $proxy = AssetFile::factory()->create(['dam_asset_id' => $video->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_PROXY, 'label' => 'Preview (1080p)', 'sort' => 900]);
    Rendition::factory()->create([
        'dam_asset_id' => $video->id, 'source_asset_file_id' => $prores->id, 'recipe' => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_DONE, 'output_file_object_id' => $object->id, 'output_asset_file_id' => $proxy->id, 'finished_at' => now(),
    ]);

    expect($playback())->toBe([
        $h264->id   => ['asset_file_id' => $h264->id, 'mime' => 'video/mp4', 'is_proxy' => false],
        $prores->id => ['asset_file_id' => $proxy->id, 'mime' => 'video/mp4', 'is_proxy' => true],
    ]);
});
