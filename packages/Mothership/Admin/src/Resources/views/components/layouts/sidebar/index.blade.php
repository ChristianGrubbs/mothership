@php
    /*
     * The Bridge rail (redesign v2, 02-information-architecture.md): the lockup, Home / Library /
     * Collections, then "Away from ship" and "Sorting", rows not placed yet under "More", and at the
     * foot Connections, Settings, Help and the storage meter. Each menu item's `rail` key places it.
     */
    $railGroups = ['main' => [], 'away' => [], 'sorting' => [], 'more' => []];

    foreach ($menu->visibleItems() as $railItem) {
        $railGroups[$railItem['rail']['group'] ?? 'more'][] = $railItem;
    }

    foreach ($railGroups as $railGroup => $railRows) {
        usort($railRows, fn (array $a, array $b): int => [$a['rail']['sort'] ?? 99, $a['sort'] ?? 0] <=> [$b['rail']['sort'] ?? 99, $b['sort'] ?? 0]);
        $railGroups[$railGroup] = $railRows;
    }

    $railLabels = [
        'away'    => trans('admin::app.components.layouts.rail.away'),
        'sorting' => trans('admin::app.components.layouts.rail.sorting'),
        'more'    => trans('admin::app.components.layouts.rail.more'),
    ];

    $railFoot = array_values(array_filter([
        Route::has('admin.configuration.integrations.index') && bouncer()->hasPermission('configuration.integrations')
            ? ['key' => 'connections', 'name' => trans('admin::app.components.layouts.rail.connections'), 'url' => route('admin.configuration.integrations.index'), 'glyph' => 'plug', 'active' => request()->routeIs('admin.configuration.integrations.*')]
            : null,
        Route::has('admin.account.edit')
            ? ['key' => 'settings', 'name' => trans('admin::app.components.layouts.rail.settings'), 'url' => route('admin.account.edit'), 'glyph' => 'settings-2', 'active' => request()->routeIs('admin.account.*')]
            : null,
    ]));

    $railStorageUrl = Route::has('admin.home.data') ? route('admin.home.data', ['widget' => 'storage']) : null;
@endphp

<div id="mothership-sidebar" class="bridge-rail flex flex-col shrink-0 h-full z-[10001] max-[719px]:hidden" aria-label="@lang('admin::app.components.layouts.rail.label')">
    <a href="{{ $adminLandingUrl ?? route('admin.home.index') }}" class="bridge-rail-word" aria-label="{{ config('documentation.name') }}">
        <img src="{{ mothership_asset('images/logo.svg') }}" width="236" height="32" class="block dark:hidden" data-mothership-logo alt="{{ config('documentation.name') }}" />
        <img src="{{ mothership_asset('images/dark_logo.svg') }}" width="236" height="32" class="hidden dark:block" data-mothership-logo alt="{{ config('documentation.name') }}" />
    </a>

    <div id="mothership-sidebar-scroll" class="bridge-rail-scroll flex-1 min-h-0 overflow-y-auto overflow-x-hidden">
        <nav class="bridge-rail-nav" aria-label="@lang('admin::app.components.layouts.rail.label')">
            @foreach ($railGroups as $railGroup => $railRows)
                @continue (! count($railRows))

                @if ($railGroup === 'more')
                    <details class="bridge-rail-more" data-rail-group="more" @if (collect($railRows)->contains(fn (array $railRow): bool => (bool) $menu->getActive($railRow))) open @endif>
                        <summary class="bridge-rail-link" aria-label="{{ $railLabels['more'] }}" title="{{ $railLabels['more'] }}">
                            <x-admin::bridge.icon name="ellipsis" />
                            <span class="bridge-rail-text">{{ $railLabels['more'] }}</span>
                            <span class="bridge-rail-more-chevron"><x-admin::bridge.icon name="chevron-down" :size="16" /></span>
                        </summary>

                        @foreach ($railRows as $menuItem)
                            @include('admin::components.layouts.sidebar.row', ['menuItem' => $menuItem])
                        @endforeach
                    </details>

                    @continue
                @endif

                @if ($railGroup !== 'main')
                    <span class="bridge-rail-group" data-rail-group="{{ $railGroup }}">{{ $railLabels[$railGroup] }}</span>
                @endif

                @foreach ($railRows as $menuItem)
                    @include('admin::components.layouts.sidebar.row', ['menuItem' => $menuItem])
                @endforeach
            @endforeach
        </nav>
    </div>

    <div class="bridge-rail-foot">
        <nav class="bridge-rail-nav">
            @foreach ($railFoot as $footItem)
                <div class="bridge-rail-row group/item {{ $footItem['active'] ? 'active' : 'inactive' }}" data-menu-item data-rail-foot="{{ $footItem['key'] }}">
                    <a
                        href="{{ $footItem['url'] }}"
                        aria-label="{{ $footItem['name'] }}"
                        title="{{ $footItem['name'] }}"
                        @if ($footItem['active']) aria-current="page" @endif
                        class="bridge-rail-link"
                    >
                        <x-admin::bridge.icon :name="$footItem['glyph']" />
                        <span class="bridge-rail-text">{{ $footItem['name'] }}</span>
                    </a>
                </div>
            @endforeach

            @foreach ($menu->pinnedItems() as $menuItem)
                @include('admin::components.layouts.sidebar.row', ['menuItem' => $menuItem])
            @endforeach
        </nav>

        @if ($railStorageUrl)
            <v-rail-storage url="{{ $railStorageUrl }}"></v-rail-storage>
        @endif
    </div>

    <v-sidebar-collapse></v-sidebar-collapse>
</div>

@pushOnce('scripts')
    <script type="text/x-template" id="v-sidebar-collapse-template">
        <button
            type="button"
            class="bridge-rail-collapse"
            :aria-label="isCollapsed ? expandLabel : collapseLabel"
            :title="isCollapsed ? expandLabel : collapseLabel"
            :aria-expanded="isCollapsed ? 'false' : 'true'"
            @click="toggle"
        >
            <span v-if="isCollapsed" class="flex"><x-admin::bridge.icon name="panel-left-open" /></span>
            <span v-else class="flex"><x-admin::bridge.icon name="panel-left-close" /></span>
        </button>
    </script>

    {{-- The storage meter: 20 cells, filled by the share of the asset volume in use (HomeController::data, widget "storage"). Hidden until it has a reading. --}}
    <script type="text/x-template" id="v-rail-storage-template">
        <div class="bridge-rail-store" role="img" :aria-label="summary" v-if="ready" data-rail-storage>
            <div class="bridge-rail-store-row"><span class="bridge-mono">@lang('admin::app.components.layouts.rail.storage')</span><span class="bridge-mono" v-text="percent + '%'"></span></div>
            <div class="bridge-cells" aria-hidden="true"><i v-for="cell in 20" :key="cell" :class="{ on: cell <= filled }"></i></div>
            <p v-text="summary"></p>
        </div>
    </script>

    <script type="module">
        app.component('v-rail-storage', {
            template: '#v-rail-storage-template',

            props: ['url'],

            data() {
                return { ready: false, used: 0, total: 0 };
            },

            computed: {
                percent() {
                    return this.total ? Math.round((this.used / this.total) * 100) : 0;
                },

                filled() {
                    return this.total ? Math.max(this.used > 0 ? 1 : 0, Math.round((this.used / this.total) * 20)) : 0;
                },

                summary() {
                    const size = (bytes) => {
                        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                        let value = bytes, unit = 0;
                        while (value >= 1000 && unit < units.length - 1) { value /= 1000; unit++; }
                        return `${value >= 100 || unit === 0 ? Math.round(value) : value.toFixed(value >= 10 ? 1 : 2)} ${units[unit]}`;
                    };

                    return @json(trans('admin::app.components.layouts.rail.storage-used')).replace(':used', size(this.used)).replace(':total', size(this.total));
                },
            },

            mounted() {
                fetch(this.url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then((response) => response.ok ? response.json() : null)
                    .then((data) => {
                        if (! data?.available || ! data.total) {
                            return;
                        }

                        this.total = Number(data.total);
                        this.used = Math.max(0, Number(data.total) - Number(data.free ?? 0));
                        this.ready = true;
                    })
                    .catch(() => {});
            },
        });

        app.component('v-sidebar-collapse', {
            template: '#v-sidebar-collapse-template',

            data() {
                return {
                    isCollapsed: {{ (int) (request()->cookie('sidebar_collapsed') ?? 0) }},

                    collapseLabel: @json(trans('admin::app.components.layouts.side-rail.collapse')),

                    expandLabel: @json(trans('admin::app.components.layouts.side-rail.expand')),
                }
            },

            methods: {
                toggle() {
                    this.isCollapsed = this.isCollapsed ? 0 : 1;

                    const expiryDate = new Date();

                    expiryDate.setMonth(expiryDate.getMonth() + 1);

                    document.cookie = 'sidebar_collapsed=' + this.isCollapsed + '; path=/; expires=' + expiryDate.toGMTString();

                    this.$root.$refs.appLayout.classList.toggle('sidebar-collapsed', Boolean(this.isCollapsed));
                },
            },
        });
    </script>

    <script>
        (() => {
            /**
             * Hover-intent for the detached fly-out submenus.
             *
             * The trigger row is far shorter than the fly-out, so moving the pointer
             * diagonally onto a lower sub-item crosses a strip where neither element
             * is hovered; a pure `group-hover` submenu closes before it can be clicked.
             * Holding it open for a short grace period bridges that strip.
             *
             * The collapsed/inactive variants hide the fly-out with `display:none !important`,
             * so the grace period must be forced with `!important` too, otherwise the
             * inline style loses and the grace period silently does nothing.
             */
            const SUBMENU_HIDE_DELAY = 400;

            /**
             * Switching to another parent is deferred: the diagonal path from a trigger
             * row down to a low sub-item sweeps across the icons below it, and swapping
             * fly-outs on contact would drop a new panel over the item being aimed at.
             */
            const SUBMENU_SWITCH_DELAY = 300;

            const VIEWPORT_MARGIN = 8;

            const MENU_ITEM_SELECTOR = '#mothership-sidebar [data-menu-item]';

            const hideTimers = new WeakMap();

            let openSubMenu = null;

            let switchTimer = null;

            const isDetached = (subMenu) => getComputedStyle(subMenu).position === 'fixed';

            const positionSubMenu = (menuItem, subMenu) => {
                if (! isDetached(subMenu)) {
                    subMenu.style.removeProperty('top');

                    return;
                }

                const triggerTop = menuItem.getBoundingClientRect().top;

                const highestTop = window.innerHeight - subMenu.offsetHeight - VIEWPORT_MARGIN;

                subMenu.style.top = `${Math.max(VIEWPORT_MARGIN, Math.min(triggerTop, highestTop))}px`;
            };

            const close = (subMenu) => {
                clearTimeout(hideTimers.get(subMenu));

                subMenu.style.removeProperty('display');
                subMenu.style.removeProperty('top');

                if (openSubMenu === subMenu) {
                    openSubMenu = null;
                }
            };

            const open = (menuItem) => {
                const subMenu = menuItem.querySelector('[data-submenu]');

                if (! subMenu) {
                    return;
                }

                if (openSubMenu && openSubMenu !== subMenu) {
                    close(openSubMenu);
                }

                clearTimeout(hideTimers.get(subMenu));

                // Reveal first: a hidden element measures 0px, which would defeat the clamp.
                subMenu.style.setProperty('display', 'grid', 'important');

                positionSubMenu(menuItem, subMenu);

                openSubMenu = subMenu;
            };

            const scheduleClose = (subMenu) => {
                clearTimeout(hideTimers.get(subMenu));

                hideTimers.set(subMenu, setTimeout(() => close(subMenu), SUBMENU_HIDE_DELAY));
            };

            const requestOpen = (menuItem) => {
                clearTimeout(switchTimer);

                const subMenu = menuItem.querySelector('[data-submenu]');

                // Pointing at the fly-out that is already up, or at the first one: no wait.
                if (! openSubMenu || ! subMenu || openSubMenu === subMenu) {
                    open(menuItem);

                    return;
                }

                // Hold the current fly-out until the swap lands, otherwise the pointer
                // crosses a stretch where neither panel is on screen.
                clearTimeout(hideTimers.get(openSubMenu));

                switchTimer = setTimeout(() => open(menuItem), SUBMENU_SWITCH_DELAY);
            };

            /**
             * From here the script owns fly-out visibility. The CSS `group-hover` reveal
             * is instant, so a second fly-out would pop up over one still held open by its
             * grace period. Injecting the override from JS keeps the CSS-only behaviour
             * intact when the script does not run, and the `#id` beats the utility classes
             * while the inline `!important` used to open a fly-out still beats this.
             */
            const style = document.createElement('style');

            style.textContent = `
                .sidebar-collapsed #mothership-sidebar [data-submenu],
                #mothership-sidebar [data-menu-item].inactive [data-submenu] {
                    display: none !important;
                }
            `;

            document.head.appendChild(style);

            document.addEventListener('mouseover', (event) => {
                const menuItem = event.target.closest?.(MENU_ITEM_SELECTOR);

                if (menuItem) {
                    requestOpen(menuItem);
                }
            });

            document.addEventListener('mouseout', (event) => {
                const menuItem = event.target.closest?.(MENU_ITEM_SELECTOR);

                // The fly-out is a descendant of the trigger, so moving onto it is not a leave.
                if (! menuItem || (event.relatedTarget && menuItem.contains(event.relatedTarget))) {
                    return;
                }

                // A pending switch is abandoned once the pointer leaves, so the panel
                // that is actually on screen is the one that has to go.
                clearTimeout(switchTimer);

                if (openSubMenu) {
                    scheduleClose(openSubMenu);
                }
            });

            document.addEventListener('focusin', (event) => {
                const menuItem = event.target.closest?.(MENU_ITEM_SELECTOR);

                if (menuItem) {
                    open(menuItem);
                } else if (openSubMenu) {
                    close(openSubMenu);
                }
            });

            const repositionOpenSubMenu = () => {
                if (! openSubMenu) {
                    return;
                }

                positionSubMenu(openSubMenu.closest('[data-menu-item]'), openSubMenu);
            };

            window.addEventListener('resize', repositionOpenSubMenu);
            document.addEventListener('scroll', repositionOpenSubMenu, { capture: true, passive: true });
        })();
    </script>
@endpushOnce
