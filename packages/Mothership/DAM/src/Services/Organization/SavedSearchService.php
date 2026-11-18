<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Support\OrganizationLog;
use Mothership\DAM\Support\OrganizationNameLock;

/**
 * P4 (D12): saved searches. A saved search stores a query definition (LibraryListing's parameters), never results, so
 * re-running it lists only what its viewer may list at that moment. It is private to its owner by default; `shared`
 * shows it (read and run only) to everyone who uses the Library. Only the owner renames, pins, shares, reorders or
 * deletes it. A saved search another viewer may not see answers the same 404 a missing id does.
 */
class SavedSearchService
{
    public function query()
    {
        return SavedSearch::query()->where('kind', SavedSearch::KIND_SEARCH);
    }

    /** The viewer's own saved searches, then the ones others shared, each in order. */
    public function visibleQuery(?int $viewerId)
    {
        return $this->query()->where(function ($visible) use ($viewerId): void {
            $visible->where('visibility', SavedSearch::VISIBILITY_SHARED);

            if ($viewerId !== null) {
                $visible->orWhere('owner_id', $viewerId);
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function list(?int $viewerId): array
    {
        return $this->visibleQuery($viewerId)
            ->orderByRaw('CASE WHEN owner_id = ? THEN 0 ELSE 1 END', [$viewerId ?? 0])
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (SavedSearch $search) => $this->present(search: $search, viewerId: $viewerId))->all();
    }

    /** @return array<string, mixed> */
    public function present(SavedSearch $search, ?int $viewerId): array
    {
        return [
            'id'         => (int) $search->id,
            'name'       => $search->name,
            'definition' => is_array($search->definition) ? $search->definition : [],
            // Pinning is the owner's own sidebar choice: a search someone else shared is never pinned into this viewer's
            // sidebar, it is listed under "More saved searches" instead (G2-08).
            'pinned'     => $viewerId !== null && (int) $search->owner_id === $viewerId && (bool) $search->pinned,
            'visibility' => $search->visibility,
            'position'   => (int) $search->position,
            'mine'       => $viewerId !== null && (int) $search->owner_id === $viewerId,
        ];
    }

    public function findVisible(int $id, ?int $viewerId): SavedSearch
    {
        $search = $this->visibleQuery($viewerId)->where('id', $id)->first();
        abort_if($search === null, 404, trans('dam::app.admin.organize.saved-searches.not-found'));

        return $search;
    }

    /** The viewer's own saved search; one shared by someone else answers 404 too, as the owner alone may change it. */
    public function findOwned(int $id, ?int $viewerId): SavedSearch
    {
        $search = $viewerId === null ? null : $this->query()->where('id', $id)->where('owner_id', $viewerId)->first();
        abort_if($search === null, 404, trans('dam::app.admin.organize.saved-searches.not-found'));

        return $search;
    }

    /** @param  array{name: string, definition: array, pinned?: bool, visibility?: string}  $attributes */
    public function create(array $attributes, ?int $viewerId): SavedSearch
    {
        abort_if($viewerId === null, 403, trans('dam::app.admin.permissions.unauthorized'));

        // Check and insert run together, so two simultaneous saves of one name cannot both pass the check.
        return OrganizationNameLock::run(
            scope: 'search.'.$viewerId,
            callback: fn (): SavedSearch => $this->createUnlocked(attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name: string, definition: array, pinned?: bool, visibility?: string}  $attributes */
    private function createUnlocked(array $attributes, int $viewerId): SavedSearch
    {
        $name = trim($attributes['name']);
        $this->assertUniqueName(name: $name, ownerId: $viewerId);

        $search = SavedSearch::create([
            'kind'       => SavedSearch::KIND_SEARCH,
            'owner_id'   => $viewerId,
            'created_by' => $viewerId,
            'name'       => $name,
            'definition' => LibraryListing::normalizeDefinition($attributes['definition']),
            'pinned'     => (bool) ($attributes['pinned'] ?? false),
            'visibility' => $attributes['visibility'] ?? SavedSearch::VISIBILITY_PRIVATE,
            'position'   => ($max = $this->query()->where('owner_id', $viewerId)->max('position')) === null ? 0 : (int) $max + 1,
        ]);

        OrganizationLog::write(event: 'saved_search.created', ctx: ['saved_search_id' => (int) $search->id, 'shared' => $search->visibility === SavedSearch::VISIBILITY_SHARED, 'viewer_id' => $viewerId]);

        return $search;
    }

    /** @param  array{name?: string, definition?: array, pinned?: bool, visibility?: string, position?: int}  $attributes */
    public function update(SavedSearch $search, array $attributes, ?int $viewerId): SavedSearch
    {
        // A rename can clash with a save in flight, so it takes the lock saving takes (the owner's scope).
        return OrganizationNameLock::run(
            scope: 'search.'.(int) $search->owner_id,
            callback: fn (): SavedSearch => $this->updateUnlocked(search: $search, attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name?: string, definition?: array, pinned?: bool, visibility?: string, position?: int}  $attributes */
    private function updateUnlocked(SavedSearch $search, array $attributes, ?int $viewerId): SavedSearch
    {
        // The row as it is now, not as the request loaded it: a rename or edit may have landed while this waited, and
        // a stale model would skip writing a value that equals its old copy but differs from what is stored.
        $search = SavedSearch::query()->lockForUpdate()->find($search->id) ?? abort(404, trans('dam::app.admin.organize.saved-searches.not-found'));

        return DB::transaction(function () use ($search, $attributes, $viewerId): SavedSearch {
            if (array_key_exists('name', $attributes)) {
                $name = trim((string) $attributes['name']);
                $this->assertUniqueName(name: $name, ownerId: (int) $search->owner_id, exceptId: (int) $search->id);
                $search->name = $name;
            }

            if (array_key_exists('definition', $attributes)) {
                $search->definition = LibraryListing::normalizeDefinition((array) $attributes['definition']);
            }

            foreach (['pinned', 'visibility'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $search->{$field} = $attributes[$field];
                }
            }

            $search->save();

            if (array_key_exists('position', $attributes)) {
                $ids = $this->query()->where('owner_id', $search->owner_id)->where('id', '<>', $search->id)
                    ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                array_splice($ids, max(0, min((int) $attributes['position'], count($ids))), 0, [(int) $search->id]);

                foreach ($ids as $index => $id) {
                    SavedSearch::query()->where('id', $id)->update(['position' => $index]);
                }
            }

            OrganizationLog::write(event: 'saved_search.updated', ctx: ['saved_search_id' => (int) $search->id, 'fields' => count($attributes), 'viewer_id' => $viewerId]);

            return $search->refresh();
        });
    }

    public function delete(SavedSearch $search, ?int $viewerId): void
    {
        $search->delete();
        OrganizationLog::write(event: 'saved_search.deleted', ctx: ['saved_search_id' => (int) $search->id, 'viewer_id' => $viewerId]);
    }

    private function assertUniqueName(string $name, int $ownerId, ?int $exceptId = null): void
    {
        // A locking read: on MySQL REPEATABLE READ a plain read answers from the snapshot taken before this waited for the lock.
        $taken = $this->query()->where('owner_id', $ownerId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->value('id') !== null;

        if ($taken) {
            throw ValidationException::withMessages(['name' => trans('dam::app.admin.organize.saved-searches.name-taken')]);
        }
    }
}
