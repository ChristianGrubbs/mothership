<?php

namespace Mothership\Admin;

use Mothership\Core\Tree;

class SystemSettings
{
    /**
     * Registry branches owned by inherited PIM packages. Keeping this marker at
     * the registry boundary applies the flag to both the hub tree and direct
     * generic editor lookups without coupling the generic routes to packages.
     *
     * @var array<int, string>
     */
    protected const PIM_OWNED_KEYS = [
        'system.measurement',
    ];

    /**
     * Memoised ACL-filtered tree, built once per request instance. The class is
     * resolved per request (never a singleton), so the per-admin ACL result never
     * leaks across requests under Octane.
     */
    protected ?Tree $tree = null;

    /**
     * Build the ACL-filtered settings tree from config('system_settings').
     */
    public function tree(): Tree
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $tree = Tree::create();

        foreach ($this->accessibleItems() as $item) {
            $tree->add($item);
        }

        $tree->items = core()->sortItems($tree->items);

        return $this->tree = $tree;
    }

    /**
     * Find a raw registry entry by its dot key.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        /** @var array<int, array<string, mixed>> $registry */
        $registry = config('system_settings', []);

        foreach ($registry as $item) {
            if (($item['key'] ?? null) === $key) {
                abort_if(
                    $this->isPimOwned($item) && ! config('mothership.pim_enabled', false),
                    403,
                    trans('admin::app.errors.403.message')
                );

                return $item;
            }
        }

        return null;
    }

    /**
     * Resolve the field group a `fields` row edits. A row may either declare its
     * fields inline (`fields`) or reference an existing `config('core')` group by
     * key (`config_group`) — the latter keeps the group's config codes intact, so
     * migrating an existing settings group into the hub never relocates saved data.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    public function formGroup(array $entry): ?array
    {
        if (! empty($entry['config_group'])) {
            /** @var array<string, mixed>|null $group */
            $group = collect((array) config('core', []))->firstWhere('key', $entry['config_group']);

            return $group;
        }

        if (! empty($entry['fields'])) {
            return $entry;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function aclKeysForConfigCodes(array $codes): array
    {
        $keys = [];

        foreach ($this->configGroupOwners() as $configGroup => $acl) {
            foreach ($codes as $code) {
                if ($code === $configGroup || str_starts_with($code, $configGroup.'.')) {
                    $keys[] = $acl;

                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    public function aclKeysForConfigGroup(string $groupKey): array
    {
        if ($groupKey === '') {
            return [];
        }

        $keys = [];

        foreach ($this->configGroupOwners() as $configGroup => $acl) {
            if (
                $configGroup === $groupKey
                || str_starts_with($configGroup, $groupKey.'.')
                || str_starts_with($groupKey, $configGroup.'.')
            ) {
                $keys[] = $acl;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    protected function configGroupOwners(): array
    {
        $owners = [];

        foreach ((array) config('system_settings', []) as $item) {
            $configGroup = (string) ($item['config_group'] ?? '');

            if ($configGroup === '' || empty($item['acl'])) {
                continue;
            }

            $owners[$configGroup] = (string) $item['acl'];
        }

        return $owners;
    }

    /**
     * Registry rows the current admin may see. Rows carrying an `acl` the admin
     * lacks are dropped; sections and acl-less rows always pass.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function accessibleItems(): array
    {
        /** @var array<int, array<string, mixed>> $registry */
        $registry = config('system_settings', []);

        return array_values(array_filter($registry, function (array $item): bool {
            if ($this->isPimOwned($item) && ! config('mothership.pim_enabled', false)) {
                return false;
            }

            if (empty($item['acl'])) {
                return true;
            }

            return bouncer()->hasPermission((string) $item['acl']);
        }));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function isPimOwned(array $item): bool
    {
        $key = (string) ($item['key'] ?? '');

        foreach (self::PIM_OWNED_KEYS as $pimKey) {
            if ($key === $pimKey || str_starts_with($key, $pimKey.'.')) {
                return true;
            }
        }

        return false;
    }
}
