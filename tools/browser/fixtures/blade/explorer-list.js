
app.component('v-dam-explorer-list', {
    template: '#v-dam-explorer-list-template',
    mixins: [window.MothershipExplorerMarquee],
    emits: ['navigate','bookmark','sort-change','refresh','internal-drop','toggle-select','select-range','set-selection','empty-action','stack-drop'],

    props: {
        directories:          { type: Array, default: () => [] },
        assets:               { type: Array, default: () => [] },
        isLoading:            { type: Boolean, default: false },
        emptyState:           { type: Object, default: null },
        sortBy:               { type: String, default: 'name' },
        sortOrder:            { type: String, default: 'asc' },
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
        bookmarkedDirIds:     { type: Array, default: () => [] },
    },

    data() { return { ctx: { on: false, x: 0, y: 0, item: null, type: null }, ctxKey: 0, _ctxClose: null, _ctxScroll: null, dropTargetId: null, stackDropId: null, dragHint: false, draggingId: null, rangeAnchor: null, thumbnailOverrides: {} }; },

    mounted() {
        this.$emitter.on('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.on('dam:asset-drag-state', this.onAssetDragState);
    },

    beforeUnmount() {
        this.$emitter.off('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.off('dam:asset-drag-state', this.onAssetDragState);
    },

    methods: {
        // Rows fill the list, so a row's type, size and date cells begin a box too; its name still moves the row.
        marqueeStartsAt(target) {
            if (target.closest('button, a, input, select, textarea, label, [role="checkbox"], [role="menu"], [contenteditable="true"], [data-marquee-no-start]')) return false;
            return ! target.closest('[data-marquee-key]') || !! target.closest('.dam-list-col-type, .dam-list-col-size, .dam-list-col-date');
        },
        // Server-rendered label with its :page placeholder filled in; a function replacement keeps `$&` in a value literal.
        matchPageLabel(asset) {
            return "p. :page".replace(':page', () => asset?.match?.page);
        },
        /**
         * A thumbnail stored while this page is open (a video frame, or a 3D
         * model captured client-side per ADR 0006) replaces the row icon
         * without a refetch; the override is keyed by asset id so a later
         * fetch() that re-seeds `assets` keeps showing it.
         */
        onThumbnailUpdated({ id, url } = {}) {
            if (id != null && url) this.thumbnailOverrides[id] = url;
        },
        assetThumbnail(asset) {
            return this.thumbnailOverrides[asset.id]
                || ((asset.has_custom_thumbnail || asset.has_thumbnail) ? asset.path : null);
        },
        isSelectedById(id, type) {
            return this.selection.ids.some(i => i.id === id && i.type === type);
        },
        /**
         * Visible row order: folders first, then files — the order shift-click ranges follow.
         */
        visibleItems() {
            return [
                ...this.directories.map(d => ({ id: d.id, type: 'directory' })),
                ...this.assets.map(a => ({ id: a.id, type: 'asset' })),
            ];
        },
        markAnchor(id, type) {
            this.rangeAnchor = { id, type };
        },
        toggleRow(id, type) {
            this.markAnchor(id, type);
            this.$emit('toggle-select', id, type);
        },
        selectRangeTo(id, type) {
            const items = this.visibleItems();
            const from  = this.rangeAnchor ? items.findIndex(i => i.id === this.rangeAnchor.id && i.type === this.rangeAnchor.type) : -1;
            const to    = items.findIndex(i => i.id === id && i.type === type);
            if (from === -1 || to === -1) { this.toggleRow(id, type); return; }
            const [lo, hi] = from <= to ? [from, to] : [to, from];
            this.$emit('select-range', items.slice(lo, hi + 1));
        },
        onCheckboxClick(e, item, type) {
            if (e?.detail > 1) return; // second click of a native double-click
            if (e?.shiftKey) { this.selectRangeTo(item.id, type); return; }
            this.toggleRow(item.id, type);
        },
        /**
         * Row click routing. A plain click opens the item only while nothing is selected;
         * once a selection exists (or with Cmd/Ctrl/Shift held) a click selects instead,
         * so building a multi-select never navigates away by accident. While selecting, the
         * row's own preview/edit buttons open an item. There is no dblclick handler, and the
         * second click of a native double-click (event.detail > 1) is ignored, so a double-click
         * can never deselect a row and then open it.
         */
        onRowClick(e, item, type, open) {
            if (e?.detail > 1) return; // second click of a native double-click: never toggle-then-open
            if (e?.shiftKey) { this.selectRangeTo(item.id, type); return; }
            if (e?.metaKey || e?.ctrlKey || this.selection.ids.length > 0) { this.toggleRow(item.id, type); return; }
            open();
        },
        isBookmarked(dir) {
            return this.bookmarkedDirIds.map(Number).includes(Number(dir.id));
        },
        sort(col) {
            const ord = this.sortBy === col && this.sortOrder === 'asc' ? 'desc' : 'asc';
            this.$emit('sort-change', { sortBy: col, sortOrder: ord });
        },
        icon(type) { return { image: 'icon-dam-image', video: 'icon-dam-video', audio: 'icon-dam-audio', document: 'icon-dam-doc' }[type] ?? 'icon-dam-image'; },
        badge(type, ext) {
            if ((ext||'').toLowerCase() === 'pdf')    return 'bg-red-100 text-red-700';
            if (type === 'video' || type === 'audio') return 'bg-primary-100 text-primary-700';
            if (type === 'image')                     return 'bg-primary-100 text-primary-700';
            if (type === 'spreadsheet')               return 'bg-green-100 text-green-700';
            return 'bg-gray-100 text-gray-600';
        },
        stackLine(asset) {
            return window.MothershipStackLine ? window.MothershipStackLine(asset) : '';
        },
        versionChips(asset) {
            const chips = Array.isArray(asset.versions) ? asset.versions : [];
            if (chips.length <= 3) return chips;
            const rest = chips.slice(2);
            return [...chips.slice(0, 2), { kind: 'more', label: `+${rest.length}`, rest: rest.map(c => c.label).join(', ') }];
        },
        chipColor(chip) {
            return { optimized: '#059669', svg: '#7c3aed' }[chip.kind] ?? 'rgba(17,24,39,.75)';
        },
        chipTitle(chip) {
            if (chip.kind === 'optimized') return "Optimized version available";
            if (chip.kind === 'svg') return "SVG version available";
            if (chip.kind === 'more') return `${"Other versions attached"}: ${chip.rest}`;
            return ":type file attached".replace(':type', chip.label);
        },
        fmtSize(b) {
            if (!b) return '—';
            return b >= 1048576 ? (b/1048576).toFixed(1)+' MB' : b >= 1024 ? (b/1024).toFixed(0)+' KB' : b+' B';
        },
        fmtDate(iso) {
            if (!iso) return '—';
            return new Date(iso).toLocaleDateString(undefined, { day:'numeric', month:'short', year:'numeric' });
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
        preview(id) { this.trackResultOpened(id); this.$emitter.emit('dam-open-preview', { id, siblings: this.assets.map(a => a.id), directoryId: this.currentDirId || 'all', total: this.resultTotal, offset: this.resultOffset }); },
        edit(id) {
            this.trackResultOpened(id);
            // Hand the visible list order AND the exact browsed scope (this folder, or "all"
            // when currentDirId is null) over, so the details page's chevrons walk the same
            // order and folder this click came from, instead of the server's fallback order.
            const directoryId = this.currentDirId || 'all';
            window.MothershipDetailsNavigation?.writeSiblings({ siblings: this.assets.map(a => a.id), scopeLabel: 'list', directoryId, total: this.resultTotal, offset: this.resultOffset });
            const url = `http://mothership.test/admin/dam/assets/edit/:id`.replace(':id', id);
            this.$navigate(window.MothershipDetailsNavigation ? window.MothershipDetailsNavigation.withNavMarker(url, directoryId) : url);
        },
        // A stack's row speaks for all its files (VISR-09): "Stack" for its type, the files' total for its size,
        // with the cover's own type and size in the tooltip.
        adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
        rowType(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "Stack" : (asset.adobe?.label || asset.file_type);
        },
        rowTypeTitle(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "Stack · the cover is a :type file".replace(':type', () => String(asset.extension || asset.file_type || '').toUpperCase()) : null;
        },
        rowSize(asset) {
            return Number(asset.attachment_count || 0) > 1 && Number(asset.attachment_size || 0) > 0 ? asset.attachment_size : asset.file_size;
        },
        rowSizeTitle(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "All :count files together".replace(':count', () => asset.attachment_count) : null;
        },
        // A row opens its entry at once, a stack's row its main file. A stack's files open from the count beside its
        // name (D5), which is only rendered for a role that may list them (dam.asset.files.index, R2).
        openAsset(asset, mode) {
            this[mode](asset.id);
        },
        stackOpenLabel(asset) {
            return "Show the :count files in this stack".replace(':count', () => Number(asset.attachment_count || 0));
        },
        openStack({ asset, el }) {
            this.$emitter.emit('dam:open-stack-fan', {
                asset, el,
                siblings: this.assets.map(a => a.id),
                directoryId: this.currentDirId || 'all',
                total: this.resultTotal,
                offset: this.resultOffset,
                onDetails: true ? () => this.edit(asset.id) : null,
            });
        },
        del(asset) {
            this.$emitter.emit('open-delete-modal', {
                message: window.MothershipStackDeleteMessage?.(asset),
                agree: () => {
                    this.$axios.delete(`http://mothership.test/admin/dam/assets/destroy/:id`.replace(':id', asset.id))
                        .then(({ data }) => { this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "Action completed successfully." }); this.$emit('refresh'); this.$emitter.emit('dam:tree-reload'); })
                        .catch(err => this.$emitter.emit('add-flash', { type: 'error', message: err?.response?.data?.message }));
                },
            });
        },
        _makeFolderDragGhost(name) {
            const ghost = document.createElement('div');
            ghost.style.cssText = 'position:fixed;top:0;left:0;width:96px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;border-radius:12px;background:rgba(237,233,254,0.95);box-shadow:0 2px 8px rgba(0,0,0,0.12);pointer-events:none;';
            const icon = document.createElement('i');
            icon.className = 'icon-dam-folder';
            icon.style.cssText = 'font-size:60px;color:#a78bfa;line-height:1;';
            const label = document.createElement('span');
            label.style.cssText = 'font-size:11px;color:#374151;overflow-wrap:anywhere;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;';
            label.textContent = name;
            ghost.appendChild(icon);
            ghost.appendChild(label);
            document.body.appendChild(ghost);
            setTimeout(() => ghost.remove(), 0);
            return ghost;
        },
        _makeAssetDragGhost(fileName, iconClass) {
            const ghost = document.createElement('div');
            ghost.style.cssText = 'position:fixed;top:0;left:0;width:96px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,0.12);pointer-events:none;';
            const icon = document.createElement('i');
            icon.className = iconClass;
            icon.style.cssText = 'font-size:60px;color:#a78bfa;line-height:1;';
            const label = document.createElement('span');
            label.style.cssText = 'font-size:11px;color:#374151;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;width:100%;';
            label.textContent = fileName;
            ghost.appendChild(icon);
            ghost.appendChild(label);
            document.body.appendChild(ghost);
            setTimeout(() => ghost.remove(), 0);
            return ghost;
        },

        _anchorDragGhost(ghost, e, anchorX, anchorY) {
            ghost.style.left = `${Math.min(Math.max(0, e.clientX - anchorX), window.innerWidth - ghost.offsetWidth)}px`;
            ghost.style.top = `${Math.min(Math.max(0, e.clientY - anchorY), window.innerHeight - ghost.offsetHeight)}px`;
        },
        onDirDragStart(e, dir) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({
                type: 'dam-folder', id: dir.id, name: dir.name,
                assetsCount: dir.assets_count ?? 0, tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: dir.id, type: 'directory', selection: this.selection.ids }),
            }));
            const ghost = this._makeFolderDragGhost(dir.name);
            this._anchorDragGhost(ghost, e, 48, 50);
            e.dataTransfer.setDragImage(ghost, 48, 50);
            this._draggingPayload = { type: 'dam-folder', id: dir.id };
            e.currentTarget.style.opacity = '0.4';
        },
        onAssetDragStart(e, asset) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({
                type: 'dam-asset', id: asset.id, name: (asset.display_name || asset.file_name), tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: asset.id, type: 'asset', selection: this.selection.ids }),
            }));
            const iconMap = { image: 'icon-dam-image', video: 'icon-dam-video', audio: 'icon-dam-audio', document: 'icon-dam-doc' };
            const ghost = this._makeAssetDragGhost((asset.display_name || asset.file_name), iconMap[asset.file_type] ?? 'icon-dam-image');
            this._anchorDragGhost(ghost, e, 48, 50);
            e.dataTransfer.setDragImage(ghost, 48, 50);
            this._draggingPayload = { type: 'dam-asset', id: asset.id };
            e.currentTarget.style.opacity = '0.4';
            this.draggingId = asset.id;
            this.$emitter.emit('dam:asset-drag-state', { active: true });
        },
        onDragEnd(e) {
            e.currentTarget.style.opacity = '';
            this.dropTargetId = null;
            this.stackDropId = null;
            this.draggingId = null;
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this._draggingPayload = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
        },
        /** Any dragged file (from a card or a row) shows the hint on this list's stack rows, except the row being dragged. */
        onAssetDragState({ active } = {}) {
            this.dragHint = !! active;
        },
        /** A stack row takes a dropped file or selection, as a stack card does in the grid (OS files and folders are left to their handlers). */
        onStackDragOver(e, asset) {
            if (Number(asset.attachment_count || 0) <= 1 || (this._draggingPayload?.type === 'dam-asset' && this._draggingPayload.id === asset.id)) return;
            const types = Array.from(e.dataTransfer?.types || []);
            if (types.includes('Files') || ! types.includes('application/json')) return;
            e.preventDefault();
            this.stackDropId = asset.id;
        },
        onStackDragLeave(e, asset) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            if (this.stackDropId === asset.id) this.stackDropId = null;
        },
        onStackDrop(e, asset) {
            this.stackDropId = null;
            if (Number(asset.attachment_count || 0) <= 1 || (this._draggingPayload?.type === 'dam-asset' && this._draggingPayload.id === asset.id)) return;
            const payload = window.MothershipFileOps.parseDropPayload(e.dataTransfer);
            if (payload?.type !== 'dam-asset') return;
            e.preventDefault();
            e.stopPropagation();
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this.$emit('stack-drop', { payload, asset });
        },
        onDirDragOver(e, dir) {
            if (this._draggingPayload?.type === 'dam-folder' && this._draggingPayload?.id === dir.id) return;
            this.dropTargetId = dir.id;
            if (this._springTimer?.dirId === dir.id) return;
            clearTimeout(this._springTimer?.id);
            const timerId = setTimeout(() => {
                if (this._springTimer?.dirId === dir.id) {
                    this.$emit('navigate', dir);
                    this._springTimer = null;
                    this.dropTargetId = null;
                }
            }, 1200);
            this._springTimer = { dirId: dir.id, id: timerId };
        },
        onDirDragLeave(e, dir) {
            if (this._springTimer?.dirId !== dir.id) return;
            if (! e.currentTarget.contains(e.relatedTarget)) {
                clearTimeout(this._springTimer?.id);
                this._springTimer = null;
                this.dropTargetId = null;
            }
        },
        onDirDrop(e, dir) {
            this.dropTargetId = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;
            if (payload.type === 'dam-folder' && payload.id === dir.id) return;
            this.$emit('internal-drop', { payload, targetDir: dir });
        },
        onSpaceDrop(e) {
            if (e.dataTransfer?.types?.includes('Files')) return;
            if (! this.currentDirId) return;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;
            if (payload.type === 'dam-folder' && this.directories.some(d => d.id === payload.id)) return;
            if (payload.type === 'dam-asset' && this.assets.some(a => a.id === payload.id)) return;
            this.$emit('internal-drop', { payload, targetDir: { id: this.currentDirId } });
        },
        renameDir(dir) { this.$emitter.emit('dam:open-rename-dir', { item: dir }); },
        delDir(dir) {
            const tabId = this.tabId;
            this.$emitter.emit('open-delete-modal', {
                message: "Deleting this directory also deletes every subdirectory inside it, and that cannot be undone. Its files move to Trash, where they can be restored for 30 days.",
                agree: () => {
                    this.$axios.delete(`http://mothership.test/admin/dam/directory/destroy/:id`.replace(':id', dir.id))
                        .then(({ data }) => {
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "Action completed successfully." });
                            let attempts = 0;
                            const poll = () => {
                                if (++attempts > 15) return;
                                this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'delete_directory'))
                                    .then(({ data: d }) => {
                                        if (d.status === 'completed') {
                                            this.$emitter.emit('add-flash', { type: 'success', message: "Action completed successfully." });
                                            this.$emitter.emit('dam:directory-deleted', { id: dir.id });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                            this.$emitter.emit('dam:tree-reload');
                                            this.$emitter.emit(`dam:dir-deleted:${tabId}`);
                                        } else if (d.status === 'failed') {
                                            this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                        } else { setTimeout(poll, 2000); }
                                    }).catch(() => {});
                            };
                            setTimeout(poll, 1000);
                        })
                        .catch(err => this.$emitter.emit('add-flash', { type: 'error', message: err?.response?.data?.message }));
                },
            });
        },
    },
});
