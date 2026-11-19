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
                    group="sorting"
                    :title="trans('dam::app.admin.dam.new-uploads.title')"
                    :description="trans('dam::app.admin.dam.new-uploads.description')"
                >
                    {{-- With nothing picked both actions are quiet and off. With a selection Move is the one filled control. --}}
                    <x-slot:actions>
                        <button type="button" class="secondary-button on-ground" :disabled="! selected.length || busy" v-on:click="act('mark-sorted')" data-dam-new-uploads-mark>
                            @lang('dam::app.admin.dam.new-uploads.mark-sorted')
                        </button>
                        <button type="button" :class="selected.length ? 'primary-button' : 'secondary-button on-ground'" :disabled="! selected.length || busy" v-on:click="act('move')" data-dam-new-uploads-move>
                            @lang('dam::app.admin.dam.new-uploads.move')
                        </button>
                    </x-slot:actions>
                </x-admin::layouts.page-header>

                {{-- The list anatomy shared with the other Sorting and Away from ship screens (app.css, "Lists").
                     --list-cols are the columns of the table form: name, uploaded by, added. --}}
                <div
                    class="bridge-list"
                    style="--list-cols: minmax(0, 1fr) 14rem 11rem"
                    data-dam-new-uploads
                >
                    <div v-if="! loaded" class="bridge-list-loading" aria-busy="true" data-list-loading>
                        <span v-for="n in 3" :key="n"></span>
                    </div>

                    {{-- A list that did not load says so, with a way to try again, and never reads as an empty Inbox. --}}
                    <v-load-error
                        v-else-if="failed && ! assets.length"
                        title="@lang('dam::app.admin.dam.new-uploads.load-failed')"
                        :message="failed.message"
                        :retryable="failed.retryable"
                        v-on:retry="load()"
                    ></v-load-error>

                    <template v-else-if="! assets.length">
                        <x-admin::list.empty-state :framed="false" icon="icon-dam-upload" :title="trans('dam::app.admin.dam.new-uploads.empty')" :description="trans('dam::app.admin.dam.new-uploads.empty-text')" data-dam-new-uploads-empty="1" />
                    </template>

                    <template v-else>
                        {{-- The head is the select-all strip at every width; its column names show in the table form only. --}}
                        <div class="bridge-list-head has-pick">
                            <div class="bridge-list-pick">
                                <label class="bridge-list-check">
                                    <input type="checkbox" class="peer sr-only" :title="selectAllTitle" :aria-label="selectAllTitle" :checked="assets.length > 0 && selected.length === assets.length" :indeterminate="selected.length > 0 && selected.length < assets.length" v-on:change="toggleAll(); $event.target.checked = assets.length > 0 && selected.length === assets.length">
                                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700"></span>
                                </label>

                                <span role="status" data-dam-new-uploads-selected v-text="selected.length ? selectedLabel : ''"></span>

                                <button v-if="selected.length" type="button" class="bridge-list-clear" v-on:click="selected = []" data-dam-new-uploads-clear>
                                    @lang('dam::app.admin.dam.new-uploads.clear')
                                </button>

                                <template v-else>
                                    <span class="bridge-list-for-cards" aria-hidden="true">@lang('dam::app.admin.dam.new-uploads.select-all')</span>
                                    <span class="bridge-list-for-table">@lang('dam::app.admin.dam.new-uploads.col-name')</span>
                                </template>
                            </div>

                            <span>@lang('dam::app.admin.dam.new-uploads.col-owner')</span>
                            <span>@lang('dam::app.admin.dam.new-uploads.col-date')</span>
                        </div>

                        <div
                            v-for="asset in assets"
                            :key="asset.id"
                            class="bridge-list-row has-no-status"
                            :class="{ 'is-picked': selected.includes(asset.id) }"
                            :data-dam-new-upload-row="asset.id"
                        >
                            <div class="bridge-list-main">
                                <label class="bridge-list-check">
                                    <input type="checkbox" class="peer sr-only" :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => asset.name)" :value="asset.id" v-model="selected">
                                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700"></span>
                                </label>

                                {{-- A picture that will not load gives way to the neutral file tile, never to a broken image. --}}
                                <img v-if="asset.thumbnail && ! broken[asset.id]" :src="asset.thumbnail" alt="" class="bridge-list-thumb" loading="lazy" v-on:error="broken[asset.id] = true">
                                <span v-else class="bridge-list-tile icon-file" aria-hidden="true"></span>

                                <div class="bridge-list-text">
                                    <p class="bridge-list-name" :title="asset.name" v-text="asset.name"></p>
                                    <p v-if="asset.folder" class="bridge-list-sub" :title="asset.folder" v-text="folderLabel(asset)"></p>
                                </div>
                            </div>

                            <div class="bridge-list-meta">
                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.dam.new-uploads.col-owner')</span>
                                    <span class="bridge-list-value" v-text="asset.owner || '—'"></span>
                                    {{-- P5a (D1): accepted from a guest upload request; the owner is the request's creator. --}}
                                    <span v-if="asset.guest" class="bridge-list-note" data-dam-new-upload-guest>@lang('dam::app.admin.dam.new-uploads.guest')</span>
                                </div>

                                <div class="bridge-list-cell">
                                    <span class="bridge-list-label">@lang('dam::app.admin.dam.new-uploads.col-date')</span>
                                    <span class="bridge-list-value" v-text="fmtDay(asset.added_at)"></span>
                                    <span class="bridge-list-note" v-text="fmtTime(asset.added_at)"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div v-if="loaded && assets.length" class="bridge-list-foot">
                        <span v-text="shownLabel"></span>
                        <button v-if="page < lastPage" type="button" class="secondary-button" :disabled="busy" v-on:click="loadMore">
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
                    return { assets: [], selected: [], broken: {}, loaded: false, failed: null, busy: false, page: 1, lastPage: 1, total: 0 };
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
                        const requests = Array.from({ length: Math.max(1, pages) }, (_, i) => this.$axios.get(@js(route('admin.dam.new-uploads.list')), { params: { page: i + 1 } }));

                        return Promise.all(requests)
                            .then(responses => {
                                const last = responses[responses.length - 1].data;

                                this.assets = responses.flatMap(r => r.data.assets);
                                this.page = last.meta.current_page;
                                this.lastPage = last.meta.last_page;
                                this.total = last.meta.total;
                                this.selected = this.selected.filter(id => this.assets.some(a => a.id === id));
                                this.failed = null;
                            })
                            .catch((error) => {
                                // A list that never loaded must not read as an empty Inbox: the cause and Try again take the list's place.
                                this.failed = globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true };
                                this.$emitter.emit('add-flash', { type: 'error', message: @js(trans('dam::app.admin.dam.new-uploads.load-failed')) });
                            })
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

                    folderLabel(asset) {
                        return @js(trans('dam::app.admin.dam.new-uploads.in-folder')).replace(':folder', () => asset.folder);
                    },

                    fmtDay(iso) {
                        return iso ? new Date(iso).toLocaleDateString([], { dateStyle: 'medium' }) : '';
                    },

                    fmtTime(iso) {
                        return iso ? new Date(iso).toLocaleTimeString([], { timeStyle: 'short' }) : '';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
