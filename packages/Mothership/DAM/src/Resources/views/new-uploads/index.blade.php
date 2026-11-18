<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.new-uploads.title')
    </x-slot:title>

    <v-dam-new-uploads></v-dam-new-uploads>

    @pushOnce('scripts')
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/id-batches.js')) !!}</script>

        <script type="text/x-template" id="v-dam-new-uploads-template">
            <div>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.dam.new-uploads.title')"
                    :description="trans('dam::app.admin.dam.new-uploads.description')"
                    :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                >
                    <x-slot:actions>
                        <span v-if="selected.length" class="self-center text-sm text-gray-600 dark:text-gray-300" v-text="selectedLabel"></span>
                        <button type="button" class="secondary-button" :disabled="! selected.length || busy" @click="act('mark-sorted')">
                            @lang('dam::app.admin.dam.new-uploads.mark-sorted')
                        </button>
                        <button type="button" class="primary-button" :disabled="! selected.length || busy" @click="act('move')">
                            @lang('dam::app.admin.dam.new-uploads.move')
                        </button>
                    </x-slot:actions>
                </x-admin::layouts.page-header>

                <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden">
                    <p v-if="loaded && ! assets.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        @lang('dam::app.admin.dam.new-uploads.empty')
                    </p>

                    <div v-else class="overflow-x-auto">
                        <div class="grid min-w-[560px] grid-cols-[32px_48px_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2.5 border-b px-4 py-2.5 text-sm font-semibold text-gray-600 dark:border-cherry-800 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900">
                            <label class="cursor-pointer">
                                <input type="checkbox" class="peer sr-only" :title="selectAllTitle" :aria-label="selectAllTitle" :checked="assets.length > 0 && selected.length === assets.length" :indeterminate="selected.length > 0 && selected.length < assets.length" @change="toggleAll(); $event.target.checked = assets.length > 0 && selected.length === assets.length">
                                <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700"></span>
                            </label>
                            <span></span>
                            <span>@lang('dam::app.admin.dam.new-uploads.col-name')</span>
                            <span>@lang('dam::app.admin.dam.new-uploads.col-owner')</span>
                            <span>@lang('dam::app.admin.dam.new-uploads.col-folder')</span>
                            <span>@lang('dam::app.admin.dam.new-uploads.col-date')</span>
                        </div>

                        <div
                            v-for="asset in assets"
                            :key="asset.id"
                            class="grid min-w-[560px] grid-cols-[32px_48px_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2.5 border-b px-4 py-2.5 text-sm text-gray-600 dark:border-cherry-800 dark:text-gray-300"
                        >
                            <label class="cursor-pointer">
                                <input type="checkbox" class="peer sr-only" :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => asset.name)" :value="asset.id" v-model="selected">
                                <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700"></span>
                            </label>
                            <img v-if="asset.thumbnail" :src="asset.thumbnail" alt="" class="h-10 w-10 rounded object-cover" loading="lazy" @@error="$event.target.style.visibility = 'hidden'">
                            <span v-else></span>
                            <span class="truncate" :title="asset.name" v-text="asset.name"></span>
                            <span class="flex min-w-0 items-center gap-1.5">
                                <span class="truncate" :title="asset.owner" v-text="asset.owner"></span>
                                {{-- P5a (D1): accepted from a guest upload request; the owner is the request's creator. --}}
                                <span v-if="asset.guest" class="label-info shrink-0" data-dam-new-upload-guest>@lang('dam::app.admin.dam.new-uploads.guest')</span>
                            </span>
                            <span class="truncate" :title="asset.folder" v-text="asset.folder"></span>
                            <span class="truncate" v-text="fmtDate(asset.added_at)"></span>
                        </div>
                    </div>

                    <div v-if="loaded && assets.length" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" :disabled="busy" @click="loadMore">
                            @lang('dam::app.admin.dam.new-uploads.load-more')
                        </button>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-new-uploads', {
                template: '#v-dam-new-uploads-template',

                data() {
                    return { assets: [], selected: [], loaded: false, busy: false, page: 1, lastPage: 1, total: 0 };
                },

                computed: {
                    selectAllTitle() {
                        return @js(trans('dam::app.admin.dam.new-uploads.select-loaded'));
                    },

                    shownLabel() {
                        return @js(trans('dam::app.admin.dam.new-uploads.showing')).replace(':shown', this.assets.length).replace(':total', this.total);
                    },

                    selectedLabel() {
                        return @js(trans('dam::app.admin.dam.new-uploads.selected')).replace(':count', this.selected.length);
                    },
                },

                mounted() {
                    this.load();
                },

                methods: {
                    // Loads pages 1..n again so a refresh after an action keeps what the admin had scrolled to.
                    load(pages = 1) {
                        const requests = Array.from({ length: pages }, (_, i) => this.$axios.get(@js(route('admin.dam.new-uploads.list')), { params: { page: i + 1 } }));

                        return Promise.all(requests)
                            .then(responses => {
                                const last = responses[responses.length - 1].data;

                                this.assets = responses.flatMap(r => r.data.assets);
                                this.page = last.meta.current_page;
                                this.lastPage = last.meta.last_page;
                                this.total = last.meta.total;
                                this.selected = this.selected.filter(id => this.assets.some(a => a.id === id));
                            })
                            .catch(() => this.$emitter.emit('add-flash', { type: 'error', message: @js(trans('dam::app.admin.dam.new-uploads.load-failed')) }))
                            .finally(() => { this.loaded = true; });
                    },

                    loadMore() {
                        this.load(this.page + 1);
                    },

                    toggleAll() {
                        this.selected = this.selected.length === this.assets.length ? [] : this.assets.map(a => a.id);
                    },

                    // At most 1,000 ids per request (the server's limit): batches go out one after another and
                    // their counts are added up into one message.
                    async act(action) {
                        if (this.busy || ! this.selected.length) return;

                        this.busy = true;

                        const url = action === 'move' ? @js(route('admin.dam.new-uploads.move')) : @js(route('admin.dam.new-uploads.mark-sorted'));
                        const key = action === 'move' ? 'moved' : 'cleared';
                        let done = 0;

                        try {
                            for (const batch of window.MothershipIdBatches.chunkIds(this.selected)) {
                                const { data } = await this.$axios.post(url, { asset_ids: batch });
                                done += data[key];
                            }

                            this.$emitter.emit('add-flash', { type: 'success', message: (action === 'move' ? "@lang('dam::app.admin.dam.new-uploads.moved')".split('|').at(done === 1 ? 0 : -1) : "@lang('dam::app.admin.dam.new-uploads.marked')".split('|').at(done === 1 ? 0 : -1)).replace(':count', done) });
                        } catch (error) {
                            this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.new-uploads.action-failed')) });
                        }

                        this.selected = [];
                        this.busy = false;
                        this.load(this.page);
                    },

                    fmtDate(iso) {
                        return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
