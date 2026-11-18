{{--
    P6a (D27): the viewer's "Clip & watermark" panel for a video. Start and end come from typing or from the panel's
    own player; an optional watermark image is drawn over the preview where the recipe will put it (the box keeps the
    video's aspect ratio, so the corner margin and the width share match the output). It starts a one-item batch —
    `clip`, or `watermark` with start and end when an image is chosen — and hands it to the shared progress panel.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-batch-clip-template">
        <x-admin::modal ref="clipModal" type="large" @toggle="onToggled" @close="stopTicking">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ t.viewer['clip-title'] }}</p>
            </x-slot>

            <x-slot:content>
                <form class="flex flex-col gap-4 py-1" data-dam-batch-clip @submit.prevent="submit">
                    <div
                        class="relative mx-auto overflow-hidden rounded-md bg-black"
                        :style="{ aspectRatio: ratio, width: 'min(100%, calc(36vh * ' + ratio + '))' }"
                        data-dam-clip-stage
                    >
                        <video
                            ref="clipVideo"
                            :src="src"
                            class="absolute inset-0 h-full w-full"
                            controls
                            playsinline
                            preload="metadata"
                            @loadedmetadata="onMeta"
                        ></video>
                        <img
                            v-if="overlay"
                            :src="overlay.url"
                            alt=""
                            aria-hidden="true"
                            class="pointer-events-none absolute"
                            :style="overlayStyle"
                            data-dam-clip-overlay-preview
                        >
                    </div>
                    <p v-if="overlay" class="-mt-2 text-center text-xs text-gray-500 dark:text-gray-400">@{{ t.viewer['preview-hint'] }}</p>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="dam-clip-start" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.start }}</label>
                            <div class="flex gap-2">
                                <input id="dam-clip-start" v-model="start" type="number" min="0" step="0.1" inputmode="decimal" data-dam-clip-start class="w-full min-w-0 rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200">
                                <button type="button" class="secondary-button shrink-0 !px-3 !py-1.5 text-xs" data-dam-clip-set-start :aria-label="t.viewer['set-start']" :title="t.viewer['set-start']" @click="setFromPlayer('start')">@{{ t.viewer['use-current'].replace(':time', clock(now)) }}</button>
                            </div>
                            <p v-if="errors['params.start']" class="mt-1 text-xs text-red-600" role="alert">@{{ errors['params.start'][0] }}</p>
                        </div>
                        <div>
                            <label for="dam-clip-end" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.end }}</label>
                            <div class="flex gap-2">
                                <input id="dam-clip-end" v-model="end" type="number" min="0.1" step="0.1" inputmode="decimal" data-dam-clip-end class="w-full min-w-0 rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200">
                                <button type="button" class="secondary-button shrink-0 !px-3 !py-1.5 text-xs" data-dam-clip-set-end :aria-label="t.viewer['set-end']" :title="t.viewer['set-end']" @click="setFromPlayer('end')">@{{ t.viewer['use-current'].replace(':time', clock(now)) }}</button>
                            </div>
                            <p v-if="errors['params.end']" class="mt-1 text-xs text-red-600" role="alert">@{{ errors['params.end'][0] }}</p>
                        </div>
                    </div>
                    <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400" data-dam-clip-length>@{{ lengthLine }}</p>

                    <fieldset class="flex flex-col gap-3 rounded-md border p-3 dark:border-gray-800">
                        <legend class="px-1 text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.overlay_asset_id }}</legend>
                        <div class="flex flex-wrap items-center gap-2">
                            <template v-if="overlay">
                                <img :src="overlay.url" alt="" class="h-10 w-10 rounded border border-gray-200 object-contain dark:border-gray-700">
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">@{{ overlay.display_name || overlay.file_name }}</span>
                                <button type="button" class="secondary-button !px-3 !py-1 text-xs" @click="overlay = null">@{{ t.viewer['remove-watermark'] }}</button>
                            </template>
                            <template v-else>
                                <button type="button" class="secondary-button !px-3 !py-1.5 text-sm" data-dam-clip-choose-overlay @click="chooseOverlay">@{{ t.viewer['choose-watermark'] }}</button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">@{{ t.viewer['no-watermark'] }}</span>
                            </template>
                        </div>
                        <p v-if="errors['params.overlay_asset_id']" class="text-xs text-red-600" role="alert">@{{ errors['params.overlay_asset_id'][0] }}</p>

                        <div v-if="overlay" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label for="dam-clip-position" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.position }}</label>
                                <select id="dam-clip-position" v-model="position" data-dam-clip-position class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200">
                                    <option v-for="value in positions" :key="value" :value="value">@{{ t.options[value] }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="dam-clip-scale" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.scale }} · @{{ Math.round(scale * 100) }}%</label>
                                <input id="dam-clip-scale" v-model.number="scale" type="range" min="0.05" max="1" step="0.01" data-dam-clip-scale class="w-full accent-primary-600">
                            </div>
                            <div>
                                <label for="dam-clip-opacity" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.opacity }} · @{{ Math.round(opacity * 100) }}%</label>
                                <input id="dam-clip-opacity" v-model.number="opacity" type="range" min="0.05" max="1" step="0.01" data-dam-clip-opacity class="w-full accent-primary-600">
                            </div>
                        </div>
                    </fieldset>

                    <div v-if="! overlay">
                        <label for="dam-clip-mode" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.params.mode }}</label>
                        <select id="dam-clip-mode" v-model="mode" class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200">
                            <option value="auto">@{{ t.options.auto }}</option>
                            <option value="reencode">@{{ t.options.reencode }}</option>
                        </select>
                    </div>

                    <fieldset class="flex flex-col gap-1.5">
                        <legend class="mb-1 text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t['save-as'] }}</legend>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="radio" value="attach" v-model="saveAs" name="dam-clip-save-as"> @{{ t['save-attach-one'] }}</label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="radio" value="new_asset" v-model="saveAs" name="dam-clip-save-as"> @{{ t['save-new-asset'] }}</label>
                    </fieldset>

                    <p v-if="generalError" class="text-sm text-red-600" role="alert">@{{ generalError }}</p>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t pt-3 dark:border-gray-800">
                        <button type="button" class="secondary-button" @click="$refs.clipModal.close()">@{{ t.close }}</button>
                        <button type="submit" class="primary-button" data-dam-clip-run :disabled="submitting">@{{ submitting ? t.starting : t.viewer.run }}</button>
                    </div>
                </form>
            </x-slot>
        </x-admin::modal>
    </script>

    <script type="module">
        app.component('v-dam-batch-clip', {
            template: '#v-dam-batch-clip-template',

            data() {
                return {
                    t: @js(trans('dam::app.admin.batch')),
                    storeUrl: @js(route('admin.dam.batches.store')),
                    assetsUrl: @js(route('admin.dam.asset_picker.get_assets')),
                    positions: ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'],
                    assetId: null,
                    src: '',
                    ratio: 16 / 9,
                    duration: null,
                    now: 0,
                    start: 0,
                    end: '',
                    overlay: null,
                    position: 'bottom-right',
                    scale: 0.2,
                    opacity: 0.8,
                    mode: 'auto',
                    saveAs: 'attach',
                    errors: {},
                    generalError: '',
                    submitting: false,
                };
            },

            computed: {
                {{-- The recipe's geometry: the mark is `scale` of the frame's width, 3% of the width in from the edges. --}}
                overlayStyle() {
                    const margin = '3%';
                    const style = { width: (this.scale * 100) + '%', opacity: this.opacity };
                    const [vertical, horizontal] = this.position === 'center' ? ['center', 'center'] : this.position.split('-');

                    if (this.position === 'center') {
                        return { ...style, left: '50%', top: '50%', transform: 'translate(-50%, -50%)' };
                    }

                    return {
                        ...style,
                        [vertical === 'top' ? 'top' : 'bottom']: 'calc(' + margin + ' * ' + this.ratio + ')',
                        [horizontal === 'left' ? 'left' : 'right']: margin,
                    };
                },

                lengthLine() {
                    const start = Number(this.start) || 0;
                    const end = Number(this.end) || 0;

                    return end > start ? this.clock(start) + ' – ' + this.clock(end) + ' (' + (end - start).toFixed(1) + ' s)' : '';
                },
            },

            mounted() {
                this._onOpen = (payload = {}) => this.open(payload);
                this.$emitter.on('dam:open-batch-clip', this._onOpen);
            },

            beforeUnmount() {
                this.$emitter.off('dam:open-batch-clip', this._onOpen);
                clearInterval(this._tick);
            },

            methods: {
                open({ assetId, src, currentTime = 0, duration = null } = {}) {
                    if (! assetId || ! src) return;

                    this.assetId = Number(assetId);
                    this.src = src;
                    this.duration = duration;
                    this.start = Math.round((currentTime || 0) * 10) / 10;
                    this.end = duration ? Math.min(duration, this.start + 5).toFixed(1) : (this.start + 5).toFixed(1);
                    this.now = currentTime || 0;
                    this.errors = {};
                    this.generalError = '';
                    this.$refs.clipModal.open();

                    clearInterval(this._tick);
                    this._tick = setInterval(() => {
                        const video = this.$refs.clipVideo;
                        if (video) this.now = video.currentTime;
                    }, 250);
                },

                stopTicking() {
                    clearInterval(this._tick);
                    this.$refs.clipVideo?.pause();
                },

                onToggled({ isActive } = {}) {
                    if (! isActive) this.stopTicking();
                },

                onMeta(event) {
                    const video = event.target;

                    if (video.videoWidth && video.videoHeight) {
                        this.ratio = video.videoWidth / video.videoHeight;
                    }

                    this.duration = video.duration || this.duration;

                    if (this.start > 0 && Math.abs(video.currentTime - this.start) > 0.05) {
                        video.currentTime = this.start;
                    }
                },

                setFromPlayer(which) {
                    const time = Math.round((this.$refs.clipVideo?.currentTime ?? this.now) * 10) / 10;
                    this[which] = time;
                },

                clock(seconds) {
                    const total = Math.max(0, Number(seconds) || 0);
                    const minutes = Math.floor(total / 60);
                    const rest = (total - minutes * 60).toFixed(1).padStart(4, '0');

                    return minutes + ':' + rest;
                },

                chooseOverlay() {
                    this.$emitter.emit('dam-asset-picker:open', {
                        ids: [],
                        onAssign: async ids => {
                            const id = Number((ids || [])[0]);
                            if (! id) return;

                            try {
                                const { data } = await this.$axios.get(this.assetsUrl, { params: { assetIds: String(id) } });
                                const asset = (data || [])[0];
                                this.overlay = asset ? { id: Number(asset.id), url: asset.url, file_name: asset.file_name, display_name: asset.display_name } : null;
                            } catch (error) {
                                this.overlay = null;
                            }
                        },
                    });
                },

                async submit() {
                    if (this.submitting) return;

                    this.submitting = true;
                    this.errors = {};
                    this.generalError = '';

                    const range = { start: this.start === '' ? null : this.start, end: this.end === '' ? null : this.end };
                    const request = this.overlay
                        ? { recipe: 'watermark', params: { overlay_asset_id: this.overlay.id, position: this.position, scale: this.scale, opacity: this.opacity, ...range } }
                        : { recipe: 'clip', params: { ...range, mode: this.mode } };

                    try {
                        const { data } = await this.$axios.post(this.storeUrl, { ...request, asset_ids: [this.assetId], save_as: this.saveAs });
                        clearInterval(this._tick);
                        this.$refs.clipVideo?.pause();
                        this.$refs.clipModal.close();
                        this.$emitter.emit('dam:open-batch-progress', { batch: data.batch });
                    } catch (error) {
                        const errors = error.response?.data?.errors || {};
                        this.errors = errors;
                        const shown = ['params.start', 'params.end', 'params.overlay_asset_id'];
                        const other = Object.keys(errors).filter(key => ! shown.includes(key));
                        this.generalError = other.length ? errors[other[0]][0] : (Object.keys(errors).length ? '' : (error.response?.data?.message || this.t['start-failed']));
                    } finally {
                        this.submitting = false;
                    }
                },
            },
        });
    </script>
@endPushOnce

<v-dam-batch-clip></v-dam-batch-clip>
