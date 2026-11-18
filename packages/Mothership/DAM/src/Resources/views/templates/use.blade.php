@php
    $usePage = trans('dam::app.admin.templates.use-page');
    $refusals = trans('dam::app.admin.templates.refusals');
@endphp

<x-admin::layouts>
    <x-slot:title>
        {{ $template['name'] }} · @lang('dam::app.admin.templates.use-page.title')
    </x-slot:title>

    {{-- Image slots take Library images the filler can open, chosen in the picker; it offers no upload here. --}}
    @include('dam::asset.picker-modal')

    <v-dam-asset-picker></v-dam-asset-picker>

    @if ($canBrowse)
        @include('dam::components.explorer.view-toggle')
        @include('dam::components.explorer.folder-picker')
    @endif

    <v-dam-template-fill></v-dam-template-fill>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-template-fill-template">
            <div data-dam-template-fill>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.templates.use-page.title').': '.$template['name']"
                    :description="$template['description']"
                    :parents="[['label' => trans('dam::app.admin.templates.title'), 'url' => route('admin.dam.templates.index')]]"
                />

                <p v-if="prefill" class="mt-3 rounded-md bg-primary-50 px-3 py-2 text-sm text-primary-800 dark:bg-cherry-800 dark:text-gray-200" data-dam-template-reopened v-text="labels.reopened"></p>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
                    <form class="flex min-w-0 flex-col gap-3 rounded-lg bg-white p-4 box-shadow dark:bg-cherry-900" novalidate data-dam-template-fill-form @submit.prevent="create">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white" v-text="labels.form"></h2>

                        <div v-for="slot in slots" :key="slot.id" class="flex flex-col gap-1" :data-dam-template-field="slot.id">
                            <label :for="'tpl-slot-' + slot.id" class="flex items-baseline justify-between gap-2 text-sm font-medium text-gray-800 dark:text-white">
                                <span v-text="slot.label"></span>
                                <span class="text-xs font-normal text-gray-500 dark:text-gray-400" v-text="slot.required ? labels.required : labels.optional"></span>
                            </label>

                            <template v-if="slot.type === 'text'">
                                <textarea
                                    v-if="slot.constraints.multiline"
                                    :id="'tpl-slot-' + slot.id"
                                    v-model="values[slot.id]"
                                    rows="3"
                                    :maxlength="slot.constraints.max_length"
                                    class="dam-tpl-input"
                                    :aria-invalid="refusal.slot === slot.id ? 'true' : 'false'"
                                    @input="changed"
                                ></textarea>
                                <input
                                    v-else
                                    :id="'tpl-slot-' + slot.id"
                                    v-model="values[slot.id]"
                                    type="text"
                                    :maxlength="slot.constraints.max_length"
                                    :placeholder="slot.sample || ''"
                                    class="dam-tpl-input"
                                    :aria-invalid="refusal.slot === slot.id ? 'true' : 'false'"
                                    @input="changed"
                                >
                                <span class="text-xs text-gray-500 dark:text-gray-400" v-text="labels.chars.replace(':count', (values[slot.id] || '').length).replace(':max', slot.constraints.max_length)"></span>
                            </template>

                            <template v-else-if="slot.type === 'image'">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex h-12 w-12 items-center justify-center overflow-hidden rounded border bg-gray-50 dark:border-cherry-800 dark:bg-cherry-800">
                                        <img v-if="thumbs[values[slot.id]]" :src="thumbs[values[slot.id]].url" alt="" class="h-full w-full object-cover">
                                        <span v-else class="icon-dam-preview text-xl text-gray-400" aria-hidden="true"></span>
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300" v-text="imageName(values[slot.id])"></span>
                                    <button :id="'tpl-slot-' + slot.id" type="button" class="secondary-button" data-dam-template-choose-image @click="chooseImage(slot)" v-text="values[slot.id] ? labels['change-image'] : labels['choose-image']"></button>
                                    <button v-if="values[slot.id]" type="button" class="transparent-button" @click="values[slot.id] = null; changed()" v-text="labels.clear"></button>
                                </div>
                            </template>

                            <template v-else>
                                <select :id="'tpl-slot-' + slot.id" v-model="values[slot.id]" class="dam-tpl-input" @change="changed">
                                    <option value="" v-text="labels.choose"></option>
                                    <option v-for="option in slot.constraints.options" :key="option.value" :value="option.value" v-text="option.label"></option>
                                </select>
                            </template>

                            <p v-if="refusal.slot === slot.id" class="text-xs text-red-600 dark:text-red-400" role="alert" v-text="refusal.message"></p>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400" v-text="labels['library-only']"></p>

                        <div class="border-t pt-3 dark:border-cherry-800">
                            <label for="tpl-name" class="block text-sm font-medium text-gray-800 dark:text-white" v-text="labels.name"></label>
                            <input id="tpl-name" v-model="name" type="text" maxlength="180" :placeholder="template.name" class="dam-tpl-input">

                            <span class="mt-3 block text-sm font-medium text-gray-800 dark:text-white" v-text="labels.folder"></span>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-300" data-dam-template-folder v-text="folder ? folder.name : labels['no-folder']"></span>
                                @if ($canBrowse)
                                    <button type="button" class="secondary-button" @click="folderPickerOpen = true" v-text="labels['choose-folder']"></button>
                                @endif
                            </div>
                        </div>

                        <p v-if="refusal.message && ! refusal.slot" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300" role="alert" data-dam-template-refusal v-text="refusal.message"></p>

                        <p v-if="! canUpload" class="text-sm text-amber-700 dark:text-amber-300" v-text="labels['no-upload']"></p>

                        <div v-if="created" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200" role="status" data-dam-template-created>
                            <span v-text="created.message"></span>
                            <a :href="created.url" class="ms-1 font-semibold underline" v-text="labels['open-asset']"></a>
                        </div>

                        <button type="submit" class="primary-button justify-center" data-dam-template-create :disabled="busy || ! canUpload || ! folder" v-text="busy ? labels.creating : labels.create"></button>
                    </form>

                    <section class="min-w-0 rounded-lg bg-white p-4 box-shadow dark:bg-cherry-900" aria-live="polite">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-white" v-text="labels.preview"></h2>
                            <span v-if="rendering" class="text-xs text-gray-500 dark:text-gray-400" v-text="labels.rendering"></span>
                        </div>
                        <div class="flex justify-center rounded-md bg-[repeating-conic-gradient(#e5e7eb_0%_25%,#fff_0%_50%)] bg-[length:16px_16px] p-2 dark:bg-[repeating-conic-gradient(#2c2f33_0%_25%,#1f2125_0%_50%)]">
                            <img v-if="previewUrl" :src="previewUrl" alt="" style="max-height: 75vh" class="block h-auto w-auto max-w-full transition-opacity" :class="rendering ? 'opacity-60' : ''" data-dam-template-live-preview>
                            <img v-else-if="template.layer_url" :src="template.layer_url" alt="" style="max-height: 75vh" class="block h-auto w-auto max-w-full opacity-60">
                        </div>
                    </section>
                </div>

                @if ($canBrowse)
                    <v-dam-folder-picker :open="folderPickerOpen" tab-id="template-fill-folder" @picked="pickFolder" @close="folderPickerOpen = false"></v-dam-folder-picker>
                @endif
            </div>
        </script>

        <style>
            .dam-tpl-input { margin-top: .25rem; display: block; width: 100%; border-radius: .375rem; border-width: 1px; padding: .375rem .5rem; font-size: .875rem; color: rgb(55 65 81); background: #fff; }
            .dark .dam-tpl-input { border-color: #2c2f33; background: #1f2125; color: rgb(209 213 219); }
            .dam-tpl-input[aria-invalid="true"] { border-color: rgb(220 38 38); }
        </style>

        <script type="module">
            app.component('v-dam-template-fill', {
                template: '#v-dam-template-fill-template',

                data() {
                    const template = @js($template);
                    const prefill = @js($prefill);
                    const values = {};

                    for (const slot of template.published?.slots ?? []) {
                        const earlier = prefill?.values?.[slot.id];
                        values[slot.id] = earlier ?? (slot.type === 'image' ? null : '');
                    }

                    return {
                        template,
                        prefill,
                        slots: template.published?.slots ?? [],
                        version: template.published?.version ?? null,
                        values,
                        name: '',
                        folder: @js($folder) ?? template.output_directory,
                        folderPickerOpen: false,
                        thumbs: {},
                        previewUrl: null,
                        rendering: false,
                        busy: false,
                        refusal: { message: '', slot: null },
                        created: null,
                        canUpload: @js($canUpload),
                        canThumbs: @js($canThumbs),
                        labels: @js($usePage),
                        refusals: @js($refusals),
                    };
                },

                mounted() {
                    this.loadThumbs(Object.values(this.values).filter(v => Number.isInteger(v)));
                    this.renderPreview();
                },

                beforeUnmount() {
                    if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                },

                methods: {
                    // Only what the filler set: slot values, never the layout or a region.
                    payload() {
                        const values = {};

                        for (const [id, value] of Object.entries(this.values)) {
                            if (value !== null && value !== '') values[id] = value;
                        }

                        return { version: this.version, values };
                    },

                    changed() {
                        this.created = null;
                        clearTimeout(this._timer);
                        this._timer = setTimeout(() => this.renderPreview(), 350);
                    },

                    async refusalFrom(error) {
                        let data = error?.response?.data;

                        if (data instanceof Blob) {
                            try { data = JSON.parse(await data.text()); } catch { data = null; }
                        }

                        if (data?.code) return { message: this.refusals[data.code] ?? data.message, slot: data.slot ?? null };
                        if (data?.errors) return { message: Object.values(data.errors).flat()[0], slot: null };

                        return { message: data?.message || @js(trans('dam::app.admin.templates.action-failed')), slot: null };
                    },

                    // The server renders exactly what Create would save, at preview size; a refusal shows beside its slot.
                    async renderPreview() {
                        const ticket = this._ticket = {};
                        this.rendering = true;

                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.use.preview', $template['id'])), this.payload(), { responseType: 'blob' });
                            if (this._ticket !== ticket) return;
                            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                            this.previewUrl = URL.createObjectURL(data);
                            this.refusal = { message: '', slot: null };
                        } catch (error) {
                            if (this._ticket !== ticket) return;
                            this.refusal = await this.refusalFrom(error);
                        } finally {
                            if (this._ticket === ticket) this.rendering = false;
                        }
                    },

                    chooseImage(slot) {
                        this.$emitter.emit('dam-asset-picker:open', {
                            ids: this.values[slot.id] ? [this.values[slot.id]] : [],
                            choose: true,
                            onAssign: (ids) => {
                                // The current image comes pre-selected; picking another alongside it replaces it.
                                const others = ids.map(Number).filter(id => id !== this.values[slot.id]);
                                const id = others.length ? others[0] : Number(ids[0]);

                                if (! id || others.length > 1) {
                                    this.refusal = { message: this.labels['pick-one'], slot: slot.id };
                                    return;
                                }

                                this.values[slot.id] = Number(id);
                                this.loadThumbs([Number(id)]);
                                this.changed();
                            },
                        });
                    },

                    loadThumbs(ids) {
                        if (! this.canThumbs || ! ids.length) return;

                        this.$axios.get(@js(route('admin.dam.asset_picker.get_assets')), { params: { assetIds: ids.join(',') } })
                            .then(({ data }) => { for (const asset of data) this.thumbs[asset.id] = asset; })
                            .catch(() => {});
                    },

                    imageName(id) {
                        if (! id) return this.labels['no-image'];

                        return this.thumbs[id]?.display_name || this.thumbs[id]?.file_name || '#' + id;
                    },

                    pickFolder(folder) {
                        this.folder = { id: folder.id, name: folder.name };
                        this.folderPickerOpen = false;
                    },

                    async create() {
                        if (this.busy) return;
                        this.busy = true;
                        this.created = null;
                        this.refusal = { message: '', slot: null };

                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.assets.store', $template['id'])), {
                                ...this.payload(),
                                directory_id: this.folder?.id ?? null,
                                name: this.name || undefined,
                            });

                            this.created = { message: data.message, url: data.asset.url };
                        } catch (error) {
                            this.refusal = await this.refusalFrom(error);
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
