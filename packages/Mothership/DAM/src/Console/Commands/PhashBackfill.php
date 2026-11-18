<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Similarity\DHash;
use Mothership\DAM\Services\Similarity\PerceptualHashes;
use Mothership\DAM\Support\DuplicateLog;
use Throwable;

/**
 * P5c (D16): hashes the existing Library for near-duplicate detection, from each asset's generated preview.
 *
 * Chunked by id and resumable: a run can stop anywhere (--limit, Ctrl-C, a crash) and the next run picks up where it
 * stopped, because PerceptualHashes::ensure() keeps a row whose bytes and algorithm are current and retries only a
 * skip whose preview was missing. `--after=<id>` starts past an id without re-reading the earlier rows.
 * Idempotent: a second full run changes nothing ("unchanged" for every asset).
 *
 * `--rebuild-pairs` re-derives every hashed asset's pairs without re-hashing (after DAM_SIMILAR_THRESHOLD is raised).
 * `--dry-run` only counts what a run would hash. Merged-away sources are skipped (they never list); trashed assets are
 * hashed so a restore needs no work.
 *
 * Reconciles at the end: every live asset either has a row or is reported missing (only possible with --limit or
 * --after); the report counts rows by status.
 */
class PhashBackfill extends Command
{
    protected $signature = 'dam:phash:backfill
        {--dry-run : Count the assets without a settled hash row; write nothing (a replaced file is not detected)}
        {--chunk=200 : Assets read per batch}
        {--limit=0 : Stop after this many assets were hashed or skipped (0: no limit)}
        {--after=0 : Start after this asset id}
        {--force : Re-hash assets whose row is current}
        {--rebuild-pairs : Rebuild the pairs of every hashed asset instead of hashing}';

    protected $description = 'Compute near-duplicate hashes (dHash) for existing assets, chunked and resumable';

    public function handle(PerceptualHashes $hashes): int
    {
        if (! config('dam.similar.enabled', true)) {
            $this->warn('Near-duplicate detection is disabled (DAM_SIMILAR_ENABLED=false); nothing to do.');

            return self::SUCCESS;
        }

        $runId = bin2hex(random_bytes(6));
        $chunk = max(1, min(5000, (int) $this->option('chunk')));

        return $this->option('rebuild-pairs')
            ? $this->rebuildPairs(hashes: $hashes, chunk: $chunk, runId: $runId)
            : $this->hash(hashes: $hashes, chunk: $chunk, runId: $runId);
    }

    private function hash(PerceptualHashes $hashes, int $chunk, string $runId): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $after = max(0, (int) $this->option('after'));
        $counts = ['seen' => 0, 'hashed' => 0, 'flat' => 0, 'skipped' => 0, 'unchanged' => 0, 'failed' => 0, 'would_hash' => 0];
        $lastId = $after;
        $started = microtime(true);

        DuplicateLog::write(event: 'phash.backfill.started', ctx: ['run_id' => $runId, 'dry_run' => $dryRun, 'force' => $force, 'chunk' => $chunk, 'limit' => $limit, 'after' => $after]);

        $query = Asset::withTrashed()->whereNull('merged_into_id')->where('id', '>', $after);

        $query->chunkById($chunk, function ($assets) use ($hashes, $dryRun, $force, $limit, &$counts, &$lastId, $runId): bool {
            $current = $dryRun ? $this->currentRows($assets->pluck('id')->all()) : [];

            foreach ($assets as $asset) {
                if ($limit > 0 && $limit <= $counts['seen'] - $counts['unchanged']) {
                    return false;
                }

                $counts['seen']++;
                $lastId = (int) $asset->id;

                if ($dryRun) {
                    $force || ! isset($current[$asset->id]) ? $counts['would_hash']++ : $counts['unchanged']++;

                    continue;
                }

                try {
                    $counts[$hashes->ensure(asset: $asset, force: $force)]++;
                } catch (Throwable $error) {
                    $counts['failed']++;
                    DuplicateLog::write(event: 'phash.failed', ctx: ['run_id' => $runId, 'asset_id' => (int) $asset->id, 'error' => mb_substr(class_basename($error), 0, 32)], level: 'warning');
                }
            }

            $this->line(sprintf('… up to asset %d: %s', $lastId, json_encode($counts)));

            return true;
        });

        $report = [
            'run_id'   => $runId,
            'dry_run'  => $dryRun,
            'last_id'  => $lastId,
            'seconds'  => round(microtime(true) - $started, 2),
            'counts'   => $counts,
            'rows'     => $this->rowsByStatus(),
            'missing'  => $this->missing(),
            'pairs'    => (int) DB::table('dam_asset_similar_pairs')->count(),
        ];

        DuplicateLog::write(event: 'phash.backfill.finished', ctx: [
            'run_id'              => $runId, 'dry_run' => $dryRun, 'last_id' => $lastId, 'seconds' => $report['seconds'],
            ...$counts, 'missing' => $report['missing'], 'pairs' => $report['pairs'],
        ], level: $counts['failed'] > 0 ? 'warning' : 'info');

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($report['missing'] > 0 && ! $dryRun) {
            $this->warn(sprintf('%d live assets have no hash row yet; run again (or with --after=%d) to continue.', $report['missing'], $lastId));
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function rebuildPairs(PerceptualHashes $hashes, int $chunk, string $runId): int
    {
        $started = microtime(true);
        $assets = 0;
        $pairs = 0;

        DB::table('dam_asset_phashes')->where('status', PerceptualHashes::STATUS_HASHED)->where('algorithm', DHash::ALGORITHM)
            ->select(['id', 'asset_id'])
            ->chunkById($chunk, function ($rows) use ($hashes, &$assets, &$pairs): void {
                foreach ($rows as $row) {
                    $assets++;
                    $pairs += $hashes->rebuildPairs((int) $row->asset_id);
                }
            });

        $report = ['run_id' => $runId, 'rebuilt_assets' => $assets, 'pairs' => (int) DB::table('dam_asset_similar_pairs')->count(), 'seconds' => round(microtime(true) - $started, 2)];
        DuplicateLog::write(event: 'phash.pairs.rebuilt', ctx: $report);
        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true> assets whose row is settled (a retryable skip is not)
     */
    private function currentRows(array $ids): array
    {
        $settled = [];

        foreach (DB::table('dam_asset_phashes')->whereIn('asset_id', $ids)->where('algorithm', DHash::ALGORITHM)->get(['asset_id', 'status', 'reason']) as $row) {
            if ($row->status !== PerceptualHashes::STATUS_SKIPPED || ! PerceptualHashes::retryable((string) $row->reason)) {
                $settled[(int) $row->asset_id] = true;
            }
        }

        return $settled;
    }

    /** @return array<string, int> */
    private function rowsByStatus(): array
    {
        return DB::table('dam_asset_phashes')->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)->all();
    }

    /** Live (not merged-away) assets with no hash row. */
    private function missing(): int
    {
        return (int) DB::table('dam_assets')->whereNull('merged_into_id')
            ->whereNotExists(fn ($row) => $row->select(DB::raw(1))->from('dam_asset_phashes')->whereColumn('dam_asset_phashes.asset_id', 'dam_assets.id'))
            ->count();
    }
}
