<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.share.index.title')
    </x-slot:title>

    {!! view_render_event('mothership.dam.shares.list.before') !!}

    <v-dam-shares-page data-fold-list data-fold-split>
        <x-admin::layouts.page-header
            group="away"
            :title="trans('dam::app.admin.dam.share.index.title')"
            :description="trans('dam::app.admin.dam.share.index.description')"
        />

        <x-admin::shimmer.datagrid.toolbar />

        <div class="bridge-list mt-4" aria-busy="true">
            <div class="bridge-list-loading"><span></span><span></span><span></span></div>
        </div>
    </v-dam-shares-page>

    {!! view_render_event('mothership.dam.shares.list.after') !!}

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-shares-page-template">
            <div data-fold-list data-fold-split>
                <x-admin::layouts.page-header
                    group="away"
                    :title="trans('dam::app.admin.dam.share.index.title')"
                    :description="trans('dam::app.admin.dam.share.index.description')"
                />

                {{--
                    One list anatomy with the other Away from ship and Sorting screens (app.css, "Lists"): a row says
                    what is shared, its status, when it ends, how much it was used and who made it. Copy link is the
                    row's one visible action; the rarer ones sit in a menu, as words. The grid keeps its own search,
                    filters and pager, because this list can be long. --list-cols are the columns of the table form:
                    link, status, expires, activity, made by, actions.
                --}}
                <div
                    class="bridge-list-grid"
                    style="--list-cols: minmax(0, 1fr) 6.5rem 8.5rem 8rem 9rem 12.5rem"
                    data-dam-shares
                >
                    <x-admin::datagrid src="{{ route('admin.dam.shared-links.index') }}" ref="datagrid">
                        <template #header="{ columns, meta, applied, sortPage }">
                            {{--
                                Below the table form the column names go, but the sort buttons stay as one strip,
                                so the order can be changed at every width. One link or none has no order to change.
                            --}}
                            <div class="bridge-list-head" :class="{ 'has-sorts': (meta?.total ?? 0) > 1 }">
                                <span class="bridge-list-for-table">@lang('dam::app.admin.dam.share.list.link')</span>
                                <span class="bridge-list-for-table">@lang('dam::app.admin.dam.share.datagrid.status')</span>
                                <span class="bridge-list-for-cards" aria-hidden="true">@lang('dam::app.admin.dam.share.list.sort')</span>

                                <span v-for="sort in sorts" :key="sort.index">
                                    <button
                                        type="button"
                                        class="bridge-list-sort"
                                        :class="{ 'is-sorted': applied.sort.column === sort.index }"
                                        :aria-label="sortLabel(sort, applied)"
                                        :title="sortLabel(sort, applied)"
                                        :data-dam-shares-sort="sort.index"
                                        v-on:click="sortBy(sort.index, columns, sortPage)"
                                    >
                                        <span v-text="sort.label"></span>

                                        <i
                                            v-if="applied.sort.column === sort.index"
                                            class="text-base"
                                            :class="applied.sort.order === 'asc' ? 'icon-down-stat' : 'icon-up-stat'"
                                            aria-hidden="true"
                                        ></i>
                                    </button>
                                </span>

                                <span class="bridge-list-for-table sr-only">@lang('admin::app.components.datagrid.table.actions')</span>
                            </div>
                        </template>

                        <template #body="{ records, meta, applied, isLoading, performAction }">
                            {{-- Three quiet rows until the first answer; a list that has not answered never shows its empty words. --}}
                            <div
                                v-if="isLoading || meta.total === undefined"
                                class="bridge-list-loading"
                                aria-busy="true"
                                data-list-loading
                            >
                                <span v-for="n in 3" :key="n"></span>
                            </div>

                            <template v-else-if="! records.length">
                                <div
                                    v-if="isNarrowed(applied)"
                                    class="bridge-list-empty"
                                    data-dam-shares-no-match
                                >
                                    <p class="bridge-list-empty-title">@lang('dam::app.admin.dam.share.list.no-match-title')</p>
                                    <p class="bridge-list-empty-text">@lang('dam::app.admin.dam.share.list.no-match-text')</p>

                                    <div v-if="hasFilters(applied)" class="bridge-list-empty-action">
                                        <button
                                            type="button"
                                            class="secondary-button"
                                            data-clear-filters-empty-state
                                            v-on:click="$refs.datagrid.clearAllFilters()"
                                        >
                                            @lang('admin::app.components.datagrid.filters.custom-filters.clear-all')
                                        </button>
                                    </div>
                                </div>

                                <template v-else>
                                    <x-admin::list.empty-state
                                        :framed="false"
                                        icon="icon-dam-link"
                                        :title="trans('dam::app.admin.dam.share.list.empty-title')"
                                        :description="trans('dam::app.admin.dam.share.list.empty-text')"
                                        data-dam-shares-empty="1"
                                    >
                                        <a href="{{ route('admin.dam.index') }}" class="primary-button">@lang('dam::app.admin.dam.share.list.empty-action')</a>
                                    </x-admin::list.empty-state>
                                </template>
                            </template>

                            <template v-else>
                                <div
                                    v-for="record in records"
                                    :key="record.id"
                                    class="bridge-list-row"
                                    :data-dam-share-row="record.id"
                                >
                                    <div class="bridge-list-main">
                                        <span
                                            class="bridge-list-tile"
                                            :class="isFolder(record) ? 'icon-folder' : 'icon-file'"
                                            aria-hidden="true"
                                        ></span>

                                        <div class="bridge-list-text">
                                            <p class="bridge-list-name" :title="record.target_name" v-text="record.target_name || record.share_name || '—'"></p>

                                            <p class="bridge-list-sub" :title="record.share_name">
                                                <template v-if="record.share_name && record.target_name"><span v-text="record.share_name"></span> · </template><span v-text="typeLabel(record)"></span>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="bridge-list-status">
                                        <span class="bridge-status" :class="statusClass(record.status)" :data-dam-share-status="record.status">
                                            <span class="bridge-status-mark" aria-hidden="true"></span>
                                            <span v-text="statusLabel(record.status)"></span>
                                        </span>
                                    </div>

                                    <div class="bridge-list-meta">
                                        <div class="bridge-list-cell">
                                            <span class="bridge-list-label">@lang('dam::app.admin.dam.share.datagrid.expires-at')</span>
                                            <span class="bridge-list-value" v-text="expiryDate(record)"></span>
                                            <span v-if="expiryNote(record)" class="bridge-list-note" v-text="expiryNote(record)"></span>
                                        </div>

                                        <div class="bridge-list-cell">
                                            <span class="bridge-list-label">@lang('dam::app.admin.dam.share.list.activity')</span>
                                            <span class="bridge-list-value" v-text="viewsLabel(record)"></span>
                                            <span v-if="downloadsLabel(record)" class="bridge-list-note" v-text="downloadsLabel(record)"></span>
                                        </div>

                                        <div class="bridge-list-cell">
                                            <span class="bridge-list-label">@lang('dam::app.admin.dam.share.datagrid.created-by')</span>
                                            <span class="bridge-list-value truncate" :title="record.created_by_name" v-text="record.created_by_name || '—'"></span>
                                            <span class="bridge-list-note" v-text="dateLabel(record.created_at)"></span>
                                        </div>
                                    </div>

                                    {{-- Only the link's creator, its target's owner or a full admin gets the URL or any action (#126, R14): the server sends no action otherwise. --}}
                                    <div class="bridge-list-actions">
                                        <button
                                            type="button"
                                            v-if="action(record, 'copy_link')"
                                            class="secondary-button"
                                            data-row-action="copy_link"
                                            v-on:click="copyShareLink(action(record, 'copy_link').url)"
                                        >
                                            @lang('dam::app.admin.dam.share.datagrid.copy-link')
                                        </button>

                                        <x-admin::dropdown v-if="menuActions(record).length" position="bottom-right" :teleport="true">
                                            <x-slot:toggle>
                                                <button
                                                    type="button"
                                                    class="transparent-button"
                                                    :aria-label="moreLabel(record)"
                                                    data-dam-share-more
                                                >
                                                    @lang('dam::app.admin.dam.share.list.more')
                                                    <span class="icon-chevron-down text-xl" aria-hidden="true"></span>
                                                </button>
                                            </x-slot:toggle>

                                            <x-slot:menu class="!py-2 min-w-[190px]">
                                                <x-admin::dropdown.menu.item
                                                    v-if="action(record, 'edit')"
                                                    data-row-action="edit"
                                                    v-on:click="editShare(record)"
                                                >
                                                    @lang('dam::app.admin.dam.share.datagrid.edit')
                                                </x-admin::dropdown.menu.item>

                                                {{-- Reissue (P2 S5a): a new URL for the same link. --}}
                                                <x-admin::dropdown.menu.item
                                                    v-if="action(record, 'reissue')"
                                                    data-dam-share-reissue="1"
                                                    data-row-action="reissue"
                                                    v-on:click="reissueShare(record, action(record, 'reissue').url)"
                                                >
                                                    @lang('dam::app.admin.dam.share.datagrid.reissue')
                                                </x-admin::dropdown.menu.item>

                                                <x-admin::dropdown.menu.item
                                                    v-if="action(record, 'revoke')"
                                                    data-row-action="revoke"
                                                    v-on:click="revokeShare(action(record, 'revoke').url)"
                                                >
                                                    @lang('dam::app.admin.dam.share.datagrid.revoke')
                                                </x-admin::dropdown.menu.item>

                                                <x-admin::dropdown.menu.item
                                                    v-if="action(record, 'delete')"
                                                    data-row-action="delete"
                                                    v-on:click="performAction(action(record, 'delete'))"
                                                >
                                                    @lang('dam::app.admin.dam.share.datagrid.delete')
                                                </x-admin::dropdown.menu.item>
                                            </x-slot:menu>
                                        </x-admin::dropdown>
                                    </div>
                                </div>
                            </template>
                        </template>
                    </x-admin::datagrid>
                </div>

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

                        // The column names that sort the list, in the order the table form shows them. The last
                        // column shows who made a link and when; it sorts by the day, so that is what it is called.
                        sorts: [
                            { index: 'expires_at', label: @js(trans('dam::app.admin.dam.share.datagrid.expires-at')) },
                            { index: 'view_count', label: @js(trans('dam::app.admin.dam.share.list.activity')) },
                            { index: 'created_at', label: @js(trans('dam::app.admin.dam.share.list.made-on')) },
                        ],

                        // The server's clock when the page was built, in the zone the rows' dates are stored in:
                        // "in 6 days" is counted against it, so it cannot disagree with the row's status.
                        serverNow: @js(now()->toDateTimeString()),
                    };
                },

                computed: {
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
                    /** One of the row's server-sent actions, or undefined when this viewer may not take it. */
                    action(record, index) {
                        return (record.actions ?? []).find(action => action.index === index);
                    },

                    /** The actions behind the row's More menu: everything but Copy link. */
                    menuActions(record) {
                        return (record.actions ?? []).filter(action => action.index !== 'copy_link');
                    },

                    moreLabel(record) {
                        return @js(trans('dam::app.admin.dam.share.list.more-for')).replace(':name', record.target_name || record.share_name || '');
                    },

                    isFolder(record) {
                        return (record.share_type || '').toLowerCase() === 'directory';
                    },

                    typeLabel(record) {
                        return this.isFolder(record)
                            ? @js(trans('dam::app.admin.dam.share.datagrid.directory'))
                            : @js(trans('dam::app.admin.dam.share.datagrid.asset'));
                    },

                    statusLabel(status) {
                        return @js([
                            'active'  => trans('dam::app.admin.dam.share.datagrid.status-active'),
                            'expired' => trans('dam::app.admin.dam.share.datagrid.status-expired'),
                            'revoked' => trans('dam::app.admin.dam.share.datagrid.status-revoked'),
                        ])[status] ?? status;
                    },

                    /** The shape that goes with the word: a filled dot while it opens, a ring once time ended it, a bar once a person stopped it. */
                    statusClass(status) {
                        return { active: 'is-live', expired: 'is-ended', revoked: 'is-stopped' }[status] ?? 'is-live';
                    },

                    /** "2026-11-24 09:30:00" as its calendar day, or null for anything else (the grid sends "Never" for no expiry). */
                    dayOf(value) {
                        const parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '');

                        return parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])) : null;
                    },

                    dateLabel(value) {
                        const day = this.dayOf(value);

                        return day ? day.toLocaleDateString([], { dateStyle: 'medium' }) : (value ?? '');
                    },

                    expiryDate(record) {
                        return this.dateLabel(record.expires_at);
                    },

                    /** "in 6 days", "tomorrow", "3 days ago". A revoked link has no countdown: its date no longer decides anything. */
                    expiryNote(record) {
                        const day = this.dayOf(record.expires_at);
                        const today = this.dayOf(this.serverNow);

                        if (! day || ! today || record.status === 'revoked') return '';

                        const days = Math.round((day - today) / 86400000);

                        // Counted in whole calendar days, so a link that ends or ended today reads "today" either way;
                        // the status beside it says which.
                        return new Intl.RelativeTimeFormat([], { numeric: 'auto' }).format(days, 'day');
                    },

                    countLabel(count, one, many) {
                        const number = Number(count) || 0;

                        return (number === 1 ? one : many).replace(':count', number.toLocaleString());
                    },

                    viewsLabel(record) {
                        if (! Number(record.view_count) && ! Number(record.download_count)) {
                            return @js(trans('dam::app.admin.dam.share.list.not-opened'));
                        }

                        return this.countLabel(record.view_count, @js(trans('dam::app.admin.dam.share.list.view-one')), @js(trans('dam::app.admin.dam.share.list.view-many')));
                    },

                    downloadsLabel(record) {
                        if (! Number(record.view_count) && ! Number(record.download_count)) return '';

                        return this.countLabel(record.download_count, @js(trans('dam::app.admin.dam.share.list.download-one')), @js(trans('dam::app.admin.dam.share.list.download-many')));
                    },

                    /** Filters narrow the list (the search box is the 'all' column and has its own field above the list). */
                    hasFilters(applied) {
                        return (applied?.filters?.columns ?? []).some(column => column.index !== 'all' && (column.value?.length ?? 0) > 0);
                    },

                    isNarrowed(applied) {
                        return (applied?.filters?.columns ?? []).some(column => (column.value?.length ?? 0) > 0);
                    },

                    sortBy(index, columns, sortPage) {
                        const column = (columns ?? []).find(column => column.index === index);

                        if (column) sortPage(column);
                    },

                    sortLabel(sort, applied) {
                        if (applied?.sort?.column !== sort.index) {
                            return @js(trans('dam::app.admin.dam.share.list.sort-by')).replace(':column', sort.label);
                        }

                        return (applied.sort.order === 'asc'
                            ? @js(trans('dam::app.admin.dam.share.list.sorted-asc'))
                            : @js(trans('dam::app.admin.dam.share.list.sorted-desc'))).replace(':column', sort.label);
                    },

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
