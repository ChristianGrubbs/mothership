{{-- P4 D14: suggested related assets, ranked live from shared tags, details, file names, folders and (with smart
     search) looks. Its own section, apart from the Files and Relationships tabs, and fetched only once it scrolls
     into view. --}}
<v-dam-related-assets :initial-asset-id="{{ $assetId }}"></v-dam-related-assets>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-related-assets-template">
        <section
            ref="root"
            class="mt-3.5 rounded-lg bg-white p-5 box-shadow dark:bg-cherry-900"
            aria-labelledby="dam-related-title"
            data-dam-related
        >
            <div class="mb-4">
                <h2 id="dam-related-title" class="text-base font-semibold text-gray-800 dark:text-white">
                    @lang('dam::app.admin.dam.asset.related.title')
                </h2>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.related.description')
                </p>
            </div>

            <p v-if="state === 'loading' || state === 'idle'" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-related-loading>
                @lang('dam::app.admin.dam.asset.related.loading')
            </p>

            <div v-else-if="state === 'failed'" class="flex flex-col items-center gap-2 py-6 text-center text-sm text-red-600 dark:text-red-400" role="alert">
                <p>@lang('dam::app.admin.dam.asset.related.load-failed')</p>
                <button type="button" class="secondary-button" data-dam-related-retry @click="load">@lang('dam::app.admin.dam.asset.related.retry')</button>
            </div>

            <p v-else-if="! items.length" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-dam-related-empty>
                @lang('dam::app.admin.dam.asset.related.empty')
            </p>

            <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-dam-related-list>
                <li v-for="item in items" :key="item.id" class="min-w-0">
                    <a
                        :href="item.url"
                        class="group flex h-full flex-col overflow-hidden rounded-lg border border-gray-200 transition hover:border-primary-500 focus-visible:border-primary-500 dark:border-gray-700"
                        data-dam-related-card
                    >
                        <span class="flex aspect-square w-full items-center justify-center overflow-hidden bg-gray-100 dark:bg-cherry-800">
                            <img
                                v-if="! broken[item.id]"
                                :src="item.thumbnail_url"
                                :alt="item.display_name"
                                class="h-full w-full object-cover"
                                loading="lazy"
                                v-on:error="broken[item.id] = true"
                            />
                            <span v-else class="icon-file text-4xl text-gray-400" aria-hidden="true"></span>
                        </span>

                        <span class="flex min-w-0 flex-col gap-0.5 p-2.5">
                            <span class="truncate text-sm font-medium text-gray-800 group-hover:text-primary-700 dark:text-white dark:group-hover:text-primary-300" :title="item.display_name">
                                @{{ item.display_name }}
                            </span>

                            <span v-if="item.folder" class="truncate text-xs text-gray-400 dark:text-gray-500" :title="item.folder" data-dam-related-folder>@{{ item.folder }}</span>

                            <span class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400" :title="item.why" data-dam-related-why>
                                @{{ item.why }}
                            </span>
                        </span>
                    </a>
                </li>
            </ul>
        </section>
    </script>

    <script type="module">
        app.component('v-dam-related-assets', {
            template: '#v-dam-related-assets-template',

            props: {
                initialAssetId: { type: Number, required: true },
            },

            data() {
                return {
                    assetId: this.initialAssetId,
                    // idle until the section scrolls into view, then loading, ready or failed.
                    state:   'idle',
                    items:   [],
                    broken:  {},
                    visible: false,
                    request: 0,
                };
            },

            mounted() {
                if ('IntersectionObserver' in window) {
                    this._observer = new IntersectionObserver((entries) => {
                        this.visible = entries.some(entry => entry.isIntersecting);

                        if (this.visible && this.state === 'idle') this.load();
                    }, { rootMargin: '200px 0px' });

                    this._observer.observe(this.$refs.root);
                } else {
                    this.visible = true;
                    this.load();
                }

                // Previous / next on the asset page swap the asset without a reload.
                this._onAssetChange = ({ asset } = {}) => {
                    if (! asset?.id || Number(asset.id) === this.assetId) return;

                    this.assetId = Number(asset.id);
                    this.items = [];
                    this.broken = {};
                    this.state = 'idle';

                    if (this.visible) this.load();
                };

                this.$emitter.on('dam-asset-changed', this._onAssetChange);
            },

            beforeUnmount() {
                this._observer?.disconnect();

                if (this._onAssetChange) this.$emitter.off('dam-asset-changed', this._onAssetChange);
            },

            methods: {
                async load() {
                    const request = ++this.request;
                    this.state = 'loading';

                    try {
                        const { data } = await this.$axios.get(
                            @js(route('admin.dam.assets.related', ['asset' => '__ASSET__'])).replace('__ASSET__', this.assetId)
                        );

                        // A newer asset's request has started since; its answer wins.
                        if (request !== this.request) return;

                        this.items = data?.data?.related || [];
                        this.state = 'ready';
                    } catch (error) {
                        if (request === this.request) this.state = 'failed';
                    }
                },
            },
        });
    </script>
@endPushOnce
