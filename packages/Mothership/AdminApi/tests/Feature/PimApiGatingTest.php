<?php

use Mothership\Attribute\Models\AttributeFamily;

beforeEach(function () {
    $this->headers = $this->getAuthenticationHeaders();
});

describe('when pim_enabled is false (default)', function () {
    beforeEach(function () {
        config(['mothership.pim_enabled' => false]);
    });

    it('returns 403 on product GET and POST API routes when pim is disabled', function () {
        $this->withHeaders($this->headers)
            ->json('GET', route('admin.api.products.index'))
            ->assertForbidden();

        $family = AttributeFamily::first();
        $payload = [
            'sku'    => 'test_gating_sku',
            'parent' => null,
            'family' => $family?->code ?? 'default',
            'values' => [
                'common' => [
                    'sku' => 'test_gating_sku',
                ],
            ],
        ];

        $this->withHeaders($this->headers)
            ->json('POST', route('admin.api.products.store'), $payload)
            ->assertForbidden();
    });

    it('returns 403 on other PIM REST API routes when pim is disabled', function () {
        foreach ([
            'admin.api.categories.index',
            'admin.api.attributes.index',
            'admin.api.attribute_groups.index',
            'admin.api.families.index',
            'admin.api.measurement.index',
        ] as $routeName) {
            $this->withHeaders($this->headers)
                ->json('GET', route($routeName))
                ->assertForbidden();
        }
    });

    it('returns 403 on every PIM REST API write family when pim is disabled', function () {
        foreach ([
            'admin.api.categories.store',
            'admin.api.attributes.store',
            'admin.api.attribute_groups.store',
            'admin.api.families.store',
            'admin.api.measurement.store',
        ] as $routeName) {
            $this->withHeaders($this->headers)
                ->json('POST', route($routeName))
                ->assertForbidden();
        }
    });

    it('allows non-PIM REST API routes like locales, channels, and currencies when pim is disabled', function () {
        $this->withHeaders($this->headers)
            ->json('GET', route('admin.api.locales.index'))
            ->assertOk();

        $this->withHeaders($this->headers)
            ->json('GET', route('admin.api.channels.index'))
            ->assertOk();

        $this->withHeaders($this->headers)
            ->json('GET', route('admin.api.currencies.index'))
            ->assertOk();
    });
});

describe('when pim_enabled is true', function () {
    beforeEach(function () {
        config(['mothership.pim_enabled' => true]);
    });

    it('returns 200 on product GET API route when pim is enabled', function () {
        $this->withHeaders($this->headers)
            ->json('GET', route('admin.api.products.index'))
            ->assertOk();
    });

    it('allows product POST API route when pim is enabled', function () {
        $family = AttributeFamily::first();
        $payload = [
            'sku'    => 'test_gating_sku_enabled',
            'parent' => null,
            'family' => $family?->code ?? 'default',
            'values' => [
                'common' => [
                    'sku' => 'test_gating_sku_enabled',
                ],
            ],
        ];

        $this->withHeaders($this->headers)
            ->json('POST', route('admin.api.products.store'), $payload)
            ->assertStatus(201);
    });

    it('allows every PIM REST API read family when pim is enabled', function () {
        foreach ([
            'admin.api.categories.index',
            'admin.api.attributes.index',
            'admin.api.attribute_groups.index',
            'admin.api.families.index',
            'admin.api.measurement.index',
        ] as $routeName) {
            expect($this->withHeaders($this->headers)->json('GET', route($routeName))->status())
                ->not->toBe(403);
        }
    });

    it('allows every PIM REST API write family to reach its own validation when pim is enabled', function () {
        foreach ([
            'admin.api.categories.store',
            'admin.api.attributes.store',
            'admin.api.attribute_groups.store',
            'admin.api.families.store',
            'admin.api.measurement.store',
        ] as $routeName) {
            expect($this->withHeaders($this->headers)->json('POST', route($routeName))->status())
                ->not->toBe(403);
        }
    });
});
