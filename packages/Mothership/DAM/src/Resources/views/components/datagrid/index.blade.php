@props(['isMultiRow' => false])

<v-dam-classic-datagrid {{ $attributes }}>
    <x-admin::shimmer.datagrid :isMultiRow="$isMultiRow" />

    {{ $slot }}
</v-dam-classic-datagrid>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dam-classic-datagrid-template"
    >
        <div>
            <x-dam::datagrid.toolbar />

            {{-- A failed load replaces the results: no endless skeleton, no stale rows, no "undefined Results". --}}
            <v-load-error
                v-if="loadError && ! isLoading"
                class="mt-4"
                :message="loadError.message"
                :retryable="loadError.retryable"
                @retry="get()"
            ></v-load-error>

            <div v-else class="flex mt-4">
                <x-admin::datagrid.table :isMultiRow="$isMultiRow">
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
                </x-admin::datagrid.table>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-classic-datagrid', {
            template: '#v-dam-classic-datagrid-template',

            props: ['src'],

            data() {
                return {
                    isLoading: false,

                    hasLoaded: false,

                    // The last load's failure ({ message, retryable }), shown in place of the results.
                    loadError: null,

                    gridRequestGeneration: 0,

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
            },

            mounted() {
                this.boot();
            },

            methods: {

                boot() {
                    let datagrids = this.getDatagrids();

                    const urlParams = new URLSearchParams(window.location.search);

                    if (urlParams.has('search')) {
                        let searchAppliedColumn = this.findAppliedColumn('all');
                        searchAppliedColumn.value = [urlParams.get('search')];
                    }

                    if (datagrids?.length) {
                        const currentDatagrid = datagrids.find(({
                            src
                        }) => src === this.src);

                        if (currentDatagrid) {
                            this.applied.pagination = currentDatagrid.applied.pagination;

                            this.applied.sort = currentDatagrid.applied.sort;

                            this.applied.filters = currentDatagrid.applied.filters;

                            if (urlParams.has('search')) {
                                let searchAppliedColumn = this.findAppliedColumn('all');

                                searchAppliedColumn.value = [urlParams.get('search')];
                            }

                            this.get();

                            return;
                        }
                    }

                    this.get();
                },

                get(extraParams = {}) {
                    const generation = ++this.gridRequestGeneration;

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

                    this.isLoading = true;

                    this.loadError = null;

                    this.$refs['filterDrawer']?.close();

                    this.$axios
                        .get(this.src, {
                            params: {
                                ...params,
                                ...extraParams
                            }
                        })
                        .then((response) => {
                            // The toolbar stays usable while a reload is in flight, so a newer request can
                            // start before this one lands; only the latest may update the grid.
                            if (generation !== this.gridRequestGeneration) return;

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
                        })
                        .catch((error) => {
                            // Next/Previous step from the requested page, which never loaded: fall back to the
                            // last loaded one. Only the latest request may move it.
                            if (generation === this.gridRequestGeneration && this.available.meta?.current_page) {
                                this.applied.pagination.page = this.available.meta.current_page;
                            }

                            if (generation !== this.gridRequestGeneration) return;

                            const failure = globalThis.mothership?.requestError?.(error) ?? { message: '', retryable: true };

                            if (failure.cancelled) return;

                            this.isLoading = false;

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
                    }  else if (typeof directionOrPageNumber === 'number') {
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
                    if ('search' == $event.srcElement.name) {
                        this.get();
                    }
                },

                runFilters() {
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

                            if (! found) {
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

                    this.$emitter.emit('delete' === actionType ? 'open-delete-modal': 'open-confirm-modal', {
                        agree: () => {
                            switch (method) {
                                case 'post':
                                case 'put':
                                case 'patch':
                                    this.$axios[method](action.url, {
                                            indices: this.applied.massActions.indices,
                                            value: this.applied.massActions.value,
                                        })
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
                                        });

                                    break;

                                case 'delete':
                                    this.$axios[method](action.url, {
                                            indices: this.applied.massActions.indices
                                        })
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
                                        });

                                    break;

                                default:
                                    console.error('Method not supported.');

                                    break;
                            }

                            this.applied.massActions.indices = [];
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
