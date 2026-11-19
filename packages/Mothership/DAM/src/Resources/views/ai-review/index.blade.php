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
            <div data-dam-ai-review data-fold-list data-fold-split>
                <x-admin::layouts.page-header
                    group="sorting"
                    :title="trans('dam::app.admin.ai-review.title')"
                    :description="trans('dam::app.admin.ai-review.description')"
                />

                <div class="bridge-list-filters" data-dam-ai-review-filters data-fold-lead>
                    <span class="bridge-list-label" aria-hidden="true">@lang('dam::app.admin.ai-review.group-by')</span>

                    <div class="bridge-switch" role="radiogroup" :aria-label="t.groupBy">
                        <button
                            v-for="option in groups"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="filters.group === option.value ? 'true' : 'false'"
                            class="bridge-switch-opt"
                            v-on:click="setFilter({ group: option.value })"
                            :data-dam-ai-review-group="option.value"
                            v-text="option.label"
                        ></button>
                    </div>

                    <label>
                        <span class="sr-only">@lang('dam::app.admin.ai-review.term')</span>
                        <select class="bridge-list-select" :value="filters.term || ''" v-on:change="setFilter({ term: $event.target.value || null })" data-dam-ai-review-term>
                            <option value="" v-text="t.allTerms"></option>
                            <option v-for="term in terms" :key="term.term" :value="term.term" v-text="t.termOption.replace(':label', term.label).replace(':count', term.count)"></option>
                        </select>
                    </label>

                    <span v-if="filters.folder_id" class="bridge-list-chip" data-dam-ai-review-folder>
                        <span v-text="t.inFolder.replace(':name', filters.folder_name)"></span>
                        <button type="button" class="bridge-list-chip-clear" :aria-label="t.clearFilter" v-on:click="setFilter({ folder_id: null, folder_name: null })">×</button>
                    </span>

                    <button type="button" class="secondary-button on-ground" v-on:click="pickerOpen = true">@lang('dam::app.admin.ai-review.choose-folder')</button>
                </div>

                <p class="bridge-list-hint">@lang('dam::app.admin.ai-review.keyboard')</p>

                {{-- The list frame shared with the other Sorting and Away from ship screens (app.css, "Lists"). --}}
                <div class="bridge-list">
                    {{-- The selection strip: with something picked, Accept is the one filled control on the screen. --}}
                    <div v-if="items.length && ! failed" class="bridge-list-bar">
                        <label class="bridge-list-pick cursor-pointer">
                            <span class="bridge-list-check">
                                <input type="checkbox" class="peer sr-only" :checked="allSelected" :indeterminate.prop="someSelected" v-on:change="toggle(items, $event.target.checked)" data-dam-ai-review-select-all>
                                <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700"></span>
                            </span>
                            <span>@lang('dam::app.admin.ai-review.select-all')</span>
                        </label>

                        <span role="status" data-dam-ai-review-selected v-text="selected.length ? t.selected.replace(':count', selected.length) : ''"></span>

                        <button v-if="selected.length" type="button" class="bridge-list-clear" v-on:click="selected = []" data-dam-ai-review-clear>
                            @lang('dam::app.admin.ai-review.clear-selection')
                        </button>

                        <span v-if="selected.length" class="bridge-list-bar-actions">
                            <button type="button" class="primary-button" :disabled="busy" v-on:click="bulk('accept', selectedItems)" data-dam-ai-review-accept-selected v-text="t.acceptSelected.replace(':count', selected.length)"></button>
                            <button type="button" class="secondary-button" :disabled="busy" v-on:click="bulk('dismiss', selectedItems)" data-dam-ai-review-dismiss-selected v-text="t.dismissSelected.replace(':count', selected.length)"></button>
                        </span>
                    </div>

                    {{-- A list that did not load says so, with a way to try again, and never reads as nothing to review. --}}
                    <v-load-error
                        v-if="failed"
                        title="@lang('dam::app.admin.ai-review.load-failed')"
                        :message="failed.message"
                        :retryable="failed.retryable"
                        v-on:retry="load(failed.page)"
                    ></v-load-error>

                    <template v-else-if="! items.length && (filters.term || filters.folder_id)">
                        <x-admin::list.empty-state :framed="false" icon="icon-dam-tags" :title="trans('dam::app.admin.ai-review.empty-filtered')" :description="trans('dam::app.admin.ai-review.empty-filtered-text')" data-dam-ai-review-empty="filtered">
                            <button type="button" class="secondary-button" v-on:click="setFilter({ term: null, folder_id: null, folder_name: null })" data-dam-ai-review-clear-filters>
                                @lang('dam::app.admin.ai-review.clear-filters')
                            </button>
                        </x-admin::list.empty-state>
                    </template>

                    <template v-else-if="! items.length">
                        <x-admin::list.empty-state :framed="false" icon="icon-dam-tags" :title="trans('dam::app.admin.ai-review.empty')" :description="trans('dam::app.admin.ai-review.empty-text')" data-dam-ai-review-empty="1" />
                    </template>

                    <div v-else v-on:keydown="onKey" data-dam-ai-review-list>
                        <section v-for="section in sections" :key="section.key" class="bridge-review-section" data-dam-ai-review-section>
                            <header class="bridge-review-head">
                                <label class="bridge-list-check">
                                    <input type="checkbox" class="peer sr-only" :checked="section.items.every(isSelected)" v-on:change="toggle(section.items, $event.target.checked)" :aria-label="t.selectGroup.replace(':name', section.title)">
                                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700"></span>
                                </label>
                                <img v-if="section.thumbnail" :src="section.thumbnail" alt="" class="bridge-list-thumb">
                                <h2 class="bridge-list-name">
                                    <a v-if="section.url" :href="section.url" class="hover:underline" :title="section.title" v-text="section.title"></a>
                                    <span v-else v-text="section.title"></span>
                                </h2>
                                <span class="bridge-list-note" v-text="countLabel(section.count)"></span>
                            </header>

                            <ul class="bridge-review-items" :class="{ 'is-files': filters.group === 'term' }">
                                <li
                                    v-for="item in section.items"
                                    :key="keyOf(item)"
                                    :ref="el => setRef(item, el)"
                                    tabindex="0"
                                    class="bridge-review-item"
                                    :class="{ 'is-picked': isSelected(item) }"
                                    :aria-label="t.selectItem.replace(':term', item.label).replace(':name', item.asset_name)"
                                    v-on:focus="cursor = keyOf(item)"
                                    data-dam-ai-review-item
                                    :data-asset-id="item.asset_id"
                                    :data-term="item.term"
                                >
                                    <label class="bridge-list-check">
                                        <input type="checkbox" class="peer sr-only" tabindex="-1" :checked="isSelected(item)" v-on:change="toggle([item], $event.target.checked)" :aria-label="t.selectItem.replace(':term', item.label).replace(':name', item.asset_name)">
                                        <span class="icon-checkbox-normal rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700"></span>
                                    </label>
                                    <template v-if="filters.group === 'term'">
                                        <img :src="item.thumbnail_url" alt="" loading="lazy" class="bridge-list-thumb">
                                        <a :href="item.url" class="bridge-review-name" tabindex="-1" :title="item.asset_name" v-text="item.asset_name"></a>
                                    </template>
                                    <span v-else class="bridge-review-name" v-text="item.label"></span>
                                    {{-- Neither is filled: a grid of filled buttons has no single next step. --}}
                                    <span class="bridge-review-acts">
                                        <button type="button" class="secondary-button" tabindex="-1" :disabled="busy" v-on:click="bulk('accept', [item])" v-text="t.accept"></button>
                                        <button type="button" class="transparent-button" tabindex="-1" :disabled="busy" v-on:click="bulk('dismiss', [item])" v-text="t.dismiss"></button>
                                    </span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <div v-if="meta.total > meta.per_page && ! failed" class="bridge-list-foot">
                        <span v-text="shownLabel"></span>
                        <span class="flex gap-2">
                            <button type="button" class="secondary-button" :disabled="busy || meta.current_page <= 1" v-on:click="load(meta.current_page - 1)">@lang('dam::app.admin.ai-review.previous')</button>
                            <button type="button" class="secondary-button" :disabled="busy || meta.current_page >= meta.last_page" v-on:click="load(meta.current_page + 1)">@lang('dam::app.admin.ai-review.next')</button>
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
                        failed: null,
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
                            this.failed = null;
                            this.selected = this.selected.filter(key => this.items.some(item => this.keyOf(item) === key));
                            window.history.replaceState(null, '', @js(route('admin.dam.ai-review.index')) + '?' + new URLSearchParams(params).toString());
                        } catch (e) {
                            // The cause and Try again take the list's place; the page asked for is kept for the retry.
                            this.failed = { ...(globalThis.mothership?.requestError?.(e) ?? { message: e?.response?.data?.message ?? '', retryable: true }), page };
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
