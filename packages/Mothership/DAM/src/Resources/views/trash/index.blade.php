<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.trash.title')
    </x-slot:title>

    <v-dam-trash></v-dam-trash>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-trash-template">
            <div>
                <x-admin::layouts.page-header
                    group="sorting"
                    :title="trans('dam::app.admin.dam.trash.title')"
                    :description="trans('dam::app.admin.dam.trash.description', ['days' => \Mothership\DAM\Models\Asset::TRASH_RETENTION_DAYS])"
                />

                {{-- The list anatomy shared with the other Sorting and Away from ship screens (app.css, "Lists").
                     --list-cols are the columns of the table form: name, deleted, kept until, actions. --}}
                <div
                    class="bridge-list"
                    style="--list-cols: minmax(0, 1fr) 11rem 9rem 17rem"
                    data-dam-trash
                >
                    <div v-if="! loaded" class="bridge-list-loading" aria-busy="true" data-list-loading>
                        <span v-for="n in 3" :key="n"></span>
                    </div>

                    {{-- A list that did not load says so, with a way to try again, and never reads as an empty trash. --}}
                    <v-load-error
                        v-else-if="failed && ! assets.length"
                        title="@lang('dam::app.admin.dam.trash.load-failed')"
                        :message="failed.message"
                        :retryable="failed.retryable"
                        v-on:retry="load()"
                    ></v-load-error>

                    <template v-else-if="! assets.length">
                        <x-admin::list.empty-state :framed="false" icon="icon-delete" :title="trans('dam::app.admin.dam.trash.empty')" :description="trans('dam::app.admin.dam.trash.empty-text', ['days' => \Mothership\DAM\Models\Asset::TRASH_RETENTION_DAYS])" data-dam-trash-empty="1" />
                    </template>

                    <template v-else>
                        <div class="bridge-list-head">
                            <span>@lang('dam::app.admin.dam.trash.col-name')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-deleted-at')</span>
                            <span>@lang('dam::app.admin.dam.trash.col-purge-after')</span>
                            <span class="sr-only">@lang('admin::app.components.datagrid.table.actions')</span>
                        </div>

                        <div
                            v-for="asset in assets"
                            :key="asset.id"
                            class="bridge-list-row has-no-status"
                            :data-dam-trash-row="asset.id"
                        >
                            <div class="bridge-list-main">
                                <div class="bridge-list-text">
                                    <p class="bridge-list-name" :title="asset.file_name" v-text="asset.name"></p>
                                    <p class="bridge-list-sub" :title="asset.location"><span>@lang('dam::app.admin.dam.trash.col-location')</span> <span v-text="asset.location"></span></p>
                                </div>
                            </div>

                            <div class="bridge-list-meta">
                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.dam.trash.col-deleted-at')</span>
                                    <span class="bridge-list-value" v-text="fmtDate(asset.deleted_at)"></span>
                                    <span class="bridge-list-note" v-text="deleterLabel(asset)"></span>
                                </div>

                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.dam.trash.col-purge-after')</span>
                                    <span class="bridge-list-value" v-text="fmtDay(asset.purge_after)"></span>
                                    <span v-if="untilNote(asset.purge_after)" class="bridge-list-note" v-text="untilNote(asset.purge_after)"></span>
                                </div>
                            </div>

                            {{-- Neither action is filled: a list of filled buttons has no single next step. Red waits for the confirmation. --}}
                            <div class="bridge-list-actions">
                                <button type="button" class="secondary-button" :disabled="busy || asset.can_restore === false" v-on:click="restore(asset)">
                                    @lang('dam::app.admin.dam.trash.restore')
                                </button>
                                <button v-if="asset.can_delete" type="button" class="transparent-button" :disabled="busy" v-on:click="confirmDelete(asset)">
                                    @lang('dam::app.admin.dam.trash.delete-permanently')
                                </button>
                            </div>
                        </div>
                    </template>

                    <div v-if="loaded && assets.length" class="bridge-list-foot">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" :disabled="busy" v-on:click="loadMore">
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
                    return { assets: [], loaded: false, failed: null, busy: false, page: 1, lastPage: 1, total: 0 };
                },

                computed: {
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
                                this.failed = null;
                            })
                            .catch((error) => {
                                // A list that never loaded must not read as an empty trash: the cause and Try again take the list's place.
                                this.failed = globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true };
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

                    fmtDay(iso) {
                        return iso ? new Date(iso).toLocaleDateString([], { dateStyle: 'medium' }) : '';
                    },

                    deleterLabel(asset) {
                        return asset.deleted_by
                            ? @js(trans('dam::app.admin.dam.trash.deleted-by-line')).replace(':name', asset.deleted_by)
                            : @js(trans('dam::app.admin.dam.trash.deleted-by-unknown'));
                    },

                    /** "in 22 days", "tomorrow", "today", in whole calendar days. */
                    untilNote(iso) {
                        if (! iso) return '';

                        const day = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
                        const days = Math.round((day(new Date(iso)) - day(new Date())) / 86400000);

                        return new Intl.RelativeTimeFormat([], { numeric: 'auto' }).format(days, 'day');
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
