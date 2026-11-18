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
        <meta name="mothership-build-commit" content="{{ config('app.build_commit') }}">
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

        @php($showSignalTicker = Route::has('admin.home.tool') && auth('admin')->check() && app(\Mothership\DAM\Services\HomeWidgetRegistry::class)->permits('rss') && app(\Mothership\DAM\Services\SignalAlerts::class)->enabledFor(auth('admin')->user()))

        {{-- The announcement strip is the RSS tool's, and a per-account choice (My Account > AI announcements): a role without RSS, or an account with the alerts off, gets neither its markup nor its assets, so nothing polls the feed. --}}
        @if ($showSignalTicker)
            <link rel="stylesheet" href="{{ route('admin.home.tool', ['widget'=>'rss', 'path'=>'ticker.css']) }}?v=7">
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

            {{-- The Bridge shell: the rail runs the full height on the left; the top bar, the strip and the page share the column beside it. --}}
            <div
                class="flex flex-1 min-h-0 group/container {{ ((int) (request()->cookie('sidebar_collapsed') ?? 0)) ? 'sidebar-collapsed' : 'sidebar-not-collapsed' }}"
                ref="appLayout"
            >
                <x-admin::layouts.sidebar />

                <div class="bridge-column">
                    <x-admin::layouts.header />

                    <x-dam::feedback.dialog />

                    @if ($showSignalTicker)
                        @include('dam::components.signal-ticker')
                    @endif

                    <main id="main-content" class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden px-4 pt-3 pb-6 bg-transparent transition-all duration-300 focus:outline-none" tabindex="-1">
                        @if (! request()->routeIs('admin.configuration.index'))
                            <x-admin::layouts.tabs />
                        @endif

                        {{-- One <h1> per page: pages that draw their own (page-header, Home) keep it and this visually hidden one steps aside; any other gets the document title. --}}
                        <h1 class="sr-only [main:has(h1:not([data-page-h1-fallback]))_&]:hidden" data-page-h1-fallback>{{ trim(html_entity_decode(strip_tags((string) ($title ?? '')), ENT_QUOTES | ENT_HTML5)) ?: config('documentation.name') }}</h1>

                        {{ $slot }}
                    </main>
                </div>
            </div>

            <x-admin::layouts.bridge.tabbar />

            {!! view_render_event('mothership.admin.layout.content.after') !!}
        </div>

        <x-admin::layouts.acl-watcher />

        {!! view_render_event('mothership.admin.layout.body.after') !!}

        {{-- The preparing card for DAM downloads; the DAM views mark their download links and call window.damDownload. --}}
        @if (Route::has('admin.dam.assets.index'))
            @include('dam::components.download.preparing')
        @endif

        @stack('scripts')
    </body>
</html>
