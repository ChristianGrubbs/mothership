
app.component('v-dam-tab', {
    template: '#v-dam-tab-template',
    // P4 organization: see organize-tab.blade.php.
    mixins: window.MothershipOrganizeTab ? [window.MothershipOrganizeTab] : [],
    emits: ['tab-state-change', 'tab-label-change'],

    props: {
        tabId:              { type: String, required: true },
        initialDirectoryId: { type: Number, default: null },
        initialSearch:      { type: String, default: '' },
        initialSimilarity:  { type: Object, default: null },
        initialScope:       { type: String, default: 'directory' },
        initialMediaType:   { type: String, default: 'all' },
        clearSavedFilters:  { type: Boolean, default: false },
        initialRecent:      { type: Boolean, default: false },
        initialViewMode:    { type: String, default: 'grid' },
        initialPage:        { type: Number, default: 1 },
        // null = the tab never chose a size, so the remembered global preference applies; a saved 50 is a choice.
        initialPerPage:     { type: Number, default: null },
        aclBypass:          { type: Boolean, default: false },
        accessibleIds:      { type: Array, default: () => [] },
    },

    data() {
        let includeSubfolders = false;
        try { includeSubfolders = localStorage.getItem('dam_explorer_include_subfolders') === 'true'; } catch {}
        const storedView    = typeof localStorage !== 'undefined' ? localStorage.getItem('dam_explorer_view_mode') : null;
        const nav           = window.MothershipLibraryNavigation;
        const userItem      = base => { const key = nav?.explorerKey ? nav.explorerKey(base, nav.userId) : null; return key && typeof localStorage !== 'undefined' ? localStorage.getItem(key) : null; };
        const storedDir     = userItem('dam_explorer_active_dir');
        const storedFilters = userItem('dam_explorer_filter_state');
        const storedSidebar = typeof localStorage !== 'undefined' ? localStorage.getItem('dam_show_sidebar') : null;
        let storedGroupBy = null;
        try { storedGroupBy = localStorage.getItem('dam_explorer_group_by'); } catch {}
        let storedSort = null;
        try { storedSort = localStorage.getItem('dam_explorer_sort'); } catch {}
        let storedPerPage = 50;
        try {
            const remembered = Number(localStorage.getItem('dam_explorer_per_page'));
            if ([50, 100, 150, 200, 250].includes(remembered)) storedPerPage = remembered;
        } catch {}
        let storedTileSize;
        try { storedTileSize = localStorage.getItem('dam_explorer_tile_size'); } catch {}
        const rememberedTileSize = {small: 55, medium: 100, large: 140}[storedTileSize] ?? Number(storedTileSize);
        const sort = this.initialRecent ? 'created_at:desc' : window.MothershipLibraryNavigation.resolveSort(storedSort);
        const [initialSortBy, initialSortOrder] = sort.split(':');
        return {
            // P4a (D9): every active typed field, for the bulk "Edit fields" action (editors only).
            fieldDefinitions: [],
            damAccessLists: false,
            currentDirId: this.initialScope === 'all' ? null : (this.initialDirectoryId ?? (storedDir ? Number(storedDir) : null)),
            scope: this.initialScope,
            mediaType: this.initialMediaType,
            mediaTypeLabels: {"all":"All","images":"Images","videos":"Videos","svgs":"SVGs","audio":"Audio","models":"3D"},
            breadcrumb:   [],
            dirs:         [],
            assets:       [],
            meta:         null,
            loading:      false,
            // D5: the id of a stack just made here, scrolled to and marked once the refreshed listing is back.
            pendingStackHighlight: null,
            // Set once the first fetch settles, successfully or not; `meta` stays null after a failed one.
            firstLoadSettled: false,
            // A failed listing ({ message, retryable }); it replaces the results so they never contradict the filter.
            loadError:    null,
            filterDrafts: {},
            includeSubfolders,
            searchInput:  this.initialSearch,
            search:       this.initialSearch,
            viewMode:     storedView || this.initialViewMode,
            tileSize:     rememberedTileSize >= 50 && rememberedTileSize <= 150 && rememberedTileSize % 5 === 0 ? rememberedTileSize : 100,
            groupBy:      storedGroupBy === 'stack' ? 'stack' : '',
            // VIS-11: on a phone the media chips and the sort/filter row sit behind one "Filter & sort" button.
            phoneFiltersOpen: false,
            selectionBarHeight: 0,
            selectionBarBottom: 0,
            sortBy:       initialSortBy,
            sortOrder:    initialSortOrder,
            smartSearch:  false,
            similarTo:    this.initialSimilarity?.assetId ?? null,
            similarName:  this.initialSimilarity?.name ?? '',
            similarityPriorView: this.initialSimilarity?.priorView ?? null,
            sortBeforeSearch: null,
            page:         this.initialPage,
            perPage:      Number.isSafeInteger(this.initialPerPage) && this.initialPerPage > 0 ? this.initialPerPage : storedPerPage,
            uploadTargetDirId:  null,
            localAccessibleIds: [...(this.accessibleIds || [])],
            debounce:        null,
            navHistory:   [],
            navIdx:       -1,
            dialog: { on: false, type: null, value: '', loading: false, extra: null },
            clipboard:          this.storedClipboard(),
            pasting:            false,
            operationOverlay:   { show: false, label: '', progress: null, fileCount: null },
            selection:          { ids: [], mode: 'none' },
            foreignSelections:  {},
            folderPicker:       { open: false, mode: null },
            ctxTarget:          null,
            sidebarVisible:     storedSidebar !== null ? storedSidebar !== 'false' : (window.matchMedia?.('(min-width: 1240px)')?.matches ?? true),
            bookmarkedDirIds:   [],
            expandedFilter:     null,
            available: {
                id: 'dam-explorer',
                columns: [
                    { index: 'file_name',  label: "File Name", filterable: true, type: 'string', options: null, input_type: 'text' },
                    { index: 'tag',        label: "Tags",      filterable: true, type: 'string', options: null, input_type: 'text' },
                    { index: 'extension',  label: "Extension", filterable: true, type: 'string', options: null, input_type: 'text' },
                    { index: 'created_at', label: "Created At", filterable: true, type: 'date_range', input_type: 'date', options: [{"name":"today","label":"Today","from":"2026-10-23","to":"2026-10-23"},{"name":"yesterday","label":"Yesterday","from":"2026-10-22","to":"2026-10-22"},{"name":"this_week","label":"This Week","from":"2026-10-18","to":"2026-10-24"},{"name":"this_month","label":"This Month","from":"2026-10-01","to":"2026-10-31"},{"name":"last_month","label":"Last Month","from":"2026-09-01","to":"2026-09-30"},{"name":"last_three_months","label":"Last 3 Months","from":"2026-07-01","to":"2026-09-30"},{"name":"last_six_months","label":"Last 6 Months","from":"2026-04-01","to":"2026-09-30"},{"name":"this_year","label":"This Year","from":"2026-01-01","to":"2026-12-31"}] },
                    { index: 'updated_at', label: "Updated At", filterable: true, type: 'date_range', input_type: 'date', options: [{"name":"today","label":"Today","from":"2026-10-23","to":"2026-10-23"},{"name":"yesterday","label":"Yesterday","from":"2026-10-22","to":"2026-10-22"},{"name":"this_week","label":"This Week","from":"2026-10-18","to":"2026-10-24"},{"name":"this_month","label":"This Month","from":"2026-10-01","to":"2026-10-31"},{"name":"last_month","label":"Last Month","from":"2026-09-01","to":"2026-09-30"},{"name":"last_three_months","label":"Last 3 Months","from":"2026-07-01","to":"2026-09-30"},{"name":"last_six_months","label":"Last 6 Months","from":"2026-04-01","to":"2026-09-30"},{"name":"this_year","label":"This Year","from":"2026-01-01","to":"2026-12-31"}] },
                    // Server-side filter on created_by; "Anyone" is no filter at all (applyFilter drops the column).
                    { index: 'uploaded_by', label: "Uploaded by", filterable: true, type: 'dropdown', input_type: 'text', options: { type: 'basic', params: { options: [ { value: 'anyone', label: "Anyone" }, { value: 'me', label: "Me" } ] } } },
                    // Stacks (D5): one choice at a time, like Uploaded by; "Any stack" is every asset with a basis.
                    { index: 'stack', label: "Stack", filterable: true, type: 'dropdown', input_type: 'text', options: { type: 'basic', params: { options: [ { value: 'any', label: "Any stack" }, { value: 'product', label: "Product" }, { value: 'campaign', label: "Campaign" }, { value: 'use_case', label: "Use case" }, { value: 'other', label: "Other" } ] } } },
                    { index: 'stack_key', label: "Stack name", filterable: true, type: 'string', options: null, input_type: 'text' },
                                        // P2 S3, editors only: drafts, archived and out-of-window files are listed (with a badge) unless left out here.
                    { index: 'unavailable', label: "Unavailable files (shown by default)", filterable: true, type: 'dropdown', input_type: 'text', options: { type: 'basic', params: { options: [ { value: 'include', label: "Show them, with a badge" }, { value: 'exclude', label: "Leave them out" } ] } } },
                                    ],
            },
            applied: {
                filters: {
                    columns: (() => {
                        try { return !this.clearSavedFilters && storedFilters ? JSON.parse(storedFilters) : []; } catch { return []; }
                    })(),
                },
            },
        };
    },

    computed: {
        createStackLabel() {
            const count = this.selection.ids.filter(i => i.type === 'asset').length;

            // Exactly one stack among the selected files: the others are added to it, so the button says "Add".
            return this.selectedStack
                ? 'Add to stack (:count)'.replace(':count', () => count - 1)
                : 'Create stack (:count)'.replace(':count', () => count);
        },
        /** The one stack among the selected files (more than one file in it), or null when none or several are selected. */
        selectedStack() {
            const ids = this.selection.ids.filter(i => i.type === 'asset').map(i => Number(i.id));
            const stacks = this.assets.filter(a => ids.includes(Number(a.id)) && Number(a.attachment_count || 0) > 1);

            return ids.length > 1 && stacks.length === 1 ? stacks[0] : null;
        },
        addToStackLabel() {
            return this.selectedStack ? 'Add to stack' : 'Create stack';
        },
        // The rendered blocks: one ungrouped block, or (grouping by stack) the folders first and then one
        // block per basis and key, in the server's group order. A basis heading rides on its first key block.
        displayBlocks() {
            if (this.groupBy !== 'stack' || ! this.assets.length) {
                return [{ id: 'all', grouped: false, dirs: this.dirs, assets: this.assets }];
            }

            const bases = {"product":"Product","campaign":"Campaign","use_case":"Use case","other":"Other"};
            const blocks = this.dirs.length ? [{ id: 'dirs', grouped: true, dirs: this.dirs, assets: [] }] : [];
            const counts = {};
            this.assets.forEach((asset) => { const basis = asset.stack_basis || ''; counts[basis] = (counts[basis] || 0) + 1; });
            let previous = null;

            this.assets.forEach((asset) => {
                const basis = asset.stack_basis || '';
                const key = basis ? String(asset.stack_key || '') : '';
                const id = `${basis}\u0000${key.toLowerCase()}`;
                const last = blocks[blocks.length - 1];

                if (last && last.id === id) {
                    last.assets.push(asset);
                    return;
                }

                blocks.push({
                    id, grouped: true, dirs: [], assets: [asset],
                    heading: basis === previous ? null : (basis ? (bases[basis] || basis) : "No grouping"),
                    // Counted on this page only; when the folder runs to more pages, the heading says so (G1-13).
                    headingCount: (this.meta?.last_page ?? 1) > 1
                        ? ":count on this page".replace(':count', () => counts[basis])
                        : counts[basis],
                    subheading: basis ? key : null,
                });
                previous = basis;
            });

            return blocks;
        },

        // What to tell someone looking at no files: why, and the one thing that would change it.
        emptyState() {
            if (this.loading || ! this.meta) return null;
            if (this.similarTo) return { message: "No similar files found.", action: null };
            // A folder the viewer can see on the way to another (a path, not a grant) lists nothing for them; say so
            // rather than call it empty. The server answers `can_access_current` for exactly this.
            if (! this.similarTo && this.scope !== 'all' && this.currentDirId && ! this.aclBypass && this.meta.can_access_current === false) {
                return { message: "You don't have access to this folder.", action: null };
            }
            if (this.meta.filter_conflict && ! this.similarTo) return { message: "No files match the filters in use.", action: null };
            if (this.search && ! this.similarTo) {
                const folderSearch = this.scope !== 'all' && !!this.currentDirId;
                return {
                    message: (folderSearch ? "No files match :query in this folder." : "No files match :query. Try fewer or different words.").replace(':query', () => `\u201c${this.search}\u201d`),
                    action: folderSearch ? "Search all assets" : "Clear search",
                    kind: folderSearch ? 'all-assets' : 'clear-search',
                };
            }
            if (this.applied.filters.columns.length) return { message: "No files match the filters in use.", action: null };

            return { message: this.dirs.length ? "This folder holds no files of its own, only subfolders." : "This folder is empty.", action: null };
        },

        similarBannerText() {
            return "Similar to :name".replace(':name', () => this.similarName);
        },
        // "Best match" only exists while smart search is on and a search is active.
        sortOptions() {
            // P4 organization: manual order inside a label, board or section; popularity behind its flag.
            const options = this.organizeSortOptions ? this.organizeSortOptions(window.MothershipLibraryNavigation.sortOptions) : window.MothershipLibraryNavigation.sortOptions;

            return this.smartSearch && this.search
                ? [{ value: 'relevance:desc', label: "Best match" }, ...options]
                : options;
        },
        canUploadHere() {
            // The Similar-to view is a result list, not a folder: nothing uploads, drops or pastes into it.
            if (this.similarTo) return false;
            if (! this.currentDirId || this.scope === 'all') return false;
            if (this.aclBypass) return true;
            return this.localAccessibleIds.map(Number).includes(Number(this.currentDirId));
        },
        canAccessCurrentDir() {
            return this.scope !== 'all' && (this.aclBypass || !!(this.meta?.can_access_current));
        },

        foreignSelectionCount() {
            const key = this.selectionScopeKey();
            const f = key != null ? this.foreignSelections[key] : null;
            return f ? f.ids.length : 0;
        },
        showForeignSelectionOffer() {
            return this.foreignSelectionCount > 0 && this.selection.ids.length === 0;
        },
        canGoBack()    { return this.navIdx > 0; },
        canGoForward() { return this.navIdx < this.navHistory.length - 1; },
        dialogTitle()       { return "Rename File"; },
        dialogPlaceholder() { return "New name"; },
        currentDirBookmarked() {
            if (! this.currentDirId) return false;
            return this.bookmarkedDirIds.map(Number).includes(Number(this.currentDirId));
        },
        bookmarkTitle() {
            return this.currentDirBookmarked
                ? "Remove bookmark"
                : "Bookmark Directory";
        },
    },

    watch: {
        // The copied item survives a reload, for this browser tab only.
        clipboard(value) {
            try {
                const key = this.userKey('dam_explorer_clipboard');
                if (! key) return;
                if (value) sessionStorage.setItem(key, JSON.stringify(value)); else sessionStorage.removeItem(key);
            } catch {}
        },

        // D5: after a stack is created, the refreshed listing scrolls to it and marks it for a moment.
        loading(now) {
            if (now || ! this.pendingStackHighlight) return;
            const id = this.pendingStackHighlight;
            this.pendingStackHighlight = null;
            this.$nextTick(() => {
                const el = this.$el?.querySelector?.(`[data-dam-asset-id="${id}"]`);
                if (! el) return;
                el.scrollIntoView({ block: 'nearest', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                el.classList.remove('dam-stack-created');
                void el.offsetWidth;
                el.classList.add('dam-stack-created');
                setTimeout(() => el.classList.remove('dam-stack-created'), 2600);
            });
        },

        'selection.ids.length'() {
            this.$nextTick(() => this.measureSelectionBar());
        },

        currentDirId(id) {
            // Entering a folder — or All Assets (null id, scope 'all') — asks other tabs whether
            // they already hold a selection there, so fetch() never restores a selection they own.
            const key = id != null ? id : this.selectionScopeKey();
            if (key != null) this.$emitter.emit('dam:selection-query', { directoryId: key, requesterId: this.tabId });
        },
    },

    mounted() {
        this._onStackDefaultChanged = () => this.fetch();
        this.$emitter.on('dam:stack-default-changed', this._onStackDefaultChanged);
        this._onResizeSelectionBar = () => this.measureSelectionBar();
        window.addEventListener('resize', this._onResizeSelectionBar);
        // A v-show tab measures its fixed bar as 0 while hidden; the observer fires when the bar gets a box again
        // (tab reactivated, wrapped line, rotated phone), so the spacer never goes stale.
        if (typeof ResizeObserver === 'function') {
            this._selectionBarObserver = new ResizeObserver(() => this.measureSelectionBar());
        }
        this._onFindSimilar = (detail) => {
            // Addressed to one tab from its context menu; the preview modal sends no tabId and the visible tab answers.
            if (! this.$el?.offsetParent || (detail.tabId && detail.tabId !== this.tabId)) return;
            this.enterSimilarity({ assetId: detail.id, name: detail.name });
        };
        this.$emitter.on('dam:find-similar', this._onFindSimilar);

        this.$axios.get("http://mothership.test/admin/dam/explorer/filter-options")
            .then(({ data }) => {
                (data.properties ?? []).forEach(prop => {
                    this.available.columns.push({
                        index: `prop_${prop}`,
                        label: prop,
                        filterable: true,
                        type: 'string',
                        options: null,
                        input_type: 'text',
                    });
                });
                // P4a typed field filters: begin. One type-aware control per filterable field (filters.blade.php).
                (data.fields ?? []).forEach(field => {
                    this.available.columns.push({
                        index: `field_${field.code}`,
                        label: field.label,
                        filterable: true,
                        type: 'field',
                        options: null,
                        input_type: 'text',
                        field,
                    });
                });
                // P4a typed field filters: end.
            })
            .catch(() => {});

        this._onSidebarVisibility = (visible) => { this.sidebarVisible = visible; };
        this.$emitter.on('dam:sidebar-visibility-changed', this._onSidebarVisibility);

        this._onBookmarksChanged = (ids) => { this.bookmarkedDirIds = ids ?? []; };
        this.$emitter.on('dam:bookmarks-changed', this._onBookmarksChanged);

        // P3b-1: a merge this tab opened hides its sources, so the selection and the listing are refreshed.
        this._onMergeDone = (event) => this.onMergeDone(event);
        this.$emitter.on('dam:merge-done', this._onMergeDone);

        this.$emitter.on(`dam:explorer-ctx-refresh:${this.tabId}`, () => this.fetch());
        // P4a (D9): a Details save refreshes that card's field lines; a bulk edit from this tab reloads the page.
        this._onAssetFieldsSaved = ({ assetId, cardFields }) => {
            const asset = this.assets.find(a => Number(a.id) === Number(assetId));
            if (asset) asset.card_fields = cardFields ?? [];
        };
        this._onFieldBulkApplied = ({ context }) => { if (context === `explorer:${this.tabId}`) this.fetch(); };
        this.$emitter.on('dam:asset-fields-saved', this._onAssetFieldsSaved);
        this.$emitter.on('dam:field-bulk-applied', this._onFieldBulkApplied);
        this.$emitter.on('dam:ai-rename-applied', this._onFieldBulkApplied);
        // A selection dragged from this tab onto the sidebar tree moves through the same path as a grid drop.
        this.$emitter.on(`dam:explorer-internal-drop:${this.tabId}`, this.onInternalDrop);
        this.$emitter.on(`dam:explorer-stack-drop:${this.tabId}`, this.onStackDrop);
        this.$emitter.on(`dam:explorer-add-to-stack:${this.tabId}`, this.addSelectedToStack);
        this.$emitter.on(`dam:explorer-create-stack:${this.tabId}`, this.openMergeDialog);

        this._onForeignSelectionActive = ({ tabId, directoryId, ids }) => {
            if (tabId === this.tabId) return;
            this.foreignSelections[directoryId] = { tabId, ids: ids ?? [] };
        };
        this._onForeignSelectionCleared = ({ tabId, directoryId }) => {
            if (this.foreignSelections[directoryId]?.tabId === tabId) delete this.foreignSelections[directoryId];
        };
        this._onSelectionQuery = ({ directoryId, requesterId }) => {
            if (requesterId === this.tabId) return;
            if (this._heldSelectionDir === directoryId && this.selection.ids.length) {
                this.$emitter.emit('dam:selection-active', { tabId: this.tabId, directoryId, ids: this.selection.ids });
            }
        };
        this.$emitter.on('dam:selection-active', this._onForeignSelectionActive);
        this.$emitter.on('dam:selection-cleared', this._onForeignSelectionCleared);
        this.$emitter.on('dam:selection-query', this._onSelectionQuery);

        this.$emitter.on('dam:tag-assign:done', ({ context } = {}) => {
            if (context !== `explorer:${this.tabId}`) return;
            this.clearSelection();
            this.fetch();
        });

        this.$emitter.on(`dam:operation-overlay:show:${this.tabId}`, ({ label, progress = null, fileCount = null }) => {
            this.operationOverlay = { show: true, label: label ?? '', progress, fileCount };
        });
        this.$emitter.on(`dam:operation-overlay:progress:${this.tabId}`, ({ progress }) => {
            this.operationOverlay = { ...this.operationOverlay, progress };
        });
        this.$emitter.on(`dam:operation-overlay:hide:${this.tabId}`, () => {
            this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
        });
        this.$emitter.on(`dam:dir-deleted:${this.tabId}`, () => {
            this.navHistory = this.navHistory.slice(0, this.navIdx + 1);
        });
        this.$emitter.on('dam:directory-mutated', () => this.fetch());

        // A sharing save changes access_mode on the item and, for a folder, on every descendant the cascade
        // rewrote, so the indicators are only right once the listing is read again. Server-side recompute,
        // not a local patch, because a cascade's descendants are not all on this page (Codex review of
        // PR #69, finding 5).
        this.$emitter.on('dam:access-updated', () => this.fetch());

        this.$emitter.on('dam:upload-files', (formData) => {
            const files = formData.getAll('files[]');
            if (! files.length) return;
            const targetDirId = formData.get('directory_id') ?? this.currentDirId;
            this.$refs.dropUpload?.enqueueUpload({
                items: files.map(f => ({ file: f, relativePath: f.name, preserveRoot: false })),
                folderPaths: [],
                targetDirId,
            });
        });

        this.$emitter.on('dam:uploads-refresh', ({ directoryId } = {}) => {
            // 3D thumbnail capture for the new ids is the page-level
            // v-dam-model-thumbnail-runner's job (ADR 0006), not this tab's.
            if (! directoryId || Number(directoryId) === Number(this.currentDirId)) this.fetch();
        });

        this.$emitter.on(`dam:explorer-generate-model-thumbnails:${this.tabId}`, ({ directoryId = null, scope = 'current' } = {}) => {
            // 'directory' names a folder the menu was opened on; 'current'
            // follows whatever this tab is showing, which is every folder the
            // role can reach when the tab is on All assets. The run itself
            // belongs to the page-level runner.
            this.$emitter.emit('dam:generate-model-thumbnails', {
                directoryId: scope === 'directory' ? directoryId : (this.scope === 'all' ? null : this.currentDirId),
            });
        });

        this.$emitter.on('dam:directory-granted', (id) => {
            const numId = Number(id);
            if (! this.localAccessibleIds.map(Number).includes(numId)) {
                this.localAccessibleIds.push(numId);
            }
        });

        this.$emitter.on(`dam:tab-all-assets:${this.tabId}`, () => this.showAllAssets());

        // "Reset" on the restored-view notice: back to All assets with no search, media type or similar seed.
        this.$emitter.on(`dam:tab-reset:${this.tabId}`, () => {
            this.resetSimilarity();
            this.search = ''; this.searchInput = '';
            this.mediaType = 'all';
            this.showAllAssets();
        });

        // Browser Back/Forward: the folder plus the search, media type and page that history entry held.
        this.$emitter.on(`dam:tab-popstate:${this.tabId}`, (plan) => this.applyPopState(plan));

        this.$emitter.on(`dam:tab-navigate:${this.tabId}`, ({ directoryId, name, fromTree }) => {
            this.goTo({ id: directoryId, name }, true, false, fromTree);
        });

        this.$emitter.on(`dam:explorer-upload-here:${this.tabId}`, ({ directoryId }) => {
            this.uploadTargetDirId = directoryId ?? this.currentDirId;
            this.$nextTick(() => {
                document.getElementById(`explorer-upload-${this.tabId}`)?.click();
            });
        });

        this.$emitter.on(`dam:explorer-folder-upload-here:${this.tabId}`, ({ directoryId }) => {
            this.uploadTargetDirId = directoryId ?? this.currentDirId;
            this.$nextTick(() => {
                document.getElementById(`explorer-folder-upload-${this.tabId}`)?.click();
            });
        });

        this.$emitter.on(`dam:explorer-rename-asset:${this.tabId}`, ({ asset }) => {
            this.openDialog('rename-asset', asset.file_name, { asset });
        });

        this.$emitter.on(`dam:ctx-open:${this.tabId}`, ({ item, type }) => {
            this.ctxTarget = { item, type };
        });

        this.$emitter.on(`dam:explorer-copy:${this.tabId}`, ({ item, type }) => {
            this.clipboard = {
                type: type === 'directory' ? 'directory' : 'asset',
                id:   item.id,
                name: item.name ?? item.file_name,
            };
            this.ctxTarget = { item, type };
        });

        this.$emitter.on(`dam:explorer-paste:${this.tabId}`, ({ targetDirId }) => {
            this.executePaste(targetDirId ?? this.currentDirId);
        });

        this.$emitter.on(`dam:explorer-kb-copy:${this.tabId}`, () => {
            if (this.ctxTarget) {
                this.clipboard = {
                    type: this.ctxTarget.type === 'directory' ? 'directory' : 'asset',
                    id:   this.ctxTarget.item.id,
                    name: this.ctxTarget.item.name ?? this.ctxTarget.item.file_name,
                };
            }
        });

        // Escape drops the selection (index.blade.php sends it to the visible tab only).
        this.$emitter.on(`dam:explorer-kb-escape:${this.tabId}`, () => {
            if (this.selection.ids.length) this.clearSelection();
        });

        this.$emitter.on(`dam:explorer-kb-paste:${this.tabId}`, () => {
            this.executePaste(this.currentDirId);
        });

        // P4 organization: the filter drawer's organization columns and the sidebar's events.
        this.organizeMounted?.();

        if (this.scope === 'all') {
            this.navHistory.push({dirId: null, scope: 'all', breadcrumb: []});
            this.navIdx = 0;
            this.fetch();

            this.$emitter.emit('dam:selection-query', { directoryId: 'all', requesterId: this.tabId });
        } else if (this.currentDirId) {

            this.$emitter.emit('dam:explorer-tree-sync', { id: this.currentDirId });

            this.navHistory.push({ dirId: this.currentDirId, breadcrumb: [] });
            this.navIdx = 0;
            this.fetch();

            this.$emitter.emit('dam:selection-query', { directoryId: this.currentDirId, requesterId: this.tabId });
        } else {
            this.loadRoot();
        }
    },

    beforeUnmount() {
        this.$emitter.off('dam:stack-default-changed', this._onStackDefaultChanged);
        window.removeEventListener('resize', this._onResizeSelectionBar);
        if (this._selectionBarObserver) this._selectionBarObserver.disconnect();
        if (this._onFindSimilar) this.$emitter.off('dam:find-similar', this._onFindSimilar);
        if (this._onSidebarVisibility) this.$emitter.off('dam:sidebar-visibility-changed', this._onSidebarVisibility);
        if (this._onBookmarksChanged) this.$emitter.off('dam:bookmarks-changed', this._onBookmarksChanged);
        if (this._onMergeDone) this.$emitter.off('dam:merge-done', this._onMergeDone);
        if (this._onAssetFieldsSaved) this.$emitter.off('dam:asset-fields-saved', this._onAssetFieldsSaved);
        if (this._onFieldBulkApplied) this.$emitter.off('dam:field-bulk-applied', this._onFieldBulkApplied);
        if (this._onFieldBulkApplied) this.$emitter.off('dam:ai-rename-applied', this._onFieldBulkApplied);

        if (this._heldSelectionDir != null) {
            this.$emitter.emit('dam:selection-cleared', { tabId: this.tabId, directoryId: this._heldSelectionDir });
        }
        this.$emitter.off('dam:selection-active', this._onForeignSelectionActive);
        this.$emitter.off('dam:selection-cleared', this._onForeignSelectionCleared);
        this.$emitter.off('dam:selection-query', this._onSelectionQuery);
        this.$emitter.off(`dam:explorer-internal-drop:${this.tabId}`, this.onInternalDrop);
        this.$emitter.off(`dam:explorer-stack-drop:${this.tabId}`, this.onStackDrop);
        this.$emitter.off(`dam:explorer-add-to-stack:${this.tabId}`, this.addSelectedToStack);
        this.$emitter.off(`dam:explorer-create-stack:${this.tabId}`, this.openMergeDialog);
    },

    methods: {
        // The bar is only out of the page flow (position: fixed) on a phone; there it takes its own height from the page.
        measureSelectionBar() {
            const bar = this.$refs.selectionBar;
            if (this._selectionBarObserver && this._observedBar !== bar) {
                if (this._observedBar) this._selectionBarObserver.disconnect();
                this._observedBar = bar || null;
                if (bar) this._selectionBarObserver.observe(bar);
            }
            const fixed = !! bar && getComputedStyle(bar).position === 'fixed';
            this.selectionBarHeight = fixed ? Math.ceil(bar.getBoundingClientRect().height) : 0;
            // The phone tab bar covers the bottom of the screen, so the fixed bar sits on top of it.
            const tabbar = fixed ? bar.ownerDocument?.querySelector('[data-bridge-tabbar]') : null;
            this.selectionBarBottom = tabbar ? Math.ceil(tabbar.getBoundingClientRect().height) : 0;
        },

        storedClipboard() {
            try {
                const key = this.userKey('dam_explorer_clipboard');
                const value = key ? JSON.parse(sessionStorage.getItem(key) || 'null') : null;

                return value && ['asset', 'directory'].includes(value.type) && value.id != null ? { type: value.type, id: value.id, name: String(value.name ?? '') } : null;
            } catch { return null; }
        },

        // Per-account key (F20); null when the shared helper is missing, so nothing is shared.
        userKey(base) {
            const nav = window.MothershipLibraryNavigation;
            return nav?.explorerKey ? nav.explorerKey(base, nav.userId) : null;
        },

        toggleSelect(id, type) {
            const idx = this.selection.ids.findIndex(i => i.id === id && i.type === type);
            if (idx === -1) {
                this.selection.ids.push({ id, type });
            } else {
                this.selection.ids.splice(idx, 1);
            }
            this.computeSelectionMode();
        },

        /**
         * Key under which this tab's selection is broadcast to other tabs and persisted:
         * the folder id, or 'all' for the All Assets scope so two All Assets tabs coordinate too.
         */
        selectionScopeKey() {
            return this.scope === 'all' ? 'all' : this.currentDirId;
        },

        selectRange(items) {
            (items ?? []).forEach(({ id, type }) => {
                if (! this.selection.ids.some(i => i.id === id && i.type === type)) {
                    this.selection.ids.push({ id, type });
                }
            });
            this.computeSelectionMode();
        },

        // The grid's drag-select hands over the whole selection as it grows and shrinks under the box.
        setSelection(items) {
            this.selection.ids = (items ?? []).map(({ id, type }) => ({ id, type }));
            this.computeSelectionMode();
        },

        /**
         * Selection survives an accidental open: it is persisted per folder in sessionStorage
         * (this browser tab only) and restored by fetch() when the same folder loads again
         * with no live selection. Stored ids are kept whole — a paginated or filtered response
         * is not proof an item is gone. Only the explorer tab that wrote a key removes it, so a
         * second tab viewing the same folder cannot wipe the owner's recovery state.
         */
        _selectionStorage() {
            try { return typeof sessionStorage !== 'undefined' ? sessionStorage : null; } catch { return null; }
        },

        selectionStorageKey() {
            return `dam_explorer_selection:${this.selectionScopeKey()}`;
        },

        persistSelection() {
            const storage = this._selectionStorage();
            if (! storage) return;
            const key = this.selectionStorageKey();
            try {
                if (this.selection.ids.length) {
                    storage.setItem(key, JSON.stringify(this.selection.ids));
                    this._persistedSelectionKey = key;
                } else if (this._persistedSelectionKey === key) {
                    storage.removeItem(key);
                    this._persistedSelectionKey = null;
                }
            } catch {}
        },

        adoptForeignSelection() {
            const key = this.selectionScopeKey();
            const f = key != null ? this.foreignSelections[key] : null;
            if (! f) return;
            this.selection.ids = f.ids.map(i => ({ ...i }));
            this.computeSelectionMode();
        },

        toggleSelectAll() {
            if (this.selection.mode === 'all' || this.selection.mode === 'partial') {
                this.clearSelection();
            } else {
                this.selection.ids = [
                    ...this.dirs.map(d => ({ id: d.id, type: 'directory' })),
                    ...this.assets.map(a => ({ id: a.id, type: 'asset' })),
                ];
                this.computeSelectionMode();
            }
        },

        restoreSelection() {
            if (this.selection.ids.length) return;
            // Another live explorer tab already owns a selection in this folder — leave it to the foreign-selection flow.
            if (this.selectionScopeKey() != null && this.foreignSelections[this.selectionScopeKey()]) return;
            const storage = this._selectionStorage();
            if (! storage) return;
            const key = this.selectionStorageKey();
            let stored;
            try { stored = JSON.parse(storage.getItem(key) || '[]'); } catch { stored = []; }
            if (! Array.isArray(stored)) return;
            const ids = stored
                .filter(i => i && (i.type === 'asset' || i.type === 'directory') && i.id != null)
                .map(i => ({ id: i.id, type: i.type }));
            if (! ids.length) return;
            this.selection.ids = ids;
            this._persistedSelectionKey = key;
        },

        clearSelection() {
            this.selection.ids  = [];
            this.selection.mode = 'none';
            this.persistSelection();
            this.broadcastSelection();
        },

        broadcastSelection() {
            const key = this.selectionScopeKey();
            if (this.selection.ids.length && key != null) {
                this._heldSelectionDir = key;
                this.$emitter.emit('dam:selection-active', { tabId: this.tabId, directoryId: key, ids: this.selection.ids });
            } else if (this._heldSelectionDir != null) {
                this.$emitter.emit('dam:selection-cleared', { tabId: this.tabId, directoryId: this._heldSelectionDir });
                this._heldSelectionDir = null;
            }
        },

        // P4a (D9): the bulk "Edit fields" modal; folders in the selection are skipped.
        openFieldBulkModal() {
            const assetIds = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);

            if (! assetIds.length) {
                this.$emitter.emit('add-flash', { type: 'warning', message: "Select at least one asset or folder." });
                return;
            }

            this.$emitter.emit('dam:open-field-bulk-modal', {
                fields: this.fieldDefinitions,
                assetIds,
                skippedFolders: this.selection.ids.some(i => i.type === 'directory'),
                context: `explorer:${this.tabId}`,
            });
        },

        // Whether any selected file is of a type the AI can ever describe (server hint `ai_possible`); a file on another
        // page, which the list does not hold, counts. The dialog then asks the server about each file.
        selectionMayUseAi() {
            return this.selection.ids.some(({ type, id }) => {
                const asset = this.assets.find(a => Number(a.id) === Number(id));
                return type === 'asset' && (Number(asset?.attachment_count) > 1 || asset?.ai_possible !== false);
            });
        },

        // Rename with AI: files only; each goes with its current name and card thumbnail.
        openAiRenameModal() {
            const assets = this.selection.ids.filter(i => i.type === 'asset').map(({ id }) => {
                const asset = this.assets.find(a => Number(a.id) === Number(id));

                return { id: Number(id), name: asset?.file_name ?? String(id), thumb: asset?.path ?? null, known: !! asset };
            });

            if (! assets.length) {
                this.$emitter.emit('add-flash', { type: 'warning', message: 'Select at least one file.' });
                return;
            }

            this.$emitter.emit('dam:open-ai-rename', {
                assets,
                skippedFolders: this.selection.ids.some(i => i.type === 'directory'),
                context: `explorer:${this.tabId}`,
            });
        },

        openAssignTagsModal() {

            const assetIds     = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);
            const directoryIds = this.selection.ids.filter(i => i.type === 'directory').map(i => i.id);

            if (! assetIds.length && ! directoryIds.length) {
                this.$emitter.emit('add-flash', {
                    type: 'warning',
                    message: "Select at least one asset or folder.",
                });
                return;
            }

            this.$emitter.emit('dam:open-tag-assign-modal', {
                assetIds,
                directoryIds,
                context: `explorer:${this.tabId}`,
            });
        },

        /** A merge this tab opened, or that took files dragged out of it (alsoRefresh), hides its sources: refresh and drop the selection. */
        onMergeDone({ context, alsoRefresh = [], reviewId, destinationId } = {}) {
            const mine = `explorer:${this.tabId}`;
            if (context !== mine && ! alsoRefresh.includes(mine)) return;
            // A stack made from an upload leaves whatever the user had selected alone.
            if (! String(reviewId || '').startsWith('upload:')) this.clearSelection();
            // D5: once the listing is back, point at the stack that was just made.
            if (context === mine) this.pendingStackHighlight = Number(destinationId) || null;
            this.fetch();
        },

        /** Card data the merge dialog needs, from the loaded records; a record not loaded here falls back to the drag's name. */
        mergeEntry(id, fallbackName = '', record = null) {
            const asset = record ?? this.assets.find(a => Number(a.id) === Number(id));

            return {
                id: Number(id),
                name: asset?.display_name || asset?.file_name || fallbackName || String(id),
                thumb: asset?.path || null,
                extension: asset?.extension || '',
                attachment_count: asset?.attachment_count || 0,
                stack_basis: asset?.stack_basis || null,
                stack_key: asset?.stack_key || null,
            };
        },

        /** Dropping assets on a stack card adds them to it: the merge dialog opens with that stack as the cover and is the confirmation. */
        onStackDrop({ payload, asset, refreshTabId = null }) {
            // refreshTabId is the tab the stack lives in; this tab then only resolves the dragged records.
            if (payload.tabId && payload.tabId !== this.tabId) {
                // A drag from another tab, single card or selection, resolves its records (stack count and label) in that tab.
                this.$emitter.emit(`dam:explorer-stack-drop:${payload.tabId}`, { payload, asset, refreshTabId: this.tabId });
                return;
            }
            const targetId = Number(asset.id);
            const droppedIds = window.MothershipFileOps.isSelectionDrag(payload) ? (payload.ids ?? []) : [payload.id];
            const sourceIds = [...new Set(droppedIds.map(Number))].filter(id => id > 0 && id !== targetId);
            if (! sourceIds.length) return;

            const fallbackName = sourceIds.length === 1 && Number(payload.id) === sourceIds[0] ? payload.name : '';
            const targetTabId = refreshTabId ?? this.tabId;
            this.$emitter.emit('dam:open-merge-dialog', {
                // The target comes from the card that was dropped on, never from this tab's listing.
                assets: [this.mergeEntry(targetId, '', asset), ...sourceIds.map(id => this.mergeEntry(id, fallbackName))],
                context: `explorer:${targetTabId}`,
                coverId: targetId,
                // Dragged from another tab (payload.tabId): that tab refreshes and clears its selection once the merge lands.
                alsoRefresh: payload.tabId && payload.tabId !== targetTabId ? [`explorer:${payload.tabId}`] : [],
            });
        },

        /** P3b-1: the selected files (folders cannot merge) go to the merge dialog, which picks the destination. */
        openMergeDialog() {
            const ids = this.selection.ids.filter(i => i.type === 'asset').map(i => Number(i.id));
            const assets = ids.map((id) => {
                const asset = this.assets.find(a => Number(a.id) === id);

                return {
                    id,
                    name: asset?.display_name || asset?.file_name || String(id),
                    thumb: asset?.path || null,
                    extension: asset?.extension || '',
                    attachment_count: asset?.attachment_count || 0,
                    stack_basis: asset?.stack_basis || null,
                    stack_key: asset?.stack_key || null,
                };
            });

            // With one stack among them the dialog opens on it as the representative: the others are added to it.
            this.$emitter.emit('dam:open-merge-dialog', { assets, context: `explorer:${this.tabId}`, ...(this.selectedStack ? { coverId: Number(this.selectedStack.id) } : {}) });
        },

        /**
         * "Add selected files to this stack" on a stack's context menu: the other selected files join that stack through the
         * same merge dialog a drop opens. With nothing else selected there is nothing to add, so the menu says what to do.
         */
        addSelectedToStack({ asset }) {
            const targetId = Number(asset.id);
            const sourceIds = [...new Set(this.selection.ids.filter(i => i.type === 'asset').map(i => Number(i.id)))].filter(id => id > 0 && id !== targetId);

            if (! sourceIds.length) {
                this.$emitter.emit('add-flash', { type: 'warning', message: 'Select the files to add first, then right-click the stack.' });
                return;
            }

            this.$emitter.emit('dam:open-merge-dialog', {
                assets: [this.mergeEntry(targetId, '', asset), ...sourceIds.map(id => this.mergeEntry(id, ''))],
                context: `explorer:${this.tabId}`,
                coverId: targetId,
                alsoRefresh: [],
            });
        },

        // P6a: the Process dialog takes the selected files; folders are not processed.
        openProcessModal() {
            const assetIds = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);

            this.$emitter.emit('dam:open-batch-process', { assetIds });
        },

        // P4 D2: files only; a selected folder is not a member (a collection is a hand-picked set of files).
        openAddToCollection() {
            const assetIds = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);

            if (! assetIds.length) {
                this.$emitter.emit('add-flash', {
                    type: 'warning',
                    message: 'Select at least one file first.',
                });
                return;
            }

            this.$emitter.emit('dam:open-collection-add-modal', { assetIds, context: `explorer:${this.tabId}` });
        },

        performMassDelete() {
            const count = this.selection.ids.length;

            this.$emitter.emit('open-delete-modal', {
                message: "Delete :count item? Files move to Trash for 30 days; folders are deleted recursively and cannot be undone.|Delete :count items? Files move to Trash for 30 days; folders are deleted recursively and cannot be undone.".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                agree: async () => {
                    const assetIds = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);
                    const dirIds   = this.selection.ids.filter(i => i.type === 'directory').map(i => i.id);
                    const sourceName = this.currentFolderName();

                    this.$emitter.emit(`dam:operation-overlay:show:${this.tabId}`, {
                        label: "Deleting :count item...|Deleting :count items...".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                    });

                    if (assetIds.length) {
                        try {
                            const result = await window.MothershipFileOps.deleteWithConfirmation({message: ":count item is required by other assets. Delete anyway? Dependent assets keep working but lose the link.|:count items are required by other assets. Delete anyway? Dependent assets keep working but lose the link.", emitter: this.$emitter,
                                onBlocked: () => this.$emitter.emit(`dam:operation-overlay:hide:${this.tabId}`),
                                request: force => this.$axios.post('http://mothership.test/admin/dam/assets/mass-delete', { indices: assetIds, force }),
                            });
                            if (!result) {
                                this.$emitter.emit(`dam:operation-overlay:hide:${this.tabId}`);
                                return;
                            }
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: ":count asset moved to Trash from ':source'.|:count assets moved to Trash from ':source'.".split('|').at(assetIds.length === 1 ? 0 : -1)
                                    .replace(':count', assetIds.length)
                                    .replace(':source', sourceName),
                            });
                        } catch (e) {
                            this.$emitter.emit('add-flash', {
                                type: 'warning',
                                message: e?.response?.data?.message ?? "dam::app.admin.dam.index.directory.error-operation",
                            });
                        }
                    }

                    if (dirIds.length) {
                        try {
                            await this.$axios.post('http://mothership.test/admin/dam/directory/mass-delete', { indices: dirIds });
                            await new Promise((resolve) => {
                                let attempts = 0;
                                const poll = () => {
                                    if (++attempts > 30) { resolve(); return; }
                                    this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'delete_directory'))
                                        .then(({ data: d }) => {
                                            if (d.status === 'completed') {
                                                dirIds.forEach(id => this.$emitter.emit('dam:directory-deleted', { id }));
                                                this.$emitter.emit('dam:tree-reload');
                                                this.$emitter.emit('add-flash', { type: 'success', message: ":count folder deleted from ':source'.|:count folders deleted from ':source'.".split('|').at(dirIds.length === 1 ? 0 : -1).replace(':count', dirIds.length).replace(':source', sourceName) });
                                                resolve();
                                            } else if (d.status === 'failed') {
                                                this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                                resolve();
                                            } else { setTimeout(poll, 2000); }
                                        }).catch(() => { setTimeout(poll, 2000); });
                                };
                                setTimeout(poll, 1000);
                            });
                        } catch (e) {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: e?.response?.data?.message ?? "dam::app.admin.dam.index.directory.error-operation",
                            });
                        }
                    }

                    this.$emitter.emit(`dam:operation-overlay:hide:${this.tabId}`);
                    this.clearSelection();
                    this.$emitter.emit(`dam:explorer-ctx-refresh:${this.tabId}`);
                },
            });
        },

        openFolderPicker(mode) {
            this.folderPicker = { open: true, mode };
        },

        closeFolderPicker() {
            this.folderPicker = { open: false, mode: null };
        },

        onFolderPickerPicked(payload) {

            const targetDirId   = payload?.id ?? payload;
            const targetDirName = payload?.name ?? '';
            const mode = this.folderPicker.mode;
            this.folderPicker = { open: false, mode: null };
            if (mode === 'move') {
                this.performMassMove(targetDirId, targetDirName);
            } else {
                this.performMassCopy(targetDirId, targetDirName);
            }
        },

        currentFolderName() {
            return this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
        },

        /**
         * The completion toast of a move or copy. It counts what the user selected, in the words for what they
         * selected: a folder move used to read "0 files moved" because it counted the files inside.
         */
        transferToast({ kind, assetCount, dirCount, fileCount, source, destination }) {
            const messages = kind === 'move'
                ? { files: ":count file moved from ':source' to ':destination'.|:count files moved from ':source' to ':destination'.", dirs: ":count folder moved from ':source' to ':destination'.|:count folders moved from ':source' to ':destination'.", items: ":count item moved from ':source' to ':destination'.|:count items moved from ':source' to ':destination'." }
                : { files: ":count file copied from ':source' to ':destination'.|:count files copied from ':source' to ':destination'.", dirs: ":count folder copied from ':source' to ':destination'.|:count folders copied from ':source' to ':destination'.", items: ":count item copied from ':source' to ':destination'.|:count items copied from ':source' to ':destination'." };
            const [template, count] = dirCount === 0
                ? [messages.files, fileCount ?? assetCount]
                : (assetCount === 0 ? [messages.dirs, dirCount] : [messages.items, assetCount + dirCount]);

            return template.split('|').at(count === 1 ? 0 : -1)
                .replace(':count', count)
                .replace(':source', source)
                .replace(':destination', destination);
        },

        /**
         * The move UIs' confirmation (sharing slice 2b): previews each id kind present against the
         * move-preview endpoint (it takes one `type` at a time) and asks confirmMove() only when their
         * counts sum above zero. Under `grants` — damAccessLists false — this always resolves true
         * without a network call, so the POST sequence is exactly what it was before this slice.
         */
        async previewAndConfirmMove({ assetIds = [], dirIds = [], targetId, confirmMessage }) {
            if (! this.damAccessLists) return true;

            const previewUrl = 'http://mothership.test/admin/dam/access/move-preview';
            const maxIds = window.MothershipFileOps.MOVE_PREVIEW_MAX_IDS;
            let total = 0;

            for (const [type, ids] of [['asset', assetIds], ['directory', dirIds]]) {
                if (! ids.length) continue;

                // One cap for the preview and the move alike, under `lists`: refused locally, before any
                // request, rather than reaching the server as an uncapped, unconfirmed move (Codex review
                // of PR #75, finding 6).
                if (ids.length > maxIds) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: `A move can check at most ${maxIds} items at once; select fewer and try again.`,
                    });

                    return false;
                }

                try {
                    const { data } = await this.$axios.get(`${previewUrl}?${window.MothershipFileOps.movePreviewQuery({ type, ids, target: targetId })}`);
                    total += (data.folders || 0) + (data.files || 0);
                } catch (error) {
                    // A preview failure (network, a refused move) must stop this move rather than let it
                    // through unconfirmed.
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });

                    return false;
                }
            }

            if (total <= 0) return true;

            return new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                // `confirmMessage` is the pipe-form one|other string; a message with no pipe is used for both.
                message: (forms => (total === 1 ? forms[0] : forms[forms.length > 1 ? 1 : 0]).replace(':count', total))(String(confirmMessage ?? '').split('|')),
                agree: () => resolve(true),
                disagree: () => resolve(false),
            }));
        },

        async performMassMove(targetDirId, targetDirName = '', { assetIds = null, dirIds = null, refreshTabId = null } = {}) {
            // A dragged selection passes its own ids; the toolbar uses this tab's. refreshTabId is the tab it was dropped in.
            const fromDrag = assetIds !== null || dirIds !== null;
            assetIds ??= fromDrag ? [] : this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);
            dirIds   ??= fromDrag ? [] : this.selection.ids.filter(i => i.type === 'directory').map(i => i.id);
            const sourceName = this.currentFolderName();

            if (! await this.previewAndConfirmMove({
                assetIds, dirIds, targetId: targetDirId,
                confirmMessage: "This move changes who can see :count item. Continue?|This move changes who can see :count items. Continue?",
            })) {
                return;
            }

            this.operationOverlay = { show: true, label: "Moving…", progress: 0, fileCount: null };

            this.$axios.post('http://mothership.test/admin/dam/explorer/count-items', {
                asset_ids: assetIds, directory_ids: dirIds,
            }).then(({ data }) => {
                this.operationOverlay = { ...this.operationOverlay, fileCount: data.file_count ?? (assetIds.length + dirIds.length) };
            }).catch(() => {
                this.operationOverlay = { ...this.operationOverlay, fileCount: assetIds.length + dirIds.length };
            });

            (async () => {

                try {
                    await this.$axios.post('http://mothership.test/admin/dam/explorer/mass-move', {
                        asset_ids:           assetIds,
                        directory_ids:       dirIds,
                        target_directory_id: targetDirId,
                    });

                    await new Promise((resolve) => {
                        let attempts = 0;
                        const poll = () => {
                            if (++attempts > 150) {
                                this.$emitter.emit('add-flash', {
                                    type: 'warning',
                                    message: "Operation is still running in the background",
                                });
                                resolve();
                                return;
                            }
                            this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'mass_move'))
                                .then(({ data: d }) => {
                                    if (d.status === 'completed') {
                                        this.$emitter.emit('add-flash', {
                                            type: 'success',
                                            message: this.transferToast({
                                                kind: 'move', assetCount: assetIds.length, dirCount: dirIds.length,
                                                fileCount: this.operationOverlay.fileCount, source: sourceName, destination: targetDirName,
                                            }),
                                        });
                                        dirIds.forEach(id => this.$emitter.emit('dam:directory-deleted', { id }));
                                        this.$emitter.emit('dam:tree-reload');
                                        resolve();
                                    } else if (d.status === 'failed') {
                                        this.$emitter.emit('add-flash', { type: 'error', message: d.message || "dam::app.admin.dam.index.directory.error-operation" });
                                        resolve();
                                    } else {
                                        if (d.progress != null) {
                                            this.$emitter.emit(`dam:operation-overlay:progress:${this.tabId}`, { progress: d.progress });
                                        }
                                        setTimeout(poll, 2000);
                                    }
                                }).catch(() => { setTimeout(poll, 2000); });
                        };
                        setTimeout(poll, 1000);
                    });
                } catch (e) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: e?.response?.data?.message ?? "dam::app.admin.dam.index.directory.error-operation",
                    });
                }

                this.$emitter.emit(`dam:operation-overlay:hide:${this.tabId}`);
                this.clearSelection();
                this.$emitter.emit(`dam:explorer-ctx-refresh:${this.tabId}`);
                if (refreshTabId && refreshTabId !== this.tabId) this.$emitter.emit(`dam:explorer-ctx-refresh:${refreshTabId}`);
            })();
        },

        performMassCopy(targetDirId, targetDirName = '') {
            const assetIds = this.selection.ids.filter(i => i.type === 'asset').map(i => i.id);
            const dirIds   = this.selection.ids.filter(i => i.type === 'directory').map(i => i.id);
            const sourceName = this.currentFolderName();

            this.operationOverlay = { show: true, label: "Copying…", progress: 0, fileCount: null };

            this.$axios.post('http://mothership.test/admin/dam/explorer/count-items', {
                asset_ids: assetIds, directory_ids: dirIds,
            }).then(({ data }) => {
                this.operationOverlay = { ...this.operationOverlay, fileCount: data.file_count ?? (assetIds.length + dirIds.length) };
            }).catch(() => {
                this.operationOverlay = { ...this.operationOverlay, fileCount: assetIds.length + dirIds.length };
            });

            (async () => {

                try {
                    await this.$axios.post('http://mothership.test/admin/dam/explorer/mass-copy', {
                        asset_ids:           assetIds,
                        directory_ids:       dirIds,
                        target_directory_id: targetDirId,
                    });

                    await new Promise((resolve) => {
                        let attempts = 0;
                        const poll = () => {
                            if (++attempts > 150) {
                                this.$emitter.emit('add-flash', {
                                    type: 'warning',
                                    message: "Operation is still running in the background",
                                });
                                resolve();
                                return;
                            }
                            this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'mass_copy'))
                                .then(({ data: d }) => {
                                    if (d.status === 'completed') {
                                        this.$emitter.emit('add-flash', {
                                            type: 'success',
                                            message: this.transferToast({
                                                kind: 'copy', assetCount: assetIds.length, dirCount: dirIds.length,
                                                fileCount: this.operationOverlay.fileCount, source: sourceName, destination: targetDirName,
                                            }),
                                        });
                                        this.$emitter.emit('dam:tree-reload');
                                        resolve();
                                    } else if (d.status === 'failed') {
                                        this.$emitter.emit('add-flash', { type: 'error', message: d.message || "dam::app.admin.dam.index.directory.error-operation" });
                                        resolve();
                                    } else {
                                        if (d.progress != null) {
                                            this.$emitter.emit(`dam:operation-overlay:progress:${this.tabId}`, { progress: d.progress });
                                        }
                                        setTimeout(poll, 2000);
                                    }
                                }).catch(() => { setTimeout(poll, 2000); });
                        };
                        setTimeout(poll, 1000);
                    });
                } catch (e) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: e?.response?.data?.message ?? "dam::app.admin.dam.index.directory.error-operation",
                    });
                }

                this.$emitter.emit(`dam:operation-overlay:hide:${this.tabId}`);
                this.clearSelection();
                this.$emitter.emit(`dam:explorer-ctx-refresh:${this.tabId}`);
            })();
        },

        computeSelectionMode() {
            const total = this.dirs.length + this.assets.length;
            const count = this.selection.ids.length;
            this.selection.mode = count === 0 ? 'none' : count === total ? 'all' : 'partial';
            this.persistSelection();
            this.broadcastSelection();
        },

        toggleSidebar() {
            this.$emitter.emit('dam:toggle-sidebar');
        },

        loadRoot() {
            this.showAllAssets();
        },

        applyPopState({ kind, directoryId, sameView, view }) {
            const listingView = view.listingView ?? (! view.similarity && this.similarTo ? this.similarityPriorView : null);
            this.resetSimilarity();
            this._pendingView = { ...view, listingView };
            if (sameView) { this.clearSelection(); this.takePendingView(); this.fetch(); return; }
            if (kind === 'all') this.showAllAssets();
            else this.goTo({ id: directoryId }, true, false, false);
        },

        // Applies the view a Back/Forward entry held, just before the fetch that follows a folder change.
        takePendingView() {
            const view = this._pendingView;
            this._pendingView = null;
            if (! view) return;
            const scope = this.scope, currentDirId = this.currentDirId;
            this.restoreListingView(view.listingView);
            // The URL's folder remains authoritative when a history entry only carries a prior-view fallback.
            this.scope = scope; this.currentDirId = currentDirId;
            this.search = view.search; this.searchInput = view.search;
            this.mediaType = view.mediaType; this.page = view.page;
            this.similarTo = view.similarity?.assetId ?? null; this.similarName = view.similarity?.name ?? '';
            this.similarityPriorView = view.similarity?.priorView ?? null;
            // A second history event can arrive before this fetch settles; the parent must compare against this view now.
            this.sync();
        },

        emptyAction() {
            if (this.emptyState?.kind === 'all-assets') this.showAllAssets();
            else if (this.emptyState?.kind === 'clear-search') this.clearSearch();
        },

        showAllAssets() {
            this.resetSimilarity();
            this.organizeReset?.(); // P4 organization
            this.clearSelection();
            this.scope = 'all';
            this.currentDirId = null;
            this.breadcrumb = [];
            this.page = 1;
            this.navHistory = this.navHistory.slice(0, this.navIdx + 1);
            this.navHistory.push({dirId: null, scope: 'all', breadcrumb: []});
            this.navIdx = this.navHistory.length - 1;
            this.$emit('tab-label-change', 'All assets');
            this.takePendingView();
            this.fetch();
        },

        setMediaType(type) {
            this.trackFilter('media_type');
            this.mediaType = type;
            this.page = 1;
            this.clearSelection();
            this.fetch();
        },

        fetch() {
            // Organization mixins may clear the seed before fetching; finish the same cleanup as every local exit.
            if (! this.similarTo && this.similarityPriorView) this.resetSimilarity();
            if (! this.currentDirId && this.scope !== 'all' && ! this.similarTo) return;
            if (this.smartSearch) this.syncSortToSearch();
            this.organizeBeforeFetch?.(); // P4 organization
            const requestId = this._fetchRequestId = (this._fetchRequestId || 0) + 1;
            // A newer list request supersedes the one still in flight; abort it so the server stops working for it.
            this._fetchAbort?.abort();
            const abortController = this._fetchAbort = new AbortController();
            let cancelled = false;
            this.loading = true;
            this.loadError = null;
            const filterParams = {};
            this.applied.filters.columns.forEach(col => {
                switch (col.index) {
                    case 'file_name': filterParams.filter_file_name = col.value[0] || undefined; break;
                    case 'extension': filterParams.filter_extension = col.value[0] || undefined; break;
                    case 'tag':       filterParams.filter_tag = col.value[0] || undefined; break;
                    case 'uploaded_by': filterParams.filter_uploaded_by = col.value[0] === 'me' ? 'me' : undefined; break;
                    case 'stack':       filterParams.filter_stack = col.value[0] || undefined; break;
                    case 'stack_key':   filterParams.filter_stack_key = col.value[0] || undefined; break;
                    case 'unavailable': filterParams.include_unavailable = col.value[0] === 'exclude' ? 0 : undefined; break;
                    case 'created_at':
                        if (col.value[0]) {
                            filterParams.filter_created_from = col.value[0][0] || undefined;
                            filterParams.filter_created_to   = col.value[0][1] || undefined;
                        }
                        break;
                    case 'updated_at':
                        if (col.value[0]) {
                            filterParams.filter_updated_from = col.value[0][0] || undefined;
                            filterParams.filter_updated_to   = col.value[0][1] || undefined;
                        }
                        break;
                    default:
                        if (col.index.startsWith('prop_')) {
                            // A map keyed by the real name: a space or dot in a query KEY would be rewritten by PHP.
                            if (col.value[0]) (filterParams.filter_props ??= {})[col.index.slice('prop_'.length)] = col.value[0];
                        }
                        // P4a typed field filters: begin. Sent as filter_field[code]; unknown codes are ignored server-side.
                        if (col.index.startsWith('field_') && col.value[0] !== undefined && col.value[0] !== null) {
                            (filterParams.filter_field ??= {})[col.index.slice('field_'.length)] = col.value[0];
                        }
                        // P4a typed field filters: end.
                }
            });
            this.$axios.get("http://mothership.test/admin/dam/explorer/data", {
                signal: abortController.signal,
                params: {
                    directory_id: this.scope === 'all' ? undefined : this.currentDirId,
                    scope:        this.similarTo ? 'all' : this.scope,
                    media_type:   this.mediaType,
                    include_subfolders: this.includeSubfolders ? 1 : 0,
                    search:       this.similarTo ? undefined : (this.search || undefined),
                    similar_to:   this.similarTo || undefined,
                    page:         this.page,
                    per_page:     this.perPage,
                    sort_by:      this.sortBy,
                    sort_order:   this.sortOrder,
                    group_by:     this.groupBy || undefined,
                    ...filterParams,
                    // P4 organization: the drawer's orientation / favorites / label filters, then the open container.
                    ...(this.organizeFilterParams?.() ?? {}),
                    ...(this.organizeParams?.() ?? {}),
                },
            }).then(({ data }) => {
                if (requestId !== this._fetchRequestId) return;
                this._recoveringToRoot = false;
                this.loadError = null;

                this.dirs   = data.directories;
                this.assets = data.assets;
                this.meta   = data.meta;
                if (data.meta?.date_range_swapped) {
                    this.$emitter.emit('add-flash', { type: 'warning', message: "The end date was before the start date, so the range was read the other way round." });
                }
                this.organizeAfterFetch?.(data); // P4 organization
                this.trackSearchSettled();

                this.restoreSelection();
                this.computeSelectionMode();

                if (Array.isArray(data.breadcrumb)) {
                    this.breadcrumb = data.breadcrumb;
                    if (this.navHistory[this.navIdx]) {
                        this.navHistory[this.navIdx].breadcrumb = this.breadcrumb.map(c => ({ ...c }));
                    }
                    const label = this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
                    this.$emit('tab-label-change', label);
                }

                try {
                    const snapshot = this.applied.filters.columns.map(c => ({ index: c.index, value: c.value }));
                    this.userKey('dam_explorer_filter_state') && localStorage.setItem(this.userKey('dam_explorer_filter_state'), JSON.stringify(snapshot));
                } catch {}

                this.sync();
            }).catch(err => {
                // An aborted (superseded) request is neither an error nor the owner of the loading state.
                if (this.$axios.isCancel?.(err) || err?.code === 'ERR_CANCELED' || err?.name === 'CanceledError') {
                    cancelled = true;
                    return;
                }
                if (requestId !== this._fetchRequestId) return;
                // Only a failed request is a failed load. A slip in the code that renders a successful response
                // must not throw the results away and claim the list could not be loaded.
                if (! (err?.isAxiosError || err?.response || err?.request)) {
                    console.error(err);
                    return;
                }
                const failure = globalThis.mothership?.requestError?.(err)
                    ?? { kind: 'unknown', status: err?.response?.status ?? 0, message: err?.response?.data?.message ?? '', retryable: true };
                if (failure.cancelled) return;
                const status = failure.status;

                /**
                 * The listing behind the chip, folder or page the user just picked failed. The old results must
                 * not stay under the new label (FAULT-01), so they are cleared and the error state replaces them.
                 *
                 * A folder that is gone, or hidden from this viewer, fails the endpoint's VisibleDirectory rule
                 * (422 on directory_id) or the narrow lookup race (404); the two are deliberately indistinguishable.
                 * A visible folder the viewer can no longer open answers 403. Only those leave the folder, once:
                 * a bad filter value (any other 422) keeps the user where they are.
                 */
                const folderGone = status === 404 || (status === 422 && !! err?.response?.data?.errors?.directory_id);
                const folderDenied = status === 403 && this.scope !== 'all';

                // A saved search whose stored folder is gone says so (and leaves the search), rather than "folder gone".
                const savedFolderGone = status === 422 && this.organization?.type === 'saved' && !! err?.response?.data?.message;

                if ((folderGone || folderDenied || savedFolderGone) && ! this._recoveringToRoot && (this.currentDirId || savedFolderGone)) {
                    this._recoveringToRoot = true;

                    this.$emitter.emit('add-flash', {
                        type: 'warning',
                        message: savedFolderGone
                            ? err.response.data.message
                            : (folderGone
                                ? "This folder no longer exists, or you no longer have access to it. Showing All assets."
                                : "You no longer have access to this folder. Showing All assets."),
                    });

                    if (savedFolderGone) this.organizeReset?.();

                    this.navHistory = [];
                    this.navIdx = -1;
                    this.currentDirId = null;

                    try { this.userKey('dam_explorer_active_dir') && localStorage.removeItem(this.userKey('dam_explorer_active_dir')); } catch {}

                    this.loadRoot();

                    return;
                }

                this.dirs = [];
                this.assets = [];
                this.meta = null;
                this.loadError = { message: failure.message, retryable: failure.retryable, status };
            }).finally(() => {
                if (! cancelled && requestId === this._fetchRequestId) {
                    this.loading = false;
                    this.firstLoadSettled = true;
                }
            });
        },

        goTo(dir, isRoot = false, skipHistory = false, fromTree = false) {
            if (! dir?.id) return;
            this.resetSimilarity();
            this.organizeReset?.(); // P4 organization
            this.clearSelection();
            this.currentDirId = dir.id;
            this.scope = 'directory';
            try { this.userKey('dam_explorer_active_dir') && localStorage.setItem(this.userKey('dam_explorer_active_dir'), dir.id); } catch {}
            this.search       = '';
            this.searchInput  = '';
            this.page         = 1;

            if (isRoot || this.breadcrumb.length === 0) {

                this.breadcrumb = isRoot
                    ? [{ id: null, name: '…' }, { id: dir.id, name: dir.name ?? '…' }]
                    : [{ id: dir.id, name: dir.name ?? '…' }];
            } else {
                const idx = this.breadcrumb.findIndex(c => c.id === dir.id);
                if (idx >= 0) {
                    this.breadcrumb = this.breadcrumb.slice(0, idx + 1);
                } else {
                    this.breadcrumb.push({ id: dir.id, name: dir.name ?? '…' });
                }
            }

            if (!skipHistory) {
                this.navHistory = this.navHistory.slice(0, this.navIdx + 1);
                this.navHistory.push({ dirId: dir.id, scope: 'directory', breadcrumb: this.breadcrumb.map(c => ({ ...c })) });
                this.navIdx = this.navHistory.length - 1;
            }

            const label = this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
            this.$emit('tab-label-change', label);
            this.$emitter.emit('dam:explorer-navigate', { directoryId: dir.id });
            this.$emitter.emit('dam:explorer-tree-sync', { id: dir.id, fromTree });
            this.takePendingView();
            this.fetch();
        },

        goBack() {
            if (! this.canGoBack) return;
            this.resetSimilarity();
            this.organizeReset?.(); // P4 organization
            this.clearSelection();
            this.navIdx--;
            const entry = this.navHistory[this.navIdx];
            this.currentDirId = entry.dirId;
            this.scope = entry.scope ?? (entry.dirId ? 'directory' : 'all');
            this.breadcrumb   = entry.breadcrumb.map(c => ({ ...c }));
            this.search       = '';
            this.searchInput  = '';
            this.page         = 1;
            const label = this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
            this.$emit('tab-label-change', label);
            this.$emitter.emit('dam:explorer-navigate', { directoryId: entry.dirId });
            this.$emitter.emit('dam:explorer-tree-sync', { id: entry.dirId });
            this.fetch();
        },

        goForward() {
            if (! this.canGoForward ) return;
            this.resetSimilarity();
            this.organizeReset?.(); // P4 organization
            this.clearSelection();
            this.navIdx++;
            const entry = this.navHistory[this.navIdx];
            this.currentDirId = entry.dirId;
            this.scope = entry.scope ?? (entry.dirId ? 'directory' : 'all');
            this.breadcrumb   = entry.breadcrumb.map(c => ({ ...c }));
            this.search       = '';
            this.searchInput  = '';
            this.page         = 1;
            const label = this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
            this.$emit('tab-label-change', label);
            this.$emitter.emit('dam:explorer-navigate', { directoryId: entry.dirId });
            this.$emitter.emit('dam:explorer-tree-sync', { id: entry.dirId });
            this.fetch();
        },

        bookmark(dir) {

            if (this.bookmarkedDirIds.map(Number).includes(Number(dir.id))) {
                this.$emitter.emit('dam:remove-bookmark', { directoryId: dir.id });
                return;
            }
            this.$emitter.emit('dam:add-bookmark', { id: dir.id, name: dir.name });
        },

        downloadCurrentDir() {
            if (! this.currentDirId) return;
            this.downloadDirectoryZip(this.currentDirId, this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets');
        },

        async downloadDirectoryZip(id, name = '') {
            const url = `http://mothership.test/admin/dam/directory/zip-download/:id`.replace(':id', id);
            // The card shows from the moment the preflight starts and then carries the download itself.
            const card = window.damDownload?.start({ name, folder: true });
            try {
                const probe = await fetch(url, { headers: { Accept: 'application/json', 'X-Zip-Preflight': '1' }, credentials: 'same-origin' });
                if (probe.status !== 204 && probe.status !== 200) {
                    const body = await probe.json().catch(() => ({}));
                    const message = body.message || "Something went wrong. Please try again.";
                    // The card shows the failure itself; the flash is the fallback when the page has no card.
                    if (card) card.fail(message); else this.$emitter.emit('add-flash', { type: 'error', message });
                    return;
                }
            } catch (e) { /* network trouble on the probe: let the browser attempt the download itself */ }
            if (card) card.go(url); else window.open(url, '_self');
        },

        shareCurrentDir() {
            if (! this.currentDirId) return;
            this.$emitter.emit('open-share-modal', { targetType: 'directory', targetId: this.currentDirId });
        },

        toggleBookmarkCurrentDir() {
            if (! this.currentDirId) return;
            if (this.currentDirBookmarked) {
                this.$emitter.emit('dam:remove-bookmark', { directoryId: this.currentDirId });
                return;
            }
            const label = this.breadcrumb[this.breadcrumb.length - 1]?.name ?? 'All assets';
            this.$emitter.emit('dam:add-bookmark', { id: this.currentDirId, name: label });
        },

        openDialog(type, value, extra) {
            this.dialog = { on: true, type, value, loading: false, extra };
        },

        closeDialog() {
            this.dialog = { on: false, type: null, value: '', loading: false, extra: null };
        },

        onDialogSubmit(name) {
            if (! name || this.dialog.loading) return;
            this.dialog.loading = true;
            const asset = this.dialog.extra?.asset;
            this.$axios.post("http://mothership.test/admin/dam/assets/rename", { id: asset.id, file_name: name })
                .then(({ data }) => {
                    // The name it already had: close quietly, nothing changed to announce or refetch.
                    if (data.code === 'unchanged') return this.closeDialog();
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "Action completed successfully." });
                    this.closeDialog();
                    this.fetch();
                })
                .catch(err => {
                    const msg = err?.response?.data?.errors?.file_name?.[0] ?? globalThis.mothership?.requestErrorMessage?.(err) ?? err?.response?.data?.message ?? "Something went wrong. Please try again.";
                    this.$emitter.emit('add-flash', { type: 'error', message: msg });
                    this.dialog.loading = false;
                });
        },

        /**
         * P1b usage analytics: a search counts once the box has rested for 1.5 s on its first page, so a word typed
         * letter by letter is one search, not one per pause. The words go to the server only normalized, where they
         * are kept apart from the event and dropped if they look like an email, a number or a hidden file's name.
         */
        trackSearchSettled() {
            clearTimeout(this._searchTrackTimer);
            const term = (this.search || '').trim().toLowerCase().replace(/\s+/g, ' ').slice(0, 100);

            if (! term || this.similarTo || this.page !== 1 || term === this._searchTracked) return;

            const props = { q_norm: term, words: term.split(' ').length, result_count: this.meta?.total_assets ?? 0, smart: !! this.smartSearch };
            this._searchTrackTimer = setTimeout(() => {
                this._searchTracked = term;
                window.damTrack?.('search.submitted', 0, props, 'library');
            }, 1500);
        },

        trackFilter(filter) {
            window.damTrack?.('filter.applied', 0, { filter }, 'library');
        },

        submitSearch() {
            if (this.similarTo && this.searchInput === this.search) return;
            this.resetSimilarity();
            this.search = this.searchInput;
            this.page = 1;
            this.fetch();
        },

        onSearch() {
            clearTimeout(this.debounce);
            const generation = this._fetchRequestId;
            this.debounce = setTimeout(() => {
                if (generation !== this._fetchRequestId || this.searchInput === this.search) return;
                this.submitSearch();
            }, 300);
        },

        clearSearch() {
            this.resetSimilarity();
            this.searchInput = '';
            this.search      = '';
            this.page        = 1;
            this.fetch();
        },

        setIncludeSubfolders(enabled) {
            this.trackFilter('subfolders');
            this.includeSubfolders = enabled;
            this.page = 1;
            this.clearSelection();
            try { localStorage.setItem('dam_explorer_include_subfolders', String(enabled)); } catch {}
            this.fetch();
        },

        toggleGroupBy() {
            this.groupBy = this.groupBy === 'stack' ? '' : 'stack';
            try { localStorage.setItem('dam_explorer_group_by', this.groupBy); } catch {}
            this.page = 1;
            this.fetch();
        },

        setView(mode) {
            this.viewMode = mode;
            try { localStorage.setItem('dam_explorer_view_mode', mode); } catch {}
            this.sync();
        },

        saveTileSize() {
            try { localStorage.setItem('dam_explorer_tile_size', String(this.tileSize)); } catch {}
        },

        findAppliedColumn(columnIndex) {
            return this.applied.filters.columns.find(c => c.index === columnIndex);
        },
        hasAnyAppliedColumnValues(columnIndex) {
            return (this.findAppliedColumn(columnIndex)?.value?.length ?? 0) > 0;
        },
        getAppliedColumnValues(columnIndex) {
            return this.findAppliedColumn(columnIndex)?.value ?? [];
        },
        removeAppliedColumnValue(columnIndex, appliedColumnValue) {
            const col = this.findAppliedColumn(columnIndex);
            if (! col) return;
            col.value = col.value.filter(v => v !== appliedColumnValue);
            if (! col.value.length) {
                this.applied.filters.columns = this.applied.filters.columns.filter(c => c.index !== columnIndex);
            }
        },
        removeAppliedColumnAllValues(columnIndex) {
            this.applied.filters.columns = this.applied.filters.columns.filter(c => c.index !== columnIndex);
            this.page = 1;
            this.fetch();
        },
        filterPage($event, column = null, additional = {}) {
            const quickFilter = additional?.quickFilter;
            if (quickFilter?.isActive) {
                const options = quickFilter.selectedFilter;
                if (column.type === 'date_range' || column.type === 'datetime_range') {
                    this.applyFilter(column, options.from, { range: { name: 'from' } });
                    this.applyFilter(column, options.to,   { range: { name: 'to' }   });
                }
            } else {
                if ($event?.target?.value === undefined) {
                    $event = { target: { value: $event } };
                }
                this.applyFilter(column, $event.target.value, additional);
                if (column) $event.target.value = '';
            }
        },
        applyFilter(column, requestedValue, additional = {}) {
            if (! column) return;
            if (this.organizeApplySingle?.(column, requestedValue)) return; // P4 organization: one choice at a time
            if (column.index === 'stack') {
                this.applied.filters.columns = this.applied.filters.columns.filter(c => c.index !== 'stack');
                if (requestedValue) this.applied.filters.columns.push({ ...column, value: [requestedValue] });
                return;
            }
            if (column.index === 'uploaded_by') {
                // One choice at a time: Me replaces Anyone and back, and Anyone leaves no filter behind.
                this.applied.filters.columns = this.applied.filters.columns.filter(c => c.index !== 'uploaded_by');
                if (requestedValue === 'me') this.applied.filters.columns.push({ ...column, value: ['me'] });
                return;
            }
            const appliedColumn = this.findAppliedColumn(column.index);
            if (requestedValue === undefined || requestedValue === '' || appliedColumn?.value.includes(requestedValue)) return;
            if (column.type === 'date_range' || column.type === 'datetime_range') {
                const { range } = additional;
                if (appliedColumn) {
                    const ranges = appliedColumn.value[0] ? [...appliedColumn.value[0]] : ['', ''];
                    if (range.name === 'from') ranges[0] = requestedValue;
                    if (range.name === 'to')   ranges[1] = requestedValue;
                    appliedColumn.value = [ranges];
                } else {
                    const ranges = ['', ''];
                    if (range.name === 'from') ranges[0] = requestedValue;
                    if (range.name === 'to')   ranges[1] = requestedValue;
                    this.applied.filters.columns.push({ ...column, value: [ranges] });
                }
            } else {
                if (appliedColumn) {
                    appliedColumn.value.push(requestedValue);
                } else {
                    this.applied.filters.columns.push({ ...column, value: [requestedValue] });
                }
            }
        },
        commitFilterDrafts() {
            for (const column of this.available.columns) {
                const value = String(this.filterDrafts[column.index] ?? '').trim();
                if (! value) continue;
                this.applyFilter(column, value);
                this.filterDrafts[column.index] = '';
            }
        },
        activeFilterChips() {
            const chips = [];
            if (this.mediaType !== 'all' && this.mediaTypeLabels[this.mediaType]) chips.push({ index: 'media_type', value: this.mediaType, label: this.mediaTypeLabels[this.mediaType] });
            for (const applied of this.applied.filters.columns) {
                const column = this.available.columns.find(c => c.index === applied.index) ?? applied;
                for (const value of applied.value) {
                    chips.push({ index: applied.index, value, label: `${this.filterLabel(column) ?? applied.index}: ${this.appliedValuesSummary(column, [value])}` });
                }
            }
            return chips;
        },
        visibleFilterConflict() {
            if (this.loading || this.loadError || this.similarTo || ! this.meta) return null;
            if (this.scope !== 'all' && this.currentDirId && ! this.aclBypass && this.meta.can_access_current === false) return null;
            return this.meta.filter_conflict ?? null;
        },
        removeActiveFilter({ index, value }) {
            if (index === 'media_type') this.mediaType = 'all';
            else {
                this.removeAppliedColumnValue(index, value);
            }
            this.trackFilter('remove');
            this.$refs.explorerFilterDrawer?.close();
            this.phoneFiltersOpen = false;
            this.page = 1;
            this.clearSelection();
            this.fetch();
        },
        runFilters() {
            this.commitFilterDrafts();
            this.applied.filters.columns.forEach(col => this.trackFilter(col.index.startsWith('prop_') ? 'property' : col.index));
            this.$refs.explorerFilterDrawer?.close();
            this.page = 1;
            this.fetch();
        },
        clearAllFilters() {
            this.trackFilter('clear');
            this.filterDrafts = {};
            this.applied.filters.columns = [];
            try { this.userKey('dam_explorer_filter_state') && localStorage.removeItem(this.userKey('dam_explorer_filter_state')); } catch {}
            this.page = 1;
            this.fetch();
        },
        filterLabel(column) {
            return column.filter_label ?? column.label;
        },
        getActiveFilterColumns() {
            return (this.available.columns ?? []).filter(column => column.filterable);
        },
        isFilterExpanded(columnIndex) {
            return this.expandedFilter === columnIndex;
        },
        toggleFilterEditor(columnIndex) {
            this.expandedFilter = this.isFilterExpanded(columnIndex) ? null : columnIndex;
        },
        filterHasValue(column) {
            return this.hasAnyAppliedColumnValues(column.index);
        },
        // P4a typed field filters: begin. A field filter holds one criterion, set whole by v-dam-field-filter.
        setFieldFilter(column, criterion) {
            this.applied.filters.columns = this.applied.filters.columns.filter(c => c.index !== column.index);
            if (criterion !== null && criterion !== undefined) this.applied.filters.columns.push({ index: column.index, value: [criterion] });
        },
        fieldFilterSummary(column, criterion) {
            const field = column.field ?? {};
            const labels = window.MothershipFieldLabels ?? {};
            const option = value => (field.options ?? []).find(o => o.value === value)?.label || value;
            if (Array.isArray(criterion)) return criterion.map(option).join(', ');
            if (field.type === 'boolean') return criterion === '1' ? labels.yes : labels.no;
            if (criterion && typeof criterion === 'object') {
                const [low, high] = field.type === 'number' ? [criterion.min, criterion.max] : [criterion.from, criterion.to];
                if (low != null && high != null) return `${low} – ${high}`;
                return low != null ? `≥ ${low}` : `≤ ${high}`;
            }
            return String(criterion ?? '');
        },
        // P4a typed field filters: end.
        appliedValuesSummary(column, values) {
            if (column.type === 'field') return this.fieldFilterSummary(column, values[0]);
            if (column.type === 'boolean') {
                return values
                    .map(value => column.options?.find(option => option.value == value)?.label ?? value)
                    .join(', ');
            }

            if (column.type === 'dropdown') {
                if (column.options?.type === 'basic') {
                    return values
                        .map(value => column.options.params.options.find(option => option.value == value)?.label ?? value)
                        .join(', ');
                }

                return ":count selected".replace(':count', values.length);
            }

            return values
                .map(value => Array.isArray(value) ? value.filter(Boolean).join(' – ') : value)
                .join(', ');
        },
        collapsedSummary(column) {
            return this.filterHasValue(column)
                ? this.appliedValuesSummary(column, this.getAppliedColumnValues(column.index))
                : "All";
        },
        appliedFilterCount() {
            return this.applied.filters.columns.filter(c =>
                c.value && c.value.length > 0 && c.value.some(v => Array.isArray(v) ? v.some(Boolean) : Boolean(v))
            ).length;
        },
        hasAppliedFilters() {
            return this.appliedFilterCount() > 0;
        },

        // A new search ranks by best match; clearing it restores the person's sort (spec §4). Run at the top of
        // fetch() because every search setter assigns `search` and fetches synchronously, before a watcher would run.
        syncSortToSearch() {
            if (this._restoreSimilaritySort) { this._restoreSimilaritySort = false; return; }
            if (! this.smartSearch || this.similarTo) return;

            if (this.search && this.sortBeforeSearch === null) {
                this.sortBeforeSearch = { sortBy: this.sortBy, sortOrder: this.sortOrder };
                this.sortBy = 'relevance'; this.sortOrder = 'desc';
            } else if (! this.search && (this.sortBeforeSearch !== null || this.sortBy === 'relevance')) {
                const previous = this.sortBeforeSearch ?? { sortBy: 'created_at', sortOrder: 'desc' };
                this.sortBy = previous.sortBy; this.sortOrder = previous.sortOrder;
                this.sortBeforeSearch = null;
            }
        },

        clearSimilar() {
            this.exitSimilarity();
        },

        invalidateSearchRequests() {
            clearTimeout(this.debounce);
            clearTimeout(this._searchTrackTimer);
            this._fetchRequestId = (this._fetchRequestId || 0) + 1;
        },

        enterSimilarity({ assetId, name }) {
            this.invalidateSearchRequests();
            if (! this.similarTo) {
                this.similarityPriorView = this.captureListingView();
            }
            this.similarTo = assetId; this.similarName = String(name ?? '').slice(0, 255);
            this.page = 1;
            this.sync();
            this.fetch();
        },

        resetSimilarity({ restorePrior = false } = {}) {
            this.invalidateSearchRequests();
            const previous = this.similarityPriorView;
            this.similarTo = null; this.similarName = ''; this.similarityPriorView = null;
            this._restoreSimilaritySort = false;
            if (restorePrior) this.restoreListingView(previous);
        },

        captureListingView() {
            return JSON.parse(JSON.stringify({
                search: this.search, searchInput: this.searchInput, page: this.page,
                mediaType: this.mediaType, sortBy: this.sortBy, sortOrder: this.sortOrder,
                sortBeforeSearch: this.sortBeforeSearch, scope: this.scope, currentDirId: this.currentDirId,
                breadcrumb: this.breadcrumb, navHistory: this.navHistory, navIdx: this.navIdx,
                includeSubfolders: this.includeSubfolders, groupBy: this.groupBy,
                organization: this.organization ?? null, columns: this.applied.filters.columns,
            }));
        },

        restoreListingView(snapshot) {
            if (snapshot && Array.isArray(snapshot.columns)) {
                const view = JSON.parse(JSON.stringify(snapshot));
                for (const key of ['search', 'searchInput', 'page', 'mediaType', 'sortBy', 'sortOrder', 'sortBeforeSearch', 'scope', 'currentDirId', 'breadcrumb', 'navHistory', 'navIdx', 'includeSubfolders', 'groupBy', 'organization']) {
                    if (Object.hasOwn(view, key)) this[key] = view[key];
                }
                this.applied.filters.columns = view.columns;
                this._restoreSimilaritySort = true;
            }
        },

        exitSimilarity() {
            this.resetSimilarity({ restorePrior: true });
            this.sync();
            this.fetch();
        },

        onSort({ sortBy, sortOrder }) {
            this.sortBy = sortBy; this.sortOrder = sortOrder; this.page = 1;
            if (sortBy !== 'relevance' && sortBy !== 'position') {
                try { localStorage.setItem('dam_explorer_sort', `${sortBy}:${sortOrder}`); } catch {}
                this.organizePersistSort?.(sortBy, sortOrder); // P4 organization: kept on the server too
            }
            this.fetch();
        },

        onSortSelection(value) {
            const [sortBy, sortOrder] = String(value).split(':');
            this.onSort({ sortBy, sortOrder });
        },

        onPage(p) {
            // A second click on the page already loading would only repeat the request.
            if (p === this.page && this.loading) return;
            // Select-all and the bulk actions act on the rows in view: a selection from another page would be
            // hidden but still sent, so paging starts a fresh one.
            this.clearSelection();
            this.page = p;
            this.fetch();
        },

        onPerPage(pp) {
            if (pp === this.perPage) return;
            this.clearSelection();
            this.perPage = pp;
            this.page = 1;
            // Remembered across folders and tabs, as sort and view are.
            try { localStorage.setItem('dam_explorer_per_page', String(pp)); } catch {}
            this.fetch();
        },

        sync() {
            this.$emit('tab-state-change', {
                directoryId: this.currentDirId,
                scope:       this.scope,
                mediaType:   this.mediaType,
                search:      this.search,
                similarity:  this.similarTo ? { assetId: this.similarTo, name: this.similarName, priorView: this.similarityPriorView } : null,
                listingView: this.captureListingView(),
                viewMode:    this.viewMode,
                page:        this.page,
                perPage:     this.perPage,
            });
        },

        onFileChange(e) {
            const files = e.target.files;
            if (! files?.length) { e.target.value = ''; return; }
            const targetDirId = this.uploadTargetDirId ?? this.currentDirId;
            this.uploadTargetDirId = null;
            this.$refs.dropUpload?.enqueueUpload({
                items: Array.from(files).map(f => ({ file: f, relativePath: f.name, preserveRoot: false })),
                folderPaths: [],
                targetDirId,
            });
            e.target.value = '';
        },

        async onInternalDrop({ payload, targetDir, refreshTabId = null }) {
            // localAccessibleIds is the live grant list (it receives dam:directory-granted for folders
            // created this session); the accessibleIds prop is only the page-load snapshot.
            if (! this.aclBypass && ! this.localAccessibleIds.map(Number).includes(Number(targetDir.id))) {
                this.$emitter.emit('add-flash', { type: 'warning', message: "You do not have access to this directory." });
                return;
            }
            if (window.MothershipFileOps.isSelectionDrag(payload)) {
                // A selection dragged from another tab moves in that tab, which clears its own selection.
                if (payload.tabId !== this.tabId) {
                    this.$emitter.emit(`dam:explorer-internal-drop:${payload.tabId}`, { payload, targetDir, refreshTabId: this.tabId });
                    return;
                }
                const dirIds = payload.dirIds ?? [];
                if (dirIds.map(Number).includes(Number(targetDir.id))) return;
                this.performMassMove(targetDir.id, targetDir.name ?? '', { assetIds: payload.ids ?? [], dirIds, refreshTabId });
                return;
            }
            if (payload.type === 'dam-asset') {
                if (! await this.previewAndConfirmMove({
                    assetIds: [payload.id], targetId: targetDir.id,
                    confirmMessage: "This move changes who can see :count item. Continue?|This move changes who can see :count items. Continue?",
                })) {
                    return;
                }

                const originTabId = payload.tabId;
                this.$emitter.emit('add-flash', { type: 'success', message: "Moving file..." });
                this.$axios.post("http://mothership.test/admin/dam/assets/moved", {
                    move_item_id:  payload.id,
                    new_parent_id: targetDir.id,
                }).then(() => {
                    this.$emitter.emit('add-flash', { type: 'success', message: "File moved successfully." });
                    this.fetch();
                    this.$emitter.emit('dam:tree-reload');
                    if (originTabId && originTabId !== this.tabId) {
                        this.$emitter.emit(`dam:explorer-ctx-refresh:${originTabId}`);
                    }
                }).catch(err => {
                    this.$emitter.emit('add-flash', { type: 'error', message: globalThis.mothership?.requestErrorMessage?.(err) ?? err?.response?.data?.message ?? "Something went wrong. Please try again." });
                });
            } else if (payload.type === 'dam-folder') {
                if (! await this.previewAndConfirmMove({
                    dirIds: [payload.id], targetId: targetDir.id,
                    confirmMessage: "This move changes who can see :count item. Continue?|This move changes who can see :count items. Continue?",
                })) {
                    return;
                }

                const tabId = this.tabId;
                const originTabId = payload.tabId;
                const folderName = payload.name ?? '';
                this.operationOverlay = { show: true, label: `Moving directory ":name"…`.replace(':name', folderName), progress: null, fileCount: null };
                this.$axios.post("http://mothership.test/admin/dam/directory/moved", {
                    move_item_id:  payload.id,
                    new_parent_id: targetDir.id,
                }).then(() => {
                    let attempts = 0;
                    const poll = () => {
                        if (++attempts > 30) {
                            this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                            return;
                        }
                        this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'move_directory_structure'))
                            .then(({ data: d }) => {
                                if (d.status === 'completed') {
                                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                                    this.$emitter.emit('add-flash', { type: 'success', message: "Folder moved successfully." });
                                    this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                    this.$emitter.emit('dam:tree-reload');
                                    if (originTabId && originTabId !== tabId) {
                                        this.$emitter.emit(`dam:explorer-ctx-refresh:${originTabId}`);
                                    }
                                } else if (d.status === 'failed') {
                                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                                    this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                    this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                    if (originTabId && originTabId !== tabId) {
                                        this.$emitter.emit(`dam:explorer-ctx-refresh:${originTabId}`);
                                    }
                                } else { setTimeout(poll, 2000); }
                            }).catch(() => { setTimeout(poll, 2000); });
                    };
                    setTimeout(poll, 1000);
                }).catch(err => {
                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                    this.$emitter.emit('add-flash', { type: 'error', message: globalThis.mothership?.requestErrorMessage?.(err) ?? err?.response?.data?.message ?? "Something went wrong. Please try again." });
                });
            }
        },

        onFolderChange(e) {
            const files = Array.from(e.target.files ?? []);
            if (! files.length) { e.target.value = ''; return; }
            const targetDirId = this.uploadTargetDirId ?? this.currentDirId;
            this.uploadTargetDirId = null;

            const folderPaths = new Set();
            files.forEach(f => {
                const rel  = f.webkitRelativePath || f.name;
                const segs = rel.split('/');
                for (let i = 1; i < segs.length; i++) folderPaths.add(segs.slice(0, i).join('/'));
            });

            this.$refs.dropUpload?.enqueueUpload({
                items: files.filter(f => f.size > 0)
                            .map(f => ({ file: f, relativePath: f.webkitRelativePath || f.name, preserveRoot: true })),
                folderPaths: [...folderPaths],
                targetDirId,
            });
            e.target.value = '';
        },

        triggerNewUpload() {
            this.uploadTargetDirId = this.currentDirId;
            this.$nextTick(() => document.getElementById(`explorer-upload-${this.tabId}`)?.click());
        },
        triggerStackUpload() {
            this.uploadTargetDirId = this.currentDirId;
            this.$nextTick(() => document.getElementById(`explorer-stack-upload-${this.tabId}`)?.click());
        },
        // "Upload as a stack": the files upload into this folder as usual, then the create-stack review opens
        // with exactly those files. A single file is not uploaded, since it cannot be a stack.
        onStackFileChange(e) {
            const files = Array.from(e.target.files ?? []);
            e.target.value = '';
            const targetDirId = this.uploadTargetDirId ?? this.currentDirId;
            this.uploadTargetDirId = null;
            if (! files.length) return;
            if (files.length < 2) {
                this.$emitter.emit('add-flash', { type: 'warning', message: "Pick at least 2 files to upload as a stack. Nothing was uploaded." });
                return;
            }
            this.$refs.dropUpload?.enqueueUpload({
                items: files.map(f => ({ file: f, relativePath: f.name, preserveRoot: false })),
                folderPaths: [],
                targetDirId,
                stackContext: `explorer:${this.tabId}`,
            });
        },
        triggerNewFolderUpload() {
            this.uploadTargetDirId = this.currentDirId;
            this.$nextTick(() => document.getElementById(`explorer-folder-upload-${this.tabId}`)?.click());
        },
        createDirHere() {
            if (! this.currentDirId) return;
            const path = this.breadcrumb.filter(c => c.id != null).map(c => c.name).join(' / ');
            this.$emitter.emit('dam:open-create-dir', { item: { id: this.currentDirId, path } });
        },
        pasteHere() {
            this.executePaste(this.currentDirId);
        },

        executePaste(targetDirId) {
            if (! this.clipboard || ! targetDirId || ! this.canUploadHere || this.pasting || this.operationOverlay.show) return;
            const cb = this.clipboard;
            this.pasting = true;

            if (cb.type === 'asset') {
                this.$emitter.emit('add-flash', { type: 'success', message: "Pasting file..." });
                this.$axios.post("http://mothership.test/admin/dam/explorer/copy/asset", {
                    asset_id:            cb.id,
                    target_directory_id: targetDirId,
                }).then(() => {
                    this.$emitter.emit('add-flash', { type: 'success', message: "Pasted successfully." });
                    this.fetch();
                }).catch(err => {
                    this.$emitter.emit('add-flash', { type: 'error', message: (globalThis.mothership?.requestErrorMessage?.(err) ?? err?.response?.data?.message) });
                }).finally(() => { this.pasting = false; });
            } else {
                const dirName = cb.name ?? '';
                this.operationOverlay = { show: true, label: `Copying directory ":name"…`.replace(':name', dirName), progress: null, fileCount: null };
                this.$axios.post("http://mothership.test/admin/dam/explorer/copy/directory", {
                    directory_id:        cb.id,
                    target_directory_id: targetDirId,
                }).then(() => {
                    let attempts = 0;
                    const poll = () => {
                        if (++attempts > 30) {
                            this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                            return;
                        }
                        this.$axios.get(`http://mothership.test/admin/dam/action-request/status/:et`.replace(':et', 'copy_directory'))
                            .then(({ data: d }) => {
                                if (d.status === 'completed') {
                                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                                    this.$emitter.emit('add-flash', { type: 'success', message: "Pasted successfully." });
                                    this.fetch();
                                } else if (d.status === 'failed') {
                                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                                    this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                } else { setTimeout(poll, 2000); }
                            }).catch(() => { setTimeout(poll, 2000); });
                    };
                    setTimeout(poll, 1000);
                }).catch(err => {
                    this.operationOverlay = { show: false, label: '', progress: null, fileCount: null };
                    this.$emitter.emit('add-flash', { type: 'error', message: (globalThis.mothership?.requestErrorMessage?.(err) ?? err?.response?.data?.message) });
                }).finally(() => { this.pasting = false; });
            }
        },
    },
});
