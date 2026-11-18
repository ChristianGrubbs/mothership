@props(['returnDirectoryId' => null])
@include('dam::components.shared.name-tail')
@php
    $darkModePreference = request()->cookie('dark_mode', 'auto');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar_AE']) ? 'rtl' : 'ltr' }}" class="{{ $darkModePreference === 'dark' || $darkModePreference === '1' ? 'dark' : '' }}">
    <head>

        {!! view_render_event('mothership.admin.layout.head.before') !!}
        {!! view_render_event('mothership.admin.layout.head') !!}

        <x-admin::layouts.document-title :title="$title ?? ''" />

        <meta charset="UTF-8">

        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="base-url" content="{{ url()->to('/') }}">
        <meta name="admin-url" content="{{ config('app.admin_url') }}">
        <meta name="currency-code" content="{{ core()->getBaseCurrencyCode() }}">
        <meta http-equiv="content-language" content="{{ app()->getLocale() }}">
        <script>
            (() => {
                const getCookie = (name) => {
                    const value = `; ${document.cookie}`;
                    const parts = value.split(`; ${name}=`);

                    return parts.length === 2 ? parts.pop().split(';').shift() : null;
                };

                const preference = getCookie('dark_mode') || 'auto';
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const shouldUseDark = preference === 'dark' || preference === '1' || (preference === 'auto' && prefersDark);

                document.documentElement.classList.toggle('dark', shouldUseDark);
            })();
        </script>

        @stack('meta')

        <x-admin::layouts.request-context />

        @mothershipVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'], 'admin')

        <x-admin::layouts.icon-font-gate />

        <link
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
            rel="stylesheet"
        />

        <link
            href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&display=swap"
            rel="stylesheet"
        />

        <link
            href="https://fonts.googleapis.com/css2?family=Inter&display=swap"
            rel="stylesheet"
        />

        @if ($favicon = core()->getConfigData('general.design.admin_logo.favicon'))
            <link
                type="image/x-icon"
                href="{{ Storage::url($favicon) }}"
                rel="shortcut icon"
                sizes="16x16"
            >
        @else
            <link
                type="image/x-icon"
                href="{{ mothership_asset('images/favicon.svg') }}"
                rel="shortcut icon"
                sizes="16x16"
            />
        @endif

        @include('dam::components.library-navigation')

        @stack('styles')

        <style>
            {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
        </style>

        {!! view_render_event('mothership.admin.layout.head.after') !!}
    </head>

    <body class="h-screen overflow-hidden bridge-body">
        {!! view_render_event('mothership.admin.layout.body.before') !!}

        <x-admin::layouts.skip-link />

        <div id="app" class="h-screen flex flex-col">
            <x-admin::flash-group />

            <x-admin::modal.history />

            <x-admin::modal.confirm />

            {!! view_render_event('mothership.admin.layout.content.before') !!}

            <div
                class="flex flex-1 min-h-0 overflow-hidden group/container {{ ((int) (request()->cookie('sidebar_collapsed') ?? 0)) ? 'sidebar-collapsed' : 'sidebar-not-collapsed' }}"
                ref="appLayout"
            >
                <x-admin::layouts.sidebar />

                <div class="bridge-column">
                <x-admin::layouts.header />

                <x-dam::feedback.dialog />

                <main id="main-content" class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden px-4 pt-3 pb-6 bg-transparent max-lg:!px-4 transition-all duration-300 focus:outline-none" tabindex="-1">
                    <h1 class="sr-only">{{ ($label ?? '') !== '' ? $label : ($title ?? config('documentation.name')) }}</h1>
                    {!! view_render_event('mothership.admin.layouts.tabs.before') !!}

                    <div class="flex flex-wrap justify-between gap-2 items-center">
                        <div class="flex min-w-0">

                            <div class="flex items-center gap-2 flex-wrap min-w-0 max-w-full">
                                <v-dam-library-back
                                    default-url="{{ route('admin.dam.index') }}"
                                    label="@lang('dam::app.admin.dam.asset.edit.back')"
                                ></v-dam-library-back>

                                @isset($breadcrumb){{ $breadcrumb }}@endisset

                                @isset($fileIcon){{ $fileIcon }}@endisset

                                <v-dam-asset-label class="flex min-w-0 max-w-full" initial-label="{{ $label ?? '' }}"></v-dam-asset-label>
                                {{ $labelBadge ?? '' }}

                                @isset($counter)
                                {{ $counter }}
                                @endisset
                            </div>
                        </div>

                        {{-- On a phone (below 525px) or a screen 480px tall or less, the less used actions fold behind "More", so the preview starts higher. --}}
                        <div class="flex flex-wrap gap-2 items-center">
                            {{ $buttonOne }}
                            <v-dam-more-actions
                                label="@lang('dam::app.admin.dam.asset.edit.button.more-actions')"
                                less-label="@lang('dam::app.admin.dam.asset.edit.button.fewer-actions')"
                            >
                                {{ $buttonTwo }}
                                {{ $buttonThree }}
                                {{ $buttonFour }}
                                {{ $buttonFive ?? '' }}
                            </v-dam-more-actions>
                        </div>
                    </div>

                    <v-asset-lock-zone>
                    <div class="tabs">
                        @php

                            $defaultTabs = [
                                [
                                    'url'    => '?',
                                    'code'   => 'general',
                                    'name'   => 'admin::app.components.layouts.sidebar.general',
                                    'icon'   => 'icon-general'
                                ], [
                                    'url'    => '?history',
                                    'code'   => 'history',
                                    'name'   => 'admin::app.components.layouts.sidebar.history',
                                    'icon'   => 'icon-history'
                                ],
                            ];

                            $items = isset($addTabs) ? optional($addTabs->attributes)->getAttributes()['items'] : $defaultTabs;

                            // The tab is named by a bare key (`?files`); other keys may ride along (`?files&file=12`).
                            $tabCodes = collect($items)->pluck('code')->all();

                            $queryTab = collect(array_keys(request()->query()))->first(fn ($key) => in_array($key, $tabCodes, true));

                            $activeTab = $queryTab ?? $items[0]['code'];

                        @endphp

                        {{-- Below 640px the tabs scroll sideways in their own lane (no wrapping) and the navButtons
                             (Share, Embed, Add to collection) wrap onto their own line below it, so neither starves the other
                             on a portrait phone (a 375px row left the lane about 10px wide). The lane fades
                             at an edge that has more tabs past it, and opens scrolled to the active tab (script below).
                             VISR-08: the icons drop out below 640px (a label is wider than its glyph) and the lane snaps to tab
                             starts, so a settled lane cuts a tab only at its right edge, which reads as "more tabs this way".
                             From 640px the tabs wrap; they are sized so all eight fit one row at 1440px, and Share
                             stays on the first row (UI review 2026-10-06). --}}
                        <div class="flex gap-4 my-4 max-[639px]:my-2 max-[639px]:gap-y-1 border-b-2 dark:border-gray-800 items-center max-[639px]:flex-wrap min-[640px]:items-start">
                            <div class="flex flex-1 min-w-0 max-[639px]:basis-full gap-x-1 gap-y-1 items-center self-stretch overflow-x-auto max-[639px]:pb-px max-[639px]:snap-x max-[639px]:snap-proximity max-[639px]:scroll-px-1 min-[640px]:flex-wrap min-[640px]:overflow-visible" data-dam-tab-lane>
                            @foreach ($items as $key => $item)
                                <a href="{{ $item['url'] }}" class="self-stretch flex items-end shrink-0 whitespace-nowrap max-[639px]:snap-start" data-dam-tab="{{ $item['code'] }}" data-dam-asset="{{ (int) request()->id }}" @if ($item['code'] === $activeTab) aria-current="page" @endif>
                                    <div class="{{  $item['code'] === $activeTab ? "-mb-px border-primary-700  border-b-2 transition" : '' }} pb-3 px-2 text-sm font-medium text-gray-600 dark:text-gray-300 cursor-pointer flex items-center gap-1.5 justify-center">
                                        <span class="text-lg max-[639px]:hidden {{ $item['icon'] }}"></span>
                                        @lang($item['name'])
                                        @if (array_key_exists('badge', $item))
                                            <v-dam-tab-badge tab-code="{{ $item['code'] }}" :initial-count="{{ (int)($item['badge'] ?? 0) }}"></v-dam-tab-badge>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                            </div>

                            @isset($navButtons)
                            <div class="ml-auto shrink-0 flex gap-2 items-center max-[639px]:w-full max-[639px]:flex-wrap max-[639px]:py-0.5 max-[639px]:gap-1 max-[639px]:[&_.transparent-button]:text-sm max-[639px]:[&_.transparent-button]:px-1.5">
                                {{ $navButtons }}
                            </div>
                            @endisset
                        </div>

                    </div>

                    @if ($activeTab === 'history')
                        {!! view_render_event('mothership.settings.channels.list.before') !!}

                        <x-admin::history src="{{ route('admin.history.index',[$entityName, request()->id]) }}" >
                        </x-admin::history>

                        {!! view_render_event('mothership.settings.channels.list.after') !!}

                    @elseif ($activeTab === 'preview')
                        {!! view_render_event('mothership.settings.slot.content.before') !!}

                        {{ $slot }}

                        {!! view_render_event('mothership.settings.slot.content.after') !!}

                    @elseif ($activeTab === 'properties')
                        {{$properties}}

                    @elseif ($activeTab === 'comments')
                        {{$comments}}

                    @elseif ($activeTab === 'linked-resources')
                        {{$linked_resources}}

                    @elseif ($activeTab === 'relationships')
                        {{$relationships}}

                    @elseif ($activeTab === 'files')
                        {{$files}}

                    @elseif ($activeTab === 'meta-data')
                        {{$meta_data}}
                    @endif
                    </v-asset-lock-zone>

                    {!! view_render_event('mothership.admin.layouts.tabs.after') !!}
                </main>
                </div>
            </div>

            <x-admin::layouts.bridge.tabbar />

            {!! view_render_event('mothership.admin.layout.content.after') !!}
        </div>

        {!! view_render_event('mothership.admin.layout.body.after') !!}

        @pushOnce('scripts')
            <script
                type="text/x-template"
                id="v-asset-lock-zone-template"
            >
                <div
                    :class="{ 'cursor-not-allowed': isLocked }"
                    :aria-busy="isLocked"
                >
                    <div :class="{ 'opacity-60 pointer-events-none': isLocked }">
                        <slot></slot>
                    </div>
                </div>
            </script>

            <script type="module">
                app.component('v-asset-lock-zone', {
                    template: '#v-asset-lock-zone-template',
                    data() {
                        return {
                            isLocked: false,
                            onLockChange: null,
                        };
                    },
                    mounted() {
                        this.onLockChange = (locked) => { this.isLocked = !!locked; };
                        this.$emitter.on('dam-asset-action-locked', this.onLockChange);
                    },
                    unmounted() {
                        if (this.onLockChange) {
                            this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                        }
                    },
                });
            </script>

            <script type="module">
                // P1b usage analytics: which details tab was opened. Delegated, so a remounted lane needs nothing.
                document.addEventListener('click', (event) => {
                    const tab = event.target.closest?.('[data-dam-tab]');

                    if (tab && ! tab.hasAttribute('aria-current')) {
                        window.damTrack?.('modal.tab.opened', Number(tab.dataset.damAsset), { tab: tab.dataset.damTab }, 'details');
                    }
                });
            </script>

            <script type="module">
                // The phone tab lane: open scrolled to the active tab, and fade whichever edge has more
                // tabs past it, so a tab cut off mid-word reads as "scroll for more". From 640px the lane
                // wraps and never scrolls, so both edges stay solid there. Vue mounts over #app on load
                // and can mount again later (arriving from another tab does), replacing this markup, so
                // whichever lane is current gets bound, once.
                const bound = new WeakSet();
                const bind = (lane) => {
                    if (! lane || bound.has(lane)) return;
                    bound.add(lane);

                    // Centred rather than just scrolled into view: a tab at the very edge sits under the fade.
                    const centre = (tab) => {
                        if (tab && lane.scrollWidth > lane.clientWidth) {
                            lane.scrollLeft = tab.offsetLeft - lane.offsetLeft - (lane.clientWidth - tab.offsetWidth) / 2;
                        }
                    };

                    centre(lane.querySelector('[aria-current="page"]'));
                    lane.addEventListener('focusin', (event) => centre(event.target.closest('a')));

                    const update = () => {
                        const before = lane.scrollLeft > 1;
                        const after = lane.scrollWidth - lane.clientWidth - lane.scrollLeft > 1;
                        const mask = before || after
                            ? `linear-gradient(to right, ${before ? 'transparent 0, #000 2rem' : '#000 0'}, ${after ? '#000 calc(100% - 2rem), transparent 100%' : '#000 100%'})`
                            : '';
                        lane.style.maskImage = mask;
                        lane.style.webkitMaskImage = mask;
                    };

                    lane.addEventListener('scroll', update, { passive: true });
                    new ResizeObserver(update).observe(lane);
                    update();
                };

                window.addEventListener('load', () => {
                    bind(document.querySelector('[data-dam-tab-lane]'));
                    new MutationObserver(() => bind(document.querySelector('[data-dam-tab-lane]')))
                        .observe(document.body, { childList: true, subtree: true });
                });
            </script>

            <script type="module">
                // Back returns to the Library view this page was opened from — its scope, search,
                // media type, folder and page — whenever the Library handoff (?nav=1) carried one;
                // otherwise, and for a direct link, the Library root (QA finding F13, 2026-09-27).
                app.component('v-dam-library-back', {
                    props: {
                        defaultUrl: { type: String, required: true },
                        label:      { type: String, required: true },
                    },
                    template: `<a :href="href" class="transparent-button" @click="forgetReturnDirectory"><i class="icon-left text-xl -mt-px" aria-hidden="true"></i>@{{ label }}</a>`,
                    data() {
                        let href = this.defaultUrl;
                        try {
                            if (new URLSearchParams(window.location.search).get('nav') === '1') {
                                href = window.MothershipDetailsNavigation?.readSiblings?.()?.returnUrl || href;
                            }
                        } catch (e) {}

                        return { href };
                    },
                    methods: {
                        // The stored view already names its folder; the asset's own folder
                        // (dam_return_dir) must not reopen the Library somewhere else.
                        forgetReturnDirectory() {
                            if (this.href === this.defaultUrl) return;
                            try { sessionStorage.removeItem('dam_return_dir'); } catch (e) {}
                        },
                    },
                });
            </script>

            <script type="module">
                app.component('v-dam-asset-label', {
                    props: {
                        initialLabel: { type: String, default: '' },
                    },
                    // VIS-09: a long title is cut in the middle so its extension stays visible, and it never breaks mid-word.
                    template: `<span class="flex min-w-0 max-w-full text-base text-gray-600 dark:text-gray-300 font-bold" :title="label"><span class="min-w-0 truncate">@{{ parts.head }}</span><span class="shrink-0 whitespace-pre">@{{ parts.tail }}</span></span>`,
                    computed: {
                        parts() {
                            return window.MothershipNameTail ? window.MothershipNameTail(this.label) : { head: this.label, tail: '' };
                        },
                    },
                    data() {
                        return { label: this.initialLabel };
                    },
                    mounted() {
                        this._onAssetChange = (data) => {
                            if (data.asset?.file_name) {
                                this.label = data.asset.display_name || data.asset.file_name;
                            }
                        };
                        this.$emitter.on('dam-asset-changed', this._onAssetChange);
                    },
                    beforeUnmount() {
                        if (this._onAssetChange) {
                            this.$emitter.off('dam-asset-changed', this._onAssetChange);
                        }
                    },
                });
            </script>
        @endPushOnce

        @include('dam::components.download.preparing')

        @stack('scripts')

        @if ($returnDirectoryId)
        <script>
            try { sessionStorage.setItem('dam_return_dir', '{{ (int) $returnDirectoryId }}'); } catch {}
        </script>
        @endif

        {!! view_render_event('mothership.admin.layout.vue-app-mount.before') !!}

        {!! view_render_event('mothership.admin.layout.vue-app-mount.after') !!}
    </body>
</html>
