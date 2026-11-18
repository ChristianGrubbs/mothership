<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessRebuildRefused;
use Mothership\DAM\Support\FolderAccessLog;

class AccessRebuild extends Command
{
    protected $signature = 'dam:access:rebuild';

    protected $description = 'Rebuild the access lists from the folder grants and print the row counts as one JSON line. Runs only while the access model state is exactly grants; reads stay on grants, and it never changes a grants answer or an owner';

    public function handle(AccessModel $model, AccessListRebuilder $rebuilder): int
    {
        $started = microtime(true);

        try {
            // Only under plain grants. Under lists the lists are the record, and a pending state is a switch
            // under way or interrupted: a rebuild must overwrite neither. The state row stays locked until the
            // rebuild commits, so no switch can activate across it.
            $outcome = $model->exclusively(fn () => $model->transactionally(
                fn (?string $locked) => $locked === AccessModel::GRANTS
                    ? ['counts' => $rebuilder->run()]
                    : ['state' => $locked]
            ));
        } catch (LockTimeoutException $busy) {
            $this->error($busy->getMessage());

            return self::FAILURE;
        } catch (AccessRebuildRefused $refused) {
            $this->table(['Group id', 'Members to add', 'Members to remove'], $refused->groups);
            $this->error($refused->getMessage());
            $this->line(AccessRebuildRefused::REMEDY);

            return self::FAILURE;
        }

        if (! isset($outcome['counts'])) {
            return $this->refuse(state: $outcome['state']);
        }

        FolderAccessLog::write(event: 'access_migrated', ctx: $outcome['counts'] + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)]);

        $this->line(json_encode($outcome['counts'], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * The state was not exactly `grants`, so nothing was written.
     */
    protected function refuse(?string $state): int
    {
        $pending = $state !== null && str_starts_with($state, AccessModel::PENDING);

        FolderAccessLog::write(event: 'access_rebuild_refused', ctx: ['state_lists' => $state === AccessModel::LISTS, 'state_pending' => $pending]);

        $this->error(match (true) {
            $state === AccessModel::LISTS => 'Reads use the access lists, which a rebuild would overwrite. Nothing was written. Run php artisan dam:access:switch grants first.',
            $pending                      => "A switch is pending or was interrupted (the state is {$state}), and a rebuild must not overwrite its lists. Nothing was written. Finish it with php artisan dam:access:switch lists, or clear it with php artisan dam:access:switch grants.",
            default                       => 'The access model state is '.($state ?? 'empty').', not grants. Nothing was written. Run php artisan dam:access:switch grants to reset it.',
        });

        return self::FAILURE;
    }
}
