<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Tags;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\AiVocabulary;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\TagLog;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Accepting and dismissing AI tag suggestions (P4 D8, P5c D8): the one code path the asset page, the AI tag review
 * queue and REST share, so a decision persists and survives re-indexing the same way wherever it was made
 * (AiIndexStore::storeResult only replaces open suggestions).
 *
 * - accept: claims the open suggestion, then puts the tag on the asset through AssetTagWriter::addRespectingRemovals
 *   with `source` = `ai:<model>` (the model that proposed it, dam_ai_index.caption_model; plain `ai` when unknown).
 *   The tag is the one the term maps to, else the shared tag named by its label (TagNameRule applies). A tag a person
 *   removed from this asset is never put back: 422.
 * - dismiss: claims it as dismissed and queues the asset's lexical re-index.
 * - A term that is not enabled in the vocabulary, or a suggestion that is no longer open, is a 422.
 *
 * Callers check the permission (dam.asset.tag, or the REST scope) and the asset's visibility first; the queue lists
 * only suggestions on assets the reviewer may list, of enabled terms, minus those a person's removal has closed.
 */
class AiTagReview
{
    public const BULK_MAX = 200;

    public const PER_PAGE = 60;

    public const GROUP_TERM = 'term';

    public const GROUP_ASSET = 'asset';

    public function __construct(
        private AssetTagWriter $tagWriter,
        private AiVocabulary $vocabulary,
        private AssetAccessDecision $access,
    ) {}

    /** The term when it is enabled and open on the asset; 422 otherwise. */
    public function openTerm(int $assetId, string $term): string
    {
        abort_unless(
            $this->vocabulary->enabledTerm($term) !== null
            && DB::table('dam_ai_tags')->where('asset_id', $assetId)->where('term', $term)->where('status', 'suggested')->exists(),
            422
        );

        return $term;
    }

    public function accept(int $assetId, string $term, ?int $actorId): void
    {
        $this->abortUnlessOwn(assetId: $assetId);

        $entry = $this->vocabulary->enabledTerm($term);
        abort_if($entry === null, 422);

        $mapped = $entry['maps_to_tag_id'] === null ? null : Tag::query()->where('id', $entry['maps_to_tag_id'])->first();

        if ($mapped === null && ($problem = TagNameRule::problem($entry['label'])) !== null) {
            throw ValidationException::withMessages(['term' => [$problem]]);
        }

        // A person removed this tag from this file: the suggestion cannot put it back (P4 D8).
        $existing = $mapped ?? Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(TagNameRule::normalize($entry['label']))])->first();
        abort_if($existing !== null && $this->tagWriter->isRemoved(assetId: $assetId, tagId: (int) $existing->id), 422);

        $source = self::sourceFor($assetId);

        DB::transaction(function () use ($assetId, $term, $entry, $mapped, $source, $actorId): void {
            // Claim the suggestion first: of two racing requests only one changes a row, so only one
            // creates the shared tag and the other gets a clean 422 instead of a unique-index 500.
            $this->claim(assetId: $assetId, term: $term, status: 'accepted', actorId: $actorId);

            $tag = $mapped ?? $this->tagWriter->resolve($entry['label'], attribute: 'term')->first();
            $this->tagWriter->addRespectingRemovals(assetIds: [$assetId], tagIds: [(int) $tag->id], source: $source);
        });
    }

    public function dismiss(int $assetId, string $term, ?int $actorId): void
    {
        $this->abortUnlessOwn(assetId: $assetId);

        $this->claim(assetId: $assetId, term: $term, status: 'dismissed', actorId: $actorId);
        AiIndexDispatcher::lexical([$assetId]);
    }

    /**
     * Accepts or dismisses many suggestions, each through accept()/dismiss(). An item on an asset the reviewer may not
     * see answers `not_found`, exactly like a missing asset.
     *
     * @param  list<array{asset_id: int, term: string}>  $items
     * @return array{done: int, not_yours: int, results: list<array{asset_id: int, term: string, outcome: string}>}
     */
    public function bulk(string $action, array $items, AccessContext $context, ?int $actorId): array
    {
        $results = [];
        $done = 0;
        $notYours = 0;

        foreach ($items as $item) {
            $assetId = (int) $item['asset_id'];
            $term = (string) $item['term'];

            if (! $this->access->visible($context, $assetId) || ! DB::table('dam_assets')->where('id', $assetId)->exists()) {
                $results[] = ['asset_id' => $assetId, 'term' => $term, 'outcome' => 'not_found'];

                continue;
            }

            // A role limited to its own items reviews suggestions on the files it added only; asked before any suggestion is claimed.
            if ($this->refusedFor(assetId: $assetId) !== null) {
                $results[] = ['asset_id' => $assetId, 'term' => $term, 'outcome' => 'not_yours'];
                $notYours++;

                continue;
            }

            try {
                $this->openTerm(assetId: $assetId, term: $term);
                $action === 'accept'
                    ? $this->accept(assetId: $assetId, term: $term, actorId: $actorId)
                    : $this->dismiss(assetId: $assetId, term: $term, actorId: $actorId);
                $results[] = ['asset_id' => $assetId, 'term' => $term, 'outcome' => $action === 'accept' ? 'accepted' : 'dismissed'];
                $done++;
            } catch (ValidationException) {
                $results[] = ['asset_id' => $assetId, 'term' => $term, 'outcome' => 'refused'];
            } catch (HttpException) {
                $results[] = ['asset_id' => $assetId, 'term' => $term, 'outcome' => 'not_open'];
            }
        }

        TagLog::write(event: 'ai_review.bulk', ctx: ['action' => $action, 'items' => count($items), 'done' => $done, 'not_yours' => $notYours, 'actor_id' => (int) $actorId, 'via' => $context->via]);

        return ['done' => $done, 'not_yours' => $notYours, 'results' => $results];
    }

    /**
     * One page of the queue: open suggestions of enabled terms on assets the reviewer may list, ordered by term then
     * asset (group `term`) or by asset then term (group `asset`), with the count per term over the whole filter.
     *
     * @return array{data: list<array<string, mixed>>, terms: list<array{term: string, label: string, count: int}>, meta: array<string, int|string>}
     */
    public function queue(AccessContext $context, string $group = self::GROUP_TERM, ?string $term = null, ?int $folderId = null, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $terms = $this->queueQuery(context: $context, term: null, folderId: $folderId)
            ->selectRaw('t.term AS term, v.label AS label, COUNT(*) AS aggregate')
            ->groupBy('t.term', 'v.label')
            ->orderBy('v.label')
            ->get()
            ->map(fn (object $row) => ['term' => (string) $row->term, 'label' => (string) $row->label, 'count' => (int) $row->aggregate])
            ->values()->all();

        $query = $this->queueQuery(context: $context, term: $term, folderId: $folderId);
        $total = (clone $query)->count();
        $perPage = max(1, min(200, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $query->select(['t.id', 't.asset_id', 't.term', 'v.label', 'dam_assets.file_name', 'dam_assets.path']);
        $group === self::GROUP_ASSET
            ? $query->orderBy('dam_assets.id')->orderBy('v.label')
            : $query->orderBy('v.label')->orderBy('dam_assets.id');

        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return [
            'data' => $rows->map(fn (object $row) => [
                'id'            => (int) $row->id,
                'asset_id'      => (int) $row->asset_id,
                'term'          => (string) $row->term,
                'label'         => (string) $row->label,
                'asset_name'    => (string) $row->file_name,
                'thumbnail_url' => route('admin.dam.file.thumbnail', ['path' => urlencode((string) $row->path)]),
                'url'           => route('admin.dam.assets.edit', ['id' => (int) $row->asset_id]),
            ])->values()->all(),
            'terms' => $terms,
            'meta'  => ['total' => $total, 'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'group' => $group],
        ];
    }

    /** `ai:<model>` from the asset's index row, `ai` when the model is unknown. */
    public static function sourceFor(int $assetId): string
    {
        $model = trim((string) DB::table('dam_ai_index')->where('asset_id', $assetId)->value('caption_model'));

        return $model === '' ? AssetTagWriter::SOURCE_AI : AssetTagWriter::SOURCE_AI.':'.mb_substr($model, 0, 80);
    }

    private function queueQuery(AccessContext $context, ?string $term, ?int $folderId): Builder
    {
        $query = DB::table('dam_ai_tags as t')
            ->join('dam_ai_vocabulary as v', 'v.term', '=', 't.term')
            ->join('dam_assets', 'dam_assets.id', '=', 't.asset_id')
            ->where('t.status', 'suggested')
            ->where('v.enabled', true)
            // A person removed the tag this suggestion would add: it is closed (AiIndexStore::suggestedTags).
            ->whereNotExists(fn ($removed) => $removed->select(DB::raw(1))
                ->from('dam_asset_tag_tombstones as tt')
                ->join('dam_tags as tg', 'tg.id', '=', 'tt.tag_id')
                ->whereColumn('tt.asset_id', 't.asset_id')
                ->where(fn ($match) => $match->whereColumn('tg.id', 'v.maps_to_tag_id')
                    ->orWhereRaw('LOWER('.DB::connection()->getQueryGrammar()->wrap('tg.name').') = LOWER('.DB::connection()->getQueryGrammar()->wrap('v.label').')')));

        if ($term !== null) {
            $query->where('t.term', $term);
        }

        if ($folderId !== null) {
            $query->whereExists(fn ($in) => $in->select(DB::raw(1))->from('dam_asset_directory')
                ->whereColumn('dam_asset_directory.asset_id', 't.asset_id')->where('dam_asset_directory.directory_id', $folderId));
        }

        $this->access->scopeListable(query: $query, context: $context);

        return $query;
    }

    /** The plain refusal when the signed-in admin is limited to their own items and the asset is not theirs; null otherwise. */
    private function refusedFor(int $assetId): ?string
    {
        $asset = Asset::query()->find($assetId);

        return $asset === null ? null : app(RoleBuilder::class)->refusal(model: $asset, reason: 'own-only-details');
    }

    private function abortUnlessOwn(int $assetId): void
    {
        if ($message = $this->refusedFor(assetId: $assetId)) {
            abort(403, $message);
        }
    }

    /** Moves one open suggestion to its decided status; 422 when it was no longer open. */
    private function claim(int $assetId, string $term, string $status, ?int $actorId): void
    {
        $changed = DB::table('dam_ai_tags')
            ->where('asset_id', $assetId)->where('term', $term)->where('status', 'suggested')
            ->update(['status' => $status, 'decided_by' => $actorId, 'decided_at' => now(), 'updated_at' => now()]);

        abort_unless($changed === 1, 422);
    }
}
