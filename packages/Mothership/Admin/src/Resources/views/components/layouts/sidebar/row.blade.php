{{-- One rail row. The wrapper's "group/item active|inactive" marker and the <a href aria-label title> order are contracts (SidebarActiveRowTest, LayoutChromeTest). --}}
@php($railActive = (bool) $menu->getActive($menuItem))
<div class="bridge-rail-row group/item {{ $railActive ? 'active' : 'inactive' }}" data-menu-item>
    <a
        href="{{ $menuItem['url'] }}"
        aria-label="{{ trans($menuItem['name']) }}"
        title="{{ trans($menuItem['name']) }}"
        @if ($railActive) aria-current="page" @endif
        class="bridge-rail-link"
    >
        @if (! empty($menuItem['rail']['glyph']))
            <x-admin::bridge.icon :name="$menuItem['rail']['glyph']" />
        @else
            <span aria-hidden="true" class="{{ $menuItem['icon'] }} bridge-rail-font-glyph"></span>
        @endif

        <span class="bridge-rail-text" data-replay-safe>@lang($menuItem['name'])</span>
    </a>

    @if (count($menuItem['children']))
        <div class="bridge-rail-flyout {{ $railActive ? 'is-open' : '' }}" data-submenu>
            @foreach ($menuItem['children'] as $subMenuItem)
                <a href="{{ $subMenuItem['url'] }}" class="{{ $menu->getActive($subMenuItem) ? 'is-current' : '' }}" @if ($menu->getActive($subMenuItem)) aria-current="page" @endif>
                    @if (! empty($subMenuItem['icon']))
                        <span class="{{ $subMenuItem['icon'] }} text-lg shrink-0" aria-hidden="true"></span>
                    @endif

                    <span data-replay-safe>@lang($subMenuItem['name'])</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
