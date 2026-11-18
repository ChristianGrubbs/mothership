<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\RequestId;
use RuntimeException;

/**
 * Detection and repair of the dam_directories nested set, separated on purpose: `report()` never writes,
 * and `repair()` only rewrites `_lft`/`_rgt` from `parent_id` — the column pair every on-disk path is
 * derived from through `Directory::generatePath()` — under the tree lock, verifying the result before the
 * transaction commits. The hourly `dam:directories:check` schedule calls `report()`; a human runs
 * `dam:directories:repair --force` after taking a database backup (docs/ops/directory-tree-integrity.md).
 */
class DirectoryTreeIntegrity
{
    public function __construct(private DirectoryTreeLock $lock) {}

    /**
     * @return array{checked_at: string, rows: int, roots: int, broken: bool, errors: array<string,int>, path_mismatches: list<int>}
     */
    public function report(): array
    {
        $rows = $this->rows();
        $mismatches = [];

        foreach ($rows as $row) {
            if (Directory::find($row->id)?->generatePath() !== $this->parentChain((int) $row->id, $rows)) {
                $mismatches[] = (int) $row->id;
            }
        }

        $errors = array_map('intval', Directory::countErrors());
        $report = [
            'checked_at'      => now()->toIso8601String(),
            'rows'            => $rows->count(),
            'roots'           => $rows->whereNull('parent_id')->count(),
            'broken'          => array_sum($errors) > 0 || $mismatches !== [],
            'errors'          => $errors,
            'path_mismatches' => $mismatches,
        ];

        $this->record('integrity.checked', $report);

        return $report;
    }

    /**
     * Rebuild `_lft`/`_rgt` from `parent_id` and prove the result inside the same transaction; any failed check
     * throws, which rolls the rebuild back. A JSON snapshot of the table is written first.
     *
     * @return array{snapshot: string, before: array, after: array, rows_renumbered: int}
     */
    public function repair(): array
    {
        $before = $this->report();
        $snapshot = $this->snapshot();

        return $this->lock->run(function () use ($before, $snapshot) {
            $rowsBefore = $this->rows();

            Directory::fixTree();

            $rowsAfter = $this->rows();
            $changed = [];

            foreach ($rowsAfter as $row) {
                $previous = $rowsBefore[$row->id] ?? null;

                if (! $previous || $previous->name !== $row->name || $previous->parent_id !== $row->parent_id) {
                    throw new RuntimeException("Repair changed the name or parent of directory {$row->id}; rolled back.");
                }

                if ($previous->_lft !== $row->_lft || $previous->_rgt !== $row->_rgt) {
                    $changed[] = (int) $row->id;
                }
            }

            $after = $this->report();

            if ($after['broken']) {
                throw new RuntimeException('Repair left the tree inconsistent: '.json_encode($after).'; rolled back.');
            }

            $result = ['snapshot' => $snapshot, 'before' => $before, 'after' => $after, 'rows_renumbered' => count($changed)];
            $this->record('integrity.repaired', ['snapshot' => $snapshot, 'rows_renumbered' => count($changed), 'errors_before' => $before['errors']]);

            return $result;
        });
    }

    private function rows()
    {
        return DB::table('dam_directories')->select('id', 'name', 'parent_id', '_lft', '_rgt')->orderBy('id')->get()->keyBy('id');
    }

    private function parentChain(int $id, $rows): ?string
    {
        $names = [];
        $seen = [];
        $current = $rows[$id] ?? null;

        while ($current) {
            if (isset($seen[$current->id])) {
                return null; // a cycle can never equal a real path
            }
            $seen[$current->id] = true;
            array_unshift($names, $current->name);
            $current = $current->parent_id === null ? null : ($rows[$current->parent_id] ?? null);
        }

        return implode('/', $names);
    }

    private function snapshot(): string
    {
        $directory = storage_path('backups');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $path = $directory.'/dam_directories-before-'.now()->format('Ymd\THis').'.json';
        file_put_contents($path, json_encode(['rows' => $this->rows()->values()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function record(string $event, array $context): void
    {
        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => (string) Str::uuid(),
            'level'  => ($context['broken'] ?? false) ? 'warning' : 'info',
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => RequestId::ctx($context),
        ];

        @file_put_contents(storage_path('logs/directory-tree.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
