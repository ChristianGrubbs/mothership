<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Feedback;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\Notification\Events\CreateNotification;
use Mothership\Notification\Models\Notification;
use Mothership\User\Models\Admin;

/**
 * In-app bell notifications for feedback: a new
 * report tells everyone FeedbackAccess::canManage() lets triage it, and a status change tells the reporter. Written
 * as Notification package records the way ReviewNotifier writes them. No mail, ntfy or GitHub call.
 *
 * A notification is a side effect: a failure is reported and logged, and never undoes the report or the status change.
 */
final class FeedbackNotifier
{
    public const TYPE_CREATED = 'dam_feedback_created';

    public const TYPE_STATUS = 'dam_feedback_status';

    public function __construct(
        private readonly FeedbackAccess $access,
    ) {}

    /** @return list<int> the admins notified */
    public function reportCreated(FeedbackReport $report): array
    {
        $recipients = array_values(array_filter(
            $this->managers(),
            fn (int $id): bool => $id !== (int) $report->admin_id,
        ));

        return $this->send(report: $report, type: self::TYPE_CREATED, recipients: $recipients, route: 'admin.dam.feedback.index', routeParams: ['report' => (int) $report->id], title: trans('dam::app.admin.feedback.notify.created-title', [
            'type'  => trans('dam::app.admin.feedback.triage.types.'.$report->type),
            'id'    => (int) $report->id,
            'title' => $report->title,
        ]), description: trans('dam::app.admin.feedback.notify.created-description', [
            'name' => $report->reporter instanceof Admin ? $report->reporter->name : trans('dam::app.admin.feedback.triage.deleted-user'),
        ]));
    }

    /** @return list<int> the admins notified: the reporter, unless they made the change themselves or cannot sign in */
    public function statusChanged(FeedbackReport $report, Admin $actor, string $to): array
    {
        $reporter = $report->reporter;

        if (! $reporter instanceof Admin || (int) $reporter->getKey() === (int) $actor->getKey() || ! $reporter->status || ! $this->access->canSubmit($reporter)) {
            return [];
        }

        return $this->send(report: $report, type: self::TYPE_STATUS, recipients: [(int) $reporter->getKey()], route: 'admin.dam.feedback.mine', routeParams: null, title: trans('dam::app.admin.feedback.notify.status-title', [
            'id'     => (int) $report->id,
            'status' => trans('dam::app.admin.feedback.triage.statuses.'.$to),
        ]), description: $report->title);
    }

    /**
     * Active people who may triage, never an API account.
     *
     * @return list<int>
     */
    public function managers(): array
    {
        return Admin::query()
            ->with('role')
            ->where('status', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Admin $admin): bool => $this->access->canManage($admin))
            ->map(fn (Admin $admin): int => (int) $admin->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $recipients
     * @param  array<string, int>|null  $routeParams
     * @return list<int>
     */
    private function send(FeedbackReport $report, string $type, array $recipients, string $route, ?array $routeParams, string $title, string $description): array
    {
        if (! config('notifications.enabled', true) || $recipients === []) {
            FeedbackLog::write(event: 'notify_skipped', ctx: ['report_id' => (int) $report->id, 'type' => $type, 'reason' => $recipients === [] ? 'no_recipients' : 'notifications_disabled']);

            return [];
        }

        try {
            DB::transaction(function () use ($report, $type, $recipients, $route, $routeParams, $title, $description): void {
                $notification = Notification::create([
                    'type'         => $type,
                    'route'        => $route,
                    'route_params' => $routeParams,
                    'title'        => Str::limit($title, 250),
                    'description'  => Str::limit($description, 250),
                    'context'      => ['dam_feedback_report_id' => (int) $report->id],
                ]);

                $notification->userNotifications()->createMany(array_map(fn (int $id): array => ['admin_id' => $id], $recipients));
            });
        } catch (\Throwable $e) {
            report($e);
            FeedbackLog::write(event: 'notify_failed', ctx: ['report_id' => (int) $report->id, 'type' => $type, 'error_class' => class_basename($e)], level: 'error');

            return [];
        }

        event(new CreateNotification);
        FeedbackLog::write(event: 'notify_delivered', ctx: ['report_id' => (int) $report->id, 'type' => $type, 'notified' => count($recipients)]);

        return $recipients;
    }
}
