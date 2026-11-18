@once('v-dam-gallery-folders')
<x-dam::tree.asset-count-badge />
@push('scripts')
<script type="text/x-template" id="v-dam-gallery-folders-template">
    <div v-if="children.length" class="mb-6">
        <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
            @lang('dam::app.admin.dam.index.folders')
        </p>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(110px,1fr))] gap-2">
            <div
                v-for="dir in children"
                :key="dir.id"
                class="relative flex flex-col items-center gap-1.5 px-2 py-3 rounded-xl text-center cursor-pointer transition-colors select-none min-w-0"
                :class="dragOverId === dir.id
                    ? 'ring-2 ring-primary-400 bg-primary-100 dark:bg-primary-900/40'
                    : 'hover:bg-primary-100 dark:hover:bg-primary-800/50'"
                :data-dir-id="dir.id"
                @click="onClick(dir)"
                @dragover.prevent="onDragOver($event, dir)"
                @dragleave="onDragLeave($event, dir)"
                @drop="onDrop($event, dir)"
            >
                <i class="icon-dam-folder text-6xl text-primary-400 dark:text-primary-500 shrink-0 leading-none"></i>
                <div class="flex items-center gap-1 max-w-full">
                    <span class="text-xs text-gray-700 dark:text-gray-300 line-clamp-2 break-words leading-tight" style="overflow-wrap:anywhere" :title="dir.name">@{{ dir.name }}</span>
                    <v-asset-count-badge :count="dir.assets_total_count ?? null" />
                </div>
            </div>

            <div
                v-if="hasMore"
                class="flex flex-col items-center justify-center gap-1.5 px-2 py-3 rounded-xl text-center cursor-pointer select-none min-w-0 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-800/50"
                @click="loadMore"
            >
                <i class="icon-dam-folder text-6xl opacity-50 shrink-0 leading-none"></i>
                <span class="text-xs">@lang('dam::app.admin.dam.index.directory.load-more')</span>
            </div>
        </div>
    </div>
</script>

<script type="module">
app.component('v-dam-gallery-folders', {
    template: '#v-dam-gallery-folders-template',

    props: {
        // The root's first page with counts, rendered into the page (dam_library_root_page()), so the
        // strip has its final height at mount instead of landing above the grid after a debounced XHR.
        seed: {
            type: Object,
            default: null,
        },
    },

    data() {
        const seed = this.seed && ! this.restoresDirectory() ? this.seed : null;

        return {
            currentDirId: null,
            rootId: seed ? seed.root_id : null,
            children: seed ? seed.children : [],
            offset: seed ? seed.children.length : 0,
            // Mirrors DirectoryRepository::DEFAULT_TREE_PAGE_SIZE; the controller falls back to the
            // same default when the query param is omitted, this just keeps offsets in lockstep.
            limit: 100,
            hasMore: seed ? !! seed.has_more : false,
            dragOverId: null,
            _reloadTimer: null,
            _requestId: 0,
            // The tree's root emit for the seeded root then needs no second request.
            _lastRequestedId: seed ? seed.root_id : null,
        };
    },

    mounted() {
        this.$emitter.on('current-directory', this.onCurrentDirectory);
        this.$emitter.on('dam:tree-root', this.onTreeRoot);
        this.$emitter.on('dam:tree-reload', this.scheduleReload);
        this.$emitter.on('dam:directory-deleted', this.scheduleReload);
        this.$emitter.on('data-grid:refresh', this.scheduleReload);
        this.$emitter.on('dam:folder-drop-uploaded', this.scheduleReload);
    },

    beforeUnmount() {
        this.$emitter.off('current-directory', this.onCurrentDirectory);
        this.$emitter.off('dam:tree-root', this.onTreeRoot);
        this.$emitter.off('dam:tree-reload', this.scheduleReload);
        this.$emitter.off('dam:directory-deleted', this.scheduleReload);
        this.$emitter.off('data-grid:refresh', this.scheduleReload);
        this.$emitter.off('dam:folder-drop-uploaded', this.scheduleReload);
        clearTimeout(this._reloadTimer);
    },

    methods: {
        // The Library page (asset/index.blade.php mounted()) reopens a folder from the same three
        // sources, checked in the same order; the root seed would only flash before that folder's
        // children replace it. Runs in data(), before the page's mounted() consumes dam_return_dir.
        restoresDirectory() {
            const nav = window.MothershipLibraryNavigation?.locationState?.(location.search);
            let dirId = null;

            try { dirId = sessionStorage.getItem('dam_return_dir'); } catch {}

            if (! dirId) {
                try { dirId = new URLSearchParams(location.search).get('filters[directory_id][]'); } catch {}
            }

            if (! dirId && ! (nav?.explicit ?? false)) {
                try {
                    const damSrc = "{{ route('admin.dam.assets.index') }}";
                    const datagrids = JSON.parse(localStorage.getItem('datagrids') || '[]');
                    const entry = datagrids.find(d => d.src === damSrc + '?media_type=all') || datagrids.find(d => d.src === damSrc);
                    dirId = entry?.applied?.filters?.columns?.find(c => c.index === 'directory_id')?.value?.[0] ?? null;
                } catch {}
            }

            return !! dirId && nav?.scope !== 'all';
        },

        onCurrentDirectory(item) {
            this.currentDirId = item ? item.id : null;
            // resetFilters() fires 'data-grid:refresh' right alongside 'current-directory' (null);
            // routing through the debounced reload collapses that pair into one request instead of two.
            this.scheduleReload();
        },

        onTreeRoot({ id } = {}) {
            // The tree mounts twice on the Library page and both instances emit before the first
            // children response lands, so comparing against children.length rarely caught the
            // duplicate. Track the id we actually last requested instead.
            if (id === this.rootId && this._lastRequestedId === id) return;
            this.rootId = id ?? null;
            // Only the "All assets" view (no current directory chosen yet) waits on the root id.
            if (! this.currentDirId) {
                this.scheduleReload();
            }
        },

        scheduleReload() {
            clearTimeout(this._reloadTimer);
            // Invalidate any in-flight request and a pending "Load more" click immediately, so a
            // directory switch (or the tree's double mount) can't have a stale response, or a
            // loadMore() fired against the old directory, land after the switch.
            this._requestId++;
            this.hasMore = false;
            this._reloadTimer = setTimeout(() => this.load(), 200);
        },

        targetId() {
            return this.currentDirId ?? this.rootId ?? null;
        },

        load() {
            const id = this.targetId();
            const requestId = ++this._requestId;
            this._lastRequestedId = id;
            // Keep the previous children on screen until the fresh page lands, so a refresh (or the
            // tree's double mount) doesn't blank the strip and pop it back in a moment later.
            if (! id) {
                this.offset = 0;
                this.children = [];
                this.hasMore = false;
                return;
            }

            this.$axios
                .get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', id), { params: { offset: 0, limit: this.limit } })
                .then((response) => {
                    if (requestId !== this._requestId) return;
                    // Keep the counts already on screen until fetchCounts() refreshes them: a badge
                    // that drops out and comes back rewraps the folder name and nudges the grid.
                    const known = new Map(this.children.map((node) => [node.id, node.assets_total_count]));
                    this.children = (response.data.data || []).map((node) => (
                        node.assets_total_count == null && known.get(node.id) != null
                            ? { ...node, assets_total_count: known.get(node.id) }
                            : node
                    ));
                    this.hasMore = !! response.data.has_more;
                    this.offset = this.children.length;
                    this.fetchCounts(this.children);
                })
                .catch(() => {
                    // A failed request for a directory we've since navigated away from must not
                    // stomp on the newer directory's (already-superseded) state.
                    if (requestId !== this._requestId) return;
                    this.children = [];
                    this.hasMore = false;
                    this.offset = 0;
                });
        },

        loadMore() {
            const id = this.targetId();
            const requestId = this._requestId;
            if (! id) return;

            this.$axios
                .get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', id), { params: { offset: this.offset, limit: this.limit } })
                .then((response) => {
                    if (requestId !== this._requestId) return;
                    const more = response.data.data || [];
                    this.children.push(...more);
                    this.hasMore = !! response.data.has_more;
                    this.offset = this.children.length;
                    this.fetchCounts(more);
                })
                .catch(() => {});
        },

        fetchCounts(nodes) {
            const list = (nodes || []).filter((node) => node && node.id != null);
            if (! list.length) return;

            this.$axios
                .post("{{ route('admin.dam.directory.asset_counts') }}", { ids: list.map((node) => node.id) })
                .then((response) => {
                    const counts = response.data.data || {};
                    list.forEach((node) => {
                        const value = counts[node.id];
                        if (value !== undefined) node.assets_total_count = value;
                    });
                })
                .catch(() => {});
        },

        onClick(dir) {
            this.$emitter.emit('dam:reveal-directory', { id: dir.id });
        },

        onDragOver(event, dir) {
            // dataTransfer.getData() returns '' during dragover (protected mode), so
            // parseDropPayload can't identify the payload yet here — go by types instead.
            const types = Array.from(event.dataTransfer?.types || []);
            this.dragOverId = (types.includes('application/json') && ! types.includes('Files')) ? dir.id : null;
        },

        onDragLeave(event, dir) {
            if (this.dragOverId === dir.id) this.dragOverId = null;
        },

        onDrop(event, dir) {
            this.dragOverId = null;
            // Only claim asset drops: folder drags are finalized by the tree's own drop handling,
            // so a folder payload (or anything unparseable) must keep propagating.
            const payload = window.MothershipFileOps.parseDropPayload(event.dataTransfer);
            if (payload?.type !== 'dam-asset') return;
            event.preventDefault();
            event.stopPropagation();
            this.$emitter.emit('dam:native-asset-drop', { targetId: dir.id, targetName: dir.name, ids: payload.ids || [payload.id] });
        },
    },
});
</script>
@endpush
@endonce
