@include('dam::components.explorer.context-menu')

<x-dam::asset.drop-upload />

<v-dam-explorer
    :acl-bypass="{{ dam_acl_bypass() ? 'true' : 'false' }}"
    :accessible-ids='@json(dam_accessible_dir_ids())'
></v-dam-explorer>

@once('v-dam-explorer')
@push('scripts')
<script type="text/x-template" id="v-dam-explorer-template">
    <div class="flex flex-col flex-1 overflow-hidden">

        <div v-if="restoredView" class="flex items-center gap-2 px-3 py-1 text-xs bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-200 border-b border-primary-100 dark:border-primary-800" data-restored-view role="status">
            <span>@lang('dam::app.admin.explorer.restored.notice')</span>
            <span aria-hidden="true">·</span>
            <button type="button" class="font-semibold underline hover:no-underline" @click="resetRestoredView">@lang('dam::app.admin.explorer.restored.reset')</button>
        </div>

        <div
            class="flex items-end gap-0 bg-gray-100 dark:bg-cherry-800 border-b border-gray-200 dark:border-cherry-700 px-2 pt-1.5 overflow-x-auto overflow-y-hidden flex-shrink-0"
            style="scrollbar-width: thin;"
        >
            <div
                v-for="tab in tabs"
                :key="tab.id"
                class="flex items-center gap-1.5 px-3 py-1.5 min-w-[84px] sm:min-w-[110px] max-w-[140px] sm:max-w-[180px] flex-shrink-0 rounded-t-md border border-b-0 text-sm cursor-pointer select-none transition-colors"
                :class="[
                    tab.id === activeTabId
                        ? 'bg-white dark:bg-cherry-900 border-gray-200 dark:border-cherry-700 text-gray-800 dark:text-white font-semibold z-10 -mb-px'
                        : 'bg-transparent border-transparent text-gray-500 hover:bg-white/60 dark:hover:bg-cherry-800 dark:text-white',
                    tabDragHover === tab.id ? 'ring-2 ring-inset ring-primary-400' : ''
                ]"
                @click="setActive(tab.id)"
                @dragover.prevent="onTabDragOver($event, tab.id)"
                @dragleave="onTabDragLeave($event, tab.id)"
            >
                <i class="icon-dam-folder text-base shrink-0" :class="tab.id === activeTabId ? 'text-primary-500' : 'text-gray-400'"></i>
                <span class="truncate flex-1 text-sm">@{{ tab.label }}</span>
                <span
                    v-if="tabs.length > 1"
                    class="tab-close-btn shrink-0 w-4 h-4 flex items-center justify-center rounded text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 text-base leading-none"
                    @click.stop="closeTab(tab.id)"
                    :title="'@lang('dam::app.admin.explorer.tab.close')'"
                >×</span>
            </div>

            <button
                v-if="tabs.length < 20"
                type="button"
                class="w-7 h-7 mb-0.5 ml-1 flex items-center justify-center rounded-md text-gray-400 hover:bg-gray-200 dark:hover:bg-cherry-800 hover:text-gray-700 text-lg font-light shrink-0"
                @click="newTabFromCurrent()"
                :title="'@lang('dam::app.admin.explorer.tab.new')'"
            >+</button>
        </div>

        <template v-for="tab in tabs" :key="tab.id">
            <div v-show="tab.id === activeTabId" class="flex flex-col flex-1 min-h-0 overflow-hidden">
                <v-dam-tab
                    :tab-id="tab.id"
                    :initial-directory-id="tab.directoryId"
                    :initial-search="tab.search"
                    :initial-similarity="tab.similarity"
                    :initial-scope="tab.scope"
                    :initial-media-type="tab.mediaType"
                    :clear-saved-filters="tab.clearSavedFilters"
                    :initial-view-mode="tab.viewMode"
                    :initial-page="tab.page"
                    :initial-per-page="tab.perPage"
                    :acl-bypass="aclBypass"
                    :accessible-ids="accessibleIds"
                    @tab-state-change="onStateChange(tab.id, $event)"
                    @tab-label-change="onLabelChange(tab.id, $event)"
                ></v-dam-tab>
            </div>
        </template>
    </div>
</script>

<script type="module">
app.component('v-dam-explorer', {
    template: '#v-dam-explorer-template',

    props: {
        aclBypass:     { type: Boolean, default: false },
        accessibleIds: { type: Array, default: () => [] },
    },

    data() {
        return { tabs: [], activeTabId: null, _kbHandler: null, tabDragHover: null, restoredView: false };
    },

    computed: {
        // The folder the visible tab is showing; null on All assets.
        activeDirectoryId() {
            const tab = this.tabs.find(t => t.id === this.activeTabId);

            return tab && tab.scope !== 'all' && tab.directoryId ? tab.directoryId : null;
        },
    },

    watch: {
        // The sidebar tree's "New folder" creates here, not in whatever folder it was last highlighted on.
        activeDirectoryId: {
            immediate: true,
            handler(directoryId) {
                window.MothershipExplorerActiveDir = directoryId;
                this.$emitter.emit('dam:explorer-active-dir', { directoryId });
            },
        },
    },

    mounted() {

        let dirId = null;
        try { dirId = sessionStorage.getItem('dam_return_dir'); } catch {}
        this.restore(dirId ? Number(dirId) : null);

        this.$emitter.on('dam:explorer-navigate', ({ directoryId, name, source }) => {
            if (source === 'bookmark') {
                this.$emitter.emit(`dam:tab-navigate:${this.activeTabId}`, { directoryId, name });
            }
        });

        this.$emitter.on('dam:suppress-nav-once', () => {
            this._suppressNextNav = true;
        });

        this.$emitter.on('dam:all-assets', () => {
            this.$emitter.emit(`dam:tab-all-assets:${this.activeTabId}`);
        });

        // P4 organization: a label, board, section, favorites or saved search picked in the sidebar opens in the active tab.
        this.$emitter.on('dam:organize-open', (target) => {
            this.$emitter.emit(`dam:tab-organize:${this.activeTabId}`, target);
        });

        this.$emitter.on('current-directory', (item) => {
            if (this._suppressNextNav) { this._suppressNextNav = false; return; }
            if (item && item.id != null) {
                this.$emitter.emit(`dam:tab-navigate:${this.activeTabId}`, { directoryId: item.id, name: item.name, fromTree: true });
            }
        });

        this.$emitter.on('dam:open-in-new-tab', ({ directoryId, name }) => {
            this.newTab(directoryId, name ?? '…');
        });

        this.$emitter.on('dam:bookmarks-ready', () => {
            const tab = this.tabs.find(t => t.id === this.activeTabId);
            if (tab?.directoryId) {
                this.$emitter.emit('dam:explorer-navigate', { directoryId: tab.directoryId });
            }
        });

        this.$emitter.on('dam:directory-deleted', ({ id }) => {
            const matching = this.tabs.filter(t => t.directoryId === id);
            if (! matching.length) return;
            matching.forEach(t => {
                if (this.tabs.length === 1) {
                    const fresh = this.makeTab();
                    this.tabs.splice(0, 1, fresh);
                    this.activeTabId = fresh.id;
                } else {
                    this.closeTab(t.id);
                }
            });
            this.persistTabs();
        });

        this._kbHandler = (e) => {
            // Not while typing, with a dialog or the preview open (they lock page scroll), or once something handled it.
            if (e.key === 'Escape' && ! e.defaultPrevented && ! e.target.matches?.('input, textarea, select, [contenteditable]') && document.body.style.overflow !== 'hidden') {
                this.$emitter.emit(`dam:explorer-kb-escape:${this.activeTabId}`);
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'c' && ! e.target.matches('input, textarea')) {
                e.preventDefault();
                this.$emitter.emit(`dam:explorer-kb-copy:${this.activeTabId}`);
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'v' && ! e.target.matches('input, textarea')) {
                e.preventDefault();
                this.$emitter.emit(`dam:explorer-kb-paste:${this.activeTabId}`);
            }
        };
        window.addEventListener('keydown', this._kbHandler);

        // Browser Back/Forward walk the folders this page visited (see syncLocation): the active tab follows the url.
        this._popStateHandler = () => {
            const nav = window.MothershipLibraryNavigation;
            const tab = this.tabs.find(t => t.id === this.activeTabId);
            const wanted = nav?.locationState?.(location.search);
            if (wanted?.similarity && history.state?.damSimilarity?.assetId === wanted.similarity.assetId) wanted.similarity = history.state.damSimilarity;
            if (wanted && history.state?.damLibraryView) wanted.listingView = history.state.damLibraryView;
            const plan = nav?.popStatePlan?.(wanted, tab);
            if (plan) this.$emitter.emit(`dam:tab-popstate:${tab.id}`, plan);
        };
        window.addEventListener('popstate', this._popStateHandler);
    },

    beforeUnmount() {
        if (this._kbHandler) window.removeEventListener('keydown', this._kbHandler);
        if (this._popStateHandler) window.removeEventListener('popstate', this._popStateHandler);
    },

    methods: {
        uid() {

            return (typeof crypto !== 'undefined' && crypto.randomUUID)
                ? crypto.randomUUID()
                : Math.random().toString(36).slice(2);
        },

        // Per-account key (F20). Without the shared helper the account is unknown, so nothing is
        // read or written rather than falling back to a key another account could share.
        tabsKey() {
            const nav = window.MothershipLibraryNavigation;
            return nav?.explorerKey ? nav.explorerKey('dam_explorer_tabs', nav.userId) : null;
        },

        makeTab(directoryId = null, label = '…') {
            return { id: this.uid(), directoryId, label, scope: directoryId ? 'directory' : 'all', mediaType: 'all', clearSavedFilters: false, search: '', viewMode: 'grid', page: 1, perPage: null };
        },

        persistTabs() {
            clearTimeout(this._persistTimer);
            this._persistTimer = setTimeout(() => {
                try {
                    const snapshot = this.tabs.map(t => ({
                        directoryId: t.directoryId,
                        scope:       t.scope,
                        mediaType:   t.mediaType,
                        label:       t.label,
                        search:      t.search,
                        similarity:  t.similarity ?? null,
                        viewMode:    t.viewMode,
                        page:        t.page,
                        perPage:     t.perPage,
                    }));
                    const activeIdx = this.tabs.findIndex(t => t.id === this.activeTabId);
                    const key = this.tabsKey();
                    if (key) localStorage.setItem(key, JSON.stringify({ tabs: snapshot, activeIdx }));
                } catch {}
            }, 200);
        },

        normalizeSavedTab(previous = {}) {
            previous = previous && typeof previous === 'object' ? previous : {};
            const candidate = Number(previous.directoryId);
            const directoryId = Number.isSafeInteger(candidate) && candidate > 0 ? candidate : null;
            const scope = previous.scope === 'all' || !directoryId ? 'all' : 'directory';
            const tab = this.makeTab(scope === 'all' ? null : directoryId, typeof previous.label === 'string' ? previous.label : '…');
            tab.scope = scope;
            tab.search = typeof previous.search === 'string' ? previous.search.slice(0, 255) : '';
            const seed = Number(previous.similarity?.assetId);
            tab.similarity = Number.isSafeInteger(seed) && seed > 0 ? {
                assetId: seed, name: String(previous.similarity.name ?? '').slice(0, 255), priorView: previous.similarity.priorView ?? null,
            } : null;
            tab.mediaType = ['all', 'images', 'videos', 'svgs', 'audio', 'models'].includes(previous.mediaType) ? previous.mediaType : 'all';
            tab.viewMode = ['grid', 'list'].includes(previous.viewMode) ? previous.viewMode : 'grid';
            previous.page = Number(previous.page);
            tab.page = Number.isSafeInteger(previous.page) && previous.page > 0 ? previous.page : 1;
            tab.perPage = Number.isSafeInteger(previous.perPage) && previous.perPage > 0 && previous.perPage <= 250 ? previous.perPage : null;
            return tab;
        },

        restore(initialDirId = null) {
            const requested = window.MothershipLibraryNavigation?.locationState?.(location.search) ?? {explicit: false};
            // History is synchronous; tab storage is debounced and may still hold the view before Find similar.
            if (requested.similarity && typeof history !== 'undefined' && history.state?.damSimilarity?.assetId === requested.similarity.assetId) {
                requested.similarity = history.state.damSimilarity;
            }
            let saved = [];
            let activeIdx = 0;
            try {
                const key = this.tabsKey();
                const snapshot = JSON.parse((key && localStorage.getItem(key)) || '{}');
                saved = Array.isArray(snapshot?.tabs) ? snapshot.tabs.slice(0, 20).map(tab => this.normalizeSavedTab(tab)) : [];
                activeIdx = Number.isInteger(snapshot?.activeIdx) ? Math.min(Math.max(snapshot.activeIdx, 0), Math.max(0, saved.length - 1)) : 0;
            } catch {}

            if (requested.explicit) {
                const incoming = this.normalizeSavedTab({...requested, label: requested.scope === 'all' ? 'All assets' : '…'});
                const active = saved[activeIdx];
                const matches = active && ['scope', 'directoryId', 'search', 'mediaType'].every(key => active[key] === incoming[key]) && (active.similarity?.assetId ?? null) === (incoming.similarity?.assetId ?? null);
                this.tabs = saved;
                if (matches && !requested.freshSearch) {
                    // A Back link or bookmark that names a page wins over the remembered one.
                    if (requested.page) active.page = requested.page;
                    this.activeTabId = active.id;
                } else {
                    incoming.clearSavedFilters = true;
                    this.tabs.splice(activeIdx, this.tabs.length ? 1 : 0, incoming);
                    this.activeTabId = incoming.id;
                }
                return;
            }
            if (initialDirId) {
                this.newTab(initialDirId);
                return;
            }
            if (saved.length) {
                // A url naming only a page (no scope, folder, search or media type) pages the
                // restored view rather than replacing it with "all".
                if (requested.page) saved[activeIdx].page = requested.page;
                this.tabs = saved;
                this.activeTabId = saved[activeIdx].id;
                // A bare Library link that opens a remembered folder or search says so, with a way out.
                const remembered = saved[activeIdx];
                if (remembered.search || (remembered.scope !== 'all' && remembered.directoryId)) {
                    this.restoredView = true;
                    this._restoredKey = this.viewKey(remembered);
                }
                return;
            }
            this.newTab();
            if (requested.page && this.tabs[0]) this.tabs[0].page = requested.page;
        },

        newTabFromCurrent() {
            const active = this.tabs.find(t => t.id === this.activeTabId);
            this.newTab(active?.directoryId ?? null, active?.label ?? '…');
            const created = this.tabs.find(t => t.id === this.activeTabId);
            if (created && active) { created.scope = active.scope; created.mediaType = active.mediaType; }
        },

        newTab(directoryId = null, label = '…') {
            if (this.tabs.length >= 20) return;
            const tab = this.makeTab(directoryId, label);
            this.tabs.push(tab);
            this.activeTabId = tab.id;
            if (directoryId) {
                this.$emitter.emit('dam:explorer-navigate', { directoryId });
            }
            this.persistTabs();
        },

        closeTab(id) {
            if (this.tabs.length <= 1) return;
            const idx = this.tabs.findIndex(t => t.id === id);
            this.tabs = this.tabs.filter(t => t.id !== id);
            if (this.activeTabId === id) this.activeTabId = this.tabs[Math.max(0, idx - 1)].id;
            const active = this.tabs.find(t => t.id === this.activeTabId);
            if (active?.directoryId) this.$emitter.emit('dam:explorer-navigate', { directoryId: active.directoryId });
            this.persistTabs();
        },

        setActive(id) {
            this.activeTabId = id;
            const tab = this.tabs.find(t => t.id === id);
            if (tab?.directoryId) {
                this.$emitter.emit('dam:explorer-navigate', { directoryId: tab.directoryId });
            }
            this.persistTabs();
            if (tab) this.syncLocation(tab, { push: false });
        },

        viewKey(tab) {
            return `${tab.scope}|${tab.directoryId ?? ''}|${tab.search ?? ''}`;
        },

        resetRestoredView() {
            this.restoredView = false;
            this.$emitter.emit(`dam:tab-reset:${this.activeTabId}`);
        },

        onStateChange(id, state) {
            const tab = this.tabs.find(t => t.id === id);
            if (! tab) return;
            Object.assign(tab, state);
            // Any move off the restored view makes the notice stale.
            if (this.restoredView && id === this.activeTabId && this.viewKey(tab) !== this._restoredKey) this.restoredView = false;
            this.persistTabs();
            if (id === this.activeTabId) this.syncLocation(tab);
        },

        // A change of folder (or to All assets) is a history entry, so Back returns to the folder before it. Search,
        // paging, media type and tab switches rewrite the current entry instead. The first call after load seeds it.
        syncLocation(tab, { push = true } = {}) {
            const url = new URL(location.href);
            const viewOf = u => `${u.searchParams.get('scope') ?? ''}|${u.searchParams.get('directory_id') ?? ''}|${u.searchParams.get('similar_to') ?? ''}`;
            const before = viewOf(url);
            url.searchParams.delete('search_submit');
            for (const key of [...url.searchParams.keys()]) if (key.startsWith('filters[')) url.searchParams.delete(key);
            url.searchParams.set('scope', tab.scope ?? 'all');
            url.searchParams.set('search', tab.search ?? '');
            if (tab.similarity) {
                url.searchParams.set('similar_to', tab.similarity.assetId);
                url.searchParams.set('similar_name', tab.similarity.name ?? '');
            } else {
                url.searchParams.delete('similar_to'); url.searchParams.delete('similar_name');
            }
            url.searchParams.set('media_type', tab.mediaType ?? 'all');
            if (Number(tab.page) > 1) url.searchParams.set('page', tab.page);
            else url.searchParams.delete('page');
            if (tab.directoryId && tab.scope !== 'all') url.searchParams.set('directory_id', tab.directoryId);
            else url.searchParams.delete('directory_id');
            const seeded = this._locationSeeded === true;
            this._locationSeeded = true;
            const replacingPreview = !! tab.similarity && history.state?.damPreview === true
                && String(tab.similarity.assetId) !== new URL(location.href).searchParams.get('similar_to');
            // Tabs are reactive Vue objects; native history requires plain structured-cloneable values.
            const copy = value => value == null ? null : JSON.parse(JSON.stringify(value));
            // Preserve the original full listing even if Find similar runs before its first fetch has settled.
            if (tab.similarity?.priorView && ! new URL(location.href).searchParams.has('similar_to')) {
                history.replaceState({ ...history.state, damLibraryView: copy(tab.similarity.priorView) }, '', location.href);
            }
            const state = { ...history.state, damSimilarity: copy(tab.similarity), damLibraryView: copy(tab.listingView) };
            // Find similar replaces the transient preview entry. Its deferred close watcher must not Back over it.
            const pushing = push && seeded && viewOf(url) !== before && ! replacingPreview;
            const listingOf = u => JSON.stringify([
                u.searchParams.get('scope') ?? 'all', u.searchParams.get('directory_id') ?? '',
                u.searchParams.get('search') ?? '', u.searchParams.get('media_type') ?? 'all',
                Number(u.searchParams.get('page') ?? 1), u.searchParams.get('similar_to') ?? '',
            ]);
            const previewHistory = {sameViewReplace: ! pushing && ! replacingPreview
                && listingOf(url) === listingOf(new URL(location.href)), preserved: false};
            // Ask the live entry owner before a same-view refetch replaces its listing snapshot.
            // Navigation and closed Forward entries always retire; active owned previews may retain it.
            window.dispatchEvent(new CustomEvent('dam:preview-history-retired', {detail: previewHistory}));
            if (! previewHistory.preserved) {
                delete state.damPreview;
                delete state.damPreviewOwner;
            }
            if (pushing) history.pushState(state, '', url);
            // Keep the owned URL exact while refreshing its snapshot; defer default-query normalization.
            else history.replaceState(state, '', previewHistory.preserved ? location.href : url);
            const input = document.querySelector('[data-asset-search] input[type=search]');
            if (input) input.value = tab.search ?? '';
        },

        onLabelChange(id, label) {
            const tab = this.tabs.find(t => t.id === id);
            if (tab) { tab.label = label; this.persistTabs(); }
        },

        onTabDragOver(e, tabId) {
            if (tabId === this.activeTabId) return;
            if (! e.dataTransfer.types.includes('application/json')) return;
            this.tabDragHover = tabId;
            if (this._tabSpringTimer?.tabId === tabId) return;
            clearTimeout(this._tabSpringTimer?.id);
            const timerId = setTimeout(() => {
                if (this._tabSpringTimer?.tabId === tabId) {
                    this.setActive(tabId);
                    this._tabSpringTimer = null;
                    this.tabDragHover = null;
                }
            }, 1200);
            this._tabSpringTimer = { tabId, id: timerId };
        },

        onTabDragLeave(e, tabId) {
            if (this._tabSpringTimer?.tabId !== tabId) return;
            if (! e.currentTarget.contains(e.relatedTarget)) {
                clearTimeout(this._tabSpringTimer?.id);
                this._tabSpringTimer = null;
                this.tabDragHover = null;
            }
        },
    },
});
</script>
@endpush
@endonce

@include('dam::components.explorer.tab')

@include('dam::components.explorer.toolbar')

@include('dam::components.explorer.view-toggle')

@include('dam::components.explorer.breadcrumb')

@include('dam::components.explorer.input-dialog')

@include('dam::components.explorer.folder-card')

@include('dam::components.explorer.asset-card')

@include('dam::components.explorer.grid')

@include('dam::components.explorer.list')

@include('dam::components.explorer.pagination')
