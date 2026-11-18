{{-- Shimmer only until the first load lands. A reload (per page, page, sort, filter) keeps the toolbar
     mounted: swapping it for the shimmer replaced every control, so the focused one (the per-page toggle,
     a pagination button) was destroyed and keyboard focus fell to <body>. --}}
<template v-if="isLoading && ! hasLoaded">
    <x-admin::shimmer.datagrid.toolbar />
</template>

<template v-else>
    <div class="mt-7 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div class="flex min-w-0 gap-x-1 max-md:w-full">
            <div class="flex w-full flex-wrap items-center gap-x-1 gap-y-2">
                <div class="flex max-w-[445px] items-center max-sm:w-full max-sm:max-w-full">
                    <div class="relative w-full">
                        <input
                            type="text"
                            name="search"
                            :value="searchDraft ?? getAppliedColumnValues('all')"
                            class="block w-full rounded-lg border dark:border-gray-500 bg-white dark:bg-cherry-900 py-1.5 ltr:pl-3 rtl:pr-3 ltr:pr-10 rtl:pl-10 leading-6 text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 focus:border-gray-400  dark:focus:border-gray-400"
                            :placeholder="available.searchPlaceholder"
                            autocomplete="off"
                            @input="debouncedFilterPage"
                        >

                        <div class="icon-search pointer-events-none absolute ltr:right-2.5 rtl:left-2.5 top-2 flex items-center text-2xl">
                        </div>
                    </div>
                </div>

                <div class="ltr:pl-2.5 rtl:pr-2.5">
                    {{-- "singular|plural" (trans_choice form); locales without a pipe keep one string for every count. --}}
                    <p class="text-sm font-light text-gray-800 dark:text-white whitespace-nowrap">
                        @{{ (forms => forms[available.meta.total === 1 && forms.length > 1 ? 0 : forms.length - 1])(@json(trans('admin::app.components.datagrid.toolbar.results')).split('|')).replace(':total', available.meta.total) }}
                    </p>
                </div>

                <div
                    class="ltr:pl-2.5 rtl:pr-2.5"
                    v-if="applied.massActions.indices.length"
                >
                    <p class="text-sm font-light text-gray-800 dark:text-white">

                        @{{ "@lang('admin::app.components.datagrid.toolbar.length-of')".replace(':length', applied.massActions.indices.length) }}

                        @{{ "@lang('admin::app.components.datagrid.toolbar.selected')".replace(':total', available.meta.total) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <x-admin::drawer width="350px" ref="filterDrawer">
                <x-slot:toggle>
                    <div>
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

                        <div class="z-10 hidden w-full divide-y divide-gray-100 rounded bg-white dark:bg-cherry-800 shadow">
                        </div>
                    </div>
                </x-slot>

                <x-slot:header>
                    <div class="flex justify-between items-center p-3">
                        <p class="text-base text-gray-800 dark:text-white font-semibold">
                            @lang('admin::app.components.datagrid.filters.title')
                        </p>
                    </div>
                </x-slot>

                <x-slot:content class="!p-5">
                    <x-dam::datagrid.filters />
                </x-slot>

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
                </x-slot>
            </x-admin::drawer>

            <div class="flex items-center gap-x-2">
                <x-admin::dropdown>
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="inline-flex w-full max-w-max cursor-pointer appearance-none items-center justify-between gap-x-2 rounded-md border dark:border-gray-500 bg-white dark:bg-cherry-900 px-2.5 py-1.5 text-center leading-6 text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:border-gray-400 dark:hover:border-gray-400 focus:border-gray-400 dark:focus:border-gray-400"
                        >
                            <span v-text="applied.pagination.perPage"></span>

                            <span class="icon-chevron-down text-2xl"></span>
                        </button>
                    </x-slot>

                    <x-slot:menu>
                        <x-admin::dropdown.menu.item
                            v-for="perPageOption in available.meta.per_page_options"
                            v-text="perPageOption"
                            @click="changePerPageOption(perPageOption)"
                        >
                        </x-admin::dropdown.menu.item>
                    </x-slot>
                </x-admin::dropdown>

                <p class="whitespace-nowrap text-gray-600 dark:text-gray-300 max-sm:hidden">
                    @lang('admin::app.components.datagrid.toolbar.per-page')
                </p>

                <input
                    type="text"
                    class="inline-flex min-h-[38px] max-w-[60px] appearance-none items-center justify-center gap-x-1 rounded-md border dark:border-gray-500 bg-white dark:bg-cherry-900 px-3 py-1.5 text-center leading-6 text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:border-gray-400 dark:hover:border-gray-400 focus:outline-none focus:border-gray-400 dark:focus:border-gray-400 max-sm:hidden"
                    :value="pageDraft ?? available.meta.current_page"
                    @input="pageDraft = $event.target.value"
                    @change="pageDraft = null; changePage(parseInt($event.target.value))"
                    @blur="pageDraft = null"
                >

                <div class="whitespace-nowrap text-gray-600 dark:text-gray-300">
                    <span> @lang('admin::app.components.datagrid.toolbar.of') </span>

                    <span v-text="available.meta.last_page"></span>
                </div>

                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex w-full max-w-max cursor-pointer appearance-none items-center justify-between gap-x-1 rounded-md border border-transparent p-1.5 text-center text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:bg-primary-100 dark:hover:bg-gray-800 active:border-gray-300"
                        title="@lang('admin::app.components.datagrid.toolbar.pagination.previous-page')"
                        aria-label="@lang('admin::app.components.datagrid.toolbar.pagination.previous-page')"
                        @click="changePage('previous')"
                    >
                        <span class="icon-chevron-left text-2xl" aria-hidden="true"></span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex w-full max-w-max cursor-pointer appearance-none items-center justify-between gap-x-1 rounded-md border border-transparent p-1.5 text-center text-gray-600 dark:text-gray-300 transition-all marker:shadow hover:bg-primary-100 dark:hover:bg-gray-800 active:border-gray-300"
                        title="@lang('admin::app.components.datagrid.toolbar.pagination.next-page')"
                        aria-label="@lang('admin::app.components.datagrid.toolbar.pagination.next-page')"
                        @click="changePage('next')"
                    >
                        <span class="icon-chevron-right text-2xl" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
