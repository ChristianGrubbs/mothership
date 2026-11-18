<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\Organization\AssetOrientation;
use Mothership\DAM\Support\OrganizationLog;

/**
 * P4 (D10): sets the stored orientation of every existing asset (trashed ones included, so a restore lists correctly)
 * from the facts it already stores: its embedded metadata and the original's measured technical facts. Nothing is
 * measured or downloaded. `--dry-run` runs the whole backfill inside a transaction it rolls back and reports what
 * would change; `--apply` keeps it. Both reconcile afterwards: every asset's stored orientation must equal the one its
 * facts describe, else the run is a failure and (for --apply) rolled back too.
 */
class OrientationBackfill extends Command
{
    protected $signature = 'dam:orientation-backfill
        {--dry-run : Run inside a rolled-back transaction and only report}
        {--apply : Write the orientations}
        {--chunk=500 : Assets read per batch}';

    protected $description = 'Set the Library orientation (landscape, portrait, square) of existing assets from their stored dimensions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Choose exactly one of --dry-run or --apply.');

            return self::INVALID;
        }

        $chunk = max(1, min(5000, (int) $this->option('chunk')));
        $report = ['dry_run' => $dryRun, 'assets' => 0, 'before' => $this->counts(), 'set' => 0, 'cleared' => 0, 'unchanged' => 0, 'unknown' => 0];

        DB::beginTransaction();

        try {
            $this->query()->chunkById($chunk, function ($assets) use (&$report): void {
                foreach ($assets as $asset) {
                    $report['assets']++;
                    $orientation = $this->describe($asset);

                    if ($orientation === null) {
                        $report['unknown']++;
                    }

                    if ($asset->orientation === $orientation) {
                        $report['unchanged']++;

                        continue;
                    }

                    // The column alone: no model events, no history entry, no updated_at change.
                    DB::table('dam_assets')->where('id', $asset->id)->update(['orientation' => $orientation]);
                    $orientation === null ? $report['cleared']++ : $report['set']++;
                }
            }, 'dam_assets.id', 'id');

            $report['after'] = $this->counts();
            $report['violations'] = $this->violations();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($dryRun || $report['violations'] !== []) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        OrganizationLog::write(event: 'orientation.backfill', ctx: [
            'dry_run' => $dryRun, 'assets' => $report['assets'], 'set' => $report['set'], 'cleared' => $report['cleared'],
            'unknown' => $report['unknown'], 'violations' => count($report['violations']),
        ], level: $report['violations'] === [] ? 'info' : 'error');

        if ($dryRun) {
            $report['would_set'] = $report['set'];
            $report['would_clear'] = $report['cleared'];
            $report['would_be'] = $report['after'];
            $report['after'] = $this->counts();
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($report['violations'] !== []) {
            $this->error('The backfill does not reconcile; nothing was kept.');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run reconciles; nothing was written.' : 'Backfill reconciles and was kept.');

        return self::SUCCESS;
    }

    private function query()
    {
        return Asset::withTrashed()
            ->select(['dam_assets.id', 'dam_assets.file_type', 'dam_assets.extension', 'dam_assets.meta_data', 'dam_assets.orientation'])
            ->with(['files' => fn ($files) => $files->where('role', AssetFile::ROLE_ORIGINAL)->orderBy('sort')->orderBy('id')->with('fileObject')]);
    }

    private function describe(Asset $asset): ?string
    {
        $technical = $asset->files->first()?->fileObject?->technical;

        return AssetOrientation::of(asset: $asset, technical: is_array($technical) ? $technical : []);
    }

    /** @return array<string, int> assets per stored orientation, unknown included */
    private function counts(): array
    {
        $counts = array_fill_keys([...AssetOrientation::VALUES, 'unknown'], 0);
        $rows = DB::table('dam_assets')->selectRaw('orientation, COUNT(*) AS assets')->groupBy('orientation')->get();

        foreach ($rows as $row) {
            $counts[$row->orientation ?? 'unknown'] = (int) $row->assets;
        }

        return $counts;
    }

    /** @return array<int, array{asset_id: int, stored: string|null, described: string|null}> the first 20 mismatches */
    private function violations(): array
    {
        $violations = [];

        $this->query()->chunkById(500, function ($assets) use (&$violations): bool {
            foreach ($assets as $asset) {
                $described = $this->describe($asset);

                if ($asset->orientation !== $described) {
                    $violations[] = ['asset_id' => (int) $asset->id, 'stored' => $asset->orientation, 'described' => $described];
                }

                if (count($violations) >= 20) {
                    return false;
                }
            }

            return true;
        }, 'dam_assets.id', 'id');

        return $violations;
    }
}
