<?php

namespace Mothership\DAM\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;

/**
 * The DAM_ACCESS_MODEL row is shared state, and a committed test that changes it puts it back on every path.
 *
 * The row-count check cannot see this one. A test that switches the row from `grants` to `lists` updates a row
 * that already existed, so every count matches while the database is left on the other access model — and the
 * key is `key`, not an id, so the sweep has no baseline to compare it against either. The failure path was
 * concrete: setUp changed the state before `$prepared` became true, and a setUp that then threw took the
 * teardown's early return, which skipped the restore (Codex review of PR #74 round 5, finding 2).
 *
 * So the value is captured before anything writes it, and restored from cleanup that runs however the test
 * ended — a partial setUp, a failing case, or a teardown step that threw before reaching the end.
 */
trait RestoresAccessModelState
{
    private bool $accessModelStateRecorded = false;

    private bool $accessModelRowExisted = false;

    private ?string $accessModelStateBefore = null;

    /** Captures the state row as the database had it. Call from setUp, before the first write to it. */
    protected function recordAccessModelState(): void
    {
        $row = DB::table('dam_configuration')->where('key', AccessModel::KEY)->first();

        $this->accessModelRowExisted = $row !== null;
        $this->accessModelStateBefore = $row?->value;
        $this->accessModelStateRecorded = true;
    }

    /** Puts $value in the state row, creating it where the database had none, and drops the cached read. */
    protected function setAccessModelState(string $value): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $value]);

        app(AccessModel::class)->flush();
    }

    /**
     * Puts the captured state back: the value the row held, or no row at all where there was none.
     *
     * Idempotent, and a no-op when nothing was captured, so it is safe to call from every teardown path. It
     * inserts rather than updates, because a case that deletes the row to prove a missing state row is no fence
     * would otherwise leave it deleted — an update matches nothing.
     */
    protected function restoreAccessModelState(): void
    {
        if (! $this->accessModelStateRecorded) {
            return;
        }

        $this->accessModelRowExisted
            ? DB::table('dam_configuration')->updateOrInsert(
                ['key' => AccessModel::KEY],
                ['value' => $this->accessModelStateBefore]
            )
            : DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();

        app(AccessModel::class)->flush();
    }

    /** What the state row holds now, or null when there is none. */
    protected function currentAccessModelState(): ?string
    {
        $value = DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value');

        return $value === null ? null : (string) $value;
    }
}
