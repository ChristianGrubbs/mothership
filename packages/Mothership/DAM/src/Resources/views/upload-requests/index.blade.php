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
                    group="away"
                >
                    @if (bouncer()->hasPermission('dam.upload_requests.create'))
                        <x-slot:actions>
                            <button type="button" class="primary-button" data-dam-upload-request-new v-on:click="picker.open = true">@lang('dam::app.admin.upload-requests.new-request')</button>
                        </x-slot>
                    @endif
                </x-admin::layouts.page-header>

                <v-dam-folder-picker :open="picker.open" tab-id="upload-request-new" @picked="newRequestIn" @close="picker.open = false"></v-dam-folder-picker>

                {{-- The list anatomy shared with the other Away from ship and Sorting screens (app.css, "Lists").
                     --list-cols are the columns of the table form: request, status, expires, files, made by, actions. --}}
                <div
                    class="bridge-list"
                    style="--list-cols: minmax(0, 1fr) 6.5rem 8.5rem 9rem 9rem 12.5rem"
                    data-dam-upload-requests
                >
                    <div v-if="! loaded" class="bridge-list-loading" aria-busy="true" data-list-loading>
                        <span v-for="n in 3" :key="n"></span>
                    </div>

                    <x-admin::load-error
                        v-else-if="failed && ! requests.length"
                        :title="trans('dam::app.admin.upload-requests.load-failed')"
                        message="failed.message"
                        retryable="failed.retryable"
                        retry="load()"
                    />

                    <template v-else-if="! requests.length">
                        <x-admin::list.empty-state
                            :framed="false"
                            icon="icon-dam-upload"
                            :title="trans('dam::app.admin.upload-requests.empty')"
                            :description="trans('dam::app.admin.upload-requests.empty-hint')"
                            data-dam-upload-requests-empty="1"
                        />
                    </template>

                    <template v-else>
                        <div class="bridge-list-head">
                            <span>@lang('dam::app.admin.upload-requests.col-title')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-status')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-expires')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-files')</span>
                            <span>@lang('dam::app.admin.upload-requests.col-created-by')</span>
                            <span class="sr-only">@lang('admin::app.components.datagrid.table.actions')</span>
                        </div>

                        <div
                            v-for="request in requests"
                            :key="request.id"
                            class="bridge-list-row"
                            :data-dam-upload-request-row="request.id"
                        >
                            <div class="bridge-list-main">
                                <div class="bridge-list-text">
                                    <a :href="showUrl(request.id)" class="bridge-list-name" :title="request.title" v-text="request.title"></a>
                                    <p class="bridge-list-sub" :title="request.directory?.path" v-text="request.directory?.path ?? '—'"></p>
                                </div>
                            </div>

                            <div class="bridge-list-status">
                                <span class="bridge-status" :class="statusClass(request.status)" :data-dam-upload-request-status="request.status">
                                    <span class="bridge-status-mark" aria-hidden="true"></span>
                                    <span v-text="statusLabel(request.status)"></span>
                                </span>
                            </div>

                            <div class="bridge-list-meta">
                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.upload-requests.col-expires')</span>
                                    <span class="bridge-list-value" v-text="request.expires_at ? fmtDate(request.expires_at) : neverLabel"></span>
                                    <span v-if="expiryNote(request)" class="bridge-list-note" v-text="expiryNote(request)"></span>
                                </div>

                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.upload-requests.col-files')</span>
                                    <span class="bridge-list-value" v-text="receivedLabel(request)"></span>
                                    <span v-if="request.counts.pending" class="bridge-list-note" v-text="pendingLabel(request)"></span>
                                </div>

                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.upload-requests.col-created-by')</span>
                                    <span class="bridge-list-value truncate" :title="request.created_by?.name" v-text="request.created_by?.name ?? '—'"></span>
                                </div>
                            </div>

                            <v-dam-upload-request-actions :request="request" menu class="bridge-list-actions" v-on:changed="load(page)"></v-dam-upload-request-actions>
                        </div>
                    </template>

                    <div v-if="loaded && requests.length" class="bridge-list-foot">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" v-on:click="load(page + 1)">@lang('dam::app.admin.upload-requests.load-more')</button>
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

                    /** The shape that goes with the word: a filled dot while it takes files, a ring once time ended it, a bar once a person closed it. */
                    statusClass(status) {
                        return { open: 'is-live', expired: 'is-ended', closed: 'is-stopped' }[status] ?? 'is-live';
                    },

                    receivedLabel(request) {
                        return @js(trans('dam::app.admin.upload-requests.files-received')).replace(':count', Number(request.counts.files).toLocaleString());
                    },

                    pendingLabel(request) {
                        return @js(trans('dam::app.admin.upload-requests.files-pending')).replace(':count', Number(request.counts.pending).toLocaleString());
                    },

                    fmtDate(iso) {
                        return new Date(iso).toLocaleDateString([], { dateStyle: 'medium' });
                    },

                    /** "in 10 days", "tomorrow", "5 days ago", in whole calendar days. A closed request has no countdown. */
                    expiryNote(request) {
                        if (! request.expires_at || request.status === 'closed') return '';

                        const day = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
                        const days = Math.round((day(new Date(request.expires_at)) - day(new Date())) / 86400000);

                        return new Intl.RelativeTimeFormat([], { numeric: 'auto' }).format(days, 'day');
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
