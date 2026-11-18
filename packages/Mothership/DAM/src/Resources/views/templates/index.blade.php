<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.templates.title')
    </x-slot:title>

    {{-- The Library picker, choose-only: a template's base image is a Library image the author can open. --}}
    @if ($canManage)
        @include('dam::asset.picker-modal')

        <v-dam-asset-picker></v-dam-asset-picker>
    @endif

    <v-dam-templates></v-dam-templates>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-templates-template">
            <div data-dam-templates>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <x-admin::layouts.page-header
                        :title="trans('dam::app.admin.templates.title')"
                        :description="trans('dam::app.admin.templates.description')"
                        :parents="[['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')]]"
                    />

                    @if ($canManage)
                        <button type="button" class="primary-button" data-dam-template-new @click="openCreate">
                            @lang('dam::app.admin.templates.new')
                        </button>
                    @endif
                </div>

                <div class="mt-4 flex flex-col gap-2.5 sm:flex-row sm:items-center">
                    <label class="relative block w-full sm:max-w-sm">
                        <span class="sr-only">@lang('dam::app.admin.templates.search')</span>
                        <span class="icon-search pointer-events-none absolute top-1/2 -translate-y-1/2 text-2xl ltr:left-2 rtl:right-2 text-gray-500" aria-hidden="true"></span>
                        <input
                            v-model="search"
                            type="search"
                            class="block w-full rounded-lg border bg-white py-1.5 text-sm leading-6 text-gray-700 outline-none transition hover:border-gray-400 focus:border-gray-400 ltr:pl-10 ltr:pr-3 rtl:pl-3 rtl:pr-10 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300"
                            placeholder="@lang('dam::app.admin.templates.search')"
                            data-dam-templates-search
                            @input="queueLoad"
                        >
                    </label>

                    @if ($canManage)
                        <label class="block w-full sm:w-48">
                            <span class="sr-only">@lang('dam::app.admin.templates.col-status')</span>
                            <select
                                v-model="status"
                                class="block w-full rounded-lg border bg-white px-3 py-1.5 text-sm leading-6 text-gray-700 outline-none dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300"
                                data-dam-templates-status
                                @change="load"
                            >
                                <option value="">@lang('dam::app.admin.templates.all-statuses')</option>
                                @foreach (\Mothership\DAM\Models\Template::STATUSES as $status)
                                    <option value="{{ $status }}">@lang('dam::app.admin.templates.statuses.'.$status)</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>

                <div class="mt-4 overflow-hidden rounded-lg bg-white box-shadow dark:bg-cherry-900">
                    <div v-if="! loaded" class="space-y-2 p-4" aria-busy="true" data-list-loading>
                        <div v-for="n in 3" :key="n" class="h-10 animate-pulse rounded bg-gray-100 dark:bg-cherry-800"></div>
                    </div>

                    <x-admin::load-error
                        v-else-if="failed"
                        class="m-4"
                        :title="trans('dam::app.admin.templates.load-failed')"
                        message="failed.message"
                        retryable="failed.retryable"
                        retry="load()"
                    />

                    <p v-else-if="! templates.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        <span v-if="search || status">@lang('dam::app.admin.templates.no-matches')</span>
                        <span v-else>{{ $canManage ? trans('dam::app.admin.templates.empty') : trans('dam::app.admin.templates.empty-filler') }}</span>
                    </p>

                    <!-- Below xl each template is a card with labelled lines; from xl it is a table row. -->
                    <div v-else>
                        <div class="hidden border-b bg-primary-50 px-4 py-2.5 text-sm font-semibold text-gray-600 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300 xl:grid xl:grid-cols-[4rem_minmax(0,3fr)_8rem_10rem_8rem_11rem_12rem] xl:items-center xl:gap-3">
                            <span></span>
                            <span>@lang('dam::app.admin.templates.col-name')</span>
                            <span>@lang('dam::app.admin.templates.col-status')</span>
                            <span>@lang('dam::app.admin.templates.col-version')</span>
                            <span>@lang('dam::app.admin.templates.col-uses')</span>
                            <span>@lang('dam::app.admin.templates.col-updated')</span>
                            <span></span>
                        </div>

                        <div
                            v-for="template in templates"
                            :key="template.id"
                            class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-3 gap-y-1 border-b px-4 py-3 text-sm text-gray-600 last:border-b-0 dark:border-cherry-800 dark:text-gray-300 xl:grid-cols-[4rem_minmax(0,3fr)_8rem_10rem_8rem_11rem_12rem] xl:items-center xl:py-2.5"
                            data-dam-template-row
                        >
                            <span class="row-span-5 flex h-16 w-16 items-center justify-center overflow-hidden rounded-md border bg-gray-50 dark:border-cherry-800 dark:bg-cherry-800 xl:row-span-1">
                                <img v-if="template.layer_url" :src="template.layer_url" alt="" loading="lazy" class="max-h-full max-w-full object-contain">
                                <span v-else class="icon-dam-preview text-2xl text-gray-400" aria-hidden="true"></span>
                            </span>

                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-gray-800 dark:text-white" v-text="template.name"></span>
                                <span v-if="template.description" class="block truncate text-xs text-gray-500 dark:text-gray-400" v-text="template.description"></span>
                            </span>

                            <span>
                                <span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.templates.col-status')</span>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="statusClass(template.status)"
                                    v-text="statusLabel(template.status)"
                                ></span>
                            </span>

                            <span>
                                <span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.templates.col-version')</span>
                                <span v-text="versionLabel(template)"></span>
                            </span>

                            <span>
                                <span class="me-1 text-gray-500 dark:text-gray-400 xl:hidden">@lang('dam::app.admin.templates.col-uses')</span>
                                <span v-text="template.uses"></span>
                            </span>

                            <span class="hidden xl:inline" v-text="fmtDate(template.updated_at)"></span>

                            <span class="col-start-2 mt-1.5 flex flex-wrap gap-2 xl:col-start-auto xl:mt-0 xl:justify-end">
                                <a v-if="template.use_url && canUse" :href="useUrl(template)" class="primary-button whitespace-nowrap" data-dam-template-use>
                                    @lang('dam::app.admin.templates.use-action')
                                </a>
                                <a v-if="template.edit_url" :href="template.edit_url" class="secondary-button whitespace-nowrap" data-dam-template-edit>
                                    @lang('dam::app.admin.templates.edit')
                                </a>
                            </span>
                        </div>
                    </div>
                </div>

                @if ($canManage)
                    <!-- New template: a name and a Library base image; the author lands on the authoring page. -->
                    <div
                        v-if="create.open"
                        class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/50 p-4"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="dam-template-create-title"
                        @keydown="onCreateKeydown"
                    >
                        <form class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-cherry-900" data-dam-template-create @submit.prevent="submitCreate">
                            <h2 id="dam-template-create-title" class="text-lg font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.templates.create.title')</h2>

                            <label class="mt-4 block text-sm font-medium text-gray-800 dark:text-white">
                                @lang('dam::app.admin.templates.create.name')
                                <input ref="createName" v-model="create.name" type="text" maxlength="191" required class="mt-1 block w-full rounded-md border px-3 py-2 text-sm font-normal text-gray-700 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300">
                            </label>

                            <label class="mt-3 block text-sm font-medium text-gray-800 dark:text-white">
                                @lang('dam::app.admin.templates.create.description')
                                <textarea v-model="create.description" rows="2" maxlength="2000" class="mt-1 block w-full rounded-md border px-3 py-2 text-sm font-normal text-gray-700 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300"></textarea>
                            </label>

                            <div class="mt-3">
                                <span class="block text-sm font-medium text-gray-800 dark:text-white">@lang('dam::app.admin.templates.create.base')</span>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <button type="button" class="secondary-button" data-dam-template-base @click="chooseBase">
                                        @lang('dam::app.admin.templates.create.choose-base')
                                    </button>
                                    <span class="text-sm text-gray-600 dark:text-gray-300" v-text="create.baseId ? '#' + create.baseId : @js(trans('dam::app.admin.templates.create.base-none'))"></span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.templates.create.base-hint')</p>
                            </div>

                            <p v-if="create.error" class="mt-3 text-sm text-red-600 dark:text-red-400" role="alert" v-text="create.error"></p>

                            <div class="mt-5 flex justify-end gap-2">
                                <button type="button" class="transparent-button" @click="closeCreate">@lang('dam::app.admin.templates.create.cancel')</button>
                                <button type="submit" class="primary-button" :disabled="create.busy || ! create.name || ! create.baseId">@lang('dam::app.admin.templates.create.submit')</button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        </script>

        <script type="module">
            app.component('v-dam-templates', {
                template: '#v-dam-templates-template',

                data() {
                    return {
                        templates: [],
                        loaded: false,
                        // null, or the shared description of the failed request (message, retryable).
                        failed: null,
                        search: '',
                        status: '',
                        canUse: @js($canUse),
                        folderId: @js($folderId),
                        create: { open: false, name: '', description: '', baseId: null, busy: false, error: '' },
                    };
                },

                mounted() {
                    this.load();
                },

                methods: {
                    queueLoad() {
                        clearTimeout(this._timer);
                        this._timer = setTimeout(() => this.load(), 250);
                    },

                    load() {
                        const ticket = this._ticket = {};

                        return this.$axios.get(@js(route('admin.dam.templates.list')), { params: { search: this.search || undefined, status: this.status || undefined } })
                            .then(({ data }) => {
                                if (this._ticket !== ticket) return;
                                this.templates = data.templates;
                                this.failed = null;
                            })
                            .catch((error) => {
                                if (this._ticket !== ticket || (globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true }).cancelled) return;
                                // A list that never loaded must not read as "no templates".
                                this.failed = (globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true });
                            })
                            .finally(() => { this.loaded = true; });
                    },

                    useUrl(template) {
                        return this.folderId ? template.use_url + '?directory_id=' + encodeURIComponent(this.folderId) : template.use_url;
                    },

                    statusLabel(status) {
                        return @js(trans('dam::app.admin.templates.statuses'))[status] ?? status;
                    },

                    statusClass(status) {
                        return {
                            published: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                            draft: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                            archived: 'bg-gray-100 text-gray-700 dark:bg-cherry-800 dark:text-gray-300',
                        }[status] ?? '';
                    },

                    versionLabel(template) {
                        const parts = [];

                        if (template.published_version) parts.push(@js(trans('dam::app.admin.templates.version')).replace(':version', template.published_version));
                        if (template.draft_version) parts.push(@js(trans('dam::app.admin.templates.draft-pending')).replace(':version', template.draft_version));

                        return parts.length ? parts.join(' · ') : @js(trans('dam::app.admin.templates.none'));
                    },

                    fmtDate(iso) {
                        return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
                    },

                    openCreate(event) {
                        this._createOpener = event?.currentTarget ?? document.activeElement;
                        this.create = { open: true, name: '', description: '', baseId: null, busy: false, error: '' };
                        this.$nextTick(() => this.$refs.createName?.focus());
                    },

                    closeCreate() {
                        this.create.open = false;
                        this.$nextTick(() => this._createOpener?.focus?.());
                    },

                    // Escape closes the dialog and hands focus back to "New template"; Tab cycles inside it instead of leaking to the page behind.
                    onCreateKeydown(event) {
                        if (event.key === 'Escape') { event.preventDefault(); this.closeCreate(); return; }
                        if (event.key !== 'Tab') return;
                        const focusable = Array.from(event.currentTarget.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled])'));
                        if (! focusable.length) return;
                        const first = focusable[0];
                        const last = focusable[focusable.length - 1];
                        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                        else if (! event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                    },

                    chooseBase() {
                        this.$emitter.emit('dam-asset-picker:open', {
                            ids: this.create.baseId ? [this.create.baseId] : [],
                            choose: true,
                            onAssign: (ids) => {
                                if (ids.length !== 1) {
                                    this.create.error = @js(trans('dam::app.admin.templates.create.pick-one'));
                                    return;
                                }
                                this.create.error = '';
                                this.create.baseId = Number(ids[0]);
                            },
                        });
                    },

                    async submitCreate() {
                        if (this.create.busy) return;
                        this.create.busy = true;
                        this.create.error = '';

                        try {
                            const { data } = await this.$axios.post(@js(route('admin.dam.templates.store')), {
                                name: this.create.name,
                                description: this.create.description || null,
                                base_asset_id: this.create.baseId,
                                output_directory_id: this.folderId,
                            });

                            window.location.href = data.template.edit_url;
                        } catch (error) {
                            const errors = error?.response?.data?.errors;
                            this.create.error = errors ? Object.values(errors).flat()[0] : (error?.response?.data?.message || @js(trans('dam::app.admin.templates.action-failed')));
                            this.create.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
