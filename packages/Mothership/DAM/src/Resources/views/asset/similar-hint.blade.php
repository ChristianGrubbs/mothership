{{-- P5c (D16): "N similar images" on the asset page, counting only pairs whose other side the viewer may see; a link
     to Similar images filtered to this file. Nothing renders at 0. Previous / next refresh it without a reload. --}}
<v-dam-similar-hint :initial-asset-id="{{ (int) $assetId }}" :initial-count="{{ (int) $count }}"></v-dam-similar-hint>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-similar-hint-template">
        <p v-if="count > 0" class="mt-3.5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-100" data-dam-similar-hint>
            <span v-text="label"></span>
            <a :href="url" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">@lang('dam::app.admin.dam.similar.hint-review')</a>
        </p>
    </script>

    <script type="module">
        app.component('v-dam-similar-hint', {
            template: '#v-dam-similar-hint-template',

            props: {
                initialAssetId: { type: Number, required: true },
                initialCount: { type: Number, default: 0 },
            },

            data() {
                return { assetId: this.initialAssetId, count: this.initialCount, request: 0 };
            },

            computed: {
                label() {
                    return this.count === 1
                        ? @js(trans_choice('dam::app.admin.dam.similar.hint', 1))
                        : @js(trans_choice('dam::app.admin.dam.similar.hint', 2, ['count' => ':count'])).replace(':count', this.count);
                },

                url() {
                    return @js(route('admin.dam.similar.index')) + '?asset=' + encodeURIComponent(this.assetId);
                },
            },

            mounted() {
                this._onAssetChange = ({ asset } = {}) => {
                    if (! asset?.id || Number(asset.id) === this.assetId) return;
                    this.assetId = Number(asset.id);
                    this.count = 0;
                    this.load();
                };
                this.$emitter.on('dam-asset-changed', this._onAssetChange);
            },

            beforeUnmount() {
                this.$emitter.off('dam-asset-changed', this._onAssetChange);
            },

            methods: {
                async load() {
                    const request = ++this.request;

                    try {
                        const { data } = await this.$axios.get(@js(route('admin.dam.similar.index')), { params: { asset: this.assetId, per_page: 1 } });
                        if (request === this.request) this.count = Number(data?.meta?.total || 0);
                    } catch (e) {
                        if (request === this.request) this.count = 0;
                    }
                },
            },
        });
    </script>
@endPushOnce
