{{-- P6 D26: the 1080p preview proxy's state beside a video's player, with Retry for editors when it failed or is missing. --}}
@php
    $videoProxyState = $asset->file_type === 'video'
        ? app(\Mothership\DAM\Services\VideoProxyStatus::class)->for($asset)
        : null;

    if ($videoProxyState !== null) {
        $videoProxyState['can_retry'] = $videoProxyState['retryable'] && bouncer()->hasPermission('dam.asset.edit');
    }
@endphp

<v-asset-video-proxy
    :asset-id="{{ $asset->id }}"
    :initial='@json($videoProxyState)'
></v-asset-video-proxy>

@pushOnce('scripts')
    <script type="text/x-template" id="v-asset-video-proxy-template">
        <section
            v-if="state"
            class="rounded-md border border-gray-200 dark:border-cherry-700 overflow-hidden"
            data-dam-video-proxy-panel
            :data-dam-video-proxy-status="state.status"
        >
            <div class="flex flex-wrap items-start gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white">
                            @lang('dam::app.admin.dam.asset.video-proxy.title')
                        </h2>

                        <span
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4"
                            :class="badgeClass"
                            data-dam-video-proxy-badge
                            v-text="state.label"
                        ></span>
                    </div>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-dam-video-proxy-hint v-text="hints[state.status] ?? ''"></p>

                    <p
                        v-if="state.status === 'failed' && state.reason"
                        class="mt-2 text-sm font-medium text-red-700 dark:text-red-400"
                        data-dam-video-proxy-reason
                        v-text="state.reason"
                    ></p>
                </div>

                <button
                    v-if="state.can_retry"
                    type="button"
                    class="secondary-button shrink-0"
                    :disabled="isRetrying"
                    data-dam-video-proxy-retry
                    @click="retry"
                >
                    @{{ isRetrying ? retryingLabel : (state.status === 'failed' ? retryLabel : generateLabel) }}
                </button>
            </div>
        </section>
    </script>

    <script type="module">
        app.component('v-asset-video-proxy', {
            template: '#v-asset-video-proxy-template',

            props: {
                assetId: { type: Number, required: true },
                initial: { type: Object, default: null },
            },

            data() {
                return {
                    currentAssetId: this.assetId,
                    state: this.initial,
                    isRetrying: false,
                    pollTimer: null,
                    hints: @js(trans('dam::app.admin.dam.asset.video-proxy.hints')),
                    retryLabel: @js(trans('dam::app.admin.dam.asset.video-proxy.retry')),
                    generateLabel: @js(trans('dam::app.admin.dam.asset.video-proxy.generate')),
                    retryingLabel: @js(trans('dam::app.admin.dam.asset.video-proxy.retrying')),
                };
            },

            computed: {
                badgeClass() {
                    return {
                        ready: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
                        queued: 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
                        processing: 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
                        failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                        missing: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                    }[this.state?.status] ?? 'bg-gray-100 text-gray-700 dark:bg-cherry-800 dark:text-gray-300';
                },

                stateUrl() {
                    return @js(route('admin.dam.assets.video-proxy.state', ['asset' => '__ID__'])).replace('__ID__', this.currentAssetId);
                },

                retryUrl() {
                    return @js(route('admin.dam.assets.video-proxy.retry', ['asset' => '__ID__'])).replace('__ID__', this.currentAssetId);
                },
            },

            mounted() {
                // The ‹ › chevrons load another asset: its show() answer carries its proxy state (null for non-video).
                this.onAssetChange = (data) => {
                    if (! data?.asset?.id) return;

                    this.currentAssetId = data.asset.id;
                    this.state = data.videoProxy ?? null;
                    this.schedulePoll();
                };
                this.$emitter.on('dam-asset-changed', this.onAssetChange);
                this.schedulePoll();
            },

            unmounted() {
                this.isGone = true;
                this.$emitter.off('dam-asset-changed', this.onAssetChange);
                clearTimeout(this.pollTimer);
            },

            methods: {
                retry() {
                    this.isRetrying = true;

                    this.$axios.post(this.retryUrl)
                        .then((response) => this.settle(response.data))
                        .catch((error) => {
                            if (error.response?.data?.data) {
                                this.settle(error.response.data);
                            } else {
                                this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || @js(trans('dam::app.admin.dam.asset.video-proxy.retry-failed')) });
                            }
                        })
                        .finally(() => { this.isRetrying = false; });
                },

                settle(body) {
                    this.state = body.data;
                    this.$emitter.emit('add-flash', { type: body.success ? 'success' : 'warning', message: body.message });
                    this.schedulePoll();
                },

                // While a job waits or runs, look again every 15 seconds so the panel turns ready or failed by itself.
                schedulePoll() {
                    clearTimeout(this.pollTimer);

                    if (this.isGone || ! ['queued', 'processing'].includes(this.state?.status)) return;

                    const assetId = this.currentAssetId;

                    this.pollTimer = setTimeout(() => {
                        this.$axios.get(this.stateUrl)
                            .then((response) => {
                                if (assetId === this.currentAssetId) this.state = response.data?.data ?? this.state;
                            })
                            .catch(() => {})
                            .finally(() => this.schedulePoll());
                    }, 15000);
                },
            },
        });
    </script>
@endPushOnce
