<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('should return a comment by id', function () {
    $asset = Asset::factory()->create();
    $comment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
    ]);

    $response = $this->getJson(route('admin.dam.asset.comments.index', $comment->id));
    $response->assertOk();
    $response->assertJson([
        'id'           => $comment->id,
        'admin_id'     => $comment->admin_id,
        'parent_id'    => $comment->parent_id,
        'comments'     => $comment->comments,
        'dam_asset_id' => $asset->id,
    ]);
});

it('reads a comment only through its own asset, never through an asset whose id matches the comment id', function () {
    $directory = Directory::factory()->create();
    $hidden = Asset::factory()->create();
    // The comment id equals the id of an asset the viewer may open; the read used to authorize that asset instead.
    $sharedId = $hidden->id + 500;
    $visible = Asset::factory()->create(['id' => $sharedId]);
    $directory->assets()->attach([$visible->id, $hidden->id]);
    // The sequence-assigned comment first: PostgreSQL does not advance a sequence past an explicit id.
    $own = AssetComments::factory()->create(['dam_asset_id' => $visible->id]);
    $secret = AssetComments::factory()->create(['id' => $sharedId, 'dam_asset_id' => $hidden->id, 'comments' => 'secret note']);

    $member = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');
    config(['app.debug' => false]);

    $hiddenRead = $this->getJson(route('admin.dam.asset.comments.index', $secret->id));
    $missingRead = $this->getJson(route('admin.dam.asset.comments.index', $sharedId + 1000));

    $hiddenRead->assertNotFound();
    expect($hiddenRead->getContent())->not->toContain('secret note')->toBe($missingRead->assertNotFound()->getContent());
    $this->getJson(route('admin.dam.asset.comments.index', $own->id))->assertOk()->assertJson(['id' => $own->id, 'dam_asset_id' => $visible->id]);
});

it('records a double-submitted comment once and tells the post that raced it that it already landed', function () {
    $asset = Asset::factory()->create();
    $payload = ['comments' => 'Posted twice by a double click', 'parent_id' => null];

    // The first post of the pair still holds its lock when the second arrives.
    $inFlight = Cache::lock('dam:comment-post:'.auth()->id().':'.$asset->id.':'.sha1(json_encode([$payload['comments'], null, null, null, []])), 10);
    expect($inFlight->get())->toBeTrue();

    $this->postJson(route('admin.dam.asset.comment.store', $asset->id), $payload)
        ->assertStatus(409)
        ->assertJson(['message' => trans('dam::app.admin.dam.asset.comments.create.duplicate')]);

    $inFlight->release();

    // Once that post finished, the same words may be posted again on purpose.
    $this->postJson(route('admin.dam.asset.comment.store', $asset->id), $payload)->assertOk();
    $this->postJson(route('admin.dam.asset.comment.store', $asset->id), $payload)->assertOk();

    expect(AssetComments::where('dam_asset_id', $asset->id)->count())->toBe(2);
});

it('should create a new comment', function () {
    $asset = Asset::factory()->create();

    $payload = [
        'comments'  => 'This is a test comment',
        'parent_id' => null,
    ];

    $response = $this->postJson(route('admin.dam.asset.comment.store', $asset->id), $payload);
    $response->assertStatus(200);
    $response->assertJson([
        'message' => trans('dam::app.admin.dam.asset.comments.create.create-success'),
    ]);

    $this->assertDatabaseHas('dam_asset_comments', [
        'comments'     => 'This is a test comment',
        'dam_asset_id' => $asset->id,
        'admin_id'     => auth()->id(),
    ]);
});

it('should delete a comment', function () {
    $asset = Asset::factory()->create();
    $comment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
        'admin_id'     => auth()->id(),
    ]);

    $payload = ['id' => $comment->id];

    $response = $this->deleteJson(route('admin.dam.asset.comment.delete', $asset->id), $payload);

    $response->assertOk();
    $response->assertJson([
        'message' => trans('dam::app.admin.dam.asset.comments.delete-success'),
    ]);

    $this->assertDatabaseMissing('dam_asset_comments', [
        'id' => $comment->id,
    ]);
});

it('should reject delete by non-owner', function () {
    $asset = Asset::factory()->create();
    $otherAdmin = Admin::factory()->create();
    $comment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
        'admin_id'     => $otherAdmin->id,
    ]);

    $response = $this->deleteJson(route('admin.dam.asset.comment.delete', $asset->id), [
        'id' => $comment->id,
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseHas('dam_asset_comments', [
        'id' => $comment->id,
    ]);
});

it('should create a threaded reply comment', function () {
    $asset = Asset::factory()->create();
    $parentComment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
    ]);

    $payload = [
        'comments'  => 'This is a reply to the parent comment',
        'parent_id' => $parentComment->id,
    ];

    $response = $this->postJson(route('admin.dam.asset.comment.store', $asset->id), $payload);
    $response->assertOk();
    $response->assertJson([
        'message' => trans('dam::app.admin.dam.asset.comments.create.create-success'),
    ]);

    $this->assertDatabaseHas('dam_asset_comments', [
        'comments'     => 'This is a reply to the parent comment',
        'parent_id'    => $parentComment->id,
        'dam_asset_id' => $asset->id,
    ]);
});

it('refuses a reply whose parent is another asset\'s comment or a reply, exactly as a missing parent', function () {
    $asset = Asset::factory()->create();
    $other = Asset::factory()->create();
    $top = AssetComments::factory()->create(['dam_asset_id' => $asset->id]);
    $reply = AssetComments::factory()->create(['dam_asset_id' => $asset->id, 'parent_id' => $top->id]);
    $foreign = AssetComments::factory()->create(['dam_asset_id' => $other->id]);

    $post = fn ($parentId) => $this->postJson(route('admin.dam.asset.comment.store', $asset->id), [
        'comments'  => 'a reply in the wrong thread',
        'parent_id' => $parentId,
    ]);
    $missing = $post($foreign->id + 1000)->assertUnprocessable()->json('errors.parent_id');

    expect($post($foreign->id)->assertUnprocessable()->json('errors.parent_id'))->toBe($missing)
        ->and($post($reply->id)->assertUnprocessable()->json('errors.parent_id'))->toBe($missing);
    $this->assertDatabaseMissing('dam_asset_comments', ['comments' => 'a reply in the wrong thread']);
});

it('should validate comment minimum length', function () {
    $asset = Asset::factory()->create();

    $response = $this->postJson(route('admin.dam.asset.comment.store', $asset->id), [
        'comments' => 'A',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['comments']);
});

it('should validate comment maximum length', function () {
    $asset = Asset::factory()->create();

    $response = $this->postJson(route('admin.dam.asset.comment.store', $asset->id), [
        'comments' => str_repeat('a', 1001),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['comments']);
});

it('should validate comment is required', function () {
    $asset = Asset::factory()->create();

    $response = $this->postJson(route('admin.dam.asset.comment.store', $asset->id), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['comments']);
});

it('should validate id and comments when updating a comment', function () {
    $asset = Asset::factory()->create();

    $response = $this->putJson(route('admin.dam.asset.comment.update', $asset->id), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['id', 'comments']);
});

it('should update own comment', function () {
    $asset = Asset::factory()->create();
    $comment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
        'admin_id'     => auth()->id(),
        'comments'     => 'original text',
    ]);

    $response = $this->putJson(route('admin.dam.asset.comment.update', $asset->id), [
        'id'       => $comment->id,
        'comments' => 'updated text',
    ]);

    $response->assertOk();
    $response->assertJson([
        'message' => trans('dam::app.admin.dam.asset.comments.updated-success'),
    ]);

    $this->assertDatabaseHas('dam_asset_comments', [
        'id'       => $comment->id,
        'comments' => 'updated text',
    ]);
});

it('should reject update by non-owner', function () {
    $asset = Asset::factory()->create();
    $otherAdmin = Admin::factory()->create();
    $comment = AssetComments::factory()->create([
        'dam_asset_id' => $asset->id,
        'admin_id'     => $otherAdmin->id,
        'comments'     => 'original text',
    ]);

    $response = $this->putJson(route('admin.dam.asset.comment.update', $asset->id), [
        'id'       => $comment->id,
        'comments' => 'attempted hijack',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseHas('dam_asset_comments', [
        'id'       => $comment->id,
        'comments' => 'original text',
    ]);
});

it('should return user info with timezone', function () {
    $admin = Admin::first();

    $response = $this->getJson(route('admin.dam.asset.comments.get_user_info', $admin->id));

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['name', 'image', 'image_url', 'status'],
            'timezone',
        ]);
});
