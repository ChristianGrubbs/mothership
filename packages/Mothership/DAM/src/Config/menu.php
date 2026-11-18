<?php

/*
 * 'rail' places an item in the Bridge rail (redesign v2, 02-information-architecture.md):
 * group main (Home, Library, Collections), away (Away from ship), sorting (Sorting) or more
 * (rows that leave the rail in later redesign phases), its order inside the group and its
 * Lucide glyph (admin::bridge.icon). Items without it fall into "more" with their icon-font glyph.
 */
return [
    [
        'key'       => 'home',
        'rail'      => ['group' => 'main', 'sort' => 1, 'glyph' => 'house'],
        'name'      => 'Home',
        'route'     => 'admin.home.index',
        'sort'      => -1,
        'icon'      => 'icon-dashboard',
    ],
    [
        // 'sort' (unchanged) still drives the settings tabs/breadcrumbs; 'account_sort' is
        // read only by AdminServiceProvider::buildAccountMenu() for the approved Users,
        // Roles, Groups, Channels order in the avatar dropdown.
        'key'          => 'settings.groups',
        'name'         => 'admin::app.acl.groups',
        'route'        => 'admin.settings.groups.index',
        'sort'         => 9,
        'account_sort' => 3,
        'icon'         => '',
    ],
    [
        // "Library" also opens the asset grid (DAMController::index and
        // AssetController::index both render dam::asset.index) — the former
        // separate top-level Assets shortcut and this nested dam.assets child
        // both duplicated it, so there is now exactly one row for it. Asset
        // routes (/admin/dam/assets/...) still highlight this row: no sibling
        // menu item's URL prefix-matches them once dam.assets is gone, so
        // Tree::isCurrentUrl() resolves to this item on its own.
        'key'       => 'dam',
        'rail'      => ['group' => 'main', 'sort' => 2, 'glyph' => 'images'],
        'name'      => 'dam::app.admin.components.layouts.sidebar.library',
        'route'     => 'admin.dam.index',
        'sort'      => 1,
        'icon'      => 'icon-dam-menu',
    ],
    [
        // Re-keyed from 'dam.pages' to a top-level row per the approved
        // navigation design (Library's former children become top-level
        // sidebar rows). 'acl' pins the permission check to the old key so
        // gating stays byte-identical for existing roles.
        'key'       => 'dam_pages',
        'rail'      => ['group' => 'more', 'sort' => 1, 'glyph' => 'file-text'],
        'acl'       => 'dam.pages',
        'name'      => 'dam::app.admin.components.layouts.sidebar.pages',
        'route'     => 'admin.dam.pages.index',
        'sort'      => 2,
        // Own glyph: 'icon-dam-link' is Shared Links' icon, so Web Pages no
        // longer doubles it up.
        'icon'  => 'icon-file',
    ],
    [
        'key'       => 'dam_transfers',
        'rail'      => ['group' => 'away', 'sort' => 3, 'glyph' => 'arrow-left-right'],
        'acl'       => 'dam.transfers',
        'name'      => 'dam::transfers.title',
        'route'     => 'admin.dam.transfers.index',
        'sort'      => 1.8,
        'icon'      => 'icon-dam-upload',
    ],
    [
        // P4 D2: collections (ADR 0011). Gated on 'dam' alone: an audience member needs no key to open what is
        // shared with them; creating and changing collections is gated by the dam.collections.* keys.
        'key'       => 'dam_collections',
        'rail'      => ['group' => 'main', 'sort' => 3, 'glyph' => 'layers'],
        'acl'       => 'dam',
        'name'      => 'dam::app.admin.components.layouts.sidebar.collections',
        'route'     => 'admin.dam.collections.index',
        'sort'      => 3,
        'icon'      => 'icon-catalog',
    ],
    [
        'key'       => 'dam_shares',
        'rail'      => ['group' => 'away', 'sort' => 1, 'glyph' => 'link-2'],
        'acl'       => 'dam.shares',
        'name'      => 'dam::app.admin.components.layouts.sidebar.shares',
        'route'     => 'admin.dam.shared-links.index',
        'sort'      => 3,
        'icon'      => 'icon-dam-link',
    ],
    [
        'key'       => 'dam_tags',
        'rail'      => ['group' => 'more', 'sort' => 2, 'glyph' => 'tags'],
        'acl'       => 'dam.tags',
        'name'      => 'dam::app.admin.components.layouts.sidebar.tags',
        'route'     => 'admin.dam.tags.index',
        'sort'      => 4,
        'icon'      => 'icon-dam-tags',
    ],
    [
        // Admin-only (role permission_type 'all'): the files other users added, waiting to be sorted.
        // 'superuser' is read by AdminServiceProvider::buildAdminMenu(); the routes enforce it too.
        'key'       => 'dam_new_uploads',
        'rail'      => ['group' => 'sorting', 'sort' => 1, 'glyph' => 'inbox'],
        'acl'       => 'dam',
        'superuser' => true,
        'name'      => 'dam::app.admin.components.layouts.sidebar.new-uploads',
        'route'     => 'admin.dam.new-uploads.index',
        'sort'      => 5,
        'icon'      => 'icon-dam-folder',
    ],
    [
        // P8′ (D25): "New from template" and the template manager. The parent key is ticked with either child.
        'key'       => 'dam_templates',
        'rail'      => ['group' => 'more', 'sort' => 3, 'glyph' => 'layout-template'],
        'acl'       => 'dam.template',
        'name'      => 'dam::app.admin.components.layouts.sidebar.templates',
        'route'     => 'admin.dam.templates.index',
        'sort'      => 5,
        'icon'      => 'icon-dam-preview',
    ],
    [
        // P5a (D1): guest upload requests, gated on their own ACL key like Shared Links and Trash.
        'key'       => 'dam_upload_requests',
        'rail'      => ['group' => 'away', 'sort' => 2, 'glyph' => 'mail-plus'],
        'acl'       => 'dam.upload_requests',
        'name'      => 'dam::app.admin.components.layouts.sidebar.upload-requests',
        'route'     => 'admin.dam.upload-requests.index',
        'sort'      => 5,
        'icon'      => 'icon-import',
    ],
    [
        // P2 S4: deleted files, restorable for 30 days. Gated on its own ACL key like Shared Links and Tags.
        'key'       => 'dam_trash',
        'rail'      => ['group' => 'sorting', 'sort' => 3, 'glyph' => 'trash-2'],
        'acl'       => 'dam.trash',
        'name'      => 'dam::app.admin.components.layouts.sidebar.trash',
        'route'     => 'admin.dam.trash.index',
        'sort'      => 6,
        'icon'      => 'icon-delete',
    ],
    [
        // P5c (D16): near-duplicate pairs to stack, merge or mark "Not duplicates".
        'key'       => 'dam_similar',
        'rail'      => ['group' => 'more', 'sort' => 4, 'glyph' => 'scan-search'],
        'acl'       => 'dam.duplicates.view',
        'name'      => 'dam::app.admin.components.layouts.sidebar.similar',
        'route'     => 'admin.dam.similar.index',
        'sort'      => 7,
        'icon'      => 'icon-dam-image',
    ],
    [
        // P5c (D8): open AI tag suggestions across the Library.
        'key'       => 'dam_ai_review',
        'rail'      => ['group' => 'sorting', 'sort' => 2, 'glyph' => 'tags'],
        'acl'       => 'dam.asset.tag',
        'name'      => 'dam::app.admin.components.layouts.sidebar.ai-review',
        'route'     => 'admin.dam.ai-review.index',
        'sort'      => 8,
        'icon'      => 'icon-dam-notes',
    ],
    [
        // Stays nested under 'dam' (key unchanged) so its own gate
        // ('dam' + 'dam.configuration') is byte-identical to today. It moves
        // to the avatar dropdown's Configuration section via 'placement' +
        // 'account_section' (AdminServiceProvider::buildAccountMenu()) rather
        // than the sidebar, and is relabelled "Library" there to name which
        // module's configuration it is, alongside Integrations/Webhooks/
        // System information/System settings. Sort 1 puts it first in that
        // section, per the approved design.
        'key'             => 'dam.configuration',
        'name'            => 'dam::app.admin.components.layouts.sidebar.library',
        'route'           => 'admin.dam.configuration.index',
        'sort'            => 1,
        'icon'            => 'icon-configuration',
        'placement'       => 'account',
        'account_section' => 'configuration',
    ],
    [
        // Staff-only (P1a): in the avatar dropdown's Configuration section, gated on dam.reports.security.
        'key'             => 'dam.reports',
        'acl'             => 'dam.reports.security',
        'name'            => 'dam::app.admin.reports.menu',
        'route'           => 'admin.dam.reports.login-access',
        'sort'            => 2,
        'icon'            => 'icon-dashboard',
        'placement'       => 'account',
        'account_section' => 'configuration',
    ],
    [
        // Product analytics (P1b), gated on dam.reports.view.
        'key'             => 'dam.analytics',
        'acl'             => 'dam.reports.view',
        'name'            => 'dam::app.admin.analytics.menu',
        'route'           => 'admin.dam.reports.engagement',
        'sort'            => 3,
        'icon'            => 'icon-dashboard',
        'placement'       => 'account',
        'account_section' => 'configuration',
    ],
    [
        // Feedback & bug reports triage: in the avatar dropdown's Settings
        // section, gated on dam.feedback.manage. DAMServiceProvider::boot() drops it while dam.feedback.enabled is off.
        'key'             => 'dam.feedback',
        'acl'             => 'dam.feedback.manage',
        'name'            => 'dam::app.admin.feedback.triage.menu',
        'route'           => 'admin.dam.feedback.index',
        'sort'            => 4,
        'account_sort'    => 5,
        'icon'            => 'icon-dam-notes',
        'placement'       => 'account',
        'account_section' => 'settings',
    ],
];
