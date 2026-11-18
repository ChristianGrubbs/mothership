<?php

// Regression for the "two active rows" bug (Codex review round 1 on PR #60): Library's own url
// (/admin/dam) is a prefix of its former children's urls, which are now top-level siblings
// (/admin/dam/pages, /admin/dam/shared-links, /admin/dam/tags) instead of nested children.
// Tree::getActive() used to OR a broad url-prefix check in with the precise current-key check, so
// viewing e.g. Web Pages also lit up Library's row. Fixed by letting the resolved current key — which
// Tree::add()/AdminServiceProvider already compute precisely for every page — win outright once it
// names anything, falling back to raw url matching only when nothing named a key at all.

function sidebarHtmlForActiveRowTest(string $html): string
{
    $startPos = strpos($html, 'id="mothership-sidebar"');
    expect($startPos)->not->toBeFalse();

    $endPos = strpos($html, 'v-sidebar-collapse', $startPos);
    expect($endPos)->not->toBeFalse();

    return substr($html, $startPos, $endPos - $startPos);
}

// Whether the sidebar row whose link is $href is the active one. The wrapping div's class carries
// either "active" or "inactive" right after the "group/item " marker (HubSidebarActiveTest guards the
// same "group/item active" string for the exact-active case); walking back from the href to the
// nearest preceding marker and comparing that fixed-length slice avoids depending on the template's
// exact indentation/newlines.
function sidebarRowIsActive(string $sidebarHtml, string $href): bool
{
    $hrefPos = strpos($sidebarHtml, 'href="'.$href.'"');
    expect($hrefPos)->not->toBeFalse("expected to find a sidebar row linking to {$href}");

    $markerPos = strrpos(substr($sidebarHtml, 0, $hrefPos), 'group/item ');
    expect($markerPos)->not->toBeFalse("expected a 'group/item' wrapper before {$href}");

    return substr($sidebarHtml, $markerPos, strlen('group/item active')) === 'group/item active';
}

it('activates exactly one top-level sidebar row', function (string $routeName) {
    $this->loginAsAdmin();

    $rail = [
        'dam'       => route('admin.dam.index'),
        'dam_pages' => route('admin.dam.pages.index'),
        'dam_share' => route('admin.dam.shared-links.index'),
        'dam_tags'  => route('admin.dam.tags.index'),
    ];

    // Both DAMController::index and AssetController::index render the same view, so the asset grid
    // route also resolves to Library's own url and is expected to activate the same row.
    $expectedActiveKey = match ($routeName) {
        'admin.dam.index', 'admin.dam.assets.index'  => 'dam',
        'admin.dam.pages.index'                      => 'dam_pages',
        'admin.dam.shared-links.index'               => 'dam_share',
        'admin.dam.tags.index'                       => 'dam_tags',
    };

    $html = $this->get(route($routeName))->assertOk()->getContent();
    $sidebar = sidebarHtmlForActiveRowTest($html);

    expect(substr_count($sidebar, 'group/item active'))->toBe(1);

    foreach ($rail as $key => $href) {
        expect(sidebarRowIsActive($sidebar, $href))->toBe($key === $expectedActiveKey, "row for {$key} ({$href})");
    }
})->with([
    'Library index'  => ['admin.dam.index'],
    'an asset route' => ['admin.dam.assets.index'],
    'Web Pages'      => ['admin.dam.pages.index'],
    'Shared Links'   => ['admin.dam.shared-links.index'],
    'Tags'           => ['admin.dam.tags.index'],
]);
