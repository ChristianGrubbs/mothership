<?php

// The collapsed sidebar rail showed only parent icons, and the DAM fly-out listed Assets/Tags/
// Shared Links/Configuration as bare text. These guard the icons. The former top-level Assets
// shortcut was removed on 2026-09-22 because it duplicated the DAM group on the rail (both opened
// the asset grid); the last test guards against it coming back.
//
// 2026-09-24: Settings and Configuration moved to the avatar menu, and Library's former children
// (Web Pages, Shared Links, Tags) became top-level sidebar rows. Assets folded into Library itself
// (dam.assets removed); dam.configuration stays nested under 'dam' for gating but is tagged for the
// avatar dropdown's Configuration section instead of the sidebar.

function damMenuItem(string $key): ?array
{
    return collect(config('menu.admin'))->firstWhere('key', $key);
}

it('gives every DAM sub-menu entry its own icon', function () {
    $icons = [
        'dam'               => 'icon-dam-menu',
        'dam_pages'         => 'icon-file',
        'dam_shares'        => 'icon-dam-link',
        'dam_tags'          => 'icon-dam-tags',
        'dam.configuration' => 'icon-configuration',
    ];

    foreach ($icons as $key => $icon) {
        $item = damMenuItem($key);

        expect($item)->not->toBeNull("menu entry {$key} must exist");
        expect($item['icon'])->toBe($icon);
    }

    // Web Pages no longer doubles up Shared Links' glyph.
    expect(damMenuItem('dam_pages')['icon'])->not->toBe(damMenuItem('dam_shares')['icon']);
});

it('gives Similar images and AI tag review glyphs of their own, apart from Templates and Tags', function () {
    expect(damMenuItem('dam_similar')['icon'])->not->toBe(damMenuItem('dam_templates')['icon'])
        ->and(damMenuItem('dam_ai_review')['icon'])->not->toBe(damMenuItem('dam_tags')['icon']);
});

it('shows the asset grid once on the rail', function () {
    $topLevel = collect(config('menu.admin'))->filter(fn (array $item) => ! str_contains($item['key'], '.'));

    expect($topLevel->firstWhere('key', 'assets'))->toBeNull();
    expect($topLevel->firstWhere('key', 'dam.assets'))->toBeNull();

    $assetGrid = $topLevel->filter(fn (array $item) => in_array($item['route'], ['admin.dam.index', 'admin.dam.assets.index'], true));

    expect($assetGrid)->toHaveCount(1);
    expect($assetGrid->first()['key'])->toBe('dam');
});

it('re-keys Library\'s former children to top level without changing their gating', function () {
    $reKeyed = [
        'dam_pages'  => 'dam.pages',
        'dam_shares' => 'dam.shares',
        'dam_tags'   => 'dam.tags',
    ];

    foreach ($reKeyed as $newKey => $oldAcl) {
        $item = damMenuItem($newKey);

        expect($item)->not->toBeNull("menu entry {$newKey} must exist");
        expect($item['acl'])->toBe($oldAcl, "menu entry {$newKey} must keep gating on the old key {$oldAcl}");
        expect(str_contains($item['key'], '.'))->toBeFalse("menu entry {$newKey} must be a top-level row");
    }

    // Home, Library, Web Pages, Shared Links, Tags — in that order — on the rail.
    $rail = collect(config('menu.admin'))
        ->filter(fn (array $item) => in_array($item['key'], ['home', 'dam', 'dam_pages', 'dam_shares', 'dam_tags'], true))
        ->sortBy('sort')
        ->pluck('key')
        ->values();

    expect($rail->all())->toBe(['home', 'dam', 'dam_pages', 'dam_shares', 'dam_tags']);
});

it('keeps dam.configuration nested under dam for gating but tags it for the avatar dropdown', function () {
    $item = damMenuItem('dam.configuration');

    expect($item)->not->toBeNull();
    expect($item['placement'])->toBe('account');
    expect($item['account_section'])->toBe('configuration');
});

it('renders sub-menu icons in the sidebar fly-out', function () {
    $blade = file_get_contents(base_path('packages/Mothership/Admin/src/Resources/views/components/layouts/sidebar/row.blade.php'));

    expect($blade)->toContain("\$subMenuItem['icon']");
});
