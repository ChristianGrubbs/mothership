<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\DngPreviewService;

class BackfillThumbnails extends Command
{
    protected $signature = 'dam:backfill-thumbnails {--sync : Run jobs synchronously instead of dispatching to the queue}';

    protected $description = 'Generate real thumbnails (DNG preview / first PDF page / first video frame) for existing DAM assets that are still showing placeholder icons.';

    public function handle(): int
    {
        $sync = (bool) $this->option('sync');

        $videoCount = Asset::where('file_type', 'video')->count();
        $pdfCount = Asset::whereRaw('LOWER(extension) = ?', ['pdf'])->count();
        $dngCount = Asset::whereRaw('LOWER(extension) = ?', ['dng'])->count();

        $this->info("Found {$videoCount} videos, {$pdfCount} PDFs and {$dngCount} DNGs.");

        $skipped = 0;
        $queued = 0;

        Asset::where('file_type', 'video')
            ->select(['id', 'meta_data'])
            ->lazy()
            ->each(function (Asset $asset) use ($sync, &$skipped, &$queued) {
                if (! empty($asset->meta_data['thumbnail_path'] ?? null)) {
                    $skipped++;

                    return;
                }
                $sync
                    ? (new GenerateVideoThumbnail($asset->id))->handle()
                    : GenerateVideoThumbnail::dispatch($asset->id);
                $queued++;
            });

        Asset::whereRaw('LOWER(extension) = ?', ['pdf'])
            ->select(['id', 'meta_data'])
            ->lazy()
            ->each(function (Asset $asset) use ($sync, &$skipped, &$queued) {
                if (! empty($asset->meta_data['thumbnail_path'] ?? null)) {
                    $skipped++;

                    return;
                }
                $sync
                    ? (new GeneratePdfThumbnail($asset->id))->handle()
                    : GeneratePdfThumbnail::dispatch($asset->id);
                $queued++;
            });

        Asset::whereRaw('LOWER(extension) = ?', ['dng'])->lazy()->each(function (Asset $asset) use ($sync, &$queued, &$skipped) {
            if (app(DngPreviewService::class)->ensure($asset, thumbnail: true, cachedOnly: true)) {
                $skipped++;

                return;
            }
            $job = new GenerateDngPreview($asset->id);
            $sync ? $job->handle() : dispatch($job);
            $queued++;
        });

        $action = $sync ? 'processed' : 'queued';
        $this->info("Done. {$queued} {$action}, {$skipped} skipped (already had thumbnails).");

        if (! $sync && $queued > 0) {
            $this->line('Make sure a queue worker is running (e.g. `php artisan queue:work`).');
        }

        return self::SUCCESS;
    }
}
