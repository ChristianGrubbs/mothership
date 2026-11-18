@include('dam::components.explorer.stack-line')
@include('dam::components.shared.adobe-identity')
@include('dam::components.explorer.marquee')
@once('v-dam-explorer-list')
@include('dam::components.shared.lazy-thumbnail')
@include('dam::components.shared.custom-thumbnail-drop')
@push('scripts')

<script type="text/x-template" id="v-dam-explorer-list-template">
    <div
        ref="space"
        class="dam-list relative border border-gray-200 dark:border-cherry-700 rounded-lg overflow-hidden bg-white dark:bg-cherry-900 min-h-72"
        @pointerdown="onSpacePointerDown"
        @dragstart.capture="onMarqueeDragStart"
        @dragover.prevent
        @drop.prevent="onSpaceDrop($event)"
    >

        <div v-if="isLoading" class="divide-y divide-gray-100 dark:divide-cherry-800 p-2">
            <div v-for="n in 8" :key="n" class="h-10 my-1 rounded bg-gray-100 dark:bg-cherry-800 animate-pulse"></div>
        </div>

        <template v-else>
            <div class="dam-list-row px-4 py-2 bg-gray-50 dark:bg-cherry-800 border-b border-gray-200 dark:border-cherry-700 text-xs font-bold uppercase tracking-widest text-gray-400" data-marquee-no-start>
                <span></span>
                <span></span>
                <span class="cursor-pointer hover:text-gray-600 dark:hover:text-white" @click="sort('name')">
                    @lang('dam::app.admin.explorer.list.header.name') <span v-if="sortBy==='name'">@{{ sortOrder==='asc'?'↑':'↓' }}</span>
                </span>
                <span class="dam-list-col-type">@lang('dam::app.admin.explorer.list.header.type')</span>
                <span class="dam-list-col-size cursor-pointer hover:text-gray-600 dark:hover:text-white" @click="sort('size')">
                    @lang('dam::app.admin.explorer.list.header.size') <span v-if="sortBy==='size'">@{{ sortOrder==='asc'?'↑':'↓' }}</span>
                </span>
                <span class="dam-list-col-date cursor-pointer hover:text-gray-600 dark:hover:text-white" @click="sort('updated_at')">
                    @lang('dam::app.admin.explorer.list.header.modified') <span v-if="sortBy==='updated_at'">@{{ sortOrder==='asc'?'↑':'↓' }}</span>
                </span>
                <span class="[grid-column-end:-1] text-right ltr:pr-1 rtl:pl-1 dam-list-actions-label">@lang('dam::app.admin.explorer.list.header.actions')</span>
            </div>

            <div
                v-for="dir in directories" :key="`d-${dir.id}`"
                class="dam-list-row min-h-[52px] px-4 py-3 text-sm border-b border-gray-100 dark:border-cherry-800 items-center cursor-pointer hover:bg-primary-100 dark:hover:bg-primary-800/50 transition-colors"
                :class="{ 'ring-2 ring-inset ring-primary-400': dropTargetId === dir.id, 'bg-primary-50 dark:bg-primary-900/40': isSelectedById(dir.id, 'directory') }"
                :data-dir-id="dir.id"
                :data-marquee-key="'directory:' + dir.id"
                :data-selected="isSelectedById(dir.id, 'directory')"
                draggable="true"
                @click="onRowClick($event, dir, 'directory', () => $emit('navigate', dir))"
                @contextmenu.prevent.stop="showCtx($event, dir, 'directory')"
                @dragstart="onDirDragStart($event, dir)"
                @dragend="onDragEnd"
                @dragover.prevent="onDirDragOver($event, dir)"
                @dragleave="onDirDragLeave($event, dir)"
                @drop.prevent.stop="onDirDrop($event, dir)"
            >
                <div
                    class="dam-list-select-cell flex items-center justify-center self-stretch -my-2.5 -ml-2 cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600"
                    role="checkbox"
                    tabindex="0"
                    :aria-checked="isSelectedById(dir.id, 'directory')"
                    :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => dir.name)"
                    :id="`sel-dir-${dir.id}`"
                    @click.stop="onCheckboxClick($event, dir, 'directory')"
                    @keydown.space.prevent.stop="onCheckboxClick($event, dir, 'directory')"
                    @keydown.enter.prevent.stop="onCheckboxClick($event, dir, 'directory')"
                >
                    <span class="rounded-md text-xl" :class="isSelectedById(dir.id, 'directory') ? 'icon-checkbox-check text-primary-700' : 'icon-checkbox-normal'"></span>
                </div>
                <i class="icon-dam-folder text-lg text-primary-400"></i>
                <div class="min-w-0">
                    <span class="flex items-center gap-1 min-w-0">
                        <span class="font-medium text-primary-700 dark:text-primary-300 truncate">@{{ dir.name }}</span>
                        @include('dam::components.explorer.access-indicator', ['expression' => 'dir.access_mode'])
                    </span>
                    <span class="dam-list-compact-meta text-xs text-gray-400 truncate block">
                        @lang('dam::app.admin.explorer.sections.folder') · @{{ "@lang('dam::app.admin.explorer.list.items-count')".replace(':count', dir.assets_count + dir.children_count) }}
                    </span>
                </div>
                <span class="dam-list-col-type text-gray-400 text-xs">@lang('dam::app.admin.explorer.sections.folder')</span>
                <span class="dam-list-col-size text-gray-400 text-xs">@{{ "@lang('dam::app.admin.explorer.list.items-count')".replace(':count', dir.assets_count + dir.children_count) }}</span>
                <span class="dam-list-col-date text-gray-400 text-xs">@{{ fmtDate(dir.updated_at) }}</span>
                <div class="flex gap-2 items-center justify-end [grid-column-end:-1]">
                    <div class="dam-list-inline-actions gap-2 items-center">
                    @if (bouncer()->hasPermission('dam.directory.rename'))
                    <button v-if="dir.can_access !== false" type="button" class="icon-dam-rename text-gray-400 hover:text-primary-600 text-base" @click.stop="renameDir(dir)" title="@lang('dam::app.admin.dam.index.directory.actions.rename')" :aria-label="'@lang('dam::app.admin.dam.index.directory.actions.rename')' + ' ' + dir.name"></button>
                    @endif
                    @if (bouncer()->hasPermission('dam.directory.destroy'))
                    <button v-if="dir.can_access !== false" type="button" class="icon-dam-delete text-gray-400 hover:text-red-500 text-base" @click.stop="delDir(dir)" title="@lang('dam::app.admin.dam.index.directory.actions.delete')" :aria-label="'@lang('dam::app.admin.dam.index.directory.actions.delete')' + ' ' + dir.name"></button>
                    @endif
                    </div>
                    <button
                        type="button"
                        class="dam-ctx-trigger flex items-center justify-center text-gray-400 hover:text-primary-600"
                        @click.stop="showCtx($event, dir, 'directory')"
                        title="@lang('dam::app.admin.explorer.list.header.actions')"
                        :aria-label="@js(trans('dam::app.admin.explorer.a11y.actions-for')).replace(':name', () => dir.name)"
                        aria-haspopup="menu"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>
                    </button>
                </div>
            </div>

            <div v-if="directories.length && assets.length" class="px-4 py-2 bg-gray-50 dark:bg-cherry-800 border-b border-gray-200 dark:border-cherry-700 text-xs font-bold uppercase tracking-widest text-gray-400">
                @lang('dam::app.admin.explorer.sections.files')
            </div>

            <div
                v-for="asset in assets" :key="`a-${asset.id}`"
                class="dam-list-row min-h-[52px] px-4 py-3 text-sm border-b border-gray-100 dark:border-cherry-800 items-center cursor-pointer hover:bg-primary-100 dark:hover:bg-primary-800/50 transition-colors"
                data-dam-asset-open
                :data-dam-asset-id="asset.id"
                :data-marquee-key="'asset:' + asset.id"
                style="position:relative"
                v-dam-custom-thumbnail-drop="asset"
                :class="{ 'bg-primary-50 dark:bg-primary-900/40': isSelectedById(asset.id, 'asset'), 'ring-2 ring-inset ring-primary-400 !bg-primary-100 dark:!bg-primary-900/60': stackDropId === asset.id }"
                :data-selected="isSelectedById(asset.id, 'asset')"
                draggable="true"
                @if (bouncer()->hasPermission('dam.asset.view'))
                    @click="onRowClick($event, asset, 'asset', () => openAsset(asset, 'preview'))"
                @elseif (bouncer()->hasPermission('dam.asset.edit'))
                    @click="onRowClick($event, asset, 'asset', () => openAsset(asset, 'edit'))"
                @else
                    @click="onRowClick($event, asset, 'asset', () => {})"
                @endif
                @contextmenu.prevent.stop="showCtx($event, asset, 'asset')"
                @dragstart="onAssetDragStart($event, asset)"
                @dragend="onDragEnd"
                @if (bouncer()->hasPermission('dam.asset.edit'))
                    @dragover="onStackDragOver($event, asset)"
                    @dragleave="onStackDragLeave($event, asset)"
                    @drop="onStackDrop($event, asset)"
                @endif
            >
                <div
                    class="dam-list-select-cell flex items-center justify-center self-stretch -my-2.5 -ml-2 cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600"
                    role="checkbox"
                    tabindex="0"
                    :aria-checked="isSelectedById(asset.id, 'asset')"
                    :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => (asset.display_name || asset.file_name))"
                    :id="`sel-asset-${asset.id}`"
                    @click.stop="onCheckboxClick($event, asset, 'asset')"
                    @keydown.space.prevent.stop="onCheckboxClick($event, asset, 'asset')"
                    @keydown.enter.prevent.stop="onCheckboxClick($event, asset, 'asset')"
                >
                    <span class="rounded-md text-xl" :class="isSelectedById(asset.id, 'asset') ? 'icon-checkbox-check text-primary-700' : 'icon-checkbox-normal'"></span>
                </div>
                <img v-if="assetThumbnail(asset)" :key="assetThumbnail(asset)" v-dam-thumbnail="assetThumbnail(asset)" :alt="asset.file_name || asset.display_name" style="width:28px;height:28px;object-fit:contain" draggable="false" data-dam-stack-anchor />
                <i v-else class="text-lg" :class="icon(asset.file_type)"></i>
                <div class="min-w-0">
                    <span class="flex items-center gap-1 min-w-0">
                        <span class="text-gray-700 dark:text-gray-200 truncate"><bdi>@{{ (asset.display_name || asset.file_name) }}</bdi></span>
                        <!-- A stack opens into its files (D5) from its count, as on the grid card. The row itself opens the main file. -->
                        @if ((bouncer()->hasPermission('dam.asset.view') || bouncer()->hasPermission('dam.asset.edit')) && bouncer()->hasPermission('dam.asset.files.index'))
                        <button
                            v-if="Number(asset.attachment_count || 0) > 1"
                            type="button"
                            class="relative shrink-0 inline-flex items-center gap-1 rounded-full bg-gray-700 px-1.5 py-px text-[11px] font-semibold leading-4 text-white hover:bg-primary-600 max-[639px]:after:absolute max-[639px]:after:-inset-x-1 max-[639px]:after:-inset-y-3 max-[639px]:after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                            :aria-label="stackOpenLabel(asset)"
                            :title="stackOpenLabel(asset)"
                            data-dam-stack-open
                            @click.stop="openStack({ asset, el: $event.currentTarget.closest('[data-dam-asset-open]') })"
                        >
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                            @{{ asset.attachment_count }}
                        </button>
                        @endif
                        <span v-if="asset.match?.page" class="shrink-0 rounded bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300" :title="asset.match.snippet || ''" data-dam-match-page>@{{ matchPageLabel(asset) }}</span>
                        <span v-else-if="asset.match?.ai" class="shrink-0 rounded bg-violet-100 dark:bg-violet-900/40 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-200" title="{{ trans('dam::app.admin.dam.asset.edit.preview-modal.ai-badge-hint') }}" data-dam-match-ai>@lang('dam::app.admin.dam.asset.edit.preview-modal.ai-badge')</span>
                        @include('dam::components.explorer.access-indicator', ['expression' => 'asset.access_mode'])
                        <!-- Other versions attached under Related files (AvailableVersions), as on the grid card. -->
                        <span
                            v-for="chip in versionChips(asset)"
                            :key="chip.kind + chip.label"
                            class="shrink-0 px-1 rounded font-bold uppercase text-white"
                            style="font-size:9px;line-height:14px;"
                            :style="{ background: chipColor(chip) }"
                            :title="chipTitle(chip)"
                            data-dam-version-chip
                        >@{{ chip.label }}</span>
                    </span>
                    <span class="dam-list-compact-meta text-xs text-gray-400 truncate block">@{{ rowType(asset) }} · @{{ fmtSize(rowSize(asset)) }}</span>
                    <span v-if="stackLine(asset)" class="text-xs font-medium text-primary-700 dark:text-primary-300 truncate block" :title="stackLine(asset)" data-dam-stack-line>@{{ stackLine(asset) }}</span>
                    <span v-if="Number(asset.attachment_count || 0) > 1 && (stackDropId === asset.id || (dragHint && draggingId !== asset.id))" class="text-xs font-semibold block" :class="stackDropId === asset.id ? 'text-primary-700 dark:text-primary-200' : 'text-primary-600 dark:text-primary-300'" data-dam-stack-drop-hint>@lang('dam::app.admin.dam.asset.merge.drop-hint')</span>
                    <!-- P4a (D9): the card fields, in priority order, each label then value. -->
                    <span v-if="asset.card_fields?.length" class="flex min-w-0 flex-wrap gap-x-3 gap-y-0.5 text-xs" data-dam-card-fields>
                        <span
                            v-for="field in asset.card_fields"
                            :key="field.code"
                            class="min-w-0 max-w-full truncate sm:max-w-[16rem]"
                            :title="`${field.label}: ${field.value}`"
                            :data-dam-card-field="field.code"
                        ><span class="text-gray-400">@{{ field.label }}</span> <span class="font-medium text-gray-700 dark:text-gray-200">@{{ field.value }}</span></span>
                    </span>
                    <span v-if="asset.unavailable_label" class="block"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200" :data-dam-availability-badge="asset.unavailable">@{{ asset.unavailable_label }}</span></span>
                    <span v-if="asset.proxy_status_label" class="block"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4" :class="asset.proxy_status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200'" :data-dam-proxy-badge="asset.proxy_status">@{{ asset.proxy_status_label }}</span></span>
                    <!-- The uploader, from created_by; a file with no owner shows nothing. -->
                    <span v-if="asset.owner_name" class="text-xs text-gray-400 truncate block" :title="`@lang('dam::app.admin.explorer.uploaded-by') ${asset.owner_name}`" data-dam-asset-owner>@{{ asset.owner_name }}</span>
                </div>
                <div class="dam-list-col-type items-center overflow-hidden"><span class="text-xs font-bold rounded px-1 py-px uppercase whitespace-nowrap overflow-hidden" style="max-width:100%;text-overflow:ellipsis;" :class="[badge(asset.file_type, asset.extension), asset.adobe && Number(asset.attachment_count || 0) <= 1 ? 'inline-flex items-center gap-1' : '']" :title="rowTypeTitle(asset) || (asset.adobe && Number(asset.attachment_count || 0) <= 1 ? asset.adobe.title : null)"><img v-if="asset.adobe && Number(asset.attachment_count || 0) <= 1" :src="adobeIcon(asset.adobe)" alt="" class="h-3.5 w-3.5 shrink-0 rounded-[3px]" draggable="false">@{{ rowType(asset) }}</span></div>
                <span class="dam-list-col-size text-gray-400 text-xs" :title="rowSizeTitle(asset)">@{{ fmtSize(rowSize(asset)) }}</span>
                <span class="dam-list-col-date text-gray-400 text-xs">@{{ fmtDate(asset.updated_at) }}</span>
                <div class="flex gap-2 items-center justify-end [grid-column-end:-1]">
                    <div class="dam-list-inline-actions gap-2 items-center">
                    @if (bouncer()->hasPermission('dam.asset.view'))
                    <button type="button" class="icon-dam-preview text-gray-400 hover:text-primary-600 text-base" @click.stop="preview(asset.id)" :title="Number(asset.attachment_count || 0) > 1 ? @js(trans('dam::app.admin.dam.asset.stack-fan.open-main')) : @js(trans('dam::app.admin.dam.asset.edit.preview-modal.card.preview'))" :aria-label="@js(trans('dam::app.admin.explorer.a11y.preview-file')).replace(':name', () => asset.display_name || asset.file_name)"></button>
                    @endif
                    @if (bouncer()->hasPermission('dam.asset.edit'))
                    <button type="button" class="icon-edit text-gray-400 hover:text-primary-600 text-base" @click.stop="edit(asset.id)" title="@lang('dam::app.admin.dam.index.directory.actions.edit')" :aria-label="@js(trans('dam::app.admin.explorer.a11y.edit-file')).replace(':name', () => asset.display_name || asset.file_name)"></button>
                    @endif
                    @if (bouncer()->hasPermission('dam.asset.destroy'))
                    <button type="button" class="icon-delete text-gray-400 hover:text-red-500 text-base" @click.stop="del(asset)" title="@lang('dam::app.admin.dam.index.directory.actions.delete')" :aria-label="@js(trans('dam::app.admin.explorer.a11y.delete-file')).replace(':name', () => asset.display_name || asset.file_name)"></button>
                    @endif
                    </div>
                    <button
                        type="button"
                        class="dam-ctx-trigger flex items-center justify-center text-gray-400 hover:text-primary-600"
                        @click.stop="showCtx($event, asset, 'asset')"
                        title="@lang('dam::app.admin.explorer.list.header.actions')"
                        :aria-label="@js(trans('dam::app.admin.explorer.a11y.actions-for')).replace(':name', () => asset.display_name || asset.file_name)"
                        aria-haspopup="menu"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>
                    </button>
                </div>
            </div>

            <div v-if="!directories.length && !assets.length" class="flex flex-col items-center justify-center py-16 gap-3" data-explorer-empty>
                <img src="{{ mothership_asset('images/no-records-found.svg', 'dam') }}" class="w-24 h-24 opacity-60" alt="" />
                <p class="font-bold text-gray-700 dark:text-slate-50 text-center">@{{ emptyState?.message ?? @js(trans('admin::app.components.datagrid.table.no-records-available')) }}</p>
                <button v-if="emptyState?.action" type="button" class="secondary-button" @click="$emit('empty-action')">@{{ emptyState.action }}</button>
            </div>
        </template>

        {{-- Drag-select box, in the list's own coordinates so it stays put while the page scrolls. --}}
        <div
            v-if="marquee"
            class="absolute pointer-events-none z-20 rounded-sm"
            :style="{ left: marquee.left + 'px', top: marquee.top + 'px', width: marquee.width + 'px', height: marquee.height + 'px', border: '1px solid #2d5be3', background: 'rgba(45, 91, 227, 0.12)' }"
            aria-hidden="true"
            data-explorer-marquee
        ></div>

        <v-dam-explorer-ctx
            v-if="ctx.on"
            :key="ctxKey"
            :x="ctx.x"
            :y="ctx.y"
            :item="ctx.item"
            :item-type="ctx.type"
            :tab-id="tabId"
            :clipboard="clipboard"
            :selection="selection"
            @close="closeCtx"
            @navigate="$emit('navigate', $event)"
            @bookmark="$emit('bookmark', $event)"
            @refresh="$emit('refresh')"
        ></v-dam-explorer-ctx>
    </div>
</script>

<script type="module">
app.component('v-dam-explorer-list', {
    template: '#v-dam-explorer-list-template',
    mixins: [window.MothershipExplorerMarquee],
    emits: ['navigate','bookmark','sort-change','refresh','internal-drop','toggle-select','select-range','set-selection','empty-action','stack-drop'],

    props: {
        directories:          { type: Array, default: () => [] },
        assets:               { type: Array, default: () => [] },
        isLoading:            { type: Boolean, default: false },
        emptyState:           { type: Object, default: null },
        sortBy:               { type: String, default: 'name' },
        sortOrder:            { type: String, default: 'asc' },
        tabId:                { type: String, required: true },
        currentDirId:         { type: Number, default: null },
        clipboard:            { type: Object, default: null },
        canAccessCurrentDir:  { type: Boolean, default: true },
        selection:            { type: Object, default: () => ({ ids: [], mode: 'none' }) },
        // The whole result count and this page's offset, handed to the details page's counter.
        resultTotal:          { type: Number, default: null },
        resultOffset:         { type: Number, default: 0 },
        // P1b: whether this page is a search's results (smart or plain), so opening one is search.result.opened.
        searchSmart:          { type: Boolean, default: null },
        bookmarkedDirIds:     { type: Array, default: () => [] },
    },

    data() { return { ctx: { on: false, x: 0, y: 0, item: null, type: null }, ctxKey: 0, _ctxClose: null, _ctxScroll: null, dropTargetId: null, stackDropId: null, dragHint: false, draggingId: null, rangeAnchor: null, thumbnailOverrides: {} }; },

    mounted() {
        this.$emitter.on('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.on('dam:asset-drag-state', this.onAssetDragState);
    },

    beforeUnmount() {
        this.$emitter.off('dam:thumbnail-updated', this.onThumbnailUpdated);
        this.$emitter.off('dam:asset-drag-state', this.onAssetDragState);
    },

    methods: {
        // Rows fill the list, so a row's type, size and date cells begin a box too; its name still moves the row.
        marqueeStartsAt(target) {
            if (target.closest('button, a, input, select, textarea, label, [role="checkbox"], [role="menu"], [contenteditable="true"], [data-marquee-no-start]')) return false;
            return ! target.closest('[data-marquee-key]') || !! target.closest('.dam-list-col-type, .dam-list-col-size, .dam-list-col-date');
        },
        // Server-rendered label with its :page placeholder filled in; a function replacement keeps `$&` in a value literal.
        matchPageLabel(asset) {
            return "@lang('dam::app.admin.explorer.match-page')".replace(':page', () => asset?.match?.page);
        },
        /**
         * A thumbnail stored while this page is open (a video frame, or a 3D
         * model captured client-side per ADR 0006) replaces the row icon
         * without a refetch; the override is keyed by asset id so a later
         * fetch() that re-seeds `assets` keeps showing it.
         */
        onThumbnailUpdated({ id, url } = {}) {
            if (id != null && url) this.thumbnailOverrides[id] = url;
        },
        assetThumbnail(asset) {
            return this.thumbnailOverrides[asset.id]
                || ((asset.has_custom_thumbnail || asset.has_thumbnail) ? asset.path : null);
        },
        isSelectedById(id, type) {
            return this.selection.ids.some(i => i.id === id && i.type === type);
        },
        /**
         * Visible row order: folders first, then files — the order shift-click ranges follow.
         */
        visibleItems() {
            return [
                ...this.directories.map(d => ({ id: d.id, type: 'directory' })),
                ...this.assets.map(a => ({ id: a.id, type: 'asset' })),
            ];
        },
        markAnchor(id, type) {
            this.rangeAnchor = { id, type };
        },
        toggleRow(id, type) {
            this.markAnchor(id, type);
            this.$emit('toggle-select', id, type);
        },
        selectRangeTo(id, type) {
            const items = this.visibleItems();
            const from  = this.rangeAnchor ? items.findIndex(i => i.id === this.rangeAnchor.id && i.type === this.rangeAnchor.type) : -1;
            const to    = items.findIndex(i => i.id === id && i.type === type);
            if (from === -1 || to === -1) { this.toggleRow(id, type); return; }
            const [lo, hi] = from <= to ? [from, to] : [to, from];
            this.$emit('select-range', items.slice(lo, hi + 1));
        },
        onCheckboxClick(e, item, type) {
            if (e?.detail > 1) return; // second click of a native double-click
            if (e?.shiftKey) { this.selectRangeTo(item.id, type); return; }
            this.toggleRow(item.id, type);
        },
        /**
         * Row click routing. A plain click opens the item only while nothing is selected;
         * once a selection exists (or with Cmd/Ctrl/Shift held) a click selects instead,
         * so building a multi-select never navigates away by accident. While selecting, the
         * row's own preview/edit buttons open an item. There is no dblclick handler, and the
         * second click of a native double-click (event.detail > 1) is ignored, so a double-click
         * can never deselect a row and then open it.
         */
        onRowClick(e, item, type, open) {
            if (e?.detail > 1) return; // second click of a native double-click: never toggle-then-open
            if (e?.shiftKey) { this.selectRangeTo(item.id, type); return; }
            if (e?.metaKey || e?.ctrlKey || this.selection.ids.length > 0) { this.toggleRow(item.id, type); return; }
            open();
        },
        isBookmarked(dir) {
            return this.bookmarkedDirIds.map(Number).includes(Number(dir.id));
        },
        sort(col) {
            const ord = this.sortBy === col && this.sortOrder === 'asc' ? 'desc' : 'asc';
            this.$emit('sort-change', { sortBy: col, sortOrder: ord });
        },
        icon(type) { return { image: 'icon-dam-image', video: 'icon-dam-video', audio: 'icon-dam-audio', document: 'icon-dam-doc' }[type] ?? 'icon-dam-image'; },
        badge(type, ext) {
            if ((ext||'').toLowerCase() === 'pdf')    return 'bg-red-100 text-red-700';
            if (type === 'video' || type === 'audio') return 'bg-primary-100 text-primary-700';
            if (type === 'image')                     return 'bg-primary-100 text-primary-700';
            if (type === 'spreadsheet')               return 'bg-green-100 text-green-700';
            return 'bg-gray-100 text-gray-600';
        },
        stackLine(asset) {
            return window.MothershipStackLine ? window.MothershipStackLine(asset) : '';
        },
        versionChips(asset) {
            const chips = Array.isArray(asset.versions) ? asset.versions : [];
            if (chips.length <= 3) return chips;
            const rest = chips.slice(2);
            return [...chips.slice(0, 2), { kind: 'more', label: `+${rest.length}`, rest: rest.map(c => c.label).join(', ') }];
        },
        chipColor(chip) {
            return { optimized: '#059669', svg: '#7c3aed' }[chip.kind] ?? 'rgba(17,24,39,.75)';
        },
        chipTitle(chip) {
            if (chip.kind === 'optimized') return "@lang('dam::app.admin.dam.asset.files.version-chip.optimized')";
            if (chip.kind === 'svg') return "@lang('dam::app.admin.dam.asset.files.version-chip.svg')";
            if (chip.kind === 'more') return `${"@lang('dam::app.admin.dam.asset.files.versions-available')"}: ${chip.rest}`;
            return "@lang('dam::app.admin.dam.asset.files.version-chip.type')".replace(':type', chip.label);
        },
        fmtSize(b) {
            if (!b) return '—';
            return b >= 1048576 ? (b/1048576).toFixed(1)+' MB' : b >= 1024 ? (b/1024).toFixed(0)+' KB' : b+' B';
        },
        fmtDate(iso) {
            if (!iso) return '—';
            return new Date(iso).toLocaleDateString(undefined, { day:'numeric', month:'short', year:'numeric' });
        },
        showCtx(e, item, type) {
            this.closeCtx();
            this.ctxKey++;
            // A keyboard activation has no pointer position (0,0): the menu hangs off its trigger instead.
            const trigger = e.clientX === 0 && e.clientY === 0 ? e.target?.closest?.('.dam-ctx-trigger') : null;
            const anchor = trigger ? trigger.getBoundingClientRect() : null;
            this.ctx = { on: true, x: anchor ? anchor.left : e.clientX, y: anchor ? anchor.bottom + 4 : e.clientY, item, type };
            let lastScrollY = window.scrollY, lastScrollX = window.scrollX;
            this._ctxScroll = () => {
                const dy = window.scrollY - lastScrollY, dx = window.scrollX - lastScrollX;
                lastScrollY = window.scrollY; lastScrollX = window.scrollX;
                this.ctx = { ...this.ctx, y: this.ctx.y - dy, x: this.ctx.x - dx };
            };
            this._ctxClose = () => this.closeCtx();
            document.addEventListener('click',  this._ctxClose, { once: true });
            window.addEventListener('scroll', this._ctxScroll, { capture: true });
            this.$emitter.emit(`dam:ctx-open:${this.tabId}`, { item, type });
        },
        closeCtx() {
            this.ctx.on = false;
            if (this._ctxClose)  { document.removeEventListener('click',  this._ctxClose); this._ctxClose  = null; }
            if (this._ctxScroll) { window.removeEventListener('scroll', this._ctxScroll, { capture: true }); this._ctxScroll = null; }
        },
        showSpaceCtx(e) {
            if (! this.currentDirId) return;
            this.showCtx(e, { id: this.currentDirId, can_access: this.canAccessCurrentDir }, 'space');
        },
        trackResultOpened(id) {
            if (this.searchSmart === null) return;
            window.damTrack?.('search.result.opened', id, { position: this.resultOffset + this.assets.findIndex(a => a.id === id) + 1, smart: this.searchSmart }, 'library');
        },
        preview(id) { this.trackResultOpened(id); this.$emitter.emit('dam-open-preview', { id, siblings: this.assets.map(a => a.id), directoryId: this.currentDirId || 'all', total: this.resultTotal, offset: this.resultOffset }); },
        edit(id) {
            this.trackResultOpened(id);
            // Hand the visible list order AND the exact browsed scope (this folder, or "all"
            // when currentDirId is null) over, so the details page's chevrons walk the same
            // order and folder this click came from, instead of the server's fallback order.
            const directoryId = this.currentDirId || 'all';
            window.MothershipDetailsNavigation?.writeSiblings({ siblings: this.assets.map(a => a.id), scopeLabel: 'list', directoryId, total: this.resultTotal, offset: this.resultOffset });
            const url = `{{ route('admin.dam.assets.edit', ':id') }}`.replace(':id', id);
            this.$navigate(window.MothershipDetailsNavigation ? window.MothershipDetailsNavigation.withNavMarker(url, directoryId) : url);
        },
        // A stack's row speaks for all its files (VISR-09): "Stack" for its type, the files' total for its size,
        // with the cover's own type and size in the tooltip.
        adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
        rowType(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "@lang('dam::app.admin.dam.asset.stack.title')" : (asset.adobe?.label || asset.file_type);
        },
        rowTypeTitle(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "@lang('dam::app.admin.dam.asset.stack.cover-type')".replace(':type', () => String(asset.extension || asset.file_type || '').toUpperCase()) : null;
        },
        rowSize(asset) {
            return Number(asset.attachment_count || 0) > 1 && Number(asset.attachment_size || 0) > 0 ? asset.attachment_size : asset.file_size;
        },
        rowSizeTitle(asset) {
            return Number(asset.attachment_count || 0) > 1 ? "@lang('dam::app.admin.dam.asset.stack.total-size')".replace(':count', () => asset.attachment_count) : null;
        },
        // A row opens its entry at once, a stack's row its main file. A stack's files open from the count beside its
        // name (D5), which is only rendered for a role that may list them (dam.asset.files.index, R2).
        openAsset(asset, mode) {
            this[mode](asset.id);
        },
        stackOpenLabel(asset) {
            return "@lang('dam::app.admin.dam.asset.stack-fan.open')".replace(':count', () => Number(asset.attachment_count || 0));
        },
        openStack({ asset, el }) {
            this.$emitter.emit('dam:open-stack-fan', {
                asset, el,
                siblings: this.assets.map(a => a.id),
                directoryId: this.currentDirId || 'all',
                total: this.resultTotal,
                offset: this.resultOffset,
                onDetails: @json(bouncer()->hasPermission('dam.asset.edit')) ? () => this.edit(asset.id) : null,
            });
        },
        del(asset) {
            this.$emitter.emit('open-delete-modal', {
                message: window.MothershipStackDeleteMessage?.(asset),
                agree: () => {
                    this.$axios.delete(`{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', asset.id))
                        .then(({ data }) => { this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.explorer.action-completed')" }); this.$emit('refresh'); this.$emitter.emit('dam:tree-reload'); })
                        .catch(err => this.$emitter.emit('add-flash', { type: 'error', message: err?.response?.data?.message }));
                },
            });
        },
        _makeFolderDragGhost(name) {
            const ghost = document.createElement('div');
            ghost.style.cssText = 'position:fixed;top:0;left:0;width:96px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;border-radius:12px;background:rgba(237,233,254,0.95);box-shadow:0 2px 8px rgba(0,0,0,0.12);pointer-events:none;';
            const icon = document.createElement('i');
            icon.className = 'icon-dam-folder';
            icon.style.cssText = 'font-size:60px;color:#a78bfa;line-height:1;';
            const label = document.createElement('span');
            label.style.cssText = 'font-size:11px;color:#374151;overflow-wrap:anywhere;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;';
            label.textContent = name;
            ghost.appendChild(icon);
            ghost.appendChild(label);
            document.body.appendChild(ghost);
            setTimeout(() => ghost.remove(), 0);
            return ghost;
        },
        _makeAssetDragGhost(fileName, iconClass) {
            const ghost = document.createElement('div');
            ghost.style.cssText = 'position:fixed;top:0;left:0;width:96px;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 8px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,0.12);pointer-events:none;';
            const icon = document.createElement('i');
            icon.className = iconClass;
            icon.style.cssText = 'font-size:60px;color:#a78bfa;line-height:1;';
            const label = document.createElement('span');
            label.style.cssText = 'font-size:11px;color:#374151;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;width:100%;';
            label.textContent = fileName;
            ghost.appendChild(icon);
            ghost.appendChild(label);
            document.body.appendChild(ghost);
            setTimeout(() => ghost.remove(), 0);
            return ghost;
        },

        _anchorDragGhost(ghost, e, anchorX, anchorY) {
            ghost.style.left = `${Math.min(Math.max(0, e.clientX - anchorX), window.innerWidth - ghost.offsetWidth)}px`;
            ghost.style.top = `${Math.min(Math.max(0, e.clientY - anchorY), window.innerHeight - ghost.offsetHeight)}px`;
        },
        onDirDragStart(e, dir) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({
                type: 'dam-folder', id: dir.id, name: dir.name,
                assetsCount: dir.assets_count ?? 0, tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: dir.id, type: 'directory', selection: this.selection.ids }),
            }));
            const ghost = this._makeFolderDragGhost(dir.name);
            this._anchorDragGhost(ghost, e, 48, 50);
            e.dataTransfer.setDragImage(ghost, 48, 50);
            this._draggingPayload = { type: 'dam-folder', id: dir.id };
            e.currentTarget.style.opacity = '0.4';
        },
        onAssetDragStart(e, asset) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/json', JSON.stringify({
                type: 'dam-asset', id: asset.id, name: (asset.display_name || asset.file_name), tabId: this.tabId,
                ...window.MothershipFileOps.selectionDragFields({ id: asset.id, type: 'asset', selection: this.selection.ids }),
            }));
            const iconMap = { image: 'icon-dam-image', video: 'icon-dam-video', audio: 'icon-dam-audio', document: 'icon-dam-doc' };
            const ghost = this._makeAssetDragGhost((asset.display_name || asset.file_name), iconMap[asset.file_type] ?? 'icon-dam-image');
            this._anchorDragGhost(ghost, e, 48, 50);
            e.dataTransfer.setDragImage(ghost, 48, 50);
            this._draggingPayload = { type: 'dam-asset', id: asset.id };
            e.currentTarget.style.opacity = '0.4';
            this.draggingId = asset.id;
            this.$emitter.emit('dam:asset-drag-state', { active: true });
        },
        onDragEnd(e) {
            e.currentTarget.style.opacity = '';
            this.dropTargetId = null;
            this.stackDropId = null;
            this.draggingId = null;
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this._draggingPayload = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
        },
        /** Any dragged file (from a card or a row) shows the hint on this list's stack rows, except the row being dragged. */
        onAssetDragState({ active } = {}) {
            this.dragHint = !! active;
        },
        /** A stack row takes a dropped file or selection, as a stack card does in the grid (OS files and folders are left to their handlers). */
        onStackDragOver(e, asset) {
            if (Number(asset.attachment_count || 0) <= 1 || (this._draggingPayload?.type === 'dam-asset' && this._draggingPayload.id === asset.id)) return;
            const types = Array.from(e.dataTransfer?.types || []);
            if (types.includes('Files') || ! types.includes('application/json')) return;
            e.preventDefault();
            this.stackDropId = asset.id;
        },
        onStackDragLeave(e, asset) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            if (this.stackDropId === asset.id) this.stackDropId = null;
        },
        onStackDrop(e, asset) {
            this.stackDropId = null;
            if (Number(asset.attachment_count || 0) <= 1 || (this._draggingPayload?.type === 'dam-asset' && this._draggingPayload.id === asset.id)) return;
            const payload = window.MothershipFileOps.parseDropPayload(e.dataTransfer);
            if (payload?.type !== 'dam-asset') return;
            e.preventDefault();
            e.stopPropagation();
            this.$emitter.emit('dam:asset-drag-state', { active: false });
            this.$emit('stack-drop', { payload, asset });
        },
        onDirDragOver(e, dir) {
            if (this._draggingPayload?.type === 'dam-folder' && this._draggingPayload?.id === dir.id) return;
            this.dropTargetId = dir.id;
            if (this._springTimer?.dirId === dir.id) return;
            clearTimeout(this._springTimer?.id);
            const timerId = setTimeout(() => {
                if (this._springTimer?.dirId === dir.id) {
                    this.$emit('navigate', dir);
                    this._springTimer = null;
                    this.dropTargetId = null;
                }
            }, 1200);
            this._springTimer = { dirId: dir.id, id: timerId };
        },
        onDirDragLeave(e, dir) {
            if (this._springTimer?.dirId !== dir.id) return;
            if (! e.currentTarget.contains(e.relatedTarget)) {
                clearTimeout(this._springTimer?.id);
                this._springTimer = null;
                this.dropTargetId = null;
            }
        },
        onDirDrop(e, dir) {
            this.dropTargetId = null;
            clearTimeout(this._springTimer?.id);
            this._springTimer = null;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;
            if (payload.type === 'dam-folder' && payload.id === dir.id) return;
            this.$emit('internal-drop', { payload, targetDir: dir });
        },
        onSpaceDrop(e) {
            if (e.dataTransfer?.types?.includes('Files')) return;
            if (! this.currentDirId) return;
            let payload;
            try { payload = JSON.parse(e.dataTransfer.getData('application/json')); } catch { return; }
            if (! payload?.type) return;
            if (payload.type === 'dam-folder' && this.directories.some(d => d.id === payload.id)) return;
            if (payload.type === 'dam-asset' && this.assets.some(a => a.id === payload.id)) return;
            this.$emit('internal-drop', { payload, targetDir: { id: this.currentDirId } });
        },
        renameDir(dir) { this.$emitter.emit('dam:open-rename-dir', { item: dir }); },
        delDir(dir) {
            const tabId = this.tabId;
            this.$emitter.emit('open-delete-modal', {
                message: "@lang('dam::app.admin.components.modal.confirm.message')",
                agree: () => {
                    this.$axios.delete(`{{ route('admin.dam.directory.destroy', ':id') }}`.replace(':id', dir.id))
                        .then(({ data }) => {
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.explorer.action-completed')" });
                            let attempts = 0;
                            const poll = () => {
                                if (++attempts > 15) return;
                                this.$axios.get(`{{ route('admin.dam.action_request.status', ':et') }}`.replace(':et', 'delete_directory'))
                                    .then(({ data: d }) => {
                                        if (d.status === 'completed') {
                                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.explorer.action-completed')" });
                                            this.$emitter.emit('dam:directory-deleted', { id: dir.id });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                            this.$emitter.emit('dam:tree-reload');
                                            this.$emitter.emit(`dam:dir-deleted:${tabId}`);
                                        } else if (d.status === 'failed') {
                                            this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                        } else { setTimeout(poll, 2000); }
                                    }).catch(() => {});
                            };
                            setTimeout(poll, 1000);
                        })
                        .catch(err => this.$emitter.emit('add-flash', { type: 'error', message: err?.response?.data?.message }));
                },
            });
        },
    },
});
</script>
@endpush
@endonce
