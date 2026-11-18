<?php

namespace Mothership\DAM\Database\Backfill;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every dam_asset_files row without a slot its attachment slot and revision number (P3a S2).
 *
 * Used by migration 2026_10_06_000201, by `php artisan dam:attachments-migrate [--dry-run]`, and by the
 * two bulk writers that insert rows with raw SQL. It reads and writes through the query builder only and
 * touches no column added after 2026_10_06_000200, so a later model change cannot break the migration.
 * Idempotent: an asset whose rows all have slots is skipped.
 *
 * Per asset:
 *  - The `original` row and its `version` rows become the representative slot. Their order comes from
 *    the dam_asset_versions chain: version N's primary file is the content that became current at N.
 *    `version` rows the chain does not name are earlier history, oldest id first; the last of them is
 *    the file version 1 replaced. When the observer's old dedupe collapsed a repeated blob into one row
 *    (A -> B -> A), the row keeps its latest occurrence and each earlier occurrence gets a new row on the
 *    same FileObject, and the version that named that earlier occurrence is pointed at the new row.
 *  - Every other row becomes its own slot holding revision 1.
 *
 * The run reconciles: rows after = rows before + inserted rows, every row has a slot, one representative
 * per asset, one current revision per slot, `original` only as the representative's current revision and
 * `version` only as a superseded one. A real run throws on a mismatch and rolls back; a dry run always
 * rolls back and returns the violations in its report.
 */
class AttachmentSlotBackfill
{
    /** @var array<string, mixed> */
    private array $report;

    private string $runId;

    /**
     * @param  array<int, int>|null  $assetIds  null for every asset
     * @return array<string, mixed> the reconciliation report
     */
    public function run(?array $assetIds = null, bool $dryRun = false): array
    {
        $this->runId = (string) Str::uuid();
        $this->report = [
            'dry_run'                 => $dryRun,
            'assets_seen'             => 0,
            'assets_slotted'          => 0,
            'rows_before'             => 0,
            'rows_after'              => 0,
            'rows_inserted'           => 0,
            'slots_created'           => 0,
            'versions_repointed'      => 0,
            'roles_before'            => [],
            'roles_after'             => [],
            'unchained_version_rows'  => [],
            'assets_without_original' => [],
            'violations'              => [],
        ];

        $this->log('backfill_started', ['dry_run' => $dryRun, 'scoped' => $assetIds !== null]);

        $work = function () use ($assetIds, $dryRun): void {
            $this->report['rows_before'] = $this->rowCount($assetIds);
            $this->report['roles_before'] = $this->roleCounts($assetIds);

            $this->pendingAssetIds($assetIds)->chunk(200)->each(function (Collection $chunk): void {
                foreach ($chunk as $assetId) {
                    $this->report['assets_seen']++;
                    $this->slotAsset((int) $assetId);
                }
            });

            $this->report['rows_after'] = $this->rowCount($assetIds);
            $this->report['roles_after'] = $this->roleCounts($assetIds);
            $this->reconcile($assetIds);

            if (! $dryRun && $this->report['violations'] !== []) {
                throw new AttachmentSlotBackfillException($this->report);
            }
        };

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $work();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($work);
        }

        $this->log('backfill_finished', array_diff_key($this->report, array_flip(['roles_before', 'roles_after'])));

        return $this->report;
    }

    /** @return Collection<int, int> */
    private function pendingAssetIds(?array $assetIds): Collection
    {
        return DB::table('dam_asset_files')
            ->whereNull('attachment_id')
            ->when($assetIds !== null, fn ($query) => $query->whereIn('dam_asset_id', $assetIds))
            ->distinct()
            ->orderBy('dam_asset_id')
            ->pluck('dam_asset_id')
            ->map(fn ($id) => (int) $id);
    }

    private function slotAsset(int $assetId): void
    {
        $rows = DB::table('dam_asset_files')
            ->where('dam_asset_id', $assetId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rows->whereNull('attachment_id')->isEmpty()) {
            return;
        }

        if ($rows->whereNotNull('attachment_id')->isNotEmpty()) {
            // Partly slotted (a bulk writer added a row beside slotted ones): slot only the new rows.
            $this->slotLooseRows($assetId, $rows->whereNull('attachment_id'));

            return;
        }

        $history = $rows->filter(fn ($row) => in_array($row->role, ['original', 'version'], true))->values();
        $others = $rows->reject(fn ($row) => in_array($row->role, ['original', 'version'], true))->values();

        if ($history->isNotEmpty()) {
            $this->slotHistory($assetId, $history);
        }

        $position = 1;

        foreach ($others->sortBy(fn ($row) => [(int) $row->sort, (int) $row->id]) as $row) {
            $slotId = $this->createSlot($assetId, (string) $row->role, $row->label, $position++, false, $row->created_at);
            $this->setRevision((int) $row->id, $slotId, 1, true, (string) $row->role);
        }

        $this->report['assets_slotted']++;
    }

    /** @param  Collection<int, object>  $history */
    private function slotHistory(int $assetId, Collection $history): void
    {
        $byId = $history->keyBy('id');
        $original = $history->firstWhere('role', 'original');

        $versions = DB::table('dam_asset_versions')
            ->where('dam_asset_id', $assetId)
            ->orderBy('number')
            ->get(['id', 'number', 'primary_asset_file_id']);

        // Version N's primary is the content current from N on; consecutive repeats (the image editor
        // records a version beside the observer's) are one occurrence.
        $chain = [];

        foreach ($versions as $version) {
            $fileId = (int) $version->primary_asset_file_id;

            if (! $byId->has($fileId)) {
                continue;
            }

            if ($chain !== [] && end($chain)['row'] === $fileId) {
                $chain[array_key_last($chain)]['versions'][] = (int) $version->id;

                continue;
            }

            $chain[] = ['row' => $fileId, 'versions' => [(int) $version->id]];
        }

        $named = array_column($chain, 'row');
        $unchained = $history
            ->reject(fn ($row) => in_array((int) $row->id, $named, true) || $row === $original)
            ->sortBy('id')
            ->values();

        $occurrences = $unchained->map(fn ($row) => ['row' => (int) $row->id, 'versions' => []])->all();

        if ($unchained->isNotEmpty()) {
            $this->report['unchained_version_rows'][$assetId] = $unchained->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($occurrences === [] && $chain !== []) {
            // Dedupe ate the file version 1 replaced; the oldest row older than version 1's file is it.
            $first = $history->filter(fn ($row) => (int) $row->id < $chain[0]['row'])->sortBy('id')->first();

            if ($first !== null) {
                $occurrences[] = ['row' => (int) $first->id, 'versions' => []];
            }
        }

        foreach ($chain as $link) {
            $occurrences[] = $link;
        }

        if ($original !== null && (end($occurrences)['row'] ?? null) !== (int) $original->id) {
            $occurrences[] = ['row' => (int) $original->id, 'versions' => []];
        }

        if ($original === null) {
            $this->report['assets_without_original'][] = $assetId;
        }

        $label = $original->label ?? $history->last()->label;
        $slotId = $this->createSlot($assetId, 'original', $label, 0, $original !== null, ($original ?? $history->first())->created_at);

        $lastIndexByRow = [];

        foreach ($occurrences as $index => $occurrence) {
            $lastIndexByRow[$occurrence['row']] = $index;
        }

        $lastIndex = count($occurrences) - 1;

        foreach ($occurrences as $index => $occurrence) {
            $current = $original !== null && $index === $lastIndex;
            $role = $current ? 'original' : 'version';
            $rowId = $occurrence['row'];

            if ($lastIndexByRow[$rowId] !== $index) {
                $source = $byId->get($rowId);
                $rowId = (int) DB::table('dam_asset_files')->insertGetId([
                    'dam_asset_id'    => $assetId,
                    'file_object_id'  => $source->file_object_id,
                    'role'            => 'version',
                    'label'           => $source->label,
                    'sort'            => $source->sort,
                    'created_at'      => $source->created_at,
                    'attachment_id'   => $slotId,
                    'revision_number' => $index + 1,
                    'is_current'      => false,
                ]);
                $this->report['rows_inserted']++;

                if ($occurrence['versions'] !== []) {
                    $this->report['versions_repointed'] += DB::table('dam_asset_versions')
                        ->whereIn('id', $occurrence['versions'])
                        ->update(['primary_asset_file_id' => $rowId]);
                }

                continue;
            }

            $this->setRevision($rowId, $slotId, $index + 1, $current, $role);
        }
    }

    /** @param  Collection<int, object>  $rows */
    private function slotLooseRows(int $assetId, Collection $rows): void
    {
        $representative = DB::table('dam_attachments')->where('dam_asset_id', $assetId)->where('is_representative', true)->value('id');
        $representative = $representative === null ? null : (int) $representative;
        $position = (int) DB::table('dam_attachments')->where('dam_asset_id', $assetId)->max('position') + 1;

        foreach ($rows as $row) {
            if (in_array($row->role, ['original', 'version'], true) && $representative !== null) {
                $next = (int) DB::table('dam_asset_files')->where('attachment_id', $representative)->max('revision_number') + 1;
                $current = $row->role === 'original';

                if ($current) {
                    DB::table('dam_asset_files')->where('attachment_id', $representative)->update(['is_current' => false, 'role' => 'version']);
                }

                $this->setRevision((int) $row->id, $representative, $next, $current, (string) $row->role);

                continue;
            }

            $isOriginal = $row->role === 'original';
            $slotId = $this->createSlot($assetId, $isOriginal || $row->role === 'version' ? 'original' : (string) $row->role, $row->label, $isOriginal ? 0 : $position++, $isOriginal, $row->created_at);
            $this->setRevision((int) $row->id, $slotId, 1, $row->role !== 'version', (string) $row->role);

            if ($isOriginal) {
                $representative = $slotId;
            }
        }

        $this->report['assets_slotted']++;
    }

    private function createSlot(int $assetId, string $role, ?string $label, int $position, bool $representative, mixed $createdAt): int
    {
        $this->report['slots_created']++;

        return (int) DB::table('dam_attachments')->insertGetId([
            'dam_asset_id'      => $assetId,
            'role'              => $role,
            'label'             => $label,
            'position'          => $position,
            'is_representative' => $representative,
            'created_at'        => $createdAt ?? now(),
            'updated_at'        => now(),
        ]);
    }

    private function setRevision(int $rowId, int $slotId, int $number, bool $current, string $role): void
    {
        DB::table('dam_asset_files')->where('id', $rowId)->update([
            'attachment_id'   => $slotId,
            'revision_number' => $number,
            'is_current'      => $current,
            'role'            => $role,
        ]);
    }

    private function reconcile(?array $assetIds): void
    {
        $files = fn () => DB::table('dam_asset_files')->when($assetIds !== null, fn ($query) => $query->whereIn('dam_asset_id', $assetIds));
        $slots = fn () => DB::table('dam_attachments')->when($assetIds !== null, fn ($query) => $query->whereIn('dam_asset_id', $assetIds));
        $violations = [];

        if ($this->report['rows_after'] !== $this->report['rows_before'] + $this->report['rows_inserted']) {
            $violations[] = 'row count: '.$this->report['rows_before'].' + '.$this->report['rows_inserted'].' != '.$this->report['rows_after'];
        }

        $checks = [
            'rows without a slot'                  => $files()->whereNull('attachment_id')->count(),
            'assets with two representatives'      => DB::query()->fromSub($slots()->select('dam_asset_id')->where('is_representative', true)->groupBy('dam_asset_id')->havingRaw('count(*) > 1'), 'groups')->count(),
            'slots with two current revisions'     => DB::query()->fromSub($files()->select('attachment_id')->where('is_current', true)->whereNotNull('attachment_id')->groupBy('attachment_id')->havingRaw('count(*) > 1'), 'groups')->count(),
            'original rows not current'            => $files()->where('role', 'original')->where('is_current', false)->count(),
            'version rows still current'           => $files()->where('role', 'version')->where('is_current', true)->count(),
            'original rows outside representative' => $files()->where('role', 'original')
                ->whereNotIn('attachment_id', DB::table('dam_attachments')->where('is_representative', true)->select('id'))->count(),
            'revisions in another asset slot'      => $files()->whereNotNull('attachment_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('dam_attachments')
                    ->whereColumn('dam_attachments.id', 'dam_asset_files.attachment_id')
                    ->whereColumn('dam_attachments.dam_asset_id', 'dam_asset_files.dam_asset_id'))->count(),
        ];

        foreach ($checks as $name => $count) {
            if ($count > 0) {
                $violations[] = "{$name}: {$count}";
            }
        }

        $this->report['violations'] = $violations;
    }

    private function rowCount(?array $assetIds): int
    {
        return DB::table('dam_asset_files')->when($assetIds !== null, fn ($query) => $query->whereIn('dam_asset_id', $assetIds))->count();
    }

    /** @return array<string, int> */
    private function roleCounts(?array $assetIds): array
    {
        return DB::table('dam_asset_files')
            ->when($assetIds !== null, fn ($query) => $query->whereIn('dam_asset_id', $assetIds))
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->orderBy('role')
            ->pluck('aggregate', 'role')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @param  array<string, mixed>  $ctx */
    private function log(string $event, array $ctx): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug(json_encode([
            'ts'     => now()->toIso8601String(),
            'run_id' => $this->runId,
            'level'  => 'debug',
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => $ctx,
        ]));
    }
}
