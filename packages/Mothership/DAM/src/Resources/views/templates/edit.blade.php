@php
    // The image editor in template mode keeps the asset editor's labels.
    $imageEditorLabels = array_replace(trans('dam::image-editor'), [
        'Close image editor' => trans('dam::app.admin.dam.asset.edit.image-editor.close'),
        'Adjustments'        => trans('dam::app.admin.dam.asset.edit.image-editor.adjustments'),
        'brightness'         => trans('dam::app.admin.dam.asset.edit.image-editor.brightness'),
        'contrast'           => trans('dam::app.admin.dam.asset.edit.image-editor.contrast'),
        'width'              => trans('dam::app.admin.dam.asset.edit.image-editor.width-px'),
        'height'             => trans('dam::app.admin.dam.asset.edit.image-editor.height-px'),
    ]);
    $editPage = trans('dam::app.admin.templates.edit-page');
@endphp

<x-admin::layouts>
    <x-slot:title>
        {{ $template['name'] }} · @lang('dam::app.admin.templates.title')
    </x-slot:title>

    {{-- Choice options with pictures use Library images the author can open; never a device upload. --}}
    @include('dam::asset.picker-modal')

    <v-dam-asset-picker></v-dam-asset-picker>

    @include('dam::components.explorer.view-toggle')
    @include('dam::components.explorer.folder-picker')

    <v-dam-template-editor></v-dam-template-editor>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-template-editor-template">
            <div data-dam-template-editor>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <x-admin::layouts.page-header
                        :title="$template['name']"
                        :parents="[['label' => trans('dam::app.admin.templates.edit-page.back'), 'url' => route('admin.dam.templates.index')]]"
                    />

                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="secondary-button" data-dam-template-layout :disabled="busy" @click="openLayout">
                            @lang('dam::app.admin.templates.edit-page.layout')
                        </button>
                        <button type="button" class="secondary-button" data-dam-template-preview :disabled="busy || ! slots.length" @click="togglePreview">
                            <span v-text="previewUrl ? labels['hide-preview'] : labels.preview"></span>
                        </button>
                        <button type="button" class="secondary-button" data-dam-template-save :disabled="busy || ! dirty" @click="save()">
                            @lang('dam::app.admin.templates.edit-page.save')
                        </button>
                        <button type="button" class="primary-button" data-dam-template-publish :disabled="busy || ! canPublish" @click="publish">
                            @lang('dam::app.admin.templates.edit-page.publish')
                        </button>
                    </div>
                </div>

                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300" data-dam-template-state>
                    <span v-if="template.published_version" v-text="labels['status-published'].replace(':version', template.published_version)"></span>
                    <span v-if="template.draft_version" class="font-semibold text-amber-700 dark:text-amber-300" v-text="labels['status-draft'].replace(':version', template.draft_version)"></span>
                    <span v-text="labels.canvas.replace(':width', canvas.width).replace(':height', canvas.height)"></span>
                    <span v-if="dirty" class="font-semibold text-primary-700 dark:text-primary-300" v-text="labels.unsaved"></span>
                </p>

                <p v-if="error" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300" role="alert" data-dam-template-error v-text="error"></p>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <!-- The canvas: the locked layer with every slot drawn over it, in canvas pixels scaled to the box. -->
                    <section class="min-w-0 rounded-lg bg-white p-3 box-shadow dark:bg-cherry-900">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-white" v-text="previewUrl ? labels.previewing : labels.locked"></h2>
                            <button v-if="! previewUrl" type="button" class="secondary-button" data-dam-template-draw :aria-pressed="drawing ? 'true' : 'false'" :class="{ 'ring-2 ring-primary-500': drawing }" @click="drawing = ! drawing">
                                <span v-text="drawing ? labels.drawing : labels['add-slot']"></span>
                            </button>
                        </div>

                        <div class="flex justify-center rounded-md bg-[repeating-conic-gradient(#e5e7eb_0%_25%,#fff_0%_50%)] bg-[length:16px_16px] p-2 dark:bg-[repeating-conic-gradient(#2c2f33_0%_25%,#1f2125_0%_50%)]">
                            <img v-if="previewUrl" :src="previewUrl" alt="" style="max-height: 75vh" class="block h-auto w-auto max-w-full" data-dam-template-preview-image>

                            <div
                                v-else
                                ref="stage"
                                class="relative w-full touch-none select-none"
                                :style="{ maxWidth: 'min(' + canvas.width + 'px, calc(75vh * ' + (canvas.width / canvas.height) + '))', aspectRatio: canvas.width + ' / ' + canvas.height }"
                                :class="drawing ? 'cursor-crosshair' : ''"
                                data-dam-template-stage
                                @pointerdown="stageDown"
                            >
                                <img v-if="layerUrl" :src="layerUrl" alt="" draggable="false" class="absolute inset-0 h-full w-full">

                                <div
                                    v-for="(slot, index) in slots"
                                    :key="slot._key"
                                    class="absolute border-2"
                                    :class="index === selected ? 'border-primary-600 bg-primary-500/25' : 'border-dashed border-primary-400 bg-primary-400/15'"
                                    :style="boxStyle(slot.region)"
                                    data-dam-template-slot
                                    @pointerdown.stop="slotDown($event, index, 'move')"
                                >
                                    <span class="pointer-events-none absolute left-0 top-0 max-w-full truncate bg-primary-600 px-1 text-[11px] font-semibold leading-4 text-white" v-text="slot.label"></span>
                                    <span
                                        class="absolute -bottom-1.5 -right-1.5 h-3 w-3 cursor-se-resize rounded-sm border border-white bg-primary-600"
                                        aria-hidden="true"
                                        @pointerdown.stop="slotDown($event, index, 'resize')"
                                    ></span>
                                </div>

                                <div v-if="draft" class="pointer-events-none absolute border-2 border-primary-600 bg-primary-500/20" :style="boxStyle(draft)"></div>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" v-text="labels['layout-hint']"></p>
                    </section>

                    <aside class="flex min-w-0 flex-col gap-4">
                        <!-- Slots -->
                        <section class="rounded-lg bg-white p-3 box-shadow dark:bg-cherry-900" data-dam-template-slots>
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-white" v-text="labels.slots"></h2>

                            <p v-if="! slots.length" class="mt-2 text-sm text-gray-500 dark:text-gray-400" v-text="labels['no-slots']"></p>

                            <ul v-else class="mt-2 flex flex-col gap-1">
                                <li v-for="(slot, index) in slots" :key="slot._key">
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                                        :class="index === selected ? 'bg-primary-50 font-semibold text-primary-800 dark:bg-cherry-800 dark:text-white' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-cherry-800'"
                                        @click="selected = index"
                                    >
                                        <span class="truncate" v-text="slot.label"></span>
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" v-text="labels.types[slot.type]"></span>
                                    </button>
                                </li>
                            </ul>

                            <!-- The selected slot -->
                            <div v-if="current" class="mt-3 flex flex-col gap-2.5 border-t pt-3 text-sm dark:border-cherry-800" data-dam-template-slot-form>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['slot-label']"></span>
                                        <input v-model="current.label" type="text" maxlength="191" class="dam-tpl-input" @input="touch">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['slot-id']"></span>
                                        <input v-model="current.id" type="text" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_\-]*" class="dam-tpl-input" @input="touch">
                                    </label>
                                </div>

                                <div class="grid grid-cols-2 items-end gap-2">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['slot-type']"></span>
                                        <select :value="current.type" class="dam-tpl-input" @change="changeType($event.target.value)">
                                            <option v-for="(name, type) in labels.types" :key="type" :value="type" v-text="name"></option>
                                        </select>
                                    </label>
                                    <label class="flex items-center gap-2 pb-2">
                                        <input v-model="current.required" type="checkbox" @change="touch">
                                        <span v-text="labels.required"></span>
                                    </label>
                                </div>

                                <fieldset>
                                    <legend class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.region"></legend>
                                    <div class="mt-1 grid grid-cols-4 gap-1.5">
                                        <label v-for="axis in ['x', 'y', 'w', 'h']" :key="axis" class="block">
                                            <span class="text-[11px] text-gray-500 dark:text-gray-400" v-text="labels[axis]"></span>
                                            <input v-model.number="current.region[axis]" type="number" min="0" step="1" class="dam-tpl-input" @change="clampRegion(current)">
                                        </label>
                                    </div>
                                </fieldset>

                                <!-- Text style (text slots and text choices) -->
                                <template v-if="current.type === 'text' || current.type === 'choice'">
                                    <div v-if="current.type === 'text'" class="grid grid-cols-2 gap-2">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['max-length']"></span>
                                            <input v-model.number="current.constraints.max_length" type="number" min="1" max="10000" class="dam-tpl-input" @input="touch">
                                        </label>
                                        <label class="flex items-center gap-2 pt-4">
                                            <input v-model="current.constraints.multiline" type="checkbox" @change="touch">
                                            <span v-text="labels.multiline"></span>
                                        </label>
                                    </div>

                                    <div v-if="current.type === 'text' || current.constraints.kind === 'text'" class="flex flex-col gap-2">
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="block">
                                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['font-min']"></span>
                                                <input v-model.number="current.constraints.font_size_min" type="number" min="6" max="1000" class="dam-tpl-input" @input="touch">
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['font-max']"></span>
                                                <input v-model.number="current.constraints.font_size_max" type="number" min="6" max="1000" class="dam-tpl-input" @input="touch">
                                            </label>
                                        </div>
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.overflow"></span>
                                            <select v-model="current.constraints.overflow" class="dam-tpl-input" @change="touch">
                                                <option value="shrink" v-text="labels['overflow-shrink']"></option>
                                                <option value="refuse" v-text="labels['overflow-refuse']"></option>
                                            </select>
                                        </label>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="block">
                                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.color"></span>
                                                <input v-model="current.constraints.color" type="color" class="mt-1 h-9 w-full rounded-md border dark:border-cherry-800" @change="touch">
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.align"></span>
                                                <select v-model="current.constraints.align" class="dam-tpl-input" @change="touch">
                                                    <option value="left" v-text="labels['align-left']"></option>
                                                    <option value="center" v-text="labels['align-center']"></option>
                                                    <option value="right" v-text="labels['align-right']"></option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>
                                </template>

                                <!-- Image slots -->
                                <div v-if="current.type === 'image'" class="grid grid-cols-3 gap-2">
                                    <label class="col-span-3 block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.fit"></span>
                                        <select v-model="current.constraints.fit" class="dam-tpl-input" @change="touch">
                                            <option value="cover" v-text="labels['fit-cover']"></option>
                                            <option value="contain" v-text="labels['fit-contain']"></option>
                                        </select>
                                    </label>
                                    <label class="col-span-3 block sm:col-span-1 lg:col-span-3 xl:col-span-1">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['min-width']"></span>
                                        <input v-model.number="current.constraints.min_width" type="number" min="0" max="8192" class="dam-tpl-input" @input="touch">
                                    </label>
                                    <label class="col-span-3 block sm:col-span-1 lg:col-span-3 xl:col-span-1">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['min-height']"></span>
                                        <input v-model.number="current.constraints.min_height" type="number" min="0" max="8192" class="dam-tpl-input" @input="touch">
                                    </label>
                                </div>

                                <!-- Choice options -->
                                <template v-if="current.type === 'choice'">
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['choice-kind']"></span>
                                        <select :value="current.constraints.kind" class="dam-tpl-input" @change="changeKind($event.target.value)">
                                            <option value="text" v-text="labels['kind-text']"></option>
                                            <option value="color" v-text="labels['kind-color']"></option>
                                            <option value="image" v-text="labels['kind-image']"></option>
                                        </select>
                                    </label>

                                    <fieldset class="flex flex-col gap-2" data-dam-template-options>
                                        <legend class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.options"></legend>
                                        <div v-for="(option, o) in current.constraints.options" :key="o" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-1.5 rounded-md border p-2 dark:border-cherry-800">
                                            <label class="block">
                                                <span class="text-[11px] text-gray-500 dark:text-gray-400" v-text="labels['option-label']"></span>
                                                <input v-model="option.label" type="text" maxlength="191" class="dam-tpl-input" @input="touch">
                                            </label>
                                            <label class="block">
                                                <span class="text-[11px] text-gray-500 dark:text-gray-400" v-text="labels['option-value']"></span>
                                                <input v-model="option.value" type="text" maxlength="64" class="dam-tpl-input" @input="touch">
                                            </label>
                                            <button type="button" class="icon-cancel rounded-md p-1 text-xl text-gray-500 hover:bg-gray-100 dark:hover:bg-cherry-800" :aria-label="labels['remove-option']" :disabled="current.constraints.options.length < 2" @click="removeOption(o)"></button>

                                            <label v-if="current.constraints.kind === 'text'" class="col-span-3 block">
                                                <span class="text-[11px] text-gray-500 dark:text-gray-400" v-text="labels['option-text']"></span>
                                                <input v-model="option.text" type="text" class="dam-tpl-input" @input="touch">
                                            </label>
                                            <label v-else-if="current.constraints.kind === 'color'" class="col-span-3 flex items-center gap-2">
                                                <span class="text-[11px] text-gray-500 dark:text-gray-400" v-text="labels['option-color']"></span>
                                                <input v-model="option.color" type="color" class="h-8 w-14 rounded-md border dark:border-cherry-800" @change="touch">
                                            </label>
                                            <div v-else class="col-span-3 flex items-center gap-2">
                                                <img v-if="option.image_url" :src="option.image_url" alt="" class="h-10 w-10 rounded object-cover">
                                                <button type="button" class="secondary-button" @click="chooseOptionImage(option)" v-text="labels['choose-image']"></button>
                                            </div>
                                        </div>
                                        <button v-if="current.constraints.options.length < 50" type="button" class="secondary-button self-start" @click="addOption" v-text="labels['add-option']"></button>
                                    </fieldset>
                                </template>

                                <label v-if="current.type !== 'image'" class="block">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.sample"></span>
                                    <input v-model="current.sample" type="text" class="dam-tpl-input" @input="touch">
                                </label>

                                <button type="button" class="transparent-button self-start text-red-600 dark:text-red-400" @click="removeSlot" v-text="labels['remove-slot']"></button>
                            </div>
                        </section>

                        <!-- Details -->
                        <section class="rounded-lg bg-white p-3 box-shadow dark:bg-cherry-900" data-dam-template-details>
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-white" v-text="labels.details"></h2>
                            <label class="mt-2 block text-sm">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.name"></span>
                                <input v-model="details.name" type="text" maxlength="191" class="dam-tpl-input" @input="touch">
                            </label>
                            <label class="mt-2 block text-sm">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels.description"></span>
                                <textarea v-model="details.description" rows="2" maxlength="2000" class="dam-tpl-input" @input="touch"></textarea>
                            </label>
                            <div class="mt-2 text-sm">
                                <span class="block text-xs font-medium text-gray-600 dark:text-gray-300" v-text="labels['default-folder']"></span>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span class="truncate text-gray-700 dark:text-gray-300" v-text="details.folder ? details.folder.name : labels['no-folder']"></span>
                                    <button type="button" class="secondary-button" @click="folderPickerOpen = true" v-text="labels['choose-folder']"></button>
                                </div>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2 border-t pt-3 dark:border-cherry-800">
                                <button v-if="template.status === 'published'" type="button" class="secondary-button" :disabled="busy" @click="archive" v-text="labels.archive"></button>
                                <button type="button" class="transparent-button text-red-600 dark:text-red-400" :disabled="busy" @click="confirmDelete" v-text="labels.delete"></button>
                            </div>
                        </section>
                    </aside>
                </div>

                <v-dam-folder-picker :open="folderPickerOpen" tab-id="template-default-folder" @picked="pickFolder" @close="folderPickerOpen = false"></v-dam-folder-picker>

                <div v-if="editorOpen" ref="editorHost"></div>
            </div>
        </script>

        <style>
            .dam-tpl-input { margin-top: .25rem; display: block; width: 100%; border-radius: .375rem; border-width: 1px; padding: .375rem .5rem; font-size: .875rem; color: rgb(55 65 81); background: #fff; }
            .dark .dam-tpl-input { border-color: #2c2f33; background: #1f2125; color: rgb(209 213 219); }
        </style>

        <script type="module">
            const defaults = {
                text: { max_length: 120, font_size_min: 12, font_size_max: 48, multiline: false, overflow: 'shrink', color: '#111827', align: 'left' },
                image: { fit: 'cover', min_width: 0, min_height: 0 },
                choice: { kind: 'text', options: [{ value: 'option-1', label: 'Option 1', text: 'Option 1' }], font_size_min: 12, font_size_max: 48, multiline: false, overflow: 'shrink', color: '#111827', align: 'left' },
            };
            const clone = (value) => JSON.parse(JSON.stringify(value));
            let keySeq = 0;

            app.component('v-dam-template-editor', {
                template: '#v-dam-template-editor-template',

                data() {
                    return {
                        template: {},
                        canvas: { width: 1, height: 1 },
                        layerUrl: null,
                        slots: [],
                        details: { name: '', description: '', folder: null },
                        selected: null,
                        drawing: false,
                        draft: null,
                        dirty: false,
                        busy: false,
                        error: '',
                        previewUrl: null,
                        folderPickerOpen: false,
                        editorOpen: false,
                        labels: @js($editPage),
                    };
                },

                computed: {
                    current() {
                        return this.selected === null ? null : this.slots[this.selected] ?? null;
                    },

                    canPublish() {
                        return this.slots.length > 0 && (this.dirty || this.template.draft_version || this.template.status !== 'published');
                    },
                },

                created() {
                    this.apply(@js($template));
                },

                beforeUnmount() {
                    this.dropPreview();
                    this._unmountEditor?.();
                },

                methods: {
                    // The latest version: the open draft, or the published one.
                    apply(template) {
                        const working = template.draft ?? template.published;

                        this.template = template;
                        this.canvas = working?.canvas ?? { width: 1, height: 1 };
                        this.layerUrl = working?.layer_url ?? null;
                        this.slots = clone(working?.slots ?? []).map(slot => ({ ...slot, _key: ++keySeq }));
                        this.details = { name: template.name, description: template.description ?? '', folder: template.output_directory };
                        this.selected = this.slots.length ? Math.min(this.selected ?? 0, this.slots.length - 1) : null;
                        this.dirty = false;
                    },

                    touch() {
                        this.dirty = true;
                        this.dropPreview();
                    },

                    // ---- canvas geometry: canvas pixels <-> the scaled box ----
                    boxStyle(region) {
                        return {
                            left: (region.x / this.canvas.width * 100) + '%',
                            top: (region.y / this.canvas.height * 100) + '%',
                            width: (region.w / this.canvas.width * 100) + '%',
                            height: (region.h / this.canvas.height * 100) + '%',
                        };
                    },

                    point(event) {
                        const box = this.$refs.stage.getBoundingClientRect();
                        const clamp = (v, max) => Math.max(0, Math.min(max, v));

                        return {
                            x: clamp(Math.round((event.clientX - box.left) / box.width * this.canvas.width), this.canvas.width),
                            y: clamp(Math.round((event.clientY - box.top) / box.height * this.canvas.height), this.canvas.height),
                        };
                    },

                    track(onMove, onUp) {
                        const move = (e) => onMove(this.point(e));
                        const up = (e) => {
                            window.removeEventListener('pointermove', move);
                            window.removeEventListener('pointerup', up);
                            onUp?.(this.point(e));
                        };
                        window.addEventListener('pointermove', move);
                        window.addEventListener('pointerup', up);
                    },

                    stageDown(event) {
                        if (! this.drawing) {
                            this.selected = null;
                            return;
                        }

                        event.preventDefault();
                        const start = this.point(event);
                        const rect = (p) => ({ x: Math.min(start.x, p.x), y: Math.min(start.y, p.y), w: Math.abs(p.x - start.x), h: Math.abs(p.y - start.y) });

                        this.draft = rect(start);
                        this.track(p => { this.draft = rect(p); }, p => {
                            const region = rect(p);
                            this.draft = null;

                            if (region.w >= 4 && region.h >= 4) this.addSlot(region);
                        });
                    },

                    slotDown(event, index, mode) {
                        if (this.drawing) return;
                        event.preventDefault();
                        this.selected = index;
                        const slot = this.slots[index];
                        const start = this.point(event);
                        const from = { ...slot.region };

                        this.track(p => {
                            const dx = p.x - start.x;
                            const dy = p.y - start.y;

                            if (mode === 'move') {
                                slot.region.x = Math.max(0, Math.min(this.canvas.width - from.w, from.x + dx));
                                slot.region.y = Math.max(0, Math.min(this.canvas.height - from.h, from.y + dy));
                            } else {
                                slot.region.w = Math.max(4, Math.min(this.canvas.width - from.x, from.w + dx));
                                slot.region.h = Math.max(4, Math.min(this.canvas.height - from.y, from.h + dy));
                            }

                            if (dx || dy) this.touch();
                        });
                    },

                    clampRegion(slot) {
                        const r = slot.region;
                        r.w = Math.max(1, Math.min(this.canvas.width, Math.round(Number(r.w) || 1)));
                        r.h = Math.max(1, Math.min(this.canvas.height, Math.round(Number(r.h) || 1)));
                        r.x = Math.max(0, Math.min(this.canvas.width - r.w, Math.round(Number(r.x) || 0)));
                        r.y = Math.max(0, Math.min(this.canvas.height - r.h, Math.round(Number(r.y) || 0)));
                        this.touch();
                    },

                    // ---- slots ----
                    addSlot(region) {
                        let n = this.slots.length + 1;
                        while (this.slots.some(s => s.id.toLowerCase() === 'slot-' + n)) n++;

                        this.slots.push({ _key: ++keySeq, id: 'slot-' + n, type: 'text', label: this.labels.slot + ' ' + n, region, required: false, constraints: clone(defaults.text), sample: '' });
                        this.selected = this.slots.length - 1;
                        this.drawing = false;
                        this.touch();
                    },

                    removeSlot() {
                        this.slots.splice(this.selected, 1);
                        this.selected = this.slots.length ? Math.max(0, this.selected - 1) : null;
                        this.touch();
                    },

                    changeType(type) {
                        this.current.type = type;
                        this.current.constraints = clone(defaults[type]);
                        if (type === 'image') delete this.current.sample;
                        else this.current.sample = this.current.sample ?? '';
                        this.touch();
                    },

                    changeKind(kind) {
                        const c = this.current.constraints;
                        c.kind = kind;
                        c.options = c.options.map(o => ({ value: o.value, label: o.label, ...(kind === 'text' ? { text: o.text ?? o.label } : kind === 'color' ? { color: o.color ?? '#2563eb' } : { resource: o.resource ?? null, image_url: o.image_url ?? null }) }));
                        this.touch();
                    },

                    addOption() {
                        const c = this.current.constraints;
                        let n = c.options.length + 1;
                        while (c.options.some(o => o.value === 'option-' + n)) n++;
                        const option = { value: 'option-' + n, label: 'Option ' + n };
                        c.options.push(c.kind === 'text' ? { ...option, text: option.label } : c.kind === 'color' ? { ...option, color: '#2563eb' } : { ...option, resource: null, image_url: null });
                        this.touch();
                    },

                    removeOption(index) {
                        this.current.constraints.options.splice(index, 1);
                        this.touch();
                    },

                    chooseOptionImage(option) {
                        this.$emitter.emit('dam-asset-picker:open', {
                            ids: [],
                            choose: true,
                            onAssign: async (ids) => {
                                if (ids.length !== 1) { this.error = @js(trans('dam::app.admin.templates.create.pick-one')); return; }

                                try {
                                    const { data } = await this.$axios.post(@js(route('admin.dam.templates.images.store', $template['id'])), { asset_id: Number(ids[0]) });
                                    option.resource = data.id;
                                    option.image_url = data.url;
                                    this.error = '';
                                    this.touch();
                                } catch (error) {
                                    this.fail(error);
                                }
                            },
                        });
                    },

                    pickFolder(folder) {
                        this.details.folder = { id: folder.id, name: folder.name };
                        this.folderPickerOpen = false;
                        this.touch();
                    },

                    // What the server stores: the schema's keys only (no display URLs, no local keys).
                    payloadSlots() {
                        return this.slots.map(({ _key, ...slot }) => {
                            const out = clone(slot);

                            if (out.type === 'choice') {
                                out.constraints.options = out.constraints.options.map(({ image_url, ...o }) => o);
                            }
                            if (out.type !== 'image' && ! out.sample) delete out.sample;

                            return out;
                        });
                    },

                    fail(error) {
                        const data = error?.response?.data;
                        this.error = data?.errors ? Object.values(data.errors).flat()[0] : (data?.message || @js(trans('dam::app.admin.templates.action-failed')));
                    },

                    async save(quiet = false) {
                        this.busy = true;
                        this.error = '';

                        try {
                            const { data } = await this.$axios.put(@js(route('admin.dam.templates.update', $template['id'])), {
                                name: this.details.name,
                                description: this.details.description || null,
                                output_directory_id: this.details.folder?.id ?? null,
                                slots: this.payloadSlots(),
                            });
                            this.apply(data.template);
                            if (! quiet) this.$emitter.emit('add-flash', { type: 'success', message: this.labels.saved });

                            return true;
                        } catch (error) {
                            this.fail(error);

                            return false;
                        } finally {
                            this.busy = false;
                        }
                    },

                    dropPreview() {
                        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                        this.previewUrl = null;
                    },

                    // The server's own render of the draft with each slot's sample: what fillers will get.
                    async togglePreview() {
                        if (this.previewUrl) { this.dropPreview(); return; }
                        if (this.dirty && ! await this.save(true)) return;

                        this.busy = true;
                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.preview', $template['id'])), {}, { responseType: 'blob' });
                            this.previewUrl = URL.createObjectURL(data);
                        } catch (error) {
                            this.error = @js(trans('dam::app.admin.templates.action-failed'));
                        } finally {
                            this.busy = false;
                        }
                    },

                    async publish() {
                        if (this.dirty && ! await this.save(true)) return;

                        this.busy = true;
                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.publish', $template['id'])));
                            this.apply(data.template);
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            this.busy = false;
                        }
                    },

                    async archive() {
                        this.busy = true;
                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.archive', $template['id'])));
                            this.apply(data.template);
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            this.busy = false;
                        }
                    },

                    confirmDelete() {
                        this.$emitter.emit('open-confirm-modal', {
                            title: this.labels['delete-title'],
                            message: this.labels['delete-message'],
                            options: { btnDisagree: this.labels.cancel, btnAgree: this.labels.delete, btnAgreeClass: 'danger-button', btnDisagreeClass: 'transparent-button' },
                            agree: async () => {
                                try {
                                    await this.$axios.delete(@js(route('admin.dam.templates.destroy', $template['id'])));
                                    window.location.href = @js(route('admin.dam.templates.index'));
                                } catch (error) {
                                    this.fail(error);
                                }
                            },
                            disagree: () => {},
                        });
                    },

                    // The image editor in template mode: base, crop, background and fixed annotations, saved into the draft.
                    async openLayout() {
                        if (this.dirty && ! await this.save(true)) return;

                        this.editorOpen = true;
                        await this.$nextTick();

                        try {
                            const { mountEditor } = await window.loadDamImageEditor();
                            this._unmountEditor = mountEditor(this.$refs.editorHost, {
                                assetId: {{ (int) $template['id'] }},
                                fileName: @js($template['name']),
                                endpoint: @js(route('admin.dam.templates.layout', $template['id'])),
                                labels: @js($imageEditorLabels),
                                templateMode: true,
                                onClose: () => this.closeLayout(),
                                onSaved: () => { this.closeLayout(); window.location.reload(); },
                            });
                        } catch (error) {
                            this.closeLayout();
                            this.error = @js($imageEditorLabels['Image editor could not load. Reload the page and try again.']);
                        }
                    },

                    closeLayout() {
                        this._unmountEditor?.();
                        this._unmountEditor = null;
                        this.editorOpen = false;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
