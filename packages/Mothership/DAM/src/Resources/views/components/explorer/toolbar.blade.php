
@once('v-dam-explorer-toolbar')
        {{-- Rendered from the first paint, not swapped in for a fixed-height shimmer when the first page arrives:
             this bar wraps to one, two or three rows with the panel width, and the swap moved the file list by the
             difference (live CLS 0.015 on /admin/dam, 2026-10-27). The pager keeps its place while it waits. --}}
        <div class="relative flex flex-wrap items-center justify-between gap-x-4 gap-y-2">

            {{-- VIS-11: with files selected, the bulk-action bar is pinned to the bottom of a phone screen. It cannot be position: sticky: the tab's overflow-hidden ancestors would hold it in place. --}}
            <div
                class="flex flex-1 min-w-[120px] min-[640px]:min-w-[300px] items-center gap-x-1"
                :class="{ 'max-[639px]:fixed max-[639px]:inset-x-0 max-[639px]:bottom-0 max-[639px]:z-[60] max-[639px]:flex-wrap max-[639px]:gap-y-2 max-[639px]:border-t max-[639px]:border-gray-200 dark:max-[639px]:border-cherry-700 max-[639px]:bg-white dark:max-[639px]:bg-cherry-900 max-[639px]:px-4 max-[639px]:pt-3 max-[639px]:pb-[calc(0.75rem+env(safe-area-inset-bottom))] max-[639px]:shadow-[0_-4px_12px_rgba(0,0,0,0.12)]': selection.ids.length > 0 }"
                :style="selectionBarBottom ? { bottom: selectionBarBottom + 'px', paddingBottom: '0.75rem' } : null"
                data-explorer-selection-bar
                ref="selectionBar"
            >
                <label v-if="dirs.length || assets.length" class="flex items-center cursor-pointer shrink-0 mr-2" data-select-all>
                    <input
                        type="checkbox"
                        class="peer sr-only"
                        aria-label="@lang('admin::app.components.datagrid.table.select-all')"
                        :checked="selection.mode === 'all'"
                        :indeterminate="selection.mode === 'partial'"
                        @change="toggleSelectAll(); $event.target.checked = selection.mode === 'all'"
                    >
                    <span
                        class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl"
                        :class="{
                            'peer-checked:icon-checkbox-check peer-checked:text-primary-700': selection.mode === 'all',
                            'peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700': selection.mode === 'partial',
                        }"
                    ></span>
                </label>

                <template v-if="selection.ids.length > 0">
                    @if (bouncer()->hasPermission('dam.asset.edit'))
                    {{-- D5: the one-step way to stack what is selected, shown as soon as two or more files are. --}}
                    <button
                        v-if="selection.ids.filter(i => i.type === 'asset').length > 1"
                        type="button"
                        class="mr-2 inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                        data-dam-create-stack
                        @click="openMergeDialog"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                        @{{ createStackLabel }}
                    </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.asset.mass_delete') || bouncer()->hasPermission('dam.asset.update') || bouncer()->hasPermission('dam.asset.edit') || bouncer()->hasPermission('dam.asset.tag') || bouncer()->hasPermission('dam.asset.process') || bouncer()->hasPermission('dam.collections.update'))
                    {{-- Teleported: it flips above the toggle and stays inside the viewport, which the phone's bottom-pinned bar needs. --}}
                    <x-admin::dropdown position="bottom-left" :teleport="true">
                        <x-slot:toggle>
                            <button
                                type="button"
                                class="flex items-center gap-x-1.5 rounded-md border border-primary-300 bg-primary-50 dark:bg-primary-900/30 dark:border-primary-700 px-3 py-1.5 text-sm font-medium text-primary-700 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition"
                            >
                                @lang('dam::app.admin.explorer.mass-actions.select-action')
                                <span class="icon-chevron-down text-2xl"></span>
                            </button>
                        </x-slot:toggle>

                        <x-slot:menu class="shadow-md !p-0 z-10">
                            @if (bouncer()->hasPermission('dam.asset.mass_delete'))
                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                @click="openFolderPicker('move')"
                            >
                                @lang('dam::app.admin.explorer.mass-actions.move-to')
                            </li>

                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                @click="openFolderPicker('copy')"
                            >
                                @lang('dam::app.admin.explorer.mass-actions.copy-to')
                            </li>
                            @endif

                            @if (bouncer()->hasPermission('dam.asset.tag'))
                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                @click="openAssignTagsModal"
                            >
                                @lang('dam::app.admin.dam.tag.mass-action.assign-tags')
                            </li>
                            @endif

                            @if (bouncer()->hasPermission('dam.asset.edit'))
                            {{-- P3b-1: merge two or more selected files into one of them, as a stack. --}}
                            <li
                                v-if="selection.ids.filter(i => i.type === 'asset').length > 1"
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                data-dam-merge-action
                                @click="openMergeDialog"
                            >
                                @{{ addToStackLabel }}
                            </li>
                            @endif

                            {{-- Rename with AI: date_event_place names for the selected files, reviewed before they apply. --}}
                            @if (bouncer()->hasPermission('dam.asset.rename') && filled(config('ai-index.api')))
                            <li
                                v-if="selectionMayUseAi()"
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                data-dam-ai-rename-open
                                @click="openAiRenameModal"
                            >
                                @lang('dam::app.admin.explorer.ai-rename.action')
                            </li>
                            @endif

                            {{-- P4a (D9): set typed field values on every selected file. --}}
                            @if (bouncer()->hasPermission('dam.asset.update'))
                            <li
                                v-if="fieldDefinitions.length"
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                data-dam-field-bulk-open
                                @click="openFieldBulkModal"
                            >
                                @lang('dam::app.admin.metadata-fields.bulk.action')
                            </li>
                            @endif

                            @if (bouncer()->hasPermission('dam.asset.process'))
                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                data-dam-process
                                @click="openProcessModal"
                            >
                                @lang('dam::app.admin.batch.process')
                            </li>
                            @endif

                            @if (bouncer()->hasPermission('dam.collections.update'))
                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                data-explorer-add-to-collection
                                @click="openAddToCollection"
                            >
                                @lang('dam::app.admin.dam.collections.add.action')
                            </li>
                            @endif

                            @if (bouncer()->hasPermission('dam.asset.mass_delete'))
                            <li
                                class="px-4 py-2 hover:bg-gray-100 dark:hover:bg-cherry-800 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
                                @click="performMassDelete"
                            >
                                @lang('dam::app.admin.explorer.mass-actions.delete')
                            </li>
                            @endif
                        </x-slot:menu>
                    </x-admin::dropdown>
                    @endif

                    {{-- P4 organization -- begin. --}}
                    <button
                        v-if="selection.ids.some((item) => item.type === 'asset')"
                        type="button"
                        class="flex items-center gap-x-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200 dark:hover:bg-cherry-800 transition ml-1"
                        data-organize-add-to-board
                        @click="openOrganizePicker('boards')"
                    >@lang('dam::app.admin.organize.actions.add-to-board')</button>
                    {{-- P4 organization -- end. --}}

                    <span class="text-sm text-gray-500 dark:text-gray-400 ml-2">
                        @{{ "@lang('dam::app.admin.explorer.mass-actions.select-count')".replace(':count', selection.ids.length) }}
                    </span>
                </template>

                <template v-else>
                    <div class="flex w-full max-w-[445px] min-w-0 items-center max-sm:max-w-full">
                        <div class="relative w-full min-w-0">
                            <input
                                type="text"
                                class="block w-full rounded-lg border dark:border-gray-500 bg-white dark:bg-cherry-900 py-1.5 ltr:pl-3 rtl:pr-3 ltr:pr-10 rtl:pl-10 leading-6 text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                                :placeholder="'@lang('dam::app.admin.explorer.search.placeholder')'"
                                aria-label="Search assets in this scope"
                                maxlength="255"
                                v-model="searchInput"
                                @input="onSearch"
                                @keydown.enter.prevent="submitSearch"
                                @keydown.esc.prevent="clearSearch"
                                autocomplete="off"
                            />
                            <button
                                v-if="searchInput"
                                type="button" aria-label="Clear asset search"
                                @click="clearSearch"
                                class="absolute ltr:right-2.5 rtl:left-2.5 top-2 text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200 text-base leading-none"
                            >×</button>
                            <div v-else class="icon-search pointer-events-none absolute ltr:right-2.5 rtl:left-2.5 top-2 flex items-center text-2xl"></div>
                            <p v-if="searchInput.length >= 255" class="absolute top-full mt-0.5 text-xs text-amber-700 dark:text-amber-300" role="status">@lang('dam::app.admin.explorer.no-files.search-limit')</p>
                        </div>
                    </div>

                    <div class="ltr:pl-2.5 rtl:pr-2.5 shrink-0" v-if="meta">
                        <p class="whitespace-nowrap text-sm font-light text-gray-800 dark:text-white">
                            @{{ (meta.total_assets === 1 ? @js(trans_choice('dam::app.admin.explorer.sections.result-count', 1)) : @js(trans_choice('dam::app.admin.explorer.sections.result-count', 2))).replace(':total', meta.total_assets) }}
                        </p>
                    </div>
                </template>
            </div>

            {{-- VIS-11: below 640px the media chips and the sort/filter row open from this one button. --}}
            <button
                type="button"
                class="min-[640px]:hidden flex w-full min-h-11 items-center justify-between gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200"
                :aria-expanded="phoneFiltersOpen ? 'true' : 'false'"
                aria-controls="explorer-phone-media-types"
                data-explorer-phone-filters
                @click="phoneFiltersOpen = ! phoneFiltersOpen"
            >
                <span class="flex items-center gap-1.5">
                    <span class="icon-filter text-2xl" aria-hidden="true"></span>
                    @lang('dam::app.admin.explorer.filter-and-sort')
                    <span
                        v-if="hasAppliedFilters() || mediaType !== 'all'"
                        class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-100 px-1.5 text-xs font-medium text-primary-700 dark:bg-cherry-800 dark:text-primary-400"
                        v-text="appliedFilterCount() + (mediaType !== 'all' ? 1 : 0)"
                    ></span>
                </span>
                <span class="text-xl" :class="phoneFiltersOpen ? 'icon-chevron-up' : 'icon-chevron-down'" aria-hidden="true"></span>
            </button>

            <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 min-w-0 max-[639px]:w-full max-[639px]:flex-nowrap max-[639px]:justify-between max-[639px]:gap-x-2" :class="{ 'max-[639px]:hidden': ! phoneFiltersOpen }">
                <!-- "Similar to" results are ordered by similarity; the backend ignores the sort there. -->
                <label v-show="! similarTo" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 max-[639px]:min-w-0 max-[639px]:flex-1">
                    <span class="whitespace-nowrap max-[639px]:sr-only">Sort by</span>
                    <select
                        data-explorer-sort
                        class="max-[639px]:w-full max-[639px]:min-w-0 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-700 dark:border-gray-500 dark:bg-cherry-900 dark:text-gray-200"
                        :title="sortBy === 'popularity' ? organizeT.sort?.['popularity-hint'] : undefined"
                        :value="`${sortBy}:${sortOrder}`"
                        @change="onSortSelection($event.target.value)"
                    >
                        <option v-for="option in sortOptions" :key="option.value" :value="option.value" v-text="option.label"></option>
                    </select>
                </label>

                {{-- P4 organization: save the current query (never its results). --}}
                <button
                    v-show="! similarTo"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200 dark:hover:bg-cherry-800 max-[639px]:px-2"
                    title="@lang('dam::app.admin.organize.actions.save-search')"
                    data-organize-save-search
                    @click="openSaveSearch"
                >
                    <svg class="shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg>
                    <span class="max-[639px]:sr-only">@lang('dam::app.admin.organize.actions.save-search')</span>
                </button>

                <x-admin::drawer width="350px" ref="explorerFilterDrawer">
                    <x-slot:toggle>
                        <div class="shrink-0">
                            <div
                                class="relative inline-flex w-full max-w-max ltr:pl-3 rtl:pr-3 ltr:pr-5 rtl:pl-5 cursor-pointer select-none appearance-none items-center justify-between gap-x-1 rounded-md border dark:border-gray-500 bg-white dark:bg-cherry-900 px-1 py-1.5 text-center text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:border-gray-400 dark:hover:border-gray-400 focus:outline-none focus:ring-2"
                                :class="{'[&>*]:text-primary-700 [&>*]:dark:text-white': hasAppliedFilters()}"
                            >
                                <span class="icon-filter text-2xl"></span>

                                <span>
                                    @lang('admin::app.components.datagrid.toolbar.filter.title')
                                </span>

                                <span
                                    class="ltr:ml-0.5 rtl:mr-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-100 px-1.5 text-xs font-medium text-primary-700 dark:bg-cherry-800 dark:text-primary-400"
                                    data-applied-filter-count
                                    v-if="hasAppliedFilters()"
                                    v-text="appliedFilterCount()"
                                ></span>
                            </div>
                        </div>
                    </x-slot:toggle>

                    <x-slot:header>
                        <div class="flex justify-between items-center p-3">
                            <p class="text-base font-semibold dark:text-white text-gray-800">
                                @lang('admin::app.components.datagrid.filters.title')
                            </p>
                        </div>
                    </x-slot:header>

                    <x-slot:content class="!p-5">
                        <x-dam::datagrid.filters />
                    </x-slot:content>

                    <x-slot:footer class="mt-auto border-t border-gray-100 bg-white p-5 dark:border-cherry-800 dark:bg-cherry-800">
                        <div class="flex flex-col gap-y-1">
                            <button
                                type="button"
                                class="primary-button w-full justify-center text-center"
                                @click="runFilters()"
                            >
                                @lang('admin::app.components.datagrid.filters.save')
                            </button>

                            <button
                                type="button"
                                class="transparent-button justify-center self-center text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                data-clear-all-filters
                                :disabled="! hasAppliedFilters()"
                                @click="clearAllFilters()"
                            >
                                @lang('admin::app.components.datagrid.filters.custom-filters.clear-all')
                            </button>
                        </div>
                    </x-slot:footer>
                </x-admin::drawer>

                <v-dam-explorer-pager
                    class="max-[639px]:hidden"
                    :class="{ invisible: ! meta }"
                    :aria-hidden="meta ? undefined : 'true'"
                    :current-page="meta?.current_page ?? 1"
                    :last-page="meta?.last_page ?? 1"
                    :per-page="perPage"
                    @page-change="onPage"
                    @per-page-change="onPerPage"
                ></v-dam-explorer-pager>
            </div>
        </div>

        <p v-if="visibleFilterConflict()" class="text-sm text-gray-700 dark:text-gray-200" role="status" data-filter-conflict v-text="visibleFilterConflict().message"></p>

        <div v-if="activeFilterChips().length" class="flex min-w-0 flex-wrap gap-2" data-active-filters aria-label="@lang('dam::app.admin.explorer.filter-recovery.active-filters')">
            <button
                v-for="(chip, chipIndex) in activeFilterChips()"
                :key="chip.index + ':' + chipIndex"
                type="button"
                class="flex min-h-11 max-w-full items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200 dark:hover:bg-cherry-800"
                :aria-label="@js(trans('dam::app.admin.explorer.filter-recovery.remove')).replace(':filter', () => chip.label)"
                data-remove-active-filter
                @click="removeActiveFilter(chip)"
            >
                <span class="min-w-0 break-words" v-text="chip.label"></span>
                <span class="icon-cancel shrink-0 text-lg" aria-hidden="true"></span>
            </button>
        </div>

@endonce
