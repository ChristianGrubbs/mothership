{{--
    P4: the explorer tab's organization behaviour, kept out of tab.blade.php as a mixin. A tab can show one label,
    board, section, the viewer's favorites or a saved search (the server re-runs its stored definition), adds the
    orientation / favorites / label filters to the filter drawer, offers manual order inside a container and
    popularity behind its flag, and keeps the viewer's sort on the server.
    Included inline inside tab.blade.php's own push, just before the tab component, so the mixin exists first
    (Blade orders pushed content by render depth, so a push of its own would land after the tab).
--}}
<script type="module">
    window.MothershipOrganizeTab = {
        data() {
            return {
                organization: null,
                organizeState: window.MothershipOrganize?.state ?? null,
            };
        },

        computed: {
            organizeT() { return window.MothershipOrganize?.t ?? {}; },
            organizeContainer() { return ['label', 'board', 'section'].includes(this.organization?.type); },
            organizeKind() {
                const t = this.organizeT.container ?? {};

                return this.organization?.type === 'saved' ? t.saved : (t[this.organization?.type] ?? '');
            },
        },

        methods: {
            // The request parameters for the open label, board, section, favorites or saved search.
            organizeParams() {
                const target = this.organization;
                if (! target) return {};
                if (target.type === 'label' || target.type === 'board') return { label_id: target.id };
                if (target.type === 'section') return { section_id: target.id };
                if (target.type === 'favorites') return { filter_favorites: 1 };
                if (target.type === 'saved') return { saved_search_id: target.id };

                return {};
            },

            // Typing a different search leaves the saved search: it is the stored query, not a starting point.
            organizeBeforeFetch() {
                if (this.organization?.type === 'saved' && (this.search || '') !== (this.organization.definition?.search ?? '')) this.organizeReset();
            },

            // The filter drawer's orientation, favorites and label columns.
            organizeFilterParams() {
                const params = {};
                this.applied.filters.columns.forEach((column) => {
                    const value = column.value ?? [];
                    if (column.index === 'orientation' && value[0]) params.filter_orientation = value[0];
                    if (column.index === 'favorites' && value[0] === 'yes') params.filter_favorites = 1;
                    if (column.index === 'labels' && value.length) params.filter_labels = value.map(Number);
                    if (column.index === 'labels_mode' && value[0]) params.filter_labels_mode = value[0];
                });

                return params;
            },

            // Each listed file learns which container it is shown in, for the context menu's move and remove.
            organizeAfterFetch(data) {
                if (! this.organizeContainer || ! Array.isArray(this.assets)) return;
                const target = { type: this.organization.type, id: this.organization.id, name: this.organization.name, manual: this.sortBy === 'position' };
                this.assets = this.assets.map((asset) => ({ ...asset, organize: target }));
            },

            openOrganization(target) {
                if (! target?.type) return;
                this.clearSelection();
                this.organization = { ...target };
                this.scope = 'all';
                this.currentDirId = null;
                this.breadcrumb = [];
                this.similarTo = null; this.similarName = '';
                this.page = 1;

                if (target.type === 'saved') {
                    // The server runs the stored definition; the box shows its words so relevance ordering follows.
                    const definition = target.definition ?? {};
                    this.search = this.searchInput = definition.search ?? '';
                    this.mediaType = definition.media_type ?? 'all';
                    if (definition.sort_by) { this.sortBy = definition.sort_by; this.sortOrder = definition.sort_order ?? 'desc'; }
                } else {
                    this.search = this.searchInput = '';
                    this.sortBeforeSearch = null;
                    if (target.type === 'section' && target.default_sort) {
                        [this.sortBy, this.sortOrder] = target.default_sort.split(':');
                    } else if (this.sortBy === 'position' && ! this.organizeContainer) {
                        this.organizeDefaultSort();
                    }
                }

                this.$emit('tab-label-change', target.name ?? this.organizeKind);
                this.$emitter.emit('dam:organize-active', { type: target.type, id: target.id ?? null });
                // The sidebar entry may sit far down a scrolled page: open the results at their top, not mid-list.
                window.scrollTo({ top: 0 });
                this.fetch();
            },

            // Leaving a container (All assets, a folder, Back): manual order belongs to the container.
            organizeReset() {
                if (! this.organization) return;
                this.organization = null;
                if (this.sortBy === 'position') this.organizeDefaultSort();
                this.$emitter.emit('dam:organize-active', null);
            },

            organizeDefaultSort() {
                const saved = this.organizeState?.sort;
                [this.sortBy, this.sortOrder] = (saved || window.MothershipLibraryNavigation.defaultSort).split(':');
            },

            clearOrganization() {
                this.organizeReset();
                this.showAllAssets();
            },

            // Manual order inside a label, board or section; popularity only behind its flag.
            organizeSortOptions(options) {
                const out = [...options];
                if (this.organizeContainer) out.unshift({ value: 'position:asc', label: this.organizeT.sort?.position });
                if (this.organizeState?.popularity) out.push({ value: 'popularity:desc', label: this.organizeT.sort?.popularity });

                return out;
            },

            // The viewer's sort is kept on the server (relevance and manual order belong to one search or container).
            organizePersistSort(sortBy, sortOrder) {
                if (sortBy === 'relevance' || sortBy === 'position' || ! window.MothershipOrganize) return;
                if (this.organizeState) this.organizeState.sort = `${sortBy}:${sortOrder}`;
                this.$axios.put(window.MothershipOrganize.url('preferences'), { sort: `${sortBy}:${sortOrder}` })
                    .catch(() => this.$emitter.emit('add-flash', { type: 'warning', message: this.organizeT.sort?.['saved-failed'] }));
            },

            // The first sidebar load brings the server's sort: it wins over this browser's copy.
            organizeApplyState(state) {
                this.organizeState = state;
                this.organizeSyncLabelOptions();
                if (this._organizeSortApplied) return;
                this._organizeSortApplied = true;
                const current = `${this.sortBy}:${this.sortOrder}`;
                if (this.initialRecent || ! state?.sort || state.sort === current || this.sortBy === 'relevance' || this.organization) return;
                [this.sortBy, this.sortOrder] = state.sort.split(':');
                try { localStorage.setItem('dam_explorer_sort', state.sort); } catch {}
                this.page = 1;
                this.fetch();
            },

            organizeSyncLabelOptions() {
                const column = this.available.columns.find((c) => c.index === 'labels');
                if (! column) return;
                column.options.params.options = window.MothershipOrganize.flatten([...(this.organizeState?.labels ?? []), ...(this.organizeState?.boards ?? [])])
                    .map((node) => ({ value: String(node.id), label: '— '.repeat(node.depth) + node.name }));
            },

            // Orientation, favorites and label match are one choice at a time; returns true when it handled the column.
            organizeApplySingle(column, value) {
                if (! ['orientation', 'favorites', 'labels_mode'].includes(column.index)) return false;
                this.applied.filters.columns = this.applied.filters.columns.filter((c) => c.index !== column.index);
                if (value) this.applied.filters.columns.push({ ...column, value: [value] });

                return true;
            },

            organizeMember({ asset, action, delta = 0 }) {
                const target = asset?.organize;
                if (! target) return;
                const section = target.type === 'section';
                const assets = section ? 'pins' : (target.type === 'board' ? 'boardAssets' : 'labelAssets');
                const member = section ? 'pin' : (target.type === 'board' ? 'boardAsset' : 'labelAsset');
                const request = action === 'remove'
                    ? this.$axios.delete(window.MothershipOrganize.url(assets, target.id), { data: { asset_ids: [asset.id] } })
                    : this.$axios.put(window.MothershipOrganize.url(member, target.id, asset.id), { position: Math.max(0, (asset.member_position ?? 0) + delta) });
                request
                    .then(({ data }) => {
                        if (data?.message) this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        if (action === 'remove') this.$emitter.emit('dam:organize-changed');
                        this.fetch();
                    })
                    .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: window.MothershipOrganize.error(error) || this.organizeT['assets-not-found'] }));
            },

            // Save search: the current query as the explorer sends it, without paging or the open container.
            openSaveSearch() {
                const definition = {
                    scope: this.scope === 'all' ? 'all' : 'directory',
                    directory_id: this.scope === 'all' ? undefined : this.currentDirId,
                    media_type: this.mediaType && this.mediaType !== 'all' ? this.mediaType : undefined,
                    include_subfolders: this.scope !== 'all' && this.includeSubfolders ? 1 : undefined,
                    search: this.search || undefined,
                    sort_by: ['relevance', 'position'].includes(this.sortBy) ? undefined : this.sortBy,
                    sort_order: ['relevance', 'position'].includes(this.sortBy) ? undefined : this.sortOrder,
                    ...this.organizeDefinitionFilters(),
                    ...this.organizeFilterParams(),
                };
                if (this.organization && this.organization.type !== 'saved') Object.assign(definition, this.organizeParams());
                Object.keys(definition).forEach((key) => definition[key] === undefined && delete definition[key]);
                this.$emitter.emit('dam:save-search-open', { definition, name: this.search || this.organization?.name || '' });
            },

            // The filter drawer's other columns, as fetch() sends them.
            organizeDefinitionFilters() {
                const params = {};
                this.applied.filters.columns.forEach((col) => {
                    const first = col.value?.[0];
                    switch (col.index) {
                        case 'file_name': params.filter_file_name = first || undefined; break;
                        case 'extension': params.filter_extension = first || undefined; break;
                        case 'tag': params.filter_tag = first || undefined; break;
                        case 'uploaded_by': params.filter_uploaded_by = first === 'me' ? 'me' : undefined; break;
                        case 'stack': params.filter_stack = first || undefined; break;
                        case 'stack_key': params.filter_stack_key = first || undefined; break;
                        case 'unavailable': params.include_unavailable = first === 'exclude' ? 0 : undefined; break;
                        case 'created_at': if (first) { params.filter_created_from = first[0] || undefined; params.filter_created_to = first[1] || undefined; } break;
                        case 'updated_at': if (first) { params.filter_updated_from = first[0] || undefined; params.filter_updated_to = first[1] || undefined; } break;
                        default: if (col.index.startsWith('prop_') && first) (params.filter_props ??= {})[col.index.slice('prop_'.length)] = first;
                    }
                });

                return params;
            },

            selectedAssetIdsForOrganize() {
                return this.selection.ids.filter((item) => item.type === 'asset').map((item) => item.id);
            },

            openOrganizePicker(focus = 'boards') {
                const assetIds = this.selectedAssetIdsForOrganize();
                if (! assetIds.length) return;
                this.$emitter.emit('dam:organize-picker', { assetIds, focus });
            },

            organizeMounted() {
                const t = this.organizeT.filters ?? {};
                const basic = (options) => ({ type: 'basic', params: { options } });
                this.available.columns.push(
                    { index: 'orientation', label: t.orientation, filterable: true, type: 'dropdown', input_type: 'text', options: basic([{ value: 'landscape', label: t.landscape }, { value: 'portrait', label: t.portrait }, { value: 'square', label: t.square }]) },
                    { index: 'favorites', label: t.favorites, filterable: true, type: 'dropdown', input_type: 'text', options: basic([{ value: 'yes', label: t.yes }]) },
                    { index: 'labels', label: t.labels, filterable: true, type: 'dropdown', input_type: 'text', options: basic([]) },
                    { index: 'labels_mode', label: t['labels-mode'], filterable: true, type: 'dropdown', input_type: 'text', options: basic([{ value: 'all', label: t.all }, { value: 'any', label: t.any }]) },
                );
                if (this.organizeState) this.organizeSyncLabelOptions();

                this._organizeHandlers = {
                    [`dam:tab-organize:${this.tabId}`]: (target) => this.openOrganization(target),
                    [`dam:organize-member:${this.tabId}`]: (payload) => this.organizeMember(payload),
                    [`dam:organize-picker-selection:${this.tabId}`]: ({ focus }) => this.openOrganizePicker(focus),
                    'dam:organize-state': (state) => this.organizeApplyState(state),
                    'dam:organize-refresh': () => { if (this.organization || this.applied.filters.columns.some((c) => c.index === 'labels' || c.index === 'favorites')) this.fetch(); },
                };
                Object.entries(this._organizeHandlers).forEach(([event, handler]) => this.$emitter.on(event, handler));
            },
        },

        beforeUnmount() {
            Object.entries(this._organizeHandlers ?? {}).forEach(([event, handler]) => this.$emitter.off(event, handler));
        },
    };
</script>
