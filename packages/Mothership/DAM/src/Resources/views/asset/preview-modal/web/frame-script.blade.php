@once
<script type="text/x-template" id="v-dam-web-page-frame-template">
    <div class="flex h-full w-full flex-col">
        <div class="flex items-center justify-end gap-2 px-3 py-1.5 text-sm">
            <span v-if="state === 'preparing'" class="mr-auto text-amber-600">@{{ isMember ? messages['member-preparing'] : messages.preparing }}</span>
            <span v-else-if="state === 'loading' || (state === 'ready' && ! loaded)" class="mr-auto text-gray-500" role="status" data-dam-web-frame-loading>@lang('dam::app.admin.dam.web-pages.loading')</span>
            <span v-else-if="state === 'failed'" class="mr-auto text-red-600">@{{ isMember ? messages['member-failed'] : messages['bundle-failed'] }}</span>
            {{-- The queue was slow, not refused: not an error, and Reload asks again. --}}
            <span v-else-if="state === 'slow'" class="mr-auto text-amber-600" role="status" data-dam-web-frame-slow>@{{ messages['member-slow'] }}</span>
            <a v-if="state === 'ready'" :href="openUrl" target="_blank" rel="noopener" class="secondary-button py-1">@lang('dam::app.admin.dam.web-pages.open-full-screen')</a>
            <button v-if="exitable" type="button" class="secondary-button py-1" data-dam-web-exit @click="exit">@lang('dam::app.admin.dam.web-pages.exit-preview')</button>
            <button v-if="state === 'ready' || state === 'slow'" type="button" class="secondary-button py-1" data-dam-web-reload @click="reload">@lang('dam::app.admin.dam.web-pages.reload')</button>
        </div>
        {{-- A selected file that cannot be shown says so about itself; it is never replaced by another file. --}}
        <div v-if="['stale', 'unavailable', 'unsupported'].includes(state)" class="flex flex-1 flex-col items-center justify-center p-8 text-center" role="status" data-dam-web-member-status :data-state="shownState">
            <p class="max-w-md text-sm text-gray-700 dark:text-gray-200">@{{ messages['member-' + shownState] }}</p>
            <slot name="status-action"></slot>
        </div>
        <iframe
            v-if="state === 'ready'"
            ref="frame"
            sandbox="{{ \Mothership\DAM\Services\WebPagePolicy::SANDBOX }}"
            :src="src"
            :title="fileName"
            @@load="loaded = true"
            class="w-full flex-1 bg-white"
            style="border: 0; min-height: 60vh;"
        ></iframe>
    </div>
</script>
@php
    // Built here, not inline in @json(): Blade splits that directive's argument on commas.
    $frameMessages = [
        'preparing' => __('dam::app.admin.dam.web-pages.preparing'),
        'bundle-failed' => __('dam::app.admin.dam.web-pages.bundle-failed'),
        'member-preparing' => __('dam::app.admin.dam.web-pages.member-preparing'),
        'member-failed' => __('dam::app.admin.dam.web-pages.member-failed'),
        'member-slow' => __('dam::app.admin.dam.web-pages.member-slow'),
        'member-stale' => __('dam::app.admin.dam.web-pages.member-stale'),
        'member-unavailable' => __('dam::app.admin.dam.web-pages.member-unavailable'),
        'member-not-shown' => __('dam::app.admin.dam.web-pages.member-not-shown'),
        'member-checking' => __('dam::app.admin.dam.web-pages.member-checking'),
        'member-check-failed' => __('dam::app.admin.dam.web-pages.member-check-failed'),
        'member-unsupported' => __('dam::app.admin.dam.web-pages.member-unsupported'),
    ];
@endphp
<script type="module">
    // The sandbox attribute above is part of the security boundary (ADR 0007): the token set is
    // WebPagePolicy::SANDBOX and nothing may be added to it.
    // How long and how often a member that is still being prepared is asked again (the page needs a queue worker).
    // When the asks run out the file may simply be waiting in a slow queue: that is the 'slow' state, never 'failed', and only
    // the viewer's Reload asks again (one more bounded round).
    const MEMBER_POLL_MS = 2000;
    const MEMBER_POLL_MAX = 30;
    const FRAME_MESSAGES = @json($frameMessages);

    app.component('v-dam-web-page-frame', {
        template: '#v-dam-web-page-frame-template',
        // `fileId` and `checksum` (the FULL 64 hex) together pin one stack member of the asset; without them this is the
        // asset's own page, unchanged.
        props: {
            assetId: { type: [Number, String], required: true },
            fileName: { type: String, default: '' },
            exitable: { type: Boolean, default: false },
            fileId: { type: [Number, String], default: null },
            checksum: { type: String, default: '' },
            // What the preview's file list says about the pinned file: 'checking' while its one refresh is out, 'unknown' when it
            // could not be read, then 'changed' or 'unavailable'. The server's 404 only says the old id is not current, so the
            // stage waits for that answer instead of guessing, and the strip and the stage never disagree.
            statusKind: { type: String, default: '' },
        },
        data() { return { loaded: false, state: 'loading', src: '', nonce: 0, captured: false, generation: 0, loadedAssetId: null, pollTimer: null, controller: null }; },
        computed: {
            messages() { return FRAME_MESSAGES; },
            isMember() { return this.fileId !== null && this.fileId !== '' && this.checksum !== ''; },
            shownState() {
                if (! ['stale', 'unavailable'].includes(this.state)) return this.state;

                // A 404 on its own (no preview list answered for it, as on the standalone full-screen page) covers a revised, removed,
                // moved or no-longer-accessible file, so it stays neutral; only the preview's confirmed answer says "no longer part".
                return { changed: 'stale', unavailable: 'unavailable', checking: 'checking', unknown: 'check-failed' }[this.statusKind]
                    || (this.state === 'unavailable' ? 'not-shown' : this.state);
            },
            memberQuery() { return this.isMember ? `?file=${encodeURIComponent(this.fileId)}&checksum=${encodeURIComponent(this.checksum)}` : ''; },
            openUrl() { return `{{ url(config('app.admin_url').'/dam/pages') }}/${this.assetId}/open${this.memberQuery}`; },
        },
        mounted() { this.load(); window.addEventListener('message', this.onMessage); },
        beforeUnmount() {
            // Closing the preview invalidates the in-flight grant and any scheduled poll: nothing may repaint afterwards.
            this.generation++;
            window.clearTimeout(this.pollTimer);
            this.controller?.abort?.();
            window.removeEventListener('message', this.onMessage);
        },
        watch: {
            assetId() { this.load(); },
            fileId() { this.load(); },
            checksum() { this.load(); },
        },
        methods: {
            async load(attempt = 0) {
                // Bumped so a stale in-flight grant/capture for a previous assetId (the modal was
                // switched to another asset while this one was still resolving) can recognise
                // itself as stale and drop its result instead of writing it against the new asset.
                // The pinned member is part of that identity: a late answer for another file is dropped too.
                const gen = ++this.generation;
                window.clearTimeout(this.pollTimer);
                this.controller?.abort?.();
                const controller = typeof AbortController === 'function' ? new AbortController() : null;
                this.controller = controller;
                const id = this.assetId;
                const query = this.memberQuery;
                const member = this.isMember;
                if (attempt === 0) { this.state = 'loading'; this.src = ''; this.loaded = false; }
                try {
                    const response = await fetch(`{{ url(config('app.admin_url').'/dam/pages') }}/${id}/grant${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller?.signal });
                    const body = await response.json().catch(() => ({}));
                    if (gen !== this.generation) return;
                    if (member && response.status === 422) { this.state = 'unsupported'; return; }
                    if (member && response.status === 404) { this.state = 'unavailable'; this.announceChange('unavailable'); return; }
                    if (response.status === 409) {
                        if (body.state === 'stale') { this.state = 'stale'; this.announceChange('stale'); return; }
                        if (body.state === 'failed') { this.state = 'failed'; return; }
                        this.state = 'preparing';
                        if (member && attempt < MEMBER_POLL_MAX) this.pollTimer = window.setTimeout(() => this.load(attempt + 1), MEMBER_POLL_MS);
                        else if (member) this.state = 'slow';
                        return;
                    }
                    if (! response.ok || ! body.url) { this.state = 'failed'; return; }
                    this.loadedAssetId = id; this.captured = false; this.src = body.url; this.state = 'ready';
                } catch (error) { if (gen === this.generation) this.state = 'failed'; }
            },
            // Tells the preview that the file it pinned changed or went away, so it can refresh its file list once and say so.
            announceChange(kind) { this.$el.dispatchEvent(new CustomEvent('dam-web-member-changed', { bubbles: true, detail: { kind } })); },
            // Re-fetch the grant rather than reusing this.src: the old grant may have expired,
            // or a re-upload may have bumped the bundle version and voided it. Routed through
            // load() so it shares the same generation bump.
            reload() { this.load(); },
            // Escape typed inside the sandboxed frame never reaches the parent, so the toolbar carries a visible way out.
            exit() { this.$el.dispatchEvent(new CustomEvent('dam-exit-preview', { bubbles: true })); },
            // The frame runs in a sandboxed opaque origin: it can only postMessage, never call
            // back into the parent directly, so this listener is the sole way a capture reaches
            // storage. Only a bounded base64 JPEG data URL is accepted; nothing supplied by the
            // page (e.g. a page-chosen URL) is ever fetched.
            async onMessage(event) {
                const frame = this.$refs.frame;
                // A stack member is never captured as the asset's thumbnail (the server injects no capture script into it).
                if (this.isMember || ! frame || event.source !== frame.contentWindow || this.captured) return;
                const data = event.data || {};
                if (data.type !== 'mothership:web-page-thumbnail' || typeof data.jpeg !== 'string') return;
                const match = /^data:image\/jpeg;base64,((?:[A-Za-z0-9+/]{4})+(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?)$/.exec(data.jpeg);
                if (! match || match[1].length > 2800000) return;
                // Captured at entry: the asset this message's frame belongs to, and the
                // generation in force when it arrived. this.assetId may have moved on by the
                // time the POST below resolves, so the request and the completion event both
                // use `id`, never `this.assetId`.
                const gen = this.generation;
                const id = this.loadedAssetId;
                if (id === null) return;
                this.captured = true;
                try {
                    const bytes = Uint8Array.from(atob(match[1]), (c) => c.charCodeAt(0));
                    const body = new FormData();
                    body.append('thumbnail', new Blob([bytes], { type: 'image/jpeg' }), 'page.jpg');
                    body.append('_token', @json(csrf_token()));
                    const response = await fetch(`{{ url(config('app.admin_url').'/dam/assets') }}/${id}/custom-thumbnail`, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (gen !== this.generation) return;
                    if (! response.ok) { this.captured = false; return; }
                    window.dispatchEvent(new CustomEvent('dam:web-page-thumbnail', { detail: { assetId: Number(id) } }));
                } catch (error) {
                    if (gen === this.generation) this.captured = false;
                }
            },
        },
    });
</script>
@endonce
