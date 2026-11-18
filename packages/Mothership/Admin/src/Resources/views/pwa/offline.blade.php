<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#141414">
    <meta name="color-scheme" content="light dark">
    <title>@lang('admin::pwa.offline-title') — The Mothership</title>
    <style>
        html { background: #f6f7f9; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; background: #f6f7f9; color: #101114; font: 1rem/1.6 system-ui, sans-serif;
            padding: env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px); box-sizing: border-box; }
        main { max-width: 30rem; padding: 2rem; }
        img { width: 64px; height: 64px; }
        h1 { line-height: 1.2; }
        a { display: inline-block; padding: .75rem 1.25rem; border-radius: .6rem; color: white; background: #2D5BE3; font-weight: 600; text-decoration: none; }
        a { min-height: 44px; box-sizing: border-box; }
        a:focus-visible { outline: 3px solid #101114; outline-offset: 4px; }
        @media (prefers-color-scheme: dark) {
            html, body { background: #17181c; color: #f1f5f9; }
            a:focus-visible { outline-color: #f1f5f9; }
        }
    </style>
</head>
<body>
    <main>
        <p>The Mothership</p>
        <h1>@lang('admin::pwa.offline-title')</h1>
        <p>@lang('admin::pwa.offline-description')</p>
        <p>@lang('admin::pwa.offline-unsaved')</p>
        <a href="">@lang('admin::pwa.retry')</a>
    </main>
</body>
</html>
