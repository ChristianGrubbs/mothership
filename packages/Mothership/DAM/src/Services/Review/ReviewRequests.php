<?php

namespace Mothership\DAM\Services\Review;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ReviewRequest;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\User\Models\Admin;

/**
 * Feedback requests (P7): ask people and groups for feedback on the asset's current revision. A request is a way to
 * ask, never a decision; it is open until its requester or an editor closes it. Shared by the Comments tab and the
 * REST API.
 */
class ReviewRequests
{
    public const MAX_PEOPLE = 100;

    public const MAX_GROUPS = 20;

    public function __construct(
        protected ReviewNotifier $notifier,
        protected AccessListReader $groups,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'user_ids'    => ['nullable', 'array', 'max:'.self::MAX_PEOPLE],
            'user_ids.*'  => ['integer', 'distinct'],
            'group_ids'   => ['nullable', 'array', 'max:'.self::MAX_GROUPS],
            'group_ids.*' => ['integer', 'distinct'],
            'message'     => ['nullable', 'string', 'max:1000'],
            'due_at'      => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * Opens a request and notifies its recipients. A person named directly who cannot see the asset fails with their
     * name, like a mention; a group's members who cannot see it are left out. The requester is never a recipient.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     */
    public function create(Asset $asset, Admin $requester, array $userIds, array $groupIds, ?string $message, ?CarbonInterface $dueAt): ReviewRequest
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));

        if ($userIds === [] && $groupIds === []) {
            throw ValidationException::withMessages(['user_ids' => [trans('dam::app.admin.dam.asset.comments.review.request-empty')]]);
        }

        $named = app(ReviewComments::class)->mentionIds(asset: $asset, ids: $userIds, attribute: 'user_ids');
        $members = $this->notifier->audience(asset: $asset, adminIds: $this->members(groupIds: $groupIds));
        $recipients = array_values(array_diff(array_unique([...$named, ...$members]), [(int) $requester->id]));

        if ($recipients === []) {
            throw ValidationException::withMessages(['group_ids' => [trans('dam::app.admin.dam.asset.comments.review.request-nobody')]]);
        }

        $request = DB::transaction(function () use ($asset, $requester, $message, $dueAt, $recipients) {
            $request = ReviewRequest::create([
                'dam_asset_id'  => $asset->id,
                'asset_file_id' => ReviewComments::currentRevisionId($asset),
                'requested_by'  => $requester->id,
                'message'       => $message === null || trim($message) === '' ? null : trim($message),
                'due_at'        => $dueAt?->copy()->endOfDay(),
            ]);

            $request->recipients()->attach($recipients);

            return $request;
        });

        $this->notifier->notify(
            asset: $asset,
            adminIds: $recipients,
            type: ReviewNotifier::TYPE_REQUEST,
            text: [
                'title'       => trans('dam::app.admin.dam.asset.comments.review.notify-request', ['name' => $requester->name]),
                'description' => $request->due_at === null
                    ? trans('dam::app.admin.dam.asset.comments.review.notify-on', ['asset' => $asset->display_name])
                    : trans('dam::app.admin.dam.asset.comments.review.notify-on-due', ['asset' => $asset->display_name, 'date' => $request->due_at->toDateString()]),
            ],
            context: ['review_request_id' => $request->id],
        );

        return $request;
    }

    /** Closes a request: its requester may, and so may anyone who may change the asset. Closing twice is a no-op. */
    public function close(ReviewRequest $request, Admin $actor, bool $actorMayEdit): ReviewRequest
    {
        abort_unless((int) $request->requested_by === (int) $actor->id || $actorMayEdit, 403, trans('dam::app.admin.dam.asset.comments.review.close-denied'));

        if ($request->closed_at === null) {
            $request->update(['closed_at' => now(), 'closed_by' => $actor->id]);
        }

        return $request;
    }

    /**
     * The groups' members when the request is made: active people only. Everyone has no member rows and stands for
     * every active person. An id that is not a group fails like a missing one.
     *
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    protected function members(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        if (DB::table('dam_groups')->whereIn('id', $groupIds)->count() !== count($groupIds)) {
            throw ValidationException::withMessages(['group_ids' => [trans('validation.exists', ['attribute' => 'group_ids'])]]);
        }

        $everyone = $this->groups->everyoneId();
        $people = ReviewComments::people();

        if ($everyone === null || ! in_array($everyone, $groupIds, true)) {
            $people->whereIn('id', DB::table('dam_group_members')->whereIn('group_id', $groupIds)->select('admin_id'));
        }

        return $people->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
