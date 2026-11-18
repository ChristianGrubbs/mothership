<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.share.index.title')
    </x-slot:title>

    {!! view_render_event('mothership.dam.shares.list.before') !!}

    <v-dam-shares-page>
        <x-admin::layouts.page-header
            :title="trans('dam::app.admin.dam.share.index.title')"
            :description="trans('dam::app.admin.dam.share.index.description')"
        />

        <x-admin::shimmer.datagrid />
    </v-dam-shares-page>

    {!! view_render_event('mothership.dam.shares.list.after') !!}

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-shares-page-template">
            <div>
                <x-admin::layouts.page-header
                    :title="trans('dam::app.admin.dam.share.index.title')"
                    :description="trans('dam::app.admin.dam.share.index.description')"
                />

                <x-admin::datagrid src="{{ route('admin.dam.shared-links.index') }}" ref="datagrid">
                    <template #header="{ columns, actions, applied, sortPage, isLoading }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.head />
                        </template>

                        <template v-else>
                            <div
                                class="row grid gap-2.5 min-h-[47px] px-4 py-2.5 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 bg-primary-50 dark:bg-cherry-900 font-semibold items-center"
                                :style="`grid-template-columns: ${gridTemplate}`"
                            >
                                <p
                                    v-for="column in columns.filter(c => c.visible !== false)"
                                    class="flex gap-1.5 items-center min-w-0"
                                    :class="{'cursor-pointer select-none hover:text-gray-800 dark:hover:text-white': column.sortable}"
                                    @click="sortPage(column)"
                                >
                                    <span
                                        class="block overflow-hidden text-ellipsis text-nowrap"
                                        :title="column.label"
                                        v-text="column.label"
                                    ></span>

                                    <i
                                        class="text-base text-gray-600 dark:text-gray-300 align-text-bottom"
                                        :class="[applied.sort.order === 'asc' ? 'icon-down-stat' : 'icon-up-stat']"
                                        v-if="column.index == applied.sort.column"
                                    ></i>
                                </p>

                                <div v-if="actions.length" class="flex gap-2.5 items-center justify-end select-none max-[639px]:sticky max-[639px]:right-0 max-[639px]:justify-self-end max-[639px]:pl-2 max-[639px]:z-[1] max-[639px]:whitespace-nowrap max-[639px]:bg-primary-50 dark:max-[639px]:bg-cherry-800">
                                    <p class="text-gray-600 dark:text-gray-300">
                                        @lang('admin::app.components.datagrid.table.actions')
                                    </p>
                                </div>
                            </div>
                        </template>
                    </template>

                    <template #body="{ columns, records, performAction }">
                        <div
                            v-for="record in records"
                            :key="record.id"
                            class="row grid gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-primary-50 hover:bg-opacity-30 dark:hover:bg-cherry-800"
                            :style="`grid-template-columns: ${gridTemplate}`"
                        >
                            <p class="truncate" :title="record.share_type">@{{ record.share_type }}</p>
                            <p class="truncate" :title="record.target_name">@{{ record.target_name }}</p>
                            <p class="truncate" :title="record.share_name">@{{ record.share_name }}</p>
                            <p class="truncate" :title="record.created_by_name">@{{ record.created_by_name }}</p>
                            <p v-html="record.status"></p>
                            <p class="break-words">@{{ record.expires_at }}</p>
                            <p>@{{ record.view_count }}</p>
                            <p>@{{ record.download_count }}</p>
                            <p class="break-words">@{{ record.created_at }}</p>

                            <div class="flex justify-end max-[639px]:sticky max-[639px]:right-0 max-[639px]:justify-self-end max-[639px]:pl-2 max-[639px]:z-[1] max-[639px]:whitespace-nowrap max-[639px]:bg-white dark:max-[639px]:bg-cherry-900" @click.stop>
                                {{-- Real buttons, so the keyboard and screen readers reach each action. The negative vertical margin keeps the row height and icon position the old link-wrapped icons had. --}}
                                <button
                                    type="button"
                                    v-if="record.actions.find(a => a.index === 'edit')"
                                    class="cursor-pointer rounded-md p-1.5 -mt-1.5 -mb-px text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :class="record.actions.find(a => a.index === 'edit')?.icon"
                                    :title="record.actions.find(a => a.index === 'edit')?.title"
                                    :aria-label="record.actions.find(a => a.index === 'edit')?.title"
                                    data-row-action="edit"
                                    @click="editShare(record)"
                                ></button>
                                <span v-else class="inline-block w-9 shrink-0" aria-hidden="true"></span>

                                {{-- Only the link's creator, its target's owner or a full admin gets the URL (#126). --}}
                                <button
                                    type="button"
                                    v-if="record.actions.find(a => a.index === 'copy_link')"
                                    class="cursor-pointer rounded-md p-1.5 -mt-1.5 -mb-px text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :class="record.actions.find(a => a.index === 'copy_link')?.icon"
                                    :title="record.actions.find(a => a.index === 'copy_link')?.title"
                                    :aria-label="record.actions.find(a => a.index === 'copy_link')?.title"
                                    data-row-action="copy_link"
                                    @click="copyShareLink(record.actions.find(a => a.index === 'copy_link')?.url)"
                                ></button>
                                <span v-else class="inline-block w-9 shrink-0" aria-hidden="true"></span>

                                {{-- Reissue (P2 S5a): a new URL for the same link. No glyph in the admin icon font fits, so an inline one. --}}
                                <button
                                    type="button"
                                    v-if="record.actions.find(a => a.index === 'reissue')"
                                    class="cursor-pointer rounded-md p-1.5 -mt-1.5 -mb-px text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :title="record.actions.find(a => a.index === 'reissue')?.title"
                                    :aria-label="record.actions.find(a => a.index === 'reissue')?.title"
                                    data-dam-share-reissue
                                    data-row-action="reissue"
                                    @click="reissueShare(record, record.actions.find(a => a.index === 'reissue')?.url)"
                                ><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: -0.125em" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.3-4.9L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0 0 14.3 4.9L20 16"/><path d="M20 21v-5h-5"/></svg></button>
                                <span v-else class="inline-block w-9 shrink-0" aria-hidden="true"></span>

                                <button
                                    type="button"
                                    v-if="record.actions.find(a => a.index === 'revoke')"
                                    class="cursor-pointer rounded-md p-1.5 -mt-1.5 -mb-px text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :class="record.actions.find(a => a.index === 'revoke')?.icon"
                                    :title="record.actions.find(a => a.index === 'revoke')?.title"
                                    :aria-label="record.actions.find(a => a.index === 'revoke')?.title"
                                    data-row-action="revoke"
                                    @click="revokeShare(record.actions.find(a => a.index === 'revoke')?.url)"
                                ></button>
                                <span v-else class="inline-block w-9 shrink-0" aria-hidden="true"></span>

                                <button
                                    type="button"
                                    v-if="record.actions.find(a => a.index === 'delete')"
                                    class="cursor-pointer rounded-md p-1.5 -mt-1.5 -mb-px text-2xl transition-all hover:bg-primary-100 dark:hover:bg-gray-800 max-sm:place-self-center"
                                    :class="record.actions.find(a => a.index === 'delete')?.icon"
                                    :title="record.actions.find(a => a.index === 'delete')?.title"
                                    :aria-label="record.actions.find(a => a.index === 'delete')?.title"
                                    data-row-action="delete"
                                    @click="performAction(record.actions.find(a => a.index === 'delete'))"
                                ></button>
                                <span v-else class="inline-block w-9 shrink-0" aria-hidden="true"></span>
                            </div>
                        </div>
                    </template>
                </x-admin::datagrid>

                <x-admin::modal ref="shareModal">
                    <x-slot:header>
                        <p class="text-lg text-gray-800 dark:text-white font-bold">
                            @{{ headerLabel }}
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex flex-col gap-4">
                            {{-- Inline, not the global confirm: that one stacks under this modal and could not be clicked. --}}
                            <div
                                v-if="pendingPublic"
                                class="flex flex-col gap-2 rounded-md border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-2"
                                data-share-public-confirm
                            >
                                <p class="text-sm text-amber-700 dark:text-amber-300">@{{ pendingPublic.message }}</p>
                                <div class="flex gap-2">
                                    <button type="button" class="primary-button" data-share-public-confirm-yes @click="runPending">@lang('dam::app.admin.dam.share.modal.public-confirm-continue')</button>
                                    <button type="button" class="transparent-button" data-share-public-confirm-no @click="pendingPublic = null">@lang('dam::app.admin.dam.share.modal.cancel')</button>
                                </div>
                            </div>

                            <div v-if="isLoading" class="text-sm text-gray-500 dark:text-slate-400 py-4 text-center">
                                @lang('dam::app.admin.dam.share.modal.loading')
                            </div>

                            <template v-else>
                                <div v-if="currentShare && currentShare.status === 'active'" class="flex items-center gap-2">
                                    <input
                                        type="text"
                                        :value="currentShare.share_url || currentShare.public_url"
                                        readonly
                                        class="flex-1 min-w-0 rounded-md border border-gray-300 dark:border-cherry-700 bg-gray-50 dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none cursor-not-allowed"
                                    />
                                    <button
                                        type="button"
                                        class="secondary-button shrink-0"
                                        @click="copyShareLink(currentShare.share_url || currentShare.public_url)"
                                    >
                                        @lang('dam::app.admin.dam.share.modal.copy')
                                    </button>
                                </div>

                                <div v-else-if="currentShare && currentShare.status === 'revoked'" class="flex flex-col gap-3">
                                    <div class="flex items-start gap-2 rounded-md border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-2">
                                        <span class="icon-warning text-lg text-amber-500 shrink-0 mt-px"></span>
                                        <p class="text-sm text-amber-700 dark:text-amber-300">
                                            @lang('dam::app.admin.dam.share.modal.revoked-notice')
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            :value="currentShare.public_url"
                                            readonly
                                            class="flex-1 min-w-0 rounded-md border border-gray-200 dark:border-cherry-700 bg-gray-100 dark:bg-cherry-900 px-3 py-2 text-sm text-gray-400 dark:text-slate-500 line-through focus:outline-none cursor-not-allowed"
                                        />
                                        <button
                                            type="button"
                                            class="primary-button shrink-0"
                                            :disabled="isReauthorizing"
                                            @click="reauthorize"
                                        >
                                            <span v-if="!isReauthorizing">@lang('dam::app.admin.dam.share.modal.reauthorize')</span>
                                            <span v-else>@lang('dam::app.admin.dam.share.modal.reauthorizing')</span>
                                        </button>
                                    </div>
                                </div>

                                <div v-else>
                                    <button
                                        type="button"
                                        class="primary-button"
                                        :disabled="isCreating || !targetId"
                                        @click="createShare"
                                    >
                                        <span v-if="!isCreating">@lang('dam::app.admin.dam.share.modal.create')</span>
                                        <span v-else>@lang('dam::app.admin.dam.share.modal.creating')</span>
                                    </button>
                                </div>

                                <label class="flex items-center gap-2 cursor-pointer select-none mt-1">
                                    <input
                                        type="checkbox"
                                        class="peer sr-only"
                                        v-model="showAdvanced"
                                    />
                                    <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl"></span>
                                    <span class="text-sm text-gray-600 dark:text-slate-300">
                                        @lang('dam::app.admin.dam.share.modal.advanced')
                                    </span>
                                </label>

                                <div
                                    v-if="showAdvanced"
                                    class="border border-gray-200 dark:border-cherry-700 rounded-md p-4 flex flex-col gap-4"
                                >
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">
                                            @lang('dam::app.admin.dam.share.modal.name-label')
                                        </label>
                                        <input
                                            type="text"
                                            v-model="advancedName"
                                            :placeholder="@js(trans('dam::app.admin.dam.share.modal.name-hint'))"
                                            class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">
                                            @lang('dam::app.admin.dam.share.modal.expiry')
                                        </label>
                                        <v-multiselect
                                            v-model="expiryOption"
                                            @update:model-value="expiryTouched = true"
                                            :options="expiryOptions"
                                            track-by="value"
                                            label="label"
                                            :allow-empty="false"
                                            :close-on-select="true"
                                            :clear-on-select="false"
                                            :searchable="false"
                                            :show-labels="false"
                                            :placeholder="@js(trans('dam::app.admin.dam.share.modal.expiry'))"
                                        ></v-multiselect>
                                    </div>

                                    <div class="flex items-center gap-2 flex-wrap">
                                        <button
                                            v-if="currentShare && currentShare.status === 'active'"
                                            type="button"
                                            class="secondary-button"
                                            :disabled="isSaving"
                                            @click="saveAdvanced"
                                        >
                                            @lang('dam::app.admin.dam.share.modal.save')
                                        </button>

                                        <button
                                            v-if="!currentShare"
                                            type="button"
                                            class="primary-button"
                                            :disabled="isCreating || !targetId"
                                            @click="createShare"
                                        >
                                            <span v-if="!isCreating">@lang('dam::app.admin.dam.share.modal.create')</span>
                                            <span v-else>@lang('dam::app.admin.dam.share.modal.creating')</span>
                                        </button>

                                        <button
                                            v-if="currentShare && currentShare.status === 'revoked'"
                                            type="button"
                                            class="primary-button"
                                            :disabled="isReauthorizing"
                                            @click="reauthorize"
                                        >
                                            <span v-if="!isReauthorizing">@lang('dam::app.admin.dam.share.modal.reauthorize')</span>
                                            <span v-else>@lang('dam::app.admin.dam.share.modal.reauthorizing')</span>
                                        </button>

                                        <button
                                            v-if="currentShare && currentShare.status === 'active'"
                                            type="button"
                                            class="secondary-button !text-red-600 dark:!text-red-500"
                                            :disabled="isRevoking"
                                            @click="revokeFromModal(currentShare)"
                                        >
                                            @lang('dam::app.admin.dam.share.modal.revoke')
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-shares-page', {
                template: '#v-dam-shares-page-template',

                data() {
                    return {
                        targetType: '',
                        targetId: null,
                        isLoading: false,
                        isCreating: false,
                        isSaving: false,
                        isRevoking: false,
                        isReauthorizing: false,
                        isReissuing: false,
                        currentShare: null,
                        showAdvanced: false,
                        advancedName: '',
                        expiryOptions: [
                            { value: '',  label: @js(trans('dam::app.admin.dam.share.modal.no-expiry')) },
                            { value: 1,   label: @js(trans('dam::app.admin.dam.share.modal.expiry-1d')) },
                            { value: 7,   label: @js(trans('dam::app.admin.dam.share.modal.expiry-7d')) },
                            { value: 30,  label: @js(trans('dam::app.admin.dam.share.modal.expiry-30d')) },
                            { value: 365, label: @js(trans('dam::app.admin.dam.share.modal.expiry-365d')) },
                        ],
                        expiryOption: null,
                        expiryTouched: false,
                        pendingPublic: null,
                    };
                },

                computed: {
                    {{-- Type, target, name, created by, status, expires, views, downloads, created, actions. The two counters take a fixed narrow track and the dates may wrap, so the text columns keep the room. --}}
                    gridTemplate() {
                        return 'minmax(60px, .8fr) minmax(120px, 2fr) minmax(90px, 1.4fr) minmax(90px, 1.2fr) minmax(80px, 1fr) minmax(96px, 1.2fr) 56px 92px minmax(96px, 1.2fr) 190px';
                    },

                    gridsCount() {
                        const cols = this.$refs.datagrid?.available?.columns?.length ?? 0;
                        const hasActions = (this.$refs.datagrid?.available?.actions?.length ?? 0) > 0;

                        return cols + (hasActions ? 1 : 0);
                    },

                    headerLabel() {
                        return this.targetType === 'directory'
                            ? @js(trans('dam::app.admin.dam.share.modal.title-directory'))
                            : @js(trans('dam::app.admin.dam.share.modal.title-asset'));
                    },
                },

                mounted() {
                    this.expiryOption = this.expiryOptions[0];
                },

                methods: {
                    editShare(record) {
                        this.targetType = (record.share_type || '').toLowerCase();
                        this.targetId = Number(record.target_id);
                        this.currentShare = null;
                        this.pendingPublic = null;
                        this.expiryTouched = false;
                        this.showAdvanced = false;
                        this.advancedName = '';
                        this.expiryOption = this.expiryOptions[0];
                        this.$refs.shareModal.toggle();
                        this.loadShares();
                    },

                    copyShareLink(url) {
                        if (!url) return;
                        const success = () => this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: @js(trans('dam::app.admin.dam.share.modal.copied')),
                        });
                        const fallback = () => {
                            const tmp = document.createElement('textarea');
                            tmp.value = url;
                            tmp.style.position = 'fixed';
                            tmp.style.opacity = '0';
                            document.body.appendChild(tmp);
                            tmp.focus();
                            tmp.select();
                            let copied = false;
                            try { copied = document.execCommand('copy'); } catch (_) {}
                            document.body.removeChild(tmp);
                            copied ? success() : this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: @js(trans('dam::app.admin.dam.share.modal.copy-failed')),
                            });
                        };
                        if (navigator.clipboard?.writeText) {
                            navigator.clipboard.writeText(url).then(success).catch(fallback);
                        } else {
                            fallback();
                        }
                    },

                    revokeShare(url) {
                        if (!url) return;
                        this.$axios.patch(url)
                            .then(({ data }) => {
                                this.$emitter.emit('add-flash', {
                                    type: data?.success ? 'success' : 'error',
                                    message: data?.message ?? @js(trans('dam::app.admin.dam.share.modal.revoke-failed')),
                                });
                                if (data?.success) {
                                    this.$refs.datagrid.get();
                                }
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.revoke-failed')),
                                });
                            });
                    },

                    /** Reissue (P2 S5a), confirmed first: the row's current URL stops working once it goes through. */
                    reissueShare(record, url) {
                        if (!url || this.isReissuing) return;
                        this.$emitter.emit('open-confirm-modal', {
                            title: @js(trans('dam::app.admin.dam.share.modal.reissue')),
                            message: @js(trans('dam::app.admin.dam.share.modal.reissue-confirm')),
                            options: {
                                btnDisagree: @js(trans('dam::app.admin.dam.share.modal.cancel')),
                                btnAgree: @js(trans('dam::app.admin.dam.share.modal.reissue')),
                                btnAgreeClass: 'primary-button',
                                btnDisagreeClass: 'transparent-button',
                            },
                            agree: () => this.doReissue(record, url),
                            disagree: () => {},
                        });
                    },

                    /** On success the link's dialog opens on the new URL, when this viewer may see it. */
                    doReissue(record, url) {
                        if (this.isReissuing) return;
                        this.isReissuing = true;

                        this.$axios.post(url)
                            .then(({ data }) => {
                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: data?.message ?? @js(trans('dam::app.admin.dam.share.reissued')),
                                });
                                this.$refs.datagrid.get();

                                if (data?.share?.public_url) {
                                    this.targetType = (record.share_type || '').toLowerCase();
                                    this.targetId = Number(record.target_id);
                                    this.currentShare = data.share;
                                    this.pendingPublic = null;
                                    this.expiryTouched = false;
                                    this.showAdvanced = false;
                                    this.advancedName = data.share.name || '';
                                    this.$refs.shareModal.toggle();
                                }
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.reissue-failed')),
                                });
                            })
                            .finally(() => {
                                this.isReissuing = false;
                            });
                    },

                    loadShares() {
                        if (!this.targetType || !this.targetId) return;
                        this.isLoading = true;
                        const url = `{{ route('admin.dam.shared-links.active_for_target', ['type' => '__type', 'targetId' => '__id']) }}`
                            .replace('__type', this.targetType)
                            .replace('__id', this.targetId);

                        this.$axios.get(url)
                            .then(({ data }) => {
                                const shares = data?.shares ?? [];
                                this.currentShare = shares.length ? shares[0] : null;
                                if (this.currentShare) {
                                    this.advancedName = this.currentShare.name || '';
                                    this.expiryOption = this.currentShare.expires_at
                                        ? (this.expiryOptions.find(o => o.value !== '') ?? this.expiryOptions[1])
                                        : this.expiryOptions[0];
                                }
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.load-failed')),
                                });
                            })
                            .finally(() => {
                                this.isLoading = false;
                            });
                    },

                    /** A public link is reachable without signing in, so creating or re-enabling one is confirmed first. */
                    confirmPublic(proceed, expiryLabel) {
                        this.pendingPublic = {
                            message: @js(trans('dam::app.admin.dam.share.public-link-confirm')).replace(':expiry', expiryLabel),
                            proceed,
                        };
                    },

                    runPending() {
                        const pending = this.pendingPublic;
                        this.pendingPublic = null;
                        pending?.proceed();
                    },

                    createShare() {
                        if (this.isCreating || !this.targetId) return;
                        // Without the advanced options the server applies its 30-day default.
                        const chosen = this.showAdvanced ? this.expiryOption : null;
                        this.confirmPublic(() => this.doCreateShare(), chosen ? chosen.label : @js(trans('dam::app.admin.dam.share.modal.expiry-30d')));
                    },

                    doCreateShare() {
                        if (this.isCreating || !this.targetId) return;
                        this.isCreating = true;

                        // Without the advanced options the server applies its 30-day default.
                        const noExpiry = this.showAdvanced && this.expiryOption?.value === '';
                        const payload = {
                            share_type: this.targetType,
                            target_id: this.targetId,
                            no_expiry: noExpiry ? 1 : 0,
                            expiry_days: noExpiry || ! this.showAdvanced ? null : this.expiryOption?.value,
                            name: this.showAdvanced ? (this.advancedName?.trim() || null) : null,
                            public_link_confirmed: true,
                        };

                        this.$axios.post(`{{ route('admin.dam.shared-links.store') }}`, payload)
                            .then(({ data }) => {
                                if (data?.share) {
                                    this.currentShare = data.share;
                                    this.advancedName = data.share.name || '';
                                    this.$emitter.emit('add-flash', {
                                        type: 'success',
                                        message: @js(trans('dam::app.admin.dam.share.modal.created')),
                                    });
                                }
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.create-failed')),
                                });
                            })
                            .finally(() => {
                                this.isCreating = false;
                            });
                    },

                    saveAdvanced() {
                        if (this.isSaving || !this.currentShare?.update_url) return;
                        if (this.expiryTouched && this.expiryOption?.value === '') {
                            this.confirmPublic(() => this.doSaveAdvanced(), this.expiryOption.label);
                            return;
                        }
                        this.doSaveAdvanced();
                    },

                    doSaveAdvanced() {
                        if (this.isSaving || !this.currentShare?.update_url) return;
                        this.isSaving = true;

                        // Expiry travels only when the viewer changed it; no expiry makes the link permanent, so it is confirmed.
                        const payload = { name: this.advancedName?.trim() || null };
                        if (this.expiryTouched) {
                            const days = this.expiryOption?.value;
                            Object.assign(payload, days === '' ? { no_expiry: 1, expiry_days: null, public_link_confirmed: true } : { no_expiry: 0, expiry_days: days });
                        }
                        this.$axios.patch(this.currentShare.update_url, payload)
                            .then(({ data }) => {
                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: data?.message ?? @js(trans('dam::app.admin.dam.share.updated')),
                                });
                                this.$refs.shareModal.toggle();
                                this.$refs.datagrid.get();
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.update-failed')),
                                });
                            })
                            .finally(() => {
                                this.isSaving = false;
                            });
                    },

                    reauthorize() {
                        if (this.isReauthorizing || !this.currentShare?.reauthorize_url) return;
                        this.confirmPublic(() => this.doReauthorize(), @js(trans('dam::app.admin.dam.share.modal.expiry-30d')));
                    },

                    doReauthorize() {
                        if (this.isReauthorizing || !this.currentShare?.reauthorize_url) return;
                        this.isReauthorizing = true;

                        this.$axios.patch(this.currentShare.reauthorize_url, { public_link_confirmed: true })
                            .then(({ data }) => {
                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: data?.message ?? @js(trans('dam::app.admin.dam.share.reauthorized')),
                                });
                                this.$refs.shareModal.toggle();
                                this.$refs.datagrid.get();
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.reauthorize-failed')),
                                });
                            })
                            .finally(() => {
                                this.isReauthorizing = false;
                            });
                    },

                    revokeFromModal(share) {
                        if (this.isRevoking) return;
                        this.isRevoking = true;
                        const url = `{{ route('admin.dam.shared-links.revoke', ':id') }}`.replace(':id', share.id);

                        this.$axios.patch(url)
                            .then(({ data }) => {
                                if (data?.success) {
                                    this.$emitter.emit('add-flash', {
                                        type: 'success',
                                        message: data.message ?? @js(trans('dam::app.admin.dam.share.modal.revoked')),
                                    });
                                    this.$refs.shareModal.toggle();
                                    this.$refs.datagrid.get();
                                }
                            })
                            .catch(error => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.revoke-failed')),
                                });
                            })
                            .finally(() => {
                                this.isRevoking = false;
                            });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
