<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\VideoPreviewProfile;

class GenerateVideoPreviews extends Command
{
    protected $signature = 'dam:generate-video-previews
        {--limit= : Stop after queueing this many assets}
        {--force : Re-queue assets that already have a finished proxy}
        {--dry-run : Report what would be queued without queueing anything}
        {--members : Work on stacked videos other than the cover (an `other` slot, as a merge leaves it) that have no preview proxy of their own}';

    protected $description = 'Generate 1080p preview proxies for existing videos that need one (oversized, unknown size, or not browser-playable).';

    public function handle(VideoPreviewProfile $profile): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('members')) {
            return $this->handleMembers(limit: $limit, force: $force, dryRun: $dryRun);
        }

        $queued = 0;
        $skipped = 0;

        Asset::where('file_type', 'video')
            ->orderBy('id')
            ->lazy()
            ->each(function (Asset $asset) use ($profile, $force, $dryRun, $limit, &$queued, &$skipped) {
                if ($limit !== null && $queued >= $limit) {
                    return false;
                }

                if (! $profile->needsProxy($asset)) {
                    $skipped++;

                    return true;
                }

                if (! $force && $this->hasFinishedProxy($asset)) {
                    $skipped++;

                    return true;
                }

                if (! $dryRun) {
                    GenerateVideoPreviewProxy::dispatch($asset->id, $force);
                }

                $queued++;

                return true;
            });

        $verb = $dryRun ? 'would queue' : 'queued';

        $this->info("Preview proxies: {$verb} {$queued}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Feedback #6: a stacked video other than the cover never had a proxy (a merge does not link the source's). Lists
     * each candidate when --dry-run, so the run can be read before anything is queued. The job re-checks every file
     * (already has a proxy, small enough to play as it is) so a queued file may still be skipped there.
     */
    private function handleMembers(?int $limit, bool $force, bool $dryRun): int
    {
        $queued = 0;
        $skipped = 0;

        AssetFile::query()->with(['fileObject', 'asset'])
            ->where('is_current', true)
            ->whereNotIn('role', [...Asset::UNSTACKED_ROLES, AssetFile::ROLE_ORIGINAL])
            ->whereHas('fileObject', fn ($object) => $object->where('mime_type', 'like', 'video/%'))
            ->whereHas('attachment', fn ($slot) => $slot->whereNull('merge_id'))
            ->whereHas('asset')
            ->orderBy('id')
            ->lazy()
            ->each(function (AssetFile $file) use ($force, $dryRun, $limit, &$queued, &$skipped) {
                if ($limit !== null && $queued >= $limit) {
                    return false;
                }

                /** @var Asset $asset */
                $asset = $file->asset;
                $playback = $asset->stackMemberPlayback($file);

                if ($playback === null || ($playback['is_proxy'] && ! $force)) {
                    $skipped++;

                    return true;
                }

                if ($dryRun) {
                    $this->line(sprintf('asset %d file %d %s (%s bytes)', $file->dam_asset_id, $file->id, $file->label ?: $file->role, $file->fileObject->size));
                } else {
                    GenerateVideoPreviewProxy::dispatch((int) $file->dam_asset_id, $force, (int) $file->id);
                }

                $queued++;

                return true;
            });

        $verb = $dryRun ? 'would queue' : 'queued';

        $this->info("Stacked-video preview proxies: {$verb} {$queued}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function hasFinishedProxy(Asset $asset): bool
    {
        return $asset->renditions()
            ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
            ->where('status', Rendition::STATUS_DONE)
            ->fromCurrentContent((int) $asset->id)
            ->exists();
    }
}
