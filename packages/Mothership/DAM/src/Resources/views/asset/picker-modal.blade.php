

@once('dam-grid-preview-modal')
    @include('dam::asset.grid-preview-modal')
@endonce

@once('dam-asset-picker-drop-upload')
    <x-dam::asset.drop-upload />
@endonce

@once('dam-asset-picker-persistent-upload')
    <v-dam-drop-upload class="hidden"></v-dam-drop-upload>
@endonce

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-asset-picker-template">
        <div>
            <x-admin::modal
                ref="assetPickerModal"
                type="full"
                :prevent-submit="true"
                v-on:close="onModalClosed"
                v-on:toggle="(event) => { if (event && event.isActive === false) onModalClosed(); }"
            >
                <x-slot:header>
                    <div class="flex gap-x-2.5">
                        <span v-if="chooseOnly" class="text-gray-800 dark:text-white font-semibold" data-dam-picker-title v-text="chooseTitle || @js(trans('dam::app.admin.components.asset.field.choose'))"></span>
                        <span v-else class="text-gray-800 dark:text-white font-semibold">
                            @lang('dam::app.admin.components.asset.field.assign-assets')
                        </span>
                    </div>
                </x-slot>

                <x-slot:content>
                    <v-dam-drop-upload
                        :current-directory="pickerCurrentDirectory"
                        :can-upload="canUploadAssets && ! chooseOnly"
                    >
                    <div class="flex flex-col gap-3 lg:flex-row">
                        @if (bouncer()->hasPermission('dam.directory.index'))
                            {{-- Below lg the folders fold behind a toggle that names the folder the grid shows. --}}
                            <div class="lg:shrink-0">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-2 rounded-md border bg-white px-3 py-2 text-sm text-gray-800 dark:border-cherry-800 dark:bg-cherry-900 dark:text-white lg:hidden"
                                    :aria-expanded="foldersOpen ? 'true' : 'false'"
                                    @click="foldersOpen = ! foldersOpen"
                                >
                                    <span class="flex min-w-0 items-center gap-1.5">
                                        <span class="icon-dam-folder shrink-0 text-xl"></span>
                                        <span
                                            class="truncate font-semibold"
                                            v-text="pickerCurrentDirectory?.name ?? @js(trans('dam::app.admin.dam.index.directory.all-folders'))"
                                        ></span>
                                    </span>

                                    <span
                                        class="icon-chevron-down shrink-0 text-2xl transition-transform"
                                        :class="{ 'rotate-180': foldersOpen }"
                                    ></span>
                                </button>

                                <div
                                    class="max-lg:mt-2"
                                    :class="{ 'max-lg:hidden': ! foldersOpen }"
                                >
                                    <x-dam::asset.picker.directory-tree />
                                </div>
                            </div>
                        @endif

                        <x-dam::asset.picker
                            v-bind:src="pickerSrc"
                            v-bind:timeout-ms="gridTimeoutMs"
                            v-on:load-failed="onGridFailed"
                            ref="datagrid"
                            class="min-w-0 flex-1"
                        >
                            <template #body-header="{ records, meta, massActions, selectAllRecords, isLoading }">
                                <div class="flex flex-wrap gap-2 items-center justify-between pb-4">
                                    <div class="flex gap-2" v-if="records.length">
                                        <label for="mass_action_select_all_records">
                                            <input
                                                type="checkbox"
                                                name="mass_action_select_all_records"
                                                id="mass_action_select_all_records"
                                                class="peer sr-only"
                                                aria-label="@lang('admin::app.components.datagrid.table.select-all')"
                                                :checked="meta.mode === 'all'"
                                                :indeterminate="meta.mode === 'partial'"
                                                @change="selectAllRecords(); $event.target.checked = meta.mode === 'all'"
                                            >

                                            <span
                                                class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 cursor-pointer rounded-md text-2xl"
                                                :class="[
                                                    meta.mode === 'all' ? 'peer-checked:icon-checkbox-check peer-checked:text-primary-700 ' : (
                                                    meta.mode === 'partial' ? 'peer-indeterminate:icon-checkbox-partial peer-indeterminate:text-primary-700' : ''
                                                    ),
                                                ]"
                                            >
                                            </span>
                                        </label>
                                        <span class="text-sm text-gray-600 dark:text-gray-300 cursor-pointer hover:text-gray-800 dark:hover:text-white">@lang('dam::app.admin.dam.index.select-all')</span>
                                    </div>

                                    <div class="flex items-center gap-2 ml-auto">
                                        @if (bouncer()->hasPermission('dam.asset.upload'))
                                            <input
                                                type="file"
                                                multiple
                                                name="picker_upload_files[]"
                                                :id="$.uid + '_pickerUpload'"
                                                class="hidden"
                                                @change="uploadToPicker"
                                            />
                                            <label
                                                v-if="! chooseOnly"
                                                :for="$.uid + '_pickerUpload'"
                                                class="secondary-button cursor-pointer"
                                            >
                                                <span>@lang('dam::app.admin.dam.index.upload')</span>
                                            </label>
                                        @endif

                                        @if (bouncer()->hasPermission('dam.asset_assign'))
                                            <span
                                                v-if="! chooseOnly"
                                                @click="saveAssets"
                                                class="secondary-button"
                                            >
                                                @lang('dam::app.admin.components.asset.field.assign-assets')
                                            </span>
                                        @endif

                                        <!-- A caller's own second way in (Creative Hub: an app's Open also takes a file from this computer). It hands the request over: closing the picker for it is not a cancel. -->
                                        <button
                                            v-if="chooseOnly && extraAction"
                                            type="button"
                                            class="secondary-button"
                                            data-dam-picker-extra
                                            @click="runExtraAction"
                                            v-text="extraAction.label"
                                        ></button>

                                        <!-- Choose-only (templates, P8′): Library images the viewer can open, never a device upload. -->
                                        <button
                                            v-if="chooseOnly"
                                            type="button"
                                            class="primary-button"
                                            data-dam-picker-choose
                                            v-bind:disabled="isLoading && gridTimeoutMs > 0"
                                            @click="saveAssets"
                                            v-text="chooseLabel || @js(trans('dam::app.admin.components.asset.field.choose'))"
                                        ></button>
                                    </div>
                                </div>
                            </template>

                            <template #body="{ columns, records, performAction, setCurrentSelectionMode, meta, applied, isLoading }">
                                <template v-if="! isLoading && records.length">
                                    <div v-for="record in records">
                                        <label :for="`mass_action_select_record_${record[meta.primary_column]}`" class="cursor-pointer">
                                            <div class="grid image-card relative overflow-hidden transition-all hover:border-gray-400 group">
                                                <img
                                                    :src="record.path"
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                    class="w-full h-full object-cover object-top"
                                                >

                                                <div class="absolute inset-0 flex items-center justify-center bg-black/60 dark:bg-cherry-800/70 transition-opacity opacity-0 group-hover:opacity-100 pointer-events-none">
                                                    <button
                                                        type="button"
                                                        class="icon-dam-preview text-xl sm:text-2xl p-1.5 rounded-md cursor-pointer text-white hover:bg-primary-600 transition-colors pointer-events-auto"
                                                        aria-label="@lang('dam::app.admin.dam.asset.edit.preview-modal.card.preview')"
                                                        @click.stop.prevent="openPreview(record[meta.primary_column])"
                                                    ></button>
                                                </div>
                                            </div>
                                            <div class="flex gap-2 items-center mt-2.5">
                                                <input
                                                    type="checkbox"
                                                    class="peer sr-only"
                                                    :aria-label="'{{ __('admin::app.components.datagrid.table.select-row') }}'.replace(':name', () => record.display_name || record.file_name)"
                                                    :name="`mass_action_select_record_${record[meta.primary_column]}`"
                                                    :value="record[meta.primary_column]"
                                                    :id="`mass_action_select_record_${record[meta.primary_column]}`"
                                                    v-model="applied.massActions.indices"
                                                    @change="setCurrentSelectionMode"
                                                >

                                                <span class="icon-checkbox-normal peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-600 dark:peer-focus-visible:outline-primary-400 peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl">
                                                </span>

                                                <h2 class="min-w-0 break-all line-clamp-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer hover:text-gray-800 dark:hover:text-white" :title="record.display_name || record.file_name" v-text="record.display_name || record.file_name"></h2>
                                            </div>
                                        </label>
                                    </div>
                                </template>

                                <template v-else>
                                    <x-admin::shimmer.datagrid.table.body isMultiRow="false" />
                                </template>
                            </template>
                        </x-dam::asset.picker>
                    </div>
                    </v-dam-drop-upload>
                </x-slot>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        app.component('v-dam-asset-picker', {
            template: '#v-dam-asset-picker-template',

            data() {
                return {
                    pendingAssign: null,
                    baseSrc: @js(route('admin.dam.asset_picker.index')),
                    pickerSrc: @js(route('admin.dam.asset_picker.index')),
                    pendingCancel: null,
                    maxPick: 0,
                    gridTimeoutMs: 0,
                    pendingFailed: null,
                    currentAssets: [],
                    pickerCurrentDirectory: null,
                    chooseOnly: false,
                    chooseTitle: null,
                    chooseLabel: null,
                    // { label, onClick } from the caller, shown beside Choose in choose-only mode; null for none.
                    extraAction: null,
                    foldersOpen: false,
                    canUploadAssets: @js(bouncer()->hasPermission('dam.asset.upload')),
                };
            },

            mounted() {
                this.$emitter.on('dam-asset-picker:open', ({ ids, onAssign, choose, onlyExtensions, withStacks, max, onCancel, timeoutMs, onFailed, title, actionLabel, extraAction } = {}) => this.open(ids, onAssign, choose, { onlyExtensions, withStacks, max, onCancel, timeoutMs, onFailed, title, actionLabel, extraAction }));
                this.$emitter.on('change-datagrid', this.loadAssetValues);
                this.$emitter.on('current-directory', (dir) => {
                    this.pickerCurrentDirectory = dir;
                    this.foldersOpen = false;
                });
                this.$emitter.on('dam:uploads-refresh', this.onUploadsRefresh);
            },

            methods: {
                open(ids = [], onAssign = null, choose = false, { onlyExtensions = null, withStacks = false, max = 0, onCancel = null, timeoutMs = 0, onFailed = null, title = null, actionLabel = null, extraAction = null } = {}) {
                    // A request still waiting on this picker is superseded by this one: answer it (cancelled) first, so
                    // whoever asked is never left waiting. Callers that pass no onCancel never set one.
                    if (this.pendingCancel) this.onModalClosed();

                    // Whether the grid is already on screen before this open: a fresh mount loads for itself.
                    const gridMounted = Boolean(this.$refs.datagrid);

                    // Choose-only hides upload (button and drop) and offers Choose whatever the assign permission.
                    this.chooseOnly = choose === true && typeof onAssign === 'function';
                    // An optional heading and action name (Creative Hub Insert names its app); without them the picker says "Choose".
                    this.chooseTitle = typeof title === 'string' && title !== '' ? title : null;
                    this.chooseLabel = typeof actionLabel === 'string' && actionLabel !== '' ? actionLabel : null;
                    this.extraAction = extraAction && typeof extraAction.onClick === 'function' && typeof extraAction.label === 'string' && extraAction.label !== ''
                        ? { label: extraAction.label, onClick: extraAction.onClick }
                        : null;
                    this.pendingAssign = typeof onAssign === 'function' ? onAssign : null;
                    // Creative Hub Insert: a type filter, a count cap and a cancel callback.
                    this.pendingCancel = typeof onCancel === 'function' ? onCancel : null;
                    this.maxPick = Number(max) > 0 ? Number(max) : 0;
                    // The grid's own load is bounded too: set before get() below reads it.
                    this.gridTimeoutMs = Number(timeoutMs) > 0 ? Number(timeoutMs) : 0;
                    this.pendingFailed = typeof onFailed === 'function' ? onFailed : null;
                    this.currentAssets = (ids || []).map(id => (isNaN(id) ? id : Number(id)));

                    const src = new URL(this.baseSrc, window.location.origin);
                    if (Array.isArray(onlyExtensions) && onlyExtensions.length) src.searchParams.set('only_extensions', onlyExtensions.join(','));
                    // Creative Hub Insert: a stack is listed whatever its main file's type (PickerDataGrid); hub/files says which of its files fit.
                    if (withStacks === true && src.searchParams.has('only_extensions')) src.searchParams.set('with_stacks', '1');
                    // Compared in URL form, so a caller without a filter never reloads a grid that is already on the base list.
                    if (src.toString() !== new URL(this.pickerSrc, window.location.origin).toString()) {
                        this.pickerSrc = src.toString();
                        // A grid that mounts with this open boots with the new src; only one already mounted must reload.
                        if (gridMounted) this.$nextTick(() => this.$refs.datagrid?.get());
                    }

                    const datagrid = this.$refs.datagrid;
                    if (datagrid?.applied?.massActions) {
                        datagrid.applied.massActions.indices = [];
                    }

                    this.$refs.assetPickerModal.open();
                },

                // A grid load that failed (v-asset-picker load-failed: a timeout or any other error): say why, then close, which cancels the request.
                onGridFailed({ reason, error } = {}) {
                    const failed = this.pendingFailed;
                    this.pendingFailed = null;
                    if (failed) {
                        failed(reason || 'failed', error);
                        this.$refs.assetPickerModal.close();
                        this.onModalClosed();   // close() may not emit when already closing; onModalClosed answers once
                    }
                },

                onModalClosed() {
                    const cancel = this.pendingCancel;
                    this.pendingCancel = null;
                    if (cancel) {
                        this.pendingAssign = null;
                        cancel();
                    }
                },

                // The caller's extra action takes the request over: nothing waiting on this picker is cancelled or assigned,
                // and the action runs inside the click (it may open the browser's file dialog, which needs the gesture).
                runExtraAction() {
                    const action = this.extraAction;
                    if (! action) return;
                    this.extraAction = null;
                    this.pendingCancel = null;
                    this.pendingAssign = null;
                    this.pendingFailed = null;
                    action.onClick();
                    this.$refs.assetPickerModal.close();
                },

                openPreview(id) {
                    this.$emitter.emit('dam-open-preview', isNaN(id) ? id : Number(id));
                },

                saveAssets() {
                    const indices = this.$refs.datagrid?.applied?.massActions?.indices ?? [];

                    if (this.maxPick && indices.length > this.maxPick) {
                        const tooMany = this.maxPick === 1 ? @js(trans_choice('dam::app.admin.creative-hub.insert.too-many', 1, ['max' => 1])) : @js(trans_choice('dam::app.admin.creative-hub.insert.too-many', 2, ['max' => ':max']));
                        this.$emitter.emit('add-flash', { type: 'warning', message: tooMany.replace(':max', this.maxPick) });

                        return;
                    }

                    this.pendingCancel = null;
                    this.pendingFailed = null;

                    if (typeof this.pendingAssign === 'function') {
                        this.pendingAssign(indices.slice());
                        this.pendingAssign = null;
                    } else {
                        this.$emit('assign', indices.slice());
                    }

                    this.$refs.assetPickerModal.close();
                },

                loadAssetValues() {
                    if (this.currentAssets.length && this.$refs?.datagrid?.applied?.massActions?.indices) {
                        const selectedIndices = this.$refs.datagrid.applied.massActions.indices;

                        this.$refs.datagrid.applied.massActions.indices = [
                            ...this.currentAssets.filter(id => ! selectedIndices.includes(id)),
                            ...selectedIndices,
                        ];

                        this.currentAssets = [];

                        this.$refs.datagrid.setCurrentSelectionMode();
                    }
                },

                uploadToPicker(e) {
                    const files = e.target.files;
                    if (! files || ! files.length) { e.target.value = ''; return; }

                    const dirId = this.pickerCurrentDirectory?.id;
                    if (! dirId) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: @js(trans('dam::app.admin.dam.index.select-directory-to-upload')) });
                        e.target.value = '';
                        return;
                    }

                    const items = Array.from(files).map(file => ({
                        file,
                        relativePath: file.name,
                        preserveRoot: false,
                    }));

                    this.$emitter.emit('dam:enqueue-upload', { items, folderPaths: [], targetDirId: dirId });

                    e.target.value = '';
                },

                onUploadsRefresh({ directoryId, assetIds = [] } = {}) {
                    if (directoryId == null) {
                        return;
                    }

                    const currentId = this.pickerCurrentDirectory?.id;
                    const isCurrent = currentId != null && Number(currentId) === Number(directoryId);

                    if (! isCurrent) {
                        this.$emitter.emit('dam:reveal-directory', { id: directoryId });

                        return;
                    }

                    const datagrid = this.$refs.datagrid;
                    if (! datagrid?.get) {
                        return;
                    }

                    Promise.resolve(datagrid.get()).then(() => {
                        if (! assetIds.length || ! datagrid.applied?.massActions) {
                            return;
                        }

                        const indices = datagrid.applied.massActions.indices;
                        assetIds.forEach(id => { if (! indices.includes(id)) indices.push(id); });
                        datagrid.setCurrentSelectionMode();
                    });
                },
            },
        });
    </script>
@endPushOnce
