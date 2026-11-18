<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Tags;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Services\Automation\ValueSource;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\TagLog;

/**
 * Every write of a tag onto an asset (P4 D8), so `source` and removals are honoured in one place.
 *
 * - A person removing a tag from an asset leaves a tombstone (dam_asset_tag_tombstones).
 * - addManual(): a person adding one tag to one asset; clears that tombstone, since they asked for the tag back.
 * - addRespectingRemovals(): every other writer (bulk add, AI accept, rules, import); skips a tombstoned pair.
 * - Tag names go through resolve(), which applies TagNameRule and finds or creates the shared tag.
 *
 * Each change dispatches `core.model.proxy.sync.tag` for the asset, which feeds its history and queues the
 * lexical re-index (DAMServiceProvider).
 */
final class AssetTagWriter
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_IMPORT = 'import';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_AI, self::SOURCE_RULE, self::SOURCE_IMPORT];

    /**
     * The shared tags for these names, created when absent: normalized, checked by TagNameRule, matched
     * case-insensitively, and deduplicated. A refused name throws a 422 on `$attribute` (or `$attribute.N`
     * for a list), naming no word.
     *
     * @param  list<mixed>|string  $names
     * @return Collection<int, Tag>
     */
    public function resolve(array|string $names, string $attribute = 'tag'): Collection
    {
        $list = is_array($names);
        $errors = [];
        $normalized = [];

        foreach (array_values((array) $names) as $index => $name) {
            $problem = TagNameRule::problem($name);

            if ($problem !== null) {
                $errors[$list ? $attribute.'.'.$index : $attribute][] = $problem;

                continue;
            }

            $clean = TagNameRule::normalize((string) $name);
            $normalized[mb_strtolower($clean)] ??= $clean;
        }

        if ($errors !== []) {
            TagLog::write(event: 'tag.name_refused', ctx: ['attribute' => mb_substr($attribute, 0, 32), 'count' => count($errors)]);

            throw ValidationException::withMessages($errors);
        }

        return collect(array_values($normalized))->map(fn (string $name) => $this->sharedTag($name))->values();
    }

    /** One person adding one tag to one asset. Clears their earlier removal. False when it was already there. */
    public function addManual(Asset $asset, Tag $tag, ?int $actorId): bool
    {
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        $oldTags = $this->names($asset);

        $added = DB::transaction(function () use ($asset, $tag) {
            DB::table('dam_asset_tag_tombstones')->where('asset_id', $asset->id)->where('tag_id', $tag->id)->delete();

            return DB::table('dam_asset_tag')->insertOrIgnore([
                'asset_id' => $asset->id, 'tag_id' => $tag->id, 'source' => self::SOURCE_MANUAL,
            ]) > 0;
        });

        TagLog::write(event: 'tag.added', ctx: ['asset_id' => (int) $asset->id, 'tag_id' => (int) $tag->id, 'source' => self::SOURCE_MANUAL, 'added' => $added, 'actor_id' => (int) $actorId]);

        if ($added) {
            $this->changed(asset: $asset, oldTags: $oldTags, source: self::SOURCE_MANUAL);
        }

        return $added;
    }

    /**
     * Adds tags to assets for a writer that must not undo a person's removal: bulk add, AI accept, rules and
     * import. A tombstoned (asset, tag) pair is skipped; a pair already present keeps its row and source.
     *
     * @param  list<int>  $assetIds
     * @param  list<int>  $tagIds
     * @return list<int> the assets that gained at least one tag
     */
    public function addRespectingRemovals(array $assetIds, array $tagIds, string $source): array
    {
        $this->assertSource($source);
        // A person's bulk add (source manual, or an accepted AI term) tags only the files they added when their role is
        // limited to its own items; callers report the difference. Machine writers have no signed-in admin and pass whole.
        $assetIds = $source === self::SOURCE_MANUAL || str_starts_with($source, self::SOURCE_AI)
            ? app(RoleBuilder::class)->ownedAssetIds($assetIds)
            : array_values(array_unique(array_map('intval', $assetIds)));
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        if ($assetIds === [] || $tagIds === []) {
            return [];
        }

        $changed = [];
        $skipped = 0;

        foreach (array_chunk($assetIds, 200) as $chunk) {
            $removed = $this->tombstonedPairs(assetIds: $chunk, tagIds: $tagIds);
            $assets = Asset::query()->whereIn('id', $chunk)->get()->keyBy('id');

            foreach ($chunk as $assetId) {
                $asset = $assets->get($assetId);

                if ($asset === null) {
                    continue;
                }

                $rows = [];

                foreach ($tagIds as $tagId) {
                    if (isset($removed[$assetId.':'.$tagId])) {
                        $skipped++;

                        continue;
                    }

                    $rows[] = ['asset_id' => $assetId, 'tag_id' => $tagId, 'source' => $source];
                }

                if ($rows === []) {
                    continue;
                }

                $oldTags = $this->names($asset);

                if (DB::table('dam_asset_tag')->insertOrIgnore($rows) > 0) {
                    $changed[] = $assetId;
                    $this->changed(asset: $asset, oldTags: $oldTags, source: $source);
                }
            }
        }

        TagLog::write(event: 'tag.added_respecting_removals', ctx: [
            'source' => $source, 'assets' => count($assetIds), 'tags' => count($tagIds), 'changed' => count($changed), 'skipped_removed' => $skipped,
        ]);

        return $changed;
    }

    /** A person removing a tag from an asset: detaches it and records the removal. False when it was not there. */
    public function remove(Asset $asset, Tag $tag, ?int $actorId): bool
    {
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        $oldTags = $this->names($asset);

        $removed = DB::transaction(function () use ($asset, $tag, $actorId) {
            $detached = DB::table('dam_asset_tag')->where('asset_id', $asset->id)->where('tag_id', $tag->id)->delete() > 0;

            if ($detached) {
                $this->tombstone(assetId: (int) $asset->id, tagId: (int) $tag->id, actorId: $actorId);
            }

            return $detached;
        });

        TagLog::write(event: 'tag.removed', ctx: ['asset_id' => (int) $asset->id, 'tag_id' => (int) $tag->id, 'removed' => $removed, 'actor_id' => (int) $actorId]);

        if ($removed) {
            $this->changed(asset: $asset, oldTags: $oldTags, source: self::SOURCE_MANUAL);
        }

        return $removed;
    }

    /**
     * A person replacing an asset's whole tag set (REST asset update): each tag that goes is a removal, each that
     * arrives a manual add.
     *
     * @param  list<int>  $tagIds
     */
    public function sync(Asset $asset, array $tagIds, ?int $actorId): void
    {
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        $current = DB::table('dam_asset_tag')->where('asset_id', $asset->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
        $oldTags = $this->names($asset);
        $leaving = array_values(array_diff($current, $tagIds));
        $arriving = array_values(array_diff($tagIds, $current));

        if ($leaving === [] && $arriving === []) {
            return;
        }

        DB::transaction(function () use ($asset, $leaving, $arriving, $actorId) {
            if ($leaving !== []) {
                DB::table('dam_asset_tag')->where('asset_id', $asset->id)->whereIn('tag_id', $leaving)->delete();

                foreach ($leaving as $tagId) {
                    $this->tombstone(assetId: (int) $asset->id, tagId: $tagId, actorId: $actorId);
                }
            }

            if ($arriving !== []) {
                DB::table('dam_asset_tag_tombstones')->where('asset_id', $asset->id)->whereIn('tag_id', $arriving)->delete();
                DB::table('dam_asset_tag')->insertOrIgnore(array_map(
                    fn (int $tagId) => ['asset_id' => $asset->id, 'tag_id' => $tagId, 'source' => self::SOURCE_MANUAL],
                    $arriving
                ));
            }
        });

        TagLog::write(event: 'tag.synced', ctx: ['asset_id' => (int) $asset->id, 'removed' => count($leaving), 'added' => count($arriving), 'actor_id' => (int) $actorId]);
        $this->changed(asset: $asset, oldTags: $oldTags, source: self::SOURCE_MANUAL);
    }

    public function isRemoved(int $assetId, int $tagId): bool
    {
        return DB::table('dam_asset_tag_tombstones')->where('asset_id', $assetId)->where('tag_id', $tagId)->exists();
    }

    /**
     * The lower-case names of the tags a person removed from this asset: search (LexicalDocument) and the AI
     * suggestion list leave these out wherever else the word comes from.
     *
     * @return list<string>
     */
    public static function removedNames(int $assetId): array
    {
        return DB::table('dam_asset_tag_tombstones')
            ->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag_tombstones.tag_id')
            ->where('dam_asset_tag_tombstones.asset_id', $assetId)
            ->pluck('dam_tags.name')
            ->map(fn ($name) => mb_strtolower((string) $name))
            ->unique()->values()->all();
    }

    /** The shared tag with this (already normalized) name, case-insensitively; a concurrent create is reused. */
    private function sharedTag(string $name): Tag
    {
        $find = fn () => Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($tag = $find()) {
            return $tag;
        }

        try {
            // Nested transaction = savepoint, so a unique-index violation cannot poison an outer one on pgsql.
            return DB::transaction(fn () => Tag::query()->create(['name' => $name]));
        } catch (QueryException $e) {
            return $find() ?? throw $e;
        }
    }

    private function tombstone(int $assetId, int $tagId, ?int $actorId): void
    {
        DB::table('dam_asset_tag_tombstones')->upsert(
            [['asset_id' => $assetId, 'tag_id' => $tagId, 'removed_by' => $actorId, 'removed_at' => now()]],
            ['asset_id', 'tag_id'],
            ['removed_by', 'removed_at']
        );
    }

    /**
     * @param  list<int>  $assetIds
     * @param  list<int>  $tagIds
     * @return array<string, true> keyed "asset:tag"
     */
    private function tombstonedPairs(array $assetIds, array $tagIds): array
    {
        return DB::table('dam_asset_tag_tombstones')->whereIn('asset_id', $assetIds)->whereIn('tag_id', $tagIds)
            ->get(['asset_id', 'tag_id'])
            ->mapWithKeys(fn ($row) => [((int) $row->asset_id).':'.((int) $row->tag_id) => true])
            ->all();
    }

    /** @return list<string> */
    private function names(Asset $asset): array
    {
        return $asset->tags()->pluck('name')->all();
    }

    /**
     * The tags currently on an asset, by lower-case name, with who put each there (P5b ValueWriter's view).
     *
     * @return array<string, array{tag_id: int, source: string}>
     */
    public function current(int $assetId): array
    {
        return DB::table('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
            ->where('dam_asset_tag.asset_id', $assetId)
            ->get(['dam_tags.name', 'dam_asset_tag.tag_id', 'dam_asset_tag.source'])
            ->mapWithKeys(fn ($row) => [mb_strtolower((string) $row->name) => ['tag_id' => (int) $row->tag_id, 'source' => (string) $row->source]])
            ->all();
    }

    /**
     * Raises the history and re-index event, and P5b's AssetValuesChanged for the automation triggers and the
     * `dam.asset.updated` webhook.
     *
     * @param  list<string>  $oldTags
     */
    private function changed(Asset $asset, array $oldTags, string $source): void
    {
        $asset->unsetRelation('tags');

        Event::dispatch('core.model.proxy.sync.tag', [
            'old_values' => $oldTags,
            'new_values' => $this->names($asset),
            'model'      => $asset,
        ]);

        Event::dispatch(new AssetValuesChanged(assetId: (int) $asset->id, kind: AssetValuesChanged::KIND_TAG, keys: ['tags'], source: $source));
    }

    /** P4d's channels, plus P5b's machine sources: `ai:<model>`, `mapping:<id>` and `rule:<id>@v<n>`. */
    private function assertSource(string $source): void
    {
        // P5c (D8, roadmap §3.4): an accepted AI suggestion names its model, `ai:<model>`.
        if (! in_array($source, self::SOURCES, true) && ! ValueSource::validMachine($source) && preg_match('/^ai:[\x21-\x7e]{1,80}$/', $source) !== 1) {
            throw new \InvalidArgumentException('Unknown tag source.');
        }
    }
}
