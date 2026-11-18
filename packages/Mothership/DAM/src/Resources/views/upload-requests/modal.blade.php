{{--
    P5a (D1): create or edit a guest upload request. Opened from a folder's context menu (Library tree and explorer)
    with `open-upload-request-modal` { directoryId, directoryName }, or from the Upload requests page with { request }.
    After a create, the dialog shows the new link once, with Copy. The server shows the link again only to the people
    the #126 rule names. Emits `dam:upload-request-saved` with the request as the server returned it.
--}}
<script type="text/x-template" id="v-dam-upload-request-modal-template">
    <div>
        <div v-if="showModal" class="relative z-[10020]">
            <v-modal :is-active="true" @toggle="onToggle">
                <template v-slot:header="{ toggle }">
                    <div class="flex justify-between items-center gap-2.5 px-4 py-3 border-b dark:border-gray-800">
                        <p class="min-w-0 flex-1 truncate text-lg text-gray-800 dark:text-white font-bold" data-dam-upload-request-title v-text="heading"></p>
                        <span class="icon-cancel shrink-0 text-3xl cursor-pointer hover:bg-primary-50 dark:hover:bg-cherry-800 hover:rounded-md" @click="toggle"></span>
                    </div>
                </template>

                <template v-slot:content>
                    <div class="px-4 py-3 max-h-[calc(100vh-9rem)] overflow-y-auto" data-dam-upload-request-dialog>
                        {{-- After a create: the link, once. --}}
                        <div v-if="created" class="flex flex-col gap-3" data-dam-upload-request-created>
                            <p class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.upload-requests.modal.created-intro')</p>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <input type="text" readonly :value="created.url" class="min-w-0 flex-1 rounded-md border border-gray-300 dark:border-cherry-700 bg-gray-50 dark:bg-cherry-800 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" data-dam-upload-request-url @focus="$event.target.select()" />
                                <button type="button" class="primary-button justify-center" data-dam-upload-request-copy @click="copy(created.url)">@lang('dam::app.admin.upload-requests.copy-link')</button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-slate-400" v-text="summary(created)"></p>
                            <div class="flex flex-wrap justify-end gap-2 pt-2">
                                <a :href="showUrl(created.id)" class="secondary-button">@lang('dam::app.admin.upload-requests.modal.open-request')</a>
                                <button type="button" class="transparent-button" @click="close">@lang('dam::app.admin.upload-requests.modal.done')</button>
                            </div>
                        </div>

                        <form v-else class="flex flex-col gap-4" @submit.prevent="save" data-dam-upload-request-form>
                            <p v-if="directoryName" class="text-sm text-gray-600 dark:text-slate-300" data-dam-upload-request-folder>
                                <span v-text="folderLine"></span>
                            </p>

                            <div class="flex flex-col gap-1">
                                <label for="dam-upload-request-title" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.title')</label>
                                <input id="dam-upload-request-title" v-model="form.title" type="text" maxlength="191" required class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.title-hint'))" data-dam-upload-request-field="title" />
                            </div>

                            <div class="flex flex-col gap-1">
                                <label for="dam-upload-request-instructions" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.instructions')</label>
                                <textarea id="dam-upload-request-instructions" v-model="form.instructions" rows="3" maxlength="5000" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.instructions-hint'))" data-dam-upload-request-field="instructions"></textarea>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="flex flex-col gap-1">
                                    <label for="dam-upload-request-expiry" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.expiry')</label>
                                    <select id="dam-upload-request-expiry" v-model="form.expiry" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" data-dam-upload-request-field="expiry">
                                        <option v-if="editing" value="keep" v-text="keepExpiryLabel"></option>
                                        <option v-for="option in expiryOptions" :key="option.value" :value="option.value" v-text="option.label"></option>
                                    </select>
                                </div>

                                <div class="flex flex-col gap-1">
                                    <label for="dam-upload-request-max-files" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.max-files')</label>
                                    <input id="dam-upload-request-max-files" v-model="form.max_files" type="number" min="1" :max="maxFilesCap" inputmode="numeric" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.no-limit'))" data-dam-upload-request-field="max_files" />
                                </div>

                                <div class="flex flex-col gap-1">
                                    <label for="dam-upload-request-max-size" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.max-size')</label>
                                    <input id="dam-upload-request-max-size" v-model="form.max_mb" type="number" min="1" inputmode="numeric" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.no-limit'))" data-dam-upload-request-field="max_mb" />
                                </div>

                                <div class="flex flex-col gap-1">
                                    <label for="dam-upload-request-types" class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.fields.types')</label>
                                    <input id="dam-upload-request-types" v-model="form.types" type="text" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.types-hint'))" data-dam-upload-request-field="types" />
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="flex items-start gap-2 cursor-pointer select-none">
                                    <input type="checkbox" class="sr-only peer" v-model="form.password_enabled" data-dam-upload-request-password-toggle />
                                    <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl shrink-0"></span>
                                    <span class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.upload-requests.fields.password-require')</span>
                                </label>
                                <div v-if="form.password_enabled" class="flex flex-col gap-1">
                                    <label for="dam-upload-request-password" class="sr-only">@lang('dam::app.admin.upload-requests.fields.password')</label>
                                    <input id="dam-upload-request-password" v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200" :placeholder="@js(trans('dam::app.admin.upload-requests.fields.password'))" data-dam-upload-request-password />
                                    <p v-if="passwordTooShort" class="text-xs text-red-600 dark:text-red-400">@lang('dam::app.admin.upload-requests.fields.password-too-short')</p>
                                    <p v-else-if="editing && hadPassword" class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.upload-requests.fields.password-keep')</p>
                                </div>
                            </div>

                            <p class="rounded-md bg-primary-50 dark:bg-cherry-800 px-3 py-2 text-xs text-gray-600 dark:text-slate-300">@lang('dam::app.admin.upload-requests.modal.privacy')</p>

                            <p v-if="error" class="text-sm text-red-600 dark:text-red-400" role="alert" data-dam-upload-request-error v-text="error"></p>

                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" class="transparent-button" @click="close">@lang('dam::app.admin.upload-requests.modal.cancel')</button>
                                <button type="submit" class="primary-button" :disabled="saving || ! form.title.trim() || passwordMissing || passwordTooShort" data-dam-upload-request-save>
                                    <span v-if="saving">@lang('dam::app.admin.upload-requests.modal.saving')</span>
                                    <span v-else-if="editing">@lang('dam::app.admin.upload-requests.modal.save')</span>
                                    <span v-else>@lang('dam::app.admin.upload-requests.modal.create')</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </template>
            </v-modal>
        </div>
    </div>
</script>

<script type="module">
    app.component('v-dam-upload-request-modal', {
        template: '#v-dam-upload-request-modal-template',

        data() {
            return {
                showModal: false,
                editing: null,
                directoryId: null,
                directoryName: '',
                hadPassword: false,
                created: null,
                saving: false,
                error: '',
                form: this.blankForm(),
                maxFilesCap: @js((int) config('dam.upload_requests.max_files_cap')),
                expiryOptions: [
                    { value: '7', label: @js(trans('dam::app.admin.upload-requests.expiry.days', ['days' => 7])) },
                    { value: '14', label: @js(trans('dam::app.admin.upload-requests.expiry.days', ['days' => 14])) },
                    { value: '30', label: @js(trans('dam::app.admin.upload-requests.expiry.days', ['days' => 30])) },
                    { value: '90', label: @js(trans('dam::app.admin.upload-requests.expiry.days', ['days' => 90])) },
                    { value: 'never', label: @js(trans('dam::app.admin.upload-requests.expiry.never')) },
                ],
            };
        },

        computed: {
            heading() {
                if (this.created) return @js(trans('dam::app.admin.upload-requests.modal.created-title'));

                return this.editing ? @js(trans('dam::app.admin.upload-requests.modal.edit-title')) : @js(trans('dam::app.admin.upload-requests.modal.create-title'));
            },

            folderLine() {
                return @js(trans('dam::app.admin.upload-requests.modal.folder')).replace(':folder', this.directoryName);
            },

            keepExpiryLabel() {
                const at = this.editing?.expires_at;

                return at
                    ? @js(trans('dam::app.admin.upload-requests.expiry.keep')).replace(':date', new Date(at).toLocaleDateString([], { dateStyle: 'medium' }))
                    : @js(trans('dam::app.admin.upload-requests.expiry.never'));
            },

            passwordMissing() {
                return this.form.password_enabled && ! this.form.password && ! (this.editing && this.hadPassword);
            },

            passwordTooShort() {
                return this.form.password_enabled && this.form.password.length > 0 && this.form.password.length < 4;
            },
        },

        mounted() {
            this.openHandler = (payload = {}) => this.open(payload);
            this.$emitter.on('open-upload-request-modal', this.openHandler);
        },

        unmounted() {
            this.$emitter.off('open-upload-request-modal', this.openHandler);
        },

        methods: {
            blankForm() {
                return { title: '', instructions: '', expiry: '14', max_files: '', max_mb: '', types: '', password_enabled: false, password: '' };
            },

            open({ directoryId = null, directoryName = '', request = null } = {}) {
                this.created = null;
                this.error = '';
                this.saving = false;
                this.editing = request;
                this.directoryId = request ? request.directory?.id : directoryId;
                this.directoryName = request ? (request.directory?.path || request.directory?.name || '') : directoryName;
                this.hadPassword = !! request?.has_password;
                this.form = request ? {
                    title: request.title ?? '',
                    instructions: request.instructions ?? '',
                    expiry: 'keep',
                    max_files: request.max_files ?? '',
                    max_mb: request.max_bytes_per_file ? Math.max(1, Math.round(request.max_bytes_per_file / 1048576)) : '',
                    types: (request.allowed_types ?? []).join(', '),
                    password_enabled: !! request.has_password,
                    password: '',
                } : this.blankForm();
                this.showModal = true;
            },

            onToggle({ isActive }) {
                if (! isActive) this.close();
            },

            close() {
                this.showModal = false;
            },

            payload() {
                const types = this.form.types.split(/[\s,;]+/).map(type => type.trim().replace(/^\./, '').toLowerCase()).filter(Boolean);
                const body = {
                    title: this.form.title.trim(),
                    instructions: this.form.instructions.trim() || null,
                    max_files: this.form.max_files === '' ? null : Number(this.form.max_files),
                    max_bytes_per_file: this.form.max_mb === '' ? null : Number(this.form.max_mb) * 1048576,
                    allowed_types: types.length ? types : null,
                };

                if (this.form.expiry === 'never') {
                    body.no_expiry = true;
                } else if (this.form.expiry !== 'keep') {
                    body.no_expiry = false;
                    body.expiry_days = Number(this.form.expiry);
                }

                if (this.form.password_enabled && this.form.password) {
                    body.password = this.form.password;
                } else if (! this.form.password_enabled && this.editing && this.hadPassword) {
                    body.remove_password = true;
                }

                if (! this.editing) body.directory_id = this.directoryId;

                return body;
            },

            async save() {
                if (this.saving) return;

                this.saving = true;
                this.error = '';

                try {
                    const { data } = this.editing
                        ? await this.$axios.patch(@js(route('admin.dam.upload-requests.update', ':id')).replace(':id', this.editing.id), this.payload())
                        : await this.$axios.post(@js(route('admin.dam.upload-requests.store')), this.payload());

                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    this.$emitter.emit('dam:upload-request-saved', data.request);

                    if (this.editing) {
                        this.close();
                    } else {
                        this.created = data.request;
                    }
                } catch (error) {
                    const errors = error?.response?.data?.errors;

                    this.error = errors ? Object.values(errors).flat()[0] : (error?.response?.data?.message || @js(trans('dam::app.admin.upload-requests.save-failed')));
                } finally {
                    this.saving = false;
                }
            },

            summary(request) {
                const parts = [];

                parts.push(request.expires_at
                    ? @js(trans('dam::app.admin.upload-requests.expires-on')).replace(':date', new Date(request.expires_at).toLocaleDateString([], { dateStyle: 'medium' }))
                    : @js(trans('dam::app.admin.upload-requests.expiry.never')));

                if (request.has_password) parts.push(@js(trans('dam::app.admin.upload-requests.password-protected')));

                return parts.join(' · ');
            },

            showUrl(id) {
                return @js(route('admin.dam.upload-requests.show', ':id')).replace(':id', id);
            },

            copy(url) {
                const done = () => this.$emitter.emit('add-flash', { type: 'success', message: @js(trans('dam::app.admin.upload-requests.copied')) });
                const failed = () => this.$emitter.emit('add-flash', { type: 'error', message: @js(trans('dam::app.admin.upload-requests.copy-failed')) });

                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(url).then(done, failed);
                } else {
                    failed();
                }
            },
        },
    });
</script>
