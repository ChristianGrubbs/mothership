<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Tags;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\TagLog;

/**
 * Changes to the shared tag list itself (P4 D8): rename, merge and delete. Each one changes the words of every
 * asset that carries the tag, so each queues the lexical re-index for those assets, in chunks of 200
 * (AiIndexDispatcher::lexical), after the change commits. A delete collects the assets before the cascade
 * removes their rows.
 */
final class TagVocabulary
{
    public function rename(Tag $tag, string $name): void
    {
        $this->abortIfCarriedByForeignAssets(tagIds: [(int) $tag->id]);

        DB::transaction(fn () => $tag->update(['name' => $name]));
        $queued = $this->reindex(tagIds: [(int) $tag->id]);
        TagLog::write(event: 'tag.renamed', ctx: ['tag_id' => (int) $tag->id, 'assets_queued' => $queued]);
    }

    /** @param  list<int>  $tagIds */
    public function delete(array $tagIds): int
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        if ($tagIds === []) {
            return 0;
        }

        $this->abortIfCarriedByForeignAssets(tagIds: $tagIds);

        return DB::transaction(function () use ($tagIds) {
            $queued = $this->reindex(tagIds: $tagIds);
            $deleted = Tag::query()->whereIn('id', $tagIds)->delete();
            TagLog::write(event: 'tag.deleted', ctx: ['tags' => $deleted, 'assets_queued' => $queued]);

            return $deleted;
        });
    }

    /**
     * Folds $from into $into: every asset tagged $from is tagged $into (keeping the row's source) unless a person
     * removed $into from it, a removal of $from carries over as a removal of $into, and $from is deleted.
     *
     * @return array{assets: int, moved: int, skipped_removed: int}
     */
    public function merge(Tag $from, Tag $into, bool $dryRun = false): array
    {
        abort_if((int) $from->id === (int) $into->id, 422);
        $this->abortIfCarriedByForeignAssets(tagIds: [(int) $from->id, (int) $into->id]);

        $report = DB::transaction(function () use ($from, $into, $dryRun) {
            $rows = DB::table('dam_asset_tag')->where('tag_id', $from->id)->orderBy('asset_id')->get(['asset_id', 'source']);
            $blocked = DB::table('dam_asset_tag_tombstones')->where('tag_id', $into->id)->whereIn('asset_id', $rows->pluck('asset_id'))
                ->pluck('asset_id')->map(fn ($id) => (int) $id)->flip();
            $moving = $rows->reject(fn ($row) => $blocked->has((int) $row->asset_id));
            $report = ['assets' => $rows->count(), 'moved' => $moving->count(), 'skipped_removed' => $rows->count() - $moving->count()];

            if ($dryRun) {
                return $report;
            }

            foreach ($moving->chunk(200) as $chunk) {
                DB::table('dam_asset_tag')->insertOrIgnore($chunk->map(fn ($row) => [
                    'asset_id' => (int) $row->asset_id, 'tag_id' => (int) $into->id, 'source' => (string) $row->source,
                ])->values()->all());
            }

            // A removal of $from becomes a removal of $into, except on an asset that carries $into: the tag is there.
            $removals = DB::table('dam_asset_tag_tombstones')->where('tag_id', $from->id)
                ->whereNotIn('asset_id', DB::table('dam_asset_tag')->where('tag_id', $into->id)->select('asset_id'))
                ->get(['asset_id', 'removed_by', 'removed_at']);

            foreach ($removals->chunk(200) as $chunk) {
                DB::table('dam_asset_tag_tombstones')->insertOrIgnore($chunk->map(fn ($row) => [
                    'asset_id' => (int) $row->asset_id, 'tag_id' => (int) $into->id, 'removed_by' => $row->removed_by, 'removed_at' => $row->removed_at,
                ])->values()->all());
            }

            $this->reindex(tagIds: [(int) $from->id, (int) $into->id]);
            $from->delete();

            return $report;
        });

        TagLog::write(event: 'tag.merged', ctx: ['from_id' => (int) $from->id, 'into_id' => (int) $into->id, 'dry_run' => $dryRun] + $report);

        return $report;
    }

    /**
     * A role limited to its own items changes or deletes the shared tag list only for tags no one else's file carries:
     * a delete cascades off every file that has the tag and a rename changes their words. The answer is one plain
     * sentence with no counts, so it says nothing about files the person cannot see. Machine callers (no signed-in
     * admin) and unrestricted roles pass.
     *
     * @param  list<int>  $tagIds
     */
    private function abortIfCarriedByForeignAssets(array $tagIds): void
    {
        $roles = app(RoleBuilder::class);

        if (! $roles->restrictedToOwn()) {
            return;
        }

        $actor = (int) $roles->actingAdmin()?->id;
        // A removal decision is someone's data about that file too: a delete cascades it away, a rename changes the
        // name it suppresses and a merge rewrites it, so tombstones count exactly like tag rows.
        $foreign = false;

        foreach (['dam_asset_tag', 'dam_asset_tag_tombstones'] as $table) {
            $foreign = $foreign || DB::table($table)
                ->join('dam_assets', 'dam_assets.id', '=', $table.'.asset_id')
                ->whereIn($table.'.tag_id', $tagIds)
                ->where(fn ($q) => $q->whereNull('dam_assets.created_by')->orWhere('dam_assets.created_by', '!=', $actor))
                ->exists();
        }

        abort_if($foreign, 403, trans('dam::app.admin.permissions.own-only-tag-vocabulary'));
    }

    /**
     * Queues the lexical re-index of every asset carrying any of these tags, 200 per job.
     *
     * @param  list<int>  $tagIds
     * @return int assets queued
     */
    public function reindex(array $tagIds): int
    {
        $queued = 0;

        DB::table('dam_asset_tag')->whereIn('tag_id', $tagIds)->select('asset_id')->distinct()->orderBy('asset_id')
            ->chunk(1000, function ($rows) use (&$queued) {
                $ids = $rows->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
                AiIndexDispatcher::lexical($ids);
                $queued += count($ids);
            });

        return $queued;
    }
}
