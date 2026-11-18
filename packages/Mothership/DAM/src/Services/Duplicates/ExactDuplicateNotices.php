<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Duplicates;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\DuplicateLog;
use Mothership\User\Models\Admin;

/**
 * P3b-2 (D16 exact): after an upload is published, says which of its files were already in the Library, as one
 * notice per file: "Already in Library: Root / Campaigns / hero.jpg", with Open, Stack instead and Keep both.
 *
 * A match is another entry whose current file (any attachment's current revision, not history or a derived
 * preview) has the same sha256, and that the uploader may list right now: the access seam's listing rule
 * (AssetAccessDecision::scopeListable), with unavailable entries left out for everyone. Trashed, unavailable
 * (draft, pending, archived, outside its window) and so merged entries are never matches. A match the uploader
 * cannot list is never disclosed: it is filtered inside the one query that looks for matches, so the answer and
 * the queries run are the same as when nothing matches.
 *
 * A batch costs at most four queries whatever its size: matches among older entries, the uploader's view of the
 * batch's own entries (only when two files in it share bytes, or one changed nothing), and the matched entries'
 * names and first folders (two, only when something visible matched).
 */
class ExactDuplicateNotices
{
    public const KIND_EXACT = 'exact';

    /** A same-name upload whose bytes were already that entry's current file: nothing changed. */
    public const KIND_UNCHANGED = 'unchanged';

    public const ACTION_OPEN = 'open';

    public const ACTION_STACK_INSTEAD = 'stack_instead';

    public const ACTION_KEEP_BOTH = 'keep_both';

    /** Receipts per pass of notices(); see there. */
    public const RECEIPT_SLICE = 400;

    public function __construct(
        private AssetAccessDecision $access,
        private DirectoryPermissionService $permissions,
    ) {}

    /** The outcome of publishing `$sha256` over `$replaced`'s current bytes (`$previousSha256`), or as a new entry. */
    public static function outcome(?Asset $replaced, ?string $previousSha256, string $sha256): string
    {
        if ($replaced === null) {
            return UploadReceipt::CREATED;
        }

        return $previousSha256 !== null && hash_equals($previousSha256, $sha256) ? UploadReceipt::UNCHANGED : UploadReceipt::VERSIONED;
    }

    /**
     * What the uploader is told when a publish did more than add their file under its own name: a same-name entry
     * they may replace got a new version, or one they may not replace kept its bytes and theirs took the next free
     * name. Null otherwise (an unchanged entry has its own duplicate notice).
     */
    public static function publishedNote(UploadReceipt $receipt, string $originalName, string $publishedName): ?string
    {
        if ($receipt->outcome === UploadReceipt::VERSIONED) {
            return trans('dam::app.admin.dam.duplicates.replaced', ['name' => $publishedName]);
        }

        if ($receipt->outcome === UploadReceipt::CREATED && $publishedName !== $originalName) {
            return trans(self::nameHeldByAnotherPerson(assetId: $receipt->assetId, originalName: $originalName)
                ? 'dam::app.admin.dam.duplicates.saved-as-not-yours'
                : 'dam::app.admin.dam.duplicates.saved-as', ['name' => $publishedName]);
        }

        return null;
    }

    /**
     * Whether the name an upload asked for is held by a live asset of someone else (or of nobody) and the uploader is a role
     * limited to its own items, the reason it gets a new file under the next free name instead of a replacement. A merge source or a
     * same-owner file keeps the plain "a file with that name is already here" wording.
     */
    private static function nameHeldByAnotherPerson(int $assetId, string $originalName): bool
    {
        $published = Asset::query()->whereKey($assetId)->first(['id', 'path', 'created_by']);

        if ($published === null || $published->created_by === null) {
            return false;
        }

        // Only the own-items rule gets this wording; any other reason keeps the plain "already here" one.
        $uploader = Admin::query()->with('role')->find($published->created_by);

        if ($uploader === null || ! app(RoleBuilder::class)->restrictedToOwn($uploader)) {
            return false;
        }

        $holder = Asset::query()
            ->where('path', substr((string) $published->path, 0, (int) strrpos((string) $published->path, '/')).'/'.$originalName)
            ->first(['id', 'created_by', 'merged_into_id']);

        return $holder !== null && $holder->merged_into_id === null && (int) $holder->created_by !== (int) $published->created_by;
    }

    /**
     * The sha256 of each entry's current representative file, for a writer about to replace it.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, string> sha256 by asset id
     */
    public function currentSha256(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return DB::table('dam_asset_files as f')
            ->join('dam_file_objects as o', 'o.id', '=', 'f.file_object_id')
            ->whereIn('f.dam_asset_id', array_values(array_unique($assetIds)))
            ->where('f.role', AssetFile::ROLE_ORIGINAL)
            ->pluck('o.sha256', 'f.dam_asset_id')
            ->mapWithKeys(fn ($sha, $id) => [(int) $id => (string) $sha])
            ->all();
    }

    /**
     * The receipts AssetUploadPublisher left on the assets it published, for notices().
     *
     * @param  iterable<Asset>  $assets
     * @return array<int, UploadReceipt>
     */
    public static function receiptsOf(iterable $assets): array
    {
        $receipts = [];

        foreach ($assets as $asset) {
            if ($asset instanceof Asset && $asset->uploadReceipt !== null) {
                $receipts[] = $asset->uploadReceipt;
            }
        }

        return $receipts;
    }

    /**
     * One notice per upload whose bytes the uploader can already see in the Library; uploads with no visible match
     * have none, so an empty list is the answer both for "no match" and for "a match you cannot see".
     *
     * @param  array<int, UploadReceipt>  $receipts
     * @param  AccessContext  $context  the uploader's surface (library or api); listing rules apply
     * @param  bool  $canStackInstead  whether the uploader holds the keys Stack instead needs
     * @return array<int, array<string, mixed>>
     */
    public function notices(array $receipts, AccessContext $context, bool $canStackInstead): array
    {
        $receipts = array_values(array_filter($receipts, fn (UploadReceipt $receipt) => in_array($receipt->outcome, UploadReceipt::OUTCOMES, true)));

        if ($receipts === []) {
            return [];
        }

        // Each query binds up to two parameters per receipt; slices keep a huge batch under SQL Server's 2,100. A file
        // in a later slice still finds an identical one from an earlier slice, as an older entry the uploader can list.
        if (count($receipts) > self::RECEIPT_SLICE) {
            $notices = [];

            foreach (array_chunk($receipts, self::RECEIPT_SLICE) as $slice) {
                array_push($notices, ...$this->notices(receipts: $slice, context: $context, canStackInstead: $canStackInstead));
            }

            return $notices;
        }

        $listing = $context->withoutUnavailable();
        $batchIds = array_values(array_unique(array_map(fn (UploadReceipt $receipt) => $receipt->assetId, $receipts)));
        $older = $this->olderMatches(receipts: $receipts, batchIds: $batchIds, context: $listing);
        $batchVisible = $this->needsBatchView($receipts) ? $this->visibleAmong(assetIds: $batchIds, context: $listing) : [];

        $matches = [];

        foreach ($receipts as $receipt) {
            $match = $this->matchFor(receipt: $receipt, older: $older, receipts: $receipts, batchVisible: $batchVisible);

            if ($match !== null) {
                $matches[$receipt->assetId] = $match;
            }
        }

        $notices = $matches === [] ? [] : $this->describe(receipts: $receipts, matches: $matches, canStackInstead: $canStackInstead);

        DuplicateLog::write(event: 'upload.duplicates', ctx: [
            'uploads' => count($receipts),
            'notices' => count($notices),
            'via'     => $context->via,
        ]);

        return $notices;
    }

    /**
     * For each uploaded sha256, the oldest entry outside the batch the uploader may list whose current file holds
     * those bytes. Filtered by the access seam inside the query, so a hidden match costs nothing extra.
     *
     * @param  array<int, UploadReceipt>  $receipts
     * @param  array<int, int>  $batchIds
     * @return array<string, int> asset id by sha256
     */
    private function olderMatches(array $receipts, array $batchIds, AccessContext $context): array
    {
        $query = DB::table('dam_asset_files as f')
            ->join('dam_file_objects as o', 'o.id', '=', 'f.file_object_id')
            ->join('dam_assets', 'dam_assets.id', '=', 'f.dam_asset_id')
            ->whereIn('o.sha256', array_values(array_unique(array_map(fn (UploadReceipt $receipt) => $receipt->sha256, $receipts))))
            ->where('f.is_current', true)
            ->whereNotIn('f.role', Asset::UNSTACKED_ROLES)
            ->whereNotIn('dam_assets.id', $batchIds)
            ->groupBy('o.sha256')
            ->selectRaw('o.sha256 AS sha256, MIN(dam_assets.id) AS asset_id');

        $this->access->scopeListable(query: $query, context: $context);

        return $query->pluck('asset_id', 'sha256')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether the batch's own entries can be each other's match, or a receipt points at its own entry. */
    private function needsBatchView(array $receipts): bool
    {
        $shas = array_map(fn (UploadReceipt $receipt) => $receipt->sha256, $receipts);

        return count($shas) !== count(array_unique($shas))
            || array_filter($receipts, fn (UploadReceipt $receipt) => $receipt->outcome === UploadReceipt::UNCHANGED) !== [];
    }

    /**
     * @param  array<int, int>  $assetIds
     * @return array<int, true> the ids among `$assetIds` the context may list
     */
    private function visibleAmong(array $assetIds, AccessContext $context): array
    {
        $query = DB::table('dam_assets')->whereIn('dam_assets.id', $assetIds)->select('dam_assets.id');
        $this->access->scopeListable(query: $query, context: $context);

        return $query->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * The entry a receipt's notice names, or null. A same-name upload that changed nothing names its own entry. A
     * new entry is matched only by an older one (a lower id), so of two identical files in one batch only the
     * second is told about the first. A new version may match any other entry.
     *
     * @param  array<string, int>  $older
     * @param  array<int, UploadReceipt>  $receipts
     * @param  array<int, true>  $batchVisible
     * @return array{id: int, kind: string}|null
     */
    private function matchFor(UploadReceipt $receipt, array $older, array $receipts, array $batchVisible): ?array
    {
        if ($receipt->outcome === UploadReceipt::UNCHANGED) {
            return isset($batchVisible[$receipt->assetId]) ? ['id' => $receipt->assetId, 'kind' => self::KIND_UNCHANGED] : null;
        }

        $candidates = [];

        if (isset($older[$receipt->sha256])) {
            $candidates[] = $older[$receipt->sha256];
        }

        foreach ($receipts as $other) {
            if ($other->assetId !== $receipt->assetId && hash_equals($other->sha256, $receipt->sha256) && isset($batchVisible[$other->assetId])) {
                $candidates[] = $other->assetId;
            }
        }

        $candidates = array_filter($candidates, fn (int $id) => $receipt->outcome !== UploadReceipt::CREATED || $id < $receipt->assetId);

        return $candidates === [] ? null : ['id' => min($candidates), 'kind' => self::KIND_EXACT];
    }

    /**
     * @param  array<int, UploadReceipt>  $receipts
     * @param  array<int, array{id: int, kind: string}>  $matches  by the upload's asset id
     * @return array<int, array<string, mixed>>
     */
    private function describe(array $receipts, array $matches, bool $canStackInstead): array
    {
        $matchIds = array_values(array_unique(array_column($matches, 'id')));
        $assets = Asset::query()->whereIn('id', $matchIds)
            ->with(['directories' => fn ($query) => $query->select('dam_directories.id')->orderBy('dam_asset_directory.id')])
            ->get(['id', 'file_name', 'path'])->keyBy('id');
        $notices = [];

        foreach ($receipts as $receipt) {
            $match = $matches[$receipt->assetId] ?? null;
            $asset = $match ? $assets->get($match['id']) : null;

            if ($asset === null) {
                continue;
            }

            $where = $this->where($asset);
            $actions = [self::ACTION_OPEN];

            // Stack instead folds a new entry into the match; a new version of an existing entry is not offered it.
            if ($match['kind'] === self::KIND_EXACT && $receipt->outcome === UploadReceipt::CREATED) {
                if ($canStackInstead) {
                    $actions[] = self::ACTION_STACK_INSTEAD;
                }

                $actions[] = self::ACTION_KEEP_BOTH;
            }

            $notices[] = [
                'asset_id' => $receipt->assetId,
                'kind'     => $match['kind'],
                'outcome'  => $receipt->outcome,
                'match'    => ['id' => (int) $asset->id, 'name' => (string) $asset->file_name, 'folder' => $where['folder'], 'location' => $where['location']],
                'message'  => trans('dam::app.admin.dam.duplicates.'.($match['kind'] === self::KIND_UNCHANGED ? 'unchanged' : 'already-in-library'), ['location' => $where['location']]),
                'actions'  => $actions,
            ];
        }

        return $notices;
    }

    /**
     * The match's folder path and name, "Root / Campaigns / hero.jpg". The folder is named only when the uploader
     * may open the folder the entry's path names (its first folder), as the open route does: a per-file grant shows
     * the file, never the folder it sits in.
     *
     * @return array{folder: ?string, location: string}
     */
    private function where(Asset $asset): array
    {
        $firstFolder = $asset->directories->first();
        $folder = null;

        if ($firstFolder !== null && $this->permissions->canAccess((int) $firstFolder->id)) {
            $prefix = Directory::ASSETS_DIRECTORY.'/';
            $dir = dirname((string) $asset->path);
            $dir = str_starts_with($dir, $prefix) ? substr($dir, strlen($prefix)) : $dir;
            $folder = implode(' / ', array_filter(explode('/', $dir), fn ($segment) => $segment !== '' && $segment !== '.'));
            $folder = $folder === '' ? null : $folder;
        }

        return ['folder' => $folder, 'location' => $folder === null ? (string) $asset->file_name : $folder.' / '.$asset->file_name];
    }
}
