<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Sleep;
use Mothership\DAM\Services\Access\AccessComparator;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessRebuildRefused;
use Mothership\DAM\Services\Access\AccessSwitchRefused;
use Mothership\DAM\Services\Access\AccessSwitchSuperseded;
use Mothership\DAM\Services\Access\ShareKeyGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\FolderAccessLog;
use Throwable;

class AccessSwitch extends Command
{
    protected $signature = 'dam:access:switch
        {model : grants or lists}
        {--force : Switch to lists even when the comparison fails (operator only; the failures are printed)}
        {--drain=30 : Seconds to wait in maintenance mode for requests admitted before it to finish}
        {--settle=5 : Seconds to wait after the switch before verifying it against the grants again}';

    protected $description = 'Switch DAM reads between the folder grants and the access lists. To lists, in maintenance mode: php artisan down, then php artisan dam:access:switch lists (it drains, switches and verifies), then php artisan up. To grants (the rollback) at any time. See --help for the states, the recovery and the residual risk';

    protected $help = <<<'HELP'
        Switching to lists runs only in maintenance mode:

          php artisan down
          php artisan dam:access:switch lists
          php artisan up

        Under the lock it shares with dam:access:rebuild, switch lists:
          1. Drains: waits --drain seconds for requests admitted before `down` to finish.
          2. In one transaction, rebuilds the lists, compares them with the grants, fills in missing owners
             and writes pending:<run>. Reads still use the grants.
          3. Settles for --settle seconds. Then, in one more transaction, compares again and makes the lists
             authoritative, or reverts to grants if the failing pairs changed.

        Each of those transactions, and every rebuild, first locks the DAM_ACCESS_MODEL row, and every change
        of state is a compare-and-set against the value read under that lock.

        The state is `grants` (or no row), `pending:<run>` or `lists`, and reads use the lists only for exactly
        `lists`. dam:access:report prints the state, and dam:access:rebuild runs only while it is exactly
        `grants`. If a switch fails or dies after step 2, the state stays pending:<run> and reads stay on the
        grants. Rerun switch lists, which redoes the whole switch, or run switch grants. switch grants is the
        rollback: from any state, at any time, without maintenance mode.

        Residual risk: a legacy grant, role or membership change admitted before `down` and still running
        after the drain, the switch and the settle is lost. Request duration bounds it, and slices 3 and 4
        remove those writers under lists.
        HELP;

    public function handle(AccessModel $model, AccessListRebuilder $rebuilder, AccessComparator $comparator, DirectoryPermissionService $permissions, ShareKeyGrant $shareKeys): int
    {
        $target = (string) $this->argument('model');

        if (! in_array($target, [AccessModel::GRANTS, AccessModel::LISTS], true)) {
            $this->error('The access model is grants or lists.');

            return self::FAILURE;
        }

        $drain = $this->seconds(option: 'drain');
        $settle = $this->seconds(option: 'settle');

        if ($drain === null || $settle === null) {
            $this->error('--drain and --settle take a whole number of seconds, 0 or more. Nothing was written.');

            return self::FAILURE;
        }

        // Every grant, group and role writer is an HTTP page, so maintenance mode keeps each of them out
        // of the window between the rebuild and the flip.
        if ($target === AccessModel::LISTS && ! $this->laravel->isDownForMaintenance()) {
            $this->error('Switching to lists needs maintenance mode, so no grant, group or role change lands between the rebuild and the flip. Nothing was written. Run php artisan down, then php artisan dam:access:switch lists, then php artisan up.');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            return $model->exclusively(
                fn (Lock $lock) => $target === AccessModel::LISTS
                    ? $this->toLists(lock: $lock, model: $model, rebuilder: $rebuilder, comparator: $comparator, permissions: $permissions, shareKeys: $shareKeys, drain: $drain, settle: $settle, started: $started)
                    : $this->toGrants(model: $model, permissions: $permissions, shareKeys: $shareKeys, started: $started),
                seconds: AccessModel::LOCK_SECONDS + $drain + $settle,
            );
        } catch (LockTimeoutException $busy) {
            $this->error($busy->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Under the lock: drain, then one transaction that rebuilds, compares, fills in owners and writes
     * pending:<run>. Then settle, verify from fresh state, and in one more transaction move pending:<run> to
     * lists, or to grants when the failing pairs changed. Every move is a compare-and-set against the value
     * this run last wrote or read; one that affects no row means another run has taken over.
     */
    protected function toLists(Lock $lock, AccessModel $model, AccessListRebuilder $rebuilder, AccessComparator $comparator, DirectoryPermissionService $permissions, ShareKeyGrant $shareKeys, int $drain, int $settle, float $started): int
    {
        // Read under the lock. Only an exact `lists` is a finished switch: a pending value is a switch that
        // never verified, and it is redone from the start.
        $read = $model->raw();

        if ($read === AccessModel::LISTS) {
            $this->info('DAM reads already use the lists model; nothing changed.');

            return self::SUCCESS;
        }

        if ($read !== null && str_starts_with($read, AccessModel::PENDING)) {
            $this->warn("Found {$read}, a switch that never finished; reads use the grants. Redoing the switch.");
        }

        // Maintenance mode stops new requests; the drain lets the ones admitted before it finish.
        $this->line("Draining: waiting {$drain}s for requests admitted before maintenance mode to finish.");
        Sleep::for($drain)->seconds();

        if (! $this->laravel->isDownForMaintenance()) {
            $this->error('Maintenance mode was lifted during the drain, so requests may be changing grants again. Nothing was written; reads stay on grants. Run php artisan down, then this command again.');

            return self::FAILURE;
        }

        $run = AccessModel::pending();

        try {
            // The state row is locked from the first statement to commit (then the directory tree lock, then the
            // generated role groups, in that order). What this run read before the drain must still stand; a
            // missing row reads, and is now created, as grants, which is the same state.
            $switched = $model->transactionally(function (?string $locked) use ($read, $run, $model, $rebuilder, $comparator) {
                if (($locked ?? AccessModel::GRANTS) !== ($read ?? AccessModel::GRANTS)) {
                    throw new AccessSwitchSuperseded;
                }

                $counts = $rebuilder->run();
                $report = $comparator->compare(withPairs: true);

                if ($report['failures'] > 0 && ! $this->option('force')) {
                    throw new AccessSwitchRefused(report: $report);
                }

                // Only here, so a preparatory rebuild never changes created_by. Every owner filled in is a
                // full admin who already sees everything, so the comparison above still holds.
                $owners = $rebuilder->fillMissingOwners();

                // Pending, not lists: reads stay on the grants until the switch is verified.
                if (! $model->compareAndSet(expected: $locked, value: $run)) {
                    throw new AccessSwitchSuperseded;
                }

                return ['counts' => $counts, 'report' => $report, 'owners' => $owners];
            });
        } catch (AccessRebuildRefused $refused) {
            $this->table(['Group id', 'Members to add', 'Members to remove'], $refused->groups);
            $this->error($refused->getMessage().' Reads stay on grants.');
            $this->line(AccessRebuildRefused::REMEDY);

            return self::FAILURE;
        } catch (AccessSwitchRefused $refused) {
            $this->failingPairs(report: $refused->report);
            FolderAccessLog::write(event: 'access_switch_refused', ctx: [
                'failures'    => $refused->report['failures'],
                'duration_ms' => $this->elapsed(started: $started),
            ]);
            $this->error($refused->getMessage());

            return self::FAILURE;
        } catch (AccessSwitchSuperseded $superseded) {
            return $this->superseded(superseded: $superseded, model: $model, started: $started);
        }

        // Logged only now, once the rebuild has committed.
        $failures = $switched['report']['failures'];
        FolderAccessLog::write(event: 'access_migrated', ctx: $switched['counts'] + ['duration_ms' => $this->elapsed(started: $started)]);
        $this->line('Rebuilt the access lists: '.json_encode($switched['counts'], JSON_THROW_ON_ERROR));
        $this->line('Owners filled in: '.json_encode($switched['owners'], JSON_THROW_ON_ERROR));

        if ($failures > 0) {
            $this->failingPairs(report: $switched['report']);
            $this->warn("--force: continuing with {$failures} failing differences.");
        }

        // A request that was still running can commit a grant, role or membership change after the
        // comparison. Reads stay on the grants while the switch settles. The lists become authoritative only
        // when a fresh comparison, run after the switch committed, still agrees.
        try {
            $this->line("Pending: reads stay on the grants. Settling {$settle}s, then checking the lists against the grants again.");
            Sleep::for($settle)->seconds();

            if (! $this->holds(lock: $lock)) {
                throw new AccessSwitchSuperseded(lockLost: true);
            }

            // Verification and finalisation, in one transaction that locks the state row first: no rebuild or
            // other transition can land between this comparison and the move it decides.
            [$verify, $verified, $shareKeyGrant] = $model->transactionally(function (?string $locked) use ($run, $model, $comparator, $permissions, $switched, $shareKeys) {
                if ($locked !== $run) {
                    throw new AccessSwitchSuperseded;
                }

                $permissions->flush();
                $verify = $comparator->compare(withPairs: true);

                // Only a check that fails exactly as the switch did, not at all or on the same pairs --force
                // let through, makes the lists authoritative.
                $verified = $this->pairs(failing: $verify['failing']) === $this->pairs(failing: $switched['report']['failing']);

                if (! $model->compareAndSet(expected: $run, value: $verified ? AccessModel::LISTS : AccessModel::GRANTS)) {
                    throw new AccessSwitchSuperseded;
                }

                // Every role holds the share keys under lists (D7); a switch that reverted on drift grants
                // nothing, since $verified is false whenever the compare-and-set above chose grants.
                $shareKeyGrant = $verified ? $shareKeys->grant() : null;

                return [$verify, $verified, $shareKeyGrant];
            });
        } catch (AccessSwitchSuperseded $superseded) {
            return $this->superseded(superseded: $superseded, model: $model, started: $started);
        } catch (Throwable $failure) {
            return $this->unverified(failure: $failure, model: $model, failures: $failures, started: $started);
        }

        $permissions->flush();

        if (! $verified) {
            $this->failingPairs(report: $verify);
            FolderAccessLog::write(event: 'access_switch_reverted', ctx: [
                'failures_at_switch' => $failures,
                'failures_at_verify' => $verify['failures'],
                'duration_ms'        => $this->elapsed(started: $started),
            ]);
            $this->error('Reverted to grants: the check after the switch no longer matches the one it switched on, so a grant, role or membership change landed during the switch. Maintenance mode is still on; run php artisan dam:access:switch lists again.');
            $this->line('The owners filled in stay: each is a full admin who already sees everything.');

            return self::FAILURE;
        }

        // Defence in depth: this run committed lists through its own fence, but without the lock it cannot
        // vouch that no other run has changed the model since.
        if (! $this->holds(lock: $lock)) {
            FolderAccessLog::write(event: 'access_switch_superseded', ctx: ['lock_lost' => true, 'committed' => true, 'duration_ms' => $this->elapsed(started: $started)]);
            $this->error('This run verified the switch and committed lists, but no longer holds the switch lock, so another run may have changed the model since. The state is now '.$this->state(model: $model).'. Check php artisan dam:access:report before php artisan up.');

            return self::FAILURE;
        }

        FolderAccessLog::write(event: 'access_model_switched', ctx: [
            'to_lists'            => true,
            'forced'              => $failures > 0,
            'failures'            => $failures,
            'folder_owners_added' => $switched['owners']['directories'],
            'file_owners_added'   => $switched['owners']['assets'],
            'duration_ms'         => $this->elapsed(started: $started),
        ]);

        // Logged only once the outcome is known: $shareKeyGrant is set only when the switch to lists itself
        // verified (see the transaction above), so a reverted switch logs nothing here.
        if ($shareKeyGrant !== null) {
            FolderAccessLog::write(event: 'share_keys_granted', ctx: [
                'roles_granted'    => $shareKeyGrant['roles_granted'],
                'keys_added'       => $shareKeyGrant['keys_added'],
                'snapshot_created' => $shareKeyGrant['snapshot_created'],
                'duration_ms'      => $this->elapsed(started: $started),
            ]);
        }

        $this->info($failures > 0
            ? "Verified: the lists still match the grants apart from the {$failures} differences --force let through."
            : 'Verified: the lists still match the grants.');
        $this->info('DAM reads now use the lists model.');
        $this->line('Run php artisan up to end maintenance mode.');

        return self::SUCCESS;
    }

    /**
     * The rollback: from any state to grants, by compare-and-set against the value read under the state row
     * lock. A missing row is created as grants, which is already the state wanted.
     */
    protected function toGrants(AccessModel $model, DirectoryPermissionService $permissions, ShareKeyGrant $shareKeys, float $started): int
    {
        try {
            [$read, $shareKeyRevert] = $model->transactionally(function (?string $locked) use ($model, $shareKeys) {
                if ($locked === AccessModel::GRANTS) {
                    return [null, null];
                }

                if (! $model->compareAndSet(expected: $locked, value: AccessModel::GRANTS)) {
                    throw new AccessSwitchSuperseded;
                }

                // A no-op unless a prior switch to lists actually granted the keys (grant() only runs once
                // that switch verifies), so rolling back a still-pending switch restores nothing.
                return [$locked ?? 'empty', $shareKeys->revert()];
            });
        } catch (AccessSwitchSuperseded $superseded) {
            return $this->superseded(superseded: $superseded, model: $model, started: $started);
        }

        if ($read === null) {
            $this->info('DAM reads already use the grants model; nothing changed.');

            return self::SUCCESS;
        }

        $permissions->flush();

        FolderAccessLog::write(event: 'access_model_switched', ctx: [
            'to_lists'     => false,
            'from_pending' => str_starts_with($read, AccessModel::PENDING),
            'duration_ms'  => $this->elapsed(started: $started),
        ]);

        if ($shareKeyRevert['snapshot_found']) {
            FolderAccessLog::write(event: 'share_keys_restored', ctx: [
                'roles_restored' => $shareKeyRevert['roles_restored'],
                'keys_removed'   => $shareKeyRevert['keys_removed'],
                'duration_ms'    => $this->elapsed(started: $started),
            ]);
        }

        $this->info("DAM reads now use the grants model (the state was {$read}).");

        return self::SUCCESS;
    }

    /**
     * Another run changed the model, or this one lost the lock: it writes nothing more.
     */
    protected function superseded(AccessSwitchSuperseded $superseded, AccessModel $model, float $started): int
    {
        FolderAccessLog::write(event: 'access_switch_superseded', ctx: [
            'lock_lost'   => $superseded->lockLost,
            'committed'   => false,
            'duration_ms' => $this->elapsed(started: $started),
        ]);
        $this->error($superseded->getMessage().' The state is now '.$this->state(model: $model).'.');
        $this->line('Reads use the lists only when that state is exactly `lists`. Check php artisan dam:access:report.');

        return self::FAILURE;
    }

    /**
     * The verification, or its compare-and-set, failed after pending:<run> committed. The report says what
     * reads use now, from the state as read afresh, never from what this run expected.
     */
    protected function unverified(Throwable $failure, AccessModel $model, int $failures, float $started): int
    {
        report($failure);

        FolderAccessLog::write(event: 'access_switch_unverified', ctx: [
            'failures_at_switch' => $failures,
            'duration_ms'        => $this->elapsed(started: $started),
        ]);
        $this->error('Unverified: the switch did not finish ('.$failure->getMessage().').');

        $observed = $this->observe(model: $model);

        if ($observed === null) {
            $this->error('The state could not be read, so which model reads use is unknown. Check php artisan dam:access:report before php artisan up.');
        } elseif ($observed['model'] === AccessModel::LISTS) {
            $this->error("The state is {$observed['state']}, so reads use the lists model. Check php artisan dam:access:report before php artisan up.");
        } else {
            $this->error("The state is {$observed['state']}, so reads use the grants model.");
            $this->line('Rerun php artisan dam:access:switch lists, or run php artisan dam:access:switch grants.');
        }

        return self::FAILURE;
    }

    /**
     * Whether this run still holds the switch lock. A lock that cannot tell is taken at its word; the
     * state row lock and the compare-and-set are the fence that matters.
     */
    protected function holds(Lock $lock): bool
    {
        return ! $lock instanceof CacheLock || $lock->isOwnedByCurrentProcess();
    }

    /**
     * The stored state as read now and the model reads use for it, by the same mapping current() uses;
     * null when the state cannot be read.
     *
     * @return array{state: string, model: string}|null
     */
    protected function observe(AccessModel $model): ?array
    {
        try {
            $raw = $model->raw();
        } catch (Throwable) {
            return null;
        }

        return ['state' => $raw ?? 'grants (no row)', 'model' => AccessModel::modelFor(raw: $raw)];
    }

    protected function state(AccessModel $model): string
    {
        return $this->observe(model: $model)['state'] ?? 'unknown (the database could not be read)';
    }

    /**
     * @param  list<array{admin_id: int, item: string, id: int, difference: string}>  $failing
     * @return list<string>
     */
    protected function pairs(array $failing): array
    {
        return collect($failing)
            ->map(fn (array $pair) => "{$pair['admin_id']}:{$pair['item']}:{$pair['id']}:{$pair['difference']}")
            ->sort()
            ->values()
            ->all();
    }

    protected function seconds(string $option): ?int
    {
        $seconds = filter_var($this->option($option), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $seconds === false ? null : $seconds;
    }

    protected function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    protected function failingPairs(array $report): void
    {
        $this->table(['Admin', 'Item', 'Id', 'Difference'], collect($report['failing'])
            ->map(fn (array $pair) => [$pair['admin_id'], $pair['item'], $pair['id'], $pair['difference']])
            ->all());
    }
}
