{{-- Drag-select for the explorer's grid and list. The view supplies `ref="space"`, the box element and `data-marquee-key` on each item. --}}
@pushOnce('scripts')
    <script>
        window.MothershipExplorerMarquee = {
            data() {
                return { marquee: null };
            },

            beforeUnmount() {
                this.endMarquee();
            },

            methods: {
                // Where a press may begin a box. The list, whose rows leave little empty space, widens this.
                marqueeStartsAt(target) {
                    return ! target.closest('[data-marquee-key], button, a, input, select, textarea, label, [contenteditable="true"], [role="menu"]');
                },
                /**
                 * Pressing on empty space and dragging draws a box; every folder and file it touches is selected. Shift or
                 * Cmd/Ctrl adds to the selection held when the drag began, otherwise the box replaces it. A press without
                 * a drag does nothing, as before. Mouse and pen only: a finger on empty space scrolls.
                 */
                onSpacePointerDown(e) {
                    if (e.button !== 0 || e.pointerType === 'touch' || this.isLoading) return;
                    if (! this.marqueeStartsAt(e.target)) return;
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
                // A press that began a box on a draggable row draws the box instead of moving the row.
                onMarqueeDragStart(e) {
                    if (! this._marquee) return;
                    e.preventDefault();
                    e.stopPropagation();
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
                // The click that follows a box's release belongs to the box: on a list row it would open the row.
                swallowMarqueeClick() {
                    const swallow = e => {
                        e.stopPropagation();
                        e.preventDefault();
                    };
                    window.addEventListener('click', swallow, { capture: true, once: true });
                    setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 50);
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
                        this.swallowMarqueeClick();
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
            },
        };
    </script>
@endPushOnce
