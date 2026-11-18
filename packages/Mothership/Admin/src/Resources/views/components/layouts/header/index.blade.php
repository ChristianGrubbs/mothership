@php
    $admin = auth()->guard('admin')->user();
    $themeCookie = request()->cookie('dark_mode');
    $themeServer = in_array($themeCookie, ['1', 'dark'], true) ? 'dark' : (in_array($themeCookie, ['0', 'light'], true) ? 'light' : null);
    $themeOptions = [
        'dark'  => ['label' => trans('admin::app.components.layouts.rail.theme-dark'), 'glyph' => 'moon'],
        'light' => ['label' => trans('admin::app.components.layouts.rail.theme-light'), 'glyph' => 'sun'],
    ];
@endphp

{{--
    The Bridge top bar (redesign v2): no fill and no rule, it sits on the ground above the page.
    Wide screens show the command field and the theme segment; below 720px it becomes the phone bar
    (lockup, search, bell, account) and the tab bar takes the navigation.
--}}
<header class="mothership-header bridge-top relative shrink-0 z-[10001]" data-fold-split>
    <a href="{{ $adminLandingUrl ?? route('admin.dashboard.index') }}" class="bridge-top-word" aria-label="{{ config('documentation.name') }}">
        @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
            <img class="h-6 w-auto" src="{{ Storage::url($logo) }}" data-mothership-logo title="{{ config('documentation.name') }}" alt="{{ config('documentation.name') }}" />
        @else
            <img src="{{ mothership_asset('images/logo.svg') }}" width="125" height="24" class="theme-logo-light block dark:hidden" data-mothership-logo title="{{ config('documentation.name') }}" alt="{{ config('documentation.name') }}" />
            <img src="{{ mothership_asset('images/dark_logo.svg') }}" width="125" height="24" class="theme-logo-dark hidden dark:block" data-mothership-logo title="{{ config('documentation.name') }}" alt="{{ config('documentation.name') }}" />
        @endif
    </a>

    @if (Route::has('admin.dam.assets.index') && bouncer()->hasPermission('dam.asset.view'))
        <form action="{{ route('admin.dam.assets.index') }}" method="GET" accept-charset="UTF-8" role="search" aria-label="Search assets" data-asset-search data-no-ajax-nav class="dam-header-search bridge-cmd">
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="search_submit" value="1">
            <button type="button" class="dam-header-search-toggle" data-asset-search-toggle aria-expanded="false" aria-controls="dam-header-search-fields" aria-label="Search assets"><x-admin::bridge.icon name="search" /></button>
            <div id="dam-header-search-fields" class="dam-search-fields">
                <x-admin::bridge.icon name="search" class="bridge-cmd-glyph" />
                <label for="dam-header-query" class="sr-only">Search all assets</label>
                <input id="dam-header-query" name="search" type="search" maxlength="255" placeholder="@lang('admin::app.components.layouts.rail.search')" value="{{ request('search', request('filters.all.0', '')) }}" autocomplete="off" aria-keyshortcuts="Meta+K Control+K">
                <button type="submit" class="sr-only" aria-label="Submit asset search">Search</button>
                <button type="button" data-asset-search-clear aria-label="Clear asset search"><x-admin::bridge.icon name="x" :size="16" /></button>
                <kbd class="bridge-kbd" aria-hidden="true" data-cmd-kbd><span class="bridge-kbd-mac">⌘K</span><span class="bridge-kbd-pc">Ctrl K</span></kbd>
            </div>
        </form>
    @endif

    <div class="bridge-top-actions">
        @if (bouncer()->hasPermission('dam.asset.upload') && bouncer()->hasPermission('dam.directory.index'))
            {{-- Only Home shows this: its stylesheet reveals the button and its script opens the upload dialog. --}}
            <a href="{{ route('admin.dam.assets.index') }}" class="bridge-btn-card bridge-top-upload hidden" data-bridge-upload>
                <x-admin::bridge.icon name="upload" :size="18" />
                <span>@lang('admin::app.components.layouts.header.upload')</span>
            </a>
        @endif

        @if ((bool) config('dam.feedback.enabled') && app(\Mothership\DAM\Services\Feedback\FeedbackAccess::class)->canSubmit($admin))
            {{-- Feedback & bug reports: opens the layout's report dialog (dam::components.feedback.dialog); Alt+Shift+B too. --}}
            <button
                type="button"
                class="bridge-icon-btn max-[719px]:hidden"
                aria-label="@lang('dam::app.admin.feedback.ui.open')"
                aria-keyshortcuts="Alt+Shift+B"
                title="@lang('dam::app.admin.feedback.ui.openShort') (Alt+Shift+B)"
                data-dam-feedback-open
            >
                <x-dam::feedback.icon :size="20" />
            </button>
        @endif

        <v-dark>
            <div class="bridge-seg" role="group" aria-label="@lang('admin::app.components.layouts.rail.theme')" data-theme-segment>
                @foreach ($themeOptions as $themeMode => $themeOption)
                    <button type="button" class="bridge-seg-opt" data-theme-option="{{ $themeMode }}" aria-pressed="{{ $themeServer === $themeMode ? 'true' : 'false' }}" title="{{ $themeOption['label'] }}">
                        <x-admin::bridge.icon :name="$themeOption['glyph']" :size="16" />
                        <span class="bridge-seg-text">{{ $themeOption['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </v-dark>

        <v-notifications {{ $attributes }}>
            <span class="flex relative">
                <button
                    type="button"
                    class="icon-notification p-1.5 rounded-md text-2xl cursor-pointer transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                    aria-label="@lang('admin::app.components.layouts.header.notifications')"
                    title="@lang('admin::app.components.layouts.header.notifications')"
                >
                </button>
            </span>
        </v-notifications>

        <x-admin::dropdown position="bottom-right">
            <x-slot:toggle>
                @if ($admin->image_url || $admin->hasGravatar())
                    <button
                        class="flex w-9 h-9 overflow-hidden rounded-full cursor-pointer hover:opacity-80"
                        data-account-avatar
                        aria-label="@lang('admin::app.components.layouts.header.account-title')"
                    >
                        <img
                            src="{{ $admin->avatar_url }}"
                            class="w-full h-full object-cover object-top"
                            alt="{{ $admin->name }}"
                        />
                    </button>
                @else
                    <button
                        class="flex justify-center items-center w-9 h-9 bg-mothership-avatar rounded-full text-sm text-white font-semibold cursor-pointer leading-6 transition-all hover:bg-mothership-primary-hover"
                        data-account-avatar
                        aria-label="@lang('admin::app.components.layouts.header.account-title')"
                    >
                        {{ substr($admin->name, 0, 1) }}
                    </button>
                @endif

            </x-slot>

            {{--
                Settings and Configuration live here now instead of the sidebar
                (AdminServiceProvider::buildAccountMenu() groups the ACL-filtered
                `menu.admin` items tagged `'placement' => 'account'` into
                $accountMenu, keyed by section). A role with neither shows only
                the name/role block, My Account and Log out — each section is
                skipped entirely when it has no authorized entries.

                This panel is `position: absolute; right: 0` against the toggle (v-dropdown
                has no `:teleport="true"` here), which never clamps to the viewport the way
                the teleported/fixed path does — a long account name (the name row is
                `w-max` plus `truncate`, which does not by itself cap a flex child's width)
                or a full Configuration section can otherwise push the panel's left edge
                off-screen at phone width. max-w-[calc(100vw-2rem)] caps it to the viewport
                with the same 1rem margin the header's own px-4 uses, on every screen size
                (harmless at desktop widths, where the panel's natural width never
                approaches the cap).
            --}}
            <x-slot:content data-account-menu="true" class="!p-0 max-h-[calc(100vh-130px)] max-w-[calc(100vw-2rem)] overflow-auto journal-scroll min-w-[220px]">
                <div class="flex flex-col gap-0.5 px-5 py-2.5 border border-b-gray-300 dark:border-gray-800">
                    <p class="text-sm font-semibold text-gray-800 dark:text-white truncate">
                        {{ $admin->name }}
                    </p>

                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                        {{ $admin->role->name }}
                    </p>
                </div>

                <div class="grid gap-1 py-2.5 border border-b-gray-300 dark:border-gray-800">
                    <a
                        class="px-5 py-2 text-base  text-gray-800 dark:text-white hover:bg-mothership-primary-soft hover:bg-primary-50 dark:hover:bg-cherry-800 cursor-pointer"
                        href="{{ route('admin.account.edit') }}"
                    >
                        @lang('admin::app.components.layouts.header.my-account')
                    </a>
                </div>

                @if ((bool) config('dam.feedback.enabled') && app(\Mothership\DAM\Services\Feedback\FeedbackAccess::class)->canSubmit($admin))
                    <div class="grid gap-1 py-2.5 border border-b-gray-300 dark:border-gray-800">
                        <a
                            class="px-5 py-2 text-base  text-gray-800 dark:text-white hover:bg-mothership-primary-soft hover:bg-primary-50 dark:hover:bg-cherry-800 cursor-pointer"
                            href="{{ route('admin.dam.feedback.mine') }}"
                            data-feedback-mine-link
                        >
                            @lang('dam::app.admin.feedback.ui.myReports')
                        </a>
                    </div>
                @endif

                @foreach ($accountMenu as $section)
                    @continue(empty($section['entries']))

                    <div class="grid gap-1 py-2.5 border border-b-gray-300 dark:border-gray-800">
                        <p class="px-5 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            @lang($section['heading'])
                        </p>

                        @foreach ($section['entries'] as $entry)
                            <a
                                class="px-5 py-2 text-base  text-gray-800 dark:text-white hover:bg-mothership-primary-soft hover:bg-primary-50 dark:hover:bg-cherry-800 cursor-pointer"
                                href="{{ $entry['url'] }}"
                            >
                                @lang($entry['name'])
                            </a>
                        @endforeach
                    </div>
                @endforeach

                <button type="button" data-pwa-install class="px-5 py-2 text-left text-base text-gray-800 dark:text-white hover:bg-mothership-primary-soft cursor-pointer">
                    @lang('admin::pwa.install')
                </button>

                {{-- Safari on iPhone has no install prompt to offer, so the item explains Add to Home Screen instead (public/pwa/register.js). --}}
                <button type="button" data-pwa-ios-install aria-expanded="false" aria-controls="pwa-ios-install-help" class="px-5 py-2 text-left text-base text-gray-800 dark:text-white hover:bg-mothership-primary-soft cursor-pointer">
                    @lang('admin::pwa.install')
                </button>
                <p id="pwa-ios-install-help" data-pwa-ios-install-help hidden class="px-5 pb-2 text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::pwa.ios-install-help')
                </p>

                <x-admin::form
                    method="DELETE"
                    action="{{ route('admin.session.destroy') }}"
                    id="adminLogout"
                    :track-dirty="false"
                    ajax="true"
                >
                </x-admin::form>

                <div class="grid gap-1 py-2.5">
                    <a
                        class="px-5 py-2 text-base  text-gray-800 dark:text-white hover:bg-mothership-primary-soft hover:bg-primary-50 dark:hover:bg-cherry-800 cursor-pointer"
                        href="{{ route('admin.session.destroy') }}"
                        data-no-ajax-nav
                        onclick="event.preventDefault(); document.getElementById('adminLogout').requestSubmit();"
                    >
                        @lang('admin::app.components.layouts.header.logout')
                    </a>
                </div>
            </x-slot>
        </x-admin::dropdown>
    </div>
</header>

{{-- The navigation drawer keeps its width on a phone: the page beside it stays in view, and a tap there closes it. --}}
<x-admin::drawer
    position="left"
    width="270px"
    :full-width-on-phone="false"
    ref="sidebarMenuDrawer"
>
    <x-slot:header>
        {{-- The padding keeps the logo clear of the drawer's close button, which sits over this corner. --}}
        <div class="flex justify-between items-center ltr:pr-10 rtl:pl-10">
            @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
                <img
                    class="h-10"
                    src="{{ Storage::url($logo) }}"
                    data-mothership-logo
                    title="{{ config('documentation.name') }}"
                    alt="{{ config('documentation.name') }}"
                />
            @else
                <img
                    src="{{ mothership_asset('images/logo.svg') }}"
                    width="319"
                    height="40"
                    class="theme-logo-light block dark:hidden"
                    data-mothership-logo
                    title="{{ config('documentation.name') }}"
                    alt="{{ config('documentation.name') }}"
                />

                <img
                    src="{{ mothership_asset('images/dark_logo.svg') }}"
                    width="319"
                    height="40"
                    class="theme-logo-dark hidden dark:block"
                    data-mothership-logo
                    title="{{ config('documentation.name') }}"
                    alt="{{ config('documentation.name') }}"
                />
            @endif
        </div>
    </x-slot>

    <x-slot:content class="p-4">
        <div class="h-[calc(100vh-100px)] overflow-auto journal-scroll">
            {{-- Phones: the top bar has no room for the theme segment, so the More drawer carries it. --}}
            <div class="mb-4 min-[720px]:hidden">
                <v-dark></v-dark>
            </div>

            <nav class="grid gap-0.5 w-full">
                {{-- Account-placed items (Settings, Configuration) are left out here too —
                     they live in the avatar dropdown, which is not part of this drawer.
                     Pinned items (Help) have no separate footer in this drawer, so they
                     render at the end of the same list instead. --}}
                @foreach ([...$menu->visibleItems(), ...$menu->pinnedItems()] as $menuItem)
                    <div class="relative group/item">
                        {{-- The rail's own row look, so the phone's More list reads as the same navigation. --}}
                        <a
                            href="{{ $menuItem['url'] }}"
                            class="bridge-rail-link {{ $menu->getActive($menuItem) ? 'bridge-drawer-current' : '' }}"
                            @if ($menu->getActive($menuItem)) aria-current="page" @endif
                        >
                            @if (! empty($menuItem['rail']['glyph']))
                                <x-admin::bridge.icon :name="$menuItem['rail']['glyph']" />
                            @else
                                <span class="{{ $menuItem['icon'] }} bridge-rail-font-glyph" aria-hidden="true"></span>
                            @endif

                            <span class="bridge-rail-text">@lang($menuItem['name'])</span>
                        </a>

                        @if (count($menuItem['children']))
                            <div class="{{ $menu->getActive($menuItem) ? '!grid' : '' }} hidden min-w-[180px] ltr:pl-10 rtl:pr-10 pb-2 rounded-b-lg z-[100]">
                                @foreach ($menuItem['children'] as $subMenuItem)
                                    <a
                                        href="{{ $subMenuItem['url'] }}"
                                        class="text-sm {{ $menu->getActive($subMenuItem) ? 'text-mothership-primary dark:text-mothership-primary text-primary-700 dark:text-primary-700':'text-gray-600 dark:text-gray-300' }} whitespace-nowrap py-1 hover:text-mothership-primary hover:text-primary-700 hover:bg-gray-950"
                                    >
                                        @lang($subMenuItem['name'])
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </nav>
        </div>
    </x-slot>
</x-admin::drawer>

@pushOnce('scripts')

    <script type="text/x-template" id="v-notifications-template">
        {{-- VIS2-07: teleported, so the panel is clamped to the viewport; anchored to the bell it started at x=-47 on a 375 px phone. --}}
        <x-admin::dropdown position="bottom-right" :teleport="true">
            <x-slot:toggle>
                <span class="flex relative">
                    <button
                        type="button"
                        class="icon-notification p-1.5 rounded-md text-2xl text-red cursor-pointer transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                        :aria-label="totalUnRead ? `${notificationsLabel} (${totalUnRead})` : notificationsLabel"
                        title="@lang('admin::app.components.layouts.header.notifications')"
                    >
                    </button>

                    <span
                        class="flex justify-center items-center min-w-5 h-5 absolute -top-2 p-1.5 ltr:left-5 rtl:right-5 bg-mothership-avatar bg-primary-400 rounded-full text-white text-[10px] font-semibold leading-[9px] cursor-pointer"
                        aria-hidden="true"
                        v-text="totalUnRead"
                        v-if="totalUnRead"
                    >
                    </span>
                </span>
            </x-slot>

            <x-slot:content class="p-5 w-[360px] max-w-[calc(100vw-1rem)] max-h-[calc(100vh-130px)] overflow-auto journal-scroll !p-0">
                <div class="text-base  p-3 text-gray-800 dark:text-gray-300 font-bold border-b dark:border-gray-800">
                    @lang('admin::app.notifications.title')
                </div>

                <div class="grid">
                    <div v-for="userNotification in userNotifications">
                        <a
                            v-if="userNotification.notification?.id"
                            class="flex gap-1.5 items-start p-3 border-b dark:border-gray-800 last:border-b-0"
                            :key="userNotification.notification.id"
                            :href="'{{ route('admin.notification.viewed_notification', ':id') }}'.replace(':id', userNotification.notification.id)"
                            :class="{
                                'bg-gray-100 dark:bg-cherry-800': userNotification.read === 1,
                                'hover:bg-gray-50 dark:hover:bg-gray-950': true,
                            }"
                        >
                            <div class="grid gap-3">
                                <p
                                    class="text-sm text-gray-800 dark:text-slate-50 font-semibold"
                                    v-text="userNotification.notification.title"
                                ></p>

                                <p
                                    class="text-sm text-gray-600 dark:text-gray-300"
                                    v-text="userNotification.notification.description"
                                ></p>

                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    @{{ userNotification.notification.created_at_human }}
                                </p>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="flex gap-1.5 justify-between h-[47px] py-4 px-6 border-t dark:border-gray-800">
                    <a
                        href="{{ route('admin.notification.index') }}"
                        class="text-sm text-mothership-primary text-primary-700 font-semibold cursor-pointer transition-all hover:underline"
                    >
                        @lang('admin::app.notifications.view-all')
                    </a>

                    <a
                        class="text-sm text-mothership-primary text-primary-700 font-semibold cursor-pointer transition-all hover:underline"
                        v-if="userNotifications?.length"
                        @click="readAll()"
                    >
                        @lang('admin::app.notifications.read-all')
                    </a>
                </div>
            </x-slot>
        </x-admin::dropdown>
    </script>

    <script type="module">
        app.component('v-notifications', {
            template: '#v-notifications-template',

                props: [
                    'getReadAllUrl',
                    'readAllTitle',
                ],

                data() {
                    return {
                        userNotifications: [],

                        totalUnRead: 0,

                        pollTimer: null,

                        notificationsLabel: @json(trans('admin::app.components.layouts.header.notifications')),
                    }
                },

                mounted() {
                    this.getNotification();

                    // A tab nobody is looking at does not poll; it catches up the moment it is shown again.
                    // A visible tab nobody has touched for IDLE_AFTER_MS polls every IDLE_POLL_MS instead of
                    // every POLL_MS, and refreshes at once when its user comes back.
                    const POLL_MS = 15000;
                    const IDLE_POLL_MS = 60000;
                    const IDLE_AFTER_MS = 180000;

                    this._lastActivity = Date.now();

                    const isIdle = () => Date.now() - this._lastActivity > IDLE_AFTER_MS;

                    const schedule = () => {
                        clearTimeout(this.pollTimer);
                        this.pollTimer = setTimeout(() => {
                            if (! document.hidden) this.getNotification();
                            schedule();
                        }, isIdle() ? IDLE_POLL_MS : POLL_MS);
                    };

                    this._onActivity = () => {
                        const wasIdle = isIdle();
                        this._lastActivity = Date.now();

                        if (wasIdle && ! document.hidden) {
                            this.getNotification();
                            schedule();
                        }
                    };
                    this._onVisible = () => {
                        if (! document.hidden) {
                            this._lastActivity = Date.now();
                            this.getNotification();
                            schedule();
                        }
                    };

                    schedule();
                    document.addEventListener('visibilitychange', this._onVisible);
                    ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove'].forEach((name) => {
                        window.addEventListener(name, this._onActivity, { passive: true });
                    });
                },

                beforeUnmount() {
                    clearTimeout(this.pollTimer);
                    document.removeEventListener('visibilitychange', this._onVisible);
                    ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove'].forEach((name) => {
                        window.removeEventListener(name, this._onActivity);
                    });
                },

                methods: {
                    getNotification() {
                        this.$axios.get('{{ route('admin.notification.get_notification') }}', {
                                params: {
                                    limit: 5
                                }
                            })
                            .then((response) => {
                                this.userNotifications = response.data.search_results.data;

                                this.totalUnRead =   response.data.total_unread;
                            })
                            .catch(error => console.log(error))
                    },

                    readAll() {
                        this.$axios.post('{{ route('admin.notification.read_all') }}')
                            .then((response) => {
                                this.userNotifications = response.data.search_results.data;

                                this.totalUnRead = response.data.total_unread;

                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.success_message });
                        })
                        .catch((error) => {});
                },
            },
        });
    </script>

    <script type="text/x-template" id="v-dark-template">
        <div class="bridge-seg" role="group" aria-label="@lang('admin::app.components.layouts.rail.theme')" data-theme-segment>
            @foreach ($themeOptions as $themeMode => $themeOption)
                <button
                    type="button"
                    class="bridge-seg-opt"
                    data-theme-option="{{ $themeMode }}"
                    :aria-pressed="shownTheme === '{{ $themeMode }}' ? 'true' : 'false'"
                    title="{{ $themeOption['label'] }}"
                    @click="choose('{{ $themeMode }}')"
                >
                    <x-admin::bridge.icon :name="$themeOption['glyph']" :size="16" />
                    <span class="bridge-seg-text">{{ $themeOption['label'] }}</span>
                </button>
            @endforeach
        </div>
    </script>

    <script type="module">
        app.component('v-dark', {
            template: '#v-dark-template',

            data() {
                const savedMode = document.cookie.match(/(?:^|; )dark_mode=([^;]*)/);

                return {
                    // The cookie, not the value at page load: the phone drawer mounts a fresh copy each time it opens.
                    darkMode: savedMode ? decodeURIComponent(savedMode[1]) : @json(request()->cookie('dark_mode', 'auto')),

                    mediaQuery: null,

                    // The theme on screen, kept in step across every segment on the page through 'change-theme'.
                    shownTheme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                };
            },

            methods: {
                choose(mode) {
                    this.$emitter.emit('change-theme-mode', mode);

                    this.applyTheme(mode, true);
                },

                applyTheme(mode, persist = false) {
                    const shouldUseDark = mode === 'dark' || (mode === 'auto' && this.isDarkFromBrowser());

                    document.documentElement.classList.toggle('dark', shouldUseDark);

                    this.$emitter.emit('change-theme', shouldUseDark ? 'dark' : 'light');

                    if (persist) {
                        const expiryDate = new Date();

                        expiryDate.setMonth(expiryDate.getMonth() + 1);

                        document.cookie = `dark_mode=${mode}; path=/; expires=${expiryDate.toUTCString()}`;
                    }
                },

                isDarkFromBrowser() {
                    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                },

                normalizeMode(mode) {
                    if (mode === '1') {
                        return 'dark';
                    }

                    if (mode === '0') {
                        return 'light';
                    }

                    return ['light', 'dark', 'auto'].includes(mode) ? mode : 'auto';
                },
            },

            mounted() {
                this.onThemeShown = (theme) => {
                    this.shownTheme = theme;
                };

                this.onThemeMode = (mode) => {
                    this.darkMode = mode;
                };

                this.onSystemTheme = () => {
                    if (this.darkMode === 'auto') {
                        this.applyTheme('auto');
                    }
                };

                this.$emitter.on('change-theme', this.onThemeShown);
                this.$emitter.on('change-theme-mode', this.onThemeMode);

                this.darkMode = this.normalizeMode(this.darkMode);

                this.applyTheme(this.darkMode);

                this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

                this.mediaQuery?.addEventListener?.('change', this.onSystemTheme);
            },

            beforeUnmount() {
                this.$emitter.off('change-theme', this.onThemeShown);
                this.$emitter.off('change-theme-mode', this.onThemeMode);
                this.mediaQuery?.removeEventListener?.('change', this.onSystemTheme);
            },
        });
    </script>
@endpushOnce
