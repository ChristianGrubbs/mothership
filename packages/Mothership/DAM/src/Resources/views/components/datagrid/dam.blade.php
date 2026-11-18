@props(['isMultiRow' => false])
@include('dam::components.explorer.folder-picker')

<v-dam-datagrid {{ $attributes }}>
    <x-admin::shimmer.datagrid :isMultiRow="$isMultiRow" />

    {{ $slot }}
</v-dam-datagrid>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dam-datagrid-template"
    >
        <div
            class="relative"
            :class="{ 'pointer-events-none cursor-not-allowed': gridLocked }"
            :aria-busy="gridLocked"
        >

            <div
                v-if="gridLocked && !actionInFlight"
                class="absolute inset-0 bg-white/60 dark:bg-cherry-900/60 z-[1] rounded-lg"
                aria-hidden="true"
            ></div>

            <div
                v-if="actionInFlight"
                class="fixed inset-0 flex items-center justify-center bg-black/50 dark:bg-black/70 backdrop-blur-sm"
                style="z-index: 99998;"
                role="status"
                aria-live="polite"
            >
                <div
                    class="flex flex-col items-center gap-4 bg-white dark:bg-cherry-800 rounded-xl px-12 py-8 shadow-2xl border border-gray-200 dark:border-cherry-600 w-96 max-w-[90vw] relative"
                    style="min-width: 360px; z-index: 99999;"
                >
                    <svg class="animate-spin h-12 w-12 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span class="text-base font-semibold text-gray-900 dark:text-white text-center" v-text="actionStatusLabel"></span>
                </div>
            </div>

            <v-dam-folder-picker :open="folderPicker.open" tab-id="datagrid" @picked="onMoveTargetPicked" @close="folderPicker.open = false"></v-dam-folder-picker>
            <x-dam::datagrid.toolbar />

            {{-- A failed load replaces the results: no endless skeleton, no stale rows, no "undefined Results". --}}
            <v-load-error
                v-if="loadError && ! isLoading"
                class="mt-4"
                :message="loadError.message"
                :retryable="loadError.retryable"
                @retry="get()"
            ></v-load-error>

            <template v-else>
            @if (bouncer()->hasPermission('dam.directory.index'))
                <x-dam::datagrid.folders></x-dam::datagrid.folders>
                {{-- A folder chosen in the URL replaces the root's folders at once, so it gets no root seed. --}}
                <v-dam-gallery-folders :seed='@json(request()->has('filters.directory_id') ? null : dam_library_folder_seed())'></v-dam-gallery-folders>
            @endif

            <div class="flex mt-4">
                <x-dam::datagrid.gallery :isMultiRow="$isMultiRow">
                    <template #header>
                        <slot
                            name="header"
                            :columns="available.columns"
                            :actions="available.actions"
                            :mass-actions="available.massActions"
                            :records="available.records"
                            :meta="available.meta"
                            :sort-page="sortPage"
                            :selectAllRecords="selectAllRecords"
                            :available="available"
                            :applied="applied"
                            :is-loading="isLoading"
                        >
                        </slot>
                    </template>

                    <template #body>
                        <slot
                            name="body"
                            :columns="available.columns"
                            :actions="available.actions"
                            :mass-actions="available.massActions"
                            :records="available.records"
                            :meta="available.meta"
                            :setCurrentSelectionMode="setCurrentSelectionMode"
                            :performAction="performAction"
                            :available="available"
                            :applied="applied"
                            :is-loading="isLoading"
                        >
                        </slot>
                    </template>
                </x-dam::datagrid.gallery>
            </div>
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-datagrid', {
            template: '#v-dam-datagrid-template',

            props: ['src', 'ignoreStoredState'],

            data() {
                return {
                    damAccessLists: @json(dam_access_lists()),
                    folderPicker: { open: false },
                    isLoading: false,
                    hasLoaded: false,
                    // The last load's failure ({ message, retryable }), shown in place of the results.
                    loadError: null,
                    gridRequestGeneration: 0,
                    actionInFlight: false,
                    actionStatusLabel: '',
                    treeBusy: false,
                    searchDebounceTimer: null,
                    expandedFilter: null,
                    hiddenFilterIndices: ['all', 'directory_id', 'directory_asset_id'],

                    available: {
                        id: null,

                        columns: [],

                        actions: [],

                        massActions: [],

                        records: [],

                        meta: {},
                    },

                    applied: {
                        massActions: {
                            meta: {
                                mode: 'none',

                                action: null,
                            },

                            indices: [],

                            value: null,
                        },

                        pagination: {
                            page: 1,

                            perPage: 50,
                        },

                        sort: {
                            column: null,

                            order: null,
                        },

                        filters: {
                            columns: [
                                {
                                    index: 'all',
                                    value: [],
                                },
                            ],
                        },
                    },
                };
            },

            computed: {

                /**
                 * The mass actions this selection can use. Rename with AI is left out when every selected file is of a type the AI
                 * can never describe (the server's `ai_possible` hint on each record); a file on another page counts as possible.
                 */
                visibleMassActions() {
                    const actions = this.available.massActions ?? [];
                    const primary = this.available.meta?.primary_column;
                    const picked = this.applied.massActions.indices;
                    const noneCan = picked.length > 0 && picked.every(id => this.available.records.find(r => Number(r[primary]) === Number(id))?.ai_possible === false);

                    return noneCan ? actions.filter(action => action?.options?.actionType?.toLowerCase() !== 'ai-rename') : actions;
                },

                gridBusy() {
                    return !! this.actionInFlight;
                },

                gridLocked() {
                    return this.actionInFlight || this.treeBusy;
                },
            },

            watch: {
                src() {
                    this.applied.pagination.page = 1;
                    this.get();
                },
                gridBusy(value) {
                    this.$emitter.emit('dam:grid-busy', value);
                },
            },

            mounted() {
                this.$emitter.on('data-grid:reset-all-filters', () => {
                    this.applied.filters.columns = [{
                        index: 'all',
                        value: []
                    }];
                    this.applied.pagination.page = 1;
                })

                this.$emitter.on('data-grid:refresh', () => this.get())

                this.$emitter.on('data-grid:filter', (data) => {
                    data.value.forEach( (value, index) => {
                        this.applyFilter(data.column, value);
                    });

                    this.get();
                })

                this.$emitter.on('dam:tree-busy', (busy) => {
                    this.treeBusy = !! busy;
                });

                this.$emitter.on('dam:tag-assign:done', ({ context } = {}) => {
                    if (context && context !== 'legacy-datagrid') return;
                    this.applied.massActions.indices = [];
                    this.applied.massActions.meta.mode = 'none';
                    this.get();
                });

                this.$emitter.on('dam:ai-rename-applied', ({ context } = {}) => {
                    if (context && context !== 'legacy-datagrid') return;
                    this.get();
                });

                this.boot();
            },

            beforeUnmount() {
                this.gridRequestGeneration++;
                clearTimeout(this.searchDebounceTimer);
            },

            methods: {

                boot() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const explicit = this.ignoreStoredState || ['scope', 'search', 'filters[all][]', 'directory_id', 'filters[directory_id][]', 'media_type'].some(key => urlParams.has(key));
                    if (!explicit) {
                        const datagrids = this.getDatagrids();
                        const legacySource = this.src.replace(/\?media_type=all$/, '');
                        const saved = datagrids.find(grid => grid.src === this.src) || datagrids.find(grid => grid.src === legacySource);
                        if (saved?.applied) {
                            this.applied.pagination = saved.applied.pagination;
                            this.applied.sort = saved.applied.sort;
                            this.applied.filters = saved.applied.filters;
                        }
                    } else {
                        this.applied.pagination.page = 1;
                        const search = urlParams.get('search') ?? urlParams.get('filters[all][]') ?? '';
                        this.applied.filters.columns = [{index: 'all', value: search === '' ? [] : [search]}];
                        const directory = Number(urlParams.get('directory_id') || urlParams.get('filters[directory_id][]'));
                        if (urlParams.get('scope') !== 'all' && directory > 0) {
                            this.applied.filters.columns.push({index: 'directory_id', value: [directory]});
                        }
                    }
                    this.get();
                },

                get(extraParams = {}) {
                    const generation = ++this.gridRequestGeneration;
                    const source = this.src;
                    let params = {
                        pagination: {
                            page: this.applied.pagination.page,
                            per_page: this.applied.pagination.perPage,
                        },

                        sort: {},

                        filters: {},
                    };

                    if (
                        this.applied.sort.column &&
                        this.applied.sort.order
                    ) {
                        params.sort = this.applied.sort;
                    }

                    this.applied.filters.columns.forEach(column => {
                        params.filters[column.index] = column.value;
                    });

                    const focusedName = document.activeElement?.name ?? null;
                    const focusedSelectionStart = document.activeElement?.selectionStart ?? null;

                    this.isLoading = true;

                    this.loadError = null;

                    this.$refs['filterDrawer']?.close();

                    return this.$axios
                        .get(source, {
                            params: {
                                ...params,
                                ...extraParams
                            }
                        })
                        .then((response) => {
                            if (generation !== this.gridRequestGeneration || source !== this.src) return;

                            // An auth redirect answers with HTML: keep the last load, and step from its page again.
                            if (! Array.isArray(response.data?.columns)) {
                                this.applied.pagination.page = this.available.meta?.current_page ?? this.applied.pagination.page;

                                this.isLoading = false;

                                this.loadError = { message: globalThis.mothership?.requestErrorText?.('server') ?? '', retryable: true };

                                return;
                            }

                            const {
                                id,
                                columns,
                                actions,
                                mass_actions,
                                search_placeholder,
                                records,
                                meta
                            } = response.data;

                            this.available.id = id;

                            this.available.columns = columns;

                            this.available.actions = actions;

                            this.available.massActions = mass_actions;

                            this.available.records = records;

                            this.available.meta = meta;

                            this.available.searchPlaceholder = search_placeholder;

                            this.setCurrentSelectionMode();

                            this.updateDatagrids();

                            this.$emitter.emit('change-datagrid', {
                                available: this.available,
                                applied: this.applied
                            });

                            this.hasLoaded = true;

                            this.isLoading = false;

                            if (focusedName) {
                                this.$nextTick(() => {
                                    const el = this.$el.querySelector(`input[name="${focusedName}"]`);
                                    if (el) {
                                        el.focus();
                                        if (focusedSelectionStart !== null) {
                                            el.setSelectionRange(focusedSelectionStart, focusedSelectionStart);
                                        }
                                    }
                                });
                            }
                        })
                        .catch(error => {
                            if (generation !== this.gridRequestGeneration || source !== this.src) return;
                            // Next/Previous step from the requested page, which never loaded.
                            this.applied.pagination.page = this.available.meta?.current_page ?? this.applied.pagination.page;
                            this.isLoading = false;
                            const failure = globalThis.mothership?.requestError?.(error) ?? { message: error.message, retryable: true };
                            if (failure.cancelled) return;
                            this.loadError = { message: failure.message, retryable: failure.retryable };
                        });
                },

                changePage(directionOrPageNumber) {
                    let newPage;

                    if (typeof directionOrPageNumber === 'string') {
                        // Step from the requested page, not the last loaded one: the toolbar stays usable
                        // while a reload is in flight, so a second Next must go one page further.
                        if (directionOrPageNumber === 'first') {
                            newPage = 1;
                        } else if (directionOrPageNumber === 'previous') {
                            newPage = this.applied.pagination.page - 1;
                        } else if (directionOrPageNumber === 'next') {
                            newPage = this.applied.pagination.page + 1;
                        } else if (directionOrPageNumber === 'last') {
                            newPage = this.available.meta.last_page;
                        } else {
                            console.warn('Invalid Direction Provided : ' + directionOrPageNumber);

                            return;
                        }
                    } else if (typeof directionOrPageNumber === 'number') {
                        newPage = directionOrPageNumber;
                    } else {
                        console.warn('Invalid Input Provided: ' + directionOrPageNumber);

                        return;
                    }

                    if (newPage >= 1 && newPage <= this.available.meta.last_page) {
                        this.applied.pagination.page = newPage;

                        this.get();
                    } else {
                        console.warn('Invalid Page Provided: ' + newPage);
                    }
                },

                changePerPageOption(option) {
                    this.applied.pagination.perPage = option;

                    if (this.available.meta.last_page >= this.applied.pagination.page) {
                        this.applied.pagination.page = 1;
                    }

                    this.get();
                },

                sortPage(column) {
                    if (column.sortable) {
                        this.applied.sort = {
                            column: column.index,
                            order: this.applied.sort.order === 'asc' ? 'desc' : 'asc',
                        };

                        this.applied.pagination.page = 1;

                        this.get();
                    }
                },

                filterPage($event, column = null, additional = {}) {
                    let quickFilter = additional?.quickFilter;

                    if (quickFilter?.isActive) {
                        let options = quickFilter.selectedFilter;

                        switch (column.type) {
                            case 'date_range':
                            case 'datetime_range':
                                this.applyFilter(column, options.from, {
                                    range: {
                                        name: 'from'
                                    }
                                });

                                this.applyFilter(column, options.to, {
                                    range: {
                                        name: 'to'
                                    }
                                });

                                break;

                            default:
                                break;
                        }
                    } else {

                        if ($event?.target?.value === undefined) {
                            $event = {
                                target: {
                                    value: $event,
                                }
                            };
                        }

                        this.applyFilter(column, $event.target.value, additional);

                        if (column) {
                            $event.target.value = '';
                        }
                    }

                    this.applied.pagination.page = 1;
                    if ('search' == $event.srcElement?.name) {
                        this.get();
                    }
                },

                debouncedFilterPage($event) {
                    clearTimeout(this.searchDebounceTimer);
                    this.searchDebounceTimer = setTimeout(() => this.filterPage($event), 500);
                },

                runFilters() {
                    this.applied.pagination.page = 1;

                    this.get();
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

                appliedValuesSummary(column, values) {
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

                        return @json(trans('admin::app.components.datagrid.filters.values-selected')).replace(':count', values.length);
                    }

                    return values
                        .map(value => Array.isArray(value) ? value.filter(Boolean).join(' – ') : value)
                        .join(', ');
                },

                collapsedSummary(column) {
                    return this.filterHasValue(column)
                        ? this.appliedValuesSummary(column, this.getAppliedColumnValues(column.index))
                        : @json(trans('admin::app.components.datagrid.filters.no-value'));
                },

                appliedFilterCount() {
                    return this.applied.filters.columns.filter(
                        column => ! this.hiddenFilterIndices.includes(column.index) && (column.value?.length ?? 0) > 0
                    ).length;
                },

                hasAppliedFilters() {
                    return this.appliedFilterCount() > 0;
                },

                clearAllFilters() {
                    this.applied.filters.columns = this.applied.filters.columns.filter(
                        column => this.hiddenFilterIndices.includes(column.index)
                    );

                    this.applied.pagination.page = 1;

                    this.get();
                },

                applyFilter(column, requestedValue, additional = {}) {
                    let appliedColumn = this.findAppliedColumn(column?.index);

                    if (! column) {
                        let appliedColumn = this.findAppliedColumn('all');

                        if (! requestedValue) {
                            appliedColumn.value = [];

                            return;
                        }

                        if (appliedColumn) {
                            appliedColumn.value = [requestedValue];
                        } else {
                            this.applied.filters.columns.push({
                                index: 'all',
                                value: [requestedValue]
                            });
                        }

                    } else {

                        if (
                            requestedValue === undefined ||
                            requestedValue === '' ||
                            appliedColumn?.value.includes(requestedValue)
                        ) {
                            return;
                        }

                        switch (column.type) {
                            case 'date_range':
                            case 'datetime_range':
                                let {
                                    range
                                } = additional;

                                if (appliedColumn) {
                                    let appliedRanges = appliedColumn.value[0];

                                    if (range.name == 'from') {
                                        appliedRanges[0] = requestedValue;
                                    }

                                    if (range.name == 'to') {
                                        appliedRanges[1] = requestedValue;
                                    }

                                    appliedColumn.value = [appliedRanges];
                                } else {
                                    let appliedRanges = ['', ''];

                                    if (range.name == 'from') {
                                        appliedRanges[0] = requestedValue;
                                    }

                                    if (range.name == 'to') {
                                        appliedRanges[1] = requestedValue;
                                    }

                                    this.applied.filters.columns.push({
                                        ...column,
                                        value: [appliedRanges]
                                    });
                                }

                                break;

                            default:
                                if (appliedColumn) {
                                    appliedColumn.value.push(requestedValue);
                                } else {
                                    this.applied.filters.columns.push({
                                        ...column,
                                        value: [requestedValue]
                                    });
                                }

                                break;
                        }
                    }
                },

                findAppliedColumn(columnIndex) {
                    return this.applied.filters.columns.find(column => column.index === columnIndex);
                },

                hasAnyAppliedColumnValues(columnIndex) {
                    let appliedColumn = this.findAppliedColumn(columnIndex);

                    return appliedColumn?.value.length > 0;
                },

                getAppliedColumnValues(columnIndex) {
                    let appliedColumn = this.findAppliedColumn(columnIndex);

                    return appliedColumn?.value ?? [];
                },

                removeAppliedColumnValue(columnIndex, appliedColumnValue) {
                    let appliedColumn = this.findAppliedColumn(columnIndex);

                    appliedColumn.value = appliedColumn?.value.filter(value => value !== appliedColumnValue);

                    if (!appliedColumn.value.length) {
                        this.applied.filters.columns = this.applied.filters.columns.filter(column => column
                            .index !== columnIndex);
                    }
                },

                removeAppliedColumnAllValues(columnIndex) {
                    this.applied.filters.columns = this.applied.filters.columns.filter(column => column.index !==
                        columnIndex);

                    this.get();
                },

                setCurrentSelectionMode() {
                    this.applied.massActions.meta.mode = 'none';

                    if (! this.available.records.length) {
                        return;
                    }

                    let selectionCount = 0;

                    this.available.records.forEach(record => {
                        const id = record[this.available.meta.primary_column];

                        if (this.applied.massActions.indices.includes(id)) {
                            this.applied.massActions.meta.mode = 'partial';

                            ++selectionCount;
                        }
                    });

                    if (this.available.records.length === selectionCount) {
                        this.applied.massActions.meta.mode = 'all';
                    }
                },

                selectAllRecords() {
                    this.setCurrentSelectionMode();

                    if (['all', 'partial'].includes(this.applied.massActions.meta.mode)) {
                        this.available.records.forEach(record => {
                            const id = record[this.available.meta.primary_column];

                            this.applied.massActions.indices = this.applied.massActions.indices.filter(
                                selectedId => selectedId !== id);
                        });

                        this.applied.massActions.meta.mode = 'none';
                    } else {
                        this.available.records.forEach(record => {
                            const id = record[this.available.meta.primary_column];

                            let found = this.applied.massActions.indices.find(selectedId => selectedId ===
                                id);

                            if (!found) {
                                this.applied.massActions.indices.push(id);
                            }
                        });

                        this.applied.massActions.meta.mode = 'all';
                    }
                },

                validateMassAction() {
                    if (!this.applied.massActions.indices.length) {
                        this.$emitter.emit('add-flash', {
                            type: 'warning',
                            message: "@lang('admin::app.components.datagrid.index.no-records-selected')"
                        });

                        return false;
                    }

                    if (!this.applied.massActions.meta.action) {
                        this.$emitter.emit('add-flash', {
                            type: 'warning',
                            message: "@lang('admin::app.components.datagrid.index.must-select-a-mass-action')"
                        });

                        return false;
                    }

                    if (
                        this.applied.massActions.meta.action?.options?.length &&
                        this.applied.massActions.value === null
                    ) {
                        this.$emitter.emit('add-flash', {
                            type: 'warning',
                            message: "@lang('admin::app.components.datagrid.index.must-select-a-mass-action-option')"
                        });

                        return false;
                    }

                    return true;
                },

                onMoveTargetPicked(payload) {
                    this.folderPicker.open = false;
                    this.moveSelectedTo(payload?.id ?? payload, payload?.name ?? '');
                },

                async moveSelectedTo(targetId, targetName) {
                    if (this.actionInFlight) return;
                    const ids = [...this.applied.massActions.indices];
                    if (!ids.length) return;
                    this.actionInFlight = true;
                    this.actionStatusLabel = "@lang('dam::app.admin.explorer.mass-actions.moving')";
                    try {
                        const result = await window.MothershipFileOps.moveAssets({
                            axios: this.$axios, ids, targetId,
                            url: '{{ route("admin.dam.explorer.mass_move") }}',
                            statusUrl: `{{ route('admin.dam.action_request.status', 'mass_move') }}`,
                            previewUrl: this.damAccessLists ? '{{ route("admin.dam.access.move_preview") }}' : null,
                            confirmMove: this.damAccessLists ? (count) => new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                                message: "@lang('dam::app.admin.dam.access.move-preview-confirm')".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                                agree: () => resolve(true),
                                disagree: () => resolve(false),
                            })) : null,
                            // A preview failure (network, the cap, a refused move) must stop this move
                            // rather than let it through unconfirmed (Codex review of PR #75, finding 6).
                            onPreviewFailed: (error) => this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message }),
                        });
                        if (result === null) return;
                        this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.explorer.mass-actions.move-done')".split('|').at(ids.length === 1 ? 0 : -1).replace(':count', ids.length).replace(':source', 'Library').replace(':destination', targetName) });
                        this.applied.massActions.indices = [];
                        this.applied.massActions.meta.mode = 'none';
                        this.get();
                        this.$emitter.emit('dam:tree-reload');
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });
                    } finally {
                        this.actionInFlight = false;
                        this.actionStatusLabel = '';
                    }
                },

                performMassAction(currentAction, currentOption = null) {
                    this.applied.massActions.meta.action = currentAction;

                    if (currentOption) {
                        this.applied.massActions.value = currentOption.value;
                    }

                    if (! this.validateMassAction()) {
                        return;
                    }

                    const {
                        action
                    } = this.applied.massActions.meta;

                    const method = action.method.toLowerCase();
                    const actionType = action?.options?.actionType?.toLowerCase() ?? '';

                    if (actionType === 'move') {
                        this.folderPicker = { open: true };
                        return;
                    }

                    if (actionType === 'process') {
                        this.$emitter.emit('dam:open-batch-process', { assetIds: [...this.applied.massActions.indices] });

                        return;
                    }

                    if (actionType === 'ai-rename') {
                        const primary = this.available.meta.primary_column;
                        const assets = this.applied.massActions.indices.map(id => {
                            const record = this.available.records.find(r => Number(r[primary]) === Number(id));

                            return { id: Number(id), name: record?.file_name ?? String(id), thumb: record?.path || null, known: !! record };
                        });

                        // Nothing selected is a type the AI can describe: say so instead of opening an empty dialog.
                        const possible = this.applied.massActions.indices.some(id => this.available.records.find(r => Number(r[primary]) === Number(id))?.ai_possible !== false);

                        if (! possible) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: @json(trans('dam::app.admin.explorer.ai-rename.none-eligible')) });

                            return;
                        }

                        this.$emitter.emit('dam:open-ai-rename', { assets, skippedFolders: false, context: 'legacy-datagrid' });

                        return;
                    }

                    if (actionType === 'add-to-collection') {
                        this.$emitter.emit('dam:open-collection-add-modal', {
                            assetIds: [...this.applied.massActions.indices],
                            context: 'legacy-datagrid',
                        });

                        return;
                    }

                    if (actionType === 'assign-tags') {
                        this.$emitter.emit('dam:open-tag-assign-modal', {
                            assetIds: [...this.applied.massActions.indices],
                            context: 'legacy-datagrid',
                        });

                        return;
                    }

                    const selectedIds = [...this.applied.massActions.indices];
                    const selectedCount = selectedIds.length;
                    const startStatus = (label) => {
                        this.actionStatusLabel = label.replace(':count', selectedCount);
                        this.actionInFlight = true;
                    };
                    const stopStatus = () => {
                        this.actionInFlight = false;
                        this.actionStatusLabel = '';
                    };

                    this.$emitter.emit('delete' === actionType ? 'open-delete-modal': 'open-confirm-modal', {
                        agree: () => {
                            const statusLabel = 'delete' === actionType
                                ? `@lang('dam::app.admin.dam.index.mass-action.deleting')`
                                : `@lang('dam::app.admin.dam.index.mass-action.processing')`;

                            switch (method) {
                                case 'post':
                                case 'put':
                                case 'patch':
                                    startStatus(statusLabel);
                                    (actionType === 'delete'
                                        ? window.MothershipFileOps.deleteWithConfirmation({message: "@lang('dam::app.admin.dam.asset.delete-blocked-confirm')", emitter: this.$emitter,
                                            onBlocked: stopStatus, request: force => this.$axios[method](action.url, { indices: selectedIds, force }),
                                        })
                                        : this.$axios[method](action.url, { indices: selectedIds, value: this.applied.massActions.value }))
                                        .then(response => {
                                            if (!response) return;
                                            this.applied.massActions.indices = [];
                                            this.$emitter.emit('add-flash', {
                                                type: 'success',
                                                message: response.data.message
                                            });
                                            this.$emitter.emit('delete-assets', {
                                                actionType: 'mass-action',
                                                count: selectedCount,
                                            });
                                            this.get();
                                        })
                                        .catch((error) => {
                                            this.$emitter.emit('add-flash', {
                                                type: 'error',
                                                message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                            });
                                        })
                                        .finally(() => {
                                            stopStatus();
                                        });

                                    break;

                                case 'delete':
                                    startStatus(statusLabel);
                                    this.$axios[method](action.url, {
                                            indices: this.applied.massActions.indices
                                        })
                                        .then(response => {
                                            if (!response) return;
                                            this.applied.massActions.indices = [];
                                            this.$emitter.emit('add-flash', {
                                                type: 'success',
                                                message: response.data.message
                                            });

                                            if ('delete' === actionType) {
                                                this.$emitter.emit('delete-assets', {
                                                    actionType: 'mass-action',
                                                    count: selectedCount,
                                                });
                                            }

                                            this.get();
                                        })
                                        .catch((error) => {
                                            this.$emitter.emit('add-flash', {
                                                type: 'error',
                                                message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                            });
                                        })
                                        .finally(() => {
                                            stopStatus();
                                        });

                                    break;

                                default:
                                    console.error('Method not supported.');

                                    break;
                            }

                        }
                    });
                },

                updateDatagrids() {
                    let datagrids = this.getDatagrids();

                    if (datagrids?.length) {
                        const currentDatagrid = datagrids.find(({
                            src
                        }) => src === this.src);

                        if (currentDatagrid) {
                            datagrids = datagrids.map(datagrid => {
                                if (datagrid.src === this.src) {
                                    return {
                                        ...datagrid,
                                        requestCount: ++datagrid.requestCount,
                                        available: this.available,
                                        applied: this.applied,
                                    };
                                }

                                return datagrid;
                            });
                        } else {
                            datagrids.push(this.getDatagridInitialProperties());
                        }
                    } else {
                        datagrids = [this.getDatagridInitialProperties()];
                    }

                    this.setDatagrids(datagrids);
                },

                getDatagridInitialProperties() {
                    return {
                        src: this.src,
                        requestCount: 0,
                        available: this.available,
                        applied: this.applied,
                    };
                },

                getDatagridsStorageKey() {
                    return 'datagrids';
                },

                getDatagrids() {
                    let datagrids = localStorage.getItem(
                        this.getDatagridsStorageKey()
                    );

                    return JSON.parse(datagrids) ?? [];
                },

                setDatagrids(datagrids) {
                    localStorage.setItem(
                        this.getDatagridsStorageKey(),
                        JSON.stringify(datagrids)
                    );
                },

                performAction(action) {
                    const method = action.method.toLowerCase();

                    switch (method) {
                        case 'get':
                            this.$navigate(action.url);

                            break;

                        case 'post':
                        case 'put':
                        case 'patch':
                        case 'delete':
                            this.$emitter.emit('delete' === method ? 'open-delete-modal' : 'open-confirm-modal', {
                                agree: () => {
                                    this.actionInFlight = true;
                                    this.$axios[method](action.url)
                                        .then(response => {
                                            this.$emitter.emit('add-flash', {
                                                type: 'success',
                                                message: response.data.message
                                            });

                                            this.get();
                                        })
                                        .catch((error) => {
                                            this.$emitter.emit('add-flash', {
                                                type: 'error',
                                                message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                            });
                                        })
                                        .finally(() => {
                                            this.actionInFlight = false;
                                        });
                                }
                            });

                            break;

                        default:
                            console.error('Method not supported.');

                            break;
                    }
                },
            },
        });
    </script>
@endPushOnce
