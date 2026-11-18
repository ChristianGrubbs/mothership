<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.trash.title')
    </x-slot:title>

    <v-dam-trash></v-dam-trash>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-trash-template">
            <div>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.dam.trash.title')"
                    :description="trans('dam::app.admin.dam.trash.description', ['days' => \Mothership\DAM\Models\Asset::TRASH_RETENTION_DAYS])"
                    :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                />

                <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden">
                    <p v-if="loaded && failed && ! assets.length" class="px-4 py-8 text-center text-sm text-red-600 dark:text-red-400" role="alert">
                        @lang('dam::app.admin.dam.trash.load-failed')
                    </p>

                    <p v-else-if="loaded && ! assets.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        @lang('dam::app.admin.dam.trash.empty')
                    </p>

                    <!-- Below xl each row is a card with labelled lines, so its actions are on screen; from xl it is a table row. The header
                         and every row are separate grids, so no column is auto-sized: an auto column would size apart in each. -->
                    <div v-else class="xl:overflow-x-auto">
                        <div class="hidden xl:grid xl:min-w-[760px] xl:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1fr)_11rem_11rem_16rem] items-center gap-2.5 border-b px-4 py-2.5 text-sm font-semibold text-gray-600 dark:border-cherry-800 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900">
                            <span>@lang('dam::app.admin.dam.trash.col-name')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-location')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-deleted-by')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-deleted-at')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-purge-after')</span>
                            <span></span>
                        </div>

                        <div
                            v-for="asset in assets"
                            :key="asset.id"
                            class="flex flex-col gap-1 border-b px-4 py-3 text-sm text-gray-600 transition-colors hover:bg-primary-50/40 dark:border-cherry-800 dark:text-gray-300 dark:hover:bg-cherry-800 xl:grid xl:min-w-[760px] xl:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1fr)_11rem_11rem_16rem] xl:items-center xl:gap-2.5 xl:py-2.5"
                        >
                            <span class="truncate font-semibold text-gray-800 dark:text-white xl:font-normal xl:text-gray-600 xl:dark:text-gray-300" :title="asset.file_name" v-text="asset.name"></span>
                            <span class="truncate" :title="asset.location"><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.dam.trash.col-location')</span><span v-text="asset.location"></span></span>
                            <span class="truncate"><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.dam.trash.col-deleted-by')</span><span v-text="asset.deleted_by ?? unknownDeleter"></span></span>
                            <span><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.dam.trash.col-deleted-at')</span><span v-text="fmtDate(asset.deleted_at)"></span></span>
                            <span><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.dam.trash.col-purge-after')</span><span v-text="fmtDate(asset.purge_after)"></span></span>
                            <span class="mt-2 flex flex-wrap gap-2 xl:mt-0 xl:flex-nowrap xl:justify-end">
                                <button type="button" class="primary-button whitespace-nowrap" :disabled="busy || asset.can_restore === false" @click="restore(asset)">
                                    @lang('dam::app.admin.dam.trash.restore')
                                </button>
                                <button v-if="asset.can_delete" type="button" class="inline-flex items-center rounded-full border border-red-300 bg-white px-3 py-1.5 text-red-700 font-semibold whitespace-nowrap transition-colors hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:bg-transparent dark:text-red-300 dark:hover:bg-red-950/40 ms-2 xl:ms-4" :disabled="busy" @click="confirmDelete(asset)">
                                    @lang('dam::app.admin.dam.trash.delete-permanently')
                                </button>
                            </span>
                        </div>
                    </div>

                    <div v-if="loaded && assets.length" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" :disabled="busy" @click="loadMore">
                            @lang('dam::app.admin.dam.trash.load-more')
                        </button>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-trash', {
                template: '#v-dam-trash-template',

                data() {
                    return { assets: [], loaded: false, failed: false, busy: false, page: 1, lastPage: 1, total: 0 };
                },

                computed: {
                    unknownDeleter() {
                        return @js(trans('dam::app.admin.dam.trash.unknown-deleter'));
                    },

                    shownLabel() {
                        return @js(trans('dam::app.admin.dam.trash.showing')).replace(':shown', this.assets.length).replace(':total', this.total);
                    },
                },

                mounted() {
                    this.load();
                },

                methods: {
                    // Loads pages 1..n again so a refresh after an action keeps what the admin had scrolled to.
                    load(pages = 1) {
                        const requests = Array.from({ length: Math.max(1, pages) }, (_, i) => this.$axios.get(@js(route('admin.dam.trash.list')), { params: { page: i + 1 } }));

                        return Promise.all(requests)
                            .then(responses => {
                                const last = responses[responses.length - 1].data;

                                this.assets = responses.flatMap(r => r.data.assets);
                                this.page = last.meta.current_page;
                                this.lastPage = last.meta.last_page;
                                this.total = last.meta.total;
                                this.failed = false;
                            })
                            .catch(() => {
                                // A list that never loaded must not read as an empty trash.
                                this.failed = true;
                                this.$emitter.emit('add-flash', { type: 'error', message: @js(trans('dam::app.admin.dam.trash.load-failed')) });
                            })
                            .finally(() => { this.loaded = true; });
                    },

                    loadMore() {
                        this.load(this.page + 1);
                    },

                    // The buttons stay disabled until the refreshed list is in: the second click of a double-click
                    // used to land on the row the first click had already restored, and answered 404 next to the success.
                    async act(request, asset) {
                        if (this.busy) return;

                        this.busy = true;

                        try {
                            const { data } = await request();

                            this.assets = this.assets.filter((row) => row.id !== asset.id);
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        } catch (error) {
                            this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message || @js(trans('dam::app.admin.dam.trash.action-failed')) });
                        }

                        try {
                            await this.load(this.page);
                        } finally {
                            this.busy = false;
                        }
                    },

                    restore(asset) {
                        this.act(() => this.$axios.post(@js(route('admin.dam.trash.restore', ':id')).replace(':id', asset.id)), asset);
                    },

                    confirmDelete(asset) {
                        this.$emitter.emit('open-confirm-modal', {
                            title: @js(trans('dam::app.admin.dam.trash.confirm-title')),
                            message: @js(trans('dam::app.admin.dam.trash.confirm-message')),
                            options: {
                                btnDisagree: @js(trans('dam::app.admin.dam.trash.cancel')),
                                btnAgree: @js(trans('dam::app.admin.dam.trash.delete-permanently')),
                                btnAgreeClass: 'danger-button',
                                btnDisagreeClass: 'transparent-button',
                            },
                            agree: () => this.act(() => this.$axios.delete(@js(route('admin.dam.trash.destroy', ':id')).replace(':id', asset.id)), asset),
                            disagree: () => {},
                        });
                    },

                    fmtDate(iso) {
                        return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
