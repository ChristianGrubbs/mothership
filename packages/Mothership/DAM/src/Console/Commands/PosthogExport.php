<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Analytics\PosthogExporter;

final class PosthogExport extends Command
{
    protected $signature = 'dam:analytics:export {--limit=100 : Maximum pending events per run (1–200)} {--health : Read delivery health without sending events}';

    protected $description = 'Deliver committed analytics events to the configured PostHog project';

    public function handle(PosthogExporter $exporter): int
    {
        if ($this->option('health')) {
            $this->line(json_encode($exporter->health(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $result = $exporter->drain(limit: (int) $this->option('limit'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
