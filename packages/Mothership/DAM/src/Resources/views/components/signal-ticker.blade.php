<section class="signal-ticker" data-signal-ticker data-user="{{ auth('admin')->id() }}" data-api="{{ url(config('app.admin_url').'/home/service/rss') }}" aria-label="AI announcements">
    <strong class="signal-label">SIGNAL</strong>
    <div class="signal-headline" data-signal-headline>Checking announcements…</div>
    <button type="button" data-signal-health aria-label="Feed source health">Checking sources</button>
    <button type="button" data-signal-bell aria-label="Announcement alerts" aria-expanded="false" aria-controls="signal-alerts"><span class="icon-notification" aria-hidden="true"></span><span class="signal-alert-label">Alerts</span><span data-signal-badge hidden></span></button>
    <div id="signal-alerts" class="signal-panel" data-signal-panel hidden>
        <div class="signal-panel-heading"><strong>Model releases &amp; Codex resets</strong><button type="button" data-signal-read>Mark all read</button></div>
        <div class="signal-list" data-signal-list></div>
        <div class="signal-sources" data-signal-sources></div>
        <small data-signal-status>Updates while {{ config('documentation.name') }} is open. Alerts are saved in this browser.</small>
    </div>
    <div class="signal-toast" data-signal-toast role="status" aria-live="polite" hidden></div>
</section>
@pushOnce('scripts')
    <script src="{{ route('admin.home.tool', ['widget'=>'rss', 'path'=>'ticker.js']) }}?v=5" defer></script>
@endPushOnce
