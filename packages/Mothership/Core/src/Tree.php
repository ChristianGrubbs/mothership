<?php

namespace Mothership\Core;

use Illuminate\Support\Facades\Request;

class Tree
{
    /**
     * Contains tree item
     *
     * @var array<int|string, mixed>
     */
    public array $items = [];

    /**
     * Contains acl roles
     *
     * @var array
     */
    public $roles = [];

    public array $alternateRoles = [];

    /**
     * Contains current item route
     *
     * @var string
     */
    public $current;

    /**
     * Contains current item key
     *
     * @var string
     */
    public $currentKey;

    /**
     * Create a new instance.
     */
    public function __construct()
    {
        $this->current = Request::url();
    }

    /**
     * Shortcut method for create a Config with a callback.
     * This will allow you to do things like fire an event on creation.
     *
     * @param  callable|null  $callback  Callback to use after the Config creation
     */
    public static function create($callback = null): self
    {
        $tree = new Tree;

        if ($callback) {
            $callback($tree);
        }

        return $tree;
    }

    /**
     * Add a Config item to the item stack
     *
     * @param  array<string, mixed>  $item
     */
    public function add(array $item, $type = ''): void
    {
        $item['children'] = [];

        if ($type == 'menu') {
            $item['url'] = route($item['route'], $item['params'] ?? []);

            if ($this->isCurrentUrl($item['url'])) {
                $this->currentKey = $item['key'];
            }
        } elseif ($type == 'acl') {
            $item['name'] = trans($item['name']);

            /**
             * A routeless acl entry registers only as an assignable permission
             * checkbox; enforcement happens in-controller (e.g. per-section
             * System Settings rows that all share one generic editor route).
             */
            if (! empty($item['route'])) {
                $this->roles[$item['route']] = $item['key'];
            }

            foreach ($item['also_authorizes'] ?? [] as $route) {
                $this->alternateRoles[$route][] = $item['key'];
            }
        }

        $children = str_replace('.', '.children.', $item['key']);

        core()->array_set($this->items, $children, $item);
    }

    /**
     * Top-level items for the sidebar/drawer's scrolling nav list: those with
     * no `placement` tag, i.e. neither rendered in the avatar dropdown
     * (`'placement' => 'account'`, see AdminServiceProvider::
     * buildAccountMenu()) nor pinned outside the scroll area
     * (`'placement' => 'pinned'`, e.g. Help). Any 'account'-tagged descendant
     * (nested elsewhere for its own gating, e.g. Library's own configuration
     * page) is stripped recursively. Breadcrumbs, tabs and active-key
     * resolution keep reading the full `items` tree directly so the moved
     * pages keep their trail and gating.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleItems(): array
    {
        return $this->itemsPlaced(null);
    }

    /**
     * Top-level items pinned outside the sidebar/drawer's scrolling nav (for
     * example Help, pinned above the collapse toggle) — `'placement' =>
     * 'pinned'` in menu.php.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pinnedItems(): array
    {
        return $this->itemsPlaced('pinned');
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function itemsPlaced(?string $placement, ?array $items = null): array
    {
        return collect($items ?? $this->items)
            ->filter(fn (array $item): bool => ($item['placement'] ?? null) === $placement)
            ->map(function (array $item): array {
                if (! empty($item['children'])) {
                    $item['children'] = $this->stripAccountPlacement($item['children']);
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function stripAccountPlacement(array $items): array
    {
        return collect($items)
            ->reject(fn (array $item): bool => ($item['placement'] ?? null) === 'account')
            ->map(function (array $item): array {
                if (! empty($item['children'])) {
                    $item['children'] = $this->stripAccountPlacement($item['children']);
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * Method to find the active links.
     *
     * Once `currentKey` names a menu item (the normal case: Tree::add() sets it
     * from an exact/prefix URL match, or AdminServiceProvider::
     * resolveActiveMenuKey()/resolveHubMenuKey() resolves an off-menu route to
     * one), that key alone decides which items are active — a sibling whose
     * own url happens to prefix the current url no longer also lights up.
     * This matters once flattening promotes former children to top-level
     * siblings that all still live under one url prefix (e.g. Library at
     * /admin/dam and Web Pages at /admin/dam/pages): before, url-ancestry
     * matching a broader sibling was harmless because that sibling was the
     * child's own parent; now it would wrongly mark two top-level rows
     * active at once. Raw url matching is kept only as a fallback for the
     * rarer case where nothing resolved a currentKey at all (e.g. a page
     * with no menu correspondence, such as My Account).
     */
    public function getActive(array $item): ?bool
    {
        if ($this->currentKey !== null) {
            return $this->isCurrentKeyDescendant($item['key']) ? true : null;
        }

        return $this->isCurrentUrl($item['url']) ? true : null;
    }

    /**
     * Whether the given menu url matches the current request url, respecting
     * path-segment boundaries so a shorter url (e.g. `configuration/system`)
     * does not match a sibling that merely shares its prefix
     * (e.g. `configuration/system-information`).
     */
    private function isCurrentUrl(string $url): bool
    {
        $current = rtrim($this->current, '/');
        $url = rtrim($url, '/');

        return $current === $url || str_starts_with($current, $url.'/');
    }

    /**
     * Whether the current active key is the given key or one of its
     * descendants, matching on the dotted key hierarchy boundary so a key is
     * not treated as an ancestor of a sibling sharing its prefix.
     */
    private function isCurrentKeyDescendant(string $key): bool
    {
        return $this->currentKey === $key || str_starts_with((string) $this->currentKey, $key.'.');
    }

    /**
     * Build the active menu trail from the top level down to the deepest active
     * item, following the active branch at each level. Used to render breadcrumbs
     * for the current page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveTrail(): array
    {
        $trail = [];

        $nodes = $this->items;

        while (! empty($nodes)) {
            $activeItem = collect($nodes)->first(fn (array $item): bool => (bool) $this->getActive($item));

            if (! $activeItem) {
                break;
            }

            $trail[] = $activeItem;

            $nodes = $activeItem['children'] ?? [];
        }

        return $trail;
    }

    /**
     * Remove unauthorized urls.
     */
    public function removeUnauthorizedUrls(): array
    {
        return collect($this->items)->map(function (array $item): array {
            $this->removeChildrenUnauthorizedUrls($item);

            return $item;
        })->toArray();
    }

    /**
     * Remove all children unauthorized urls. This will handle all levels.
     *
     * An account-placed child (rendered in the avatar dropdown instead of the
     * sidebar — see AdminServiceProvider::buildAccountMenu()) is skipped when
     * picking the "first child" here: it stays nested under its parent only
     * for that parent's gating, and is not a sidebar destination a parent
     * with no other children should delegate its own url to (e.g. Library
     * keeps its own asset-grid url rather than inheriting its nested-for-
     * gating-only Configuration child's url).
     */
    private function removeChildrenUnauthorizedUrls(array &$item): void
    {
        $children = collect($item['children'] ?? [])
            ->reject(fn (array $child): bool => ($child['placement'] ?? null) === 'account')
            ->values();

        if ($children->isEmpty()) {
            return;
        }

        $firstChildrenItem = $children->first();

        $item['route'] = $firstChildrenItem['route'];

        $item['url'] = route($firstChildrenItem['route'], $firstChildrenItem['params'] ?? []);

        $this->removeChildrenUnauthorizedUrls($firstChildrenItem);
    }
}
