{{--
    P5a (D1): one request and what guests sent to it. Each file is pending until someone with edit rights on the intake
    folder accepts it (into the Library, in place or moved to another folder) or rejects it (to the trash). One file or
    many at once. Files the viewer may not see are only counted. Duplicate notices are for staff alone.
--}}
<x-admin::layouts>
    <x-slot:title>
        {{ $uploadRequest['title'] }}
    </x-slot:title>

    <v-dam-upload-request-review></v-dam-upload-request-review>

    {{-- The modal component's template ships with <x-admin::modal> (see index.blade.php). --}}
    <x-admin::modal />

    @include('dam::upload-requests.partials.row-actions')
    @include('dam::components.explorer.view-toggle')
    @include('dam::components.explorer.folder-picker')

    @pushOnce('scripts')
        @include('dam::upload-requests.modal')

        <script type="text/x-template" id="v-dam-upload-request-review-template">
            <div>
                <x-admin::layouts.page-header
                    :title="$uploadRequest['title']"
                    :description="trans('dam::app.admin.upload-requests.review.description')"
                    :parents="[
                        ['label' => trans('dam::app.admin.components.layouts.sidebar.library'), 'url' => route('admin.dam.index')],
                        ['label' => trans('dam::app.admin.upload-requests.title'), 'url' => route('admin.dam.upload-requests.index')],
                    ]"
                />

                <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow p-4 flex flex-col gap-3" data-dam-upload-request-summary>
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2 xl:grid-cols-4">
                            <div><dt class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.col-folder')</dt><dd class="text-gray-800 dark:text-white break-words" v-text="request.directory?.path ?? '—'"></dd></div>
                            <div><dt class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.col-status')</dt><dd><span :class="request.status === 'open' ? 'label-active' : 'label-info'" v-text="statusLabel(request.status)"></span></dd></div>
                            <div><dt class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.col-expires')</dt><dd class="text-gray-800 dark:text-white" v-text="request.expires_at ? fmtDate(request.expires_at) : neverLabel"></dd></div>
                            <div><dt class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.col-limits')</dt><dd class="text-gray-800 dark:text-white" v-text="limitsLabel"></dd></div>
                        </dl>
                        <v-dam-upload-request-actions :request="request" @changed="load"></v-dam-upload-request-actions>
                    </div>
                    <p v-if="request.instructions" class="whitespace-pre-line text-sm text-gray-600 dark:text-slate-300" v-text="request.instructions"></p>
                </div>

                <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden" data-dam-upload-request-submissions>
                    {{-- The bulk bar: what is selected, and the decision for all of it. --}}
                    <div v-if="canDecide && pendingFiles.length" class="sticky top-0 z-[1] flex flex-col gap-2 border-b bg-primary-50 px-4 py-2.5 dark:border-cherry-800 dark:bg-cherry-800 sm:flex-row sm:items-center sm:justify-between" data-dam-intake-bulk>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-200 cursor-pointer select-none">
                            <input type="checkbox" class="sr-only peer" :checked="allSelected" @change="toggleAll" data-dam-intake-select-all />
                            <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 rounded-md text-2xl"></span>
                            <span v-text="selectedLabel"></span>
                        </label>
                        <span class="flex flex-wrap gap-2">
                            <button type="button" class="primary-button" :disabled="busy || ! selected.length" data-dam-intake-accept-selected @click="decide('accept', selected)">@lang('dam::app.admin.upload-requests.review.accept-selected')</button>
                            <button type="button" class="secondary-button" :disabled="busy || ! selected.length" data-dam-intake-accept-into @click="pickDestination(selected)">@lang('dam::app.admin.upload-requests.review.accept-into')</button>
                            <button type="button" class="danger-button" :disabled="busy || ! selected.length" data-dam-intake-reject-selected @click="confirmReject(selected)">@lang('dam::app.admin.upload-requests.review.reject-selected')</button>
                        </span>
                    </div>

                    <x-admin::load-error
                        v-if="loaded && failed"
                        class="m-4"
                        :title="trans('dam::app.admin.upload-requests.load-failed')"
                        message="failed.message"
                        retryable="failed.retryable"
                        retry="load()"
                    />

                    <div v-else-if="loaded && ! submissions.length" class="px-4 py-10 text-center" data-dam-upload-request-no-submissions>
                        <p class="text-sm font-semibold text-gray-700 dark:text-slate-200">@lang('dam::app.admin.upload-requests.review.empty')</p>
                        <p v-if="hidden" class="mt-1 text-xs text-gray-500 dark:text-gray-400" v-text="hiddenLabel"></p>
                    </div>

                    <section v-for="submission in submissions" :key="submission.id" class="border-b last:border-b-0 dark:border-cherry-800" :data-dam-submission="submission.id">
                        <header class="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-3 pb-1">
                            <p class="text-sm font-semibold text-gray-800 dark:text-white" v-text="guestLabel(submission)"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" v-text="fmtDateTime(submission.created_at)"></p>
                        </header>

                        <ul>
                            <li v-for="file in submission.files" :key="file.id" class="flex flex-col gap-2 px-4 py-2.5 sm:flex-row sm:items-center" :data-dam-intake-file="file.id" :data-status="file.status">
                                <span class="flex min-w-0 flex-1 items-center gap-3">
                                    <label v-if="canDecide && file.status === 'pending'" class="cursor-pointer select-none">
                                        <input type="checkbox" class="sr-only peer" :value="file.id" v-model="selected" :aria-label="file.name" />
                                        <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 rounded-md text-2xl"></span>
                                    </label>
                                    <span class="h-12 w-12 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-cherry-800">
                                        <img v-if="file.thumbnail" :src="file.thumbnail" alt="" class="h-full w-full object-cover" loading="lazy" v-on:error="$event.target.remove()" />
                                    </span>
                                    <span class="flex min-w-0 flex-col">
                                        <a v-if="file.url" :href="file.url" class="truncate text-sm text-gray-800 hover:text-primary-700 dark:text-white" :title="file.name" v-text="file.name"></a>
                                        <span v-else class="truncate text-sm text-gray-800 dark:text-white" :title="file.name" v-text="file.name"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" v-text="fileMeta(file)"></span>
                                        <span v-if="file.duplicate" class="mt-0.5 flex items-start gap-1 text-xs text-amber-700 dark:text-amber-300" data-dam-intake-duplicate>
                                            <span class="icon-information text-sm"></span>
                                            <a :href="assetUrl(file.duplicate.match.id)" class="underline" v-text="file.duplicate.message"></a>
                                        </span>
                                    </span>
                                </span>
                                <span class="flex flex-wrap items-center gap-2 sm:justify-end">
                                    <span :class="statusBadge(file.status)" v-text="fileStatus(file.status)"></span>
                                    <template v-if="canDecide && file.status === 'pending'">
                                        <button type="button" class="secondary-button" :disabled="busy" data-dam-intake-accept @click="decide('accept', [file.id])">@lang('dam::app.admin.upload-requests.review.accept')</button>
                                        <button type="button" class="transparent-button !text-red-600 dark:!text-red-400" :disabled="busy" data-dam-intake-reject @click="confirmReject([file.id])">@lang('dam::app.admin.upload-requests.review.reject')</button>
                                    </template>
                                </span>
                            </li>
                        </ul>
                    </section>

                    <p v-if="loaded && submissions.length && hidden" class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400" data-dam-intake-hidden v-text="hiddenLabel"></p>
                    <p v-if="loaded && ! canDecide && pendingFiles.length" class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.upload-requests.review.no-rights')</p>
                </div>

                <v-dam-folder-picker :open="picker.open" tab-id="upload-request-accept" @picked="acceptInto" @close="picker.open = false"></v-dam-folder-picker>
                <v-dam-upload-request-modal></v-dam-upload-request-modal>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-upload-request-review', {
                template: '#v-dam-upload-request-review-template',

                data() {
                    return {
                        request: @js($uploadRequest),
                        canDecide: @js($canDecide),
                        submissions: [],
                        hidden: 0,
                        selected: [],
                        loaded: false,
                        failed: null,
                        busy: false,
                        picker: { open: false, ids: [] },
                    };
                },

                computed: {
                    pendingFiles() {
                        return this.submissions.flatMap(s => s.files).filter(f => f.status === 'pending');
                    },

                    allSelected() {
                        return this.pendingFiles.length > 0 && this.selected.length === this.pendingFiles.length;
                    },

                    selectedLabel() {
                        return @js(trans('dam::app.admin.upload-requests.review.selected')).replace(':count', this.selected.length).replace(':total', this.pendingFiles.length);
                    },

                    hiddenLabel() {
                        return @js(trans('dam::app.admin.upload-requests.review.hidden')).replace(':count', this.hidden);
                    },

                    neverLabel() {
                        return @js(trans('dam::app.admin.upload-requests.expiry.never'));
                    },

                    limitsLabel() {
                        const parts = [];

                        if (this.request.max_files) parts.push(@js(trans('dam::app.admin.upload-requests.limits.files')).replace(':count', this.request.max_files));
                        if (this.request.max_bytes_per_file) parts.push(@js(trans('dam::app.admin.upload-requests.limits.size')).replace(':size', this.fmtBytes(this.request.max_bytes_per_file)));
                        if (this.request.allowed_types?.length) parts.push(this.request.allowed_types.join(', '));
                        if (this.request.has_password) parts.push(@js(trans('dam::app.admin.upload-requests.password-protected')));

                        return parts.length ? parts.join(' · ') : @js(trans('dam::app.admin.upload-requests.limits.none'));
                    },
                },

                mounted() {
                    this.load();
                    this.savedHandler = () => this.load();
                    this.$emitter.on('dam:upload-request-saved', this.savedHandler);
                },

                unmounted() {
                    this.$emitter.off('dam:upload-request-saved', this.savedHandler);
                },

                methods: {
                    load() {
                        return this.$axios.get(@js(route('admin.dam.upload-requests.submissions', $uploadRequest['id'])))
                            .then(({ data }) => {
                                this.request = data.request;
                                this.canDecide = data.can_decide;
                                this.submissions = data.submissions;
                                this.hidden = data.hidden;
                                const pending = new Set(this.pendingFiles.map(f => f.id));
                                this.selected = this.selected.filter(id => pending.has(id));
                                this.failed = null;
                            })
                            .catch((error) => { this.failed = (globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true }); })
                            .finally(() => { this.loaded = true; });
                    },

                    toggleAll() {
                        this.selected = this.allSelected ? [] : this.pendingFiles.map(f => f.id);
                    },

                    pickDestination(ids) {
                        this.picker = { open: true, ids: [...ids] };
                    },

                    acceptInto({ id }) {
                        const ids = this.picker.ids;

                        this.picker = { open: false, ids: [] };
                        this.decide('accept', ids, id);
                    },

                    confirmReject(ids) {
                        this.$emitter.emit('open-confirm-modal', {
                            title: @js(trans('dam::app.admin.upload-requests.review.reject-confirm-title')),
                            message: @js(trans('dam::app.admin.upload-requests.review.reject-confirm-message')).split('|').at(ids.length === 1 ? 0 : -1).replace(':count', ids.length),
                            options: {
                                btnDisagree: @js(trans('dam::app.admin.upload-requests.modal.cancel')),
                                btnAgree: @js(trans('dam::app.admin.upload-requests.review.reject')),
                                btnAgreeClass: 'danger-button',
                                btnDisagreeClass: 'transparent-button',
                            },
                            agree: () => this.decide('reject', ids),
                            disagree: () => {},
                        });
                    },

                    async decide(decision, ids, destinationId = null) {
                        if (this.busy || ! ids.length) return;

                        this.busy = true;

                        const url = decision === 'accept'
                            ? @js(route('admin.dam.upload-requests.accept', $uploadRequest['id']))
                            : @js(route('admin.dam.upload-requests.reject', $uploadRequest['id']));

                        try {
                            const { data } = await this.$axios.post(url, { asset_ids: ids, destination_id: destinationId });

                            this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        } catch (error) {
                            this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message || @js(trans('dam::app.admin.upload-requests.action-failed')) });
                        }

                        this.busy = false;
                        this.load();
                    },

                    guestLabel(submission) {
                        const who = [submission.guest.name, submission.guest.email].filter(Boolean).join(' · ');

                        if (! who) return @js(trans('dam::app.admin.upload-requests.review.guest-anonymous'));

                        return @js(trans('dam::app.admin.upload-requests.review.guest-self-reported')).replace(':who', who);
                    },

                    statusLabel(status) {
                        return @js([
                            'open'    => trans('dam::app.admin.upload-requests.status.open'),
                            'closed'  => trans('dam::app.admin.upload-requests.status.closed'),
                            'expired' => trans('dam::app.admin.upload-requests.status.expired'),
                        ])[status] ?? status;
                    },

                    fileStatus(status) {
                        return @js([
                            'pending'  => trans('dam::app.admin.upload-requests.review.status-pending'),
                            'accepted' => trans('dam::app.admin.upload-requests.review.status-accepted'),
                            'rejected' => trans('dam::app.admin.upload-requests.review.status-rejected'),
                        ])[status] ?? status;
                    },

                    statusBadge(status) {
                        return { pending: 'label-pending', accepted: 'label-active', rejected: 'label-canceled' }[status] ?? 'label-info';
                    },

                    fileMeta(file) {
                        return [this.fmtBytes(file.size), file.extension?.toUpperCase()].filter(Boolean).join(' · ');
                    },

                    assetUrl(id) {
                        return @js(route('admin.dam.assets.edit', ':id')).replace(':id', id);
                    },

                    fmtBytes(bytes) {
                        if (! bytes) return '';
                        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));

                        return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
                    },

                    fmtDate(iso) {
                        return new Date(iso).toLocaleDateString([], { dateStyle: 'medium' });
                    },

                    fmtDateTime(iso) {
                        return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
