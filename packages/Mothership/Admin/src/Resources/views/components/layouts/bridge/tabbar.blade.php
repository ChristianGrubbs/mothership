@php
    /*
     * The phone tab bar (Bridge v2, below 720px): Home, Library, Collections, Away and More.
     * Rows come from the same ACL-filtered menu as the rail, so a tab a role cannot open is not drawn.
     * More opens the navigation drawer the top bar owns.
     */
    ['tree' => $tabTree] = app('mothership.admin.menu');
    $tabItems = collect($tabTree->visibleItems())->keyBy('key');
    $tabs = collect([
        ['key' => 'home', 'label' => 'tab-home', 'glyph' => 'house'],
        ['key' => 'dam', 'label' => 'tab-library', 'glyph' => 'images'],
        ['key' => 'dam_collections', 'label' => 'tab-collect', 'glyph' => 'layers'],
        ['key' => 'dam_shares', 'label' => 'tab-away', 'glyph' => 'link-2'],
    ])->filter(fn (array $tab): bool => $tabItems->has($tab['key']));
@endphp

<nav class="bridge-tabbar" aria-label="@lang('admin::app.components.layouts.rail.tabs')" data-bridge-tabbar>
    @foreach ($tabs as $tab)
        @php($tabActive = (bool) $tabTree->getActive($tabItems[$tab['key']]))
        <a href="{{ $tabItems[$tab['key']]['url'] }}" @class(['is-current' => $tabActive]) @if ($tabActive) aria-current="page" @endif>
            <x-admin::bridge.icon :name="$tab['glyph']" :size="22" />
            <span>@lang('admin::app.components.layouts.rail.'.$tab['label'])</span>
        </a>
    @endforeach

    <button type="button" @click="$refs.sidebarMenuDrawer.open()" aria-haspopup="dialog">
        <x-admin::bridge.icon name="ellipsis" :size="22" />
        <span>@lang('admin::app.components.layouts.rail.tab-more')</span>
    </button>
</nav>
