
app.component('v-dam-folder-picker', {
    template: '#v-dam-folder-picker-template',
    emits: ['picked', 'close'],

    props: {
        open:           { type: Boolean, default: false },
        tabId:          { type: String, required: true },
        excludedDirIds: { type: Array, default: () => [] },
        // P5c: a filter (Similar images, AI tag review) names its own purpose instead of "Select Destination".
        title:          { type: String, default: '' },
    },

    data() {
        let savedView = 'list';
        try { savedView = localStorage.getItem('dam_picker_view') || 'list'; } catch (e) {}

        return {
            viewMode:        savedView,
            currentDirId:    null,
            breadcrumb:      [],
            dirs:            [],
            loading:         false,
            query:           '',
            searchResults:   [],
            searchLoading:   false,
            debounceTimer:   null,
            creating:        { open: false, name: '', loading: false, error: '' },

            selectedChildId: null,
            returnFocus:     null,
            focusReady:      false,
        };
    },

    // Opening a folder or cancelling a new one removes the control that had focus. Focus then goes to the
    // picker itself, so Tab and Escape still belong to it and never reach the page behind.
    updated() {
        const dialog = this.$refs.dialog;
        if (this.open && this.focusReady && dialog && ! dialog.contains(document.activeElement)) dialog.focus();
    },

    computed: {
        isSearching() {
            return this.query.trim().length >= 2;
        },
        visibleDirs() {
            const excluded = this.excludedDirIds.map(Number);
            if (! excluded.length) return this.dirs;
            return this.dirs.filter(d => ! excluded.includes(Number(d.id)));
        },
        visibleSearchResults() {
            const excluded = this.excludedDirIds.map(Number);
            if (! excluded.length) return this.searchResults;
            return this.searchResults.filter(r => ! excluded.includes(Number(r.id)));
        },
        // Inside a folder being moved or copied is never a valid place for it (the server answers 422 "cannot move").
        insideExcluded() {
            const excluded = this.excludedDirIds.map(Number);

            return this.breadcrumb.some(crumb => crumb.id != null && excluded.includes(Number(crumb.id)));
        },
        canConfirm() {
            return this.currentDirId != null
                && ! this.insideExcluded
                && ! this.excludedDirIds.map(Number).includes(Number(this.currentDirId));
        },
    },

    watch: {
        open(val) {
            if (val) {
                this.reset();
                this.loadRoot();
                // The menu that opened the picker puts focus back on its own button first, so this waits a frame
                // and remembers that button as the place to return to.
                this.$nextTick(() => requestAnimationFrame(() => {
                    this.returnFocus = document.activeElement;
                    this.$refs.search?.focus();
                    this.focusReady = true;
                }));
            } else {
                this.focusReady = false;
                this.returnFocus?.focus?.();
                this.returnFocus = null;
            }
        },
        query(val) {
            clearTimeout(this.debounceTimer);
            this.searchResults = [];
            const q = (val || '').trim();
            if (q.length < 2) { this.searchLoading = false; return; }

            this.selectedChildId = null;
            this.cancelCreate();
            this.searchLoading = true;
            this.debounceTimer = setTimeout(() => this.fetchSearch(q), 300);
        },
    },

    methods: {
        // Escape first clears a typed search; with nothing typed it closes the picker.
        clearQuery(event) {
            if (! this.query) return;
            this.query = '';
            event.preventDefault();
            event.stopPropagation();
        },

        onEscape(event) {
            if (event.defaultPrevented) return;
            // Consumed here, so the page behind does not also clear its selection.
            event.preventDefault();
            event.stopPropagation();
            this.$emit('close');
        },

        trapFocus(event) {
            const focusable = Array.from(this.$refs.dialog?.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])') ?? [])
                .filter(el => el.getClientRects().length > 0);
            if (! focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.dialog)) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        reset() {
            this.currentDirId   = null;
            this.breadcrumb     = [];
            this.dirs           = [];
            this.query          = '';
            this.searchResults  = [];
            this.searchLoading  = false;
            this.selectedChildId = null;
            this.cancelCreate();
            clearTimeout(this.debounceTimer);
        },

        fetchSearch(q) {
            this.$axios.get("http://mothership.test/admin/dam/directory/search", { params: { q, offset: 0 } })
                .then(({ data }) => {
                    this.searchResults = (data.data || []).map(r => ({
                        id:        r.id,
                        name:      r.name,
                        parent_id: r.parent_id,
                        breadcrumb: (r.path_names || []).join(' › '),
                        path_names: r.path_names || [],
                        path_ids:   r.path_ids   || [],
                    }));
                    this.searchLoading = false;
                })
                .catch(() => { this.searchResults = []; this.searchLoading = false; });
        },

        selectSearchResult(result) {
            this.selectedChildId = null;
            this.cancelCreate();
            this.query         = '';
            this.searchResults = [];
            this.currentDirId  = result.id;

            this.breadcrumb = result.path_ids.map((id, i) => ({
                id,
                name: result.path_names[i] ?? '',
            }));
            this.loadChildren(result.id);
        },

        loadRoot() {
            this.loading = true;
            this.$axios.get("http://mothership.test/admin/dam/directory")
                .then(({ data }) => {
                    const root = Array.isArray(data.data) ? data.data[0] : null;
                    if (root) {
                        this.currentDirId = root.id;
                        this.breadcrumb   = [{ id: root.id, name: root.name }];
                        this.loadChildren(root.id);
                    }
                })
                .catch(() => { this.loading = false; });
        },

        loadChildren(dirId) {
            this.loading = true;
            this.$axios.get("http://mothership.test/admin/dam/explorer/data", {
                params: { directory_id: dirId, per_page: 250 },
            }).then(({ data }) => {
                this.dirs    = data.directories ?? [];
                this.loading = false;
            }).catch(() => { this.loading = false; });
        },

        navigateInto(dir) {
            this.selectedChildId = null;
            this.cancelCreate();
            this.currentDirId = dir.id;
            this.breadcrumb.push({ id: dir.id, name: dir.name });
            this.loadChildren(dir.id);
        },

        navigateTo(index) {
            if (index === this.breadcrumb.length - 1) return;
            this.selectedChildId = null;
            this.cancelCreate();
            this.breadcrumb   = this.breadcrumb.slice(0, index + 1);
            const crumb       = this.breadcrumb[index];
            this.currentDirId = crumb.id;
            this.loadChildren(crumb.id);
        },

        openCreate() {
            this.creating = { open: true, name: '', loading: false, error: '' };
            this.$nextTick(() => {
                if (this.$refs.createInput) this.$refs.createInput.focus();
            });
        },

        cancelCreate() {
            this.creating = { open: false, name: '', loading: false, error: '' };
        },

        submitCreate() {
            const name = (this.creating.name || '').trim();
            if (! name || this.creating.loading) return;

            this.creating.loading = true;
            this.creating.error   = '';

            this.$axios.post("http://mothership.test/admin/dam/directory/store", {
                name,
                parent_id: this.currentDirId,
            }).then(({ data }) => {
                const dir = data && data.data;
                this.creating = { open: false, name: '', loading: false, error: '' };

                if (dir && dir.id != null) {

                    if (! this.dirs.some(d => d.id === dir.id)) {
                        this.dirs.unshift({ id: dir.id, name: dir.name });
                    }
                    this.selectedChildId = dir.id;
                }
            }).catch((error) => {
                this.creating.loading = false;
                let msg = "Could not create the folder.";
                const resp = error && error.response;
                if (resp && resp.data) {
                    if (resp.data.errors && resp.data.errors.name && resp.data.errors.name.length) {
                        msg = resp.data.errors.name[0];
                    } else if (resp.data.message) {
                        msg = resp.data.message;
                    }
                }
                this.creating.error = msg;
            });
        },

        confirm() {
            const targetId = this.selectedChildId != null ? this.selectedChildId : this.currentDirId;
            if (! targetId || ! this.canConfirm) return;

            let name = '';
            if (this.selectedChildId != null) {
                const selected = this.dirs.find(d => d.id === this.selectedChildId);
                name = selected ? selected.name : '';
            } else {
                const crumb = this.breadcrumb[this.breadcrumb.length - 1];
                name = crumb ? crumb.name : '';
            }

            this.$emit('picked', { id: targetId, name });
        },

        setView(mode) {
            this.viewMode = mode;
            try { localStorage.setItem('dam_picker_view', mode); } catch (e) {}
        },
    },
});
