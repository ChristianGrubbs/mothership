{{-- Device fit for every shell, signed in or not: viewport units, touch targets, and the fold layer that applies only
     where the browser reports two viewport segments. Static files, no cookies, no service worker (public/pwa/device.*). --}}
<link rel="stylesheet" href="{{ asset('pwa/device.css') }}?v=5">
<script src="{{ asset('pwa/device.js') }}?v=3" defer></script>
