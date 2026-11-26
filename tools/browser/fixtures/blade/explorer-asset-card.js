
app.component('v-dam-explorer-asset-card', {
    template: '#v-dam-explorer-asset-card-template',
    emits: ['preview', 'edit', 'delete', 'ctx', 'toggle-select', 'refresh', 'open-stack'],

    props: {
        asset: { type: Object, required: true },
        tabId: { type: String, required: true },
        isSelected: { type: Boolean, default: false },
        anySelected: { type: Boolean, default: false },
        selection: { type: Array, default: () => [] },
    },

    data() {
        return {
            favorite: false,
            isDropTarget: false,
            dragHint: false,
            thumbnailOverride: null,
            placeholders: {
                video:       'http://mothership.test/themes/dam/build/assets/video-Bceqy86P.svg',
                audio:       'http://mothership.test/themes/dam/build/assets/audio-BbmUgpvq.svg',
                pdf:         'http://mothership.test/themes/dam/build/assets/file-DGthS4_I.svg',
                spreadsheet: 'http://mothership.test/themes/dam/build/assets/sheet-CT3hCK4c.svg',
                csv:         'http://mothership.test/themes/dam/build/assets/csv-G_xsPvx_.svg',
                document:    'http://mothership.test/themes/dam/build/assets/file-DGthS4_I.svg',
                image:       'http://mothership.test/themes/dam/build/assets/image-CtibLiMw.svg',
            },
            fallback: 'http://mothership.test/themes/dam/build/assets/unspecified-j104ePes.svg',
        };
    },

    computed: {
        thumbnailSrc() { return this.thumbnailOverride || this.asset.path; },
        attachmentCount() { return Number(this.asset.attachment_count || 0); },
        /** More than one file a person stacked: the card carries a count that opens into its files. */
        isStack() { return this.attachmentCount > 1; },
        assetName() { return this.asset.display_name || this.asset.file_name; },
        previewLabel() { return 'Preview :name'.replace(':name', () => this.assetName); },
        editLabel() { return 'Edit :name'.replace(':name', () => this.assetName); },
        actionsLabel() { return 'Actions for :name'.replace(':name', () => this.assetName); },
        stackIndicatorLabel() {
            return 'Stack of :count files'.replace(':count', () => this.attachmentCount);
        },
        stackOpenLabel() {
            return 'Show the :count files in this stack'.replace(':count', () => this.attachmentCount);
        },
        stackLine() {
            return window.MothershipStackLine ? window.MothershipStackLine(this.asset) : '';
        },
        /** At most three chips; the rest fold into one "+N" chip whose title names them. */
        versionChips() {
            const chips = Array.isArray(this.asset.versions) ? this.asset.versions : [];
            if (chips.length <= 3) return chips;
            const rest = chips.slice(2);
            return [...chips.slice(0, 2), { kind: 'more', label: `+${rest.length}`, rest: rest.map(c => c.label).join(', ') }];
        },
    },

    watch: {
        'asset.id'() { this.thumbnailOverride = null; },
    },

    async mounted() {
        this.$emitter.on('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.on('dam:asset-drag-state', this.onAssetDragState);
        try { this.favorite = await window.MothershipFavorites.has(this.asset.id); } catch (error) { this.$emitter.emit('add-flash',{type:'error',message:error.message}); }
    },

    beforeUnmount() {
        this.$emitter.off('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.off('dam:asset-drag-state', this.onAssetDragState);
    },

    methods: {
        adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
        // Server-rendered label with its :page placeholder filled in; a function replacement keeps `$&` in a value literal.
        matchPageLabel(asset) {
            return "p. :page".replace(':page', () => asset?.match?.page);
        },
        /**
         * A thumbnail stored from this browser (a video frame, or a 3D model
         * captured client-side per ADR 0006) swaps the card image immediately;
         * :key on the <img> rebuilds the element so a previous load error's
         * fallback styling goes with it.
         */
        onThumbnailUpdated({ id, url } = {}) {
            if (Number(id) === Number(this.asset.id) && url) this.thumbnailOverride = url;
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
        async toggleFavorite() { try { this.favorite = await window.MothershipFavorites.toggle(this.asset.id); } catch (error) { this.$emitter.emit('add-flash',{type:'error',message:error.message}); } },
        openAsset(mode) {
            this.$emit(mode, this.asset.id);
        },
        openStack() {
            this.$emit('open-stack', { asset: this.asset, el: this.$el });
        },
        onImgErr(e) {
            e.target.src = this.placeholders[this.asset.file_type] ?? this.fallback;
            e.target.className = 'w-full h-full object-contain p-4';
        },
        /** Only a stack accepts a dropped asset (OS files and a card's own drag are left to their handlers). */
        onStackDragOver(e) {
            if (! this.isStack || this._dragging) return;
            const types = Array.from(e.dataTransfer?.types || []);
            if (types.includes('Files') || ! types.includes('application/json')) return;
            e.preventDefault();
            this.isDropTarget = true;
        },
        onStackDragLeave(e) {
            if (e.relatedTarget && this.$el.contains(e.relatedTarget)) return;
            this.isDropTarget = false;
        },
        onStackDrop(e) {
            this.isDropTarget = false;
            if (! this.isStack || this._dragging) return;
            const payload = window.MothershipFileOps.parseDropPayload(e.dataTransfer);
            if (payload?.type !== 'dam-asset') return;
            e.preventDefault();
            e.stopPropagation();
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this.$emit('stack-drop', { payload, asset: this.asset });
        },
        /** Any file dragged in the Library (a card or a list row) lights the "drop to add" hint on every stack but its own card. */
        onAssetDragState({ active } = {}) {
            this.dragHint = !! active && ! this._dragging && this.isStack;
        },
        onDragStart(e) {
            this._dragging = true;
            e.dataTransfer.setData('application/json', JSON.stringify({
                type:  'dam-asset',
                id:    this.asset.id,
                name:  (this.asset.display_name || this.asset.file_name),
                tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: this.asset.id, type: 'asset', selection: this.selection }),
            }));

            const size = 96;
            const thumb = this.$el.querySelector('.image-card') ?? this.$el;
            const clone = thumb.cloneNode(true);
            const left = Math.min(Math.max(0, e.clientX - size / 2), window.innerWidth - size);
            const top = Math.min(Math.max(0, e.clientY - size / 2), window.innerHeight - size);
            clone.style.cssText = `position:fixed;top:${top}px;left:${left}px;pointer-events:none;width:${size}px;height:${size}px;overflow:hidden;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);`;
            document.body.appendChild(clone);
            e.dataTransfer.setDragImage(clone, size / 2, size / 2);
            setTimeout(() => clone.remove(), 0);
            requestAnimationFrame(() => { this.$el.style.opacity = '0.4'; });
            // After the drag image is cloned, so the hint is not in it.
            setTimeout(() => this.$emitter.emit('dam:asset-drag-state', { active: true }), 0);
        },
        onDragEnd(e) {
            this._dragging = false;
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            e.currentTarget.style.opacity = '';
        },
    },
});
