@props(['isMultiRow' => false])

<v-datagrid-table>
    {{ $slot }}
</v-datagrid-table>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-datagrid-table-template"
    >
        <div class="w-full">
            {{--
                Narrow screens (below 640px, the phone range; the theme's own `sm` starts at 525px) scroll the
                table sideways instead of crushing columns: rows get a floor width, and an actual Actions cell
                (the ones below, and the callers' own, carry the sticky classes themselves) stays pinned to the
                right edge so it is never off-screen. The loading and empty-state rows have no Actions cell.
            --}}
            <div class="table-responsive grid w-full box-shadow rounded bg-white dark:bg-cherry-900 overflow-x-auto max-[639px]:[&_.row]:min-w-[960px]">
                <slot name="header">
                    <template v-if="$parent.isLoading">
                        <x-admin::shimmer.datagrid.table.head :isMultiRow="$isMultiRow" />
                    </template>

                    <template v-else>
                        <div
                            class="row grid gap-2.5 min-h-[47px] px-4 py-2.5 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 bg-primary-50 dark:bg-cherry-800 font-semibold items-center"
                            :style="`grid-template-columns: repeat(${gridsCount}, minmax(80px, 1fr))`"
                        >
                            <!-- Mass Actions -->
                            <p
                                v-if="$parent.available.massActions.length"
                                class="flex items-center gap-1"
                            >
                                <label for="mass_action_select_all_records">
                                    <input
                                        type="checkbox"
                                        name="mass_action_select_all_records"
                                        id="mass_action_select_all_records"
                                        class="peer sr-only"
                                        aria-label="@lang('admin::app.components.datagrid.table.select-all')"
                                        :checked="['all', 'partial'].includes($parent.applied.massActions.meta.mode)"
                                        @change="$parent.selectAllRecords"
                                    >

                                    <span
                                        aria-hidden="true"
                                        class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 cursor-pointer rounded-md text-2xl"
                                        :class="[
                                            $parent.applied.massActions.meta.mode === 'all' ? 'peer-checked:icon-checkbox-check peer-checked:text-primary-700 ' : (
                                                $parent.applied.massActions.meta.mode === 'partial' ? 'peer-checked:icon-checkbox-partial peer-checked:text-primary-700' : ''
                                            ),
                                        ]"
                                    >
                                    </span>
                                </label>

                                {{--
                                    Selecting the current page is the checkbox's own job. This dropdown only
                                    adds the cross-page actions, so it appears solely when the result set spans
                                    more than one page (and the grid allows selecting all).
                                --}}
                                <x-admin::dropdown
                                    position="bottom-left"
                                    v-if="$parent.available.meta.select_all_enabled !== false && $parent.available.meta.last_page > 1"
                                >
                                    <x-slot:toggle>
                                        <button
                                            type="button"
                                            class="icon-chevron-down cursor-pointer rounded-md text-xl leading-none text-gray-500 transition-all hover:text-primary-600 dark:text-gray-400 dark:hover:text-white"
                                            :aria-label="'@lang('admin::app.components.datagrid.toolbar.selection-options')'"
                                        >
                                        </button>
                                    </x-slot>

                                    <x-slot:menu class="!py-2 min-w-[180px]">
                                        <x-admin::dropdown.menu.item
                                            ::class="$parent.isSelectingAllMatching ? 'pointer-events-none opacity-60' : ''"
                                            @click="$parent.selectAllMatching()"
                                        >
                                            <span class="flex items-center gap-2">
                                                <span
                                                    class="text-lg text-primary-600"
                                                    :class="$parent.isSelectingAllMatching ? 'icon-loader animate-spin' : 'icon-select-all'"
                                                >
                                                </span>

                                                @{{ @json(trans('admin::app.components.datagrid.toolbar.select-all-matching')).replace(':total', $parent.available.meta.total) }}
                                            </span>
                                        </x-admin::dropdown.menu.item>

                                        <x-admin::dropdown.menu.item
                                            v-if="$parent.applied.massActions.indices.length"
                                            @click="$parent.clearMassSelection()"
                                        >
                                            <span class="flex items-center gap-2">
                                                <span class="icon-cross-large text-lg text-gray-500 dark:text-gray-400"></span>

                                                @lang('admin::app.components.datagrid.toolbar.clear-selection')
                                            </span>
                                        </x-admin::dropdown.menu.item>
                                    </x-slot>
                                </x-admin::dropdown>
                            </p>

                            <!-- Columns -->
                            <p
                                v-for="column in visibleColumns"
                                :key="column.index"
                                :data-grid-column="column.index"
                                class="flex gap-1.5 items-center min-w-0"
                                :class="{'cursor-pointer select-none hover:text-gray-800 dark:hover:text-white': column.sortable}"
                                @click="$parent.sortPage(column)"
                            >
                                <span
                                    class="block overflow-hidden text-ellipsis text-nowrap"
                                    :title="column.label"
                                    v-text="column.label"
                                >
                                </span>

                                <i
                                    class="text-base text-gray-600 dark:text-gray-300 align-text-bottom"
                                    :class="[$parent.applied.sort.order === 'asc' ? 'icon-down-stat' : 'icon-up-stat']"
                                    v-if="column.index == $parent.applied.sort.column"
                                >
                                </i>
                            </p>

                            <!-- Actions -->
                            <div
                                class="flex gap-2.5 items-center justify-end select-none"
                                :class="{'max-[639px]:sticky max-[639px]:right-0 max-[639px]:justify-self-end max-[639px]:pl-2 max-[639px]:z-[1] max-[639px]:whitespace-nowrap max-[639px]:bg-primary-50 dark:max-[639px]:bg-cherry-800': $parent.available.actions.length}"
                            >
                                <p
                                    class="text-gray-600 dark:text-gray-300"
                                    v-if="$parent.available.actions.length"
                                >
                                    @lang('admin::app.components.datagrid.table.actions')
                                </p>
                            </div>
                        </div>
                    </template>
                </slot>

                <slot name="body">
                    <template v-if="$parent.isLoading">
                        <x-admin::shimmer.datagrid.table.body :isMultiRow="$isMultiRow" />
                    </template>

                    <template v-else>
                        <template v-if="$parent.available.records.length">
                            <div
                                class="row grid gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all"
                                v-for="record in $parent.available.records"
                                :key="record[$parent.available.meta.primary_column]"
                                :data-row-key="record[$parent.available.meta.primary_column]"
                                :class="{'cursor-pointer hover:bg-primary-50 hover:bg-opacity-30 dark:hover:bg-cherry-800': isRowEditable(record)}"
                                :style="`grid-template-columns: repeat(${gridsCount}, minmax(80px, 1fr))`"
                                @click="handleRowClick($event, record)"
                            >
                                <!-- Mass Actions -->
                                <p v-if="$parent.available.massActions.length" @click.stop>
                                    <label :for="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`">
                                        <input
                                            type="checkbox"
                                            class="peer sr-only"
                                            :aria-label="selectRowLabel(record)"
                                            :name="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`"
                                            :value="record[$parent.available.meta.primary_column]"
                                            :id="`mass_action_select_record_${record[$parent.available.meta.primary_column]}`"
                                            v-model="$parent.applied.massActions.indices"
                                        >

                                        <span aria-hidden="true" class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl">
                                        </span>
                                    </label>
                                </p>

                                <!-- Columns -->
                                <div
                                    class="min-w-0"
                                    v-for="column in visibleColumns"
                                    :key="column.index"
                                >
                                    <template v-if="column.type === 'image'">
                                        <img
                                            :src="record[column.index] ? record[column.index] : '{{ mothership_asset('images/placeholder.svg') }}'"
                                            alt="@lang('admin::app.components.datagrid.table.thumbnail')"
                                            width="74"
                                            height="74"
                                            class="h-[120px] max-w-[60px] min-w-[60px] max-h-[60px] min-h-[60px] rounded-lg border border-gray-300 shadow-sm object-cover"
                                        />
                                    </template>

                                    <template v-else-if="column.type === 'gallery'">
                                        <template v-if="record[column.index]?.type === 'video'">
                                            <div class="relative h-[120px] max-w-[60px] min-w-[60px] max-h-[60px] min-h-[60px]">
                                                <video
                                                    :src="record[column.index].url"
                                                    width="74"
                                                    height="74"
                                                    class="h-full w-full rounded-lg border border-gray-300 shadow-sm object-cover"
                                                ></video>

                                                <!-- Overlay that blocks clicks -->
                                                <div class="absolute inset-0 bg-transparent z-10"></div>
                                            </div>
                                        </template>

                                        <template v-else>
                                            <img
                                                :src="record[column.index]?.url ? record[column.index].url : '{{ mothership_asset('images/placeholder.svg') }}'"
                                                alt="@lang('admin::app.components.datagrid.table.thumbnail')"
                                                width="74"
                                                height="74"
                                                class="h-[120px] max-w-[60px] min-w-[60px] max-h-[60px] min-h-[60px] rounded-lg border border-gray-300 shadow-sm object-cover"
                                            />
                                        </template>
                                    </template>

                                    {{-- Only closure columns emit trusted server-rendered HTML; everything else is plain text to prevent stored XSS. --}}
                                    <p
                                        v-else-if="column.closure"
                                        class="truncate"
                                        :title="stripHtml(record[column.index])"
                                        v-html="record[column.index]"
                                    >
                                    </p>

                                    <p
                                        v-else
                                        class="truncate"
                                        :title="stripHtml(record[column.index])"
                                        v-text="record[column.index]"
                                    >
                                    </p>
                                </div>

                                <!-- Actions -->
                                <div
                                    class="flex gap-2.5 items-center justify-end select-none"
                                    :class="{'mr-[-15px]': $parent.available.actions.length > 2, 'max-[639px]:sticky max-[639px]:right-0 max-[639px]:justify-self-end max-[639px]:pl-2 max-[639px]:z-[1] max-[639px]:whitespace-nowrap max-[639px]:bg-white dark:max-[639px]:bg-cherry-900': $parent.available.actions.length }"
                                    @click.stop
                                >
                                    <p
                                        class="text-gray-600 dark:text-gray-300"
                                        v-if="$parent.available.actions.length"
                                    >
                                        {{--
                                            Each action is a real control, so the keyboard and screen readers reach it: a link
                                            when it navigates, a button otherwise. The negative vertical margin keeps the row
                                            height the old icon span had, whose padding sat outside the line box.
                                        --}}
                                        <component
                                            :is="isLinkAction(action) ? 'a' : 'button'"
                                            class="cursor-pointer rounded-md p-1.5 -my-1.5 text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                            :class="action.icon"
                                            v-text="!action.icon ? action.title : ''"
                                            v-for="(action, actionIndex) in record.actions"
                                            :key="actionIndex"
                                            :type="isLinkAction(action) ? null : 'button'"
                                            :href="isLinkAction(action) ? action.url : null"
                                            :title="action.title ?? ''"
                                            :aria-label="action.title ?? ''"
                                            :data-row-action="action.index"
                                            @click="runRowAction($event, action, record)"
                                        >
                                        </component>
                                    </p>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="row grid gap-2 px-4 py-8 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 text-center max-[639px]:!min-w-0 max-[639px]:sticky max-[639px]:left-0 max-[639px]:w-[min(20rem,calc(100vw-4rem))]">
                                <p v-if="! $parent.hasAppliedFilters()">
                                    @lang('admin::app.components.datagrid.table.no-records-available')
                                </p>

                                <template v-else>
                                    <p>
                                        @lang('admin::app.components.datagrid.table.no-records-filtered')
                                    </p>

                                    <button
                                        type="button"
                                        class="transparent-button justify-center self-center text-sm"
                                        data-clear-filters-empty-state
                                        @click="$parent.clearAllFilters()"
                                    >
                                        @lang('admin::app.components.datagrid.filters.custom-filters.clear-all')
                                    </button>
                                </template>
                            </div>
                        </template>
                    </template>
                </slot>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-datagrid-table', {
            template: '#v-datagrid-table-template',

            computed: {
                visibleColumns() {
                    return this.$parent.available.columns.filter(c => c.visible !== false);
                },

                gridsCount() {
                    let count = this.visibleColumns.length;

                    if (this.$parent.available.actions.length) {
                        ++count;
                    }

                    if (this.$parent.available.massActions.length) {
                        ++count;
                    }

                    return count;
                },
            },

            methods: {
                selectRowLabel(record) {
                    const column = this.visibleColumns[0];
                    const text = new DOMParser().parseFromString(String(column ? record[column.index] ?? '' : ''), 'text/html').body.textContent.trim();

                    return @json(__('admin::app.components.datagrid.table.select-row')).replace(':name', text || record[this.$parent.available.meta.primary_column]);
                },

                isRowEditable(record) {
                    return (record.actions ?? []).some(action => action.index === 'edit');
                },

                handleRowClick(event, record) {
                    this.$parent.handleRowClick(event, record);
                },

                isLinkAction(action) {
                    return (action.method ?? '').toLowerCase() === 'get' && !! action.url;
                },

                {{-- A plain click keeps the in-app visit; a modified click on a link action is left to the browser, so it can open in a new tab. --}}
                runRowAction(event, action, record) {
                    if (this.isLinkAction(action)) {
                        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                            return;
                        }

                        event.preventDefault();
                    }

                    this.$parent.performAction(action, record);
                },

                stripHtml(value) {
                    if (value === null || value === undefined) {
                        return '';
                    }

                    return String(value).replace(/<[^>]*>/g, '').trim();
                },
            }
        });
    </script>
@endpushOnce
