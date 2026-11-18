<link rel="manifest" href="{{ route('pwa.manifest') }}">
{{-- Opaque 180px icon: iOS fills an icon's transparent corners with black. --}}
<link rel="apple-touch-icon" href="{{ asset('pwa/apple-touch-icon.png') }}">
<meta name="theme-color" content="#141414">
<meta name="apple-mobile-web-app-title" content="The Mothership">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="pwa-offline-message" content="{{ __('admin::pwa.offline-banner') }}">
<script src="{{ asset('pwa/register.js') }}?v=3" defer></script>
<style>
    [data-pwa-install], [data-pwa-ios-install] { display: none; }
    html[data-pwa-installable] [data-pwa-install],
    html[data-pwa-ios-installable] [data-pwa-ios-install] { display: block; }
    {{-- No browser chrome in a Home Screen app shows the connection, so the app says it lost one. Never on the fold or under the home indicator. --}}
    [data-pwa-offline-banner] {
        position: fixed; z-index: 10020; left: 50%; transform: translateX(-50%);
        bottom: calc(1rem + env(safe-area-inset-bottom, 0px)); max-width: calc(100vw - 2rem);
        padding: .625rem 1rem; border-radius: .75rem; background: #101114; color: #fff;
        font: 600 .875rem/1.4 system-ui, sans-serif; box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
    }
    html[data-fold="columns"] [data-pwa-offline-banner] {
        left: calc(var(--seg-trailing-left) + var(--seg-trailing-width) / 2);
        max-width: calc(var(--seg-trailing-width) - 2rem);
    }
</style>
