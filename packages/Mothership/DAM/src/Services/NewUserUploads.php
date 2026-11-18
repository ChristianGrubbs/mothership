<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\DamConfiguration;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;

/**
 * The admin's queue of files other users added (operator decision 2026-09-30): every upload by a user whose
 * role is not `all` is flagged `needs_sorting_at`, and an admin gathers them into the `To Sort` folder to
 * organise, or just marks them sorted.
 */
class NewUserUploads
{
    public function __construct(
        protected AccessModel $accessModel,
        protected AccessListService $lists,
    ) {}

    public const SORT_FOLDER = 'To Sort';

    /** dam_configuration row holding the id of the folder this feature made. */
    public const CONFIG_KEY = 'DAM_SORT_FOLDER_ID';

    /** Whether the user's role grants everything (`permission_type = all`), the same test the folder-access pages use. */
    public static function isAdmin(int $adminId): bool
    {
        // The session user's role is already loaded by the admin guard; only a queued owner needs a lookup.
        $user = auth('admin')->user();
        $user = $user && (int) $user->getKey() === $adminId ? $user : Admin::query()->with('role:id,permission_type')->find($adminId);

        return $user?->role?->permission_type === 'all';
    }

    /**
     * Moves the flagged files among `$assetIds` into the `To Sort` folder and clears their flag. Ids that are not
     * flagged are ignored. The move is the Library's own MassMove job run inline, so the moved files take the
     * destination's access list and keep their owners exactly as any other move does (ADR 0010, "Move").
     *
     * @param  list<int>  $assetIds
     * @return array{moved: int, ignored: int}
     */
    public function moveToSortFolder(array $assetIds, int $adminId): array
    {
        $flagged = $this->flaggedIds(assetIds: $assetIds);

        if ($flagged === []) {
            return ['moved' => 0, 'ignored' => count($assetIds)];
        }

        // The invariant is asserted and the move runs in the same fenced transaction, so nobody can share the
        // folder in between. Run inline, not queued: the page reports what actually moved.
        $folder = $this->locked(function () use ($flagged, $adminId): Directory {
            $folder = $this->ensureSortFolder(adminId: $adminId);

            (new MassMove($flagged, [], $folder->id, $adminId))->handle();

            return $folder;
        });

        $inFolder = DB::table('dam_asset_directory')
            ->whereIn('asset_id', $flagged)
            ->where('directory_id', $folder->id)
            ->pluck('asset_id')
            ->all();

        // Only what really landed in the folder is cleared: a failed move stays on the page.
        Asset::query()->whereIn('id', $inFolder)->update(['needs_sorting_at' => null]);

        return ['moved' => count($inFolder), 'ignored' => count($assetIds) - count($inFolder)];
    }

    /**
     * @param  list<int>  $assetIds
     * @return array{cleared: int, ignored: int}
     */
    public function markSorted(array $assetIds): array
    {
        $flagged = $this->flaggedIds(assetIds: $assetIds);

        Asset::query()->whereIn('id', $flagged)->update(['needs_sorting_at' => null]);

        return ['cleared' => count($flagged), 'ignored' => count($assetIds) - count($flagged)];
    }

    /**
     * The sorting folder. It is identified by the id this feature stored when it created the folder, never by
     * name: a folder someone else already called `To Sort` is not it, and is never reused. Missing or deleted,
     * a new one is made under Root (`To Sort`, or `To Sort (2)` ... when the name is taken) and its id stored.
     *
     * Every call, inside one fenced transaction (ADR 0010) under the tree lock, re-asserts the invariant that
     * makes moves safe: the folder's user and group lists are empty and an admin owns it. A move adopts the
     * destination's list, so this runs before MassMove and a folder an admin has since shared never passes its
     * viewers on to private uploads.
     */
    public function sortFolder(int $adminId): Directory
    {
        return $this->locked(fn (): Directory => $this->ensureSortFolder(adminId: $adminId));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $body
     * @return T
     */
    private function locked(callable $body): mixed
    {
        return $this->accessModel->sharedly(fn () => Directory::withTreeLock($body));
    }

    private function ensureSortFolder(int $adminId): Directory
    {
        $folder = Directory::query()->find((int) DamConfiguration::find(self::CONFIG_KEY)?->value);

        if (! $folder) {
            $root = Directory::query()->whereNull('parent_id')->orderBy('id')->firstOrFail();
            $name = self::SORT_FOLDER;

            for ($n = 2; Directory::query()->where('parent_id', $root->id)->where('name', $name)->exists(); $n++) {
                $name = self::SORT_FOLDER.' ('.$n.')';
            }

            $folder = app(DirectoryRepository::class)->create(['name' => $name, 'parent_id' => $root->id, 'created_by' => $adminId]);
            DamConfiguration::updateOrCreate(['key' => self::CONFIG_KEY], ['value' => (string) $folder->id]);
        }

        if (! self::isAdmin(adminId: (int) $folder->created_by)) {
            $folder->forceFill(['created_by' => $adminId])->saveQuietly();
        }

        $this->lists->applyToSubtree(directoryId: $folder->id, userIds: [], groupIds: []);

        return $folder;
    }

    /**
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    private function flaggedIds(array $assetIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));

        return Asset::query()->whereIn('id', $ids)->whereNotNull('needs_sorting_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
