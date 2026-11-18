<?php

declare(strict_types=1);

namespace Mothership\DAM\Database\Backfill;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Support\FilenameStem;
use RuntimeException;

/**
 * Fills dam_assets.name_stem from file_name (P3b-2). Used by migration 2026_10_07_000601 and by
 * `php artisan dam:name-stems [--dry-run]`.
 *
 * It reads and writes through the query builder only, over every row including trashed ones, and uses the live
 * FilenameStem rules on purpose: the column must equal what Asset::saving() writes today, so a later rule change
 * is applied by running the command again. Idempotent: a row whose stem is already right is not written.
 *
 * The run reconciles: rows_seen = rows_with_stem + rows_without_stem, and a second pass finds no row whose stored
 * stem differs from its file name's. A real run throws on a mismatch and rolls back; a dry run always rolls back
 * and returns the mismatches in its report.
 */
class NameStemBackfill
{
    private const CHUNK = 500;

    /** @return array<string, mixed> the reconciliation report */
    public function run(bool $dryRun = false): array
    {
        $report = [
            'dry_run'           => $dryRun,
            'rows_seen'         => 0,
            'rows_written'      => 0,
            'rows_with_stem'    => 0,
            'rows_without_stem' => 0,
            'mismatches'        => [],
        ];

        DB::beginTransaction();

        try {
            DB::table('dam_assets')->select(['id', 'file_name', 'name_stem'])->orderBy('id')
                ->chunkById(self::CHUNK, function ($rows) use (&$report): void {
                    $writes = [];

                    foreach ($rows as $row) {
                        $stem = FilenameStem::of($row->file_name);
                        $report['rows_seen']++;
                        $report[$stem === null ? 'rows_without_stem' : 'rows_with_stem']++;

                        if ($row->name_stem !== $stem) {
                            $writes[$stem ?? "\0"][] = (int) $row->id;
                        }
                    }

                    // One statement per distinct stem in the chunk, not one per row.
                    foreach ($writes as $stem => $ids) {
                        $report['rows_written'] += DB::table('dam_assets')->whereIn('id', $ids)
                            ->update(['name_stem' => $stem === "\0" ? null : (string) $stem]);
                    }
                }, 'id');

            $report['mismatches'] = $this->mismatches();

            if ($report['rows_seen'] !== $report['rows_with_stem'] + $report['rows_without_stem']) {
                $report['mismatches'][] = ['reason' => 'counts'];
            }

            if ($dryRun || $report['mismatches'] !== []) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if (! $dryRun && $report['mismatches'] !== []) {
            throw new RuntimeException('dam_assets.name_stem does not reconcile: '.json_encode(array_slice($report['mismatches'], 0, 20)));
        }

        return $report;
    }

    /** @return list<array{id: int, stored: ?string, expected: ?string}> the first 100 rows whose stem is wrong */
    private function mismatches(): array
    {
        $found = [];

        DB::table('dam_assets')->select(['id', 'file_name', 'name_stem'])->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$found): bool {
                foreach ($rows as $row) {
                    $expected = FilenameStem::of($row->file_name);

                    if ($row->name_stem !== $expected) {
                        $found[] = ['id' => (int) $row->id, 'stored' => $row->name_stem, 'expected' => $expected];
                    }
                }

                return count($found) < 100;
            }, 'id');

        return array_slice($found, 0, 100);
    }
}
