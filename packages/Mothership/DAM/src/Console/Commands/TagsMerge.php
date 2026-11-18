<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Tags\TagVocabulary;

/**
 * Folds one shared tag into another (P4 D8): the operator's merge until the vocabulary admin (P5) gives it a page.
 * Re-indexes every affected asset, like a rename or delete from the Tags page.
 */
class TagsMerge extends Command
{
    protected $signature = 'dam:tags:merge {from : Id of the tag that goes} {into : Id of the tag that stays} {--dry-run : Only report what would move}';

    protected $description = 'Merge one tag into another, keeping removals, and re-index the affected assets';

    public function handle(TagVocabulary $vocabulary): int
    {
        $from = Tag::query()->find((int) $this->argument('from'));
        $into = Tag::query()->find((int) $this->argument('into'));

        if ($from === null || $into === null || $from->id === $into->id) {
            $this->error('Name two different existing tag ids.');

            return self::FAILURE;
        }

        $report = $vocabulary->merge(from: $from, into: $into, dryRun: (bool) $this->option('dry-run'));
        $this->line(json_encode($report + ['dry_run' => (bool) $this->option('dry-run')], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
