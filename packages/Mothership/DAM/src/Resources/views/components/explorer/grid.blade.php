@include('dam::components.explorer.marquee')
@once('v-dam-explorer-grid')
@push('scripts')
<script type="text/x-template" id="v-dam-explorer-grid-template">

    <div ref="space" class="relative pr-4 min-h-72" @dragover.prevent @drop.prevent="onSpaceDrop($event)" @pointerdown="onSpacePointerDown">
        <div v-if="isLoading" class="grid grid-cols-1 min-[380px]:grid-cols-2 xl:grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3 min-[640px]:gap-5 xl:gap-6 animate-pulse" :style="tileGridStyle">
            <div v-for="n in 10" :key="n" class="aspect-square bg-gray-100 dark:bg-cherry-800 rounded-lg"></div>
        </div>

        <template v-else>
            <template v-if="directories.length">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,11.5rem),1fr))] gap-3 mb-8">
                    <v-dam-explorer-folder-card
                        v-for="dir in directories"
                        :key="dir.id"
                        :dir="dir"
                        :is-drop-target="dropTargetId === dir.id"
                        :data-dir-id="dir.id"
                        :data-marquee-key="'directory:' + dir.id"
                        :is-selected="isSelectedById(dir.id, 'directory')"
                        :any-selected="selection.ids.length > 0"
                        @click.capture="onCardClick($event, dir.id, 'directory')"
                        @toggle-select="$emit('toggle-select', dir.id, 'directory')"
                        @navigate="$emit('navigate', $event)"
                        @ctx="showCtx($event.event, $event.dir, 'directory')"
                        @drag-start="onDragStart($event, dir)"
                        @drag-end="onDragEnd"
                        @drag-over="onFolderDragOver($event, dir)"
                        @drag-leave="onFolderDragLeave($event, dir)"
                        @drop="onInternalDrop($event, dir)"
                    ></v-dam-explorer-folder-card>
                </div>
            </template>

            <template v-if="assets.length">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
                    @lang('dam::app.admin.explorer.sections.files')
                </p>
                <div class="grid grid-cols-1 min-[380px]:grid-cols-2 xl:grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3 min-[640px]:gap-5 xl:gap-6" :style="tileGridStyle">
                    <v-dam-explorer-asset-card
                        v-for="asset in assets"
                        :key="asset.id"
                        :asset="asset"
                        :tab-id="tabId"
                        :data-marquee-key="'asset:' + asset.id"
                        :is-selected="isSelectedById(asset.id, 'asset')"
                        :any-selected="selection.ids.length > 0"
                        :selection="selection.ids"
                        @click.capture="onCardClick($event, asset.id, 'asset')"
                        @toggle-select="$emit('toggle-select', asset.id, 'asset')"
                        @preview="preview"
                        @edit="edit"
                        @open-stack="openStack"
                        v-on:stack-drop="$emit('stack-drop', $event)"
                        @delete="del"
                        @refresh="$emit('refresh')"
                        @ctx="showCtx($event.event, $event.asset, 'asset')"
                    ></v-dam-explorer-asset-card>
                </div>
            </template>

            <div v-if="!directories.length && !assets.length" class="flex flex-col items-center justify-center py-16 gap-4" data-explorer-empty>
                <img src="{{ mothership_asset('images/no-records-found.svg', 'dam') }}" class="w-32 h-32 opacity-60" alt="" />
                <p class="text-lg font-bold text-gray-700 dark:text-slate-50 text-center">@{{ emptyState?.message ?? @js(trans('admin::app.components.datagrid.table.no-records-available')) }}</p>
                <button v-if="emptyState?.action" type="button" class="secondary-button" @click="$emit('empty-action')">@{{ emptyState.action }}</button>
            </div>
        </template>

        {{-- Drag-select box, in the space's own coordinates so it stays put while the page scrolls. --}}
        <div
            v-if="marquee"
            class="absolute pointer-events-none z-20 rounded-sm"
            :style="{ left: marquee.left + 'px', top: marquee.top + 'px', width: marquee.width + 'px', height: marquee.height + 'px', border: '1px solid #2d5be3', background: 'rgba(45, 91, 227, 0.12)' }"
            aria-hidden="true"
            data-explorer-marquee
        ></div>

        <v-dam-explorer-ctx
            v-if="ctx.on"
            :key="ctxKey"
            :x="ctx.x"
            :y="ctx.y"
            :item="ctx.item"
            :item-type="ctx.type"
            :tab-id="tabId"
            :clipboard="clipboard"
            :selection="selection"
            @close="closeCtx"
            @navigate="$emit('navigate', $event)"
            @bookmark="$emit('bookmark', $event)"
            @refresh="$emit('refresh')"
        ></v-dam-explorer-ctx>
    </div>
</script>

<script type="module">
app.component('v-dam-explorer-grid', {
    template: '#v-dam-explorer-grid-template',
    mixins: [window.MothershipExplorerMarquee],
    emits: ['navigate', 'bookmark', 'refresh', 'internal-drop', 'toggle-select', 'select-range', 'set-selection', 'empty-action', 'stack-drop'],

    props: {
        directories:          { type: Array, default: () => [] },
        assets:               { type: Array, default: () => [] },
        tileSize:             { type: String, default: 'medium' },
        isLoading:            { type: Boolean, default: false },
        emptyState:           { type: Object, default: null },
        tabId:                { type: String, required: true },
        currentDirId:         { type: Number, default: null },
        clipboard:            { type: Object, default: null },
        canAccessCurrentDir:  { type: Boolean, default: true },
        selection:            { type: Object, default: () => ({ ids: [], mode: 'none' }) },
        // The whole result count and this page's offset, handed to the details page's counter.
        resultTotal:          { type: Number, default: null },
        resultOffset:         { type: Number, default: 0 },
        // P1b: whether this page is a search's results (smart or plain), so opening one is search.result.opened.
        searchSmart:          { type: Boolean, default: null },
    },

    data() {
        return {
            ctx: { on: false, x: 0, y: 0, item: null, type: null },
            ctxKey: 0,
            rangeAnchor: null,
            _ctxClose: null,
            _ctxScroll: null,
            _springTimer: null,
            dropTargetId: null,
        };
    },

    computed: {
        tileGridStyle() {
            const width = {small: '8.5rem', large: '21rem'}[this.tileSize];
            return width ? {gridTemplateColumns: `repeat(auto-fill, minmax(min(100%, ${width}), 1fr))`} : null;
        },
    },

    methods: {
        isSelectedById(id, type) {
            return this.selection.ids.some(i => i.id === id && i.type === type);
        },
        onDragStart(e, d) {
            e.dataTransfer.setData('application/json', JSON.stringify({
                type: 'dam-folder',
                id: d.id,
                name: d.name,
                assetsCount: d.assets_count ?? 0,
                tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: d.id, type: 'directory', selection: this.selection.ids }),
            }));
            this._draggingId = d.id;

        },
        onDragEnd() {
            this._draggingId = null;
            this.dropTargetId = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
        },
        onFolderDragOver(e, dir) {
            if (this._draggingId === dir.id) return;
            this.dropTargetId = dir.id;
            if (this._springTimer?.dirId === dir.id) return;
            clearTimeout(this._springTimer?.id);
            const timerId = setTimeout(() => {
                if (this.dropTargetId === dir.id) this.$emit('navigate', dir);
            }, 1500);
            this._springTimer = { dirId: dir.id, id: timerId };
        },
        onFolderDragLeave(e, dir) {

            if (this.dropTargetId === dir.id) {
                this.dropTargetId = null;
                clearTimeout(this._springTimer?.id);
                this._springTimer = null;
            }
        },
        onInternalDrop(e, targetDir) {
            this.dropTargetId = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (payload.type === 'dam-folder' && payload.id === targetDir.id) return;
            this.$emit('internal-drop', { payload, targetDir });
        },
        /**
         * Shift-click selects the range from the last clicked card and Cmd/Ctrl-click toggles one, as in the list view,
         * instead of the card opening. Runs in the capture phase, ahead of the card's own click.
         */
        onCardClick(e, id, type) {
            if (e.shiftKey && this.rangeAnchor) {
                const items = [
                    ...this.directories.map(d => ({ id: d.id, type: 'directory' })),
                    ...this.assets.map(a => ({ id: a.id, type: 'asset' })),
                ];
                const from = items.findIndex(i => i.id === this.rangeAnchor.id && i.type === this.rangeAnchor.type);
                const to = items.findIndex(i => i.id === id && i.type === type);
                if (from !== -1 && to !== -1) {
                    e.stopPropagation();
                    e.preventDefault();
                    const [lo, hi] = from <= to ? [from, to] : [to, from];
                    this.$emit('select-range', items.slice(lo, hi + 1));
                    return;
                }
            }
            if (e.metaKey || e.ctrlKey || e.shiftKey) {
                e.stopPropagation();
                e.preventDefault();
                this.rangeAnchor = { id, type };
                this.$emit('toggle-select', id, type);
                return;
            }
            this.rangeAnchor = { id, type };
        },
        showCtx(e, item, type) {
            this.closeCtx();
            this.ctxKey++;
            // A keyboard activation has no pointer position (0,0): the menu hangs off its trigger instead.
            const trigger = e.clientX === 0 && e.clientY === 0 ? e.target?.closest?.('.dam-ctx-trigger') : null;
            const anchor = trigger ? trigger.getBoundingClientRect() : null;
            this.ctx = { on: true, x: anchor ? anchor.left : e.clientX, y: anchor ? anchor.bottom + 4 : e.clientY, item, type };
            let lastScrollY = window.scrollY, lastScrollX = window.scrollX;
            this._ctxScroll = () => {
                const dy = window.scrollY - lastScrollY, dx = window.scrollX - lastScrollX;
                lastScrollY = window.scrollY; lastScrollX = window.scrollX;
                this.ctx = { ...this.ctx, y: this.ctx.y - dy, x: this.ctx.x - dx };
            };
            this._ctxClose = () => this.closeCtx();
            document.addEventListener('click',  this._ctxClose, { once: true });
            window.addEventListener('scroll', this._ctxScroll, { capture: true });
            this.$emitter.emit(`dam:ctx-open:${this.tabId}`, { item, type });
        },
        closeCtx() {
            this.ctx.on = false;
            if (this._ctxClose)  { document.removeEventListener('click',  this._ctxClose); this._ctxClose  = null; }
            if (this._ctxScroll) { window.removeEventListener('scroll', this._ctxScroll, { capture: true }); this._ctxScroll = null; }
        },
        showSpaceCtx(e) {
            if (! this.currentDirId) return;
            this.showCtx(e, { id: this.currentDirId, can_access: this.canAccessCurrentDir }, 'space');
        },
        trackResultOpened(id) {
            if (this.searchSmart === null) return;
            window.damTrack?.('search.result.opened', id, { position: this.resultOffset + this.assets.findIndex(a => a.id === id) + 1, smart: this.searchSmart }, 'library');
        },
        // D5: a stack's card fans its files out; the fan previews the chosen one or opens the stack's details.
        openStack({ asset, el }) {
            this.trackResultOpened(asset.id);
            this.$emitter.emit('dam:open-stack-fan', {
                asset, el,
                siblings: this.assets.map(a => a.id),
                directoryId: this.currentDirId || 'all',
                total: this.resultTotal,
                offset: this.resultOffset,
                onDetails: @json(bouncer()->hasPermission('dam.asset.edit')) ? () => this.edit(asset.id) : null,
            });
        },
        preview(id)  { this.trackResultOpened(id); this.$emitter.emit('dam-open-preview', { id, siblings: this.assets.map(a => a.id), directoryId: this.currentDirId || 'all', total: this.resultTotal, offset: this.resultOffset }); },
        edit(id) {
            this.trackResultOpened(id);
            // Hand the visible grid order AND the exact browsed scope (this folder, or "all"
            // when currentDirId is null) over, so the details page's chevrons walk the same
            // order and folder this click came from, instead of the server's fallback order.
            const directoryId = this.currentDirId || 'all';
            window.MothershipDetailsNavigation?.writeSiblings({ siblings: this.assets.map(a => a.id), scopeLabel: 'grid', directoryId, total: this.resultTotal, offset: this.resultOffset });
            const url = `{{ route('admin.dam.assets.edit', ':id') }}`.replace(':id', id);
            this.$navigate(window.MothershipDetailsNavigation ? window.MothershipDetailsNavigation.withNavMarker(url, directoryId) : url);
        },
        del(asset) {
            this.$emitter.emit('open-delete-modal', {
                message: window.MothershipStackDeleteMessage?.(asset),
                agree: () => {
                    window.MothershipFileOps.deleteWithConfirmation({message: "@lang('dam::app.admin.dam.asset.delete-blocked-confirm')", emitter: this.$emitter, request: force => this.$axios.delete(`{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', asset.id), { data: { force } }) })
                        .then(response => { if (!response) return; const { data } = response; this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.explorer.action-completed')" }); this.$emit('refresh'); this.$emitter.emit('dam:tree-reload'); })
                        .catch(err => this.$emitter.emit('add-flash', { type: 'error', message: err?.response?.data?.message }));
                },
            });
        },

        onSpaceDrop(e) {
            if (e.dataTransfer?.types?.includes('Files')) return;
            if (! this.currentDirId) return;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;

            if (payload.type === 'dam-folder' && this.directories.some(d => d.id === payload.id)) return;
            if (payload.type === 'dam-asset'  && this.assets.some(a => a.id === payload.id)) return;
            this.$emit('internal-drop', { payload, targetDir: { id: this.currentDirId } });
        },
    },
});
</script>
@endpush
@endonce
