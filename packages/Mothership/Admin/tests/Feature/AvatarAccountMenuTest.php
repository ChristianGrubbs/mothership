<?php

// 2026-09-24 navigation redesign: Settings and Configuration moved from the sidebar into the avatar
// (top-right) dropdown, and Library's former children (Web Pages, Shared Links, Tags) became
// top-level sidebar rows. AdminServiceProvider::buildAccountMenu() groups the ACL-filtered
// `menu.admin` items tagged `'placement' => 'account'` into $accountMenu; Tree::visibleItems() /
// Tree::pinnedItems() split the rest between the sidebar's scrolling list and its pinned Help row.
//
// Region checks below use plain substring bounds rather than a DOM crawler: since the Bridge redesign
// (2026-11-18) `#mothership-sidebar` (the rail) renders first and closes before the `<header>` top bar
// that holds the avatar dropdown (packages/Mothership/Admin/src/Resources/views/components/layouts/index.blade.php),
// so the two never overlap.
function extractBetween(string $html, string $start, string $end): string
{
    $startPos = strpos($html, $start);
    expect($startPos)->not->toBeFalse("expected to find '{$start}' in the response");

    $endPos = strpos($html, $end, $startPos);
    expect($endPos)->not->toBeFalse("expected to find '{$end}' after '{$start}'");

    return substr($html, $startPos, $endPos - $startPos);
}

function accountMenuHtml(string $html): string
{
    return extractBetween($html, 'data-account-menu', '</header>');
}

function sidebarHtml(string $html): string
{
    return extractBetween($html, 'id="mothership-sidebar"', 'v-sidebar-collapse');
}

// The header's own mobile nav drawer (<x-admin::drawer ref="sidebarMenuDrawer">, a separate markup
// region from both the sidebar and the avatar dropdown — see its `@foreach ($menu->visibleItems() ...`
// loop). `sidebarMenuDrawer` is this drawer's only ref in the codebase, so anchoring on it (rather
// than the more common `<x-admin::drawer>` tag other pages also use) scopes to exactly this one.
function mobileDrawerHtml(string $html): string
{
    return extractBetween($html, 'ref="sidebarMenuDrawer"', '</v-drawer>');
}

it('shows Settings and Configuration in the avatar menu, not the sidebar, for a full admin', function () {
    $this->loginAsAdmin();

    $html = $this->get(route('admin.home.index'))->assertOk()->getContent();

    $account = accountMenuHtml($html);
    $sidebar = sidebarHtml($html);

    expect($account)->toContain(trans('admin::app.components.layouts.sidebar.settings'));
    expect($account)->toContain(trans('admin::app.components.layouts.sidebar.configure'));

    // Each route is the decisive check: the sidebar must no longer link to it at all, wherever it
    // renders. (The heading words "Settings"/"Configuration" are not checked for absence here — an
    // unrelated Magic AI sidebar sub-item is itself labelled "Settings", so that text alone is not a
    // reliable signal.)
    foreach ([
        route('admin.settings.users.index'),
        route('admin.settings.roles.index'),
        route('admin.settings.groups.index'),
        route('admin.settings.channels.index'),
        route('admin.dam.configuration.index'),
        route('webhook.index'),
        route('admin.configuration.system.information'),
        route('admin.settings.system.index'),
    ] as $url) {
        expect($account)->toContain($url);
        expect($sidebar)->not->toContain($url);
    }

    // Integrations is in both: the account menu's Configuration section and the rail foot, where the
    // Bridge redesign (02-information-architecture.md) names it Connections.
    expect($account)->toContain(route('admin.configuration.integrations.index'));
    expect($sidebar)->toContain('href="'.route('admin.configuration.integrations.index').'"')
        ->and($sidebar)->toContain(trans('admin::app.components.layouts.rail.connections'));
});

it('lists no Settings or Configuration entries in the mobile drawer nav', function () {
    // Codex review round 1 on PR #60, item 3(b): scoped to the drawer's own markup, not the whole
    // page — an unrelated Magic AI sidebar sub-item is itself labelled "Settings" (see the comment
    // above), so route presence within this one region is the reliable signal, as in the first test.
    $this->loginAsAdmin();

    $drawer = mobileDrawerHtml($this->get(route('admin.home.index'))->assertOk()->getContent());

    foreach ([
        route('admin.settings.users.index'),
        route('admin.settings.roles.index'),
        route('admin.settings.groups.index'),
        route('admin.settings.channels.index'),
        route('admin.dam.configuration.index'),
        route('admin.configuration.integrations.index'),
        route('webhook.index'),
        route('admin.configuration.system.information'),
        route('admin.settings.system.index'),
    ] as $url) {
        expect($drawer)->not->toContain($url);
    }

    // The drawer still lists the ordinary sidebar rows (unaffected by this fix).
    expect($drawer)->toContain(route('admin.dam.index'));
});

it('shows only name, My Account and Log out for a role with no settings or configuration rights', function () {
    $admin = $this->loginWithPermissions('custom', ['home']);

    $html = $this->get(route('admin.home.index'))->assertOk()->getContent();

    $account = accountMenuHtml($html);

    // Blade escapes the name, so a Faker name such as "O'Reilly" renders as O&#039;Reilly.
    expect($account)->toContain(e($admin->name));
    expect($account)->toContain(trans('admin::app.components.layouts.header.my-account'));
    expect($account)->toContain(trans('admin::app.components.layouts.header.logout'));

    expect($account)->not->toContain(trans('admin::app.components.layouts.sidebar.settings'));
    expect($account)->not->toContain(trans('admin::app.components.layouts.sidebar.configure'));

    foreach ([
        route('admin.settings.users.index'),
        route('admin.dam.configuration.index'),
        route('admin.configuration.integrations.index'),
    ] as $url) {
        expect($account)->not->toContain($url);
    }
});

it('keeps dam.configuration reachable, labelled Library, under Configuration for a role missing the unrelated global configuration permission', function () {
    // 'dam.configuration' stays nested under 'dam' for gating (unchanged from before this redesign):
    // a role granted it does not also need the unrelated global 'configuration' permission to reach
    // it, in the sidebar before or the avatar dropdown now.
    $this->loginWithPermissions('custom', ['home', 'dam', 'dam.configuration']);

    $account = accountMenuHtml($this->get(route('admin.home.index'))->assertOk()->getContent());

    expect($account)->toContain(trans('admin::app.components.layouts.sidebar.configure'));
    expect($account)->toContain(route('admin.dam.configuration.index'));

    foreach ([
        route('admin.configuration.integrations.index'),
        route('webhook.index'),
        route('admin.configuration.system.information'),
    ] as $url) {
        expect($account)->not->toContain($url);
    }
});

it('renders the Settings and Configuration sections in the approved order', function () {
    // Codex review round 1 on PR #60: the Settings section was rendering Channels, Users, Roles,
    // Groups (their unrelated 'sort' values, 6/7/8/9 — still used by the settings tabs, breadcrumbs
    // and which child 'settings' delegates its landing url to) instead of the approved Users, Roles,
    // Groups, Channels. Fixed with a separate 'account_sort' that only buildAccountMenu() reads;
    // Configuration's children have none, so they keep ordering by their own 'sort' unchanged.
    $this->loginAsAdmin();

    $account = accountMenuHtml($this->get(route('admin.home.index'))->assertOk()->getContent());

    $labels = [
        'Settings heading'      => trans('admin::app.components.layouts.sidebar.settings'),
        'Users'                 => trans('admin::app.components.layouts.sidebar.users'),
        'Roles'                 => trans('admin::app.components.layouts.sidebar.roles'),
        'Groups'                => trans('admin::app.acl.groups'),
        'Channels'              => trans('admin::app.components.layouts.sidebar.channels'),
        'Configuration heading' => trans('admin::app.components.layouts.sidebar.configure'),
        'Library'               => trans('dam::app.admin.components.layouts.sidebar.library'),
        'Integrations'          => trans('admin::app.components.layouts.sidebar.integrations'),
        'Webhooks'              => trans('webhook::app.components.layouts.sidebar.menu.webhook.name'),
        'System information'    => trans('admin::app.components.layouts.sidebar.system-information'),
        'System settings'       => trans('admin::app.components.layouts.sidebar.system-settings'),
    ];

    $positions = [];

    foreach ($labels as $description => $label) {
        $position = strpos($account, $label);
        expect($position)->not->toBeFalse("expected to find {$description} (\"{$label}\") in the avatar menu");
        $positions[] = $position;
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted,
        'avatar menu must read Settings > Users, Roles, Groups, Channels > Configuration > Library, Integrations, Webhooks, System information, System settings');
});

it('orders the rail Home, Library, Collections, then Away from ship, Sorting and More, with Help at the foot', function () {
    $this->loginAsAdmin();

    $sidebar = sidebarHtml($this->get(route('admin.home.index'))->assertOk()->getContent());

    // The Bridge rail (redesign v2, 02-information-architecture.md): main rows, the "Away from ship"
    // and "Sorting" groups, rows not yet placed under "More", and Help in the foot.
    $positions = [
        // 'Home' is a literal string in DAM's menu.php, not a lang key.
        'Home'            => strpos($sidebar, 'Home'),
        'Library'         => strpos($sidebar, trans('dam::app.admin.components.layouts.sidebar.library')),
        'Collections'     => strpos($sidebar, 'href="'.route('admin.dam.collections.index').'"'),
        'Away from ship'  => strpos($sidebar, trans('admin::app.components.layouts.rail.away')),
        'Shared Links'    => strpos($sidebar, trans('dam::app.admin.components.layouts.sidebar.shares')),
        'Sorting'         => strpos($sidebar, trans('admin::app.components.layouts.rail.sorting')),
        'More'            => strpos($sidebar, 'data-rail-group="more"'),
        'Web Pages'       => strpos($sidebar, trans('dam::app.admin.components.layouts.sidebar.pages')),
        'Help'            => strpos($sidebar, trans('admin::app.components.layouts.sidebar.help')),
    ];

    foreach ($positions as $label => $position) {
        expect($position)->not->toBeFalse("expected to find {$label} in the sidebar");
    }

    $ordered = array_values($positions);
    $sorted = $ordered;
    sort($sorted);

    expect($ordered)->toBe($sorted, 'rail must read Home, Library, Collections, Away from ship, Sorting, More, ..., Help');

    // Assets no longer has its own row — Library covers it.
    expect($sidebar)->not->toContain(trans('dam::app.admin.components.layouts.sidebar.assets'));
});
