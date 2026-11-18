<?php

return [
    /**
     * Dashboard.
     */
    [
        'key'        => 'dashboard',
        'name'       => 'admin::app.components.layouts.sidebar.dashboard',
        'route'      => 'admin.dashboard.index',
        'sort'       => 1,
        'icon'       => 'icon-dashboard',
    ],

    /**
     * Catalog.
     */
    [
        'key'        => 'catalog',
        'name'       => 'admin::app.components.layouts.sidebar.catalog',
        'route'      => 'admin.catalog.products.index',
        'sort'       => 3,
        'icon'       => 'icon-catalog',
    ], [
        'key'        => 'catalog.products',
        'name'       => 'admin::app.components.layouts.sidebar.products',
        'route'      => 'admin.catalog.products.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'catalog.categories',
        'name'       => 'admin::app.components.layouts.sidebar.categories',
        'route'      => 'admin.catalog.categories.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'catalog.category_fields',
        'name'       => 'admin::app.components.layouts.sidebar.category_fields',
        'route'      => 'admin.catalog.category_fields.index',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'catalog.attributes',
        'name'       => 'admin::app.components.layouts.sidebar.attributes',
        'route'      => 'admin.catalog.attributes.index',
        'sort'       => 4,
        'icon'       => '',
    ], [
        'key'        => 'catalog.attribute_groups',
        'name'       => 'admin::app.components.layouts.sidebar.attribute-groups',
        'route'      => 'admin.catalog.attribute.groups.index',
        'sort'       => 5,
        'icon'       => '',
    ], [
        'key'        => 'catalog.families',
        'name'       => 'admin::app.components.layouts.sidebar.attribute-families',
        'route'      => 'admin.catalog.families.index',
        'sort'       => 6,
        'icon'       => '',
    ], [
        'key'        => 'catalog.association_types',
        'name'       => 'admin::app.components.layouts.sidebar.association_types',
        'route'      => 'admin.catalog.association_types.index',
        'sort'       => 7,
        'icon'       => '',
    ],

    /**
     * Data Transfer.
     */
    [
        'key'        => 'data_transfer',
        'name'       => 'admin::app.components.layouts.sidebar.data-transfer',
        'route'      => 'admin.settings.data_transfer.tracker.index',
        'sort'       => 8,
        'icon'       => 'icon-data-transfer',
    ],  [
        'key'        => 'data_transfer.job_tracker',
        'name'       => 'admin::app.components.layouts.sidebar.tracker',
        'route'      => 'admin.settings.data_transfer.tracker.index',
        'sort'       => 1,
        'icon'       => '',
    ], [
        'key'        => 'data_transfer.imports',
        'name'       => 'admin::app.components.layouts.sidebar.imports',
        'route'      => 'admin.settings.data_transfer.imports.index',
        'sort'       => 2,
        'icon'       => '',
    ], [
        'key'        => 'data_transfer.export',
        'name'       => 'admin::app.components.layouts.sidebar.exports',
        'route'      => 'admin.settings.data_transfer.exports.index',
        'sort'       => 3,
        'icon'       => '',
    ],

    /**
     * Settings.
     */
    [
        'key'        => 'settings',
        'name'       => 'admin::app.components.layouts.sidebar.settings',
        'route'      => 'admin.settings.channels.index',
        'sort'       => 8,
        'icon'       => 'icon-setting',
        // Rendered in the avatar dropdown's Settings section instead of the
        // sidebar; skipped by Tree::visibleItems(). See AdminServiceProvider::
        // buildAccountMenu().
        'placement'  => 'account',
    ], [
        // 'sort' (unchanged) still drives the settings tabs, breadcrumbs and which
        // child 'settings' delegates its own url to; 'account_sort' is read only by
        // AdminServiceProvider::buildAccountMenu() to place this in the approved
        // Users, Roles, Groups, Channels order in the avatar dropdown.
        'key'          => 'settings.channels',
        'name'         => 'admin::app.components.layouts.sidebar.channels',
        'route'        => 'admin.settings.channels.index',
        'sort'         => 6,
        'account_sort' => 4,
        'icon'         => '',
    ], [
        'key'          => 'settings.users',
        'name'         => 'admin::app.components.layouts.sidebar.users',
        'route'        => 'admin.settings.users.index',
        'sort'         => 7,
        'account_sort' => 1,
        'icon'         => '',
    ], [
        'key'          => 'settings.roles',
        'name'         => 'admin::app.components.layouts.sidebar.roles',
        'route'        => 'admin.settings.roles.index',
        'sort'         => 8,
        'account_sort' => 2,
        'icon'         => '',
    ],
    /**
     * Configuration.
     */
    [
        'key'    => 'configuration',
        'name'   => 'admin::app.components.layouts.sidebar.configure',
        'route'  => 'admin.configuration.integrations.index',
        'sort'   => 10,
        'icon'   => 'icon-configuration',
        // Rendered in the avatar dropdown's Configuration section instead of
        // the sidebar; skipped by Tree::visibleItems(). See
        // AdminServiceProvider::buildAccountMenu().
        'placement'  => 'account',
    ], [
        'key'        => 'configuration.system_information',
        'name'       => 'admin::app.components.layouts.sidebar.system-information',
        'route'      => 'admin.configuration.system.information',
        'sort'       => 3,
        'icon'       => '',
    ], [
        'key'        => 'configuration.system_settings',
        'name'       => 'admin::app.components.layouts.sidebar.system-settings',
        'route'      => 'admin.settings.system.index',
        'sort'       => 4,
        'icon'       => '',
    ],

    /**
     * Help.
     */
    [
        'key'       => 'help',
        'rail'      => ['glyph' => 'circle-help'],
        // Help is open to every signed-in admin (no ACL row); 'home' resolves to "authenticated".
        'acl'       => 'home',
        'name'      => 'admin::app.components.layouts.sidebar.help',
        'route'     => 'admin.help.index',
        'sort'      => 99999,
        'icon'      => 'icon-information',
        // Pinned to the bottom of the sidebar/drawer, outside the scrolling
        // list, just above the collapse toggle. See Tree::pinnedItems().
        'placement' => 'pinned',
    ],
];
