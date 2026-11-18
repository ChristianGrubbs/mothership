<?php

namespace Mothership\DAM\Services\Review;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\DeliverReviewNotification;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\Notification\Events\CreateNotification;
use Mothership\Notification\Models\Notification;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * Raises the DAM's review notifications (P7) as Notification package records: one notification and a row per
 * recipient, the shape its listener writes.
 *
 * The access rule lives here, once: a notification goes only to an active admin who can see the asset, asked
 * through the access seam as that admin, and a trashed or unavailable asset sends nothing at all. It is applied
 * when the activity happens (so the caller learns who will hear), again inside the queued delivery in the same
 * transaction as the write, and once more whenever the notification is read (listed()): a reader who cannot open
 * the asset by then sees neither its name nor who did what, so no gap between a check and a write can leak it. The
 * text never carries the comment body or the request message. No mail: the package mails only when a producer
 * opts in, and a mailed body could not be taken back when access is.
 */
class ReviewNotifier
{
    public const TYPE_MENTION = 'dam_mention';

    public const TYPE_COMMENT = 'dam_comment';

    public const TYPE_RESOLVED = 'dam_comment_resolved';

    public const TYPE_REQUEST = 'dam_review_request';

    public const TYPES = [self::TYPE_MENTION, self::TYPE_COMMENT, self::TYPE_RESOLVED, self::TYPE_REQUEST];

    private const LOG = 'logs/dam-review.jsonl';

    public function __construct(
        protected AssetAccessDecision $access,
        protected DirectoryPermissionService $permissions,
    ) {}

    /**
     * Queues a notification for those of $adminIds who can see $asset now; delivery checks them again.
     *
     * @param  list<int>  $adminIds
     * @param  array{title: string, description: string}  $text
     * @param  array<string, int|string|null>  $context  ids only (asset, comment, request), kept on the notification
     * @return list<int> the admins it is addressed to
     */
    public function notify(Asset $asset, array $adminIds, string $type, array $text, array $context = []): array
    {
        $asked = array_values(array_unique(array_map('intval', $adminIds)));
        $recipients = $this->eligible(assetId: (int) $asset->id, adminIds: $asked, type: $type, stage: 'queue');

        if ($recipients === []) {
            return [];
        }

        DeliverReviewNotification::dispatch((int) $asset->id, $recipients, $type, $text, $context);

        return $recipients;
    }

    /**
     * The queued half of notify(): decides the audience again, as it stands now, and writes the notification for
     * whoever is left. Nothing is written when nobody is.
     *
     * @param  list<int>  $adminIds
     * @param  array{title: string, description: string}  $text
     * @param  array<string, int|string|null>  $context
     * @return list<int> the admins notified
     */
    public function deliver(int $assetId, array $adminIds, string $type, array $text, array $context = []): array
    {
        // A long-running worker keeps resolved grants between jobs; ask the database afresh.
        $this->permissions->flush();

        // The decision and the write are one transaction holding the asset row, so a trash or availability change
        // on it cannot land between them. A grant or recipient change elsewhere still can; listed() closes that.
        $recipients = DB::transaction(function () use ($assetId, $adminIds, $type, $text, $context) {
            Asset::withTrashed()->whereKey($assetId)->lockForUpdate()->first();

            $recipients = $this->eligible(assetId: $assetId, adminIds: $adminIds, type: $type, stage: 'deliver');

            if ($recipients === []) {
                return [];
            }

            $this->beforeWrite(assetId: $assetId, recipients: $recipients);

            $notification = Notification::create([
                'type'         => $type,
                'route'        => 'admin.dam.assets.edit',
                'route_params' => ['id' => $assetId, 'comments' => ''],
                'title'        => Str::limit($text['title'], 250),
                'description'  => $text['description'],
                'context'      => ['dam_asset_id' => $assetId, ...$context],
            ]);

            $notification->userNotifications()->createMany(array_map(fn (int $id) => ['admin_id' => $id], $recipients));

            return $recipients;
        });

        if ($recipients !== []) {
            event(new CreateNotification);
        }

        return $recipients;
    }

    /**
     * Applied to every review notification as it is read (the bell, the notifications page, a click-through): a
     * reader who cannot open the asset now sees only that there was review activity, without the asset's name or
     * who did what, whatever the access was when it was written. Others' notifications are left alone.
     */
    public function listed(Notification $notification): void
    {
        $assetId = (int) (($notification->context ?? [])['dam_asset_id'] ?? 0);

        if (! in_array($notification->type, self::TYPES, true) || $assetId === 0) {
            return;
        }

        $reader = auth()->guard('admin')->user();

        if (! $reader instanceof Admin) {
            return;
        }

        $asset = Asset::withTrashed()->find($assetId);

        if ($asset !== null && ! $asset->trashed() && $this->canSee(asset: $asset, admin: $reader)) {
            return;
        }

        $notification->forceFill([
            'title'        => trans('dam::app.admin.dam.asset.comments.review.notify-hidden'),
            'description'  => '',
            'route'        => null,
            'route_params' => null,
            'context'      => null,
        ])->syncOriginal();
    }

    /** A seam between deciding and writing; nothing happens here. Tests use it to change access in that gap. */
    protected function beforeWrite(int $assetId, array $recipients): void {}

    /**
     * Who of $adminIds may hear about the asset at this moment; nobody when notifications are off or the asset is
     * missing, trashed or unavailable.
     *
     * @param  list<int>  $adminIds
     * @return list<int>
     */
    protected function eligible(int $assetId, array $adminIds, string $type, string $stage): array
    {
        $fresh = Asset::withTrashed()->find($assetId);
        $skip = match (true) {
            $adminIds === []                          => 'no_recipients',
            ! config('notifications.enabled', true)   => 'notifications_disabled',
            $fresh === null                           => 'asset_missing',
            $fresh->trashed()                         => 'asset_trashed',
            $fresh->unavailableBecause() !== null     => 'asset_unavailable',
            default                                   => null,
        };

        if ($skip !== null) {
            $this->log(event: 'review.notify.skipped', ctx: ['stage' => $stage, 'type' => $type, 'asset_id' => $assetId, 'reason' => $skip, 'asked' => count($adminIds)]);

            return [];
        }

        $recipients = $this->audience(asset: $fresh, adminIds: $adminIds);

        $this->log(event: 'review.notify.audience', ctx: ['stage' => $stage, 'type' => $type, 'asset_id' => $assetId, 'asked' => count($adminIds), 'notified' => count($recipients)]);

        return $recipients;
    }

    /**
     * Which of $adminIds can see the asset now: active people (never an integration user), each asked through the
     * seam as that admin, so their own folders or list, availability and trash decide.
     *
     * @param  list<int>  $adminIds
     * @return list<int>
     */
    public function audience(Asset $asset, array $adminIds): array
    {
        if ($adminIds === []) {
            return [];
        }

        return Admin::query()
            ->whereIn('id', $adminIds)
            ->where('status', true)
            ->whereNotIn('id', DB::table('admins')->where('type', 'api')->select('id'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Admin $admin) => $this->canSee(asset: $asset, admin: $admin))
            ->map(fn (Admin $admin) => (int) $admin->id)
            ->values()
            ->all();
    }

    public function canSee(Asset $asset, Admin $admin): bool
    {
        try {
            return $this->access->for(AccessContext::forAdmin($admin), (int) $asset->id)->visible;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $ctx */
    protected function log(string $event, array $ctx): void
    {
        if (! config('dam.review.log')) {
            return;
        }

        try {
            $line = json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => request()->attributes->get('dam_review_run_id') ?? tap((string) Str::uuid(), fn ($id) => request()->attributes->set('dam_review_run_id', $id)),
                'level'  => 'info',
                'event'  => $event,
                'loc'    => self::class.'::'.($ctx['stage'] ?? 'notify'),
                'ctx'    => $ctx,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            @file_put_contents(storage_path(self::LOG), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            Log::warning('DAM review record could not be written.', ['event' => $event, 'exception' => $e->getMessage()]);
        }
    }
}
