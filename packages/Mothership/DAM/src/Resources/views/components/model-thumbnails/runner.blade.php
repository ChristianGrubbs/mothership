@once('v-dam-model-thumbnail-runner')
@push('scripts')
<script type="text/x-template" id="v-dam-model-thumbnail-runner-template">
    <div
        v-if="modelThumbnails.visible"
        data-dam-model-thumbnail-progress
        class="fixed bottom-4 ltr:left-8 rtl:right-8 z-[10005] w-[340px] rounded-xl shadow-2xl overflow-hidden border border-gray-300 bg-white dark:border-cherry-600 dark:bg-cherry-800"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-center justify-between gap-2 px-4 py-2.5">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white" v-text="modelThumbnails.label"></p>
                <p class="text-xs text-gray-500 dark:text-gray-400" v-text="modelThumbnailSummary"></p>
            </div>
            <button
                v-if="modelThumbnails.running"
                type="button"
                class="shrink-0 rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-700"
                @click="cancelModelThumbnails"
            >Cancel</button>
            <button
                v-else
                type="button"
                class="shrink-0 rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-cherry-700"
                aria-label="Dismiss 3D thumbnail progress"
                @click="modelThumbnails.visible = false"
            >Close</button>
        </div>
        <div class="h-1.5 bg-gray-200 dark:bg-cherry-600">
            <div class="h-full bg-primary-600 transition-all duration-300 dark:bg-primary-500" :style="{ width: modelThumbnailPercent + '%' }"></div>
        </div>
    </div>
</script>

<script type="module">
/**
 * The one page-level owner of 3D thumbnail capture runs on the Library page
 * (ADR 0006). Mounted once by asset/index.blade.php in BOTH Library modes, so
 * the after-upload trigger and the backfill work whether or not the explorer
 * is enabled, and an explorer with several tabs still runs a single queue.
 *
 * In:  `dam:uploads-refresh` { assetIds }            -- every upload path
 *      `dam:generate-model-thumbnails` { directoryId } -- backfill, null = all reachable folders
 * Out: `dam:thumbnail-updated` per capture (from ModelThumbnailQueue)
 *      `dam:model-thumbnails-finished` { done, ... } once per run
 */
app.component('v-dam-model-thumbnail-runner', {
    template: '#v-dam-model-thumbnail-runner-template',

    data() {
        return {
            modelThumbnails: { visible: false, running: false, label: '', total: 0, done: 0, skipped: 0, failed: 0 },
        };
    },

    computed: {
        modelThumbnailPercent() {
            const settled = this.modelThumbnails.done + this.modelThumbnails.skipped + this.modelThumbnails.failed;
            return this.modelThumbnails.total ? Math.round((settled / this.modelThumbnails.total) * 100) : 0;
        },
        modelThumbnailSummary() {
            const parts = [`${this.modelThumbnails.done} of ${this.modelThumbnails.total}`];
            if (this.modelThumbnails.skipped) parts.push(`${this.modelThumbnails.skipped} skipped`);
            if (this.modelThumbnails.failed) parts.push(`${this.modelThumbnails.failed} failed`);
            return parts.join(' · ');
        },
    },

    mounted() {
        // Every upload path in the Library funnels through this event, so one
        // hook here covers the drop zone, the chunked panel and the classic
        // picker. The missing endpoint decides which of the new ids are 3D
        // models that still need a thumbnail.
        this._onUploadsRefresh = ({ assetIds } = {}) => {
            if (assetIds && assetIds.length) this.queueModelThumbnailIds(assetIds);
        };
        this._onGenerate = ({ directoryId = null } = {}) => {
            this.runModelThumbnails({ directoryId, label: 'Generating missing 3D thumbnails', announce: true });
        };

        this.$emitter.on('dam:uploads-refresh', this._onUploadsRefresh);
        this.$emitter.on('dam:generate-model-thumbnails', this._onGenerate);
    },

    beforeUnmount() {
        this.$emitter.off('dam:uploads-refresh', this._onUploadsRefresh);
        this.$emitter.off('dam:generate-model-thumbnails', this._onGenerate);
        this.cancelModelThumbnails();
    },

    methods: {
        cancelModelThumbnails() {
            this._modelThumbnailQueue?.cancel();
            // Nothing pending should start a new run after a deliberate cancel
            // (or after unmount, which calls this too) -- a run's own finally
            // still fires and would otherwise drain straight into a fresh one.
            this._modelThumbnailPendingIds?.clear();
        },

        /**
         * An upload's ids are never dropped just because a capture run is
         * already active. Every `dam:uploads-refresh` seen while running folds
         * into one deduplicated pending set; `runModelThumbnails` drains it the
         * moment the active run finishes, so a slow first run started by an
         * early, partial upload batch still picks up whatever a later, final
         * refresh added while it was busy.
         */
        queueModelThumbnailIds(ids) {
            if (! ids || ! ids.length) return;
            if (! this._modelThumbnailPendingIds) this._modelThumbnailPendingIds = new Set();
            for (const id of ids) this._modelThumbnailPendingIds.add(Number(id));
            if (! this.modelThumbnails.running) this.drainPendingModelThumbnailIds();
        },

        drainPendingModelThumbnailIds() {
            if (! this._modelThumbnailPendingIds?.size) return;
            const ids = [...this._modelThumbnailPendingIds];
            this._modelThumbnailPendingIds.clear();
            this.runModelThumbnails({ ids, label: 'Generating 3D thumbnails' });
        },

        /**
         * Page the missing-thumbnail list for a scope and run the capture queue
         * over it. State lives on this page-level component, so the run
         * survives folder navigation inside the Library and dies with a reload.
         */
        async runModelThumbnails({ directoryId = null, ids = null, label = '', announce = false } = {}) {
            if (this.modelThumbnails.running) {
                if (announce) this.$emitter.emit('add-flash', { type: 'info', message: 'A 3D thumbnail run is already in progress.' });
                return;
            }

            Object.assign(this.modelThumbnails, { visible: true, running: true, label, total: 0, done: 0, skipped: 0, failed: 0 });

            try {
                const items = await this.fetchMissingModels({ directoryId, ids });

                if (! items.length) {
                    this.modelThumbnails.visible = announce;
                    this.modelThumbnails.running = false;
                    if (announce) this.$emitter.emit('add-flash', { type: 'success', message: 'Every 3D model in this scope already has a thumbnail.' });
                    return;
                }

                const { ModelThumbnailQueue } = await window.loadDamModelThumbnails();
                const storeUrl = `{{ route('admin.dam.assets.model_thumbnail.store', ['id' => '__ID__']) }}`;
                const queue = new ModelThumbnailQueue({
                    endpoint: entry => storeUrl.replace('__ID__', entry.id),
                    emitter: this.$emitter,
                    onProgress: stats => Object.assign(this.modelThumbnails, {
                        total: stats.total, done: stats.done, skipped: stats.skipped, failed: stats.failed,
                    }),
                });

                this._modelThumbnailQueue = queue;
                queue.enqueue(items);
                this.modelThumbnails.total = items.length;

                const stats = await queue.run();

                // Views without per-card `dam:thumbnail-updated` handling (the
                // classic datagrid) reload once per run off this.
                this.$emitter.emit('dam:model-thumbnails-finished', { ...stats });

                if (announce) {
                    this.$emitter.emit('add-flash', {
                        type: stats.failed ? 'warning' : 'success',
                        message: `${stats.cancelled ? 'Stopped' : 'Finished'}: ${this.modelThumbnailSummary}.`,
                    });
                }
            } catch (error) {
                this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message ?? error.message });
            } finally {
                this.modelThumbnails.running = false;
                this._modelThumbnailQueue = null;
                // Whatever queueModelThumbnailIds accumulated while this run was
                // busy (including ids this same run's own scope never covered,
                // e.g. a directory-scoped backfill while an upload landed) gets
                // its own run right away rather than waiting for another event.
                this.drainPendingModelThumbnailIds();
            }
        },

        async fetchMissingModels({ directoryId = null, ids = null } = {}) {
            // ModelThumbnailController validates `ids` with max:200, but the
            // pending set queueModelThumbnailIds builds can accumulate ids
            // across more than one upload batch before a run drains it --
            // partition into requests the endpoint actually accepts instead of
            // sending a batch over 200 and taking a blanket 422 for all of them.
            if (ids && ids.length > 200) {
                const items = [];
                for (let start = 0; start < ids.length; start += 200) {
                    items.push(...await this.fetchMissingModels({ directoryId, ids: ids.slice(start, start + 200) }));
                }

                return items;
            }

            const items = [];
            let cursor = null;

            // Bounded: 200 pages of 100 is 20,000 models, well past the library's
            // 551, and a server that kept handing back a cursor could not spin
            // this loop forever.
            for (let page = 0; page < 200; page++) {
                const { data } = await this.$axios.get("{{ route('admin.dam.model_thumbnails.missing') }}", {
                    params: {
                        directory_id: directoryId ?? undefined,
                        ids: ids ?? undefined,
                        limit: 100,
                        cursor: cursor ?? undefined,
                    },
                });

                items.push(...(data.items ?? []));
                cursor = data.next_cursor;
                if (! cursor) break;
            }

            return items;
        },
    },
});
</script>
@endpush
@endonce
