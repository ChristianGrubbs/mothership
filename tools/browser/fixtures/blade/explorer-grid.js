
app.component('v-dam-explorer-grid', {
    template: '#v-dam-explorer-grid-template',
    emits: ['navigate', 'open-new-tab', 'bookmark', 'refresh', 'internal-drop', 'toggle-select', 'select-range', 'set-selection', 'empty-action', 'stack-drop'],

    props: {
        directories:          { type: Array, default: () => [] },
        assets:               { type: Array, default: () => [] },
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
            marquee: null,
        };
    },

    beforeUnmount() {
        this.endMarquee();
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
        /**
         * Pressing on empty space and dragging draws a box; every folder and file it touches is selected. Shift or
         * Cmd/Ctrl adds to the selection held when the drag began, otherwise the box replaces it. A press without
         * a drag does nothing, as before. Mouse and pen only: a finger on empty space scrolls.
         */
        onSpacePointerDown(e) {
            if (e.button !== 0 || e.pointerType === 'touch' || this.isLoading) return;
            if (e.target.closest('[data-marquee-key], button, a, input, select, textarea, label, [contenteditable="true"], [role="menu"]')) return;
            const box = this.$refs.space.getBoundingClientRect();
            this._marquee = {
                pointerId: e.pointerId,
                x: e.clientX - box.left,
                y: e.clientY - box.top,
                clientX: e.clientX,
                clientY: e.clientY,
                base: e.shiftKey || e.metaKey || e.ctrlKey ? this.selection.ids.map(i => ({ ...i })) : [],
                active: false,
                key: null,
            };
            window.addEventListener('pointermove', this.onMarqueeMove);
            window.addEventListener('pointerup', this.endMarquee);
            window.addEventListener('pointercancel', this.endMarquee);
            window.addEventListener('scroll', this.onMarqueeScroll, { capture: true });
        },
        onMarqueeMove(e) {
            const m = this._marquee;
            if (! m || e.pointerId !== m.pointerId) return;
            if (! m.active && Math.hypot(e.clientX - m.clientX, e.clientY - m.clientY) < 5) return;
            if (! m.active) {
                m.active = true;
                this.closeCtx();
                window.getSelection()?.removeAllRanges();
                m.userSelect = document.body.style.userSelect;
                document.body.style.userSelect = 'none';
            }
            m.clientX = e.clientX;
            m.clientY = e.clientY;
            this.scheduleMarquee();
        },
        onMarqueeScroll() {
            if (this._marquee?.active) this.scheduleMarquee();
        },
        // Pointer and scroll events arrive faster than frames; measuring every card once per frame is enough.
        scheduleMarquee() {
            const m = this._marquee;
            if (! m || m.frame) return;
            m.frame = requestAnimationFrame(() => {
                m.frame = null;
                if (this._marquee === m) this.updateMarquee();
            });
        },
        updateMarquee() {
            const m = this._marquee;
            const space = this.$refs.space;
            if (! space) return;
            const box = space.getBoundingClientRect();
            const cx = m.clientX - box.left, cy = m.clientY - box.top;
            const rect = { left: Math.min(m.x, cx), top: Math.min(m.y, cy), width: Math.abs(cx - m.x), height: Math.abs(cy - m.y) };
            this.marquee = rect;

            const ids = m.base.map(i => ({ ...i }));
            const held = new Set(ids.map(i => `${i.type}:${i.id}`));
            const items = { asset: new Map(this.assets.map(x => [String(x.id), x])), directory: new Map(this.directories.map(x => [String(x.id), x])) };
            // Grouped by stack, the tab renders one grid per group; the box selects across all of them.
            const listing = space.closest('[data-explorer-block]')?.parentElement ?? space;
            listing.querySelectorAll('[data-marquee-key]').forEach(el => {
                const r = el.getBoundingClientRect();
                const left = r.left - box.left, top = r.top - box.top;
                if (left > rect.left + rect.width || left + r.width < rect.left || top > rect.top + rect.height || top + r.height < rect.top) return;
                const [type, id] = el.dataset.marqueeKey.split(':');
                // A card in another group's grid is not in this grid's lists; its key still carries its id.
                const itemId = items[type]?.get(id)?.id ?? (type in items ? Number(id) : NaN);
                if (! Number.isNaN(itemId) && ! held.has(`${type}:${itemId}`)) {
                    held.add(`${type}:${itemId}`);
                    ids.push({ id: itemId, type });
                }
            });

            const key = ids.map(i => `${i.type}:${i.id}`).join(',');
            if (key === m.key) return;
            m.key = key;
            this.$emit('set-selection', ids);
        },
        endMarquee(e) {
            const m = this._marquee;
            // Another pointer lifting or cancelling is not this drag's end; unmount passes no event.
            if (m && e?.pointerId !== undefined && e.pointerId !== m.pointerId) return;
            // A release before the next frame still selects what the box covers at the release point.
            if (e?.type === 'pointerup' && m?.active) {
                if (m.frame) cancelAnimationFrame(m.frame);
                m.frame = null;
                m.clientX = e.clientX;
                m.clientY = e.clientY;
                this.updateMarquee();
            }
            window.removeEventListener('pointermove', this.onMarqueeMove);
            window.removeEventListener('pointerup', this.endMarquee);
            window.removeEventListener('pointercancel', this.endMarquee);
            window.removeEventListener('scroll', this.onMarqueeScroll, { capture: true });
            if (this._marquee?.frame) cancelAnimationFrame(this._marquee.frame);
            if (this._marquee?.active) document.body.style.userSelect = this._marquee.userSelect ?? '';
            this._marquee = null;
            this.marquee = null;
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
                onDetails: true ? () => this.edit(asset.id) : null,
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
            const url = `http://mothership.test/admin/dam/assets/edit/:id`.replace(':id', id);
            this.$navigate(window.MothershipDetailsNavigation ? window.MothershipDetailsNavigation.withNavMarker(url, directoryId) : url);
        },
        del(asset) {
            this.$emitter.emit('open-delete-modal', {
                message: window.MothershipStackDeleteMessage?.(asset),
                agree: () => {
                    window.MothershipFileOps.deleteWithConfirmation({message: ":count item is required by other assets. Delete anyway? Dependent assets keep working but lose the link.|:count items are required by other assets. Delete anyway? Dependent assets keep working but lose the link.", emitter: this.$emitter, request: force => this.$axios.delete(`http://mothership.test/admin/dam/assets/destroy/:id`.replace(':id', asset.id), { data: { force } }) })
                        .then(response => { if (!response) return; const { data } = response; this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "Action completed successfully." }); this.$emit('refresh'); this.$emitter.emit('dam:tree-reload'); })
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
