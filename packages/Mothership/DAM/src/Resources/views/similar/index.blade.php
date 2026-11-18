{{--
    P5c (D16): Similar images. Review groups of two or more images that are all within the threshold of each other, the
    admin may see every member of, closest first. A chain where A is like B and B like C but A is not like C is shown as
    the two groups {A,B} and {B,C}, never one group. Stack opens the Create stack review (the merge dialog) with the members
    shown, exactly as the Suggested stacks panel does; the review names the group, so the server refuses (HTTP 409) a group
    that changed since. "Not duplicates" dismisses exactly the group's own pairs through the same per-viewer
    dismissal the Library's Suggested stacks panel uses, so each surface hides them from the other.
--}}
<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.similar.title')
    </x-slot:title>

    <v-dam-similar-images :initial="@js($initial)"></v-dam-similar-images>

    @include('dam::components.explorer.folder-picker')

    @if ($initial['can_merge'])
        <x-dam::merge.dialog />
    @endif

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-similar-images-template">
            <div data-dam-similar>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.dam.similar.title')"
                    :description="trans('dam::app.admin.dam.similar.description')"
                    :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                />

                <div class="mt-4 flex flex-wrap items-center gap-2" data-dam-similar-filters>
                    <span v-if="filters.asset_id" class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800 dark:bg-cherry-800 dark:text-primary-200">
                        <span class="truncate" v-text="t.similarTo.replace(':name', filters.asset_name)"></span>
                        <button type="button" class="ms-1 shrink-0 text-base leading-none" :aria-label="t.clearFilter" @click="setFilter({ asset_id: null, asset_name: null })">×</button>
                    </span>

                    <span v-if="filters.folder_id" class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800 dark:bg-cherry-800 dark:text-primary-200">
                        <span class="truncate" v-text="t.inFolder.replace(':name', filters.folder_name)"></span>
                        <button type="button" class="ms-1 shrink-0 text-base leading-none" :aria-label="t.clearFilter" @click="setFilter({ folder_id: null, folder_name: null })">×</button>
                    </span>

                    <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" @click="pickerOpen = true" data-dam-similar-folder>
                        @lang('dam::app.admin.dam.similar.choose-folder')
                    </button>
                </div>

                <div class="mt-3 rounded-lg bg-white box-shadow dark:bg-cherry-900">
                    <div v-if="groups.length" class="flex flex-wrap items-center gap-3 border-b px-4 py-2.5 text-sm text-gray-700 dark:border-cherry-800 dark:text-gray-300">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" class="h-4 w-4" :checked="allSelected" :indeterminate.prop="someSelected" @change="toggleAll($event.target.checked)" data-dam-similar-select-all>
                            <span>@lang('dam::app.admin.dam.similar.select-all')</span>
                        </label>
                        <span v-if="selected.length" class="text-gray-500 dark:text-gray-400" v-text="t.selected.replace(':count', selected.length)"></span>
                        <button
                            v-if="selected.length"
                            type="button"
                            class="secondary-button !h-8 !px-3 !py-1 text-sm ms-auto"
                            :disabled="busy"
                            @click="dismiss(selected)"
                            data-dam-similar-dismiss-selected
                            v-text="t.dismissSelected.replace(':count', selected.length)"
                        ></button>
                    </div>

                    <p v-if="meta.truncated" class="border-b px-4 py-2 text-sm text-amber-800 dark:border-cherry-800 dark:text-amber-200" role="status" data-dam-similar-truncated v-text="t.truncated.replace(':ceiling', meta.edge_ceiling)"></p>

                    <p v-if="failed" class="px-4 py-8 text-center text-sm text-red-600 dark:text-red-400" role="alert">@lang('dam::app.admin.dam.similar.load-failed')</p>
                    <p v-else-if="! groups.length && (filters.asset_id || filters.folder_id)" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-similar-empty>@lang('dam::app.admin.dam.similar.empty-filtered')</p>
                    <p v-else-if="! groups.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-similar-empty>@lang('dam::app.admin.dam.similar.empty')</p>

                    <ul v-else class="divide-y divide-gray-100 dark:divide-cherry-800" data-dam-similar-list>
                        <li
                            v-for="group in groups"
                            :key="group.group_key"
                            class="px-4 py-4"
                            data-dam-similar-group
                            :data-group-key="group.group_key"
                            :data-count="group.count"
                        >
                            <div class="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :value="group.group_key"
                                    v-model="selected"
                                    :aria-label="selectLabel(group)"
                                >

                                <div class="flex min-w-0 flex-1 flex-col gap-3">
                                    {{-- The verdict and the decision come first, so a large group never pushes its actions out of reach. --}}
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-dam-similar-header>
                                        <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                            <span class="font-semibold text-gray-800 dark:text-white" data-dam-similar-count v-text="t.groupCount.replace(':count', group.count)"></span>
                                            <span
                                                class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                :class="group.similarity === 'near_identical' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200'"
                                                :title="t.difference.replace(':distance', group.distance)"
                                                v-text="group.similarity_label"
                                            ></span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400" data-dam-similar-score v-text="t.difference.replace(':distance', group.distance)"></span>
                                        </p>

                                        <div class="flex flex-wrap gap-2">
                                            <button v-if="canStack" type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm max-[639px]:!h-11" :disabled="busy" @click="stack(group)" data-dam-similar-stack>@lang('dam::app.admin.dam.similar.stack')</button>
                                            <button type="button" class="transparent-button !h-8 !px-3 !py-1 text-sm max-[639px]:!h-11" :disabled="busy" @click="dismiss([group.group_key])" data-dam-similar-dismiss>@lang('dam::app.admin.dam.similar.not-duplicates')</button>
                                        </div>
                                    </div>

                                    <div class="grid min-w-0 gap-3" :class="group.count > 6 ? 'grid-cols-3 sm:grid-cols-4 xl:grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-6'" data-dam-similar-members>
                                        <a
                                            v-for="asset in group.assets"
                                            :key="asset.id"
                                            :href="asset.url"
                                            :title="asset.name"
                                            :aria-label="asset.name"
                                            class="group flex min-w-0 flex-col overflow-hidden rounded-lg border border-gray-200 transition hover:border-primary-500 focus-visible:border-primary-500 dark:border-gray-700"
                                        >
                                            <span class="relative block aspect-[4/3] overflow-hidden bg-gray-50 dark:bg-cherry-800">
                                                <img :src="asset.thumbnail_url" :alt="asset.name" loading="lazy" class="absolute inset-0 h-full w-full object-contain">
                                            </span>
                                            {{-- Shared head and tail are muted and give way first; what tells the files apart stays whole. --}}
                                            <span class="flex min-w-0 px-2 py-1.5 text-xs text-gray-700 group-hover:text-primary-700 dark:text-gray-300" data-dam-similar-name>
                                                <span v-if="nameParts(group, asset).head" class="min-w-0 shrink truncate text-gray-500 dark:text-gray-400" v-text="nameParts(group, asset).head"></span>
                                                <span :class="nameParts(group, asset).head || nameParts(group, asset).tail ? 'max-w-full shrink-0 font-semibold' : 'min-w-0 truncate'" v-text="nameParts(group, asset).mid"></span>
                                                <span v-if="nameParts(group, asset).tail" class="truncate text-gray-500 dark:text-gray-400" :class="nameParts(group, asset).fixed ? 'shrink-0' : 'min-w-0 shrink'" v-text="nameParts(group, asset).tail"></span>
                                            </span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div v-if="meta.total > meta.per_page" class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-2.5 text-sm text-gray-600 dark:border-cherry-800 dark:text-gray-300">
                        <span v-text="shownLabel"></span>
                        <span class="flex gap-2">
                            <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy || meta.current_page <= 1" @click="go(meta.current_page - 1)">@lang('dam::app.admin.dam.similar.previous')</button>
                            <button type="button" class="secondary-button !h-8 !px-3 !py-1 text-sm" :disabled="busy || meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">@lang('dam::app.admin.dam.similar.next')</button>
                        </span>
                    </div>
                </div>

                <v-dam-folder-picker :open="pickerOpen" tab-id="similar-images" :title="t.folderPickerTitle" @picked="pickFolder" @close="pickerOpen = false"></v-dam-folder-picker>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-similar-images', {
                template: '#v-dam-similar-images-template',

                props: {
                    initial: { type: Object, required: true },
                },

                data() {
                    return {
                        groups: this.initial.data,
                        meta: this.initial.meta,
                        filters: { ...this.initial.filters },
                        canStack: !! this.initial.can_stack,
                        selected: [],
                        busy: false,
                        failed: false,
                        pickerOpen: false,
                        t: {
                            similarTo: @js(trans('dam::app.admin.dam.similar.similar-to')),
                            inFolder: @js(trans('dam::app.admin.dam.similar.in-folder')),
                            clearFilter: @js(trans('dam::app.admin.dam.similar.clear-filter')),
                            selected: @js(trans('dam::app.admin.dam.similar.selected')),
                            dismissSelected: @js(trans('dam::app.admin.dam.similar.dismiss-selected')),
                            selectPair: @js(trans('dam::app.admin.dam.similar.select-pair')),
                            selectGroup: @js(trans('dam::app.admin.dam.similar.select-group')),
                            groupCount: @js(trans('dam::app.admin.dam.similar.group-count')),
                            truncated: @js(trans('dam::app.admin.dam.similar.truncated')),
                            difference: @js(trans('dam::app.admin.dam.similar.difference')),
                            showing: @js(trans('dam::app.admin.dam.similar.showing')),
                            failed: @js(trans('dam::app.admin.dam.similar.failed')),
                            folderPickerTitle: @js(trans('dam::app.admin.dam.similar.folder-picker-title')),
                        },
                    };
                },

                computed: {
                    allSelected() {
                        return this.groups.length > 0 && this.selected.length === this.groups.length;
                    },

                    someSelected() {
                        return this.selected.length > 0 && this.selected.length < this.groups.length;
                    },

                    shownLabel() {
                        const from = (this.meta.current_page - 1) * this.meta.per_page + 1;

                        return this.t.showing
                            .replace(':from', from)
                            .replace(':to', Math.min(this.meta.total, from + this.groups.length - 1))
                            .replace(':total', this.meta.total);
                    },
                },

                mounted() {
                    this._onMergeDone = ({ context } = {}) => {
                        if (context === 'similar-images') this.load(this.meta.current_page);
                    };
                    this.$emitter.on('dam:merge-done', this._onMergeDone);
                    // A review closed without stacking (cancelled, or refused 409 because the group changed) leaves the groups possibly stale: show what is there now.
                    this._onMergeClosed = ({ reviewId, merged } = {}) => {
                        if (! merged && String(reviewId).startsWith('similar:')) this.load(this.meta.current_page);
                    };
                    this.$emitter.on('dam:merge-dialog-closed', this._onMergeClosed);
                },

                beforeUnmount() {
                    this.$emitter.off('dam:merge-done', this._onMergeDone);
                    this.$emitter.off('dam:merge-dialog-closed', this._onMergeClosed);
                },

                methods: {
                    params(page) {
                        const params = { page };
                        if (this.filters.asset_id) params.asset = this.filters.asset_id;
                        if (this.filters.folder_id) params.folder = this.filters.folder_id;

                        return params;
                    },

                    async load(page = 1) {
                        const params = this.params(page);

                        try {
                            const { data } = await this.$axios.get(@js(route('admin.dam.similar.index')), { params });
                            this.groups = data.data;
                            this.meta = data.meta;
                            this.failed = false;
                            this.selected = this.selected.filter(key => this.groups.some(group => group.group_key === key));
                            window.history.replaceState(null, '', @js(route('admin.dam.similar.index')) + '?' + new URLSearchParams(params).toString());
                        } catch (e) {
                            this.failed = true;
                        }
                    },

                    go(page) {
                        this.selected = [];
                        this.load(page);
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

                    toggleAll(on) {
                        this.selected = on ? this.groups.map(group => group.group_key) : [];
                    },

                    selectLabel(group) {
                        return group.count === 2
                            ? this.t.selectPair.replace(':a', group.assets[0].name).replace(':b', group.assets[1].name)
                            : this.t.selectGroup.replace(':count', group.count).replace(':name', group.assets[0].name);
                    },

                    /**
                     * A file name split into what the group's names share at the start, what tells it apart, and what they share at the end,
                     * so a crowded tile can shorten the shared parts and keep the part that identifies the file. The shared parts end at a
                     * separator, never inside a word or number; with nothing shared (or a name that is only shared parts) the whole name is "mid".
                     */
                    nameParts(group, asset) {
                        this._nameParts ??= new WeakMap();
                        let parts = this._nameParts.get(group);

                        if (! parts) {
                            const names = group.assets.map(item => String(item.name));
                            let head = names[0];
                            let tail = names[0];

                            names.forEach(name => {
                                while (! name.startsWith(head)) head = head.slice(0, -1);
                                while (! name.endsWith(tail)) tail = tail.slice(1);
                            });

                            head = head.slice(0, Math.max(...['-', '_', '.', ' '].map(mark => head.lastIndexOf(mark))) + 1);
                            const cut = tail.search(/[-_. ]/);
                            tail = cut < 0 ? '' : tail.slice(cut);
                            const split = names.every(name => name.length > head.length + tail.length);

                            parts = new Map(group.assets.map(item => {
                                const name = String(item.name);

                                return [item.id, split ? { head, mid: name.slice(head.length, name.length - tail.length), tail, fixed: tail.length <= 8 } : { head: '', mid: name, tail: '', fixed: false }];
                            }));
                            this._nameParts.set(group, parts);
                        }

                        return parts.get(asset.id);
                    },

                    /** Opens the Create stack review for the members shown; it names the group, so the server refuses (409) a changed one. */
                    stack(group) {
                        this.$emitter.emit('dam:open-merge-dialog', {
                            assets: group.assets.map(asset => ({ id: asset.id, name: asset.name, thumb: asset.thumbnail_url, stack_basis: asset.stack?.basis || null, stack_key: asset.stack?.key || null })),
                            context: 'similar-images',
                            reviewId: `similar:${group.group_key}`,
                            review: { group_key: group.group_key, members: group.members },
                        });
                    },

                    async dismiss(keys) {
                        if (! keys.length) return;
                        this.busy = true;

                        try {
                            // Each group names its members, so only the pairs the admin reviewed are dismissed.
                            const groups = this.groups.filter(group => keys.includes(group.group_key)).map(group => ({ key: group.group_key, members: group.members }));
                            const { data } = await this.$axios.post(@js(route('admin.dam.similar.dismiss')), { groups });
                            this.selected = this.selected.filter(key => ! keys.includes(key));
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                            await this.load(this.meta.current_page);
                        } catch (e) {
                            this.$emitter.emit('add-flash', { type: 'error', message: e.response?.data?.message || this.t.failed });

                            if (e.response?.status === 409) await this.load(this.meta.current_page);
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
