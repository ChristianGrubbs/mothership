@php
    $darkModePreference = request()->cookie('dark_mode', 'auto');
@endphp
<!DOCTYPE html>
<html
    lang="{{ app()->getLocale() }}"
    dir="{{ in_array(app()->getLocale(), ['ar_AE']) ? 'rtl' : 'ltr' }}"
    class="{{ $darkModePreference === 'dark' || $darkModePreference === '1' ? 'dark' : '' }}"
>

<head>
    <x-admin::layouts.document-title :title="$title ?? ''" />

    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="base-url" content="{{ rtrim(config('app.url'), '/') }}">
    <meta name="mothership-build-commit" content="{{ config('app.build_commit') }}">
    <meta http-equiv="content-language" content="{{ app()->getLocale() }}">

    {{-- Mirror the admin layout: honour the saved preference; only 'auto' falls back to the OS setting. --}}
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
    @if (request()->routeIs('admin.session.create'))
        <x-admin::layouts.pwa />
    @endif

    <x-admin::layouts.request-context :signed-in="false" />

    @mothershipVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])
    <x-admin::layouts.device />

    {{-- An Apple device draws the system face instead of downloading the theme's web fonts: on a throttled lab load
         of a share page they took the bandwidth app.js needed, and LCP fell from 5.2 s to 3.2 s without them (PostHog
         UX review 2026-10-30, finding 4). Other systems get the theme's Inter. --}}
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Inter", "Helvetica Neue", Helvetica, Arial, sans-serif; }
        h1, h2 { font-family: -apple-system, BlinkMacSystemFont, "Inter", "Helvetica Neue", Helvetica, Arial, sans-serif; }
    </style>

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

    {{-- Library search and file operations are staff tools: a visitor of a share or upload link is never signed in. --}}
    @if (Route::has('admin.dam.assets.index') && auth()->guard('admin')->check())
        @include('dam::components.library-navigation')
    @endif

    @stack('styles')

    <style>
        {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
    </style>

    {!! view_render_event('mothership.admin.layout.head') !!}
</head>

<body class="bridge-body antialiased">
    {!! view_render_event('mothership.admin.layout.body.before') !!}

    {{-- Optional decorative backdrop, kept outside the Vue root so mounting never recreates it. --}}
    {{ $background ?? '' }}

    <div id="app">
        <x-admin::flash-group />

        {!! view_render_event('mothership.admin.layout.content.before') !!}

                {{ $slot }}
    </div>

    {!! view_render_event('mothership.admin.layout.body.after') !!}

    @stack('scripts')

    <script type="text/javascript">
        {!! core()->getConfigData('general.content.custom_scripts.custom_javascript') !!}
    </script>
</body>

</html>
