{{-- The usage collector and its config (P1b, §3.6.6). $collector is null when nothing may be collected on this page
     (analytics off, opted out, DNT or GPC): then neither the config nor the script is sent. --}}
@if ($collector !== null)
    <script type="application/json" id="dam-t">@json($collector)</script>
    @if ($collector['replay'] ?? null)
        @mothershipVite(['src/Resources/assets/js/analytics/replay.js'], 'dam')
    @endif
    @mothershipVite(['src/Resources/assets/js/analytics/collector.js'], 'dam')
@endif
