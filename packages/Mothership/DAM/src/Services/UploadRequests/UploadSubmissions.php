<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\UploadReceipt;

/**
 * P5a (D1): what staff see of a request's submissions, the same on the review page and in the REST API.
 *
 * Every file passes the access seam for the viewer: a file they may not see is left out and only counted
 * (`hidden`), never named. A pending file is visible only to the request's creator and full admins; once accepted it
 * reads as any file in its folder does. A rejected file is shown while it is in the trash, to those who could see it
 * before. Exact-duplicate notices (P3b-2) are worked out for the staff viewer alone; a guest never sees one.
 */
class UploadSubmissions
{
    public const MAX_SUBMISSIONS = 200;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        protected AssetAccessDecision $access,
        protected ExactDuplicateNotices $duplicates,
    ) {}

    /**
     * @return array{submissions: list<array<string, mixed>>, hidden: int}
     */
    public function forStaff(UploadRequest $request, AccessContext $context): array
    {
        $submissions = UploadSubmission::query()->where('upload_request_id', $request->id)->where('file_count', '>', 0)
            ->orderByDesc('id')->limit(self::MAX_SUBMISSIONS)->get();
        $assets = Asset::withTrashed()->whereIn('upload_submission_id', $submissions->pluck('id')->all())
            ->orderBy('id')->get()->groupBy('upload_submission_id');

        $hidden = 0;
        $visible = [];

        foreach ($assets->flatten() as $asset) {
            $status = $this->statusFor(asset: $asset, context: $context);

            if ($status === null) {
                $hidden++;
            } else {
                $visible[(int) $asset->id] = $status;
            }
        }

        $notices = $this->notices(pendingIds: array_keys(array_filter($visible, fn (string $status) => $status === self::STATUS_PENDING)), context: $context);

        return [
            'submissions' => $submissions->map(fn (UploadSubmission $submission) => [
                'id'         => (int) $submission->id,
                'guest'      => [
                    'name'          => $submission->guest_name,
                    'email'         => $submission->guest_email,
                    'self_reported' => true,
                    'pruned'        => $submission->identity_pruned_at !== null,
                ],
                'created_at' => $submission->created_at?->toIso8601String(),
                'file_count' => (int) $submission->file_count,
                'files'      => ($assets->get($submission->id) ?? collect())
                    ->filter(fn (Asset $asset) => isset($visible[(int) $asset->id]))
                    ->map(fn (Asset $asset) => [
                        'id'         => (int) $asset->id,
                        'name'       => (string) $asset->file_name,
                        'extension'  => strtolower((string) $asset->extension),
                        'mime_type'  => $asset->mime_type,
                        'size'       => (int) $asset->file_size,
                        'status'     => $visible[(int) $asset->id],
                        'created_at' => $asset->created_at?->toIso8601String(),
                        'thumbnail'  => ! $asset->trashed() && $asset->path ? route('admin.dam.file.thumbnail', ['path' => urlencode((string) $asset->path)]) : null,
                        'url'        => ! $asset->trashed() ? route('admin.dam.assets.edit', ['id' => $asset->id]) : null,
                        'duplicate'  => $notices[(int) $asset->id] ?? null,
                    ])->values()->all(),
            ])->values()->all(),
            'hidden'      => $hidden,
        ];
    }

    /** pending, accepted or rejected for a file the viewer may see; null for one they may not. */
    protected function statusFor(Asset $asset, AccessContext $context): ?string
    {
        if ($asset->trashed()) {
            return $this->access->forTrashed($context, $asset)->visible ? self::STATUS_REJECTED : null;
        }

        if (! $this->access->for($context, $asset)->visible) {
            return null;
        }

        return $asset->isPendingGuestIntake() ? self::STATUS_PENDING : self::STATUS_ACCEPTED;
    }

    /**
     * @param  list<int>  $pendingIds
     * @return array<int, array<string, mixed>> notice by asset id
     */
    protected function notices(array $pendingIds, AccessContext $context): array
    {
        if ($pendingIds === []) {
            return [];
        }

        $receipts = [];

        foreach ($this->duplicates->currentSha256($pendingIds) as $assetId => $sha256) {
            $receipts[] = new UploadReceipt(assetId: (int) $assetId, sha256: $sha256, outcome: UploadReceipt::CREATED);
        }

        $byAsset = [];

        foreach ($this->duplicates->notices(receipts: $receipts, context: $context, canStackInstead: false) as $notice) {
            $byAsset[(int) $notice['asset_id']] = ['match' => $notice['match'], 'message' => $notice['message'], 'kind' => $notice['kind']];
        }

        return $byAsset;
    }
}
