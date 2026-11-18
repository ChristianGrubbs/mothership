<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Services\LibraryMediaFilter;
use Mothership\DAM\Support\OrganizationLog;
use Mothership\DAM\Support\OrganizationNameLock;

/**
 * P4 (D3): sections, stored in dam_saved_searches with `kind` section (an admin-owned, shared saved search, roadmap
 * §3.2) and their manual pins in dam_section_pins. A section is a view, never a placement: an asset is in every
 * section whose asset-type rule matches it (any listed media type, or any listed extension) plus every section that
 * pins it. Sections never change access: membership only narrows a query already scoped through the access seam.
 *
 * Who may create, rename, reorder, delete or pin is the caller's check (dam.sections.manage, or the route's API
 * permission); everyone who uses the Library sees every section.
 */
class SectionService
{
    /** Where an unpinned asset sorts in a section's manual order: after every pin. */
    public const UNPINNED_POSITION = 2147483647;

    public const MEDIA_TYPES = ['images', 'videos', 'svgs', 'audio', 'models'];

    public function query()
    {
        return SavedSearch::query()->where('kind', SavedSearch::KIND_SECTION);
    }

    /** The section, else the same 404 a missing id gets. */
    public function find(int $id): SavedSearch
    {
        $section = $this->query()->where('id', $id)->first();

        abort_if($section === null, 404, trans('dam::app.admin.organize.sections.not-found'));

        return $section;
    }

    /**
     * Every section in order, each with how many assets the viewer may list in it and, when asset ids are given, how
     * many of those (visible ones only) it pins.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, array<string, mixed>>
     */
    public function list(AccessContext $context, array $assetIds = []): array
    {
        $visibleAssetIds = VisibleAssets::filter($assetIds, $context);

        return $this->query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (SavedSearch $section) => $this->present($section) + [
                'count' => $this->count(section: $section, context: $context),
                'pins'  => $visibleAssetIds === [] ? 0 : DB::table('dam_section_pins')->where('section_id', $section->id)->whereIn('asset_id', $visibleAssetIds)->count(),
            ])->all();
    }

    /** @return array<string, mixed> */
    public function present(SavedSearch $section): array
    {
        return [
            'id'           => (int) $section->id,
            'name'         => $section->name,
            'rule'         => $this->rule($section),
            'default_sort' => $section->default_sort,
            'position'     => (int) $section->position,
        ];
    }

    public function count(SavedSearch $section, AccessContext $context): int
    {
        $query = VisibleAssets::query($context);
        $this->applyMembership(query: $query, section: $section);

        return $query->count();
    }

    /** @return array{media_types: array<int, string>, extensions: array<int, string>} */
    public function rule(SavedSearch $section): array
    {
        $rule = is_array($section->definition['rule'] ?? null) ? $section->definition['rule'] : [];

        return [
            'media_types' => array_values(array_intersect((array) ($rule['media_types'] ?? []), self::MEDIA_TYPES)),
            'extensions'  => array_values(array_filter(array_map(fn ($value) => strtolower(trim((string) $value)), (array) ($rule['extensions'] ?? [])))),
        ];
    }

    /** Narrows an asset query (over dam_assets) to the section: its rule's matches plus its pins. */
    public function applyMembership(Builder $query, SavedSearch $section): void
    {
        $rule = $this->rule($section);
        $extensionColumn = DB::connection()->getQueryGrammar()->wrap('dam_assets.extension');

        $query->where(function ($member) use ($rule, $section, $extensionColumn): void {
            $member->whereExists(fn ($pin) => $pin->select(DB::raw(1))->from('dam_section_pins')
                ->whereColumn('dam_section_pins.asset_id', 'dam_assets.id')
                ->where('dam_section_pins.section_id', $section->id));

            foreach ($rule['media_types'] as $type) {
                $member->orWhere(fn ($matches) => LibraryMediaFilter::apply($matches, $type));
            }

            if ($rule['extensions'] !== []) {
                $placeholders = implode(',', array_fill(0, count($rule['extensions']), '?'));
                $member->orWhereRaw("LOWER({$extensionColumn}) IN ({$placeholders})", $rule['extensions']);
            }
        });
    }

    /** @param  array{name: string, rule?: array, default_sort?: string|null}  $attributes */
    public function create(array $attributes, ?int $viewerId): SavedSearch
    {
        // Check and insert run together, so two simultaneous creates of one name cannot both pass the check.
        return OrganizationNameLock::run(
            scope: 'section',
            callback: fn (): SavedSearch => $this->createUnlocked(attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name: string, rule?: array, default_sort?: string|null}  $attributes */
    private function createUnlocked(array $attributes, ?int $viewerId): SavedSearch
    {
        $name = trim($attributes['name']);
        $this->assertUniqueName($name);

        $section = SavedSearch::create([
            'kind'         => SavedSearch::KIND_SECTION,
            'owner_id'     => null,
            'created_by'   => $viewerId,
            'name'         => $name,
            'definition'   => ['rule' => $this->normalizeRule($attributes['rule'] ?? [])],
            'pinned'       => true,
            'visibility'   => SavedSearch::VISIBILITY_SHARED,
            'default_sort' => $attributes['default_sort'] ?? null,
            'position'     => ($max = $this->query()->max('position')) === null ? 0 : (int) $max + 1,
        ]);

        OrganizationLog::write(event: 'section.created', ctx: ['section_id' => (int) $section->id, 'viewer_id' => $viewerId]);

        return $section;
    }

    /** @param  array{name?: string, rule?: array, default_sort?: string|null, position?: int}  $attributes */
    public function update(SavedSearch $section, array $attributes, ?int $viewerId): SavedSearch
    {
        // A rename can clash with a create in flight, so it takes the lock creating takes.
        return OrganizationNameLock::run(
            scope: 'section',
            callback: fn (): SavedSearch => $this->updateUnlocked(section: $section, attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name?: string, rule?: array, default_sort?: string|null, position?: int}  $attributes */
    private function updateUnlocked(SavedSearch $section, array $attributes, ?int $viewerId): SavedSearch
    {
        // The row as it is now, not as the request loaded it (see SavedSearchService::updateUnlocked).
        $section = SavedSearch::query()->lockForUpdate()->find($section->id) ?? abort(404, trans('dam::app.admin.organize.sections.not-found'));

        return DB::transaction(function () use ($section, $attributes, $viewerId): SavedSearch {
            if (array_key_exists('name', $attributes)) {
                $name = trim((string) $attributes['name']);
                $this->assertUniqueName(name: $name, exceptId: (int) $section->id);
                $section->name = $name;
            }

            if (array_key_exists('rule', $attributes)) {
                $section->definition = ['rule' => $this->normalizeRule((array) $attributes['rule'])];
            }

            if (array_key_exists('default_sort', $attributes)) {
                $section->default_sort = $attributes['default_sort'];
            }

            $section->save();

            if (array_key_exists('position', $attributes)) {
                $ids = $this->query()->where('id', '<>', $section->id)->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                array_splice($ids, max(0, min((int) $attributes['position'], count($ids))), 0, [(int) $section->id]);

                foreach ($ids as $index => $id) {
                    SavedSearch::query()->where('id', $id)->update(['position' => $index]);
                }
            }

            OrganizationLog::write(event: 'section.updated', ctx: ['section_id' => (int) $section->id, 'fields' => count($attributes), 'viewer_id' => $viewerId]);

            return $section->refresh();
        });
    }

    public function delete(SavedSearch $section, ?int $viewerId): void
    {
        DB::transaction(function () use ($section): void {
            DB::table('dam_section_pins')->where('section_id', $section->id)->delete();
            $section->delete();
            app(ValueDecisions::class)->forgetTarget(kind: ValueDecisions::KIND_SECTION, targetIds: (int) $section->id);
        });

        OrganizationLog::write(event: 'section.deleted', ctx: ['section_id' => (int) $section->id, 'viewer_id' => $viewerId]);
    }

    /**
     * Pins assets the viewer may list to the end of the section's pins. Any id the viewer cannot list answers the same
     * 404 a missing one does, and nothing is pinned.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, int>
     */
    public function pin(SavedSearch $section, array $assetIds, AccessContext $context, ?int $viewerId): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $visible = VisibleAssets::filter($assetIds, $context);
        abort_if(count($visible) !== count($assetIds), 404, trans('dam::app.admin.organize.assets-not-found'));

        $added = MemberOrder::sectionPins((int) $section->id)->append(assetIds: $visible, addedBy: $viewerId);
        // P5b (§3.4): a person pinning lifts their earlier unpin.
        app(ValueDecisions::class)->clear(assetIds: $visible, kind: ValueDecisions::KIND_SECTION, targetId: (int) $section->id);
        OrganizationLog::write(event: 'section.pinned', ctx: ['section_id' => (int) $section->id, 'requested' => count($assetIds), 'added' => count($added), 'viewer_id' => $viewerId]);

        return $added;
    }

    /** Pins one asset for a rule (P5b ValueWriter, which decided it may); the pin keeps `$source`. False when it was pinned. */
    public function pinByMachine(SavedSearch $section, int $assetId, string $source): bool
    {
        $added = MemberOrder::sectionPins((int) $section->id)->append(assetIds: [$assetId], addedBy: null, source: $source) !== [];
        OrganizationLog::write(event: 'section.pinned', ctx: ['section_id' => (int) $section->id, 'requested' => 1, 'added' => (int) $added, 'viewer_id' => null]);

        return $added;
    }

    /** @param  array<int, int>  $assetIds */
    public function unpin(SavedSearch $section, array $assetIds, AccessContext $context, ?int $viewerId): int
    {
        $order = MemberOrder::sectionPins((int) $section->id);
        $visible = VisibleAssets::filter($assetIds, $context);
        $members = array_values(array_intersect($visible, $order->ids()));
        $removed = $order->remove($visible);

        // P5b (§3.4): a person's unpin is recorded, so no rule pins the asset again.
        if ($members !== []) {
            app(ValueDecisions::class)->remove(assetIds: $members, kind: ValueDecisions::KIND_SECTION, targetId: (int) $section->id, actorId: $viewerId);
        }

        OrganizationLog::write(event: 'section.unpinned', ctx: ['section_id' => (int) $section->id, 'removed' => $removed, 'viewer_id' => $viewerId]);

        return $removed;
    }

    public function movePin(SavedSearch $section, int $assetId, int $position, AccessContext $context, ?int $viewerId): void
    {
        $order = MemberOrder::sectionPins((int) $section->id);
        $visible = VisibleAssets::filter($order->ids(), $context);
        abort_unless(in_array($assetId, $visible, true), 404, trans('dam::app.admin.organize.assets-not-found'));

        $order->move(assetId: $assetId, position: $position, visibleIds: $visible);
        OrganizationLog::write(event: 'section.pin_moved', ctx: ['section_id' => (int) $section->id, 'asset_id' => $assetId, 'position' => $position, 'viewer_id' => $viewerId]);
    }

    /** The asset ids a section holds for the viewer (rule matches plus pins), for a caller listing it. */
    public function memberIds(SavedSearch $section, AccessContext $context): Collection
    {
        $query = VisibleAssets::query($context);
        $this->applyMembership(query: $query, section: $section);

        return $query->pluck('dam_assets.id')->map(fn ($id) => (int) $id);
    }

    /** @return array{media_types: array<int, string>, extensions: array<int, string>} */
    private function normalizeRule(array $rule): array
    {
        $mediaTypes = array_values(array_unique(array_intersect((array) ($rule['media_types'] ?? []), self::MEDIA_TYPES)));
        $extensions = array_values(array_unique(array_filter(array_map(
            fn ($value) => ltrim(strtolower(trim((string) $value)), '.'),
            (array) ($rule['extensions'] ?? []),
        ), fn (string $value) => preg_match('/^[a-z0-9]{1,16}$/', $value) === 1)));

        return ['media_types' => $mediaTypes, 'extensions' => $extensions];
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        // A locking read: on MySQL REPEATABLE READ a plain read answers from the snapshot taken before this waited for the lock.
        $taken = $this->query()->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->value('id') !== null;

        if ($taken) {
            throw ValidationException::withMessages(['name' => trans('dam::app.admin.organize.sections.name-taken')]);
        }
    }
}
