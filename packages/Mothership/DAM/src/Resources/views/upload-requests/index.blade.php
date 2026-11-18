{{-- P5a (D1): the requests the viewer may see. New requests start from a folder's context menu in the Library. --}}
<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.upload-requests.title')
    </x-slot:title>

    {{-- The modal component's template ships with <x-admin::modal>; this page has no other use of it, so without this
         the request dialog (Edit, New request) rendered as an empty wrapper. --}}
    <x-admin::modal />

    <v-dam-upload-requests></v-dam-upload-requests>

    @include('dam::upload-requests.partials.row-actions')
    @include('dam::components.explorer.folder-picker')

    @pushOnce('scripts')
        @include('dam::upload-requests.modal')

        <script type="text/x-template" id="v-dam-upload-requests-template">
            <div>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.upload-requests.title')"
                    :description="trans('dam::app.admin.upload-requests.description')"
                    :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                >
                    @if (bouncer()->hasPermission('dam.upload_requests.create'))
                        <x-slot:actions>
                            <button type="button" class="primary-button" data-dam-upload-request-new @click="picker.open = true">@lang('dam::app.admin.upload-requests.new-request')</button>
                        </x-slot>
                    @endif
                </x-admin::layouts.page-header>

                <v-dam-folder-picker :open="picker.open" tab-id="upload-request-new" @picked="newRequestIn" @close="picker.open = false"></v-dam-folder-picker>

                <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden" data-dam-upload-requests>
                    <div v-if="! loaded" class="space-y-2 p-4" aria-busy="true" data-list-loading>
                        <div v-for="n in 3" :key="n" class="h-10 animate-pulse rounded bg-gray-100 dark:bg-cherry-800"></div>
                    </div>

                    <x-admin::load-error
                        v-else-if="failed && ! requests.length"
                        class="m-4"
                        :title="trans('dam::app.admin.upload-requests.load-failed')"
                        message="failed.message"
                        retryable="failed.retryable"
                        retry="load()"
                    />

                    <div v-else-if="! requests.length" class="px-4 py-10 text-center" data-dam-upload-requests-empty>
                        <p class="text-sm font-semibold text-gray-700 dark:text-slate-200">@lang('dam::app.admin.upload-requests.empty')</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.empty-hint')</p>
                    </div>

                    <div v-else>
                        <div class="hidden xl:grid xl:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_8rem_10rem_8rem_minmax(0,1fr)_22rem] items-center gap-2.5 border-b px-4 py-2.5 text-sm font-semibold text-gray-600 dark:border-cherry-800 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900">
                            <span>@lang('dam::app.admin.upload-requests.col-title')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-folder')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-status')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-expires')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-files')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-created-by')</span>
                            <span></span>
                        </div>

                        <div
                            v-for="request in requests"
                            :key="request.id"
                            class="flex flex-col gap-1 border-b px-4 py-3 text-sm text-gray-600 dark:border-cherry-800 dark:text-gray-300 xl:grid xl:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_8rem_10rem_8rem_minmax(0,1fr)_22rem] xl:items-center xl:gap-2.5 xl:py-2.5"
                            :data-dam-upload-request-row="request.id"
                        >
                            <a :href="showUrl(request.id)" class="truncate font-semibold text-gray-800 hover:text-primary-700 dark:text-white" v-text="request.title"></a>
                            <span class="truncate" :title="request.directory?.path"><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.upload-requests.col-folder')</span><span v-text="request.directory?.path ?? '—'"></span></span>
                            <span><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.upload-requests.col-status')</span><span :class="statusClass(request.status)" v-text="statusLabel(request.status)"></span></span>
                            <span><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.upload-requests.col-expires')</span><span v-text="request.expires_at ? fmtDate(request.expires_at) : neverLabel"></span></span>
                            <span><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.upload-requests.col-files')</span><span v-text="filesLabel(request)"></span></span>
                            <span class="truncate"><span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.upload-requests.col-created-by')</span><span v-text="request.created_by?.name ?? '—'"></span></span>
                            <v-dam-upload-request-actions :request="request" class="mt-2 xl:mt-0 xl:justify-end" @changed="load(page)"></v-dam-upload-request-actions>
                        </div>
                    </div>

                    <div v-if="loaded && requests.length" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" @click="load(page + 1)">@lang('dam::app.admin.upload-requests.load-more')</button>
                    </div>
                </div>

                <v-dam-upload-request-modal></v-dam-upload-request-modal>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-upload-requests', {
                template: '#v-dam-upload-requests-template',

                data() {
                    return { requests: [], loaded: false, failed: null, page: 1, lastPage: 1, total: 0, picker: { open: false } };
                },

                computed: {
                    neverLabel() {
                        return @js(trans('dam::app.admin.upload-requests.expiry.never'));
                    },

                    shownLabel() {
                        return @js(trans('dam::app.admin.upload-requests.showing')).replace(':shown', this.requests.length).replace(':total', this.total);
                    },
                },

                mounted() {
                    this.load();
                    this.savedHandler = () => this.load(this.page);
                    this.$emitter.on('dam:upload-request-saved', this.savedHandler);
                },

                unmounted() {
                    this.$emitter.off('dam:upload-request-saved', this.savedHandler);
                },

                methods: {
                    load(pages = 1) {
                        const requests = Array.from({ length: Math.max(1, pages) }, (_, i) => this.$axios.get(@js(route('admin.dam.upload-requests.list')), { params: { page: i + 1 } }));

                        return Promise.all(requests)
                            .then(responses => {
                                const last = responses[responses.length - 1].data;

                                this.requests = responses.flatMap(r => r.data.requests);
                                this.page = last.meta.current_page;
                                this.lastPage = last.meta.last_page;
                                this.total = last.meta.total;
                                this.failed = null;
                            })
                            .catch((error) => { this.failed = (globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true }); })
                            .finally(() => { this.loaded = true; });
                    },

                    // A new request starts from a folder: pick it, then the dialog opens for that folder.
                    newRequestIn({ id, name }) {
                        this.picker.open = false;
                        this.$emitter.emit('open-upload-request-modal', { directoryId: id, directoryName: name ?? '' });
                    },

                    showUrl(id) {
                        return @js(route('admin.dam.upload-requests.show', ':id')).replace(':id', id);
                    },

                    statusLabel(status) {
                        return @js([
                            'open'    => trans('dam::app.admin.upload-requests.status.open'),
                            'closed'  => trans('dam::app.admin.upload-requests.status.closed'),
                            'expired' => trans('dam::app.admin.upload-requests.status.expired'),
                        ])[status] ?? status;
                    },

                    statusClass(status) {
                        return status === 'open' ? 'label-active' : 'label-info';
                    },

                    filesLabel(request) {
                        return @js(trans('dam::app.admin.upload-requests.files-count')).replace(':files', request.counts.files).replace(':pending', request.counts.pending);
                    },

                    fmtDate(iso) {
                        return new Date(iso).toLocaleDateString([], { dateStyle: 'medium' });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
