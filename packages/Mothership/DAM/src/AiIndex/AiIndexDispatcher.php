<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\AiReindexLexical;

/** The one place that decides whether indexing runs (MOTHERSHIP_AI_INDEX_ENABLED). */
final class AiIndexDispatcher
{
    public static function full(int $assetId): void
    {
        if (config('ai-index.index_enabled')) {
            // The row exists from the moment the job is queued, so the file's card says "being created" instead of
            // offering to start what is already waiting. Its new token retires any job queued earlier for the file, so a
            // second request for the same file never runs twice. Rolls back with the caller's transaction.
            $token = app(AiIndexStore::class)->stamp($assetId);
            AiIndexAsset::dispatch($assetId, $token)->afterCommit();
        }
    }

    /**
     * A picture the AI can look at (DNG preview, 3D capture, game-asset poster) has just been written for this file.
     * A file whose last run found no picture, or whose run is under way and may have missed it, is indexed once more.
     * Called only where a derivative is first made, never when it is read, so it cannot loop: a run that still
     * finds nothing makes no new derivative.
     */
    public static function derivativeReady(int $assetId): void
    {
        if (! config('ai-index.index_enabled')) {
            return;
        }

        $row = DB::table('dam_ai_index')->where('asset_id', $assetId)->first();

        if (! $row) {
            return;
        }

        if ($row->status === 'done' && $row->error === AiEligibility::NO_IMAGE) {
            DB::table('dam_ai_index')->where('asset_id', $assetId)->update(['attempts' => 0]);
            self::full($assetId);
        } elseif ($row->status === 'running') {
            self::full($assetId);
        }
    }

    /** New bytes: the failed-try count belongs to the old content, so it starts over. Rolls back with the caller's transaction. */
    public static function contentReplaced(int $assetId): void
    {
        if (config('ai-index.index_enabled')) {
            DB::table('dam_ai_index')->where('asset_id', $assetId)->update(['attempts' => 0]);
            self::full($assetId);
        }
    }

    /** @param list<int> $assetIds */
    public static function lexical(array $assetIds): void
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds))));

        if ($assetIds !== [] && config('ai-index.index_enabled')) {
            foreach (array_chunk($assetIds, 200) as $chunk) {
                AiReindexLexical::dispatch($chunk)->afterCommit();
            }
        }
    }
}
