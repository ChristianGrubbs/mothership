<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\DeliverReviewNotification;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\ReviewRequest;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Review\ReviewNotifier;
use Mothership\User\Models\Admin;

/*
 * P7 notifications through the Notification package: mentions, feedback requests (groups expanded when asked) and
 * watchers. A notification reaches only someone who can see the asset right now, asked through the access seam as
 * that person; a trashed or unavailable asset sends nothing; the text never carries the comment.
 */

if (! function_exists('p7Notified')) {
    /** The admins a notification of $type reached, ascending. */
    function p7Notified(string $type): array
    {
        return DB::table('user_notifications')
            ->join('notifications', 'notifications.id', '=', 'user_notifications.notification_id')
            ->where('notifications.type', $type)
            ->orderBy('user_notifications.admin_id')
            ->pluck('user_notifications.admin_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

if (! function_exists('p7NewDeliveries')) {
    /**
     * How many notification rows of ANY type were delivered since the beforeEach baseline. Rows that already exist (the
     * installer's demo-data run leaves a completeness notification on a CI database) are tolerated; anything new is not.
     */
    function p7NewDeliveries(): int
    {
        return DB::table('user_notifications')->where('id', '>', test()->notificationBaseline)->count();
    }
}

if (! function_exists('p7Revoke')) {
    /** Takes away the folders a p7ReviewMember() reached. */
    function p7Revoke(Admin $member): void
    {
        DB::table('dam_directory_role')->where('role_id', $member->role_id)->delete();
        app(DirectoryPermissionService::class)->flush();
    }
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->me = auth()->guard('admin')->user();
    $this->folder = Directory::create(['name' => 'Review '.uniqid()]);
    $this->elsewhere = Directory::create(['name' => 'Elsewhere '.uniqid()]);
    $this->asset = p7ReviewAsset($this->folder);
    $this->colleague = p7ReviewMember([$this->folder]);
    $this->outsider = p7ReviewMember([$this->elsewhere]);
    $this->notificationBaseline = (int) DB::table('user_notifications')->max('id');
    $this->comment = fn (array $payload) => $this->postJson(route('admin.dam.asset.comment.store', $this->asset->id), ['comments' => 'Secret launch detail', ...$payload]);
});

it('notifies the people asked for feedback, with groups expanded to the members who can see the asset', function () {
    $named = p7ReviewMember([$this->folder]);
    $groupId = DB::table('dam_groups')->insertGetId(['name' => 'Reviewers '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert([
        ['group_id' => $groupId, 'admin_id' => $this->colleague->id],
        ['group_id' => $groupId, 'admin_id' => $this->outsider->id],
        ['group_id' => $groupId, 'admin_id' => $this->me->id],
    ]);

    $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), [
        'user_ids' => [$named->id], 'group_ids' => [$groupId], 'message' => 'Secret brief', 'due_at' => now()->addDay()->toDateString(),
    ])->assertCreated();

    $request = ReviewRequest::where('dam_asset_id', $this->asset->id)->sole();
    $expected = collect([$named->id, $this->colleague->id])->sort()->values()->all();
    expect($request->recipients()->orderBy('admins.id')->pluck('admins.id')->map(fn ($id) => (int) $id)->all())->toBe($expected)
        ->and($request->asset_file_id)->toBe(p7CurrentRevision($this->asset)->id)
        ->and(p7Notified(ReviewNotifier::TYPE_REQUEST))->toBe($expected);

    $notification = DB::table('notifications')->where('type', ReviewNotifier::TYPE_REQUEST)->sole();
    expect($notification->title.$notification->description)->not->toContain('Secret brief')
        ->and($notification->route)->toBe('admin.dam.assets.edit');

    // The asset shows who was asked, and only the requester or an editor closes it.
    $shown = $this->getJson(route('admin.dam.asset.review.show', $this->asset->id))->assertOk()->json('requests.0');
    expect(collect($shown['recipients'])->pluck('id')->sort()->values()->all())->toBe($expected)->and($shown['message'])->toBe('Secret brief');
    $this->actingAs($this->colleague, 'admin');
    $this->postJson(route('admin.dam.asset.review.requests.close', ['id' => $this->asset->id, 'request' => $request->id]))->assertForbidden();
    $this->actingAs($this->me, 'admin');
    $this->postJson(route('admin.dam.asset.review.requests.close', ['id' => $this->asset->id, 'request' => $request->id]))->assertOk();
    expect($request->fresh()->closed_at)->not->toBeNull()
        ->and($this->getJson(route('admin.dam.asset.review.show', $this->asset->id))->json('requests'))->toBe([]);

    // Everyone stands for every active person, still only those who can see the asset.
    DB::table('notifications')->delete();
    $everyone = app(AccessListReader::class)->everyoneId() ?? DB::table('dam_groups')->insertGetId(['name' => 'Everyone '.uniqid(), 'system_key' => AccessListReader::EVERYONE, 'created_at' => now(), 'updated_at' => now()]);
    app(AccessListReader::class)->flush();
    $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), ['group_ids' => [$everyone]])->assertCreated();
    expect(p7Notified(ReviewNotifier::TYPE_REQUEST))->toContain($this->colleague->id)->toContain($named->id)
        ->not->toContain($this->outsider->id)->not->toContain($this->me->id);
});

it('refuses a request naming someone who cannot see the asset, and creates nothing', function () {
    $response = $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), ['user_ids' => [$this->colleague->id, $this->outsider->id]])
        ->assertUnprocessable();

    expect($response->json('errors.user_ids.0'))->toBe(trans('dam::app.admin.dam.asset.comments.review.mention-no-access', ['name' => $this->outsider->name]))
        ->and(ReviewRequest::count())->toBe(0)
        ->and(p7Notified(ReviewNotifier::TYPE_REQUEST))->toBe([]);
    $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), [])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
});

it('refuses a mention of someone who cannot see the asset with a 422, and notifies nobody', function () {
    $response = ($this->comment)(['mentions' => [$this->colleague->id, $this->outsider->id]])->assertUnprocessable();

    expect($response->json('errors.mentions.0'))->toBe(trans('dam::app.admin.dam.asset.comments.review.mention-no-access', ['name' => $this->outsider->name]))
        ->and(AssetComments::where('dam_asset_id', $this->asset->id)->count())->toBe(0)
        ->and(p7NewDeliveries())->toBe(0);

    // An id that is no active person fails like a missing one, without a name.
    $disabled = p7ReviewMember([$this->folder]);
    $disabled->update(['status' => 0]);
    expect(($this->comment)(['mentions' => [$disabled->id]])->assertUnprocessable()->json('errors.mentions.0'))->not->toContain($disabled->name);

    ($this->comment)(['mentions' => [$this->colleague->id]])->assertOk();
    $mention = DB::table('notifications')->where('type', ReviewNotifier::TYPE_MENTION)->sole();
    expect(p7Notified(ReviewNotifier::TYPE_MENTION))->toBe([$this->colleague->id])
        ->and($mention->title.$mention->description)->not->toContain('Secret launch detail')
        ->and(AssetComments::where('dam_asset_id', $this->asset->id)->sole()->mentions)->toBe([$this->colleague->id]);
});

it('sends nothing for a hidden, trashed or unavailable asset', function () {
    // Hidden: the asset's creator lost the folder, so their watch stops reaching them.
    $this->asset->update(['created_by' => $this->outsider->id]);
    $watcher = p7ReviewMember([$this->folder]);
    $this->actingAs($watcher, 'admin');
    $this->putJson(route('admin.dam.asset.review.watch', $this->asset->id), ['watching' => true])->assertOk();
    $this->actingAs($this->me, 'admin');
    ($this->comment)([])->assertOk();
    expect(p7Notified(ReviewNotifier::TYPE_COMMENT))->toBe([$watcher->id]);

    // Unavailable: a draft is open to editors, but sends nothing at all.
    DB::table('notifications')->delete();
    $editor = p7ReviewMember([$this->folder], editor: true);
    $this->asset->update(['availability' => Asset::AVAILABILITY_DRAFT]);
    ($this->comment)(['mentions' => [$editor->id]])->assertOk();
    $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), ['user_ids' => [$editor->id]])->assertCreated();
    expect(p7NewDeliveries())->toBe(0);

    // Trashed: the writer cannot reach it, and the notifier refuses it whoever is asked.
    $this->asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
    $this->asset->delete();
    expect(app(ReviewNotifier::class)->notify(asset: $this->asset, adminIds: [$watcher->id, $this->me->id], type: ReviewNotifier::TYPE_COMMENT, text: ['title' => 'x', 'description' => 'y']))->toBe([])
        ->and(p7NewDeliveries())->toBe(0);
    ($this->comment)([])->assertNotFound();
});

it('notifies watchers of new and resolved comments, and Unwatch stops it', function () {
    $this->asset->update(['created_by' => $this->colleague->id]);
    $commenter = p7ReviewMember([$this->folder]);
    $follower = p7ReviewMember([$this->folder]);

    $this->actingAs($commenter, 'admin');
    $commentId = ($this->comment)(['comments' => 'First thoughts'])->assertOk()->json('comment.id');
    $this->actingAs($follower, 'admin');
    $this->putJson(route('admin.dam.asset.review.watch', $this->asset->id), ['watching' => true])->assertOk()->assertJson(['watching' => true]);

    // The commenter's own comment told only the creator, not the commenter.
    expect(p7Notified(ReviewNotifier::TYPE_COMMENT))->toBe([$this->colleague->id]);
    DB::table('notifications')->delete();

    $this->actingAs($this->me, 'admin');
    ($this->comment)(['comments' => 'Second thoughts'])->assertOk();
    expect(p7Notified(ReviewNotifier::TYPE_COMMENT))->toBe(collect([$this->colleague->id, $commenter->id, $follower->id])->sort()->values()->all());

    // The creator unwatches; a comment no longer reaches them, while a resolve tells the rest but not the resolver.
    DB::table('notifications')->delete();
    $this->actingAs($this->colleague, 'admin');
    $this->putJson(route('admin.dam.asset.review.watch', $this->asset->id), ['watching' => false])->assertOk();
    expect($this->getJson(route('admin.dam.asset.review.show', $this->asset->id))->json('watching'))->toBeFalse();
    $this->actingAs($commenter, 'admin');
    $this->putJson(route('admin.dam.asset.comment.resolution', ['id' => $this->asset->id, 'comment' => $commentId]), ['resolved' => true])->assertOk();
    expect(p7Notified(ReviewNotifier::TYPE_RESOLVED))->toBe(collect([$this->me->id, $follower->id])->sort()->values()->all());

    $this->actingAs($this->me, 'admin');
    ($this->comment)(['comments' => 'Third thoughts'])->assertOk();
    expect(p7Notified(ReviewNotifier::TYPE_COMMENT))->toBe(collect([$commenter->id, $follower->id])->sort()->values()->all());
});

it('asks for feedback and watches over REST the same way', function () {
    $headers = $this->getAuthenticationHeaders();

    $created = $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.review.requests.store', $this->asset->id), ['user_ids' => [$this->colleague->id]])
        ->assertCreated()->json('data');
    expect($created['recipients'])->toBe([$this->colleague->id])->and(p7Notified(ReviewNotifier::TYPE_REQUEST))->toBe([$this->colleague->id]);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.review.requests.store', $this->asset->id), ['user_ids' => [$this->outsider->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('user_ids');

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.review.watch', $this->asset->id), ['watching' => false])->assertOk()->assertJsonPath('data.watching', false);
    $review = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.review.show', $this->asset->id))->assertOk()->json('data');
    expect($review['watching'])->toBeFalse()->and($review['requests'][0]['id'])->toBe($created['id']);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.review.requests.close', ['asset' => $this->asset->id, 'request' => $created['id']]))->assertOk();
    expect(ReviewRequest::findOrFail($created['id'])->closed_at)->not->toBeNull();
});

it('decides access again when a queued notification is delivered', function (string $change) {
    Queue::fake([DeliverReviewNotification::class]);
    $other = p7ReviewMember([$this->folder]);

    ($this->comment)(['mentions' => [$this->colleague->id, $other->id]])->assertOk();
    $this->postJson(route('admin.dam.asset.review.requests.store', $this->asset->id), ['user_ids' => [$this->colleague->id, $other->id]])->assertCreated();
    Queue::assertPushed(DeliverReviewNotification::class, 2);
    expect(p7NewDeliveries())->toBe(0);

    // What changes while the jobs wait.
    match ($change) {
        'access revoked'     => p7Revoke($this->colleague),
        'recipient disabled' => Admin::query()->whereKey($this->colleague->id)->update(['status' => 0]),
        'asset unavailable'  => $this->asset->update(['availability' => Asset::AVAILABILITY_DRAFT]),
        'asset trashed'      => $this->asset->delete(),
    };

    Queue::pushed(DeliverReviewNotification::class)->each(fn (DeliverReviewNotification $job) => app()->call([$job, 'handle']));

    $expected = in_array($change, ['access revoked', 'recipient disabled'], true) ? [$other->id] : [];
    expect(p7Notified(ReviewNotifier::TYPE_MENTION))->toBe($expected, $change)
        ->and(p7Notified(ReviewNotifier::TYPE_REQUEST))->toBe($expected, $change)
        ->and(ReviewRequest::query()->where('dam_asset_id', $this->asset->id)->count())->toBe(1);
})->with(['access revoked', 'recipient disabled', 'asset unavailable', 'asset trashed']);

it('shows a review notification without the asset or the activity to a reader who can no longer open the asset', function () {
    $other = p7ReviewMember([$this->folder]);
    ($this->comment)(['mentions' => [$this->colleague->id, $other->id]])->assertOk();
    expect(p7Notified(ReviewNotifier::TYPE_MENTION))->toBe(collect([$this->colleague->id, $other->id])->sort()->values()->all());

    p7Revoke($this->colleague);

    $hidden = $this->actingAs($this->colleague, 'admin')->getJson(route('admin.notification.get_notification'))->assertOk();
    expect($hidden->getContent())->not->toContain($this->asset->file_name)
        ->not->toContain('Secret launch detail')
        ->not->toContain($this->me->name)
        ->and($hidden->json('search_results.data.0.notification'))->toMatchArray([
            'title' => trans('dam::app.admin.dam.asset.comments.review.notify-hidden'), 'description' => '', 'route' => null, 'context' => null,
        ]);

    // Someone who still can open it reads it as written.
    $shown = $this->actingAs($other, 'admin')->getJson(route('admin.notification.get_notification'))->assertOk();
    expect($shown->json('search_results.data.0.notification.description'))->toContain($this->asset->file_name);
});

it('keeps the asset out of a notification whose recipient lost access between the check and the write', function () {
    $colleague = $this->colleague;
    $notifier = new class(app(AssetAccessDecision::class), app(DirectoryPermissionService::class)) extends ReviewNotifier
    {
        public ?Closure $gap = null;

        protected function beforeWrite(int $assetId, array $recipients): void
        {
            ($this->gap)();
        }
    };
    $notifier->gap = fn () => p7Revoke($colleague);
    app()->instance(ReviewNotifier::class, $notifier);

    ($this->comment)(['mentions' => [$colleague->id]])->assertOk();

    // The row was decided before the revoke landed; reading it is what keeps the asset out.
    expect(p7Notified(ReviewNotifier::TYPE_MENTION))->toBe([$colleague->id]);
    $listed = $this->actingAs($colleague, 'admin')->getJson(route('admin.notification.get_notification'))->assertOk();
    expect($listed->getContent())->not->toContain($this->asset->file_name)
        ->and($listed->json('search_results.data.0.notification.title'))->toBe(trans('dam::app.admin.dam.asset.comments.review.notify-hidden'));
});

it('tolerates a notification that already exists, and still catches any new delivery of any type', function () {
    $seeded = DB::table('notifications')->insertGetId(['type' => 'completeness', 'title' => 'Completeness calculated', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_notifications')->insert(['notification_id' => $seeded, 'admin_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->notificationBaseline = (int) DB::table('user_notifications')->max('id');

    ($this->comment)(['mentions' => [$this->colleague->id, $this->outsider->id]])->assertUnprocessable();
    expect(p7NewDeliveries())->toBe(0)
        ->and(DB::table('user_notifications')->where('notification_id', $seeded)->count())->toBe(1);

    $other = DB::table('notifications')->insertGetId(['type' => 'import', 'title' => 'Import done', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_notifications')->insert(['notification_id' => $other, 'admin_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(p7NewDeliveries())->toBe(1);
});

it('suggests only people who can open the asset when the mention picker names one', function () {
    $ids = fn (array $response) => collect($response['users'])->pluck('id')->all();

    $named = $this->getJson(route('admin.dam.asset.review.people', ['q' => '', 'asset_id' => $this->asset->id]))->assertOk()->json();

    expect($ids($named))->toContain($this->colleague->id)->not->toContain($this->outsider->id);
});
