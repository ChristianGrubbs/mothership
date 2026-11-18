@props([
    'activeTab' => 'general',
    'historyId' => null,
    'generalUrl' => '?',
    'historyUrl' => '?history',
    'tabItems' => [],
])

@php
    $darkModePreference = request()->cookie('dark_mode', 'auto');
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar_AE']) ? 'rtl' : 'ltr' }}" class="{{ $darkModePreference === 'dark' || $darkModePreference === '1' ? 'dark' : '' }}">
    <head>
        <x-admin::layouts.document-title :title="$title ?? ''" />

        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="base-url" content="{{ rtrim(config('app.url'), '/') }}">
        <meta name="admin-url" content="{{ trim(parse_url(url(config('app.admin_url')), PHP_URL_PATH), '/') }}">
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

        {{-- Feedback & bug reports: renders nothing unless DAM_FEEDBACK_ENABLED. --}}
        <x-dam::feedback.head />

        @stack('meta')

        <x-admin::layouts.request-context />

        {!! view_render_event('mothership.admin.layout.head.before') !!}

        @mothershipVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])

        <x-admin::layouts.icon-font-gate />
        <x-admin::layouts.pwa />
        <x-admin::layouts.device />


        @if ($favicon = core()->getConfigData('general.design.admin_logo.favicon'))
            <link
                type="image/x-icon"
                href="{{ Storage::url($favicon) }}"
                title="{{ config('documentation.name') }}"
                rel="shortcut icon"
                sizes="16x16"
            >
        @else
            <link
                type="image/x-icon"
                href="{{ mothership_asset('images/favicon.svg') }}"
                title="{{ config('documentation.name') }}"
                rel="shortcut icon"
                sizes="16x16"
            />
        @endif

        @if (Route::has('admin.dam.assets.index'))
            @include('dam::components.library-navigation')
        @endif

        @stack('styles')

        <style>
            {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
        </style>

        {!! view_render_event('mothership.admin.layout.head') !!}
    </head>

    <body class="h-screen overflow-hidden bridge-body">
        {!! view_render_event('mothership.admin.layout.body.before') !!}

        <x-admin::layouts.skip-link />

        <div id="app" class="flex flex-col h-screen">
            <x-admin::flash-group />

            <x-admin::modal.history />

            <x-admin::modal.confirm />

            {!! view_render_event('mothership.admin.layout.content.before') !!}

            <div
                class="flex flex-1 min-h-0 group/container {{ ((int) (request()->cookie('sidebar_collapsed') ?? 0)) ? 'sidebar-collapsed' : 'sidebar-not-collapsed' }}"
                ref="appLayout"
            >
                <x-admin::layouts.sidebar />

                <div class="bridge-column">
                <x-admin::layouts.header />

                <x-dam::feedback.dialog />

                <main id="main-content" class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden px-4 pt-3 pb-6 bg-transparent transition-all duration-300 focus:outline-none" tabindex="-1">
                    @php
                        $hasPermission = bouncer()->hasPermission('history');

                        $activeTab = $hasPermission
                            ? (request()->has('history') ? 'history' : $activeTab)
                            : ($activeTab ?? 'general');

                        $currentQuery = request()->query();
                        $generalQuery = $currentQuery;
                        unset($generalQuery['history']);

                        $defaultGeneralUrl = request()->url().(count($generalQuery) ? '?'.http_build_query($generalQuery) : '?');
                        $defaultHistoryUrl = request()->url().'?'.http_build_query(array_merge($generalQuery, ['history' => 1]));

                        $generalUrl = $generalUrl === '?' ? $defaultGeneralUrl : $generalUrl;
                        $historyUrl = $historyUrl === '?history' ? $defaultHistoryUrl : $historyUrl;

                        $tabItems = count($tabItems)
                            ? $tabItems
                            : [
                                [
                                    'key'   => 'general',
                                    'url'   => $generalUrl,
                                    'label' => 'admin::app.components.layouts.sidebar.general',
                                ],
                            ];
                    @endphp

                    <div class="js-sticky-header sticky -top-3 z-[9999] -mx-4 -mt-3 border-b border-gray-200 bg-mothership-primary-page px-4 pt-3 transition-shadow dark:border-gray-800 dark:bg-cherry-800">
                        @isset($pageHeader)
                            {{ $pageHeader }}
                        @else
                            <x-admin::page-title :title="$title ?? ''" />
                        @endisset

                        <x-admin::layouts.edit-tabs
                            :items="$tabItems"
                            :active="$activeTab"
                            :history-url="$historyUrl"
                            :show-history="$hasPermission"
                        >
                            {{ $tabs ?? '' }}
                        </x-admin::layouts.edit-tabs>
                    </div>

                    <x-admin::layouts.sticky-header-dropdown-guard />

                    <div class="pt-4">
                        @if ($activeTab === 'general')
                            {{ $slot }}
                        @endif

                        {{ $tabContents ?? '' }}

                        @if ($activeTab === 'history')
                            {!! view_render_event('mothership.admin.layout.history.before') !!}

                            <x-admin::history src="{{ route('admin.history.index',[$entityName, ($historyId ?? request()->id)]) }}" >

                            </x-admin::history>

                            {!! view_render_event('mothership.admin.layout.history.after') !!}
                        @endif
                    </div>
                </main>
                </div>
            </div>

            <x-admin::layouts.bridge.tabbar />

            {!! view_render_event('mothership.admin.layout.content.after') !!}
        </div>

        {!! view_render_event('mothership.admin.layout.body.after') !!}

        @stack('scripts')
    </body>
</html>
