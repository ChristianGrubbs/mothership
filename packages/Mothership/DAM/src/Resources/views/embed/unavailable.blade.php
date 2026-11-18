{{-- P6 D26: every embed refusal renders exactly this, whatever the reason, so the page tells a probe nothing. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@lang('dam::app.embed.unavailable-title')</title>
    <style>
        html, body { margin: 0; height: 100%; background: #111827; color: #d1d5db; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        body { display: flex; align-items: center; justify-content: center; text-align: center; padding: 16px; box-sizing: border-box; }
        p { margin: 0; font-size: 14px; }
    </style>
</head>
<body data-dam-embed-unavailable>
    <p>@lang('dam::app.embed.unavailable-message')</p>
</body>
</html>
