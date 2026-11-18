{{--
    P4a (D9, D10): the typed metadata field controls shared by the Library.
    - v-dam-field-input: one typed input (date, number, select, multi-select chips, URL with an open-link affordance,
      toggle, text). Used by the modal's Details editor and the bulk "Edit fields" modal.
    - v-dam-field-editor: the modal's Details section, with the missing-required badge.
    - v-dam-field-filter: one field's control in the Library filter panel.
    - v-dam-field-bulk-modal: the bulk "Edit fields" modal, opened with `dam:open-field-bulk-modal`.
    Every write goes through the same server validator, so errors arrive as `fields.<code>` messages.
--}}
@pushOnce('scripts')
<script type="text/x-template" id="v-dam-field-input-template">
    <div class="min-w-0" :data-dam-field-input="field.type">
        <div v-if="field.type === 'boolean'" class="flex items-center gap-2">
            <button
                type="button"
                role="switch"
                :id="inputId"
                :aria-checked="modelValue === true ? 'true' : 'false'"
                :aria-label="field.label"
                :disabled="disabled"
                class="relative h-5 w-9 shrink-0 rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 disabled:cursor-not-allowed disabled:opacity-60"
                :class="modelValue === true ? 'bg-primary-700' : 'bg-gray-200 dark:bg-cherry-800'"
                @click="emitValue(modelValue !== true)"
            >
                <!-- The same track and knob as the DAM settings toggles. -->
                <span class="absolute top-0.5 h-4 w-4 rounded-full border bg-white transition-all ltr:left-0.5 rtl:right-0.5" :class="modelValue === true ? 'border-white ltr:translate-x-full rtl:-translate-x-full' : 'border-gray-300'"></span>
            </button>
            <span class="text-xs text-gray-600 dark:text-gray-300">@{{ modelValue === true ? labels.yes : (modelValue === false ? labels.no : '—') }}</span>
        </div>

        <select
            v-else-if="field.type === 'select'"
            :id="inputId"
            :disabled="disabled"
            :value="modelValue ?? ''"
            class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-700 disabled:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-800 dark:text-gray-200"
            @change="emitValue($event.target.value === '' ? null : $event.target.value)"
        >
            <option value="">@{{ labels.choose }}</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">@{{ option.label || option.value }}</option>
        </select>

        <div v-else-if="field.type === 'multi_select'" class="flex flex-wrap items-center gap-1.5" data-dam-field-chips>
            <span
                v-for="value in selectedOptions"
                :key="value"
                class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:bg-primary-900/40 dark:text-primary-200"
            >
                <span class="truncate">@{{ optionLabel(value) }}</span>
                <button
                    v-if="! disabled"
                    type="button"
                    class="-mr-1 flex h-4 w-4 items-center justify-center rounded-full leading-none hover:bg-primary-100 dark:hover:bg-primary-800"
                    :aria-label="labels.remove.replace(':option', optionLabel(value))"
                    @click="emitValue(selectedOptions.filter(v => v !== value))"
                >×</button>
            </span>
            <select
                v-if="! disabled && remainingOptions.length"
                :id="inputId"
                :aria-label="field.label"
                class="min-w-[7rem] flex-1 rounded-md border border-dashed border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 dark:border-cherry-700 dark:bg-cherry-800 dark:text-gray-300"
                value=""
                @change="addOption($event)"
            >
                <option value="">@{{ labels.add }}</option>
                <option v-for="option in remainingOptions" :key="option.value" :value="option.value">@{{ option.label || option.value }}</option>
            </select>
            <span v-if="disabled && ! selectedOptions.length" class="text-xs text-gray-400">—</span>
        </div>

        <div v-else-if="field.type === 'url'" class="flex min-w-0 items-center gap-1.5">
            <input
                type="url"
                :id="inputId"
                :disabled="disabled"
                :value="modelValue ?? ''"
                maxlength="1000"
                placeholder="https://"
                class="min-w-0 flex-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-700 disabled:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-800 dark:text-gray-200"
                @input="emitValue(blankToNull($event.target.value))"
            >
            <a
                v-if="openableUrl"
                :href="openableUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:border-primary-300 hover:text-primary-700 dark:border-cherry-700 dark:text-gray-300"
                :title="labels.openLink"
                :aria-label="labels.openLink + ': ' + field.label"
                data-dam-field-open-link
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
            </a>
        </div>

        <input
            v-else
            :type="field.type === 'number' ? 'number' : (field.type === 'date' ? 'date' : 'text')"
            :step="field.type === 'number' ? 'any' : undefined"
            :maxlength="field.type === 'text' ? 1000 : undefined"
            :id="inputId"
            :disabled="disabled"
            :value="modelValue ?? ''"
            class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-700 disabled:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-800 dark:text-gray-200"
            @input="onTyped($event)"
        >
    </div>
</script>

<script type="text/x-template" id="v-dam-field-editor-template">
    <section class="pt-5" data-dam-field-editor>
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.editor.title')</h3>
            <span
                v-if="missingCount"
                class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                data-dam-missing-required
            >@{{ missingCount === 1 ? labels.missingOne.replace(':count', 1) : labels.missingMany.replace(':count', missingCount) }}</span>
        </div>

        <p v-if="! canEdit" class="mb-2 text-xs text-gray-400">@lang('dam::app.admin.metadata-fields.editor.read-only')</p>

        <!-- novalidate: the server's messages are the ones every edit path shares, so the browser does not pre-empt them. -->
        <form novalidate class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800" @submit.prevent="save">
            <div v-for="field in fields" :key="field.code" class="grid gap-1.5 px-3 py-2.5" :data-dam-field="field.code">
                <label :for="`dam-field-${uid}-${field.code}`" class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <span class="truncate">@{{ field.label }}</span>
                    <span v-if="field.required" class="shrink-0 text-[10px] font-semibold uppercase tracking-wide" :class="isMissing(field) ? 'text-amber-700 dark:text-amber-300' : 'text-gray-400'">@lang('dam::app.admin.metadata-fields.editor.required')</span>
                </label>
                <v-dam-field-input
                    :field="field"
                    :input-id="`dam-field-${uid}-${field.code}`"
                    :disabled="! canEdit || saving"
                    :model-value="draft[field.code]"
                    @update:model-value="setValue(field.code, $event)"
                    @invalid="setInvalid(field.code, $event)"
                ></v-dam-field-input>
                <p v-if="fieldError(field)" class="text-xs text-red-600" data-dam-field-error>@{{ fieldError(field) }}</p>
            </div>

            <div v-if="canEdit" class="flex items-center justify-end gap-3 bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
                <p v-if="formError" class="mr-auto text-xs text-red-600">@{{ formError }}</p>
                <button type="submit" class="primary-button !px-3 !py-1.5 text-xs" :disabled="saving || ! dirty || blocked.length > 0" data-dam-field-save>
                    @{{ saving ? labels.saving : labels.save }}
                </button>
            </div>
        </form>
    </section>
</script>

<script type="text/x-template" id="v-dam-field-filter-template">
    <div class="grid gap-1.5" :data-dam-field-filter="field.type">
        <div v-if="field.type === 'select' || field.type === 'multi_select'" class="grid max-h-56 gap-1 overflow-y-auto">
            <label v-for="option in field.options" :key="option.value" class="relative flex cursor-pointer items-center gap-1.5 rounded px-1 py-0.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-cherry-800">
                <input type="checkbox" class="peer sr-only" :checked="checked.includes(option.value)" @change="toggleOption(option.value, $event.target.checked)">
                <span class="icon-checkbox-normal shrink-0 rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-300" aria-hidden="true"></span>
                <span class="truncate">@{{ option.label || option.value }}</span>
            </label>
        </div>

        <div v-else-if="field.type === 'number' || field.type === 'date'" class="grid grid-cols-2 gap-1.5">
            <input
                :type="field.type"
                :step="field.type === 'number' ? 'any' : undefined"
                class="min-h-[39px] w-full min-w-0 rounded-md border px-2.5 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-cherry-800 dark:bg-cherry-800 dark:text-gray-300"
                :placeholder="field.type === 'number' ? labels.min : labels.from"
                :aria-label="field.label + ' ' + (field.type === 'number' ? labels.min : labels.from)"
                :value="rangeValue(0)"
                @change="setRange(0, $event.target.value)"
            >
            <input
                :type="field.type"
                :step="field.type === 'number' ? 'any' : undefined"
                class="min-h-[39px] w-full min-w-0 rounded-md border px-2.5 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-cherry-800 dark:bg-cherry-800 dark:text-gray-300"
                :placeholder="field.type === 'number' ? labels.max : labels.to"
                :aria-label="field.label + ' ' + (field.type === 'number' ? labels.max : labels.to)"
                :value="rangeValue(1)"
                @change="setRange(1, $event.target.value)"
            >
        </div>

        <div v-else-if="field.type === 'boolean'" class="grid grid-cols-3 gap-1.5" role="radiogroup" :aria-label="field.label">
            <button
                v-for="choice in booleanChoices"
                :key="choice.value"
                type="button"
                role="radio"
                :aria-checked="booleanValue === choice.value ? 'true' : 'false'"
                class="rounded-md border px-2 py-1.5 text-center text-sm font-medium transition-all"
                :class="booleanValue === choice.value ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300' : 'text-gray-600 hover:border-gray-400 dark:border-cherry-800 dark:text-gray-300'"
                @click="emitCriterion(choice.value === '' ? null : choice.value)"
            >@{{ choice.label }}</button>
        </div>

        <input
            v-else
            type="text"
            maxlength="255"
            class="flex min-h-[39px] w-full rounded-md border px-3 py-1.5 text-sm leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-cherry-800 dark:bg-cherry-800 dark:text-gray-300 dark:hover:border-gray-400"
            :placeholder="labels.contains"
            :aria-label="field.label"
            :value="typeof value === 'string' ? value : ''"
            @change="emitCriterion($event.target.value.trim() || null)"
            @keyup.enter="emitCriterion($event.target.value.trim() || null)"
        >
    </div>
</script>

<script type="text/x-template" id="v-dam-field-bulk-modal-template">
    <x-admin::modal ref="fieldBulkModal">
        <x-slot:header>
            <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ labels.title.replace(':count', assetIds.length) }}</p>
        </x-slot>

        <x-slot:content>
            <form novalidate class="grid gap-3" data-dam-field-bulk @submit.prevent="apply">
                <p class="text-sm text-gray-600 dark:text-slate-300">@lang('dam::app.admin.metadata-fields.bulk.hint')</p>
                <p v-if="skippedFolders" class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.metadata-fields.bulk.folders')</p>

                <div class="max-h-[55vh] overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800">
                    <div v-for="field in fields" :key="field.code" class="grid gap-1.5 px-3 py-2.5" :data-dam-field="field.code">
                        <label class="relative flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <input type="checkbox" class="peer sr-only" v-model="ticked[field.code]" @change="setInvalid(field.code, false)">
                            <span class="icon-checkbox-normal shrink-0 rounded-md text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-300" aria-hidden="true"></span>
                            <span class="truncate">@{{ field.label }}</span>
                        </label>
                        <v-dam-field-input
                            v-if="ticked[field.code]"
                            :field="field"
                            :input-id="`dam-bulk-field-${field.code}`"
                            :disabled="saving"
                            :model-value="draft[field.code]"
                            @update:model-value="draft[field.code] = $event"
                            @invalid="setInvalid(field.code, $event)"
                        ></v-dam-field-input>
                        <p v-if="fieldError(field)" class="text-xs text-red-600" data-dam-field-error>@{{ fieldError(field) }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <p v-if="formError" class="mr-auto text-xs text-red-600">@{{ formError }}</p>
                    <button type="button" class="secondary-button" @click="close">@lang('dam::app.admin.metadata-fields.cancel')</button>
                    <button type="submit" class="primary-button" :disabled="saving || blocked.length > 0" data-dam-field-bulk-apply>@lang('dam::app.admin.metadata-fields.bulk.apply')</button>
                </div>
            </form>
        </x-slot>
    </x-admin::modal>
</script>

<script type="module">
    const damFieldLabels = {
        yes:         @js(trans('dam::app.admin.metadata-fields.boolean.yes')),
        no:          @js(trans('dam::app.admin.metadata-fields.boolean.no')),
        choose:      @js(trans('dam::app.admin.metadata-fields.editor.choose')),
        add:         @js(trans('dam::app.admin.metadata-fields.editor.add')),
        remove:      @js(trans('dam::app.admin.metadata-fields.editor.remove')),
        openLink:    @js(trans('dam::app.admin.metadata-fields.editor.open-link')),
        save:        @js(trans('dam::app.admin.metadata-fields.editor.save')),
        saving:      @js(trans('dam::app.admin.metadata-fields.editor.saving')),
        saveFailed:  @js(trans('dam::app.admin.metadata-fields.editor.save-failed')),
        missingOne:  @js(trans_choice('dam::app.admin.metadata-fields.editor.missing', 1)),
        missingMany: @js(trans_choice('dam::app.admin.metadata-fields.editor.missing', 2)),
        min:         @js(trans('dam::app.admin.metadata-fields.filter.min')),
        max:         @js(trans('dam::app.admin.metadata-fields.filter.max')),
        from:        @js(trans('dam::app.admin.metadata-fields.filter.from')),
        to:          @js(trans('dam::app.admin.metadata-fields.filter.to')),
        contains:    @js(trans('dam::app.admin.metadata-fields.filter.contains')),
        any:         @js(trans('dam::app.admin.metadata-fields.filter.any')),
        title:       @js(trans('dam::app.admin.metadata-fields.bulk.title')),
        noneTicked:  @js(trans('dam::app.admin.metadata-fields.bulk.none-ticked')),
        invalid:     @js(trans('dam::app.admin.metadata-fields.editor.invalid')),
    };
    window.MothershipFieldLabels = damFieldLabels;

    /** `fields.<code>` messages from a 422, keyed by code; the first message per field. */
    const damFieldErrors = (error) => {
        const out = {};
        Object.entries(error?.response?.data?.errors ?? {}).forEach(([key, messages]) => {
            const match = /^fields\.([a-z][a-z0-9_]*)/.exec(key);
            if (match && ! out[match[1]]) out[match[1]] = Array.isArray(messages) ? messages[0] : String(messages);
        });
        return out;
    };

    app.component('v-dam-field-input', {
        template: '#v-dam-field-input-template',
        props: {
            field:      { type: Object, required: true },
            modelValue: { default: null },
            disabled:   { type: Boolean, default: false },
            inputId:    { type: String, default: undefined },
        },
        // `invalid`: true while a number or date input holds text the browser cannot read as a value (badInput, such as
        // "1e400" or a half-typed date). Its value then reads as "", which must never be sent as a clear.
        emits: ['update:modelValue', 'invalid'],
        data() { return { labels: damFieldLabels }; },
        computed: {
            selectedOptions() { return Array.isArray(this.modelValue) ? this.modelValue : []; },
            remainingOptions() { return (this.field.options ?? []).filter(option => ! this.selectedOptions.includes(option.value)); },
            // Only an http(s) address gets the link; anything else stays text the person can fix.
            openableUrl() {
                const value = typeof this.modelValue === 'string' ? this.modelValue.trim() : '';
                return /^https?:\/\/[^\s]+$/i.test(value) ? value : null;
            },
        },
        methods: {
            emitValue(value) { this.$emit('update:modelValue', value); },
            blankToNull(value) { return value === '' ? null : value; },
            onTyped(event) {
                const target = event.target;
                const unreadable = !! target.validity?.badInput
                    || (this.field.type === 'number' && target.value !== '' && ! Number.isFinite(Number(target.value)));
                this.$emit('invalid', unreadable);
                if (! unreadable) this.emitValue(this.blankToNull(target.value));
            },
            optionLabel(value) { return (this.field.options ?? []).find(option => option.value === value)?.label || value; },
            addOption(event) {
                const value = event.target.value;
                event.target.value = '';
                if (value) this.emitValue([...this.selectedOptions, value]);
            },
        },
    });

    app.component('v-dam-field-editor', {
        template: '#v-dam-field-editor-template',
        props: {
            assetId: { type: [Number, String], required: true },
            fields:  { type: Array, default: () => [] },
            missing: { type: Array, default: () => [] },
            canEdit: { type: Boolean, default: false },
        },
        emits: ['saved'],
        data() {
            return { labels: damFieldLabels, draft: {}, saved: '{}', invalid: {}, saving: false, errors: {}, formError: '', uid: Math.random().toString(36).slice(2, 8) };
        },
        computed: {
            dirty() { return JSON.stringify(this.draft) !== this.saved; },
            blocked() { return Object.keys(this.invalid).filter(code => this.invalid[code]); },
            missingCount() { return this.fields.filter(field => this.isMissing(field)).length; },
        },
        watch: {
            fields: { immediate: true, handler() { this.reset(); } },
        },
        methods: {
            reset() {
                const draft = {};
                this.fields.forEach(field => { draft[field.code] = field.value ?? null; });
                this.draft = draft;
                this.saved = JSON.stringify(draft);
                this.invalid = {};
                this.errors = {};
                this.formError = '';
            },
            setInvalid(code, invalid) { this.invalid = { ...this.invalid, [code]: invalid }; },
            fieldError(field) {
                return this.errors[field.code] || (this.invalid[field.code] ? this.labels.invalid.replace(':field', field.label) : '');
            },
            isMissing(field) {
                return field.required && this.missing.includes(field.code);
            },
            setValue(code, value) {
                this.draft = { ...this.draft, [code]: value };
                if (this.errors[code]) this.errors = { ...this.errors, [code]: undefined };
            },
            save() {
                if (! this.canEdit || this.saving || this.blocked.length) return;
                // Only the fields the person changed are sent, each with the value it was loaded with: the server
                // refuses the save (409) when someone else changed one of them since, rather than writing over it.
                const original = JSON.parse(this.saved);
                const fields = {};
                const expected = {};
                Object.keys(this.draft).forEach(code => {
                    if (JSON.stringify(this.draft[code]) === JSON.stringify(original[code])) return;
                    fields[code] = this.draft[code];
                    expected[code] = original[code] ?? null;
                });
                if (! Object.keys(fields).length) return;
                this.saving = true;
                this.errors = {};
                this.formError = '';
                const assetId = this.assetId;
                this.$axios.put(@js(route('admin.dam.assets.fields.update', '__id__')).replace('__id__', assetId), { fields, expected })
                    .then(({ data }) => {
                        this.$emit('saved', { fields: data.fields, missing_required: data.missing_required });
                        this.$emitter.emit('dam:asset-fields-saved', { assetId: Number(assetId), cardFields: data.card_fields ?? [] });
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    })
                    .catch(error => {
                        this.errors = damFieldErrors(error);
                        if (! Object.keys(this.errors).length) this.formError = error?.response?.data?.message || this.labels.saveFailed;
                    })
                    .finally(() => { this.saving = false; });
            },
        },
    });

    app.component('v-dam-field-filter', {
        template: '#v-dam-field-filter-template',
        props: {
            field: { type: Object, required: true },
            // The applied criterion, as the server reads filter_field[code].
            value: { default: null },
        },
        emits: ['change'],
        data() {
            return {
                labels: damFieldLabels,
                booleanChoices: [
                    { value: '', label: damFieldLabels.any },
                    { value: '1', label: damFieldLabels.yes },
                    { value: '0', label: damFieldLabels.no },
                ],
            };
        },
        computed: {
            checked() { return Array.isArray(this.value) ? this.value : []; },
            booleanValue() { return this.value === '1' || this.value === '0' ? this.value : ''; },
        },
        methods: {
            emitCriterion(criterion) { this.$emit('change', criterion); },
            toggleOption(option, on) {
                const next = on ? [...new Set([...this.checked, option])] : this.checked.filter(v => v !== option);
                this.emitCriterion(next.length ? next : null);
            },
            rangeKeys() { return this.field.type === 'number' ? ['min', 'max'] : ['from', 'to']; },
            rangeValue(index) {
                return this.value && typeof this.value === 'object' && ! Array.isArray(this.value) ? (this.value[this.rangeKeys()[index]] ?? '') : '';
            },
            setRange(index, raw) {
                const keys = this.rangeKeys();
                const next = { [keys[0]]: this.rangeValue(0), [keys[1]]: this.rangeValue(1) };
                next[keys[index]] = raw;
                Object.keys(next).forEach(key => { if (next[key] === '' || next[key] === null) delete next[key]; });
                this.emitCriterion(Object.keys(next).length ? next : null);
            },
        },
    });

    app.component('v-dam-field-bulk-modal', {
        template: '#v-dam-field-bulk-modal-template',
        data() {
            return { labels: damFieldLabels, fields: [], assetIds: [], skippedFolders: false, ticked: {}, draft: {}, invalid: {}, errors: {}, formError: '', saving: false, context: null };
        },
        computed: {
            // Ticked fields whose input the browser cannot read: Apply waits until each is corrected or cleared.
            blocked() { return this.fields.filter(field => this.ticked[field.code] && this.invalid[field.code]).map(field => field.code); },
        },
        mounted() {
            this.$emitter.on('dam:open-field-bulk-modal', this.open);
        },
        beforeUnmount() {
            this.$emitter.off('dam:open-field-bulk-modal', this.open);
        },
        methods: {
            open({ fields, assetIds, skippedFolders, context } = {}) {
                this.fields = Array.isArray(fields) ? fields : [];
                this.assetIds = (assetIds ?? []).map(Number).filter(Boolean);
                this.skippedFolders = !! skippedFolders;
                this.context = context ?? null;
                this.ticked = {};
                this.draft = {};
                this.invalid = {};
                this.errors = {};
                this.formError = '';
                this.$refs.fieldBulkModal.open();
            },
            close() { this.$refs.fieldBulkModal.close(); },
            setInvalid(code, invalid) { this.invalid = { ...this.invalid, [code]: invalid }; },
            fieldError(field) {
                return this.errors[field.code] || (this.ticked[field.code] && this.invalid[field.code] ? this.labels.invalid.replace(':field', field.label) : '');
            },
            apply() {
                if (this.blocked.length) return;
                const fields = {};
                this.fields.forEach(field => { if (this.ticked[field.code]) fields[field.code] = this.draft[field.code] ?? null; });
                if (! Object.keys(fields).length) { this.formError = this.labels.noneTicked; return; }

                this.saving = true;
                this.errors = {};
                this.formError = '';
                this.$axios.post(@js(route('admin.dam.assets.mass_update_fields')), { indices: this.assetIds, fields })
                    .then(({ data }) => {
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        this.$emitter.emit('dam:field-bulk-applied', { context: this.context });
                        this.close();
                    })
                    .catch(error => {
                        this.errors = damFieldErrors(error);
                        if (! Object.keys(this.errors).length) this.formError = error?.response?.data?.message || this.labels.saveFailed;
                    })
                    .finally(() => { this.saving = false; });
            },
        },
    });
</script>
@endPushOnce
