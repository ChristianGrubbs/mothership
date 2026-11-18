@props(['visible' => true])
{{-- The shared navigation styles/scripts are included from <head> by both admin layouts. A second
     include here rendered first (component slots render before the layout) and consumed the
     partial's @once, leaving the styles inside the Vue mount and out of the head. --}}
@include('dam::components.explorer.folder-picker')
<div class="flex flex-col gap-2">
    <x-dam::tree.search />
    <x-dam::tree.asset-count-badge />

    <v-dam-tree-view
        :acl-bypass="{{ dam_acl_bypass() ? 'true' : 'false' }}"
        :accessible-ids='@json(dam_accessible_dir_ids())'
        :show-assets="{{ config('dam.tree.show_assets') ? 'true' : 'false' }}"
        :visible="{{ $visible ? 'true' : 'false' }}"
    >
        <div class="tree-container overflow-hidden" style="max-height: calc(100vh - 360px);">
            <x-dam::tree.shimmer />
        </div>
    </v-dam-tree-view>
</div>

{{-- Retired under `lists`: the Share dialog replaces this panel, and the folder grants are no longer
     read (ADR 0010, sharing slice 3). Under `grants` it renders exactly as before. --}}
@if (! dam_access_lists() && auth('admin')->user()?->role?->permission_type === 'all')
    @include('dam::components.folder-access')
@endif

@pushOnce('scripts')
<script type="text/x-template" id="v-asset-item-template">
    <div
        class="tree-container-assets-details"
    >
        <div
            class="flex gap-1 w-full p-1"
            :class="treeLocked ? 'cursor-not-allowed opacity-60 pointer-events-none' : 'cursor-pointer'"
            :aria-disabled="treeLocked"
            @click.stop="treeLocked ? null : setFilters(item)"
            @contextmenu.prevent.stop="treeLocked ? null : showContextMenu($event, item)"
        >
            <span>
                <i
                    class="text-xl transition-all group-hover:text-gray-800 dark:group-hover:text-white cursor-grab"
                    :class="getFileTypeIcon(item)"
                ></i>
            </span>
            <span
                class="text-sm"
                :class="selectedItem && selectedItem.file_name && item.id == selectedItem.id ? 'text-primary-700 dark:text-primary-400 font-semibold' : 'text-zinc-600 dark:text-white'"
            >@{{ item.display_name || formatFileName(item.file_name) }}</span>
        </div>
    </div>
</script>
<script type="module">
    app.component('v-asset-item', {
        template: "#v-asset-item-template",
        props: {
            item: Object,
            selectedItem: Object,
            treeLocked: {
                type: Boolean,
                default: false,
            },
        },
        mounted() {
            this.$emitter.on('update-current-item', (data) => {
                if (data.id === this.item.id) {
                    this.item = data;
                }
            });
        },
        methods: {
            setFilters(item) {
                this.$emit("set-filters", item, 'asset');
            },

            getFileTypeIcon(item) {
                switch (item.file_type) {
                    case 'image':
                        return 'icon-dam-image';
                    case 'video':
                        return 'icon-dam-video';
                    case 'audio':
                        return 'icon-dam-audio';
                    case 'document':
                        return 'icon-dam-doc';
                    default:
                        return 'icon-dam-image';
                }
            },

            formatFileName(fileName) {
                if (fileName.length > 29) {
                    fileName = fileName.substring(0, 20) + '...' + fileName.substring(fileName.lastIndexOf('.'));
                }

                return fileName
            },

            selectItem(item) {
                this.$emit('select-item', item);
            },

            openContextMenu(event, item) {
                event.stopPropagation();
                this.$emit('open-context-menu', event, item);
            },

            showContextMenu(event, item) {
                event.stopPropagation();
                this.contextMenuPosition = {
                    x: event.pageX,
                    y: event.pageY
                };
                this.showContextMenuFlag = true;
                this.$emit("right-click-item", event, item, 'asset');
            },
        }
    });
</script>
<script type="text/x-template" id="v-item-template">
    <div class="tree-container-details">
        <div
            v-if="!item.is_structural_wrapper"
            class="flex items-center gap-1 w-full pl-1 pt-1"
            :title="item.name"
            @keydown.shift.f10.prevent="isBusy ? null : showContextMenu($event, item)"
            :class="[
                isBusy ? 'cursor-not-allowed opacity-60 pointer-events-none' : 'cursor-pointer',
                moveTargetState === 'valid' ? 'rounded-md bg-primary-50 ring-2 ring-inset ring-primary-500 dark:bg-cherry-800' : '',
                moveTargetState === 'invalid' ? 'rounded-md opacity-50 ring-2 ring-inset ring-red-400' : '',
            ]"
            :aria-disabled="isBusy"
            :data-dir-id="item.id"
            @drop="onNativeDrop($event)"
            @click.stop="isBusy ? null : toggle(item)"
            @contextmenu.prevent.stop="isBusy ? null : showContextMenu($event, item)"
            @dragenter.prevent="isBusy ? null : onDragEnter()"
            @dragleave="cancelHoverExpand"
            @dragover.prevent
        >
            <span
                class="text-xl text-zinc-600 dark:text-white"
                v-if="isDirectory || isAssets"
                :class="isOpen ? 'icon-dam-close' : 'icon-dam-open'"
            >
            </span>
            <span>
                <svg
                    v-if="isSelfBusy"
                    class="align-center inline-block animate-spin h-5 w-5 text-primary-700"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>
                    <path
                        class="opacity-75"
                        fill="#8A2BE2"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                </svg>
                <i v-else class="icon-dam-folder text-xl transition-all group-hover:text-gray-800 dark:group-hover:text-white cursor-grab"></i>
            </span>
            <button type="button"
                @click.stop="isBusy ? null : toggle(item)" :disabled="isBusy" :aria-expanded="isDirectory ? isOpen : undefined"
                class="text-sm dam-directory-label"
                :class="selectedItem && item.id == selectedItem.id ? 'text-primary-700 dark:text-primary-400 font-semibold' : 'text-zinc-600 dark:text-white'"
            >@{{ item?.name }}</button>
            <v-asset-count-badge :count="item?.assets_total_count ?? null" />
            <button type="button" :aria-label="'Actions for ' + item.name" :disabled="isBusy" class="shrink-0 px-1 text-lg text-zinc-600 dark:text-gray-300" @click.stop="showContextMenu($event, item)" @keydown.enter.stop @keydown.space.stop>⋯</button>
        </div>
        <div
            v-show="isOpen || item.is_structural_wrapper"
            v-if="hasDropZone"
            class="flex flex-col" :class="item.is_structural_wrapper ? '' : 'pl-4'"
        >
            <draggable
                id="child-tree-groups"
                class="directoryItems"
                ghost-class="draggable-ghost"
                handle=".icon-dam-folder"
                v-bind="{animation: 200}"
                :list="item.children"
                item-key="id"
                :sort='false'
                group="directoryItems"
                @change="onMergeItems($event, item.id)"
                @start="onDragStart"
                @end="onDragEnd"
            >
                <template #item="{ element, index }">
                    <div class="sub-tree-container">
                        <v-dam-tree-item
                            class="sub-tree-item"
                            :item="element"
                            :key="element.id"
                            @right-click-item="showContextMenu"
                            @set-filters="setFilters"
                            @on-merge-items="onMergeItems"
                            @on-drag-start="onDragStart"
                            @on-drag-end="onDragEnd"
                            :selectedItem="selectedItem"
                            :movingDirectoryId="movingDirectoryId"
                            :deletingDirectoryId="deletingDirectoryId"
                            :copyingDirectoryId="copyingDirectoryId"
                            :treeLocked="treeLocked"
                            :aclBypass="aclBypass"
                            :accessibleIds="accessibleIds"
                            :draggedDirectoryId="draggedDirectoryId"
                            :draggedDescendantIds="draggedDescendantIds"
                        ></v-dam-tree-item>
                    </div>
                </template>
            </draggable>

            <button
                v-if="childrenHasMore"
                type="button"
                @click.stop="loadMoreChildren"
                :disabled="childrenLoadingMore"
                class="flex items-center gap-1.5 ml-2 mt-0.5 mb-1 px-2 py-1 text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline disabled:opacity-50"
            >
                <span v-if="childrenLoadingMore" class="icon-spinner animate-spin text-sm"></span>
                <span>@lang('dam::app.admin.dam.index.directory.load-more')</span>
            </button>

            <draggable
                id="assets-items"
                ghost-class="draggable-ghost"
                handle=".tree-container-assets-details"
                v-bind="{ animation: 200 }"
                :list="localAssets"
                item-key="id"
                :sort='false'
                group="itemsAssets"
                @start="onDragStart"
                @end="onDragEnd"
                @change="onMergeItems($event, item.id, 'asset')"
            >
                <template #item="{ element, index }">
                    <div>
                        <v-asset-item
                            :item="element"
                            @set-filters="setFilters"
                            @right-click-item="showContextMenu"
                            :selectedItem="selectedItem"
                            @on-merge-items="onMergeItems"
                            @on-drag-start="onDragStart"
                            :treeLocked="treeLocked"
                        />
                    </div>
                </template>
            </draggable>
        </div>
    </div>
    <draggable
        v-if="!isDirectory"
        id="child-tree-groups"
        class="mb-1 directoryItems ml-6"
        ghost-class="draggable-ghost"
        handle=".icon-dam-folder"
        v-bind="{animation: 200}"
        :list="item.children"
        item-key="id"
        :sort='false'
        group="directoryItems"
        @change="onMergeItems($event, item.id)"
        @start="onDragStart"
        @end="onDragEnd"
    >
        <template #item="{ element, index }">

        </template>
    </draggable>
    <draggable
        v-if="!isAssets"
        id="assets-items"
        class="mb-1 itemsAssets ml-6"
        ghost-class="draggable-ghost"
        handle=".tree-container-assets-details"
        v-bind="{ animation: 200 }"
        :list="localAssets"
        item-key="id"
        :sort='false'
        group="itemsAssets"
        @change="onMergeItems($event, item.id, 'asset')"
        @start="onDragStart"
        @end="onDragEnd"
    >
        <template #item="{ element, index }">

        </template>
    </draggable>
</script>
<script type="module">
    app.component('v-dam-tree-item', {
        template: "#v-item-template",
        inject: ['damTreeShowAssets'],
        props: {
            item: Object,
            selectedItem: Object,
            movingDirectoryId: {
                type: [String, Number],
                default: null,
            },
            deletingDirectoryId: {
                type: [String, Number],
                default: null,
            },
            copyingDirectoryId: {
                type: [String, Number],
                default: null,
            },
            treeLocked: {
                type: Boolean,
                default: false,
            },
            aclBypass: {
                type: Boolean,
                default: false,
            },
            accessibleIds: {
                type: Array,
                default: () => [],
            },
            draggedDirectoryId: {
                type: [String, Number],
                default: null,
            },
            draggedDescendantIds: {
                type: Array,
                default: () => [],
            },
        },
        data: function() {
            return {
                isOpen: !!this.item.is_structural_wrapper,
                showContextMenuFlag: false,
                contextMenuPosition: { x: 0, y: 0 },
                assetsLoaded: false,
                assetsLoading: false,
                assetsStale: false,
                childrenLoading: false,
                childrenLoadingMore: false,
                hoverExpandTimer: null,

                localAssets: [],
            };
        },
        mounted() {

            if (Array.isArray(this.item.assets) && this.item.assets.length) {
                this.localAssets.splice(0, 0, ...this.item.assets);
            }

            if (Array.isArray(this.item.children) && this.item.children.length) {
                this.item._loaded = true;
                // The tree already asked for the counts of the folders it loaded (root and its children): a child mounted
                // afterwards asks only for the ones nobody has asked about, so a fresh Library load sends one request.
                this.fetchChildCounts(this.item.children.filter((child) => ! child._countStamp).map((child) => child.id));
            }

            if (this.item.is_structural_wrapper) {
                if (this.item.has_children && !this.childrenLoaded) this.loadChildDirectories();
                if (!this.assetsLoaded) this.loadDirectoryAssets();
            }

            // Kept by name so beforeUnmount removes exactly these: a folder that is gone must not answer or request anything.
            this._emitterHandlers = {
                'current-item-expanded': (data) => {
                    if (data.id !== this.item.id) return;
                    this.isOpen = true;
                    if (this.item.has_children && ! this.childrenLoaded && ! this.childrenLoading) {
                        this.loadChildDirectories();
                    }
                    if (! this.assetsLoaded && ! this.assetsLoading) {
                        this.loadDirectoryAssets();
                    }
                },
                'update-current-item': (data) => {
                    if (data.id === this.item.id) this.item = data;
                },
                'invalidate-dir-assets': (dirId) => {
                    if (dirId == null || dirId == this.item.id) {
                        this.invalidateAssetCache();
                    }
                },
                // A stack was made or split: the files this folder lists changed, whether or not it is open.
                'refresh-dir-assets': () => this.refreshAssets(),
            };

            Object.entries(this._emitterHandlers).forEach(([event, handler]) => this.$emitter.on(event, handler));
        },
        beforeUnmount() {
            Object.entries(this._emitterHandlers || {}).forEach(([event, handler]) => this.$emitter.off(event, handler));
            // File lists still on their way belong to a folder that is gone.
            this._assetsGeneration = (this._assetsGeneration || 0) + 1;
            this.cancelHoverExpand();
        },
        watch: {

            'item.id'() {
                this._assetsGeneration = (this._assetsGeneration || 0) + 1;
                this.assetsLoaded = false;
                this.assetsLoading = false;
                this.assetsStale = false;
                this.childrenLoaded = false;
                this.childrenLoading = false;
                this.localAssets.splice(0, this.localAssets.length);
            },

            'item.children'(newChildren) {
                if (Array.isArray(newChildren) && newChildren.length === 0 && this.childrenLoaded) {
                    this.childrenLoaded = false;
                }
            },
        },
        computed: {

            childrenLoaded: {
                get() {
                    return !! this.item._loaded;
                },
                set(value) {
                    this.item._loaded = value;
                },
            },

            childrenHasMore() {
                return !! this.item.children_has_more;
            },

            isDirectory: function() {
                return (this.item.children && Object.keys(this.item.children).length)
                    || this.item.has_children;
            },

            isAssets: function() {

                if (! this.damTreeShowAssets) return false;

                return this.localAssets.length > 0
                    || (this.item.assets_count && this.item.assets_count > 0);
            },

            hasDropZone: function() {
                return this.isDirectory || this.isAssets || this.assetsLoaded;
            },

            isMoving: function() {
                return this.movingDirectoryId !== null
                    && this.item
                    && this.item.id == this.movingDirectoryId;
            },

            isDeleting: function() {
                return this.deletingDirectoryId !== null
                    && this.item
                    && this.item.id == this.deletingDirectoryId;
            },

            isCopying: function() {
                return this.copyingDirectoryId !== null
                    && this.item
                    && this.item.id == this.copyingDirectoryId;
            },

            isSelfBusy: function() {

                return this.isMoving || this.isDeleting || this.isCopying;
            },

            isBusy: function() {

                return this.isSelfBusy || this.treeLocked;
            },
            moveTargetState() {
                if (this.draggedDirectoryId == null) return null;

                return window.MothershipLibraryNavigation.isValidFolderMoveTarget({
                    sourceId: this.draggedDirectoryId,
                    targetId: this.item.id,
                    descendantIds: this.draggedDescendantIds,
                    accessibleIds: this.accessibleIds,
                    aclBypass: this.aclBypass,
                }) ? 'valid' : 'invalid';
            },
        },
        methods: {
            onNativeDrop(event) {
                // Only claim asset drops: Sortable.js finalizes its own folder drags through a
                // document-level drop listener, so native/other payloads must keep propagating.
                const payload = window.MothershipFileOps.parseDropPayload(event.dataTransfer);
                // An explorer multi-selection moves through its origin tab's mass move.
                if (window.MothershipFileOps.isSelectionDrag(payload)) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (! this.isBusy) this.$emitter.emit(`dam:explorer-internal-drop:${payload.tabId}`, { payload, targetDir: { id: this.item.id, name: this.item.name } });
                    return;
                }
                if (payload?.type !== 'dam-asset') return;
                event.preventDefault();
                event.stopPropagation();
                if (this.isBusy) return;
                this.$emitter.emit('dam:native-asset-drop', { targetId: this.item.id, targetName: this.item.name, ids: payload.ids || [payload.id] });
            },

            onDragStart(event, draggedItem = null) {
                this.$emit("on-drag-start", event, draggedItem || event?.item?._underlying_vm_ || null);
            },

            onDragEnd(event) {
                this.$emit("on-drag-end", event);
            },

            onMergeItems(event, id, type = 'directory') {
                this.$emit("on-merge-items", event, id, type);
            },

            toggle: function(item) {
                const willOpen = ! this.isOpen;
                if (this.isDirectory || this.isAssets || ! this.assetsLoaded) {
                    this.isOpen = willOpen;
                }

                if (willOpen) {
                    if (this.item.has_children && ! this.childrenLoaded && ! this.childrenLoading) {
                        this.loadChildDirectories();
                    }
                    if (! this.assetsLoaded && ! this.assetsLoading) {
                        this.loadDirectoryAssets();
                    }
                }

                this.$emit("set-filters", item);
            },

            replaceAssetsInPlace(fresh) {
                this.localAssets.splice(0, this.localAssets.length, ...fresh);
            },

            loadDirectoryAssets() {

                if (! this.damTreeShowAssets) {
                    this.assetsLoaded = true;
                    return;
                }

                if (this.assetsLoading) {
                    this.assetsStale = true;
                    return;
                }
                this.assetsLoading = true;
                const generation = this._assetsGeneration || 0;
                const settled = () => {
                    this.assetsLoading = false;
                    // A merge or split arrived while this read was out: read again, whether this one worked or not.
                    if (this.assetsStale) {
                        this.assetsStale = false;
                        this.loadDirectoryAssets();
                    }
                };
                this.$axios
                    .get(`{{ route('admin.dam.directory.assets', ':id') }}`.replace(':id', this.item.id))
                    .then((response) => {
                        // The folder was unmounted or became another folder meanwhile: this answer is not for it.
                        if (generation !== (this._assetsGeneration || 0)) return;

                        this.replaceAssetsInPlace(response.data.data || []);
                        this.assetsLoaded = true;
                        settled();
                    })
                    .catch(() => {
                        if (generation !== (this._assetsGeneration || 0)) return;

                        // The rows already shown stay as they are.
                        settled();
                    });
            },

            onDragEnter() {
                if (this.assetsLoading) return;
                if (this.draggedDirectoryId != null && this.moveTargetState !== 'valid') return;
                this.cancelHoverExpand();
                this.hoverExpandTimer = setTimeout(() => {
                    if (! this.isOpen) this.isOpen = true;
                    if (this.item.has_children && ! this.childrenLoaded && ! this.childrenLoading) this.loadChildDirectories();
                    if (! this.assetsLoaded) this.loadDirectoryAssets();
                }, 550);
            },

            cancelHoverExpand() {
                clearTimeout(this.hoverExpandTimer);
                this.hoverExpandTimer = null;
            },

            loadChildDirectories() {
                if (this.childrenLoading || this.childrenLoaded) return;
                this.childrenLoading = true;
                this.$axios
                    .get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', this.item.id), { params: { offset: 0 } })
                    .then((response) => {
                        const children = response.data.data || [];
                        if (! Array.isArray(this.item.children)) {
                            this.item.children = [];
                        }
                        this.item.children.splice(0, this.item.children.length, ...children);
                        this.item.children_has_more = !! response.data.has_more;
                        this.childrenLoaded = true;
                        this.childrenLoading = false;
                        this.fetchChildCounts(children.map((child) => child.id));
                    })
                    .catch(() => {
                        this.childrenLoading = false;
                    });
            },

            loadMoreChildren() {
                if (this.childrenLoadingMore || ! this.childrenHasMore) return;
                this.childrenLoadingMore = true;
                const offset = Array.isArray(this.item.children) ? this.item.children.length : 0;
                this.$axios
                    .get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', this.item.id), { params: { offset } })
                    .then((response) => {
                        const children = response.data.data || [];
                        if (! Array.isArray(this.item.children)) {
                            this.item.children = [];
                        }
                        this.item.children.push(...children);
                        this.item.children_has_more = !! response.data.has_more;
                        this.childrenLoadingMore = false;
                        this.fetchChildCounts(children.map((child) => child.id));
                    })
                    .catch(() => {
                        this.childrenLoadingMore = false;
                    });
            },

            fetchChildCounts(ids) {
                ids = (ids || []).filter((id) => id != null);
                if (! ids.length) return;
                // Each request stamps the folders it asks about; an answer older than the folder's latest request is dropped.
                const asked = (this.item.children || []).filter((child) => ids.includes(child.id));
                const stamps = asked.map((child) => (child._countStamp = (child._countStamp || 0) + 1));
                this.$axios
                    .post("{{ route('admin.dam.directory.asset_counts') }}", { ids })
                    .then((response) => {
                        const counts = response.data.data || {};
                        asked.forEach((child, index) => {
                            const value = counts[child.id];
                            if (value !== undefined && child._countStamp === stamps[index]) {
                                child.assets_total_count = value;
                            }
                        });
                    })
                    .catch(() => {});
            },

            /**
             * Re-reads the files this folder lists, in place: its list is on screen (open) or was read before, so a file a merge hid or a split brought back must show. Selection, filters and expansion stay as
             * they are; a request already in flight is followed by one more (assetsStale), so the last answer wins.
             */
            refreshAssets() {
                if (! this.damTreeShowAssets) return;

                // A folder whose files were ever read (even to find none) is re-read; one never looked into stays lazy.
                const known = this.assetsLoaded || this.localAssets.length > 0;
                this.assetsLoaded = false;

                if (this.assetsLoading) {
                    this.assetsStale = true;
                    return;
                }

                if (this.isOpen || known) {
                    this.loadDirectoryAssets();
                }
            },

            invalidateAssetCache() {
                if (this.assetsLoading) {
                    this.assetsStale = true;
                    return;
                }
                this.assetsLoaded = false;
                if (this.isOpen) {
                    this.loadDirectoryAssets();
                }
            },

            setFilters: function(item, type = 'directory') {
                this.$emit("set-filters", item, type);
            },

            showContextMenu(event, item, type = 'directory') {
                event.stopPropagation();
                this.contextMenuPosition = {
                    x: event.pageX,
                    y: event.pageY
                };
                this.showContextMenuFlag = true;
                this.$emit("right-click-item", event, item, type);
            },

        }
    });
</script>
<script type="text/x-template" id="v-dam-tree-view-template">
    <template v-if="treeLoading">
        <div class="tree-container overflow-hidden" style="max-height: calc(100vh - 360px);">
            <x-dam::tree.shimmer />
        </div>
    </template>
    <div
            class="relative"
            ref="treeContainer"
            v-else-if="formattedItems && formattedItems.length"
        >
            <div
                v-if="moveStatusLabel"
                class="fixed inset-0 flex items-center justify-center bg-black/50 dark:bg-black/70 backdrop-blur-sm"
                style="z-index: 99998;"
                role="status"
                aria-live="polite"
            >
                <div
                    class="flex flex-col items-center gap-4 bg-white dark:bg-cherry-800 rounded-xl px-12 py-8 shadow-2xl border border-gray-200 dark:border-cherry-600 w-96 max-w-[90vw] relative"
                    style="min-width: 360px; z-index: 99999;"
                >
                    <svg class="animate-spin h-12 w-12 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span class="text-base font-semibold text-gray-900 dark:text-white text-center break-words" v-text="moveStatusLabel"></span>
                </div>
            </div>
            <div
                class="tree-container text-nowrap overflow-auto"
                style="max-height: calc(100vh - 360px);"
                :class="treeBusy ? 'cursor-not-allowed' : ''"
                @dragover.prevent="autoScrollTree"
            >
                <div class="flex gap-2 items-center mb-2 p-1">
                    <button type="button" @click="showAllAssets" :disabled="treeBusy" class="flex-1 text-left text-sm font-semibold text-primary-600 dark:text-primary-300">All assets</button>
                    @if (bouncer()->hasPermission('dam.directory.store'))
                        <button type="button" @click="newFolder" :disabled="treeBusy || !newFolderParent" class="text-sm text-gray-600 dark:text-gray-300 border dark:border-cherry-700 rounded px-2 py-1">New folder</button>
                    @endif
                </div>
                <button
                    v-if="formattedItems[0]"
                    type="button"
                    data-root-move-target
                    class="mb-2 flex w-full items-center gap-2 rounded-md border border-dashed px-2 py-1.5 text-left text-sm dark:border-cherry-700"
                    :class="rootMoveTargetState === 'valid' ? 'border-primary-500 bg-primary-50 text-primary-700 ring-2 ring-primary-500 dark:bg-cherry-800 dark:text-primary-300' : (rootMoveTargetState === 'invalid' ? 'border-red-400 opacity-50' : 'text-gray-600 dark:text-gray-300')"
                    @dragover.prevent
                    @drop.prevent="dropOnRoot"
                >
                    <i class="icon-dam-folder text-xl" aria-hidden="true"></i>
                    <span v-text="formattedItems[0].name || 'Root'"></span>
                </button>
                <v-dam-tree-item
                    v-for="root in formattedItems" :key="root.id" :item="root"
                    @right-click-item="showContextMenu" @set-filters="setFilters"
                    @on-merge-items="onMergeItems" @on-drag-start="onDragStart" @on-drag-end="onDragEnd"
                    :selected-item="selectedItem" :moving-directory-id="movingDirectoryId"
                    :deleting-directory-id="deletingDirectoryId" :copying-directory-id="copyingDirectoryId"
                    :tree-locked="treeBusy"
                    :acl-bypass="aclBypass" :accessible-ids="localAccessibleIds"
                    :dragged-directory-id="draggedDirectoryId" :dragged-descendant-ids="draggedDescendantIds"
                ></v-dam-tree-item>
            </div>

            <v-dam-folder-picker
                :open="treeFolderPickerOpen"
                tab-id="sidebar"
                :excluded-dir-ids="treeMoveExcludedIds"
                @picked="moveSelectedTo"
                @close="treeFolderPickerOpen = false"
            ></v-dam-folder-picker>

            <teleport to="body">
            <div v-if="showContextMenuFlag"
                ref="contextMenu" role="group" aria-label="Folder actions" @keydown.esc.prevent.stop="closeContextMenu"
                :style="{ top: `${contextMenuPosition.y}px`, left: `${contextMenuPosition.x}px` }"
                class="dam-tree-context-menu fixed bg-white border border-gray-300 px-4 py-2 rounded shadow-lg z-[9999] dark:border-cherry-800 dark:bg-cherry-800 dark:text-white"
            >
                <div>
                    @if (bouncer()->hasPermission('dam.asset.upload'))
                    <input
                        class="hidden"
                        type="file"
                        ref="fileInput"
                        @change="handleFileChange"
                        multiple="multiple"
                        name="files[]"
                    />
                    <input
                        class="hidden"
                        type="file"
                        ref="folderInput"
                        @change="handleFolderChange"
                        webkitdirectory
                        multiple
                        name="folder_files[]"
                    />
                    <button type="button"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="uploadFile"
                        v-if="requestType != 'asset' && canAccessSelected()"
                    >
                        <i class="icon-dam-upload text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                        <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.upload-files')</span>
                    </button>
                    <button type="button"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="triggerFolderUpload"
                        v-if="requestType != 'asset' && canAccessSelected()"
                    >
                        <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                        <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.upload-folder')</span>
                    </button>
                    @endif

                    @if (! dam_access_lists() && auth('admin')->user()?->role?->permission_type === 'all')
                        <button type="button"
                            class="flex w-full items-center gap-2 rounded-md p-1.5 text-sm text-zinc-600 dark:text-white"
                            v-if="requestType == 'directory'"
                            @click="$emitter.emit('dam:manage-access', selectedItem); closeContextMenu()"
                        >
                            <i class="icon-user" aria-hidden="true"></i>
                            <span>Manage access</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.store'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 !leading-normal dark:text-slate-300"
                            @click="createDirectory"
                            v-if="requestType != 'asset' && canAccessSelected()"
                        >
                            <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white"> @lang('dam::app.admin.dam.index.directory.actions.add-directory') </span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.rename'))
                    <button type="button"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="renameItem"
                        v-if="requestType == 'directory' && canAccessSelected()"
                    >
                        <i class="icon-dam-rename" aria-hidden="true"></i>
                        <span class="text-sm text-zinc-600 dark:text-white">@lang('dam::app.admin.dam.index.directory.actions.rename')</span>
                    </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.moved'))
                    <button type="button"
                        data-tree-move-to
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="openMovePicker"
                        v-if="requestType == 'directory' && selectedItem && selectedItem.id != 1 && canAccessSelected()"
                    >
                        <i class="icon-arrow-right" aria-hidden="true"></i>
                        <span>@lang('dam::app.admin.explorer.mass-actions.move-to')</span>
                    </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.asset.rename'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                            @click="renameItem"
                            v-if="requestType == 'asset'"
                        >
                            <i class="icon-dam-rename" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white">@lang('dam::app.admin.dam.index.directory.actions.rename')</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.destroy'))
                    <button type="button"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="deleteItem"
                        v-if="requestType == 'directory' && canAccessSelected()"
                    >
                        <i class="icon-dam-delete" aria-hidden="true"></i>
                        <span class="text-sm text-zinc-600 dark:text-white">@lang('dam::app.admin.dam.index.directory.actions.delete')</span>
                    </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.asset.destroy'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                            @click="deleteFile"
                            v-if="requestType == 'asset'"
                        >
                            <i class="icon-dam-delete" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white">@lang('dam::app.admin.dam.index.directory.actions.delete')</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.copy_structure'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                            @click="copyDirectory"
                            v-if="requestType != 'asset' && canAccessSelected()"
                        >
                            <i class="icon-dam-directory" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.copy-directory-structured')</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.download_zip'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 !leading-normal dark:text-slate-300"
                            @click="downloadItem('directory')"
                            v-if="requestType != 'asset' && canAccessSelected()"
                        >
                            <i class="icon-dam-zip" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600  dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.download-zip')</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.directory.share'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 !leading-normal dark:text-slate-300"
                            @click="shareDirectory"
                            v-if="requestType == 'directory' && canAccessSelected()"
                        >
                            <i class="icon-dam-link" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.share')</span>
                        </button>
                    @endif

                    {{-- P5a (D1): ask people outside the DAM to send files into this folder. --}}
                    @if (bouncer()->hasPermission('dam.upload_requests.create'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 !leading-normal dark:text-slate-300"
                            data-dam-request-uploads
                            @click="requestUploads"
                            v-if="requestType == 'directory' && canAccessSelected()"
                        >
                            <i class="icon-import" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.upload-requests.request-uploads')</span>
                        </button>
                    @endif

                    @if (bouncer()->hasPermission('dam.asset.download'))
                    <button type="button"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 dark:text-white !leading-normal"
                        @click="downloadItem('asset')"
                        v-if="requestType == 'asset'"
                    >
                        <i class="icon-import" aria-hidden="true"></i>
                        <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.download')</span>
                    </button>
                    @endif

                    {{-- New in sharing slice 3: the tree's file entries get the Share dialog too, gated
                         exactly like the folder entry above, plus dam_access_lists() so `grants` keeps
                         rendering what the base commit renders (finding 3). --}}
                    @if (dam_access_lists() && bouncer()->hasPermission('dam.asset.share'))
                        <button type="button"
                            class="flex items-center justify-start rounded-md p-1.5 gap-2 cursor-pointer text-sm text-zinc-600 !leading-normal dark:text-slate-300"
                            data-dam-share-asset
                            @click="shareAsset"
                            v-if="requestType == 'asset'"
                        >
                            <i class="icon-dam-link" aria-hidden="true"></i>
                            <span class="text-sm text-zinc-600 dark:text-white text-nowrap">@lang('dam::app.admin.dam.index.directory.actions.share')</span>
                        </button>
                    @endif

                    <div
                        v-if="requestType == 'directory' && ! canAccessSelected()"
                        class="flex items-center justify-start rounded-md p-1.5 gap-2 text-sm text-zinc-500 dark:text-slate-400 italic !leading-normal cursor-default"
                    >
                        <span class="text-sm text-nowrap">@lang('dam::app.admin.permissions.no-actions')</span>
                    </div>
                </div>
            </div>
            </teleport>
        </div>

        <teleport to="body">
        <x-admin::form
            v-slot="{ meta, errors, handleSubmit }"
            as="div"
            ref="modalForm"
            class="z-[10002]"
        >
            <form
                @submit="handleSubmit($event, createOrRenameDirectory)"
                ref="directoryCreateOrRenameForm"
            >
                {{-- viewport-fit: the Name field takes focus as the dialog opens, and on a phone the keyboard would cover the save button. --}}
                <x-admin::modal ref="directoryCreateOrRenameModal" :viewport-fit="true" @toggle="focusNameInput">
                    <x-slot:header>
                        <p
                            class="text-lg text-gray-800 dark:text-white font-bold"
                            v-if="directoryCreate"
                        >
                            @lang('dam::app.admin.dam.index.directory.create.title')
                        </p>

                        <p
                            class="text-lg text-gray-800 dark:text-white font-bold"
                            v-else
                        >
                            @lang('dam::app.admin.dam.index.directory.rename.title')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        {!! view_render_event('mothership.admin.dam.directory.create.before') !!}

                        <x-admin::form.control-group.control
                            type="hidden"
                            name="parent_id"
                            v-model="directoryParentId"
                        />

                        <x-admin::form.control-group.control
                            type="hidden"
                            name="id"
                            v-model="selectedItem.id"
                            v-if="!directoryCreate"
                        />

                        <p v-if="directoryCreate && createDirPath" class="mb-3 text-sm text-gray-600 dark:text-gray-300" data-create-in>
                            @lang('dam::app.admin.dam.index.directory.create.in') <strong class="break-words">@{{ createDirPath }}</strong>
                        </p>

                        <x-admin::form.control-group v-if="directoryCreate">
                            <x-admin::form.control-group.label class="required">
                                @lang('dam::app.admin.dam.index.directory.create.name')
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="text"
                                name="name"
                                ref="nameInput"
                                rules="required"
                                :value="old('name')"
                                :label="trans('dam::app.admin.dam.index.directory.create.name')"
                                :placeholder="trans('dam::app.admin.dam.index.directory.create.name')"
                            />

                            <x-admin::form.control-group.error control-name="name" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group  v-if="!directoryCreate">
                            <x-admin::form.control-group.label class="required">
                                @lang('dam::app.admin.dam.index.directory.create.name')
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="text"
                                name="name"
                                ref="nameInput"
                                rules="required"
                                :value="old('name')"
                                v-model="directoryName"
                                :label="trans('dam::app.admin.dam.index.directory.create.name')"
                                :placeholder="trans('dam::app.admin.dam.index.directory.create.name')"
                            />

                            <x-admin::form.control-group.error control-name="name" />
                        </x-admin::form.control-group>

                        {!! view_render_event('mothership.admin.dam.directory.create.after') !!}
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex gap-x-2.5 items-center">
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="directorySubmitting"
                            >
                                @lang('dam::app.admin.dam.index.directory.create.save-btn')
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </form>
        </x-admin::form>
        </teleport>

        <teleport to="body">
        <x-admin::form
            v-slot="{ meta, errors, handleSubmit }"
            as="div"
            ref="modalForm"
        >
            <form
                @submit="handleSubmit($event, renameAsset)"
                ref="assetRenameForm"
            >
                <x-admin::modal ref="assetRenameModal" @toggle="focusNameInput">
                    <x-slot:header>
                        <p
                            class="text-lg text-gray-800 dark:text-white font-bold"
                        >
                            @lang('dam::app.admin.dam.index.directory.asset.rename.title')
                        </p>
                    </x-slot>

                    <x-slot:content>
                        {!! view_render_event('mothership.admin.dam.asset.rename.before') !!}

                        <x-admin::form.control-group.control
                            type="hidden"
                            name="id"
                            v-model="selectedItem.id"
                        />

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">
                                @lang('dam::app.admin.dam.index.directory.create.name')
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="text"
                                name="file_name"
                                ref="nameInput"
                                rules="required"
                                :value="old('file_name')"
                                v-model="assetName"
                                :label="trans('dam::app.admin.dam.index.directory.create.file_name')"
                                :placeholder="trans('dam::app.admin.dam.index.directory.create.file_name')"
                            />

                            <x-admin::form.control-group.error control-name="file_name" />
                        </x-admin::form.control-group>

                        {!! view_render_event('mothership.admin.dam.asset.rename.after') !!}
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex gap-x-2.5 items-center">
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                @lang('dam::app.admin.dam.index.directory.asset.rename.save-btn')
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </form>
        </x-admin::form>
        </teleport>

    </script>
<script type="module">
    const damTreeMaxFileUploads = @js((int) ini_get('max_file_uploads'));

    app.component('v-dam-tree-view', {
        template: '#v-dam-tree-view-template',
        props: {
            src: {
                type: String,
                default: null,
            },
            aclBypass: {
                type: Boolean,
                default: false,
            },
            accessibleIds: {
                type: Array,
                default: () => [],
            },
            showAssets: {
                type: Boolean,
                default: false,
            },
            visible: {
                type: Boolean,
                default: true,
            },
        },
        provide() {

            return {
                damTreeShowAssets: this.showAssets,
            };
        },
        data() {
            return {
                damAccessLists: @json(dam_access_lists()),
                formattedItems: null,
                formattedValues: null,
                showContextMenuFlag: false,
                contextMenuPosition: {
                    x: 0,
                    y: 0
                },
                selectedItem: null,
                directoryName: null,
                assetName: null,
                selectedEvent: null,
                directoryCreate: false,
                directoryParentId: null,
                createDirPath: '',
                directorySubmitting: false,
                // The folder the active Explorer tab shows (null: All assets). Undefined outside the Explorer.
                explorerActiveDirId: window.MothershipExplorerActiveDir,
                copyItem: null,
                requestType: null,
                parentItem: null,
                isLoading: false,
                treeLoading: false,
                actionStatus: null,
                dragStart: false,
                dragSnapshot: null,
                draggedDirectory: null,
                draggedDirectoryId: null,
                draggedDescendantIds: [],
                treeFolderPickerOpen: false,
                movingDirectoryId: null,
                deletingDirectoryId: null,
                copyingDirectoryId: null,
                gridBusy: false,
                moveStatusLabel: '',
                rootChildrenLoadingMore: false,
                _folderAbortController: null,
                localAccessibleIds: [...(this.accessibleIds || [])],
                nativeMoveBusy: false,
            };
        },

        beforeUnmount() {
            this.$emitter.off('dam:native-asset-drop', this.onNativeAssetDrop);
            this.$axios.interceptors.response.eject(this._csrfInterceptorId);
        },

        mounted() {
            this.$emitter.on('dam:native-asset-drop', this.onNativeAssetDrop);
            this._csrfInterceptorId = this.$axios.interceptors.response.use(
                undefined,
                async (error) => {
                    if (error.response?.status === 419 && ! error.config._csrfRetried) {
                        error.config._csrfRetried = true;

                        try {
                            await this.$axios.get("{{ route('sanctum.csrf-cookie') }}");

                            return await this.$axios(error.config);
                        } catch (retryError) {
                            return Promise.reject(retryError);
                        }
                    }

                    return Promise.reject(error);
                }
            );

            this.$emitter.on('uploaded-assets', (data) => {
                const uploadedCount = Array.isArray(data) ? data.length : (data ? 1 : 0);
                if (uploadedCount > 0 && this.selectedItem) {
                    this.adjustAncestorCounts(this.selectedItem.id, uploadedCount);
                }
                this.setAssets(data);
            });

            this.$emitter.on('delete-assets', (payload = {}) => {

                const deletedCount = Number(payload.count || 0);
                if (deletedCount > 0 && this.selectedItem) {
                    this.adjustAncestorCounts(this.selectedItem.id, -deletedCount);
                }
                this.invalidateAllAssetCaches();
            });

            this.$emitter.on('dam:grid-busy', (busy) => {
                this.gridBusy = !! busy;
            });

            // The tree emitted this event for its own create modal but never listened, so a folder
            // granted by a drag-upload's createStructure or created in the explorer pane stayed
            // outside localAccessibleIds for the whole session: "Move to" hid, the context menu
            // fell back to "View only", and a folder drag onto it was refused as "Cannot move
            // directory" even though the server would have accepted the move.
            this.$emitter.on('dam:directory-granted', (id) => {
                const numId = Number(id);
                if (! Number.isFinite(numId) || numId <= 0) return;
                if (! this.localAccessibleIds.map(Number).includes(numId)) {
                    this.localAccessibleIds.push(numId);
                }
            });

            this.$emitter.on('dam:cancel-folder-upload', () => {
                if (this._folderAbortController) {
                    this._folderAbortController.abort();
                }
            });

            // A stack hides the files folded into it, and a split brings them back: the folder counts follow, in place.
            // Never loadDirectories() here: it selects Root when no folder is selected (a search or filtered view), which
            // sends the library away from the view the stack was made in.
            this.$emitter.on('dam:merge-done', () => this.refreshCounts());
            this.$emitter.on('dam-merges-changed', () => this.refreshCounts());

            this.$emitter.on('dam:folder-drop-uploaded', ({ directoryId, count } = {}) => {
                if (count > 0 && directoryId) {
                    this.adjustAncestorCounts(directoryId, count);
                }
                this.loadDirectories();
            });

            this.$emitter.on('dam:reveal-directory', ({ id, silent = false } = {}) => {

                if (! this.formattedItems || ! this.formattedItems[0]) {
                    this._pendingReveal = { id, silent };
                    return;
                }
                this.revealDirectory(id, silent);
            });

            this.$emitter.on('dam:explorer-tree-sync', async ({ id, fromTree } = {}) => {
                if (id == null) return;
                if (! this.formattedItems || ! this.formattedItems[0]) {

                    this._pendingReveal = { id, silent: true, fromExplorerSync: true };
                    this.__explorerSync = true;
                    return;
                }
                this.__explorerSync = true;
                let path = this.findPathToDirectory(id);
                if (! path) {
                    path = await this.fetchAndRevealPath(id, true);
                    if (! path) {
                        this.__explorerSync = false;
                        return;
                    }
                }
                const expandCount = fromTree ? path.length - 1 : path.length;
                for (let i = 0; i < expandCount; i++) {
                    this.$emitter.emit('current-item-expanded', path[i]);
                }
                this.$nextTick().then(() => this.$nextTick().then(() => {
                    const target = path[path.length - 1];
                    const el = this.$refs.treeContainer
                        && this.$refs.treeContainer.querySelector(`[data-dir-id="${target.id}"]`);
                    if (el) {
                        const container = this.$refs.treeContainer;
                        const containerRect = container.getBoundingClientRect();
                        const elRect = el.getBoundingClientRect();
                        const scrollTop = container.scrollTop + elRect.top - containerRect.top - (containerRect.height / 2 - el.offsetHeight / 2);
                        container.scrollTo({ top: scrollTop, behavior: 'smooth' });
                    }
                    this.setFilters(target);
                    this.__explorerSync = false;
                }));
            });

            this.$emitter.on('dam:tree-reload', () => {
                this.loadDirectories();
            });

            this.$emitter.on('dam:explorer-active-dir', ({ directoryId = null } = {}) => {
                this.explorerActiveDirId = directoryId;
            });

            this.$emitter.on('dam:open-create-dir', ({ item } = {}) => {
                if (! item?.id) return;
                this.selectedItem = item;
                this.directoryCreate = true;
                this.createDirPath = item.path || this.pathLabel(item.id) || item.name || '';
                this.__explorerTriggeredCreate = true;
                this.$refs.directoryCreateOrRenameModal.toggle();
            });

            this.$emitter.on('dam:open-rename-dir', ({ item } = {}) => {
                if (! item?.id) return;
                this.selectedItem = item;
                this.directoryCreate = false;
                this.__explorerTriggeredRename = true;
                this.$refs.directoryCreateOrRenameModal.toggle();
            });

            if (this.visible) {
                this.loadDirectories();
            }
        },

        computed: {

            newFolderParent() {
                // In the Explorer, "New folder" creates where the active tab is looking, not where the tree was last
                // highlighted from another tab; All assets creates at the root.
                const activeId = this.explorerActiveDirId;
                const activeNode = activeId != null ? this.findPathToDirectory(activeId)?.at(-1) : null;
                const candidate = activeNode
                    ?? (activeId === undefined && this.selectedItem?.id && !this.selectedItem.file_name ? this.selectedItem : this.formattedItems?.[0]);
                if (!candidate) return null;
                return this.aclBypass || this.localAccessibleIds.map(Number).includes(Number(candidate.id)) ? candidate : null;
            },

            treeMutating() {
                return !! (
                    this.nativeMoveBusy
                    || this.deletingDirectoryId
                    || this.movingDirectoryId
                    || this.copyingDirectoryId
                );
            },

            treeBusy() {
                return this.treeMutating || this.gridBusy;
            },

            rootChildrenHasMore() {
                return !! (this.formattedItems && this.formattedItems[0] && this.formattedItems[0].children_has_more);
            },

            treeMoveExcludedIds() {
                return this.selectedItem && this.requestType === 'directory'
                    ? this.findAllDirectoryIds(this.selectedItem)
                    : [];
            },

            rootMoveTargetState() {
                const root = this.formattedItems?.[0];
                if (! root || this.draggedDirectoryId == null) return null;

                return window.MothershipLibraryNavigation.isValidFolderMoveTarget({
                    sourceId: this.draggedDirectoryId,
                    targetId: root.id,
                    descendantIds: this.draggedDescendantIds,
                    accessibleIds: this.localAccessibleIds,
                    aclBypass: this.aclBypass,
                }) ? 'valid' : 'invalid';
            },
        },

        watch: {

            treeMutating(value) {
                this.$emitter.emit('dam:tree-busy', value);
            },
        },

        methods: {

            showAllAssets() {
                this.selectedItem = null;
                this.$emitter.emit('dam:all-assets');
                @if (!config('dam.explorer.enabled'))
                    this.resetFilters(this.formattedItems?.[0]);
                @endif
            },

            newFolder() {
                if (!this.newFolderParent) return;
                this.selectedItem = this.newFolderParent;
                this.requestType = 'directory';
                this.createDirectory();
            },

            canAccessSelected() {
                if (this.aclBypass) return true;
                if (! this.selectedItem || this.selectedItem.id == null) return false;

                return this.localAccessibleIds.map(Number).includes(Number(this.selectedItem.id));
            },

            focusNameInput() {
                this.$nextTick(() => {
                    if (this.$refs.nameInput) {
                        this.$refs.nameInput.focus();
                        this.directoryParentId = this.directoryCreate ? this.selectedItem.id : this.selectedItem.parent_id;
                        this.directoryName = this.selectedItem.name || null;
                        this.assetName = this.selectedItem.file_name || null;
                    }
                });
            },
            showContextMenu(event, item, type = 'directory') {
                this.selectedItem = item;
                this.selectedEvent = event;
                this.requestType = type;

                this._contextOpener = event.currentTarget;
                const bounds = event.currentTarget?.getBoundingClientRect?.();
                this.contextMenuPosition = { x: event.clientX || bounds?.left || 10, y: event.clientY || bounds?.bottom || 10 };

                if (! this.isLoading) {
                    this.showContextMenuFlag = true;
                    this.$nextTick(() => { this.clampContextMenuToViewport(); this.$refs.contextMenu?.querySelector('button')?.focus(); });
                }

                document.addEventListener('click', this.closeContextMenu);
            },

            clampContextMenuToViewport() {
                const menu = this.$refs.contextMenu;
                if (! menu) return;

                const margin = 10;
                const { width, height } = menu.getBoundingClientRect();
                const { x, y } = this.contextMenuPosition;

                this.contextMenuPosition = {
                    x: Math.max(margin, Math.min(x, window.innerWidth - width - margin)),
                    y: Math.max(margin, Math.min(y, window.innerHeight - height - margin)),
                };
            },

            closeContextMenu() {
                this.showContextMenuFlag = false;
                document.removeEventListener('click', this.closeContextMenu);
                if (this._contextOpener?.isConnected) this._contextOpener.focus();
            },

            pathLabel(id) {
                return (id != null ? this.findPathToDirectory(id) : null)?.map(node => node.name).join(' / ') ?? '';
            },

            findPathToDirectory(id) {
                const stack = (this.formattedItems || []).map(root => [root, [root]]);
                while (stack.length) {
                    const [node, path] = stack.pop();
                    if (Number(node.id) === Number(id)) {
                        return path;
                    }
                    for (const child of (node.children || [])) {
                        stack.push([child, [...path, child]]);
                    }
                }
                return null;
            },

            async revealDirectory(id, silent = false) {
                let path = this.findPathToDirectory(id);

                if (! path) {

                    path = await this.fetchAndRevealPath(id, silent);
                    if (! path) return;
                }

                for (let i = 0; i < path.length; i++) {
                    this.$emitter.emit('current-item-expanded', path[i]);
                }

                await this.$nextTick();

                await this.$nextTick();

                const target = path[path.length - 1];
                const el = this.$refs.treeContainer
                    && this.$refs.treeContainer.querySelector(`[data-dir-id="${target.id}"]`);
                if (el) {
                    const container = this.$refs.treeContainer;
                    const containerRect = container.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const scrollTop = container.scrollTop + elRect.top - containerRect.top - (containerRect.height / 2 - el.offsetHeight / 2);
                    container.scrollTo({ top: scrollTop, behavior: 'smooth' });
                }

                this.setFilters(target);
            },

            async loadNodeChildrenUntilFound(node, targetId) {
                if (! Array.isArray(node.children)) node.children = [];
                node.children.splice(0, node.children.length);

                let hasMore = true;
                let guard = 0;

                while (hasMore && guard++ < 100) {
                    const offset = node.children.length;
                    let response;
                    try {
                        response = await this.$axios.get(
                            `{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', node.id),
                            { params: { offset } }
                        );
                    } catch (e) {
                        break;
                    }

                    const children = response.data.data || [];
                    node.children.push(...children);
                    hasMore = !! response.data.has_more;

                    if (! children.length) break;
                    if (node.children.some((child) => Number(child.id) === Number(targetId))) break;
                }

                node._loaded = true;
                node.children_has_more = hasMore;
                this.fetchCountsForNodes(node.children);

                return node.children;
            },

            async fetchAndRevealPath(id, silent = false) {
                let ancestors;
                try {
                    const response = await this.$axios.get(
                        `{{ route('admin.dam.directory.path', ':id') }}`.replace(':id', id)
                    );
                    ancestors = response.data.data || [];
                } catch (e) {
                    if (! silent) {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: "@lang('dam::app.admin.dam.index.directory.search.not-found-flash')",
                        });
                    }
                    return null;
                }

                if (! ancestors.length) {
                    if (! silent) {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: "@lang('dam::app.admin.dam.index.directory.search.not-found-flash')",
                        });
                    }
                    return null;
                }

                for (const ancestor of ancestors) {
                    const inTree = this.findItemDirectoryById(this.formattedItems, ancestor.id, null);
                    if (inTree) continue;

                    if (ancestor.parent_id == null) continue;

                    const parentNode = this.findItemDirectoryById(this.formattedItems, ancestor.parent_id, null);
                    if (parentNode) {
                        await this.loadNodeChildrenUntilFound(parentNode.item, ancestor.id);
                    }
                }

                const path = this.findPathToDirectory(id);
                if (! path && ! silent) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: "@lang('dam::app.admin.dam.index.directory.search.not-found-flash')",
                    });
                }
                return path;
            },

            setFilters(item, type = "directory") {
                this.selectedItem = item;
                this.parentItem = item;

                if (item.hasOwnProperty('directories') || item.hasOwnProperty('directories')) {
                    this.parentItem = item.directories[0];
                }

                let column = type == 'directory' ? 'directory_id' : 'directory_asset_id';
                let value = [this.selectedItem.id];

                if (! this.__explorerSync) {
                    this.$emitter.emit('current-directory', this.parentItem);
                }
                this.emitBreadcrumb(this.parentItem);
                this.$emitter.emit('data-grid:reset-all-filters');
                this.$emitter.emit('data-grid:filter', {
                    column: {
                        column: column,
                        index: column
                    },
                    value
                });

                this.closeContextMenu();

            },

            resetFilters(item) {
                this.selectedItem = item;
                this.parentItem = item;
                this.$emitter.emit('data-grid:reset-all-filters');
                this.$emitter.emit('data-grid:refresh');
                this.$emitter.emit('current-directory', null);
                this.emitBreadcrumb(null);
                this.closeContextMenu();
            },

            emitBreadcrumb(item) {
                if (! item) {
                    this.$emitter.emit('current-directory-breadcrumb', []);
                    return;
                }

                const path = this.findPathToDirectory(item.id);
                const crumbs = (path || [item]).filter(node => !node.is_structural_wrapper).map(n => ({ id: n.id, name: n.name }));
                this.$emitter.emit('current-directory-breadcrumb', crumbs);
            },

            findAllDirectoryIds(selectedItem) {
                let ids = [];

                function traverse(item) {
                    if (item.id) {
                        ids.push(item.id);
                    }

                    if (item.children && item.children.length > 0) {
                        item.children.forEach(child => traverse(child));
                    }
                }

                traverse(selectedItem);

                return ids;
            },

            createDirectory() {
                this.directoryCreate = true;
                this.createDirPath = this.pathLabel(this.selectedItem?.id) || this.selectedItem?.name || '';
                this.$refs.directoryCreateOrRenameModal.toggle();
                this.closeContextMenu();
            },

            createOrRenameDirectory(params, {
                resetForm,
                setErrors
            }) {
                // One request per submit: a triple-click used to send three, and the duplicates came back as errors.
                if (this.directorySubmitting) return;
                this.directorySubmitting = true;
                let formData = new FormData(this.$refs.directoryCreateOrRenameForm);
                this.$axios.post(this.directoryCreate ? "{{ route('admin.dam.directory.store') }}" : "{{ route('admin.dam.directory.update') }}", formData)
                    .then((response) => {
                        this.$refs.directoryCreateOrRenameModal?.close();
                        if (this.directoryCreate) {
                            if (!this.selectedItem.children) {
                                this.selectedItem.children = [];
                            }

                            this.selectedItem.children.push(response.data.data);

                            if (response.data.data && response.data.data.id) {
                                const newId = Number(response.data.data.id);
                                if (! this.localAccessibleIds.map(Number).includes(newId)) {
                                    this.localAccessibleIds.push(newId);
                                }
                                this.$emitter.emit('dam:directory-granted', newId);
                            }

                            this.selectedItem = response.data.data;
                            this.parentItem = response.data.data;

                            if (this.__explorerTriggeredCreate) {
                                this.$emitter.emit('dam:suppress-nav-once');
                                this.__explorerTriggeredCreate = false;
                            }
                        } else {
                            this.selectedItem = response.data.data;

                            if (this.__explorerTriggeredRename) {
                                this.$emitter.emit('dam:suppress-nav-once');
                                this.__explorerTriggeredRename = false;
                            }
                        }

                        this.loadDirectories();

                        this.$emitter.emit('current-item-expanded', this.selectedItem);
                        this.$emitter.emit('dam:directory-mutated');

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });

                        resetForm();
                    })
                    .catch(error => {
                        if (error.response?.status == 422 && error.response.data?.errors) {
                            setErrors(error.response.data.errors);
                        }

                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message) ?? "@lang('dam::app.admin.dam.index.directory.operation-failed')"
                        });
                    })
                    .finally(() => {
                        this.directorySubmitting = false;
                    });
            },

            renameItem() {
                if (this.requestType == 'asset') {
                    this.$refs.assetRenameModal.toggle();
                    this.closeContextMenu();

                    return;
                }

                this.directoryCreate = false;
                this.$refs.directoryCreateOrRenameModal.toggle();
                this.closeContextMenu();
            },

            renameAsset(params, {
                resetForm,
                setErrors
            }) {
                let formData = new FormData(this.$refs.assetRenameForm);
                this.$axios.post("{{ route('admin.dam.assets.rename') }}", formData)
                    .then((response) => {
                        this.$refs.assetRenameModal?.close();

                        // The name it already had: nothing changed, so nothing to announce or refresh.
                        if (response.data.code === 'unchanged') {
                            resetForm();
                            return;
                        }

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                        this.$emitter.emit('data-grid:refresh');
                        this.loadDirectories();
                        resetForm();
                    })
                    .catch(error => {
                        if (error.response?.status == 422) {
                            setErrors(error.response.data.errors);
                        }

                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                        });
                    });
            },

            deleteItem() {
                this.$emitter.emit('open-delete-modal', {
                    message: "@lang('dam::app.admin.components.modal.confirm.message')",
                    agree: () => {
                        this.isLoading = true;
                        this.deletingDirectoryId = this.selectedItem.id;
                        this.$axios.delete(`{{ route('admin.dam.directory.destroy', ':id') }}`.replace(':id', this.selectedItem.id))
                            .then(response => {
                                this.parentItem = response.data.data;
                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: response.data.message
                                });

                                setTimeout(() => {
                                    this.checkActionStatus('delete_directory');
                                }, 1000);

                            })
                            .catch((error) => {
                                this.isLoading = false;
                                this.deletingDirectoryId = null;
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                });
                            });
                    }
                });

                this.closeContextMenu();
            },

            deleteFile() {
                this.$emitter.emit('open-delete-modal', {
                    agree: () => {
                        this.$axios.delete(`{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', this.selectedItem.id))
                            .then(response => {

                                this.invalidateAllAssetCaches();

                                this.$emitter.emit('data-grid:refresh');

                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: response.data.message
                                });

                                this.setFilters(this.parentItem);
                            })
                            .catch((error) => {
                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                });
                            });
                    }
                });

                this.closeContextMenu();
            },

            async downloadItem(type = 'directory', ) {
                // The preparing-download card (components/download/preparing.blade.php) when this page has it.
                const dam = window.damDownload;

                if (type != 'directory') {
                    const downloadLink = `{{ route('admin.dam.assets.download', ':id') }}`.replace(':id', this.selectedItem.id);
                    // The Library's asset rows carry their thumbnail route as `path`; a stored path (no leading slash or scheme) is not a picture.
                    const picture = String(this.selectedItem.thumbnail_url || this.selectedItem.path || '');

                    if (dam) dam.start({ url: downloadLink, name: this.selectedItem.file_name || this.selectedItem.display_name || '', thumb: /^(https?:\/\/|\/)/.test(picture) ? picture : '' });
                    else window.open(downloadLink, '_self');

                    return;
                }

                const downloadLink = `{{ route('admin.dam.directory.zip_download', ':id') }}`.replace(':id', this.selectedItem.id);
                // Asked first (X-Zip-Preflight), so an empty folder is a message, never a page loaded into the download frame.
                const card = dam?.start({ name: this.selectedItem.name, folder: true });
                try {
                    const probe = await fetch(downloadLink, { headers: { Accept: 'application/json', 'X-Zip-Preflight': '1' }, credentials: 'same-origin' });
                    if (probe.status !== 204 && probe.status !== 200) {
                        const body = await probe.json().catch(() => ({}));
                        const message = body.message || "@lang('dam::app.admin.dam.index.directory.something-wrong')";
                        // The card shows the failure itself; the flash is the fallback when the page has no card.
                        if (card) card.fail(message); else this.$emitter.emit('add-flash', { type: 'error', message });

                        return;
                    }
                } catch (e) { /* network trouble on the probe: let the browser attempt the download itself */ }

                if (card) card.go(downloadLink); else window.open(downloadLink, '_self');
            },

            shareDirectory() {
                if (!this.selectedItem || !this.selectedItem.id) return;
                this.$emitter.emit('open-share-modal', {
                    targetType: 'directory',
                    targetId: this.selectedItem.id,
                });
                this.closeContextMenu();
            },

            requestUploads() {
                if (!this.selectedItem || !this.selectedItem.id) return;
                this.$emitter.emit('open-upload-request-modal', {
                    directoryId: this.selectedItem.id,
                    directoryName: this.selectedItem.name,
                });
                this.closeContextMenu();
            },

            shareAsset() {
                if (!this.selectedItem || !this.selectedItem.id) return;
                this.$emitter.emit('open-share-modal', {
                    targetType: 'asset',
                    targetId: this.selectedItem.id,
                });
                this.closeContextMenu();
            },

            copyDirectory() {
                this.isLoading = true;
                this.copyingDirectoryId = this.selectedItem.id;
                this.$axios.post("{{ route('admin.dam.directory.copy_structure') }}", this.selectedItem)
                    .then((response) => {
                        setTimeout(() => {
                            this.checkActionStatus('copy_directory_structure');
                        }, 1000);

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                    })
                    .catch(error => {
                        this.isLoading = false;
                        this.copyingDirectoryId = null;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                        });
                    });
            },

            pasteDirectory() {
                this.copyItem.parent_id = this.selectedItem.id;
                this.$axios.post("{{ route('admin.dam.directory.copy') }}", this.copyItem)
                    .then((response) => {
                        if (!this.selectedItem.children) {
                            this.selectedItem.children = [];
                        }

                        this.selectedItem.children.push(response.data.data);

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                    })
                    .catch(error => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                        });
                    });
            },

            onDragStart(event, item = null) {
                this.dragStart = true;
                if (! item || item.file_name) return;
                this.dragSnapshot = JSON.stringify(this.formattedItems);
                this.draggedDirectory = item;
                this.draggedDirectoryId = item.id;
                this.draggedDescendantIds = this.findAllDirectoryIds(item).filter(id => Number(id) !== Number(item.id));
            },

            onDragEnd() {
                this.$nextTick(() => {
                    if (this.dragStart) this.restoreFolderTreeSnapshot();
                });
            },

            onMergeItems(event, directoryId, type = 'directory') {
                const {
                    added,
                    removed
                } = event;

                if (this.isLoading) {
                    this.loadDirectories();
                    return;
                }

                if (!this.dragStart) {
                    this.loadDirectories();
                    return;
                }

                this.dragStart = false;

                let moved = added || removed;

                if (moved && type == 'directory') {

                    let {parent} = this.findItemDirectoryById(this.formattedItems, moved.element.id);
                    if (parent) {
                        if (! window.MothershipLibraryNavigation.isValidFolderMoveTarget({
                            sourceId: moved.element.id,
                            targetId: parent.id,
                            descendantIds: this.draggedDescendantIds,
                            accessibleIds: this.localAccessibleIds,
                            aclBypass: this.aclBypass,
                        })) {
                            this.restoreFolderTreeSnapshot();
                            this.$emitter.emit('add-flash', { type: 'error', message: "@lang('dam::app.admin.dam.index.directory.cannot-move')" });
                            return;
                        }
                        this.addedItems(moved.element, parent.id, type);
                    }
                }

                if (moved && type == 'asset') {

                    if (added) {
                        this.addedItems(moved.element, directoryId, type);
                    }
                }
            },

            findItemDirectoryById(items, id, parent = null) {
                for (const item of items) {
                    if (item.id === id) {
                        return { item, parent };
                    }

                    if (item.children && item.children.length > 0) {
                        const found = this.findItemDirectoryById(item.children, id, item);
                        if (found) {
                            return found;
                        }
                    }
                }

                return null;
            },

            findItemAssetById(items, targetId, parent = null) {
                for (const item of items) {
                    const assetItem = (item.assets || []).find(obj => obj.id === targetId);
                    if (assetItem) {
                        return { item, parent };
                    }

                    if (item.children && item.children.length > 0) {
                        const found = this.findItemAssetById(item.children, targetId, item);
                        if (found) {
                            return found;
                        }
                    }
                }

                return null;
            },

            restoreFolderTreeSnapshot() {
                if (this.dragSnapshot) this.formattedItems = JSON.parse(this.dragSnapshot);
                this.clearFolderDragState();
            },

            clearFolderDragState() {
                this.dragStart = false;
                this.dragSnapshot = null;
                this.draggedDirectory = null;
                this.draggedDirectoryId = null;
                this.draggedDescendantIds = [];
            },

            autoScrollTree(event) {
                const container = event.currentTarget;
                const rect = container.getBoundingClientRect();
                const delta = window.MothershipLibraryNavigation.edgeScrollDelta({
                    pointerY: event.clientY,
                    top: rect.top,
                    bottom: rect.bottom,
                });
                if (delta) container.scrollBy({ top: delta, behavior: 'auto' });
            },

            async onNativeAssetDrop({ targetId, targetName, ids }) {
                if (this.treeBusy || !Array.isArray(ids) || !ids.length) return;
                if (!this.aclBypass && !this.localAccessibleIds.map(Number).includes(Number(targetId))) {
                    this.$emitter.emit('add-flash', { type: 'error', message: "@lang('dam::app.admin.permissions.move-unauthorized')" });
                    return;
                }
                this.nativeMoveBusy = true;
                this.isLoading = true;
                this.actionStatus = 'pending';
                try {
                    const result = await window.MothershipFileOps.moveAssets({
                        axios: this.$axios, ids, targetId,
                        url: '{{ route("admin.dam.explorer.mass_move") }}',
                        statusUrl: `{{ route('admin.dam.action_request.status', 'mass_move') }}`,
                        previewUrl: this.damAccessLists ? '{{ route("admin.dam.access.move_preview") }}' : null,
                        confirmMove: this.damAccessLists ? (count) => new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                            message: "@lang('dam::app.admin.dam.access.move-preview-confirm')".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                            agree: () => resolve(true),
                            disagree: () => resolve(false),
                        })) : null,
                        // A preview failure (network, the cap, a refused move) must stop this move rather
                        // than let it through unconfirmed (Codex review of PR #75, finding 6).
                        onPreviewFailed: (error) => this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message }),
                    });
                    if (result === null) return;
                    this.invalidateAllAssetCaches();
                    await this.loadDirectories();
                    this.$emitter.emit('data-grid:refresh');
                    this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.explorer.mass-actions.move-done')".split('|').at(ids.length === 1 ? 0 : -1).replace(':count', ids.length).replace(':source', 'Library').replace(':destination', targetName || '') });
                } catch (error) {
                    this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });
                } finally {
                    this.isLoading = false;
                    this.actionStatus = null;
                    this.nativeMoveBusy = false;
                }
            },

            dropOnRoot(event) {
                const root = this.formattedItems?.[0];
                const payload = window.MothershipFileOps.parseDropPayload(event?.dataTransfer);
                if (window.MothershipFileOps.isSelectionDrag(payload)) {
                    if (root) this.$emitter.emit(`dam:explorer-internal-drop:${payload.tabId}`, { payload, targetDir: { id: root.id, name: root.name } });
                    return;
                }
                if (payload?.type === 'dam-asset') {
                    if (root) this.onNativeAssetDrop({ targetId: root.id, targetName: root.name, ids: payload.ids || [payload.id] });
                    return;
                }
                if (! this.draggedDirectory || ! root || this.rootMoveTargetState !== 'valid') {
                    this.restoreFolderTreeSnapshot();
                    return;
                }
                const item = this.draggedDirectory;
                this.dragStart = false;
                this.addedItems(item, root.id, 'directory');
            },

            openMovePicker() {
                this.treeFolderPickerOpen = true;
                this.closeContextMenu();
            },

            moveSelectedTo(target) {
                this.treeFolderPickerOpen = false;
                if (! this.selectedItem || ! target?.id) return;
                this.addedItems(this.selectedItem, target.id, 'directory');
            },

            async addedItems(item, moveTodirectoryId, type = 'directory') {
                if (this.damAccessLists) {
                    let previewFailed = null;
                    const proceed = await window.MothershipFileOps.guardedMovePreview({
                        axios: this.$axios,
                        previewUrl: '{{ route("admin.dam.access.move_preview") }}',
                        type, ids: [item.id], target: moveTodirectoryId,
                        confirmMove: (count) => new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                            message: "@lang('dam::app.admin.dam.access.move-preview-confirm')".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                            agree: () => resolve(true),
                            disagree: () => resolve(false),
                        })),
                        // A preview failure must stop the move exactly like a decline, but also says why
                        // (Codex review of PR #75, finding 6) — a decline alone gets no flash.
                        onPreviewFailed: (error) => { previewFailed = error; },
                    });
                    if (! proceed) {
                        if (previewFailed) {
                            this.$emitter.emit('add-flash', { type: 'error', message: previewFailed.response?.data?.message || previewFailed.message });
                        }
                        if (type === 'directory') this.restoreFolderTreeSnapshot();
                        return;
                    }
                }

                this.isLoading = true;
                this.actionStatus = 'pending';

                const itemName = type == 'directory' ? (item.name ?? '') : (item.file_name ?? '');
                const labelTemplate = type == 'directory'
                    ? `@lang('dam::app.admin.dam.index.move.directory')`
                    : `@lang('dam::app.admin.dam.index.move.asset')`;
                this.moveStatusLabel = labelTemplate.replace(':name', itemName);

                if (type == 'directory') {
                    this.movingDirectoryId = item.id;
                }

                this.$axios.post(type == 'directory' ? "{{ route('admin.dam.directory.moved') }}" : "{{ route('admin.dam.assets.moved') }}", {
                        new_parent_id: moveTodirectoryId,
                        move_item_id: item.id,
                    })
                    .then((response) => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });

                        if (type == 'directory') {
                            setTimeout(() => {
                                this.checkActionStatus('move_directory_structure');
                            }, 1000);
                        } else {
                            this.isLoading = false;
                            this.actionStatus = null;
                            this.moveStatusLabel = '';

                            this.invalidateAllAssetCaches();
                        }
                    })
                    .catch(error => {
                        this.isLoading = false;
                        this.actionStatus = null;
                        this.movingDirectoryId = null;
                        this.moveStatusLabel = '';
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                        });

                        this.restoreFolderTreeSnapshot();
                        this.loadDirectories();
                    });
            },

            uploadFile() {
                this.closeContextMenu();
                this.$refs.fileInput.click();
            },

            async triggerFolderUpload() {
                this.closeContextMenu();

                if (! window.showDirectoryPicker) {
                    this.$refs.folderInput.click();
                    return;
                }

                try {
                    const dirHandle = await window.showDirectoryPicker();
                    await this.handleDirectoryPicker(dirHandle);
                } catch (e) {
                    if (e.name === 'AbortError' || e.name === 'NotAllowedError') return;
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: @js(trans('dam::app.admin.dam.index.folder-upload-https-required')),
                    });
                }
            },

            async handleDirectoryPicker(dirHandle) {
                const emptyDirs = [];

                const collectFiles = async (handle, prefix) => {
                    const results = [];
                    const children = [];
                    for await (const [name, entry] of handle) {
                        children.push([name, entry]);
                    }

                    if (children.length === 0) {
                        const relPath = prefix ? `${dirHandle.name}/${prefix}` : dirHandle.name;
                        emptyDirs.push(relPath);
                        return results;
                    }

                    for (const [name, entry] of children) {
                        const path = prefix ? `${prefix}/${name}` : name;
                        if (entry.kind === 'file') {
                            const file = await entry.getFile();
                            results.push({ file, relativePath: `${dirHandle.name}/${path}` });
                        } else {
                            results.push(...await collectFiles(entry, path));
                        }
                    }
                    return results;
                };

                const fileEntries = await collectFiles(dirHandle, '');

                if (fileEntries.length === 0 && emptyDirs.length === 0) {
                    this.$emitter.emit('add-flash', {
                        type: 'warning',
                        message: @js(trans('dam::app.admin.dam.index.folder-upload-no-files')),
                    });
                    return;
                }

                this.enqueueFolderUpload(fileEntries, emptyDirs);
            },

            handleFileChange(event) {
                const files = event.target.files;
                if (! files || files.length === 0) return;
                if (! this.selectedItem) { event.target.value = ''; return; }

                this.$emitter.emit('dam:enqueue-upload', {
                    items: Array.from(files).map(f => ({ file: f, relativePath: f.name, preserveRoot: false })),
                    folderPaths: [],
                    targetDirId: this.selectedItem.id,
                });
                event.target.value = '';
            },

            async handleFolderChange(event) {
                const files = event.target.files;
                if (! files || files.length === 0) {
                    event.target.value = '';
                    return;
                }

                const fileEntries = Array.from(files).map(file => ({
                    file,
                    relativePath: file.webkitRelativePath || file.name,
                }));

                event.target.value = '';

                this.enqueueFolderUpload(fileEntries);
            },

            enqueueFolderUpload(fileEntries, emptyDirs = []) {
                if (! this.selectedItem) return;
                if (! fileEntries.length && ! emptyDirs.length) return;

                const folderPaths = new Set(emptyDirs);
                const items = [];
                for (const { file, relativePath } of fileEntries) {
                    const rel  = relativePath || file.name;
                    const segs = rel.split('/');
                    for (let i = 1; i < segs.length; i++) folderPaths.add(segs.slice(0, i).join('/'));
                    items.push({ file, relativePath: rel, preserveRoot: true });
                }

                this.$emitter.emit('dam:enqueue-upload', {
                    items,
                    folderPaths: [...folderPaths],
                    targetDirId: this.selectedItem.id,
                });
            },

            setAssets(data) {
                if (! this.selectedItem.assets) this.selectedItem.assets = [];
                if (! this.selectedItem.children) this.selectedItem.children = [];

                this.invalidateDirAssetCache(this.selectedItem.id);

                this.$nextTick(() => {
                    this.$emitter.emit('current-item-expanded', this.selectedItem);
                    this.$emitter.emit('current-directory', this.selectedItem);
                    this.setFilters(this.selectedItem);
                });
            },

            isRootDir(id) {
                return this.formattedItems
                    && this.formattedItems[0]
                    && id == this.formattedItems[0].id;
            },

            invalidateDirAssetCache(dirId) {
                if (this.isRootDir(dirId)) {
                    this.loadRootAssets();
                } else {
                    this.$emitter.emit('invalidate-dir-assets', dirId);
                }
            },

            invalidateAllAssetCaches() {

                this.$emitter.emit('invalidate-dir-assets', null);
                this.loadRootAssets();
            },

            adjustAncestorCounts(dirId, delta) {
                if (! delta || ! this.formattedItems || ! this.formattedItems[0]) return;

                const path = [];
                const find = (node) => {
                    path.push(node);
                    if (Number(node.id) === Number(dirId)) return true;
                    for (const child of (node.children || [])) {
                        if (find(child)) return true;
                    }
                    path.pop();
                    return false;
                };

                if (!this.formattedItems.some(root => find(root))) return;

                for (const node of path) {
                    const current = Number(node.assets_total_count || 0);
                    node.assets_total_count = Math.max(0, current + delta);
                }
            },

            loadDirectories() {
                this.treeLoading = true;
                this.$axios.get("{{ route('admin.dam.directory.index') }}")
                        .then((response) => {

                            this.treeLoading = false;
                            const tree = response.data.data;

                            if (tree && tree[0] && ! Array.isArray(tree[0].assets)) {
                                tree[0].assets = [];
                            }

                            this.formattedItems = tree;

                            if (this.formattedItems && this.formattedItems[0]) {
                                this.$emitter.emit('dam:tree-root', {
                                    id: this.formattedItems[0].id,
                                    name: this.formattedItems[0].name,
                                });
                            }

                            const initialCountNodes = [];
                            (this.formattedItems || []).forEach((root) => {
                                initialCountNodes.push(root);
                                (root.children || []).forEach((child) => initialCountNodes.push(child));
                            });
                            this.fetchCountsForNodes(initialCountNodes);

                            this.$nextTick(() => {
                                if (this.selectedItem) {

                                    this.revealDirectory(this.selectedItem.id, true);
                                } else if (! this._pendingReveal) {

                                    this.setDefaultSeletedItem();
                                }

                                if (this.formattedItems && this.formattedItems[0]) {
                                    this.loadRootAssets();
                                }

                                if (this._pendingReveal) {
                                    const { id, silent, fromExplorerSync } = this._pendingReveal;
                                    this._pendingReveal = null;

                                    const reveal = this.revealDirectory(id, silent);
                                    if (fromExplorerSync) {

                                        reveal.finally(() => {
                                            this.__explorerSync = false;
                                        });
                                    }

                                }
                            });
                        })
                        .catch((error) => {
                            this.treeLoading = false;
                            console.error('Error fetching directories:', error);
                        });
            },

            loadRootAssets() {

                if (! this.showAssets) return;

                const root = this.formattedItems[0];
                if (! root) return;
                const generation = (this._rootAssetsGeneration = (this._rootAssetsGeneration || 0) + 1);
                this.$axios
                    .get(`{{ route('admin.dam.directory.assets', ':id') }}`.replace(':id', root.id))
                    .then((response) => {
                        // A newer request was made meanwhile: its answer is the one to show.
                        if (generation !== this._rootAssetsGeneration) return;

                        const fresh = response.data.data || [];

                        if (Array.isArray(root.assets)) {
                            root.assets.splice(0, root.assets.length, ...fresh);
                        } else {
                            root.assets = fresh;
                        }
                    })
                    .catch(() => {});
            },

            /** Re-reads the count of every folder already in the tree. Touches no selection, scope, search or filter. */
            refreshCounts() {
                const nodes = [];
                const walk = (list) => (list || []).forEach((node) => { nodes.push(node); walk(node.children); });
                walk(this.formattedItems);

                for (let start = 0; start < nodes.length; start += 400) {
                    this.fetchCountsForNodes(nodes.slice(start, start + 400));
                }

                // With files shown in the tree, the lists already loaded name files a merge hid or a split brought back.
                if (this.showAssets) {
                    this.$emitter.emit('refresh-dir-assets');
                    this.loadRootAssets();
                }
            },

            fetchCountsForNodes(nodes) {
                const list = (nodes || []).filter((node) => node && node.id != null);
                if (! list.length) return;
                // Each request stamps the folders it asks about; an answer older than the folder's latest request is dropped.
                const stamps = list.map((node) => (node._countStamp = (node._countStamp || 0) + 1));
                this.$axios
                    .post("{{ route('admin.dam.directory.asset_counts') }}", { ids: list.map((node) => node.id) })
                    .then((response) => {
                        const counts = response.data.data || {};
                        list.forEach((node, index) => {
                            const value = counts[node.id];
                            if (value !== undefined && node._countStamp === stamps[index]) {
                                node.assets_total_count = value;
                            }
                        });
                    })
                    .catch(() => {});
            },

            loadMoreRootChildren() {
                const root = this.formattedItems && this.formattedItems[0];
                if (! root || this.rootChildrenLoadingMore || ! root.children_has_more) return;
                this.rootChildrenLoadingMore = true;
                const offset = Array.isArray(root.children) ? root.children.length : 0;
                this.$axios
                    .get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', root.id), { params: { offset } })
                    .then((response) => {
                        const children = response.data.data || [];
                        if (! Array.isArray(root.children)) {
                            root.children = [];
                        }
                        root.children.push(...children);
                        root.children_has_more = !! response.data.has_more;
                        this.rootChildrenLoadingMore = false;
                        this.fetchCountsForNodes(children);
                    })
                    .catch(() => {
                        this.rootChildrenLoadingMore = false;
                    });
            },

            loadDirectoryChildrens() {
                this.$axios.get(`{{ route('admin.dam.directory.children', ':id') }}`.replace(':id', this.parentItem.id))
                    .then((response) => {
                        this.selectedItem = response.data.data;
                    })
                    .catch((error) => {
                        console.error('Error fetching directory children:', error);
                    });
            },

            loadDirectoryAssets() {
                this.$axios.get(`{{ route('admin.dam.directory.assets', ':id') }}`.replace(':id', this.parentItem.id))
                    .then((response) => {
                        this.parentItem.assets = response.data.data;
                    })
                    .catch((error) => {
                        console.error('Error fetching directory children:', error);
                    });
            },

            setDefaultSeletedItem() {
                if (!this.parentItem) {
                    this.selectedItem = this.formattedItems[0];
                    this.parentItem = this.formattedItems[0];
                }

                @if (!config('dam.explorer.enabled'))
                    if (!(window.MothershipLibraryNavigation?.locationState?.(location.search)?.explicit ?? false)) this.$emitter.emit('current-directory', this.selectedItem);
                    this.emitBreadcrumb(this.selectedItem);
                @endif
            },

            getDatagrids() {
                let datagrids = localStorage.getItem(
                    'datagrids'
                );

                return JSON.parse(datagrids) ?? [];
            },

            checkActionStatus(action) {
                if (this.actionStatus == 'completed') {
                    setTimeout(() => {
                        this.goForNextAction(action);
                        this.selectedItem = this.parentItem
                        this.$emitter.emit('data-grid:reset-all-filters');
                        this.setFilters(this.parentItem);

                        if (action == 'move_directory_structure') {
                            this.movingDirectoryId = null;
                            this.moveStatusLabel = '';
                            this.clearFolderDragState();
                        }

                        if (action == 'delete_directory') {
                            this.deletingDirectoryId = null;
                        }

                        if (action == 'copy_directory_structure') {
                            this.copyingDirectoryId = null;
                        }
                    }, 1000);

                    this.isLoading = false;
                    this.actionStatus = null;

                    this.$emitter.emit('add-flash', {
                        type: 'success',
                        message: "@lang('dam::app.admin.explorer.action-completed')"
                    });

                    return true;
                }
                this.$axios.get(`{{ route('admin.dam.action_request.status', ':eventType') }}`.replace(':eventType', action))
                    .then((response) => {
                        this.actionStatus = response.data.status;

                        if (this.actionStatus == 'failed') {
                                this.$emitter.emit('add-flash', { type: 'error', message: response.data.message });
                                this.isLoading = false;
                                if (action == 'move_directory_structure') {
                                    this.movingDirectoryId = null;
                                    this.moveStatusLabel = '';
                                    this.restoreFolderTreeSnapshot();
                                    this.loadDirectories();
                                }

                                if (action == 'delete_directory') {
                                    this.deletingDirectoryId = null;
                                }

                                if (action == 'copy_directory_structure') {
                                    this.copyingDirectoryId = null;
                                }

                                return;
                            }

                        if (this.actionStatus == 'error') {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: response.data.message
                            });
                            this.isLoading = false;
                            if (action == 'move_directory_structure') {
                                this.movingDirectoryId = null;
                                this.moveStatusLabel = '';
                                this.restoreFolderTreeSnapshot();
                            }

                            if (action == 'delete_directory') {
                                this.deletingDirectoryId = null;
                            }

                            if (action == 'copy_directory_structure') {
                                this.copyingDirectoryId = null;
                            }
                            this.goForNextAction(action);
                            return;
                        }

                        setTimeout(() => {
                            this.checkActionStatus(action);
                        }, 2000);
                    })
                    .catch((error) => {
                        this.isLoading = false;
                        if (action == 'move_directory_structure') {
                            this.movingDirectoryId = null;
                            this.moveStatusLabel = '';
                            this.restoreFolderTreeSnapshot();
                            this.loadDirectories();
                        }

                        if (action == 'delete_directory') {
                            this.deletingDirectoryId = null;
                        }

                        if (action == 'copy_directory_structure') {
                            this.copyingDirectoryId = null;
                        }
                    });
            },

            goForNextAction(action) {
                if (
                    action == 'delete_directory'
                    || action == 'copy_directory_structure'
                    || action == 'move_directory_structure'
                ) {
                    this.loadDirectories();
                }
            }
        },
    });
</script>
@endPushOnce
