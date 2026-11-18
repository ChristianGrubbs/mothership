<?php

return [
    /**
     * A small catalog team, modelled on how a mid-sized brand splits the work:
     * one owner, someone who owns product data, someone who owns copy and
     * translation, a merchandiser who only touches the storefront channel, a
     * read-only compliance seat, and a machine account for integrations.
     */
    'roles' => [
        [
            'name'            => 'Administrator',
            'description'     => 'Full access to the catalog, settings and user management.',
            'permission_type' => 'all',
        ],
        [
            'name'            => 'Catalog Manager',
            'description'     => 'Owns product data, families, attributes and categories. Cannot change users, roles or system settings.',
            'permission_type' => 'custom',
            'permissions'     => [
                'dashboard', 'catalog', 'catalog.products', 'catalog.products.create', 'catalog.products.edit',
                'catalog.products.mass-delete', 'catalog.products.copy', 'catalog.products.quick-export',
                'catalog.products.bulk-edit', 'catalog.categories', 'catalog.categories.create',
                'catalog.categories.edit', 'catalog.categories.delete', 'catalog.attributes',
                'catalog.attributes.create', 'catalog.attributes.edit', 'catalog.families',
                'catalog.families.create', 'catalog.families.edit', 'catalog.attribute_groups',
                'catalog.category_fields', 'catalog.association_types', 'settings.data_transfer',
                'settings.data_transfer.imports', 'settings.data_transfer.exports',
            ],
        ],
        [
            'name'            => 'Content & Localization',
            'description'     => 'Writes and translates product copy. Read-only on structure: cannot add or remove attributes, families or categories.',
            'permission_type' => 'custom',
            'permissions'     => [
                'dashboard', 'catalog', 'catalog.products', 'catalog.products.edit',
                'catalog.categories', 'catalog.categories.edit', 'catalog.attributes', 'catalog.families',
                'settings.magic_ai',
            ],
        ],
        [
            'name'            => 'Channel Merchandiser',
            'description'     => 'Curates what the storefront shows: categories and product enrichment. No structural or user administration.',
            'permission_type' => 'custom',
            'permissions'     => [
                'dashboard', 'catalog', 'catalog.products', 'catalog.products.edit',
                'catalog.categories', 'catalog.categories.edit', 'settings.channels',
            ],
        ],
        [
            'name'            => 'Compliance Auditor',
            'description'     => 'Read-only access for compliance review: product data and the audit trail.',
            'permission_type' => 'custom',
            'permissions'     => [
                'dashboard', 'catalog', 'catalog.products', 'catalog.categories',
                'catalog.attributes', 'catalog.families',
            ],
        ],
        [
            'name'            => 'Integration Service',
            'description'     => 'Machine account for the API and scheduled imports. No admin UI beyond data transfer.',
            'permission_type' => 'custom',
            'permissions'     => [
                'dashboard', 'catalog', 'catalog.products', 'catalog.products.create', 'catalog.products.edit',
                'settings.data_transfer', 'settings.data_transfer.imports', 'settings.data_transfer.exports',
                'settings.integration',
            ],
        ],
    ],

    /**
     * Passwords are seeded to a single well-known value; a demo install is not
     * a place for per-user secrets, and the credentials are printed by the
     * installer anyway.
     */
    'users' => [
        [
            'name'     => 'Mara Lindqvist',
            'email'    => 'catalog.manager@example.com',
            'role'     => 'Catalog Manager',
            'timezone' => 'Europe/Stockholm',
            'avatar'   => 'user-catalog-manager',
        ],
        [
            'name'     => 'Tomás Ferreira',
            'email'    => 'content@example.com',
            'role'     => 'Content & Localization',
            'timezone' => 'Europe/Lisbon',
            'avatar'   => 'user-content',
        ],
        [
            'name'     => 'Julia Brandt',
            'email'    => 'merchandiser@example.com',
            'role'     => 'Channel Merchandiser',
            'timezone' => 'Europe/Berlin',
            'avatar'   => 'user-merchandiser',
        ],
        [
            'name'     => 'Aurélie Moreau',
            'email'    => 'compliance@example.com',
            'role'     => 'Compliance Auditor',
            'timezone' => 'Europe/Paris',
            'avatar'   => 'user-compliance',
        ],
        [
            'name'     => 'Integration Service',
            'email'    => 'integration@example.com',
            'role'     => 'Integration Service',
            'timezone' => 'UTC',
            'avatar'   => 'user-integration',
        ],
    ],

    'webhooks' => [
        [
            'name'   => 'Storefront — product published',
            'url'    => 'https://storefront.example.com/hooks/mothership/product',
            'events' => ['product.created', 'product.updated'],
            'active' => false,
        ],
        [
            'name'   => 'ERP — category tree changed',
            'url'    => 'https://erp.example.com/integrations/mothership/categories',
            'events' => ['category.created', 'category.updated', 'category.deleted'],
            'active' => false,
        ],
    ],
];
