<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Similarity;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Grammar;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Duplicates\StackSuggestions;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Support\DuplicateLog;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * P5c (D16): the near-duplicate pairs one viewer may be shown, for the Similar images page, the asset page's hint, the
 * upload receipt and REST.
 *
 * Nondisclosure: a pair is shown only when the viewer may list BOTH assets now (the access seam's listing rule,
 * applied to each side inside the one query), so a pair with a hidden, trashed, merged or unavailable side is never
 * listed, counted or hinted; the answers and the queries run are the same as when there is no such pair.
 *
 * Only still images are paired here: a video, PDF or 360 video is hashed from its poster, which is no reason to offer
 * to merge it with a picture.
 *
 * Review groups (groupPage, candidates): sets of two to GROUP_MAX_MEMBERS images that are ALL within the threshold of each
 * other, each member listable by the viewer, so three similar files are one card, not three pairs. A chain (A-B and B-C but
 * not A-C) is never one group: its pairs stay reachable as overlapping smaller groups. A group action names the exact
 * members that were reviewed (groupKey) and is refused with HTTP 409 if that set no longer holds; members are never
 * added. page() keeps one row per pair for the upload receipt hints, the asset page count and REST. Exact duplicates are
 * unchanged: byte-identical files are never paired (PerceptualHashes), so one of them has no edge to its twin and a
 * cluster containing both is shown as overlapping groups rather than one.
 *
 * A pair whose two assets already share one stack basis and key is settled and left out. "Not duplicates" stores a
 * dismissal in dam_stack_suggestion_dismissals (kind near_duplicate, group key "low:high"), the same per-viewer
 * dismissal the Library's Suggested stacks panel uses, so either surface hides the pair from both.
 */
class SimilarImages
{
    public const KIND = StackSuggestions::KIND_NEAR;

    public const PER_PAGE = 24;

    /** Up to this distance a pair reads "near-identical"; above, up to the threshold, "very similar". */
    public const NEAR_IDENTICAL_MAX = 2;

    /** Matches a receipt hint names; its count covers them all. */
    public const HINT_MATCHES = 3;

    /** Review groups per page; a group shows up to GROUP_MAX_MEMBERS thumbnails. */
    public const GROUPS_PER_PAGE = 12;

    /** As StackSuggestions::MAX_MEMBERS and MergeGate::MAX_SOURCES: the most one group action touches. */
    public const GROUP_MAX_MEMBERS = 50;

    public const GROUP_KEY_PREFIX = 'grp:';

    /** The most rows one listing page returns, and so the most groups one "Not duplicates" request may name. */
    public const MAX_PER_PAGE = 100;

    /** Rows per edge query, and assets per frontier query (two IN lists of this size, well under SQL Server's 2,100 bound parameters). */
    public const EDGE_CHUNK = 1000;

    public const FRONTIER_CHUNK = 200;

    public function __construct(
        private AssetAccessDecision $access,
        private PerceptualHashes $hashes,
    ) {}

    /**
     * One page of the viewer's pairs, closest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, current_page: int, last_page: int, per_page: int, threshold: int}}
     */
    public function page(Admin $viewer, AccessContext $context, ?int $assetId = null, ?int $folderId = null, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $query = $this->pairs(viewer: $viewer, context: $context, assetId: $assetId, folderId: $folderId);
        $total = (clone $query)->count();
        $perPage = max(1, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $rows = $query
            ->select([
                'p.pair_key', 'p.distance',
                'a.id as a_id', 'a.file_name as a_name', 'a.path as a_path', 'a.stack_basis as a_basis', 'a.stack_key as a_key',
                'b.id as b_id', 'b.file_name as b_name', 'b.path as b_path', 'b.stack_basis as b_basis', 'b.stack_key as b_key',
            ])
            ->orderBy('p.distance')->orderBy('p.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get();

        return [
            'data' => $rows->map(fn (object $row) => $this->describe($row))->values()->all(),
            'meta' => ['total' => $total, 'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'threshold' => PerceptualHashes::threshold()],
        ];
    }

    /** How many pairs with `$assetId` the viewer may see (the asset page's "N similar"); 0 when it is hidden. */
    public function countFor(Admin $viewer, AccessContext $context, int $assetId): int
    {
        return $this->pairs(viewer: $viewer, context: $context, assetId: $assetId)->count();
    }

    /**
     * The viewer's reviewable groups as candidates for StackSuggestions, the closest `$limit`, members by id. A group of
     * two keeps the legacy "low:high" pair key and kind (its actions act on those two only); three or more are a
     * `near_group` keyed by their member set (groupKey), which every action revalidates.
     *
     * @return array<int, array{kind: string, key: string, ids: array<int, int>}>
     */
    public function candidates(Admin $viewer, AccessContext $context, int $limit): array
    {
        $universe = $this->edgeUniverse(viewer: $viewer, context: $context, assetId: null, folderId: null);

        return array_map(
            fn (array $group) => count($group['ids']) === 2
                ? ['kind' => self::KIND, 'key' => $group['ids'][0].':'.$group['ids'][1], 'ids' => $group['ids']]
                : ['kind' => StackSuggestions::KIND_NEAR_GROUP, 'key' => self::groupKey($group['ids']), 'ids' => $group['ids']],
            array_slice(self::unsettled(self::buildGroups($universe['edges']), $universe['stacks']), 0, max(0, $limit)),
        );
    }

    /**
     * Whether `$pairKey` is a pair the viewer may see now (both sides listable, within the threshold).
     *
     * @return array{0: int, 1: int}|null the two asset ids
     */
    public function visiblePair(Admin $viewer, AccessContext $context, string $pairKey): ?array
    {
        $row = $this->pairs(viewer: $viewer, context: $context, applyDismissals: false)
            ->where('p.pair_key', $pairKey)->first(['p.asset_id', 'p.other_asset_id']);

        return $row === null ? null : [(int) $row->asset_id, (int) $row->other_asset_id];
    }

    /**
     * "Not duplicates" for each pair the viewer may see; any other key is left alone, so a hidden pair answers like a
     * missing one.
     *
     * @param  list<string>  $pairKeys
     * @return int pairs dismissed
     */
    public function dismiss(Admin $viewer, AccessContext $context, array $pairKeys): int
    {
        $keys = array_values(array_unique(array_filter($pairKeys, fn ($key) => is_string($key) && preg_match('/^\d{1,20}:\d{1,20}$/', $key) === 1)));

        if ($keys === []) {
            return 0;
        }

        $visible = [];

        foreach (array_chunk($keys, 200) as $chunk) {
            array_push($visible, ...$this->pairs(viewer: $viewer, context: $context, applyDismissals: false)
                ->whereIn('p.pair_key', $chunk)->pluck('p.pair_key')->map(fn ($key) => (string) $key)->all());
        }

        foreach ($visible as $key) {
            DB::table('dam_stack_suggestion_dismissals')->updateOrInsert(
                ['admin_id' => (int) $viewer->id, 'kind' => self::KIND, 'group_key' => $key],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        app(StackSuggestions::class)->forget($viewer);

        DuplicateLog::write(event: 'similar.dismissed', ctx: ['admin_id' => (int) $viewer->id, 'requested' => count($keys), 'dismissed' => count($visible)]);

        return count($visible);
    }

    /**
     * Near-duplicate hints for an upload's receipts, beside P3b-2's exact notices: for each new entry or new version
     * (up to `dam.similar.receipt_inline_max` per call, hashed now from their preview), the closest pairs the uploader
     * may see. A pair with a hidden side is neither named nor counted; no hint answers both "no match" and "a match
     * you cannot see".
     *
     * @param  array<int, UploadReceipt>  $receipts
     * @return list<array<string, mixed>>
     */
    public function hints(array $receipts, Admin $viewer, AccessContext $context): array
    {
        if (! config('dam.similar.enabled', true)) {
            return [];
        }

        $receipts = array_slice(array_values(array_filter(
            $receipts,
            fn (UploadReceipt $receipt) => in_array($receipt->outcome, [UploadReceipt::CREATED, UploadReceipt::VERSIONED], true),
        )), 0, max(0, (int) config('dam.similar.receipt_inline_max', 20)));

        $hints = [];

        foreach ($receipts as $receipt) {
            $asset = Asset::query()->where('id', $receipt->assetId)->first();

            if ($asset === null) {
                continue;
            }

            try {
                $this->hashes->ensure($asset);
            } catch (Throwable $error) {
                DuplicateLog::write(event: 'phash.failed', ctx: ['asset_id' => (int) $asset->id, 'error' => mb_substr(class_basename($error), 0, 32)], level: 'warning');

                continue;
            }

            $pairs = $this->page(viewer: $viewer, context: $context, assetId: $receipt->assetId, perPage: self::HINT_MATCHES);

            if ($pairs['meta']['total'] === 0) {
                continue;
            }

            $matches = array_map(function (array $pair) use ($receipt): array {
                $other = $pair['assets'][0]['id'] === $receipt->assetId ? $pair['assets'][1] : $pair['assets'][0];

                return ['id' => $other['id'], 'name' => $other['name'], 'similarity' => $pair['similarity'], 'distance' => $pair['distance'], 'pair_key' => $pair['pair_key']];
            }, $pairs['data']);

            $hints[] = [
                'asset_id' => $receipt->assetId,
                'kind'     => self::KIND,
                'count'    => $pairs['meta']['total'],
                'matches'  => $matches,
                'message'  => trans_choice('dam::app.admin.dam.similar.receipt', $pairs['meta']['total'], ['name' => $matches[0]['name'], 'count' => $pairs['meta']['total'] - 1]),
            ];
        }

        DuplicateLog::write(event: 'upload.similar', ctx: ['uploads' => count($receipts), 'hints' => count($hints), 'via' => $context->via]);

        return $hints;
    }

    /**
     * A .dng counts as a still image unless its bytes were positively identified as a PDF, video or audio file
     * (the same rule as Asset::getFileTypeAttribute): a PDF or video named raw.dng is not a picture.
     */
    private static function dngStillImageSql(Grammar $grammar, string $alias): string
    {
        $extension = $grammar->wrap($alias.'.extension');
        $mime = 'LOWER('.$grammar->wrap($alias.'.mime_type').')';

        return "LOWER({$extension}) = 'dng' AND NOT (COALESCE({$mime}, '') = 'application/pdf' OR COALESCE({$mime}, '') LIKE 'video/%' OR COALESCE({$mime}, '') LIKE 'audio/%')";
    }

    /**
     * The viewer's pairs: within the threshold, both sides listable, not already one stack, not dismissed by them.
     *
     * Group building needs the pairs inside one stack too (`includeStacked`, so a half-stacked cluster stays one group),
     * the pairs touching a set of assets (`touching`, either side) and the pairs among a set (`within`, both sides).
     *
     * @param  list<int>|null  $touching
     * @param  list<int>|null  $within
     */
    public function pairs(Admin $viewer, AccessContext $context, ?int $assetId = null, ?int $folderId = null, bool $applyDismissals = true, bool $includeStacked = false, ?array $touching = null, ?array $within = null): Builder
    {
        $grammar = DB::connection()->getQueryGrammar();
        $query = DB::table('dam_asset_similar_pairs as p')
            ->join('dam_assets as a', 'a.id', '=', 'p.asset_id')
            ->join('dam_assets as b', 'b.id', '=', 'p.other_asset_id')
            ->where('p.distance', '<=', PerceptualHashes::threshold())
            // Videos and PDFs are hashed from their poster, but this is the images page: a poster is not a picture to merge.
            ->where(fn ($images) => $images->where('a.file_type', 'image')->orWhereRaw(self::dngStillImageSql($grammar, 'a')))
            ->where(fn ($images) => $images->where('b.file_type', 'image')->orWhereRaw(self::dngStillImageSql($grammar, 'b')));

        if (! $includeStacked) {
            // Already one stack: the pair is settled.
            $query->whereRaw('NOT ('.$grammar->wrap('a.stack_basis').' IS NOT NULL AND '.$grammar->wrap('a.stack_basis').' = '.$grammar->wrap('b.stack_basis')
                .' AND '.$grammar->wrap('a.stack_key').' = '.$grammar->wrap('b.stack_key').')');
        }

        if ($touching !== null) {
            $query->where(fn ($either) => $either->whereIn('p.asset_id', $touching)->orWhereIn('p.other_asset_id', $touching));
        }

        if ($within !== null) {
            $query->whereIn('p.asset_id', $within)->whereIn('p.other_asset_id', $within);
        }

        if ($applyDismissals) {
            $query->whereNotExists(fn ($dismissed) => $dismissed->select(DB::raw(1))->from('dam_stack_suggestion_dismissals as d')
                ->where('d.admin_id', (int) $viewer->id)->where('d.kind', self::KIND)->whereColumn('d.group_key', 'p.pair_key'));
        }

        if ($assetId !== null) {
            $query->where(fn ($either) => $either->where('p.asset_id', $assetId)->orWhere('p.other_asset_id', $assetId));
        }

        if ($folderId !== null) {
            $inFolder = fn (string $column) => fn ($ids) => $ids->select('dam_asset_directory.asset_id')->from('dam_asset_directory')->where('dam_asset_directory.directory_id', $folderId)->whereColumn('dam_asset_directory.asset_id', $column);
            $query->where(fn ($either) => $either->whereExists($inFolder('p.asset_id'))->orWhereExists($inFolder('p.other_asset_id')));
        }

        $this->access->scopeListable(query: $query, context: $context, column: 'p.asset_id');
        $this->access->scopeListable(query: $query, context: $context, column: 'p.other_asset_id');

        return $query;
    }

    /**
     * One page of the viewer's review groups, closest first: sets of 2 to GROUP_MAX_MEMBERS images that are all within the
     * threshold of each other, every one of whom the viewer may list now.
     *
     * A cluster whose pairs are not all within the threshold (A-B and B-C but not A-C) is never merged into one group:
     * its pairs stay reachable as overlapping smaller groups, so no listed pair is dropped (see buildGroups).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, current_page: int, last_page: int, per_page: int, threshold: int, truncated: bool, edge_ceiling: int}}
     */
    public function groupPage(Admin $viewer, AccessContext $context, ?int $assetId = null, ?int $folderId = null, int $page = 1, int $perPage = self::GROUPS_PER_PAGE): array
    {
        $universe = $this->edgeUniverse(viewer: $viewer, context: $context, assetId: $assetId, folderId: $folderId);
        $groups = self::unsettled(self::buildGroups($universe['edges']), $universe['stacks']);

        if ($assetId !== null) {
            $groups = array_values(array_filter($groups, fn (array $group) => in_array($assetId, $group['ids'], true)));
        }

        if ($folderId !== null) {
            $inFolder = array_flip($this->idsInFolder(folderId: $folderId, ids: array_values(array_unique(array_merge(...array_column($groups, 'ids') ?: [[]])))));
            $groups = array_values(array_filter($groups, fn (array $group) => array_intersect_key(array_flip($group['ids']), $inFolder) !== []));
        }

        $total = count($groups);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $slice = array_slice($groups, ($page - 1) * $perPage, $perPage);

        $assets = $this->assetRows(array_values(array_unique(array_merge(...array_column($slice, 'ids') ?: [[]]))));

        return [
            'data' => array_map(fn (array $group) => $this->describeGroup(group: $group, assets: $assets), $slice),
            'meta' => [
                'total'        => $total,
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'threshold'    => PerceptualHashes::threshold(),
                'truncated'    => $universe['truncated'],
                'edge_ceiling' => self::edgeCeiling(),
            ],
        ];
    }

    /** The stable key of a member set: what a group action names, recomputed from the members on every request. */
    public static function groupKey(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return self::GROUP_KEY_PREFIX.sha1(implode(',', $ids));
    }

    /**
     * The edges (pair keys) of the reviewed set `$ids`, or null when the set is no longer what was reviewed: a member the
     * viewer cannot list now, a pair outside the threshold or gone (a re-hash), a pair dismissed (when `$applyDismissals`),
     * or a size outside 2..GROUP_MAX_MEMBERS. Nothing is ever added to the set.
     *
     * @param  list<int>  $ids
     * @return list<string>|null
     */
    public function reviewedEdges(Admin $viewer, AccessContext $context, array $ids, bool $applyDismissals): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if (count($ids) < 2 || count($ids) > self::GROUP_MAX_MEMBERS || $ids[0] < 1) {
            return null;
        }

        $keys = $this->pairs(viewer: $viewer, context: $context, applyDismissals: $applyDismissals, includeStacked: true, within: $ids)
            ->pluck('p.pair_key')->map(fn ($key) => (string) $key)->all();

        return count($keys) === count($ids) * (count($ids) - 1) / 2 ? $keys : null;
    }

    /**
     * The refusal for a group that is no longer the one the viewer reviewed: HTTP 409 with the reason in the body, because the
     * exception handler gives a plain abort(409) only generic copy and the page and dialog show why the action did nothing.
     */
    public static function changed(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.dam.similar.group-changed'),
            'reason'  => 'group-changed',
        ], 409));
    }

    /**
     * The members of a group the viewer reviewed, or HTTP 409 when the set changed since: its key is not the one of the
     * members sent, or `reviewedEdges` refuses it. A stale tab never acts on a different set than it showed.
     *
     * @param  list<int>  $members
     * @return array{ids: list<int>, edges: list<string>}
     */
    public function reviewedGroup(Admin $viewer, AccessContext $context, array $members, string $groupKey, bool $applyDismissals): array
    {
        $ids = array_values(array_unique(array_map('intval', $members)));
        sort($ids);
        $edges = hash_equals(self::groupKey($ids), $groupKey) ? $this->reviewedEdges(viewer: $viewer, context: $context, ids: $ids, applyDismissals: $applyDismissals) : null;

        if ($edges === null) {
            self::changed();
        }

        return ['ids' => $ids, 'edges' => $edges];
    }

    /**
     * The members' asset rows locked in id order (ADR 0009, asset rows first), trashed ones included so the caller can refuse
     * them. The same lock PerceptualHashes takes to rewrite an asset's pairs and Stack/Merge take to change it, so while a
     * caller holds it no member's pairs, trash state or stack changes under the revalidation it does next.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Asset> by id
     */
    public function lockMembers(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Asset::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockedForGraph()->get()->keyBy(fn (Asset $asset) => (int) $asset->id);
    }

    /**
     * "Not duplicates" for each reviewed group: exactly the group's own pairs are dismissed for this viewer (the same
     * rows the pair page and the Suggested stacks panel use), never a pair outside it. A group that changed is left alone.
     *
     * @param  list<array{key: string, members: list<int>}>  $groups
     * @return array{dismissed: int, stale: int}
     */
    public function dismissGroups(Admin $viewer, AccessContext $context, array $groups): array
    {
        $dismissed = 0;
        $stale = 0;

        foreach ($groups as $group) {
            $ids = array_values(array_unique(array_map('intval', $group['members'])));
            sort($ids);

            // Under the members' row locks, so no member's pairs are rewritten between the check and the dismissal.
            $done = hash_equals(self::groupKey($ids), (string) $group['key']) && DB::transaction(function () use ($viewer, $context, $ids): bool {
                $this->lockMembers($ids);
                $edges = $this->reviewedEdges(viewer: $viewer, context: $context, ids: $ids, applyDismissals: false);

                if ($edges === null) {
                    return false;
                }

                foreach (array_chunk($edges, 200) as $chunk) {
                    DB::table('dam_stack_suggestion_dismissals')->upsert(
                        array_map(fn (string $key) => ['admin_id' => (int) $viewer->id, 'kind' => self::KIND, 'group_key' => $key, 'created_at' => now(), 'updated_at' => now()], $chunk),
                        ['admin_id', 'kind', 'group_key'],
                        ['updated_at'],
                    );
                }

                return true;
            });

            if (! $done) {
                $stale++;

                continue;
            }

            $dismissed++;
        }

        app(StackSuggestions::class)->forget($viewer);

        DuplicateLog::write(event: 'similar.groups_dismissed', ctx: ['admin_id' => (int) $viewer->id, 'requested' => count($groups), 'dismissed' => $dismissed, 'stale' => $stale]);

        return ['dismissed' => $dismissed, 'stale' => $stale];
    }

    /** Review groups cover edges with cliques: see groupPage. The ceiling only bounds memory and is reported when hit. */
    public static function edgeCeiling(): int
    {
        return max(1, (int) config('dam.similar.group_edge_ceiling', 50000));
    }

    /**
     * Deterministic clique cover of `$edges`: every edge ends up inside at least one group, and every group is a set of
     * 2..GROUP_MAX_MEMBERS assets whose every pair is an edge.
     *
     * Edges are taken closest first (then by row id). The first one not yet inside a group seeds a new group, which grows
     * with the common neighbour of all members that is closest to them (largest distance to any member, then lowest id)
     * until none is left or the group is full. A non-transitive chain (A-B, B-C, no A-C) therefore gives {A,B} and {B,C};
     * a full clique gives one group; a clique above the cap gives overlapping groups. Two assets are never grouped
     * because of a path through a third.
     *
     * @param  list<array{id: int, key: string, a: int, b: int, distance: int}>  $edges
     * @return list<array{ids: list<int>, distance: int}> closest first
     */
    public static function buildGroups(array $edges): array
    {
        usort($edges, fn (array $left, array $right) => [$left['distance'], $left['id']] <=> [$right['distance'], $right['id']]);

        $adjacent = [];

        foreach ($edges as $edge) {
            $adjacent[$edge['a']][$edge['b']] = $edge['distance'];
            $adjacent[$edge['b']][$edge['a']] = $edge['distance'];
        }

        $covered = [];
        $groups = [];

        foreach ($edges as $edge) {
            $low = min($edge['a'], $edge['b']);
            $high = max($edge['a'], $edge['b']);

            if (isset($covered[$low.':'.$high])) {
                continue;
            }

            $members = [$low, $high];
            $candidates = array_diff_key(array_intersect_key($adjacent[$low], $adjacent[$high]), [$low => 1, $high => 1]);

            // Each common neighbour carries its greatest distance to the clique so far.
            // Adding one member only requires checking the edge to that new member.
            foreach ($candidates as $candidate => $distance) {
                $candidates[$candidate] = max($distance, $adjacent[$high][$candidate]);
            }

            while ($candidates !== [] && count($members) < self::GROUP_MAX_MEMBERS) {
                $best = null;
                $bestDistance = 0;

                foreach ($candidates as $candidate => $farthest) {
                    if ($best === null || $farthest < $bestDistance || ($farthest === $bestDistance && $candidate < $best)) {
                        $best = $candidate;
                        $bestDistance = $farthest;
                    }
                }

                $members[] = $best;
                unset($candidates[$best]);
                $candidates = array_intersect_key($candidates, $adjacent[$best]);
                foreach ($candidates as $candidate => $distance) {
                    $candidates[$candidate] = max($distance, $adjacent[$best][$candidate]);
                }
            }

            sort($members);
            $widest = 0;

            foreach ($members as $index => $first) {
                foreach (array_slice($members, $index + 1) as $second) {
                    $covered[$first.':'.$second] = true;
                    $widest = max($widest, $adjacent[$first][$second]);
                }
            }

            $groups[] = ['ids' => $members, 'distance' => $widest];
        }

        usort($groups, fn (array $left, array $right) => [$left['distance'], -count($left['ids']), $left['ids']] <=> [$right['distance'], -count($right['ids']), $right['ids']]);

        return $groups;
    }

    /**
     * Every edge the viewer may review, read in keyset chunks of EDGE_CHUNK. With an asset or folder filter the edges
     * touching it seed the read, and the connected cluster around them is then read in frontier chunks, so a group the
     * filter finds is built from the same edges as in the unfiltered view. Stops at edgeCeiling() and says so.
     *
     * @return array{edges: list<array{id: int, key: string, a: int, b: int, distance: int}>, stacks: array<int, array{0: ?string, 1: ?string}>, truncated: bool}
     */
    private function edgeUniverse(Admin $viewer, AccessContext $context, ?int $assetId, ?int $folderId): array
    {
        $ceiling = self::edgeCeiling();
        $edges = [];
        $stacks = [];
        $truncated = false;

        $read = function (?int $assetId, ?int $folderId, ?array $touching) use ($viewer, $context, $ceiling, &$edges, &$stacks, &$truncated): void {
            $last = 0;

            while (! $truncated) {
                $rows = $this->pairs(viewer: $viewer, context: $context, assetId: $assetId, folderId: $folderId, includeStacked: true, touching: $touching)
                    ->where('p.id', '>', $last)->orderBy('p.id')->limit(self::EDGE_CHUNK)
                    ->get(['p.id', 'p.pair_key', 'p.asset_id', 'p.other_asset_id', 'p.distance', 'a.stack_basis as a_basis', 'a.stack_key as a_key', 'b.stack_basis as b_basis', 'b.stack_key as b_key']);

                foreach ($rows as $row) {
                    $last = (int) $row->id;

                    if (isset($edges[$last])) {
                        continue;
                    }

                    if (count($edges) >= $ceiling) {
                        $truncated = true;

                        return;
                    }

                    $edges[$last] = ['id' => $last, 'key' => (string) $row->pair_key, 'a' => (int) $row->asset_id, 'b' => (int) $row->other_asset_id, 'distance' => (int) $row->distance];
                    $stacks[(int) $row->asset_id] = [$row->a_basis, $row->a_key];
                    $stacks[(int) $row->other_asset_id] = [$row->b_basis, $row->b_key];
                }

                if ($rows->count() < self::EDGE_CHUNK) {
                    return;
                }
            }
        };

        $read($assetId, $folderId, null);

        if ($assetId !== null || $folderId !== null) {
            $known = [];
            $frontier = [];
            $remember = function () use (&$edges, &$known, &$frontier): void {
                foreach ($edges as $edge) {
                    foreach ([$edge['a'], $edge['b']] as $id) {
                        if (! isset($known[$id])) {
                            $known[$id] = true;
                            $frontier[] = $id;
                        }
                    }
                }
            };

            $remember();

            while ($frontier !== [] && ! $truncated) {
                $batch = $frontier;
                $frontier = [];

                foreach (array_chunk($batch, self::FRONTIER_CHUNK) as $chunk) {
                    $read(null, null, $chunk);
                }

                $remember();
            }
        }

        return ['edges' => array_values($edges), 'stacks' => $stacks, 'truncated' => $truncated];
    }

    /**
     * Groups whose members do not all already share one stack basis and key. A half-stacked group stays; only a settled one goes.
     *
     * @param  list<array{ids: list<int>, distance: int}>  $groups
     * @param  array<int, array{0: ?string, 1: ?string}>  $stacks
     * @return list<array{ids: list<int>, distance: int}>
     */
    private static function unsettled(array $groups, array $stacks): array
    {
        return array_values(array_filter($groups, function (array $group) use ($stacks): bool {
            $first = $stacks[$group['ids'][0]] ?? [null, null];

            if ($first[0] === null || $first[1] === null) {
                return true;
            }

            foreach ($group['ids'] as $id) {
                if (($stacks[$id] ?? [null, null]) !== $first) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> those of `$ids` filed in the folder
     */
    private function idsInFolder(int $folderId, array $ids): array
    {
        $found = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($found, ...DB::table('dam_asset_directory')->where('directory_id', $folderId)->whereIn('asset_id', $chunk)->pluck('asset_id')->map(fn ($id) => (int) $id)->all());
        }

        return $found;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object> by id
     */
    private function assetRows(array $ids): array
    {
        $rows = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('dam_assets')->whereIn('id', $chunk)->get(['id', 'file_name', 'path', 'stack_basis', 'stack_key']) as $row) {
                $rows[(int) $row->id] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array{ids: list<int>, distance: int}  $group
     * @param  array<int, object>  $assets
     * @return array<string, mixed>
     */
    private function describeGroup(array $group, array $assets): array
    {
        $similarity = $group['distance'] <= self::NEAR_IDENTICAL_MAX ? 'near_identical' : 'very_similar';

        return [
            'group_key'        => self::groupKey($group['ids']),
            'members'          => $group['ids'],
            'count'            => count($group['ids']),
            'distance'         => $group['distance'],
            'similarity'       => $similarity,
            'similarity_label' => trans('dam::app.admin.dam.similar.similarity.'.$similarity),
            'assets'           => array_map(fn (int $id) => [
                'id'            => $id,
                'name'          => (string) $assets[$id]->file_name,
                'thumbnail_url' => route('admin.dam.file.thumbnail', ['path' => urlencode((string) $assets[$id]->path)]),
                'url'           => route('admin.dam.assets.edit', ['id' => $id]),
                'stack'         => ['basis' => $assets[$id]->stack_basis, 'key' => $assets[$id]->stack_key],
            ], $group['ids']),
        ];
    }

    /** @return array<string, mixed> */
    private function describe(object $row): array
    {
        $side = fn (string $prefix) => [
            'id'            => (int) $row->{$prefix.'_id'},
            'name'          => (string) $row->{$prefix.'_name'},
            'thumbnail_url' => route('admin.dam.file.thumbnail', ['path' => urlencode((string) $row->{$prefix.'_path'})]),
            'url'           => route('admin.dam.assets.edit', ['id' => (int) $row->{$prefix.'_id'}]),
            'stack'         => ['basis' => $row->{$prefix.'_basis'}, 'key' => $row->{$prefix.'_key'}],
        ];
        $distance = (int) $row->distance;
        $similarity = $distance <= self::NEAR_IDENTICAL_MAX ? 'near_identical' : 'very_similar';

        return [
            'pair_key'         => (string) $row->pair_key,
            'distance'         => $distance,
            'similarity'       => $similarity,
            'similarity_label' => trans('dam::app.admin.dam.similar.similarity.'.$similarity),
            'assets'           => [$side('a'), $side('b')],
        ];
    }
}
