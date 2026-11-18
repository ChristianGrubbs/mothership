<?php

namespace Mothership\DAM\Tests\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Support\DamTables;
use Throwable;

/**
 * A committed test leaves the database exactly as it found it, and proves it.
 *
 * Tests on DamConcurrencyTestCase commit their fixtures for real, because a row inside the
 * DatabaseTransactions wrapper is invisible to the second session they exist to probe. The cost is that CI runs
 * every DAM step against one database in one job, so anything they leave behind is another step's failure.
 *
 * That is what happened on run 36213570523: nine `dam_file_objects` rows survived the sharing slice 2a step,
 * whose own teardowns removed assets, folders and job tracks but not the asset graph beneath them. Four later
 * steps failed on those rows — a restore that found file objects with no intact blob, an observer test counting
 * ten objects where it wanted one, and `dam:generate-scale-data` succeeding where it expected to refuse. The
 * blobs were gone with the faked disk while the rows were committed for real.
 *
 * So the sweep is generic rather than per-test: every row above the id this test started from, children before
 * parents, and every blob those file objects named, on whichever disk they named. Then the counts, table by
 * table, have to match the baseline — including tables nothing here writes, so a new fixture that starts
 * writing one is caught the first time it runs.
 */
trait AssertsNoCommittedResidue
{
    /** @var array<string, array{count: int, max?: int, ids?: list<string>}> */
    private array $residueBaseline = [];

    /** Call from setUp, once the connection is the one the test will use. */
    protected function recordResidueBaseline(): void
    {
        $this->residueBaseline = [];

        foreach ($this->residueTables() as $table) {
            $this->residueBaseline[$table] = ['count' => (int) DB::table($table)->count()]
                + $this->baselineKeys(table: $table);
        }
    }

    /**
     * Sweeps whatever the test committed and then asserts that both the row counts are back where they started
     * and no blob it could not remove is left on any disk. Safe to call from a `finally`, so it runs even when
     * the test or its own cleanup failed.
     */
    protected function assertNoCommittedResidue(): void
    {
        if ($this->residueBaseline === []) {
            return;
        }

        $blobs = $this->sweep();

        $rows = [];

        foreach ($this->residueBaseline as $table => $before) {
            $now = (int) DB::table($table)->count();

            if ($now !== $before['count']) {
                $rows[$table] = $now - $before['count'];
            }
        }

        // Both in one assertion, so a blob left on disk is reported even when the counts are level and a count
        // that is off does not hide it.
        $left = ['rows' => $rows, 'blobs' => $blobs];

        $this->assertSame(
            ['rows' => [], 'blobs' => []],
            $left,
            'this committed test left something behind, which another CI step will fail on: '
                .json_encode($left, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Every row this test inserted, and every blob those file objects named.
     *
     * Children before parents, because DamTables::ALL is ordered parents-first for the dump/restore it feeds.
     * Directories go deepest-first instead: the nested set references itself.
     *
     * @return list<string> the blobs it could not remove, which the caller asserts on
     */
    private function sweep(): array
    {
        $blobs = $this->sweepBlobs();

        foreach (array_reverse(array_keys($this->residueBaseline)) as $table) {
            $rows = $this->newRows(table: $table);

            if ($rows === null) {
                continue;
            }

            try {
                $table === 'dam_directories'
                    ? $this->sweepDirectories($rows)
                    : $rows->delete();
            } catch (Throwable) {
                // A foreign key this sweep cannot see the order for: the count assertion below reports it, which
                // is the point — a silent sweep would hide the residue it failed to remove.
            }
        }

        return $blobs;
    }

    /**
     * Deletes the blob each file object this test created named, on the disk that row names, and returns the
     * ones that are still there.
     *
     * Nothing here may be assumed. A delete that returns false, a disk that cannot be resolved and a delete that
     * throws were all swallowed, and the row was then deleted by the sweep anyway, so the counts came back level
     * with the bytes still on disk — and an exception is no evidence that a faked disk was torn down or that its
     * contents went with it (round 5, finding 1). Each path is therefore checked for absence, and whatever is
     * left is named in the failure rather than dropped. A delete that reports false for a path that is already
     * gone is not residue, which is why absence decides rather than the return value alone.
     *
     * @return list<string> `disk:path` for each blob still present, with why
     */
    private function sweepBlobs(): array
    {
        $rows = $this->newRows(table: 'dam_file_objects');

        if ($rows === null) {
            return [];
        }

        $left = [];

        foreach ($rows->get(['disk', 'path']) as $object) {
            $disk = (string) $object->disk;
            $path = (string) $object->path;

            try {
                $filesystem = Storage::disk($disk);
                $deleted = $filesystem->delete($path);

                if (! $filesystem->exists($path)) {
                    continue;
                }

                $left[] = $disk.':'.$path.($deleted ? ' (still there after a delete that reported success)' : ' (delete returned false)');
            } catch (Throwable $failed) {
                $left[] = $disk.':'.$path.' ('.$failed::class.': '.$failed->getMessage().')';
            }
        }

        return $left;
    }

    /** Deepest first, so the nested set unwinds without its own foreign key complaining. */
    private function sweepDirectories(Builder $rows): void
    {
        foreach ($rows->orderByDesc('_lft')->pluck('id') as $id) {
            DB::table('dam_directories')->where('id', $id)->delete();
        }
    }

    /**
     * The DAM's own tables plus the shared ones a fixture here creates rows in.
     *
     * `notifications` and `user_notifications` are here because a committed delivery (the S9 race test's arrival
     * notification) writes them for every admin who may review, and that includes the full admins the install
     * seeds. The sweep removes the fixture's own admins and their pivot rows go with them (ON DELETE CASCADE), but a
     * seeded admin outlives the test, so its pivot row and the notification it points at stayed committed in the
     * one CI database. Run 40038341913: a later step's whole-table `b5Notified(TYPE_RECEIVED)` then found admin 1.
     * The pivot comes after its parent here so the reversed sweep order removes it first.
     *
     * @return list<string>
     */
    private function residueTables(): array
    {
        $tables = array_merge(DamTables::ALL, ['admins', 'roles', 'job_track', 'job_instances', 'notifications', 'user_notifications']);

        return array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));
    }

    /**
     * A query over the rows this test added to $table, or null when the sweep has no way to tell them apart.
     */
    private function newRows(string $table): ?Builder
    {
        $before = $this->residueBaseline[$table] ?? [];

        if (isset($before['max'])) {
            return DB::table($table)->where('id', '>', $before['max']);
        }

        if (isset($before['ids'])) {
            // Nothing there to start with means every row is new, and `not in ()` would say the opposite.
            return DB::table($table)
                ->when($before['ids'] !== [], fn (Builder $query) => $query->whereNotIn('id', $before['ids']));
        }

        return null;
    }

    /**
     * How the sweep will recognise this table's new rows: the highest id where the key is an integer, the whole
     * set of keys where it is not, and neither where the table has no `id` at all.
     *
     * PostgreSQL has no max(uuid), so asking for one on `dam_image_editor_resources` threw inside the teardown
     * of every committed Access case on the required leg — the counts never got asserted at all. A text maximum
     * would not have been an ordering either, so those tables are swept by set difference instead. A table this
     * cannot clean is still counted, so residue in it fails the assertion rather than passing unnoticed.
     *
     * @return array{max?: int, ids?: list<string>}
     */
    private function baselineKeys(string $table): array
    {
        $id = collect(Schema::getColumns($table))->firstWhere('name', 'id');

        if ($id === null) {
            return [];
        }

        if (preg_match('/^(big|small|medium|tiny)?int/', strtolower((string) $id['type_name'])) === 1) {
            return ['max' => (int) (DB::table($table)->max('id') ?? 0)];
        }

        return ['ids' => DB::table($table)->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    }
}
