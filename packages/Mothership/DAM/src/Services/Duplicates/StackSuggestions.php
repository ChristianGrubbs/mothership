<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Duplicates;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Services\Similarity\PerceptualHashes;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\DAM\Support\DuplicateLog;
use Mothership\DAM\Support\FilenameStem;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * P3b-2 (D5 suggestions, no automatic action): groups of entries the viewer can see that look like they belong in
 * one stack, because they share
 *  - `key`: a stack key (case and outer spaces aside) but not one basis and spelling, e.g. campaign "Fall 26" and
 *    product "fall 26 " (a campaign is the `campaign` basis until P4 brings a controlled Campaign field);
 *  - `capture`: a capture group;
 *  - `stem`: a filename stem once version, size and copy suffixes are gone (Support\FilenameStem);
 *  - `near_duplicate` (P5c, D16): two images whose perceptual hashes are within the threshold, the closest pairs the
 *    viewer may see both sides of (Services\Similarity\SimilarImages). Its key is the pair, "low:high", which is also
 *    what the Similar images page's "Not duplicates" stores, so a dismissal on either surface hides it on both.
 * A group whose members already share one basis and key is already stacked and is not suggested.
 *
 * Bounded and cached:
 *  - Candidates are three GROUP BY queries over dam_assets (indexed stack_key, capture_group_id and name_stem), each
 *    filtered by the access seam's listing rule, keeping at most GROUPS_PER_KIND groups of 2 to MAX_MEMBERS entries
 *    (larger "groups" are generic names such as `image`, not stacks), and one query for their members.
 *  - They are cached per admin for CACHE_SECONDS. Every read re-checks the cached members in one query against the
 *    viewer's access and the members' current names, keys and capture groups, and applies the viewer's dismissals
 *    in one more, so a cached group never counts or names an entry the viewer cannot see now, and a group stacked
 *    or dismissed since disappears at once.
 *
 * "Stack them" gives every member the viewer may change the same basis and key through AssetStackService, the P3a
 * stack flow. It merges nothing: merging entries into one is P3b-1.
 */
class StackSuggestions
{
    public const KIND_KEY = 'key';

    public const KIND_CAPTURE = 'capture';

    public const KIND_STEM = 'stem';

    public const KIND_NEAR = 'near_duplicate';

    /**
     * Three or more near-duplicate images that are all within the threshold of each other (SimilarImages::buildGroups). Unlike
     * the other kinds its key is not looked up: it names the exact member set the viewer reviewed, and apply and dismiss
     * revalidate that set and refuse a changed one (HTTP 409) instead of acting on whoever is in the group now.
     */
    public const KIND_NEAR_GROUP = 'near_group';

    /** Also the order groups are shown in, and which kind keeps a group two kinds find with the same members. */
    public const KINDS = [self::KIND_KEY, self::KIND_CAPTURE, self::KIND_STEM, self::KIND_NEAR, self::KIND_NEAR_GROUP];

    public const GROUPS_PER_KIND = 20;

    /** As SimilarImages::GROUP_MAX_MEMBERS. */
    private const SIMILAR_GROUP_MAX = SimilarImages::GROUP_MAX_MEMBERS;

    public const MAX_MEMBERS = 50;

    /** How many members a suggestion names; its count covers them all. */
    public const NAMED_MEMBERS = 8;

    public const CACHE_SECONDS = 600;

    /** Fired after a group action's first checks and before its row locks, so a test can change the data there (as StackInstead::EVENT_LOCKING). */
    public const EVENT_LOCKING = 'dam.stack-suggestions.locking';

    /** Ids per IN list when re-checking members, well under SQL Server's 2,100 parameters with the access filter's. */
    public const ID_CHUNK = 500;

    public function __construct(
        private AssetAccessDecision $access,
        private AssetStackService $stacks,
    ) {}

    /**
     * The viewer's suggestions: key groups, then capture groups, then stems, largest first within each.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for(Admin $viewer, AccessContext $context): array
    {
        $cached = true;
        $candidates = Cache::remember(self::cacheKey(viewer: $viewer, context: $context), self::CACHE_SECONDS, function () use ($viewer, $context, &$cached): array {
            $cached = false;

            return $this->candidates(viewer: $viewer, context: $context);
        });

        $groups = $this->present(candidates: $candidates, viewer: $viewer, context: $context);

        DuplicateLog::write(event: 'suggestions.read', ctx: [
            'admin_id'   => (int) $viewer->id,
            'cached'     => $cached,
            'candidates' => count($candidates),
            'shown'      => count($groups),
        ]);

        return $groups;
    }

    public static function cacheKey(Admin $viewer, AccessContext $context): string
    {
        return 'dam:stack-suggestions:v3:'.$context->via.':'.$viewer->id;
    }

    public function forget(Admin $viewer): void
    {
        foreach ([AccessContext::library(), AccessContext::api()] as $context) {
            Cache::forget(self::cacheKey(viewer: $viewer, context: $context));
        }
    }

    /** Hides one suggestion from this viewer only, for good. */
    public function dismiss(Admin $viewer, string $kind, string $groupKey): void
    {
        DB::table('dam_stack_suggestion_dismissals')->updateOrInsert(
            ['admin_id' => (int) $viewer->id, 'kind' => $kind, 'group_key' => $groupKey],
            ['created_at' => now(), 'updated_at' => now()],
        );

        DuplicateLog::write(event: 'suggestions.dismissed', ctx: ['admin_id' => (int) $viewer->id, 'kind' => $kind]);
    }

    /**
     * "Stack them": the group's members as the viewer sees them now get `$basis` and `$key`. A member the viewer may
     * see but not change is left as it is and counted as skipped.
     *
     * @return array{stacked: int, skipped: int, stack: array{basis: ?string, key: ?string}}
     */
    public function apply(Admin $viewer, AccessContext $context, string $kind, string $groupKey, string $basis, string $key): array
    {
        // A group of three or more is stacked only through applyGroup, which names its members.
        abort_if($kind === self::KIND_NEAR_GROUP, 422, trans('dam::app.admin.dam.suggestions.gone'));

        $query = DB::table('dam_assets')->select('dam_assets.id')->orderBy('dam_assets.id')->limit(self::MAX_MEMBERS + 1);
        $this->whereInGroup(query: $query, kind: $kind, groupKeys: [$groupKey]);
        $this->access->scopeListable(query: $query, context: $context);
        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        abort_unless(count($ids) >= 2 && count($ids) <= self::MAX_MEMBERS, 422, trans('dam::app.admin.dam.suggestions.gone'));

        return $this->stackIds(viewer: $viewer, context: $context, kind: $kind, ids: $ids, basis: $basis, key: $key);
    }

    /**
     * "Stack them" for a near-duplicate group of three or more: exactly the `$members` the viewer reviewed, and only if that
     * set is still what they saw (SimilarImages::reviewedGroup: every member listable now, every pair still within the
     * threshold and not dismissed). A changed set is refused with HTTP 409; members are never added.
     *
     * @param  list<int>  $members
     * @return array{stacked: int, skipped: int, stack: array{basis: ?string, key: ?string}}
     */
    public function applyGroup(Admin $viewer, AccessContext $context, array $members, string $groupKey, string $basis, string $key): array
    {
        $ids = array_values(array_unique(array_map('intval', $members)));
        sort($ids);

        // Cheap refusal first; the decision is made again under the members' row locks (stackIds), which is the one that counts.
        if (count($ids) < 2 || count($ids) > self::SIMILAR_GROUP_MAX || ! hash_equals(SimilarImages::groupKey($ids), $groupKey)) {
            SimilarImages::changed();
        }

        return $this->stackIds(viewer: $viewer, context: $context, kind: self::KIND_NEAR_GROUP, ids: $ids, basis: $basis, key: $key, reviewedKey: $groupKey);
    }

    /**
     * @param  list<int>  $ids
     * @return array{stacked: int, skipped: int, stack: array{basis: ?string, key: ?string}}
     */
    private function stackIds(Admin $viewer, AccessContext $context, string $kind, array $ids, string $basis, string $key, ?string $reviewedKey = null): array
    {
        Event::dispatch(self::EVENT_LOCKING, [$kind, $ids]);

        $editContext = $context->via === AccessContext::VIA_API ? AccessContext::api(AccessContext::PURPOSE_EDIT) : AccessContext::library(AccessContext::PURPOSE_EDIT);
        $stacked = 0;
        $skipped = 0;

        DB::transaction(function () use ($viewer, $context, $ids, $editContext, $basis, $key, $reviewedKey, &$stacked, &$skipped): void {
            if ($reviewedKey === null) {
                $locked = Asset::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            } else {
                // A reviewed group: every member locked (a trashed or purged one is a changed group), then the whole group decided
                // again on the locked rows, so nothing is stacked unless the set the viewer reviewed still holds.
                $locked = app(SimilarImages::class)->lockMembers($ids);
                if ($locked->count() !== count($ids) || $locked->contains(fn (Asset $asset) => $asset->trashed())) {
                    SimilarImages::changed();
                }
                app(SimilarImages::class)->reviewedGroup(viewer: $viewer, context: $context, members: $ids, groupKey: $reviewedKey, applyDismissals: true);
            }

            foreach ($locked as $asset) {
                if (! $this->access->for($editContext, $asset)->allowed) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->stacks->set(asset: $asset, basis: $basis, key: $key);
                } catch (HttpException $refused) {
                    // Someone else's stack details are not replaced by a role limited to its own files; counted with the skipped.
                    if ($refused->getStatusCode() !== 403) {
                        throw $refused;
                    }

                    $skipped++;

                    continue;
                }

                $stacked++;
            }
        });

        $this->forget($viewer);

        DuplicateLog::write(event: 'suggestions.stacked', ctx: [
            'admin_id' => (int) $viewer->id,
            'kind'     => $kind,
            'stacked'  => $stacked,
            'skipped'  => $skipped,
        ]);

        return ['stacked' => $stacked, 'skipped' => $skipped, 'stack' => ['basis' => $basis, 'key' => trim($key)]];
    }

    /**
     * The groups the viewer could be shown, before re-checking: kind, the value the members share, and member ids.
     *
     * @return array<int, array{kind: string, key: string, ids: array<int, int>}>
     */
    private function candidates(Admin $viewer, AccessContext $context): array
    {
        $near = config('dam.similar.enabled', true)
            ? app(SimilarImages::class)->candidates(viewer: $viewer, context: $context, limit: self::GROUPS_PER_KIND)
            : [];

        $grammar = DB::connection()->getQueryGrammar();
        $keyExpr = 'LOWER(TRIM('.$grammar->wrap('dam_assets.stack_key').'))';
        $basis = $grammar->wrap('dam_assets.stack_basis');
        $stackKey = $grammar->wrap('dam_assets.stack_key');
        // Not every member already carries one and the same basis and key.
        $notStacked = "(COUNT(DISTINCT {$basis}) <> 1 OR COUNT(DISTINCT {$stackKey}) <> 1 OR COUNT({$stackKey}) <> COUNT(*))";
        // Members disagree on basis or spelling: one key, not yet one stack.
        $disagree = "(COUNT(DISTINCT {$basis}) > 1 OR COUNT(DISTINCT {$stackKey}) > 1)";

        $keys = [
            self::KIND_KEY     => $this->groupKeys(context: $context, expression: $keyExpr, having: $disagree, notNull: 'dam_assets.stack_key'),
            self::KIND_CAPTURE => $this->groupKeys(context: $context, expression: $grammar->wrap('dam_assets.capture_group_id'), having: $notStacked, notNull: 'dam_assets.capture_group_id'),
            self::KIND_STEM    => $this->groupKeys(context: $context, expression: $grammar->wrap('dam_assets.name_stem'), having: $notStacked, notNull: 'dam_assets.name_stem'),
        ];

        if (array_merge(...array_values($keys)) === []) {
            return $near;
        }

        $members = DB::table('dam_assets')
            ->select(['dam_assets.id', 'dam_assets.capture_group_id', 'dam_assets.name_stem'])
            ->selectRaw("{$keyExpr} AS key_norm")
            ->where(function ($any) use ($keys): void {
                foreach ($keys as $kind => $groupKeys) {
                    if ($groupKeys !== []) {
                        $any->orWhere(fn ($query) => $this->whereInGroup(query: $query, kind: $kind, groupKeys: $groupKeys));
                    }
                }
            })
            ->orderBy('dam_assets.id');
        $this->access->scopeListable(query: $members, context: $context);
        $members = $members->get();

        $candidates = [];

        foreach ($keys as $kind => $groupKeys) {
            foreach ($groupKeys as $groupKey) {
                $ids = $members->filter(fn ($row) => $this->groupValue(row: $row, kind: $kind) === $groupKey)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

                if (count($ids) >= 2) {
                    $candidates[] = ['kind' => $kind, 'key' => $groupKey, 'ids' => $ids];
                }
            }
        }

        DuplicateLog::write(event: 'suggestions.built', ctx: ['candidates' => count($candidates) + count($near), 'members' => $members->count()]);

        return [...$candidates, ...$near];
    }

    /**
     * The values of the largest groups of one kind the context may list.
     *
     * @return array<int, string>
     */
    private function groupKeys(AccessContext $context, string $expression, string $having, string $notNull): array
    {
        $query = DB::table('dam_assets')
            ->whereNotNull($notNull)
            ->selectRaw("{$expression} AS group_key, COUNT(*) AS members")
            ->groupByRaw($expression)
            ->havingRaw('COUNT(*) BETWEEN 2 AND '.self::MAX_MEMBERS)
            ->havingRaw($having)
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw($expression)
            ->limit(self::GROUPS_PER_KIND);
        $this->access->scopeListable(query: $query, context: $context);

        return $query->pluck('group_key')->map(fn ($value) => (string) $value)->filter(fn ($value) => $value !== '')->values()->all();
    }

    /** @param  array<int, string>  $groupKeys */
    private function whereInGroup(Builder $query, string $kind, array $groupKeys): void
    {
        match ($kind) {
            self::KIND_KEY     => $query->whereRaw('LOWER(TRIM('.DB::connection()->getQueryGrammar()->wrap('dam_assets.stack_key').')) IN ('.implode(', ', array_fill(0, count($groupKeys), '?')).')', $groupKeys),
            self::KIND_CAPTURE => $query->whereIn('dam_assets.capture_group_id', array_map('intval', $groupKeys)),
            self::KIND_STEM    => $query->whereIn('dam_assets.name_stem', $groupKeys),
            // Both assets of a pair that is still within the threshold.
            // A group is never looked up by key: its members are named by the request (applyGroup).
            self::KIND_NEAR_GROUP => throw new LogicException('A near_group is resolved from its named members, not from its key.'),
            self::KIND_NEAR       => $query->whereIn('dam_assets.id', self::pairIds($groupKeys))
                ->whereExists(fn ($pair) => $pair->select(DB::raw(1))->from('dam_asset_similar_pairs')
                    ->whereIn('dam_asset_similar_pairs.pair_key', $groupKeys)
                    ->where('dam_asset_similar_pairs.distance', '<=', PerceptualHashes::threshold())),
        };
    }

    /**
     * The asset ids a list of "low:high" pair keys names.
     *
     * @param  array<int, string>  $pairKeys
     * @return array<int, int>
     */
    private static function pairIds(array $pairKeys): array
    {
        $ids = [];

        foreach ($pairKeys as $key) {
            if (preg_match('/^(\d{1,20}):(\d{1,20})$/', $key, $match) === 1) {
                array_push($ids, (int) $match[1], (int) $match[2]);
            }
        }

        return $ids === [] ? [0] : array_values(array_unique($ids));
    }

    private function groupValue(object $row, string $kind): ?string
    {
        $value = match ($kind) {
            self::KIND_KEY                         => $row->key_norm,
            self::KIND_CAPTURE                     => $row->capture_group_id,
            self::KIND_STEM                        => $row->name_stem,
            self::KIND_NEAR, self::KIND_NEAR_GROUP => null,
        };

        return $value === null ? null : (string) $value;
    }

    /**
     * Re-checks cached candidates against the viewer's access and the members' current values, drops dismissed and
     * already-stacked groups and groups another kind found with the same members, and describes the rest.
     *
     * @param  array<int, array{kind: string, key: string, ids: array<int, int>}>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function present(array $candidates, Admin $viewer, AccessContext $context): array
    {
        if ($candidates === []) {
            return [];
        }

        $live = $this->liveMembers(ids: array_values(array_unique(array_merge(...array_column($candidates, 'ids')))), context: $context);

        $dismissed = DB::table('dam_stack_suggestion_dismissals')->where('admin_id', (int) $viewer->id)
            ->get(['kind', 'group_key'])->map(fn ($row) => $row->kind."\0".$row->group_key)->flip();

        $groups = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if ($dismissed->has($candidate['kind']."\0".$candidate['key'])) {
                continue;
            }

            if ($candidate['kind'] === self::KIND_NEAR_GROUP) {
                $group = $this->presentGroup(candidate: $candidate, live: $live, viewer: $viewer, context: $context, dismissed: $dismissed);

                if ($group !== null && ! isset($seen[$group['signature']])) {
                    $seen[$group['signature']] = true;
                    $groups[] = $this->describe(kind: self::KIND_NEAR_GROUP, groupKey: $group['key'], members: $group['members']);
                }

                continue;
            }

            /** @var Collection<int, object> $members */
            $members = collect($candidate['ids'])->map(fn (int $id) => $live->get($id))
                // A pair's members belong to it by id; the other kinds' by the value they still share.
                ->filter(fn ($row) => $row !== null && ($candidate['kind'] === self::KIND_NEAR || $this->groupValue(row: $row, kind: $candidate['kind']) === $candidate['key']))
                ->values();

            if ($members->count() < 2 || $this->alreadyStacked($members)) {
                continue;
            }

            $signature = $members->pluck('id')->sort()->implode(',');

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $groups[] = $this->describe(kind: $candidate['kind'], groupKey: $candidate['key'], members: $members);
        }

        return $groups;
    }

    /**
     * A cached near-duplicate group as the viewer may review it now: its members still listable, still every pair within the
     * threshold and none dismissed. A member lost to access leaves a smaller group, which is still all-pairs similar (a subset
     * of a clique); a group whose pairs changed is dropped until the cache is rebuilt. Its key is recomputed from what is shown.
     *
     * @param  array{kind: string, key: string, ids: array<int, int>}  $candidate
     * @param  Collection<int, object>  $live
     * @param  Collection<string, int>  $dismissed
     * @return array{key: string, signature: string, members: Collection<int, object>}|null
     */
    private function presentGroup(array $candidate, Collection $live, Admin $viewer, AccessContext $context, Collection $dismissed): ?array
    {
        $members = collect($candidate['ids'])->map(fn (int $id) => $live->get($id))->filter()->values();

        if ($members->count() < 2 || $this->alreadyStacked($members)) {
            return null;
        }

        $ids = $members->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $edges = app(SimilarImages::class)->reviewedEdges(viewer: $viewer, context: $context, ids: $ids, applyDismissals: false);

        if ($edges === null || collect($edges)->contains(fn (string $edge) => $dismissed->has(self::KIND_NEAR."\0".$edge))) {
            return null;
        }

        return ['key' => SimilarImages::groupKey($ids), 'signature' => implode(',', $ids), 'members' => $members->sortBy('id')->values()];
    }

    /**
     * The members as the viewer may list them now, read in chunks of ID_CHUNK: up to 3 kinds x GROUPS_PER_KIND x
     * MAX_MEMBERS ids would pass SQL Server's 2,100 bound parameters in one IN list, and the access filter adds its own.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, object> by id
     */
    private function liveMembers(array $ids, AccessContext $context): Collection
    {
        $grammar = DB::connection()->getQueryGrammar();
        $live = collect();

        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $query = DB::table('dam_assets')
                ->whereIn('dam_assets.id', $chunk)
                ->select(['dam_assets.id', 'dam_assets.file_name', 'dam_assets.extension', 'dam_assets.capture_group_id', 'dam_assets.name_stem', 'dam_assets.stack_basis', 'dam_assets.stack_key'])
                ->selectRaw('LOWER(TRIM('.$grammar->wrap('dam_assets.stack_key').')) AS key_norm');
            $this->access->scopeListable(query: $query, context: $context);

            foreach ($query->get() as $row) {
                $live->put((int) $row->id, $row);
            }
        }

        return $live;
    }

    /** @param  Collection<int, object>  $members */
    private function alreadyStacked(Collection $members): bool
    {
        return $members->every(fn ($row) => $row->stack_basis !== null && $row->stack_key !== null)
            && $members->pluck('stack_basis')->unique()->count() === 1
            && $members->pluck('stack_key')->unique()->count() === 1;
    }

    /**
     * @param  Collection<int, object>  $members
     * @return array<string, mixed>
     */
    private function describe(string $kind, string $groupKey, Collection $members): array
    {
        $basis = $this->mostCommon($members->pluck('stack_basis'));
        $key = $this->mostCommon($members->pluck('stack_key')->map(fn ($value) => $value === null ? null : trim((string) $value)));
        $shared = match ($kind) {
            self::KIND_KEY                         => (string) $key,
            self::KIND_CAPTURE                     => (string) (FilenameStem::readable($members->first()->file_name) ?? $members->first()->file_name),
            self::KIND_STEM                        => (string) (FilenameStem::readable($members->first()->file_name) ?? $groupKey),
            self::KIND_NEAR, self::KIND_NEAR_GROUP => (string) (FilenameStem::readable($members->first()->file_name) ?? $members->first()->file_name),
        };

        return [
            'kind'    => $kind,
            'key'     => $groupKey,
            'title'   => trans('dam::app.admin.dam.suggestions.titles.'.$kind, ['count' => $members->count(), 'value' => $shared]),
            'count'   => $members->count(),
            'members' => $members->take(self::NAMED_MEMBERS)->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->file_name])->values()->all(),
            // Every member, for the Create stack review "Stack them" opens; `members` names only the first few.
            'entries' => $members->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->file_name, 'extension' => (string) ($row->extension ?? '')])->values()->all(),
            'prefill' => [
                'basis' => $basis ?? 'other',
                'key'   => $key ?? $shared,
            ],
        ];
    }

    /** The most frequent non-null value, the first one seen on a tie. */
    private function mostCommon(Collection $values): ?string
    {
        $counts = $values->filter(fn ($value) => $value !== null && $value !== '')->countBy();

        return $counts->isEmpty() ? null : (string) $counts->sortDesc()->keys()->first();
    }
}
