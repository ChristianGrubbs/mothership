<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\DataGrids\Share\ShareDataGrid;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\Transfers\TransferSession;
use Mothership\DAM\Services\UploadRequests\UploadRequestService;
use Mothership\User\Models\Admin;

/**
 * Everything the centre of Home shows, for one person. A source that cannot answer says so with `available: false`
 * and a reason, so Home never presents a missing figure as zero.
 */
class HomeSummary
{
    private const NOT_PERMITTED = 'not-permitted';

    public function __construct(
        private readonly HomeVisits $visits,
        private readonly HomeLandings $landings,
        private readonly HomeStorageReading $storage,
        private readonly UploadRequestService $uploadRequests,
        private readonly TransferSession $transfers,
    ) {}

    /** @return array<string, mixed> */
    public function payload(Admin $admin, CarbonImmutable $now): array
    {
        $context = new HomeSummaryContext(adminId: (int) $admin->id, since: $this->visits->since((int) $admin->id), now: $now);
        $canSeeFiles = bouncer()->hasPermission('dam.assets');
        $canSeeShares = bouncer()->hasPermission('dam.shares');
        $canSeeRequests = bouncer()->hasPermission('dam.upload_requests');

        $summary = [
            'generated_at' => $now->toIso8601String(),
            'lead'         => $canSeeFiles ? $this->lead($context) : self::unavailable(self::NOT_PERMITTED),
            'board'        => $canSeeFiles ? $this->landings->board($context) : self::unavailable(self::NOT_PERMITTED),
            'needs_you'    => $this->needsYou(context: $context, admin: $admin, access: ['shares' => $canSeeShares, 'requests' => $canSeeRequests]),
            'away'         => $this->away(context: $context, admin: $admin, access: ['shares' => $canSeeShares, 'requests' => $canSeeRequests]),
            'storage'      => $canSeeFiles ? $this->storageSummary() : self::unavailable(self::NOT_PERMITTED),
        ];
        $this->log($summary);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function lead(HomeSummaryContext $context): array
    {
        $arrivals = $context->since === null
            ? (object) ['files' => 0, 'bytes' => 0]
            : $this->landings->visibleAssets()
                ->where('dam_assets.created_at', '>', $context->since)
                ->toBase()
                ->selectRaw('COUNT(*) AS files, COALESCE(SUM(dam_assets.file_size), 0) AS bytes')
                ->first();
        $files = (int) $arrivals->files;
        // Only this person's own saves and files they may list, so someone else's hidden work reveals nothing.
        $craftSaves = DB::table('dam_creative_hub_saves')->whereNotNull('evidence');
        $craftDocked = (clone $craftSaves)->where('admin_id', $context->adminId)->exists()
            || $this->landings->visibleAssets()->whereIn('dam_assets.id', (clone $craftSaves)->select('asset_id'))->exists();

        return [
            'available'    => true,
            'state'        => match (true) {
                $files > 0                                                     => 'new',
                ! $craftDocked && ! $this->landings->visibleAssets()->exists() => 'first-run',
                default                                                        => 'nothing-new',
            },
            'files'        => $files,
            'bytes'        => (int) $arrivals->bytes,
            'since'        => $context->since?->toIso8601String(),
            'craft_docked' => $craftDocked,
        ];
    }

    /**
     * @param  array{shares: bool, requests: bool}  $access
     * @return array<string, mixed>
     */
    private function needsYou(HomeSummaryContext $context, Admin $admin, array $access): array
    {
        $attentionDays = (int) config('dam-home.summary.attention_days');
        $warningDays = (int) config('dam-home.summary.share_warning_days');

        // Failed saves in a craft's outbox join this list when the MCP rework reports them.
        $unavailable = [['kind' => 'outbox-failures', 'reason' => 'awaiting-outbox-reports']];
        $items = [[
            'kind'  => 'failed-uploads',
            'count' => $this->landings->failedFileCount(adminId: $context->adminId, after: $context->now->subDays($attentionDays)),
        ]];
        if (! $access['shares']) {
            $unavailable[] = ['kind' => 'expiring-shares', 'reason' => self::NOT_PERMITTED];
        }
        if (! $access['requests']) {
            $unavailable[] = ['kind' => 'expired-upload-requests', 'reason' => self::NOT_PERMITTED];
        }
        if ($access['shares']) {
            $items[] = [
                'kind'  => 'expiring-shares',
                'count' => $this->visibleShares()
                    ->whereBetween('dam_shares.expires_at', [$context->now, $context->now->addDays($warningDays)])
                    ->count(),
            ];
        }
        if ($access['requests']) {
            $items[] = [
                'kind'  => 'expired-upload-requests',
                'count' => $this->visibleRequests($admin)
                    ->where('status', UploadRequest::STATUS_OPEN)
                    ->whereBetween('expires_at', [$context->now->subDays($attentionDays), $context->now])
                    ->count(),
            ];
        }
        $items = array_values(array_filter($items, fn (array $item): bool => $item['count'] > 0));

        return [
            'available'   => true,
            'items'       => $items,
            'total'       => array_sum(array_column($items, 'count')),
            // The total covers only the sources above. These could not be counted.
            'unavailable' => $unavailable,
        ];
    }

    /**
     * @param  array{shares: bool, requests: bool}  $access
     * @return array<string, mixed>
     */
    private function away(HomeSummaryContext $context, Admin $admin, array $access): array
    {
        return [
            'shares'          => $access['shares']
                ? ['available' => true, 'count' => $this->visibleShares()
                    ->where(fn (QueryBuilder $live) => $live->whereNull('dam_shares.expires_at')->orWhere('dam_shares.expires_at', '>', $context->now))
                    ->count()]
                : self::unavailable(self::NOT_PERMITTED),
            'upload_requests' => $access['requests']
                ? ['available' => true, 'count' => $this->visibleRequests($admin)
                    ->where('status', UploadRequest::STATUS_OPEN)
                    ->where(fn (Builder $open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', $context->now))
                    ->whereHas('creator', fn (Builder $creator) => $creator->where('status', true))
                    ->count()]
                : self::unavailable(self::NOT_PERMITTED),
            // Transfers run person to person through the signalling service, which keeps no record to count.
            'transfers'       => self::unavailable($this->transfers->available() ? 'no-transfer-record' : 'transfers-off'),
        ];
    }

    /** @return array<string, mixed> */
    private function storageSummary(): array
    {
        $reading = $this->storage->read();
        if (! $reading['available']) {
            return self::unavailable('store-unreachable') + ['label' => $reading['label']];
        }

        return [...$reading, 'used' => $reading['total'] - $reading['free']];
    }

    /** The links the Shared links page lists for this person, leaving out revoked ones. */
    private function visibleShares(): QueryBuilder
    {
        return app(ShareDataGrid::class)->prepareQueryBuilder()->whereNull('dam_shares.revoked_at');
    }

    private function visibleRequests(Admin $admin): Builder
    {
        return $this->uploadRequests->visibleQuery($admin)->reorder()->setEagerLoads([]);
    }

    /** @return array{available: false, count: null, reason: string} */
    private static function unavailable(string $reason): array
    {
        return ['available' => false, 'count' => null, 'reason' => $reason];
    }

    /**
     * Counts only, never names. Off unless MOTHERSHIP_HOME_LOG_ENABLED is set.
     *
     * @param  array<string, mixed>  $summary
     */
    private function log(array $summary): void
    {
        if (! config('dam-home.log_enabled')) {
            return;
        }

        $record = [
            'ts'     => $summary['generated_at'],
            'run_id' => (string) Str::uuid(),
            'level'  => 'debug',
            'event'  => 'home.summary',
            'loc'    => 'dam.home',
            'ctx'    => [
                'lead_state'  => $summary['lead']['state'] ?? null,
                'new_files'   => $summary['lead']['files'] ?? null,
                'board_rows'  => count($summary['board']['rows'] ?? []),
                'board_more'  => $summary['board']['more'] ?? null,
                'truncated'   => $summary['board']['truncated'] ?? null,
                'needs_you'   => $summary['needs_you']['total'],
            ],
        ];
        @file_put_contents(storage_path('logs/home.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
