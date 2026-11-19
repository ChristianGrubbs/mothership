{{-- Device fit for every shell, signed in or not: viewport units, touch targets, and the fold layer that applies only
     where the browser reports two viewport segments. Static files, no cookies, no service worker (public/pwa/device.*).
     Each file's address carries a hash of its content. Production serves both as immutable for 30 days
     (dockerfiles/nginx.conf), so a deploy that changes a file must also change its address, or a browser that has the
     old file keeps it. A missing file gets a fixed word instead, so the page still renders. --}}
@php($deviceFileVersion = static fn (string $file): string => is_file(public_path($file)) ? substr(md5_file(public_path($file)), 0, 8) : 'missing')
<link rel="stylesheet" href="{{ asset('pwa/device.css') }}?v={{ $deviceFileVersion('pwa/device.css') }}">
<script src="{{ asset('pwa/device.js') }}?v={{ $deviceFileVersion('pwa/device.js') }}" defer></script>
