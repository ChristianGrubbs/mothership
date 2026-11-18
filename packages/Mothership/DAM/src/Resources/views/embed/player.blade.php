{{-- P6 D26: the public embed player. No admin chrome, no session, no download control; the CSP allows the media
     route, the inline styles below and the one nonce'd script, nothing else. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style>
        html, body { margin: 0; height: 100%; background: #000; color: #e5e7eb; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        body { display: flex; align-items: center; justify-content: center; overflow: hidden; }
        video { display: block; width: 100%; height: 100%; object-fit: contain; background: #000; }
        .audio { box-sizing: border-box; width: 100%; padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
        .audio p { margin: 0; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        audio { width: 100%; }
    </style>
</head>
<body data-dam-embed="{{ $kind }}">
    {{-- Chrome answers '' to canPlayType('video/quicktime') yet decodes a .mov, and a <source> whose type it rejects never loads: a QuickTime file names no type. --}}
    @php($typeHint = str_contains(strtolower((string) $mime), 'quicktime') ? null : $mime)
    @if ($kind === 'audio')
        <div class="audio">
            <p title="{{ $title }}">{{ $title }}</p>
            <audio controls controlslist="nodownload" preload="metadata" data-dam-embed-media>
                <source src="{{ $mediaUrl }}"@if ($typeHint) type="{{ $typeHint }}"@endif>
                @lang('dam::app.embed.audio-not-supported')
            </audio>
        </div>
    @else
        <video controls controlslist="nodownload" playsinline preload="metadata" aria-label="{{ $title }}" data-dam-embed-media>
            <source src="{{ $mediaUrl }}"@if ($typeHint) type="{{ $typeHint }}"@endif>
            @lang('dam::app.embed.video-not-supported')
        </video>
    @endif

    {{-- The browser's own "Save video as" sits in the context menu, which no control list removes. --}}
    <script nonce="{{ $nonce }}">
        document.querySelectorAll('[data-dam-embed-media]').forEach(function (media) {
            media.addEventListener('contextmenu', function (event) { event.preventDefault(); });
        });
    </script>
</body>
</html>
