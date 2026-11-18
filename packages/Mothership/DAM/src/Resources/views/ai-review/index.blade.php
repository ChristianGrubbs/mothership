{{--
    P5c (D8): AI tag review. Open AI suggestions on every file the reviewer may list and tag, grouped by term or by
    file, accepted or dismissed one at a time or in bulk through the asset page's own code path (AiTagReview).
    Keyboard: arrow keys (or j / k) move between suggestions, Space selects, A accepts, D dismisses.
--}}
<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.ai-review.title')
    </x-slot:title>

    <v-dam-ai-review :initial="@js($initial)"></v-dam-ai-review>

    @include('dam::components.explorer.folder-picker')

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-ai-review-template">
            <div data-dam-ai-review>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.ai-review.title')"
                    :description="trans('dam::app.admin.ai-review.description')"
                    :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                />

                <div class="mt-4 flex flex-wrap items-center gap-2" data-dam-ai-review-filters>
                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 dark:border-cherry-700" role="radiogroup" :aria-label="t.groupBy">
                        <button
                            v-for="option in groups"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="filters.group === option.value ? 'true' : 'false'"
                            class="px-3 py-1.5 text-sm"
                            :class="filters.group === option.value ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-cherry-900 dark:text-gray-200 dark:hover:bg-cherry-800'"
                            @click="setFilter({ group: option.value })"
                            :data-dam-ai-review-group="option.value"
                            v-text="option.label"
                        ></button>
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <span class="sr-only">@lang('dam::app.admin.ai-review.term')</span>
                        <select class="h-8 max-w-[14rem] rounded-md border border-gray-300 px-2 text-sm dark:border-cherry-700 dark:bg-cherry-800" :value="filters.term || ''" @change="setFilter({ term: $event.target.value || null })" data-dam-ai-review-term>
                            <option value="" v-text="t.allTerms"></option>
                            <option v-for="term in terms" :key="term.term" :value="term.term" v-text="t.termOption.replace(':label', term.label).replace(':count', term.count)"></option>
                        </select>
                    </label>

                    <span v-if="filters.folder_id" class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800 dark:bg-cherry-800 dark:text-primary-200">
                        <span class="truncate" v-text="t.inFolder.replace(':name', filters.folder_name)"></span>
                        <button type="button" class="ms-1 shrink-0 text-base leading-none" :aria-label="t.clearFilter" @click="setFilter({ folder_id: null, folder_name: null })">×</button>
                    </span>

                    <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" @click="pickerOpen = true">@lang('dam::app.admin.ai-review.choose-folder')</button>
                </div>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.ai-review.keyboard')</p>

                <div class="mt-3 rounded-lg bg-white box-shadow dark:bg-cherry-900">
                    <div v-if="items.length" class="sticky top-0 z-[1] flex flex-wrap items-center gap-3 border-b bg-white px-4 py-2.5 text-sm text-gray-700 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" class="h-4 w-4" :checked="allSelected" :indeterminate.prop="someSelected" @change="toggle(items, $event.target.checked)" data-dam-ai-review-select-all>
                            <span>@lang('dam::app.admin.ai-review.select-all')</span>
                        </label>
                        <span v-if="selected.length" class="text-gray-500 dark:text-gray-400" v-text="t.selected.replace(':count', selected.length)"></span>
                        <span v-if="selected.length" class="ms-auto flex flex-wrap gap-2">
                            <button type="button" class="primary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy" @click="bulk('accept', selectedItems)" data-dam-ai-review-accept-selected v-text="t.acceptSelected.replace(':count', selected.length)"></button>
                            <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy" @click="bulk('dismiss', selectedItems)" data-dam-ai-review-dismiss-selected v-text="t.dismissSelected.replace(':count', selected.length)"></button>
                        </span>
                    </div>

                    <p v-if="failed" class="px-4 py-8 text-center text-sm text-red-600 dark:text-red-400" role="alert">@lang('dam::app.admin.ai-review.load-failed')</p>
                    <p v-else-if="! items.length && (filters.term || filters.folder_id)" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-ai-review-empty>@lang('dam::app.admin.ai-review.empty-filtered')</p>
                    <p v-else-if="! items.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-ai-review-empty>@lang('dam::app.admin.ai-review.empty')</p>

                    <div v-else @keydown="onKey" data-dam-ai-review-list>
                        <section v-for="section in sections" :key="section.key" class="border-b border-gray-100 last:border-b-0 dark:border-cherry-800" data-dam-ai-review-section>
                            <header class="flex items-center gap-3 bg-gray-50 px-4 py-2 dark:bg-cherry-800/60">
                                <input type="checkbox" class="h-4 w-4 shrink-0" :checked="section.items.every(isSelected)" @change="toggle(section.items, $event.target.checked)" :aria-label="t.selectGroup.replace(':name', section.title)">
                                <img v-if="section.thumbnail" :src="section.thumbnail" alt="" class="h-10 w-10 shrink-0 rounded object-cover">
                                <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-white">
                                    <a v-if="section.url" :href="section.url" class="hover:underline" :title="section.title" v-text="section.title"></a>
                                    <span v-else v-text="section.title"></span>
                                </h2>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" v-text="countLabel(section.count)"></span>
                            </header>

                            <ul :class="filters.group === 'term' ? 'grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 xl:grid-cols-3' : 'flex flex-wrap gap-2 p-3'">
                                <li
                                    v-for="item in section.items"
                                    :key="keyOf(item)"
                                    :ref="el => setRef(item, el)"
                                    tabindex="0"
                                    class="flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2 outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                    :class="isSelected(item) ? 'border-primary-400 bg-primary-50 dark:border-primary-600 dark:bg-cherry-800' : 'border-gray-200 dark:border-cherry-700'"
                                    :aria-label="t.selectItem.replace(':term', item.label).replace(':name', item.asset_name)"
                                    @focus="cursor = keyOf(item)"
                                    data-dam-ai-review-item
                                    :data-asset-id="item.asset_id"
                                    :data-term="item.term"
                                >
                                    <input type="checkbox" class="h-4 w-4 shrink-0" tabindex="-1" :checked="isSelected(item)" @change="toggle([item], $event.target.checked)" :aria-label="t.selectItem.replace(':term', item.label).replace(':name', item.asset_name)">
                                    <template v-if="filters.group === 'term'">
                                        <img :src="item.thumbnail_url" alt="" loading="lazy" class="h-12 w-12 shrink-0 rounded object-cover">
                                        <a :href="item.url" class="min-w-0 flex-1 line-clamp-2 break-all text-sm text-gray-800 hover:underline dark:text-gray-100" tabindex="-1" :title="item.asset_name" v-text="item.asset_name"></a>
                                    </template>
                                    <span v-else class="min-w-0 flex-1 truncate text-sm font-medium text-gray-800 dark:text-gray-100" v-text="item.label"></span>
                                    <button type="button" class="shrink-0 rounded px-2 py-1 text-xs font-semibold text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-900/30" tabindex="-1" :disabled="busy" @click="bulk('accept', [item])" v-text="t.accept"></button>
                                    <button type="button" class="shrink-0 rounded px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-700" tabindex="-1" :disabled="busy" @click="bulk('dismiss', [item])" v-text="t.dismiss"></button>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <div v-if="meta.total > meta.per_page" class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-2.5 text-sm text-gray-600 dark:border-cherry-800 dark:text-gray-300">
                        <span v-text="shownLabel"></span>
                        <span class="flex gap-2">
                            <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy || meta.current_page <= 1" @click="load(meta.current_page - 1)">@lang('dam::app.admin.ai-review.previous')</button>
                            <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy || meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">@lang('dam::app.admin.ai-review.next')</button>
                        </span>
                    </div>
                </div>

                <v-dam-folder-picker :open="pickerOpen" tab-id="ai-review" :title="t.folderPickerTitle" @picked="pickFolder" @close="pickerOpen = false"></v-dam-folder-picker>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-ai-review', {
                template: '#v-dam-ai-review-template',

                props: {
                    initial: { type: Object, required: true },
                },

                data() {
                    return {
                        items: this.initial.data,
                        terms: this.initial.terms,
                        meta: this.initial.meta,
                        filters: { ...this.initial.filters },
                        selected: [],
                        cursor: null,
                        busy: false,
                        failed: false,
                        pickerOpen: false,
                        groups: [
                            { value: 'term', label: @js(trans('dam::app.admin.ai-review.group-term')) },
                            { value: 'asset', label: @js(trans('dam::app.admin.ai-review.group-asset')) },
                        ],
                        t: {
                            groupBy: @js(trans('dam::app.admin.ai-review.group-by')),
                            allTerms: @js(trans('dam::app.admin.ai-review.all-terms')),
                            termOption: @js(trans('dam::app.admin.ai-review.term-option')),
                            inFolder: @js(trans('dam::app.admin.ai-review.in-folder')),
                            clearFilter: @js(trans('dam::app.admin.ai-review.clear-filter')),
                            folderPickerTitle: @js(trans('dam::app.admin.ai-review.folder-picker-title')),
                            accept: @js(trans('dam::app.admin.ai-review.accept')),
                            dismiss: @js(trans('dam::app.admin.ai-review.dismiss')),
                            acceptSelected: @js(trans('dam::app.admin.ai-review.accept-selected')),
                            dismissSelected: @js(trans('dam::app.admin.ai-review.dismiss-selected')),
                            selected: @js(trans('dam::app.admin.ai-review.selected')),
                            selectGroup: @js(trans('dam::app.admin.ai-review.select-group')),
                            selectItem: @js(trans('dam::app.admin.ai-review.select-item')),
                            showing: @js(trans('dam::app.admin.ai-review.showing')),
                            failed: @js(trans('dam::app.admin.ai-review.failed')),
                            countOne: @js(trans_choice('dam::app.admin.ai-review.count', 1)),
                            countMany: @js(trans_choice('dam::app.admin.ai-review.count', 2, ['count' => ':count'])),
                            skippedOne: @js(trans_choice('dam::app.admin.ai-review.skipped', 1)),
                            skippedMany: @js(trans_choice('dam::app.admin.ai-review.skipped', 2, ['count' => ':count'])),
                        },
                    };
                },

                computed: {
                    /** The page's suggestions in sections: one per term (with its count over the whole filter) or one per file. */
                    sections() {
                        const sections = [];
                        const byKey = {};

                        this.items.forEach(item => {
                            const key = this.filters.group === 'term' ? 'term:' + item.term : 'asset:' + item.asset_id;
                            if (! byKey[key]) {
                                const term = this.terms.find(row => row.term === item.term);
                                byKey[key] = this.filters.group === 'term'
                                    ? { key, title: item.label, count: term ? term.count : 0, items: [] }
                                    : { key, title: item.asset_name, url: item.url, thumbnail: item.thumbnail_url, count: 0, items: [] };
                                sections.push(byKey[key]);
                            }
                            byKey[key].items.push(item);
                            if (this.filters.group !== 'term') byKey[key].count++;
                        });

                        return sections;
                    },

                    /** The suggestions in the order they are shown, for the arrow keys. */
                    ordered() {
                        return this.sections.flatMap(section => section.items);
                    },

                    selectedItems() {
                        return this.items.filter(item => this.isSelected(item));
                    },

                    allSelected() {
                        return this.items.length > 0 && this.selected.length === this.items.length;
                    },

                    someSelected() {
                        return this.selected.length > 0 && this.selected.length < this.items.length;
                    },

                    shownLabel() {
                        const from = (this.meta.current_page - 1) * this.meta.per_page + 1;

                        return this.t.showing.replace(':from', from).replace(':to', Math.min(this.meta.total, from + this.items.length - 1)).replace(':total', this.meta.total);
                    },
                },

                created() {
                    // Row elements by suggestion key, for the arrow keys; not reactive state.
                    this._rows = {};
                },

                methods: {
                    keyOf(item) {
                        return item.asset_id + '|' + item.term;
                    },

                    isSelected(item) {
                        return this.selected.includes(this.keyOf(item));
                    },

                    setRef(item, el) {
                        if (el) this._rows[this.keyOf(item)] = el;
                        else delete this._rows[this.keyOf(item)];
                    },

                    toggle(items, on) {
                        const keys = items.map(item => this.keyOf(item));
                        this.selected = on
                            ? [...new Set([...this.selected, ...keys])]
                            : this.selected.filter(key => ! keys.includes(key));
                    },

                    countLabel(count) {
                        return count === 1 ? this.t.countOne : this.t.countMany.replace(':count', count);
                    },

                    focusAt(index) {
                        const item = this.ordered[Math.max(0, Math.min(index, this.ordered.length - 1))];
                        if (item) this._rows[this.keyOf(item)]?.focus();
                    },

                    onKey(event) {
                        // Only a focused suggestion row takes the shortcuts; its buttons and links keep their own keys.
                        if (event.target.tagName !== 'LI') return;
                        if (event.altKey || event.ctrlKey || event.metaKey) return;

                        const index = this.ordered.findIndex(item => this.keyOf(item) === this.cursor);
                        const item = this.ordered[index];
                        const key = event.key.toLowerCase();

                        if (key === 'arrowdown' || key === 'j') {
                            event.preventDefault();
                            this.focusAt(index + 1);
                        } else if (key === 'arrowup' || key === 'k') {
                            event.preventDefault();
                            this.focusAt(index - 1);
                        } else if (key === ' ' && item) {
                            event.preventDefault();
                            this.toggle([item], ! this.isSelected(item));
                        } else if ((key === 'a' || key === 'd') && item && ! this.busy) {
                            event.preventDefault();
                            this.bulk(key === 'a' ? 'accept' : 'dismiss', [item], index);
                        }
                    },

                    params(page) {
                        const params = { page, group: this.filters.group };
                        if (this.filters.term) params.term = this.filters.term;
                        if (this.filters.folder_id) params.folder = this.filters.folder_id;

                        return params;
                    },

                    async load(page = 1) {
                        const params = this.params(page);

                        try {
                            const { data } = await this.$axios.get(@js(route('admin.dam.ai-review.index')), { params });
                            this.items = data.data;
                            this.terms = data.terms;
                            this.meta = data.meta;
                            this.failed = false;
                            this.selected = this.selected.filter(key => this.items.some(item => this.keyOf(item) === key));
                            window.history.replaceState(null, '', @js(route('admin.dam.ai-review.index')) + '?' + new URLSearchParams(params).toString());
                        } catch (e) {
                            this.failed = true;
                        }
                    },

                    setFilter(patch) {
                        this.filters = { ...this.filters, ...patch };
                        this.selected = [];
                        this.load(1);
                    },

                    pickFolder({ id, name } = {}) {
                        this.pickerOpen = false;
                        if (id) this.setFilter({ folder_id: Number(id), folder_name: name });
                    },

                    async bulk(action, items, focusIndex = null) {
                        if (! items.length || this.busy) return;
                        this.busy = true;

                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.ai-review.bulk')), {
                                action,
                                items: items.map(item => ({ asset_id: item.asset_id, term: item.term })),
                            });
                            const skipped = items.length - data.done;
                            this.$emitter.emit('add-flash', { type: data.done ? 'success' : 'warning', message: data.message });
                            if (skipped > 0) {
                                this.$emitter.emit('add-flash', { type: 'warning', message: skipped === 1 ? this.t.skippedOne : this.t.skippedMany.replace(':count', skipped) });
                            }
                            const keys = items.map(item => this.keyOf(item));
                            this.selected = this.selected.filter(key => ! keys.includes(key));
                            await this.load(this.meta.current_page);
                            if (focusIndex !== null) this.$nextTick(() => this.focusAt(focusIndex));
                        } catch (e) {
                            this.$emitter.emit('add-flash', { type: 'error', message: e.response?.data?.message || this.t.failed });
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
