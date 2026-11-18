<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\MetadataFields\FieldAdoption;
use Mothership\DAM\Services\MetadataFields\FieldAdoptionException;

/**
 * Proposes (--dry-run) or creates (--apply) a typed field for each free-text property name and links the values it
 * accepts (P4a, D9). Leftovers stay free-text properties. Run --dry-run first and keep its JSON report; never run
 * from a migration.
 */
class FieldsAdopt extends Command
{
    protected $signature = 'dam:fields:adopt
        {--dry-run : Report the proposed fields and counts; write nothing}
        {--apply : Create the fields and link the values}
        {--name=* : Limit the run to these property names}';

    protected $description = 'Adopt free-text property names as typed metadata fields, with reconciled counts';

    public function handle(FieldAdoption $adoption): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --dry-run or --apply.');

            return self::INVALID;
        }

        $names = array_values(array_filter(array_map('strval', (array) $this->option('name')))) ?: null;

        try {
            $report = $adoption->run(apply: (bool) $this->option('apply'), names: $names);
        } catch (FieldAdoptionException $e) {
            $this->line(json_encode($e->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->error('The adoption does not reconcile; nothing was kept.');

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info($report['mode'] === 'dry-run'
            ? 'Dry run reconciles; nothing was written.'
            : 'Fields created and values linked; counts reconcile.');

        return self::SUCCESS;
    }
}
