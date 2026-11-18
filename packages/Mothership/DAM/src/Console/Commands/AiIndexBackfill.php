<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\Jobs\AiIndexAsset;

class AiIndexBackfill extends Command
{
    protected $signature = 'dam:ai-index:backfill {--only-missing : Skip assets already indexed} {--limit=0 : Stop after N assets}';

    protected $description = 'Queue AI indexing (words, caption, tags, vectors) for existing assets';

    public function handle(AiBridgeClient $bridge, AiIndexStore $store): int
    {
        if (! $bridge->ready()) {
            $this->error('MOTHERSHIP_AI_API is not set; nothing queued.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $queued = 0;
        $query = DB::table('dam_assets')->leftJoin('dam_ai_index', 'dam_ai_index.asset_id', '=', 'dam_assets.id')
            ->select('dam_assets.id')->orderBy('dam_assets.id');

        if ($this->option('only-missing')) {
            $query->where(fn ($q) => $q->whereNull('dam_ai_index.status')->orWhere('dam_ai_index.status', '!=', 'done'));
        }

        foreach ($query->lazy() as $row) {
            // Through the claim like every other dispatch: a file whose run is queued or going is left to it.
            $token = $store->claim((int) $row->id);

            if ($token === null) {
                continue;
            }

            AiIndexAsset::dispatch((int) $row->id, $token);
            $queued++;

            if ($limit > 0 && $queued >= $limit) {
                break;
            }
        }

        $this->info("Queued {$queued} asset(s) on ai-index.");

        return self::SUCCESS;
    }
}
