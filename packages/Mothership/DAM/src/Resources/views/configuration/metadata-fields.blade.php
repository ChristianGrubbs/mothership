<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.metadata-fields.title')</x-slot:title>

    <v-dam-metadata-fields :initial='@json($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'></v-dam-metadata-fields>

    @pushOnce('scripts')
    <script type="text/x-template" id="v-dam-metadata-fields-template">
        <div class="mx-auto max-w-6xl pb-10" data-metadata-fields>
            <a class="text-sm text-primary-600" href="{{ route('admin.dam.configuration.index') }}">@lang('dam::app.admin.metadata-fields.back')</a>

            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.metadata-fields.title')</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.description')</p>
                </div>
                <button v-if="initial.can_create" type="button" class="primary-button whitespace-nowrap" data-metadata-field-new @click="startCreate">@lang('dam::app.admin.metadata-fields.new')</button>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
                <!-- The editor comes first on a phone, so it opens where the person is looking. -->
                <section
                    v-if="draft"
                    class="order-first rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-cherry-900 lg:order-last lg:sticky lg:top-4"
                    data-metadata-field-form
                    :aria-label="draft.id ? @js(trans('dam::app.admin.metadata-fields.form.edit-title')) : @js(trans('dam::app.admin.metadata-fields.form.create-title'))"
                >
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white">@{{ draft.id ? @js(trans('dam::app.admin.metadata-fields.form.edit-title')) : @js(trans('dam::app.admin.metadata-fields.form.create-title')) }}</h2>

                    <form class="mt-4 grid gap-4" @submit.prevent="save">
                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.metadata-fields.form.label')
                            <input v-model.trim="draft.label" @input="suggestCode" maxlength="191" required class="rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-field-input="label">
                            <span v-if="errors.label" class="text-xs font-normal text-red-600">@{{ errors.label }}</span>
                        </label>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.metadata-fields.form.code')
                            <input v-model.trim="draft.code" @input="codeTouched = true" :disabled="!!draft.id" maxlength="64" required pattern="[a-z][a-z0-9_]*" class="rounded-md border border-gray-300 px-3 py-2 font-mono text-sm font-normal disabled:bg-gray-50 disabled:text-gray-500 dark:border-cherry-700 dark:bg-cherry-800 dark:disabled:bg-cherry-900" data-field-input="code">
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.form.code-hint')</span>
                            <span v-if="errors.code" class="text-xs font-normal text-red-600">@{{ errors.code }}</span>
                        </label>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.metadata-fields.form.type')
                            <select v-model="draft.type" :disabled="typeLocked" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-normal disabled:bg-gray-50 dark:border-cherry-700 dark:bg-cherry-800" data-field-input="type">
                                <option v-for="type in initial.types" :key="type" :value="type">@{{ typeLabel(type) }}</option>
                            </select>
                            <span v-if="typeLocked" class="text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.form.type-locked')</span>
                            <span v-if="errors.type" class="text-xs font-normal text-red-600">@{{ errors.type }}</span>
                        </label>

                        <fieldset v-if="hasOptions" class="grid gap-2" data-field-options>
                            <legend class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">@lang('dam::app.admin.metadata-fields.form.options')</legend>
                            <div v-for="(option, index) in draft.options" :key="index" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2">
                                <input v-model.trim="option.value" maxlength="191" required :aria-label="@js(trans('dam::app.admin.metadata-fields.form.option-value')) + ' ' + (index + 1)" :placeholder="@js(trans('dam::app.admin.metadata-fields.form.option-value'))" class="min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800">
                                <input v-model.trim="option.label" maxlength="191" :aria-label="@js(trans('dam::app.admin.metadata-fields.form.option-label')) + ' ' + (index + 1)" :placeholder="@js(trans('dam::app.admin.metadata-fields.form.option-label'))" class="min-w-0 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm dark:border-cherry-700 dark:bg-cherry-800">
                                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-cherry-800" :aria-label="@js(trans('dam::app.admin.metadata-fields.form.remove-option'))" @click="draft.options.splice(index, 1)">×</button>
                            </div>
                            <button type="button" class="justify-self-start text-sm font-medium text-primary-600 hover:underline" @click="draft.options.push({ value: '', label: '' })">@lang('dam::app.admin.metadata-fields.form.add-option')</button>
                            <span v-if="errors.options" class="text-xs text-red-600">@{{ errors.options }}</span>
                        </fieldset>

                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                            @lang('dam::app.admin.metadata-fields.form.card')
                            <select v-model="draft.card_priority" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-normal dark:border-cherry-700 dark:bg-cherry-800" data-field-input="card_priority">
                                <option :value="null">@lang('dam::app.admin.metadata-fields.form.card-none')</option>
                                <option v-for="slot in [1, 2, 3, 4, 5]" :key="slot" :value="slot" :disabled="slotOwner(slot) !== null">@{{ slotLabel(slot) }}</option>
                            </select>
                            <span v-if="errors.card_priority" class="text-xs font-normal text-red-600">@{{ errors.card_priority }}</span>
                        </label>

                        <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" v-model="draft.required" class="mt-0.5 h-4 w-4 accent-primary-600" data-field-input="required">
                            <span><span class="font-medium">@lang('dam::app.admin.metadata-fields.form.required')</span><span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.form.required-hint')</span></span>
                        </label>

                        <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <input type="checkbox" v-model="draft.is_filterable" class="h-4 w-4 accent-primary-600" data-field-input="is_filterable">
                            @lang('dam::app.admin.metadata-fields.form.filterable')
                        </label>

                        <p v-if="errors.general" role="alert" class="text-sm text-red-600">@{{ errors.general }}</p>

                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            <button type="submit" class="primary-button" :disabled="busy" :aria-busy="busy" data-metadata-field-save>@{{ busy ? @js(trans('dam::app.admin.metadata-fields.saving')) : @js(trans('dam::app.admin.metadata-fields.save')) }}</button>
                            <button type="button" class="transparent-button" @click="cancel">@lang('dam::app.admin.metadata-fields.cancel')</button>
                        </div>
                    </form>
                </section>

                <section class="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-cherry-900" data-metadata-field-list>
                    <p v-if="!fields.length" class="p-6 text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.metadata-fields.empty')</p>

                    <ol v-else class="divide-y divide-gray-100 dark:divide-cherry-800">
                        <li
                            v-for="(field, index) in fields"
                            :key="field.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:flex-nowrap"
                            :class="{ 'bg-primary-50/60 dark:bg-primary-900/20': draft && draft.id === field.id, 'opacity-60': field.archived }"
                            :data-metadata-field-row="field.code"
                        >
                            <div v-if="!field.archived && initial.can_update" class="flex shrink-0 flex-col">
                                <button type="button" class="h-5 w-6 text-xs text-gray-400 hover:text-primary-600 disabled:opacity-30" :disabled="index === 0 || busy" :aria-label="moveLabel('up', field)" @click="move(index, -1)">▲</button>
                                <button type="button" class="h-5 w-6 text-xs text-gray-400 hover:text-primary-600 disabled:opacity-30" :disabled="index === activeCount - 1 || busy" :aria-label="moveLabel('down', field)" @click="move(index, 1)">▼</button>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white" :title="field.label">@{{ field.label }}</p>
                                <p class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">@{{ field.code }}</p>
                            </div>

                            <div class="flex basis-full flex-wrap items-center gap-1.5 sm:basis-auto sm:justify-end">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-cherry-800 dark:text-gray-200">@{{ typeLabel(field.type) }}<template v-if="field.options.length"> · @{{ field.options.length }}</template></span>
                                <span v-if="field.required" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">@lang('dam::app.admin.metadata-fields.flags.required')</span>
                                <span v-if="field.card_priority" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/40 dark:text-primary-200">@{{ @js(trans('dam::app.admin.metadata-fields.flags.card')).replace(':n', field.card_priority) }}</span>
                                <span v-if="field.is_filterable" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">@lang('dam::app.admin.metadata-fields.flags.filterable')</span>
                                <span v-if="field.archived" class="rounded-full border border-gray-300 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-cherry-700 dark:text-gray-300">@lang('dam::app.admin.metadata-fields.archived')</span>
                                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">@{{ valuesLabel(field.values_count) }}</span>
                            </div>

                            <div class="ml-auto flex shrink-0 items-center gap-1">
                                <template v-if="field.archived">
                                    <button v-if="initial.can_update" type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" @click="restore(field)">@lang('dam::app.admin.metadata-fields.restore')</button>
                                </template>
                                <template v-else>
                                    <button v-if="initial.can_update" type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-cherry-800" :data-metadata-field-edit="field.code" @click="startEdit(field)">@lang('dam::app.admin.metadata-fields.edit')</button>
                                    <button v-if="initial.can_delete" type="button" class="rounded-md px-2.5 py-1 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-cherry-800" @click="remove(field)">@lang('dam::app.admin.metadata-fields.delete-btn')</button>
                                </template>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </script>

    <script type="module">
    app.component('v-dam-metadata-fields', {
        template: '#v-dam-metadata-fields-template',
        props: ['initial'],

        data() {
            return { fields: this.initial.fields, draft: null, codeTouched: false, busy: false, errors: {} };
        },

        computed: {
            hasOptions() { return ['select', 'multi_select'].includes(this.draft?.type); },
            typeLocked() { return !!this.draft?.id && (this.fields.find(f => f.id === this.draft.id)?.values_count ?? 0) > 0; },
            activeCount() { return this.fields.filter(f => !f.archived).length; },
        },

        methods: {
            typeLabel(type) { return @js(trans('dam::app.admin.metadata-fields.types'))[type] ?? type; },
            valuesLabel(count) {
                const [one, many] = @js(trans('dam::app.admin.metadata-fields.values-count')).split('|');
                return (count === 1 ? one : many).replace(':count', count);
            },
            moveLabel(direction, field) {
                return (direction === 'up' ? @js(trans('dam::app.admin.metadata-fields.move-up')) : @js(trans('dam::app.admin.metadata-fields.move-down'))).replace(':field', () => field.label);
            },
            /** The field that holds a card slot, other than the one being edited. */
            slotOwner(slot) {
                return this.fields.find(f => !f.archived && f.card_priority === slot && f.id !== this.draft?.id) ?? null;
            },
            slotLabel(slot) {
                const owner = this.slotOwner(slot);
                return owner
                    ? @js(trans('dam::app.admin.metadata-fields.form.card-taken')).replace(':n', slot).replace(':field', () => owner.label)
                    : @js(trans('dam::app.admin.metadata-fields.form.card-slot')).replace(':n', slot);
            },
            startCreate() {
                this.errors = {};
                this.codeTouched = false;
                this.draft = { id: null, label: '', code: '', type: 'text', options: [{ value: '', label: '' }], required: false, card_priority: null, is_filterable: false };
            },
            startEdit(field) {
                this.errors = {};
                this.draft = JSON.parse(JSON.stringify({ ...field, options: field.options.length ? field.options : [{ value: '', label: '' }] }));
            },
            cancel() { this.draft = null; this.errors = {}; },
            // A new field's code follows its label until someone types a code of their own.
            suggestCode() {
                if (this.draft.id || this.codeTouched) return;
                let code = this.draft.label.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
                if (code && !/^[a-z]/.test(code)) code = ('f_' + code).slice(0, 64);
                this.draft.code = code;
            },
            payload() {
                const body = { label: this.draft.label, type: this.draft.type, required: this.draft.required, card_priority: this.draft.card_priority, is_filterable: this.draft.is_filterable };
                if (!this.draft.id) body.code = this.draft.code;
                if (this.hasOptions) body.options = this.draft.options.filter(o => o.value !== '');
                return body;
            },
            showErrors(error) {
                const errors = error.response?.data?.errors || {};
                this.errors = {};
                Object.entries(errors).forEach(([key, messages]) => { this.errors[key.split('.')[0]] = [].concat(messages).join(' '); });
                if (!Object.keys(errors).length) this.errors.general = error.response?.data?.message || 'Could not save. Your edits are still here. Try again.';
            },
            async save() {
                if (this.busy) return;
                this.busy = true;
                try {
                    const { data } = this.draft.id
                        ? await this.$axios.put(@js(route('admin.dam.configuration.fields.update', '__id__')).replace('__id__', this.draft.id), this.payload())
                        : await this.$axios.post(@js(route('admin.dam.configuration.fields.store')), this.payload());
                    const index = this.fields.findIndex(f => f.id === data.field.id);
                    index < 0 ? this.fields.splice(this.activeCount, 0, data.field) : this.fields.splice(index, 1, data.field);
                    this.draft = null;
                    this.errors = {};
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    this.showErrors(error);
                } finally {
                    this.busy = false;
                }
            },
            async move(index, direction) {
                const active = this.fields.filter(f => !f.archived);
                const next = index + direction;
                if (next < 0 || next >= active.length || this.busy) return;
                active.splice(next, 0, ...active.splice(index, 1));
                this.busy = true;
                try {
                    const { data } = await this.$axios.put(@js(route('admin.dam.configuration.fields.order')), { ids: active.map(f => f.id) });
                    this.fields = data.fields;
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || 'Could not reorder.' });
                } finally {
                    this.busy = false;
                }
            },
            remove(field) {
                const url = @js(route('admin.dam.configuration.fields.destroy', '__id__')).replace('__id__', field.id);
                const confirmThen = (message, confirm) => this.$emitter.emit('open-confirm-modal', {
                    message,
                    options: { btnAgree: @js(trans('dam::app.admin.metadata-fields.delete-btn')), btnDisagree: @js(trans('dam::app.admin.metadata-fields.cancel')), btnAgreeClass: 'danger-button', btnDisagreeClass: 'transparent-button' },
                    agree: () => this.destroy(url, confirm, confirmThen),
                    disagree: () => {},
                });
                const [one, many] = @js(trans('dam::app.admin.metadata-fields.delete.confirm')).split('|');
                field.values_count > 0
                    ? confirmThen((field.values_count === 1 ? one : many).replace(':count', field.values_count), true)
                    : confirmThen(@js(trans('dam::app.admin.metadata-fields.delete.confirm-empty')).replace(':field', () => field.label), false);
            },
            async destroy(url, confirm, confirmThen) {
                try {
                    const { data } = await this.$axios.delete(url, { data: { confirm } });
                    const { data: list } = await this.$axios.get(@js(route('admin.dam.configuration.fields.index')), { headers: { Accept: 'application/json' } });
                    this.fields = list.fields;
                    if (this.draft && !this.fields.some(f => f.id === this.draft.id && !f.archived)) this.draft = null;
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    // Values arrived since the list loaded: ask again, this time to archive.
                    if (error.response?.status === 409) return confirmThen(error.response.data.message, true);
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || 'Could not delete.' });
                }
            },
            async restore(field) {
                try {
                    const { data } = await this.$axios.post(@js(route('admin.dam.configuration.fields.restore', '__id__')).replace('__id__', field.id));
                    const { data: list } = await this.$axios.get(@js(route('admin.dam.configuration.fields.index')), { headers: { Accept: 'application/json' } });
                    this.fields = list.fields;
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || 'Could not restore.' });
                }
            },
        },
    });
    </script>
    @endPushOnce
</x-admin::layouts>
