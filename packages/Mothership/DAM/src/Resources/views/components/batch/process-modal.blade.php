@props(['withClip' => false])

{{--
    P6a: the Process dialog and the batch progress panel, one component for the Library's multi-select
    ("Process…") and the viewer's Export menu, which opens it on one file with a recipe already chosen.
    `dam:open-batch-process` opens the settings ({assetIds, recipe?, params?, lockRecipe?}); `dam:open-batch-progress`
    shows a batch another panel started ({batch}). The progress view polls the batch until no item is open.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-batch-process-template">
        <x-admin::modal ref="batchModal" @close="onClosed" @toggle="onToggled">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white" data-dam-batch-title>
                    @{{ title }}
                </p>
            </x-slot>

            <x-slot:content>
                {{-- Settings --}}
                <form v-if="view === 'setup'" class="flex flex-col gap-4 py-1" data-dam-batch-setup @submit.prevent="start">
                    <p v-if="! lockRecipe" class="text-sm text-gray-600 dark:text-slate-300">
                        @{{ t['max-items'].replace(':max', maxItems) }}
                    </p>

                    <div v-if="! lockRecipe">
                        <label for="dam-batch-recipe" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t.recipe }}</label>
                        <select
                            id="dam-batch-recipe"
                            v-model="recipeKey"
                            data-dam-batch-recipe
                            class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200"
                            @change="resetParams()"
                        >
                            <option value="" disabled>@{{ t['choose-recipe'] }}</option>
                            <option v-for="recipe in recipes" :key="recipe.key" :value="recipe.key">@{{ recipe.label }}</option>
                        </select>
                        <p v-if="recipe" class="mt-1 text-xs text-gray-500 dark:text-gray-400">@{{ appliesTo(recipe) }}</p>
                    </div>

                    <div v-if="recipe" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div
                            v-for="field in fields"
                            :key="field.name"
                            :class="field.type === 'asset' || (field.options && field.options.length > 4) ? 'sm:col-span-2' : ''"
                        >
                            <label :for="'dam-batch-' + field.name" class="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">
                                @{{ field.label }}<span v-if="field.required" class="text-red-600" aria-hidden="true"> *</span>
                            </label>

                            <template v-if="field.type === 'asset'">
                                <div class="flex flex-wrap items-center gap-2" data-dam-batch-overlay>
                                    <template v-if="overlay">
                                        <img :src="overlay.url" alt="" class="h-10 w-10 rounded border border-gray-200 bg-[repeating-conic-gradient(#e5e7eb_0%_25%,#fff_0%_50%)] bg-[length:12px_12px] object-contain dark:border-gray-700">
                                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">@{{ overlay.display_name || overlay.file_name }}</span>
                                        <button type="button" class="secondary-button !px-3 !py-1 text-xs" @click="clearOverlay">@{{ t.viewer['remove-watermark'] }}</button>
                                    </template>
                                    <button v-else :id="'dam-batch-' + field.name" type="button" class="secondary-button !px-3 !py-1.5 text-sm" @click="chooseOverlay">@{{ t.viewer['choose-watermark'] }}</button>
                                </div>
                            </template>

                            <select
                                v-else-if="field.type === 'enum'"
                                :id="'dam-batch-' + field.name"
                                v-model="params[field.name]"
                                :data-dam-batch-param="field.name"
                                class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200"
                            >
                                <option v-if="! field.required && field.default === undefined" value="">—</option>
                                <option v-for="option in field.options" :key="option.value" :value="option.value">@{{ option.label }}</option>
                            </select>

                            <div v-else class="relative">
                                <input
                                    :id="'dam-batch-' + field.name"
                                    v-model="params[field.name]"
                                    :data-dam-batch-param="field.name"
                                    type="number"
                                    inputmode="decimal"
                                    :min="field.min"
                                    :max="field.max"
                                    :step="field.integer ? 1 : 'any'"
                                    :placeholder="field.default !== undefined ? String(field.default) : ''"
                                    class="w-full rounded-md border border-gray-300 bg-white py-2 pl-2.5 pr-9 text-sm text-gray-700 dark:border-cherry-700 dark:bg-cherry-900 dark:text-gray-200"
                                    :aria-describedby="errors['params.' + field.name] ? 'dam-batch-error-' + field.name : null"
                                >
                                <span v-if="field.unit" class="pointer-events-none absolute right-2.5 top-2 text-sm text-gray-400" aria-hidden="true">@{{ field.unit }}</span>
                            </div>

                            <p v-if="errors['params.' + field.name]" :id="'dam-batch-error-' + field.name" class="mt-1 text-xs text-red-600" role="alert">@{{ errors['params.' + field.name][0] }}</p>
                        </div>
                    </div>

                    <fieldset v-if="recipe" class="flex flex-col gap-1.5">
                        <legend class="mb-1 text-xs font-medium text-gray-600 dark:text-slate-300">@{{ t['save-as'] }}</legend>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="attach" v-model="saveAs" name="dam-batch-save-as" data-dam-batch-save="attach"> @{{ assetIds.length === 1 ? t['save-attach-one'] : t['save-attach'] }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="new_asset" v-model="saveAs" name="dam-batch-save-as" data-dam-batch-save="new_asset"> @{{ t['save-new-asset'] }}
                        </label>
                    </fieldset>

                    <p v-if="generalError" class="text-sm text-red-600" role="alert" data-dam-batch-error>@{{ generalError }}</p>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t pt-3 dark:border-gray-800">
                        <button type="button" class="secondary-button" @click="close">@{{ t.close }}</button>
                        <button type="submit" class="primary-button" data-dam-batch-start :disabled="! canStart">
                            @{{ submitting ? t.starting : t.start }}
                        </button>
                    </div>
                </form>

                {{-- Progress --}}
                <div v-else-if="batch" class="flex flex-col gap-3 py-1" data-dam-batch-progress :data-dam-batch-status="batch.status">
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">@{{ progressTitle }}</p>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="pillClass(batch.status)">@{{ t.statuses[batch.status] || batch.status }}</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="progressbar" :aria-valuenow="settled" aria-valuemin="0" :aria-valuemax="batch.counts.total">
                            <div class="h-full rounded-full bg-primary-600 transition-all" :style="{ width: percent + '%' }"></div>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">@{{ statusLine }}</p>
                    </div>

                    <ul class="max-h-[50vh] divide-y overflow-y-auto rounded-md border dark:divide-gray-800 dark:border-gray-800" data-dam-batch-items aria-live="polite">
                        <li
                            v-for="item in batch.items"
                            :key="item.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                            :data-dam-batch-item="item.status"
                        >
                            <span class="w-20 shrink-0 rounded-full px-2 py-0.5 text-center text-xs font-semibold" :class="pillClass(item.status)">
                                <span v-if="item.status === 'running'" class="mr-1 inline-block h-2 w-2 animate-pulse rounded-full bg-current align-middle" aria-hidden="true"></span>@{{ t.statuses[item.status] || item.status }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <a v-if="item.asset_url" :href="item.asset_url" class="block truncate text-sm text-gray-800 hover:text-primary-700 hover:underline dark:text-gray-100">@{{ item.asset_name }}</a>
                                <span v-else class="block truncate text-sm italic text-gray-500">@{{ t['hidden-file'] }}</span>
                                <p v-if="item.error_message" class="text-xs text-red-600 dark:text-red-400">@{{ item.error_message }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <a v-if="item.output" :href="item.output.url" target="_blank" rel="noopener" class="text-sm font-semibold text-primary-700 hover:underline dark:text-primary-300" data-dam-batch-output>@{{ t['open-output'] }}</a>
                                <button v-if="item.can_retry" type="button" class="secondary-button !px-3 !py-1 text-xs" :disabled="retrying === item.id" data-dam-batch-retry @click="retry(item)">@{{ t.retry }}</button>
                            </div>
                        </li>
                    </ul>

                    <p v-if="generalError" class="text-sm text-red-600" role="alert">@{{ generalError }}</p>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t pt-3 dark:border-gray-800">
                        <button v-if="hasOpenItems" type="button" class="secondary-button !text-red-600 dark:!text-red-400" data-dam-batch-cancel :disabled="cancelling" @click="cancel">@{{ t.cancel }}</button>
                        <button type="button" class="primary-button" @click="close">@{{ t.close }}</button>
                    </div>
                </div>
            </x-slot>
        </x-admin::modal>
    </script>

    <script type="module">
        app.component('v-dam-batch-process', {
            template: '#v-dam-batch-process-template',

            data() {
                return {
                    t: @js(array_merge(trans('dam::app.admin.batch'), ['process-title-one' => trans_choice('dam::app.admin.batch.process-title', 1, ['count' => ':count']), 'process-title-other' => trans_choice('dam::app.admin.batch.process-title', 2, ['count' => ':count'])])),
                    urls: {
                        recipes: @js(route('admin.dam.batches.recipes')),
                        store: @js(route('admin.dam.batches.store')),
                        show: @js(route('admin.dam.batches.show', ['batch' => '__BATCH__'])),
                        retry: @js(route('admin.dam.batches.items.retry', ['batch' => '__BATCH__', 'item' => '__ITEM__'])),
                        cancel: @js(route('admin.dam.batches.cancel', ['batch' => '__BATCH__'])),
                        assets: @js(route('admin.dam.asset_picker.get_assets')),
                    },
                    recipes: [],
                    maxItems: 200,
                    view: 'setup',
                    assetIds: [],
                    recipeKey: '',
                    lockRecipe: false,
                    params: {},
                    overlay: null,
                    saveAs: 'attach',
                    errors: {},
                    generalError: '',
                    submitting: false,
                    batch: null,
                    retrying: null,
                    cancelling: false,
                    timer: null,
                    failures: 0,
                    announced: {},
                };
            },

            computed: {
                recipe() {
                    return this.recipes.find(recipe => recipe.key === this.recipeKey) || null;
                },

                fields() {
                    return this.recipe ? this.recipe.parameters : [];
                },

                title() {
                    if (this.view === 'progress' && this.batch) {
                        return this.batch.recipe_label;
                    }

                    if (this.lockRecipe && this.recipe) {
                        return this.t['export-title'] + ': ' + this.recipe.label;
                    }

                    return (this.assetIds.length === 1 ? this.t['process-title-one'] : this.t['process-title-other']).replace(':count', this.assetIds.length);
                },

                canStart() {
                    return !! this.recipe && ! this.submitting && this.assetIds.length > 0 && this.assetIds.length <= this.maxItems;
                },

                settled() {
                    const c = this.batch?.counts ?? {};

                    return (c.done ?? 0) + (c.failed ?? 0) + (c.skipped ?? 0) + (c.cancelled ?? 0);
                },

                percent() {
                    const total = this.batch?.counts?.total ?? 0;

                    return total ? Math.round(this.settled / total * 100) : 0;
                },

                progressTitle() {
                    const c = this.batch.counts;

                    return this.t['progress-title']
                        .replace(':recipe', this.batch.output_label || this.batch.recipe_label)
                        .replace(':done', c.done)
                        .replace(':total', c.total);
                },

                statusLine() {
                    const c = this.batch.counts;

                    return this.t['status-line']
                        .replace(':done', c.done).replace(':failed', c.failed)
                        .replace(':skipped', c.skipped).replace(':cancelled', c.cancelled);
                },

                hasOpenItems() {
                    return (this.batch?.items ?? []).some(item => item.status === 'queued' || item.status === 'running');
                },
            },

            mounted() {
                this._onOpen = (payload = {}) => this.open(payload);
                this._onProgress = ({ batch } = {}) => batch && this.showProgress(batch);
                this.$emitter.on('dam:open-batch-process', this._onOpen);
                this.$emitter.on('dam:open-batch-progress', this._onProgress);
            },

            beforeUnmount() {
                clearTimeout(this.timer);
                this.$emitter.off('dam:open-batch-process', this._onOpen);
                this.$emitter.off('dam:open-batch-progress', this._onProgress);
            },

            methods: {
                async loadRecipes() {
                    if (this.recipes.length) {
                        return;
                    }

                    const { data } = await this.$axios.get(this.urls.recipes);
                    this.recipes = data.recipes || [];
                    this.maxItems = data.max_items || this.maxItems;
                },

                async open({ assetIds = [], recipe = '', params = {}, lockRecipe = false } = {}) {
                    this.assetIds = [...new Set((assetIds || []).map(Number).filter(Boolean))];

                    if (! this.assetIds.length) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: this.t['no-assets'] });

                        return;
                    }

                    clearTimeout(this.timer);
                    this.view = 'setup';
                    this.batch = null;
                    this.errors = {};
                    this.generalError = '';
                    this.saveAs = 'attach';
                    this.overlay = null;

                    try {
                        await this.loadRecipes();
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: this.t['start-failed'] });

                        return;
                    }

                    this.recipeKey = this.recipes.some(r => r.key === recipe) ? recipe : '';
                    this.lockRecipe = lockRecipe && !! this.recipeKey;
                    this.resetParams(params);

                    if (this.assetIds.length > this.maxItems) {
                        this.generalError = this.t['too-many'].replace(':count', this.assetIds.length).replace(':max', this.maxItems);
                    }

                    this.$refs.batchModal.open();
                },

                resetParams(preset = {}) {
                    const params = {};

                    for (const field of this.fields) {
                        const value = preset[field.name] ?? field.default;
                        params[field.name] = value === undefined || value === null ? '' : value;
                    }

                    this.params = params;
                    this.overlay = null;
                    this.errors = {};
                },

                appliesTo(recipe) {
                    const names = (recipe.file_types || []).map(type => this.t['type-names'][type] || type);

                    return this.t['applies-to'].replace(':types', names.join(', '));
                },

                payloadParams() {
                    const out = {};

                    for (const field of this.fields) {
                        const value = this.params[field.name];

                        if (field.type === 'asset') {
                            if (this.overlay) out[field.name] = this.overlay.id;
                        } else if (value !== '' && value !== null && value !== undefined) {
                            out[field.name] = value;
                        }
                    }

                    return out;
                },

                async start() {
                    if (! this.canStart) return;

                    this.submitting = true;
                    this.errors = {};
                    this.generalError = '';

                    try {
                        const { data } = await this.$axios.post(this.urls.store, {
                            recipe: this.recipeKey,
                            params: this.payloadParams(),
                            asset_ids: this.assetIds,
                            save_as: this.saveAs,
                        });

                        this.showProgress(data.batch);
                    } catch (error) {
                        const errors = error.response?.data?.errors || {};
                        this.errors = errors;
                        const fieldKeys = this.fields.map(field => 'params.' + field.name);
                        const other = Object.keys(errors).filter(key => ! fieldKeys.includes(key));
                        this.generalError = other.length
                            ? errors[other[0]][0]
                            : (Object.keys(errors).length ? '' : (error.response?.data?.message || this.t['start-failed']));
                    } finally {
                        this.submitting = false;
                    }
                },

                showProgress(batch) {
                    this.batch = batch;
                    this.view = 'progress';
                    this.generalError = '';
                    this.failures = 0;
                    this.announced = {};
                    this.announce(batch);

                    if (! this.$refs.batchModal.isOpen) {
                        this.$refs.batchModal.open();
                    }

                    this.schedule();
                },

                schedule(delay = 1500) {
                    clearTimeout(this.timer);

                    if (this.batch && this.hasOpenItems) {
                        this.timer = setTimeout(() => this.refresh(), delay);
                    }
                },

                async refresh() {
                    if (! this.batch) return;

                    try {
                        const { data } = await this.$axios.get(this.urls.show.replace('__BATCH__', this.batch.id));
                        this.batch = data.batch;
                        this.failures = 0;
                        this.generalError = '';
                        this.announce(data.batch);
                        this.schedule();
                    } catch (error) {
                        if ([401, 403, 404, 419].includes(error.response?.status)) return;

                        this.generalError = this.t['load-failed'];
                        this.schedule(Math.min(15000, 1500 * ++this.failures));
                    }
                },

                {{-- The Files tab and strip refresh when an output lands on the open asset. --}}
                announce(batch) {
                    for (const item of batch.items || []) {
                        if (item.status === 'done' && item.output?.asset_file_id && ! this.announced[item.id]) {
                            this.announced[item.id] = true;
                            this.$emitter.emit('dam-asset-files-updated', { assetId: item.asset_id });
                        }
                    }
                },

                async retry(item) {
                    this.retrying = item.id;

                    try {
                        const { data } = await this.$axios.post(this.urls.retry.replace('__BATCH__', this.batch.id).replace('__ITEM__', item.id));
                        this.batch = data.batch;
                        this.announce(data.batch);
                        this.schedule();
                    } catch (error) {
                        this.generalError = error.response?.data?.message || this.t['load-failed'];
                    } finally {
                        this.retrying = null;
                    }
                },

                async cancel() {
                    this.cancelling = true;

                    try {
                        const { data } = await this.$axios.post(this.urls.cancel.replace('__BATCH__', this.batch.id));
                        this.batch = data.batch;
                        this.schedule();
                    } catch (error) {
                        this.generalError = error.response?.data?.message || this.t['load-failed'];
                    } finally {
                        this.cancelling = false;
                    }
                },

                chooseOverlay() {
                    this.$emitter.emit('dam-asset-picker:open', {
                        ids: [],
                        onAssign: ids => this.pickOverlay(ids),
                    });
                },

                async pickOverlay(ids) {
                    const id = Number((ids || [])[0]);

                    if (! id) return;

                    try {
                        const { data } = await this.$axios.get(this.urls.assets, { params: { assetIds: String(id) } });
                        const asset = (data || [])[0];
                        this.overlay = asset ? { id: Number(asset.id), url: asset.url, file_name: asset.file_name, display_name: asset.display_name } : null;
                    } catch (error) {
                        this.overlay = null;
                    }
                },

                clearOverlay() {
                    this.overlay = null;
                },

                pillClass(status) {
                    return {
                        done: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                        finished: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                        failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                        running: 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-200',
                        queued: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                        skipped: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
                        cancelled: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                    }[status] || 'bg-gray-100 text-gray-700';
                },

                close() {
                    this.$refs.batchModal.close();
                },

                {{-- Closing stops polling; the batch keeps running on the server. --}}
                onClosed() {
                    clearTimeout(this.timer);
                },

                onToggled({ isActive } = {}) {
                    if (! isActive) this.onClosed();
                },
            },
        });
    </script>
@endPushOnce

@if ($withClip)
    @include('dam::components.batch.clip-panel')
@endif

<v-dam-batch-process></v-dam-batch-process>

{{-- The watermark chooser opens the Library asset picker; one instance per page. --}}
@once('dam-batch-asset-picker')
    @include('dam::asset.picker-modal')

    <v-dam-asset-picker></v-dam-asset-picker>
@endonce
