@props(['isMultiRow' => false])

<v-history-table>
    {{ $slot }}
</v-history-table>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-history-table-template"
    >
        <div class="w-full">
            <div class="table-responsive grid w-full box-shadow rounded bg-white dark:bg-cherry-900 overflow-hidden">
                <slot name="header">
                    <template v-if="$parent.isLoading">
                        <x-admin::shimmer.datagrid.table.head :isMultiRow="$isMultiRow" />
                    </template>

                    <template v-else>
                        <div
                            class="row grid gap-2.5 min-h-[47px] px-4 py-2.5 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-cherry-800 font-semibold items-center"
                            :style="`grid-template-columns: repeat(${gridsCount}, minmax(0, 1fr))`"
                        >
                            <!-- Mass Actions -->
                            <p v-if="$parent.available.massActions.length">
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
                            </p>

                            <!-- Columns -->
                            <p
                                v-for="column in $parent.available.columns"
                                :key="column.index"
                                class="flex gap-1.5 items-center break-words"
                                :class="{'cursor-pointer select-none hover:text-gray-800 dark:hover:text-white': column.sortable}"
                                @click="$parent.sortPage(column)"
                            >
                                @{{ column.label }}

                                <i
                                    class="text-base  text-gray-600 dark:text-gray-300 align-text-bottom"
                                    :class="[$parent.applied.sort.order === 'asc' ? 'icon-down-stat': 'icon-up-stat']"
                                    v-if="column.index == $parent.applied.sort.column"
                                ></i>
                            </p>

                            <!-- Actions -->

                            <div
                                class="flex gap-2.5 items-center justify-end select-none"
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
                                class="row grid gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-primary-50 dark:hover:bg-cherry-800"
                                v-for="record in $parent.available.records"
                                :key="record[$parent.available.meta.primary_column]"
                                :style="`grid-template-columns: repeat(${gridsCount}, minmax(0, 1fr))`"
                            >
                                <!-- Mass Actions -->
                                <p v-if="$parent.available.massActions.length">
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
                                <p
                                    class="break-words"
                                    v-for="column in $parent.available.columns"
                                    :key="column.index"
                                    v-html="record[column.index]"
                                >
                                </p>

                                <!-- Actions -->

                                <div
                                    class="flex gap-2.5 items-center justify-end select-none"
                                >
                                    <p
                                        class="text-gray-600 dark:text-gray-300"
                                        v-if="$parent.available.actions.length"
                                    >
                                        {{--
                                            Each action is a real control, so the keyboard and screen readers reach it: a link
                                            when it navigates, a button otherwise (a version opens in a modal). The negative
                                            vertical margin keeps the row height the old icon span had, whose padding sat
                                            outside the line box.
                                        --}}
                                        <component
                                            :is="isLinkAction(action) ? 'a' : 'button'"
                                            class="cursor-pointer rounded-md p-1.5 -my-1.5 text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                            :class="action.icon"
                                            v-text="!action.icon ? action.title : ''"
                                            v-for="action in record.actions"
                                            :key="action.index"
                                            :type="isLinkAction(action) ? null : 'button'"
                                            :href="isLinkAction(action) ? action.url : null"
                                            :title="action.title ?? ''"
                                            :aria-label="action.title ?? ''"
                                            :data-row-action="action.index"
                                            @click="runRowAction($event, action)"
                                        >
                                        </component>
                                    </p>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="row grid px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 text-center">
                                <p>
                                    @lang('admin::app.components.datagrid.table.no-records-available')
                                </p>
                            </div>
                        </template>
                    </template>
                </slot>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-history-table', {
            template: '#v-history-table-template',

            computed: {
                gridsCount() {
                    let count = this.$parent.available.columns.length;

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
                    const column = this.$parent.available.columns[0];
                    const text = new DOMParser().parseFromString(String(column ? record[column.index] ?? '' : ''), 'text/html').body.textContent.trim();

                    return @json(__('admin::app.components.datagrid.table.select-row')).replace(':name', text || record[this.$parent.available.meta.primary_column]);
                },

                isLinkAction(action) {
                    return (action.method ?? '').toLowerCase() === 'get' && !! action.url && action.frontend_view !== 'view-modal';
                },

                {{-- A plain click keeps the in-app handling; a modified click on a link action is left to the browser, so it can open in a new tab. --}}
                runRowAction(event, action) {
                    if (this.isLinkAction(action)) {
                        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                            return;
                        }

                        event.preventDefault();
                    }

                    this.$parent.performAction(action);
                },
            },
        });
    </script>
@endpushOnce
