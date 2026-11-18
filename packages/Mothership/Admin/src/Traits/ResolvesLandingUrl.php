<?php

namespace Mothership\Admin\Traits;

use Illuminate\Support\Facades\Route;

trait ResolvesLandingUrl
{
    /**
     * Resolve the landing URL for the authenticated admin by walking the sorted
     * menu config and returning the first item whose ACL key the user owns.
     *
     * A menu item's own key is not enough: the destination route is gated by its
     * own ACL key (menu key "settings" routes to admin.settings.channels.index,
     * gated by "settings.channels"), so both are checked. The acl config sweep
     * afterwards covers roles that grant only child-level keys. Falls back to
     * logging the user out when no entry is reachable.
     */
    protected function firstAllowedUrl(): string
    {
        $pimEnabled = (bool) config('mothership.pim_enabled', false);
        $pimKeys = (array) config('mothership.pim_menu_keys', [
            'dashboard',
            'catalog',
            'data_transfer',
            'ai-agent',
        ]);

        $items = array_filter(
            config('menu.admin') ?? [],
            function ($item) use ($pimEnabled, $pimKeys) {
                if (empty($item['key']) || str_contains($item['key'], '.')) {
                    return false;
                }

                if (! $pimEnabled && $this->isPimMenuItem($item['key'], $pimKeys)) {
                    return false;
                }

                return true;
            }
        );

        usort($items, fn ($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        $aclRoutes = optional(app('acl'))->roles ?? [];

        foreach ($items as $item) {
            if (empty($item['route']) || ! Route::has($item['route'])) {
                continue;
            }

            if (! bouncer()->hasPermission($item['key'])) {
                continue;
            }

            $routeAclKey = $aclRoutes[$item['route']] ?? $item['key'];

            if (! bouncer()->hasPermission($routeAclKey)) {
                continue;
            }

            return route($item['route']);
        }

        foreach (config('acl') ?? [] as $aclItem) {
            if (empty($aclItem['route']) || ! Route::has($aclItem['route'])) {
                continue;
            }

            if (! $pimEnabled && ! empty($aclItem['key']) && $this->isPimMenuItem($aclItem['key'], $pimKeys)) {
                continue;
            }

            if (bouncer()->hasPermission($aclItem['key'])) {
                return route($aclItem['route']);
            }
        }

        auth()->guard('admin')->logout();

        session()->flash('error', trans('admin::app.errors.403.message'));

        return route('admin.session.create');
    }

    /**
     * Check if a menu key belongs to the PIM surface.
     */
    protected function isPimMenuItem(string $key, array $pimKeys): bool
    {
        foreach ($pimKeys as $pimKey) {
            if ($key === $pimKey || str_starts_with($key, $pimKey.'.')) {
                return true;
            }
        }

        return false;
    }
}
