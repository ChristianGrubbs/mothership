<?php

namespace Mothership\DAM\Support;

/**
 * What an operator asked `dam:storage:reclaim` for (ADR 0009 slice 4). A run is a report unless `force` is set;
 * `dryRun` only records that `--dry-run` was passed, so a run that asks for both is refused rather than guessed
 * at. `backup` names a `storage/dam-backups/<name>/` pair taken by `dam:storage:backup`.
 */
final readonly class ReclaimOptions
{
    public function __construct(
        public bool $force = false,
        public bool $dryRun = false,
        public ?string $backup = null,
    ) {}
}
